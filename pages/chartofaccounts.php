<?php

declare(strict_types=1);

/**
 * pages/chartofaccounts.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: Chart of Accounts (sidebar > Accounting > Chart of Accounts).
 *
 * One page, no views. The account tree is rendered by PHP on GET.
 * Three AJAX actions (POST, JSON): parents, save, delete.
 * Same shell as pages/livestock.php, functional model taken from the Dalsan chart of accounts page.
 *
 * Tables and columns expected (single tenant, no agency id anywhere):
 *   accounts     (accountid, parentaccountid, code, accountname, accounttype, status)   code is unique
 *   activitylogs (userid, action, description, activitydate)   userid must accept NULL
 *   Optional tables (a missing table or column is logged and skipped), used to block a delete and to
 *   compute the Assets running balance (each one needs an amount column for the balance):
 *     receipts.accountid, payments.accountid, expenses.accountid / expenses.paidfromaccountid,
 *     accounttransfers.fromaccountid / accounttransfers.toaccountid
 *
 * Running balance of an Assets account = receipts - payments + transfers in - transfers out - expenses paid from it.
 * It shows 0.00 while those tables do not exist.
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call the auth helpers of ../db.php right after the
 * "NO LOGIN" marker in the request flow below (and pass the user id to dt_log_account()).
 * Single tenant: no agency id in any query.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';

if (!defined('DT_ALLOW_CDN')) {
    define('DT_ALLOW_CDN', true); // opt-in: allows the vetted CDN hosts in the CSP for this page only
}

/* ================================================================
 * 1. CONSTANTS AND CONFIG
 * ============================================================== */

const DT_MAX_CODE = 20;
const DT_MAX_NAME = 150;

const DT_LOGO_FILE = 'bushrofarmingandlivestocklogo.png'; // preferred logo file name
const DT_LOGO_DIRS = ['uploads', 'assets', 'images'];     // folders next to pages/ searched for the logo (in this order)

/** Account types, in tab order. */
const DT_ACC_TYPES = ['Assets', 'Liability', 'Equity', 'Revenue', 'Expense'];

/** Per type: [pane id, table id, main row label]. */
const DT_TYPE_INFO = [
    'Assets'    => ['assets', 'tblAssets', 'ASSETS'],
    'Liability' => ['liability', 'tblLiability', 'LIABILITY'],
    'Expense'   => ['expense', 'tblExpense', 'EXPENSES'],
    'Equity'    => ['equity', 'tblEquity', 'EQUITY'],
    'Revenue'   => ['revenue', 'tblRevenue', 'REVENUE'],
];

/**
 * Tables that reference accounts.accountid and block a delete: [table, [columns]].
 * Fixed whitelist (never user input). Several columns of one table are counted together.
 * A table or column that does not exist is logged and skipped.
 */
const DT_ACCOUNT_REFS = [
    ['receipts', ['accountid']],
    ['payments', ['accountid']],
    ['expenses', ['accountid', 'paidfromaccountid']],
    ['accounttransfers', ['fromaccountid', 'toaccountid']],
];

/**
 * Amount sources of the Assets running balance: [table, account column, sign (1 adds, -1 subtracts)].
 * Fixed whitelist (never user input). A table or column that does not exist is logged and skipped.
 */
const DT_BALANCE_SOURCES = [
    ['receipts', 'accountid', 1],
    ['payments', 'accountid', -1],
    ['accounttransfers', 'toaccountid', 1],
    ['accounttransfers', 'fromaccountid', -1],
    ['expenses', 'paidfromaccountid', -1],
];

/** Business-rule failure: message is shown to the user (the transaction is rolled back by the caller). */
final class DtFail extends RuntimeException
{
    public function __construct(string $message, public readonly bool $blocked = false, public readonly string $field = '')
    {
        parent::__construct($message);
    }
}

/* ================================================================
 * 2. HELPERS
 * ============================================================== */

/* Fallbacks: only defined when ../db.php does not already provide them. */
if (!function_exists('dt_e')) {
    /**
     * Escape a value for HTML output.
     *
     * @param mixed $v Value.
     *
     * @return string Escaped text.
     */
    function dt_e(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('dt_nonce')) {
    /**
     * CSP nonce of the request.
     *
     * @return string Nonce.
     */
    function dt_nonce(): string
    {
        static $n = null;

        return $n ??= bin2hex(random_bytes(16));
    }
}
if (!function_exists('dt_security_headers')) {
    /**
     * Send the security headers (CSP allows the vetted CDN hosts when DT_ALLOW_CDN is true).
     *
     * @return void
     */
    function dt_security_headers(): void
    {
        $n       = dt_nonce();
        $cdn     = defined('DT_ALLOW_CDN') && DT_ALLOW_CDN === true;
        $scripts = $cdn ? ' https://code.jquery.com https://cdn.jsdelivr.net https://cdn.datatables.net https://cdnjs.cloudflare.com' : '';
        $styles  = $cdn ? ' https://cdn.jsdelivr.net https://cdn.datatables.net https://cdnjs.cloudflare.com' : '';
        $fonts   = $cdn ? ' https://cdnjs.cloudflare.com' : '';
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'nonce-{$n}' https://fonts.googleapis.com{$styles}; font-src https://fonts.gstatic.com{$fonts}; script-src 'self' 'nonce-{$n}'{$scripts}; img-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
    }
}
if (!function_exists('dt_log_error')) {
    /**
     * Write a line to the error log.
     *
     * @param string $m Message.
     *
     * @return void
     */
    function dt_log_error(string $m): void
    {
        error_log($m);
    }
}
if (!function_exists('dt_describe_throwable')) {
    /**
     * Short description of a throwable for the log.
     *
     * @param Throwable $e Throwable.
     *
     * @return string Class and message.
     */
    function dt_describe_throwable(Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage();
    }
}
if (!function_exists('dt_pdo')) {
    /**
     * The PDO connection created by ../db.php.
     *
     * @return PDO Connection.
     *
     * @throws RuntimeException When db.php exposes no PDO.
     */
    function dt_pdo(): PDO
    {
        foreach (['pdo', 'conn', 'db'] as $k) {
            if (isset($GLOBALS[$k]) && $GLOBALS[$k] instanceof PDO) {
                return $GLOBALS[$k];
            }
        }
        throw new RuntimeException('No PDO connection found in db.php');
    }
}
if (!function_exists('dt_transaction')) {
    /**
     * Run a callback inside a transaction: commit on success, roll back and rethrow on any failure.
     *
     * @param callable $fn Callback receiving the PDO connection.
     *
     * @return mixed Callback result.
     *
     * @throws Throwable Anything the callback throws (after the rollback).
     */
    function dt_transaction(callable $fn): mixed
    {
        $pdo = dt_pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
if (!function_exists('dt_csrf_token')) {
    /**
     * The CSRF token of the session (created on first use).
     *
     * @return string Token.
     */
    function dt_csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
        }
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}
if (!function_exists('dt_csrf_valid')) {
    /**
     * Check a posted CSRF token against the session token.
     *
     * @param mixed $token Posted token.
     *
     * @return bool True when valid.
     */
    function dt_csrf_valid(mixed $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
        }
        $expected = $_SESSION['csrf_token'] ?? '';

        return is_string($token) && is_string($expected) && $expected !== '' && hash_equals($expected, $token);
    }
}
if (!function_exists('dt_csrf_field')) {
    /**
     * Hidden CSRF input for forms.
     *
     * @return string HTML.
     */
    function dt_csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . dt_e(dt_csrf_token()) . '">';
    }
}

/**
 * Run a prepared query. Placeholders are named, each name used once per statement.
 *
 * @param PDO                 $pdo    Connection.
 * @param string              $sql    SQL with named placeholders (each name used once).
 * @param array<string,mixed> $params Bound values.
 *
 * @return PDOStatement Executed statement.
 */
function dt_q(PDO $pdo, string $sql, array $params = []): PDOStatement
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt;
}

/**
 * Send a JSON answer and stop.
 *
 * @param array<string,mixed> $data Payload.
 * @param int                 $code HTTP status.
 *
 * @return never
 */
function dt_json(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Standard failure payload.
 *
 * @param string $message User-facing message.
 *
 * @return array<string,mixed> Payload.
 */
function dt_fail(string $message): array
{
    return ['success' => false, 'message' => $message];
}

/**
 * CSP nonce, already escaped for attributes.
 *
 * @return string Nonce.
 */
function dt_n(): string
{
    return dt_e(dt_nonce());
}

/**
 * Find the logo and return its URL.
 * Searches each folder of DT_LOGO_DIRS: the preferred file name first (exact,
 * then case-insensitive), then any image whose name contains "logo".
 *
 * @param string $preferred Preferred file name.
 *
 * @return string Relative URL, or an empty string when no logo file exists.
 */
function dt_find_logo(string $preferred): string
{
    $extensions = ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'];

    foreach (DT_LOGO_DIRS as $folder) {
        $dir = __DIR__ . '/../' . $folder . '/';
        if (!is_dir($dir)) {
            continue;
        }
        if (is_file($dir . $preferred)) {
            return '../' . $folder . '/' . rawurlencode($preferred);
        }

        $files = scandir($dir);
        if ($files === false) {
            continue;
        }
        sort($files);

        $fallback = '';
        foreach ($files as $file) {
            if (!is_file($dir . $file)) {
                continue;
            }
            if (strcasecmp($file, $preferred) === 0) {
                return '../' . $folder . '/' . rawurlencode($file);
            }
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($fallback === '' && in_array($ext, $extensions, true) && stripos($file, 'logo') !== false) {
                $fallback = $file;
            }
        }
        if ($fallback !== '') {
            return '../' . $folder . '/' . rawurlencode($fallback);
        }
    }

    return '';
}

/**
 * Inline SVG icon (no icon fonts, CSP safe). Used by the shell.
 *
 * @param string $name Icon key.
 *
 * @return string SVG markup.
 */
function dt_icon(string $name): string
{
    static $paths = [
        'home'       => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'livestock'  => '<circle cx="5" cy="10" r="2"/><circle cx="9" cy="5" r="2"/><circle cx="15" cy="5" r="2"/><circle cx="19" cy="10" r="2"/><path d="M12 11c-3 0-6 3.5-6 6.5 0 2 1.5 2.5 3 2s2-.7 3-.7 2 .7 3 .7 3-.5 3-2.5c0-3-3-6-6-6z"/>',
        'purchases'  => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
        'products'   => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
        'people'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'users'      => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/>',
        'accounting' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'reports'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'settings'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'activities' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'sales'      => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
        'menu'       => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'logout'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'caret'      => '<polyline points="6 9 12 15 18 9"/>',
    ];

    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
}

/**
 * Format an amount: 2 decimals, thousands separator, never "-0.00".
 *
 * @param mixed $value Amount.
 *
 * @return string e.g. "1,234.50".
 */
function dt_money(mixed $value): string
{
    $value = round((float)$value, 2);
    if ($value == 0) {
        $value = 0.0;
    }

    return number_format($value, 2, '.', ',');
}

/**
 * Whether a value is one of the account types.
 *
 * @param string $type Account type.
 *
 * @return bool True when valid.
 */
function dt_valid_type(string $type): bool
{
    return in_array($type, DT_ACC_TYPES, true);
}

/**
 * Data attributes carried by every editable row.
 *
 * @param array<string,mixed> $a Account row.
 *
 * @return string Escaped attribute string (leading space).
 */
function dt_row_attrs(array $a): string
{
    return ' data-id="' . (int)$a['accountid'] . '"'
        . ' data-parent="' . ($a['parentaccountid'] !== null ? (int)$a['parentaccountid'] : '') . '"'
        . ' data-code="' . dt_e((string)$a['code']) . '"'
        . ' data-name="' . dt_e((string)$a['accountname']) . '"'
        . ' data-type="' . dt_e((string)$a['accounttype']) . '"'
        . ' data-status="' . dt_e((string)$a['status']) . '"';
}

/**
 * Edit and Delete buttons of a row.
 *
 * @return string Button markup.
 */
function dt_action_buttons(): string
{
    return '<button type="button" class="btn btn-primary act-btn btn-edit" title="Edit" aria-label="Edit account"><i class="fas fa-edit" aria-hidden="true"></i></button> '
        . '<button type="button" class="btn btn-danger act-btn btn-del" title="Delete" aria-label="Delete account"><i class="fas fa-trash" aria-hidden="true"></i></button>';
}

/* ================================================================
 * 3. SHARED BUSINESS RULES (read and validate, load and lock, parent, unique code, references, log)
 * ============================================================== */

/**
 * Read and validate the posted account fields (used by save, before the transaction).
 *
 * @return array{id:int,type:string,parent:int,code:string,name:string,status:string} Clean fields.
 *
 * @throws DtFail On invalid input.
 */
function dt_read_account_input(): array
{
    $id     = max(0, (int)($_POST['accountid'] ?? 0));
    $type   = trim((string)($_POST['accounttype'] ?? ''));
    $parent = max(0, (int)($_POST['parentaccountid'] ?? 0));
    $code   = trim((string)($_POST['code'] ?? ''));
    $name   = trim((string)($_POST['accountname'] ?? ''));
    $status = strtoupper(trim((string)($_POST['status'] ?? 'ON')));

    if ($type === '') {
        throw new DtFail('Account type is required.', false, 'accounttype');
    }
    if (!dt_valid_type($type)) {
        throw new DtFail('Invalid account type.', false, 'accounttype');
    }
    if ($code === '') {
        throw new DtFail('Account code is required.', false, 'code');
    }
    if ($name === '') {
        throw new DtFail('Account name is required.', false, 'accountname');
    }
    if (mb_strlen($code) > DT_MAX_CODE) {
        throw new DtFail('The code is too long (maximum ' . DT_MAX_CODE . ' characters).', false, 'code');
    }
    if (mb_strlen($name) > DT_MAX_NAME) {
        throw new DtFail('The account name is too long (maximum ' . DT_MAX_NAME . ' characters).', false, 'accountname');
    }
    if ($status !== 'ON' && $status !== 'OFF') {
        throw new DtFail('Invalid status.');
    }

    return ['id' => $id, 'type' => $type, 'parent' => $parent, 'code' => $code, 'name' => $name, 'status' => $status];
}

/**
 * Load and lock (FOR UPDATE) an account. Call inside a transaction.
 *
 * @param PDO $pdo       Connection.
 * @param int $accountId Account id.
 *
 * @return array<string,mixed>|null Account row or null when it does not exist.
 */
function dt_lock_account(PDO $pdo, int $accountId): ?array
{
    $row = dt_q(
        $pdo,
        'SELECT accountid, parentaccountid, code, accountname, accounttype
           FROM accounts WHERE accountid = :id FOR UPDATE',
        ['id' => $accountId]
    )->fetch();

    return is_array($row) ? $row : null;
}

/**
 * Count the sub-accounts of an account.
 *
 * @param PDO $pdo       Connection.
 * @param int $accountId Account id.
 *
 * @return int Number of sub-accounts.
 */
function dt_count_children(PDO $pdo, int $accountId): int
{
    return (int)dt_q(
        $pdo,
        'SELECT COUNT(*) FROM accounts WHERE parentaccountid = :id',
        ['id' => $accountId]
    )->fetchColumn();
}

/**
 * Check the chosen parent group (locks it FOR UPDATE). Does nothing when no parent was posted.
 *
 * @param PDO    $pdo       Connection.
 * @param int    $accountId Account being saved (0 for a new one).
 * @param int    $parentId  Posted parent id (0 = none).
 * @param string $type      Account type of the account being saved.
 *
 * @return void
 *
 * @throws DtFail When the parent is not allowed.
 */
function dt_check_parent(PDO $pdo, int $accountId, int $parentId, string $type): void
{
    if ($parentId <= 0) {
        return;
    }
    if ($parentId === $accountId) {
        throw new DtFail('An account cannot be its own parent.');
    }
    $parent = dt_lock_account($pdo, $parentId);
    if ($parent === null) {
        throw new DtFail('The selected parent group does not exist.');
    }
    if ((string)$parent['accounttype'] !== $type) {
        throw new DtFail('The parent group must have the same account type.');
    }
    /* Any level allowed; only block a parent that is one of the account's own descendants. */
    $cursor = $parent['parentaccountid'];
    for ($i = 0; $accountId > 0 && $cursor !== null && $i < 1000; $i++) {
        if ((int)$cursor === $accountId) {
            throw new DtFail('An account cannot be placed under one of its own sub-accounts.');
        }
        $row    = dt_q($pdo, 'SELECT parentaccountid FROM accounts WHERE accountid = :id', ['id' => (int)$cursor])->fetch();
        $cursor = is_array($row) ? $row['parentaccountid'] : null;
    }
}

function dt_check_parent_old(PDO $pdo, int $accountId, int $parentId, string $type): void
{
    if ($parentId <= 0) {
        return;
    }
    if ($parentId === $accountId) {
        throw new DtFail('An account cannot be its own parent.');
    }
    $parent = dt_lock_account($pdo, $parentId);
    if ($parent === null) {
        throw new DtFail('The selected parent group does not exist.');
    }
    if ($parent['parentaccountid'] !== null) {
        throw new DtFail('The parent must be a top-level group.');
    }
    if ((string)$parent['accounttype'] !== $type) {
        throw new DtFail('The parent group must have the same account type.');
    }
}

/**
 * Make sure no other account uses the code (the code is unique).
 *
 * @param PDO    $pdo       Connection.
 * @param string $code      Code.
 * @param int    $accountId Account being saved (0 for a new one).
 *
 * @return void
 *
 * @throws DtFail When the code is taken.
 */
function dt_assert_code_free(PDO $pdo, string $code, int $accountId): void
{
    $dup = dt_q(
        $pdo,
        'SELECT accountid FROM accounts WHERE code = :c AND accountid <> :id LIMIT 1',
        ['c' => $code, 'id' => $accountId]
    )->fetchColumn();
    if ($dup !== false) {
        throw new DtFail('This code is already used by another account.', false, 'code');
    }
}

/**
 * Count the transaction rows that use an account (see DT_ACCOUNT_REFS).
 * A missing table or column (SQLSTATE 42S02 / 42S22) is logged and skipped; any other database error is rethrown.
 *
 * @param PDO $pdo       Connection.
 * @param int $accountId Account id.
 *
 * @return int Total rows across all referencing tables.
 *
 * @throws PDOException On a database error other than a missing table or column.
 */
function dt_count_references(PDO $pdo, int $accountId): int
{
    $total = 0;
    foreach (DT_ACCOUNT_REFS as [$table, $columns]) {
        $conditions = [];
        $params     = [];
        foreach ($columns as $i => $column) {
            $conditions[]     = "`{$column}` = :i{$i}";
            $params['i' . $i] = $accountId;
        }
        try {
            $total += (int)dt_q(
                $pdo,
                "SELECT COUNT(*) FROM `{$table}` WHERE " . implode(' OR ', $conditions),
                $params
            )->fetchColumn();
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
                dt_log_error('[accounts refs] skipped ' . $table . ': ' . dt_describe_throwable($e));
                continue;
            }
            throw $e;
        }
    }

    return $total;
}

/**
 * Write an account activity row (call before commit). Without login the user is unknown: userid is NULL ("System" on the dashboard).
 *
 * @param PDO    $pdo    Connection.
 * @param string $action Add | Update | Delete.
 * @param string $text   Description.
 *
 * @return void
 */
function dt_log_account(PDO $pdo, string $action, string $text): void
{
    dt_q(
        $pdo,
        'INSERT INTO activitylogs (userid, action, description, activitydate) VALUES (NULL, :a, :d, NOW())',
        ['a' => $action, 'd' => mb_substr($text, 0, 255)]
    );
}

/* ================================================================
 * 4. AJAX ACTIONS (one small function per action)
 * ============================================================== */

/**
 * Parent group options: top-level accounts of one type.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_parents(PDO $pdo): array
{
    $type    = (string)($_POST['accounttype'] ?? '');
    $exclude = max(0, (int)($_POST['excludeid'] ?? 0));
    if (!dt_valid_type($type)) {
        return ['success' => true, 'data' => []];
    }

    $rows = dt_q(
        $pdo,
        'SELECT accountid, parentaccountid, code, accountname FROM accounts WHERE accounttype = :t',
        ['t' => $type]
    )->fetchAll();
    usort($rows, static fn (array $a, array $b): int => strnatcmp((string)$a['code'], (string)$b['code']));

    $kids = [];
    foreach ($rows as $r) {
        $kids[$r['parentaccountid'] === null ? 0 : (int)$r['parentaccountid']][] = $r;
    }

    $out  = [];
    $walk = static function (int $parentId, int $level) use (&$walk, &$kids, &$out, $exclude): void {
        foreach ($kids[$parentId] ?? [] as $r) {
            $id = (int)$r['accountid'];
            if ($id === $exclude) {
                continue; // skips the account itself and all its descendants
            }
            $out[] = ['accountid' => $id, 'code' => (string)$r['code'], 'accountname' => (string)$r['accountname'], 'level' => $level];
            $walk($id, $level + 1);
        }
    };
    $walk(0, 0);

    return ['success' => true, 'data' => $out];
}

function dt_act_parents_old(PDO $pdo): array
{
    $type    = (string)($_POST['accounttype'] ?? '');
    $exclude = max(0, (int)($_POST['excludeid'] ?? 0));
    if (!dt_valid_type($type)) {
        return ['success' => true, 'data' => []];
    }

    $rows = dt_q(
        $pdo,
        'SELECT accountid, code, accountname FROM accounts
          WHERE parentaccountid IS NULL AND accounttype = :t AND accountid <> :x',
        ['t' => $type, 'x' => $exclude]
    )->fetchAll();
    usort($rows, static fn (array $a, array $b): int => strnatcmp((string)$a['code'], (string)$b['code']));

    $out = [];
    foreach ($rows as $r) {
        $out[] = ['accountid' => (int)$r['accountid'], 'code' => (string)$r['code'], 'accountname' => (string)$r['accountname']];
    }

    return ['success' => true, 'data' => $out];
}

/**
 * Add (id = 0) or edit an account (one transaction).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_save(PDO $pdo): array
{
    $d = dt_read_account_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        if ($d['id'] > 0) {
            $current = dt_lock_account($pdo, $d['id']);
            if ($current === null) {
                throw new DtFail('Account not found.');
            }
            if (dt_count_children($pdo, $d['id']) > 0) {
                if ((string)$current['accounttype'] !== $d['type']) {
                    throw new DtFail('You cannot change the type of a group that has sub-accounts.', false, 'accounttype');
                }
                if ($d['parent'] > 0) {
                    /* allowed: any account can be moved under another; cycles are blocked in dt_check_parent() */
                }
            }
        }

        dt_check_parent($pdo, $d['id'], $d['parent'], $d['type']);
        dt_assert_code_free($pdo, $d['code'], $d['id']);

        $parentValue = $d['parent'] > 0 ? $d['parent'] : null;

        if ($d['id'] > 0) {
            dt_q(
                $pdo,
                'UPDATE accounts
                    SET parentaccountid = :p, code = :c, accountname = :n, accounttype = :t, status = :s
                  WHERE accountid = :id',
                ['p' => $parentValue, 'c' => $d['code'], 'n' => $d['name'], 't' => $d['type'], 's' => $d['status'], 'id' => $d['id']]
            );
            dt_log_account($pdo, 'Update', 'Account updated: ' . $d['code'] . ' ' . $d['name']);

            return ['success' => true, 'message' => 'Account updated successfully.'];
        }

        dt_q(
            $pdo,
            'INSERT INTO accounts (parentaccountid, code, accountname, accounttype, status)
             VALUES (:p, :c, :n, :t, :s)',
            ['p' => $parentValue, 'c' => $d['code'], 'n' => $d['name'], 't' => $d['type'], 's' => $d['status']]
        );
        dt_log_account($pdo, 'Add', 'Account added: ' . $d['code'] . ' ' . $d['name']);

        return ['success' => true, 'message' => 'Account added successfully.'];
    });
}

/**
 * Delete an account unless transactions or sub-accounts use it (one transaction).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found or still used (blocked).
 */
function dt_act_delete(PDO $pdo): array
{
    $accountId = max(0, (int)($_POST['accountid'] ?? 0));

    return dt_transaction(static function (PDO $pdo) use ($accountId): array {
        $account = dt_lock_account($pdo, $accountId);
        if ($account === null) {
            throw new DtFail('Account not found.');
        }
        if (dt_count_references($pdo, $accountId) > 0) {
            throw new DtFail('You cannot delete this account because it has associated transaction history.', true);
        }
        if (dt_count_children($pdo, $accountId) > 0) {
            throw new DtFail('You cannot delete this account because it has sub-accounts.', true);
        }

        dt_q($pdo, 'DELETE FROM accounts WHERE accountid = :id', ['id' => $accountId]);
        dt_log_account($pdo, 'Delete', 'Account deleted: ' . $account['code'] . ' ' . $account['accountname']);

        return ['success' => true, 'message' => 'Account deleted successfully.'];
    });
}

/**
 * Dispatch a POST (AJAX) request: CSRF check, whitelist, error mapping.
 *
 * @return never
 */
function dt_handle_post(): never
{
    if (!dt_csrf_valid($_POST['csrf_token'] ?? null)) {
        dt_json(dt_fail('Invalid security token. Refresh the page.'), 403);
    }

    $handlers = ['parents' => 'dt_act_parents', 'save' => 'dt_act_save', 'delete' => 'dt_act_delete'];
    $action   = (string)($_POST['action'] ?? '');
    if (!isset($handlers[$action])) {
        dt_json(dt_fail('Unknown action.'), 400);
    }

    try {
        dt_json($handlers[$action](dt_pdo()));
    } catch (DtFail $e) {
        $out = dt_fail($e->getMessage());
        if ($e->blocked) {
            $out['blocked'] = true;
        }
        if ($e->field !== '') {
            $out['field'] = $e->field;
        }
        dt_json($out);
    } catch (PDOException $e) {
        dt_log_error('[accounts ' . $action . '] ' . dt_describe_throwable($e));
        if ($action === 'save' && (int)($e->errorInfo[1] ?? 0) === 1062) {
            dt_json(['success' => false, 'field' => 'code', 'message' => 'This code is already used by another account.']);
        }
        if ($action === 'delete' && $e->getCode() === '23000') {
            dt_json(['success' => false, 'blocked' => true, 'message' => 'You cannot delete this account because it has associated transaction history.']);
        }
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[accounts ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    }
}

/* ================================================================
 * 5. PAGE DATA (accounts tree, balances)
 * ============================================================== */

/**
 * Build the account tree per type (accounts sorted naturally by code).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,array{groups:array<int,array<string,mixed>>,children:array<int,array<int,array<string,mixed>>>,count:int,mainCode:string}> Tree per type.
 */
function dt_build_tree(PDO $pdo): array
{
    $rows = dt_q(
        $pdo,
        'SELECT accountid, parentaccountid, code, accountname, accounttype, status FROM accounts'
    )->fetchAll();
    usort($rows, static fn (array $a, array $b): int => strnatcmp((string)$a['code'], (string)$b['code']));

    $tree = [];
    foreach (DT_ACC_TYPES as $type) {
        $tree[$type] = ['groups' => [], 'children' => [], 'count' => 0, 'mainCode' => ''];
    }
    foreach ($rows as $a) {
        $type = (string)$a['accounttype'];
        if (!isset($tree[$type])) {
            continue;
        }
        $tree[$type]['count']++;
        if ($tree[$type]['mainCode'] === '') {
            $tree[$type]['mainCode'] = explode('.', (string)$a['code'])[0];
        }
        if ($a['parentaccountid'] === null) {
            $tree[$type]['groups'][] = $a;
        } else {
            $tree[$type]['children'][(int)$a['parentaccountid']][] = $a;
        }
    }

    return $tree;
}

/**
 * Running balance of every account that appears in DT_BALANCE_SOURCES (one grouped query per source).
 * A missing table or column (SQLSTATE 42S02 / 42S22) is logged and skipped: its amounts count as 0.
 * Any other database error is rethrown.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<int,float> Balance per account id (an account without movements is simply absent: 0.00).
 *
 * @throws PDOException On a database error other than a missing table or column.
 */
function dt_build_balances(PDO $pdo): array
{
    $balances = [];
    foreach (DT_BALANCE_SOURCES as [$table, $column, $sign]) {
        try {
            $rows = dt_q(
                $pdo,
                "SELECT `{$column}` AS accountid, SUM(amount) AS total FROM `{$table}` WHERE `{$column}` IS NOT NULL GROUP BY `{$column}`"
            )->fetchAll();
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
                dt_log_error('[accounts balances] skipped ' . $table . '.' . $column . ': ' . dt_describe_throwable($e));
                continue;
            }
            throw $e;
        }
        foreach ($rows as $r) {
            $id            = (int)$r['accountid'];
            $balances[$id] = ($balances[$id] ?? 0.0) + $sign * (float)$r['total'];
        }
    }

    /* Double entry: journal lines not already counted above (inventory, receivable, payable, revenue, COGS).
       Assets and Expense grow with debit; Liability, Equity and Revenue grow with credit. */
    $types = [];
    foreach (dt_q($pdo, 'SELECT accountid, accounttype FROM accounts')->fetchAll() as $r) {
        $types[(int)$r['accountid']] = (string)$r['accounttype'];
    }
    foreach (dt_build_journal($pdo) as $id => $j) {
        $debitNormal   = in_array($types[$id] ?? 'Assets', ['Assets', 'Expense'], true);
        $balances[$id] = ($balances[$id] ?? 0.0) + ($debitNormal ? $j['net'] : -$j['net']);
    }

    foreach ($balances as $id => $value) {
        $balances[$id] = round($value, 2);
    }

    return $balances;
}

/**
 * Journal totals per account (double entry written by pages/livestock.php).
 * debit / credit = every journal line; net = (debit - credit) of the lines NOT already counted by
 * receipts, payments or refunds (skipbalance = 0). A missing table or column is logged and counts as none.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<int,array{debit:float,credit:float,net:float}> Totals per account id.
 *
 * @throws PDOException On a database error other than a missing table or column.
 */
function dt_build_journal(PDO $pdo): array
{
    try {
        $rows = dt_q(
            $pdo,
            'SELECT accountid, SUM(debit) AS d, SUM(credit) AS c,
                    SUM(CASE WHEN skipbalance = 0 THEN debit - credit ELSE 0 END) AS n
               FROM journallines GROUP BY accountid'
        )->fetchAll();
    } catch (PDOException $e) {
        if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
            dt_log_error('[accounts journal] skipped journallines: ' . dt_describe_throwable($e));

            return [];
        }
        throw $e;
    }

    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['accountid']] = [
            'debit'  => round((float)$r['d'], 2),
            'credit' => round((float)$r['c'], 2),
            'net'    => round((float)$r['n'], 2),
        ];
    }

    return $out;
}

/** Old version, never called (delete it when you want). */
function dt_build_balances_old(PDO $pdo): array
{
    $balances = [];
    foreach (DT_BALANCE_SOURCES as [$table, $column, $sign]) {
        try {
            $rows = dt_q(
                $pdo,
                "SELECT `{$column}` AS accountid, SUM(amount) AS total FROM `{$table}` WHERE `{$column}` IS NOT NULL GROUP BY `{$column}`"
            )->fetchAll();
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
                dt_log_error('[accounts balances] skipped ' . $table . '.' . $column . ': ' . dt_describe_throwable($e));
                continue;
            }
            throw $e;
        }
        foreach ($rows as $r) {
            $id            = (int)$r['accountid'];
            $balances[$id] = ($balances[$id] ?? 0.0) + $sign * (float)$r['total'];
        }
    }

    foreach ($balances as $id => $value) {
        $balances[$id] = round($value, 2);
    }

    return $balances;
}

/* ================================================================
 * 6. HTML SHELL (same look as livestock.php): CSS, head, topbar, sidebar, footer
 * ============================================================== */

/**
 * Application stylesheet (dashboard tokens and components, loaded after the CDN styles so the brand wins).
 *
 * @return string CSS rules.
 */
function dt_app_css(): string
{
    return <<<'CSS'
/* ===== Tokens ===== */
:root {
    --brand-primary: #9d1d22;
    --brand-primary-dark: #7a1519;
    --brand-accent: #16a04a;
    --brand-accent-dark: #0f7a37;   /* text on white: AA contrast */
    --text: #4a4f57;
    --heading: #2a1213;
    --muted: #6b727b;
    --line: #e9ecef;
    --surface: #f6f4f4;
    --warning: #9a5b00;
    --info: #1f5f99;
    --danger: #9d1d22;
    --topbar-h: 70px;
}

/* ===== Base ===== */
*, *::before, *::after { box-sizing: border-box; }
html { overflow-x: hidden; min-height: 100%; }
body {
    --side-w: 240px; --left-w: 240px;
    margin: 0; background: var(--surface); color: var(--text);
    font-family: 'Poppins', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    font-size: 15px; line-height: 1.5;
}
body.enlarged { --side-w: 70px; --left-w: 70px; }
h1, h2, h3, h4, h5, h6 { margin: 0; font-weight: 500; color: var(--heading); }
p { margin: 0; }
a { color: inherit; text-decoration: none; }
a:hover { text-decoration: none; }
button, select, input { font-family: inherit; }
[hidden] { display: none !important; }
.ico { width: 18px; height: 18px; flex: none; display: inline-block; vertical-align: middle; }
:focus-visible { outline: 3px solid var(--brand-accent); outline-offset: 2px; }
.skip-link {
    position: fixed; top: 8px; left: 8px; z-index: 2000; padding: 10px 16px;
    background: var(--brand-accent-dark); color: #ffffff; font-weight: 500; transform: translateY(-200%);
}
.skip-link:focus { transform: translateY(0); color: #ffffff; }

/* ===== Topbar ===== */
.topbar { position: fixed; top: 0; left: 0; right: 0; height: var(--topbar-h); z-index: 999; display: flex; }
.topbar-left {
    flex: none; width: var(--left-w); height: var(--topbar-h); background: #ffffff;
    border-right: 1px solid var(--line);
    display: flex; align-items: center; justify-content: center; transition: width .2s ease;
}
.topbar-left a { display: flex; align-items: center; justify-content: center; height: 100%; width: 100%; padding: 0 8px; }
.topbar-left img { height: 46px; width: auto; max-width: 100%; object-fit: contain; display: block; }
.brand-text { font-weight: 600; font-size: 14px; line-height: 1.2; color: var(--brand-primary); text-align: center; }
body.enlarged .brand-text { display: none; }
.navbar-custom {
    flex: 1; min-width: 0; height: var(--topbar-h); display: flex; align-items: center; justify-content: space-between;
    background: #ffffff; padding: 0 12px 0 0;
    box-shadow: 0 2px 3px -2px rgba(0, 0, 0, 0.18);
}
.menu-btn {
    border: 0; background: #ffffff; color: var(--brand-primary); cursor: pointer; height: 100%; width: 60px;
    display: grid; place-items: center; transition: color .15s ease, background-color .15s ease;
}
.menu-btn .ico { width: 24px; height: 24px; }
.menu-btn:hover { color: var(--brand-accent-dark); background: var(--surface); }

.nav-user { position: relative; }
.nav-user-btn {
    display: flex; align-items: center; gap: 10px; border: 1px solid transparent; background: none;
    padding: 4px 8px; cursor: pointer; color: inherit; text-align: left;
    transition: background-color .15s ease, border-color .15s ease;
}
.nav-user-btn:hover { background: var(--surface); border-color: var(--line); }
.avatar {
    width: 36px; height: 36px; background: var(--brand-primary); color: #ffffff;
    display: grid; place-items: center; font-weight: 600; font-size: 14px; flex: none;
}
.user-name { max-width: 170px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; color: var(--heading); }
.chev { width: 14px; height: 14px; color: var(--muted); transition: transform .15s ease; }
.nav-user-btn[aria-expanded="true"] .chev { transform: rotate(180deg); }
.user-dropdown {
    display: none; position: absolute; right: 0; top: calc(100% + 10px); min-width: 230px; background: #ffffff;
    border: 1px solid rgba(0, 0, 0, 0.08); border-top: 3px solid var(--brand-accent);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.14); padding: 6px 0; z-index: 1000;
}
.user-dropdown.show { display: block; }
.user-head { padding: 8px 20px 10px; font-weight: 500; color: var(--heading); }
.dropdown-agency { font-size: 13.5px; font-weight: 400; color: var(--muted); }
.user-sep { height: 1px; background: var(--line); margin: 6px 0; }
.user-item {
    display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 20px; color: var(--text);
    background: none; border: 0; text-align: left; cursor: pointer; font-size: 15px;
}
.user-item:hover { background: var(--surface); color: var(--brand-primary); }
.user-item.danger { color: var(--brand-primary); }
.user-item:disabled { opacity: .7; cursor: wait; }
.dropdown-form { margin: 0; }

/* ===== Sidebar ===== */
.side-menu {
    position: fixed; top: var(--topbar-h); bottom: 0; left: 0; width: 240px; background: #ffffff;
    border-right: 1px solid var(--line);
    overflow-y: auto; overflow-x: hidden; padding: 10px 0 30px; z-index: 10;
    transition: width .2s ease, transform .25s ease;
}
.side-menu ul { list-style: none; margin: 0; padding: 0; }
.side-menu a {
    display: flex; align-items: center; gap: 12px; padding: 13px 20px; font-size: 15px;
    color: var(--text); border-left: 4px solid transparent;
    transition: color .15s ease, background-color .15s ease;
}
.side-menu .ico { color: var(--brand-primary); }
.side-menu a:hover { color: var(--brand-primary); background: var(--surface); }
.side-menu a:focus-visible { outline: none; background: var(--surface); }
.side-menu a.active { color: var(--brand-primary); background: rgba(157, 29, 34, 0.08); font-weight: 600; }
.side-menu a span, .side-menu .nav-btn span { white-space: nowrap; }
.side-menu .nav-btn {
    display: flex; align-items: center; gap: 12px; width: 100%; padding: 13px 20px; font-size: 15px; text-align: left;
    color: var(--text); background: none; border: 0; border-left: 4px solid transparent; cursor: pointer;
    transition: color .15s ease, background-color .15s ease;
}
.side-menu .nav-btn:hover, .side-menu .nav-btn[aria-expanded="true"] { color: var(--brand-primary); background: var(--surface); }
.side-menu .nav-btn:focus-visible { outline: none; background: var(--surface); }
.side-menu .nav-btn > .ico:last-child { margin-left: auto; width: 14px; height: 14px; color: var(--muted); transition: transform .15s ease; }
.side-menu .nav-btn[aria-expanded="true"] > .ico:last-child { transform: rotate(180deg); }
.side-menu .sub { display: none; background: var(--surface); }
.side-menu .sub.show { display: block; }
.side-menu .sub a { padding: 9px 20px 9px 56px; font-size: 14px; }
.backdrop { display: none; }

/* ===== Content ===== */
.content-page { margin-left: var(--side-w); min-height: 100vh; display: flex; flex-direction: column; transition: margin-left .2s ease; }
.content { flex: 1; min-width: 0; padding: var(--topbar-h) 20px 10px; }
.page-title-box { padding: 22px 0 18px; }
.page-title { font-size: 24px; line-height: 34px; }
.page-sub { color: var(--muted); }
.table-scroll { overflow-x: auto; }

/* ===== Cards ===== */
.card {
    background: #ffffff; min-width: 0; border: 0; border-radius: 0;
    box-shadow: 0 -3px 31px 0 rgba(0, 0, 0, 0.04), 0 6px 20px 0 rgba(0, 0, 0, 0.03);
}
.card-body { padding: 20px; }
.card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
.header-title { font-size: 17px; font-weight: 600; color: var(--brand-primary); }

/* ===== Buttons ===== */
.btn { border-radius: 0; font-size: 14px; font-weight: 500; }
.btn-sm { font-size: 13px; }
.btn-primary { background: var(--brand-primary); border-color: var(--brand-primary); color: #ffffff; }
.btn-primary:hover, .btn-primary:focus { background: var(--brand-primary-dark); border-color: var(--brand-primary-dark); color: #ffffff; box-shadow: none; }
.btn-primary:disabled { background: var(--brand-primary); border-color: var(--brand-primary); color: #ffffff; opacity: .6; }
.btn-primary:not(:disabled):not(.disabled):active { background: var(--brand-primary-dark); border-color: var(--brand-primary-dark); }
.btn-success { background: var(--brand-accent-dark); border-color: var(--brand-accent-dark); color: #ffffff; }
.btn-success:hover, .btn-success:focus { background: #0a5a28; border-color: #0a5a28; color: #ffffff; box-shadow: none; }
.btn-success:disabled { background: var(--brand-accent-dark); border-color: var(--brand-accent-dark); color: #ffffff; opacity: .6; }
.btn-danger { background: var(--danger); border-color: var(--danger); color: #ffffff; }
.btn-danger:hover, .btn-danger:focus { background: var(--brand-primary-dark); border-color: var(--brand-primary-dark); color: #ffffff; box-shadow: none; }
.btn-danger:disabled { background: var(--danger); border-color: var(--danger); color: #ffffff; opacity: .6; }
.btn-secondary { background: #545b64; border-color: #545b64; color: #ffffff; }
.btn-secondary:hover, .btn-secondary:focus { background: #3f454c; border-color: #3f454c; color: #ffffff; box-shadow: none; }
.act-btn { padding: 2px 8px; font-size: 13px; }
.btn:focus-visible { outline: 3px solid var(--brand-accent); outline-offset: 2px; box-shadow: none; }

/* ===== Forms ===== */
.form-control {
    height: 38px; padding: 6px 10px; font-size: 15px; color: var(--text); background: #ffffff;
    border: 1px solid var(--line); border-radius: 0;
}
.form-control:focus { border-color: var(--brand-accent); box-shadow: 0 0 0 2px rgba(22, 160, 74, 0.35); outline: 0; color: var(--text); }
.form-control.is-invalid { border-color: var(--danger); background-image: none; padding-right: 10px; }
.form-control.is-invalid:focus { box-shadow: 0 0 0 2px rgba(157, 29, 34, 0.3); }
.invalid-feedback { color: var(--danger); font-size: 13px; font-weight: 500; }
label { color: var(--heading); font-weight: 500; font-size: 14px; }
label .req { color: var(--danger); }

/* ===== Badges ===== */
.badge { display: inline-block; padding: 2px 8px; font-size: 12.5px; font-weight: 600; line-height: 1.5; color: #ffffff; background: var(--muted); border-radius: 0; }
.badge.st-active { background: var(--brand-accent-dark); }
.badge.st-inactive { background: #6c757d; }

/* ===== Modals ===== */
.modal-content { border: 0; border-radius: 0; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25); }
.modal-header { background: var(--brand-primary); color: #ffffff; border-radius: 0; border-bottom: 0; padding: 10px 20px; }
.modal-header .modal-title { color: #ffffff; font-size: 16px; font-weight: 500; margin: 0; }
.modal-header .close { color: #ffffff; opacity: .9; text-shadow: none; }
.modal-header .close:hover { opacity: 1; color: #ffffff; }
.modal-footer { border-top: 1px solid var(--line); padding: 10px 20px; }

/* ===== Tabs ===== */
.nav-tabs { border-bottom: 1px solid var(--line); }
.nav-tabs.compact-tabs { margin-top: 8px; }
.nav-tabs.compact-tabs .nav-link {
    padding: 6px 14px; font-size: 13px; font-weight: 600; border-radius: 0; border: 1px solid transparent;
    background: var(--surface); color: var(--brand-primary); margin-right: 2px;
}
.nav-tabs.compact-tabs .nav-link:hover { color: var(--brand-accent-dark); }
.nav-tabs.compact-tabs .nav-link.active { background: var(--brand-primary); color: #ffffff; border-color: var(--brand-primary); }
.tab-content { padding-top: 16px; }

/* ===== Chart of Accounts ===== */
.acc-table { width: 100% !important; font-size: 14px; }
.acc-table thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); white-space: nowrap; font-weight: 600; }
.acc-table td { vertical-align: middle; }
.acc-table tr.acc-main td { background: var(--surface); color: var(--brand-primary); font-weight: 600; }
.acc-table tr.acc-sub { cursor: pointer; font-weight: 600; }
.acc-table tr.acc-sub td { background: rgba(22, 160, 74, 0.06); }
.toggle-icon {
    display: inline-block; width: 18px; padding: 0; border: 0; background: none; text-align: center;
    font-size: 18px; font-weight: 600; line-height: 1; color: var(--brand-primary); cursor: pointer;
}
.toggle-icon:hover { color: var(--brand-accent-dark); }
.acc-table .child-row.is-collapsed { display: none; }
.acc-table.searching .child-row.is-collapsed { display: table-row; }
.acc-table .child-cell { padding-left: 20px !important; }

/* ===== DataTable ===== */
.table-bordered, .table-bordered td, .table-bordered th { border-color: var(--line); }
.table-hover tbody tr:hover { background: rgba(22, 160, 74, 0.06); }
.dataTables_wrapper { font-size: 14px; }
.dataTables_wrapper .row { margin-left: 0; margin-right: 0; }
.dataTables_wrapper .row > [class*="col-"] { padding-left: 0; padding-right: 0; }
.dataTables_wrapper .dataTables_filter input, .dataTables_wrapper .dataTables_length select { height: 34px; border: 1px solid var(--line); border-radius: 0; padding: 0 8px; }
.dataTables_wrapper .dataTables_length select { width: auto; display: inline-block; }
.dataTables_wrapper .dataTables_filter input:focus, .dataTables_wrapper .dataTables_length select:focus { border-color: var(--brand-accent); outline: 0; box-shadow: 0 0 0 2px rgba(22, 160, 74, 0.35); }
.dt-top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; margin-bottom: .5rem; }
.dt-top .dataTables_length, .dt-top .dataTables_filter { margin: 0; }
.dt-top .dt-len, .dt-top .dt-find { flex: 1 1 0; }
.dt-top .dt-find { text-align: right; }
.dt-btns { max-width: 100%; overflow-x: auto; }
.dt-top .dt-buttons.btn-group { flex-wrap: nowrap !important; }
.dt-buttons .btn { font-size: 13px; padding: 3px 10px; white-space: nowrap; }
.page-item .page-link { color: var(--brand-primary); border-radius: 0; border-color: var(--line); font-size: 14px; }
.page-item.active .page-link { background: var(--brand-primary); border-color: var(--brand-primary); color: #ffffff; }
.page-item.disabled .page-link { color: var(--muted); }
.page-link:hover { color: var(--brand-accent-dark); background: var(--surface); }
table.dataTable thead .sorting:before, table.dataTable thead .sorting:after { opacity: .3; }

/* ===== Toastr ===== */
#toast-container > div { border-radius: 0; opacity: .97; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18); font-family: inherit; font-size: 14px; }
#toast-container > div:hover { box-shadow: 0 8px 24px rgba(0, 0, 0, 0.28); opacity: 1; }
.toast-success { background-color: var(--brand-accent-dark); }
.toast-error { background-color: var(--danger); }
.toast-warning { background-color: var(--warning); }
.toast-info { background-color: var(--info); }
.toast-progress { background-color: rgba(255, 255, 255, 0.55); opacity: 1; }
.toast-close-button { color: #ffffff; text-shadow: none; }

/* ===== Widths ===== */
.px36 { width: 36px; } .px60 { width: 60px; } .px110 { width: 110px; } .px120 { width: 120px; } .px140 { width: 140px; }

/* ===== Footer and scroll-to-top ===== */
.footer { border-top: 1px solid rgba(112, 112, 112, 0.2); text-align: center; padding: 19px 30px 20px; color: var(--muted); }
.scroll-to-top {
    position: fixed; right: 15px; bottom: 15px; display: none; width: 2.75rem; height: 2.75rem;
    text-align: center; color: #ffffff; background: var(--brand-primary); line-height: 2.75rem; z-index: 998;
}
.scroll-to-top:hover { background: var(--brand-primary-dark); color: #ffffff; }

/* ===== Responsive ===== */
@media (min-width: 769px) {
    body.enlarged .side-menu { width: 70px; }
    body.enlarged .side-menu a span, body.enlarged .side-menu .nav-btn span, body.enlarged .side-menu .nav-btn > .ico:last-child { display: none; }
    body.enlarged .side-menu .sub { display: none !important; }
    body.enlarged .side-menu a, body.enlarged .side-menu .nav-btn { justify-content: center; padding: 15px 0; }
    body.enlarged .side-menu .ico { width: 20px; height: 20px; }
}
@media (max-width: 768px) {
    body { --side-w: 0px; --left-w: 70px; overflow-x: hidden; }
    .brand-text { display: none; }
    .content { padding: var(--topbar-h) 12px 10px; }
    .side-menu { transform: translateX(-100%); }
    body.sidebar-open .side-menu { transform: translateX(0); box-shadow: 4px 0 24px rgba(0, 0, 0, 0.18); }
    body.sidebar-open .backdrop {
        display: block; position: fixed; top: var(--topbar-h); right: 0; bottom: 0; left: 0;
        background: rgba(42, 18, 19, 0.5); z-index: 9;
    }
    .dt-top .dt-len { display: none; }
    .dt-top .dt-find { flex: none; }
}
@media (max-width: 575px) { .user-name, .chev { display: none; } }
@media (max-width: 419px) { .topbar-left { display: none; } }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition: none !important; } }
CSS;
}

/**
 * Print the open part of the page: head, CSS, topbar, sidebar, content start.
 *
 * @param array<string,mixed> $p Page context.
 *
 * @return void
 */
function dt_render_open(array $p): void
{
    $n = dt_n();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= dt_e($p['docTitle']) ?> | Bushra Farming and Livestock</title>
  <?php if ($p['logoUrl'] !== ''): ?><link rel="shortcut icon" href="<?= dt_e($p['logoUrl']) ?>"><?php endif; ?>

  <!-- CDN: Poppins, Bootstrap 4.6, Font Awesome 5 (content icons), DataTables, Toastr -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap4.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/2.1.4/toastr.min.css">

  <!-- Application CSS: loaded after every library stylesheet so the brand tokens win -->
  <style nonce="<?= $n ?>">
<?= dt_app_css() ?>
  </style>
</head>
<body>
<a class="skip-link" href="#main">Skip to main content</a>

<script nonce="<?= $n ?>">
    try { if (window.innerWidth > 768 && localStorage.getItem('dt_sidebar') === 'collapsed') document.body.classList.add('enlarged'); } catch (e) {}
</script>

<header class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" aria-label="<?= dt_e($p['agencyName']) ?>: Dashboard">
            <?php if ($p['logoUrl'] !== ''): ?>
                <img src="<?= dt_e($p['logoUrl']) ?>" alt="<?= dt_e($p['agencyName']) ?>" id="logo">
            <?php endif; ?>
            <span class="brand-text" id="brandText"<?= $p['logoUrl'] !== '' ? ' hidden' : '' ?>>Bushra Farming<br>&amp; Livestock</span>
        </a>
    </div>

    <div class="navbar-custom">
        <button type="button" class="menu-btn" id="menuToggle" aria-label="Toggle navigation menu" aria-controls="sidebar-menu" aria-expanded="true" title="Show or hide the menu">
            <?= dt_icon('menu') ?>
        </button>

        <div class="nav-user">
            <button type="button" class="nav-user-btn" id="userToggle" aria-haspopup="true" aria-expanded="false" aria-controls="userMenu">
                <span class="avatar" aria-hidden="true"><?= dt_e($p['initial']) ?></span>
                <span class="user-name"><?= dt_e($p['userName']) ?></span>
                <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="user-dropdown" id="userMenu" role="menu" aria-labelledby="userToggle">
                <div class="user-head"><?= dt_e($p['userName']) ?><div class="dropdown-agency"><?= dt_e($p['agencyName']) ?></div></div>
                <div class="user-sep"></div>
                <?php foreach ($p['userMenu'] as [$label, $page, $icon]): ?>
                    <a class="user-item" role="menuitem" href="<?= dt_e($page) ?>"><?= dt_icon($icon) ?> <?= dt_e($label) ?></a>
                <?php endforeach; ?>
                <?php if ($p['userMenu']): ?><div class="user-sep"></div><?php endif; ?>
                <form class="dropdown-form" id="logoutForm" method="post" action="<?= dt_e($p['logoutUrl']) ?>">
                    <?= $p['csrfField'] ?>
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="user-item danger" role="menuitem" id="logoutBtn">
                        <?= dt_icon('logout') ?> <span id="logoutLabel">Logout</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>

<nav class="side-menu" id="sidebar-menu" aria-label="Main navigation">
    <ul>
        <?php foreach ($p['nav'] as [$key, $label, $icon, $page, $subs]):
            $isAccounting = ($key === 'accounting'); ?>
            <li>
                <?php if ($subs): ?>
                    <button type="button" class="nav-btn" data-sub title="<?= dt_e($label) ?>" aria-expanded="<?= $isAccounting ? 'true' : 'false' ?>" aria-controls="sub-<?= dt_e($key) ?>">
                        <?= dt_icon($icon) ?> <span><?= dt_e($label) ?></span><?= dt_icon('caret') ?>
                    </button>
                    <div class="sub<?= $isAccounting ? ' show' : '' ?>" id="sub-<?= dt_e($key) ?>">
                        <?php foreach ($subs as [$subLabel, $subPage]): ?>
                            <a href="<?= dt_e($subPage) ?>"<?= ($isAccounting && $subPage === 'chartofaccounts.php') ? ' class="active" aria-current="page"' : '' ?>><span><?= dt_e($subLabel) ?></span></a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <a href="<?= dt_e($page) ?>" title="<?= dt_e($label) ?>">
                        <?= dt_icon($icon) ?> <span><?= dt_e($label) ?></span>
                    </a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
<div class="backdrop" id="backdrop"></div>

<div class="content-page">
    <main class="content" id="main">

        <div class="page-title-box">
            <h1 class="page-title"><?= dt_e($p['heading']) ?></h1>
            <?php if ((string)$p['sub'] !== ''): ?><p class="page-sub"><?= dt_e($p['sub']) ?></p><?php endif; ?>
        </div>
<?php
}

/**
 * Print the close of the page content and the footer (dashboard footer).
 *
 * @return void
 */
function dt_render_close(): void
{
    ?>
    </main>

    <footer class="footer">
        &copy; <?= dt_e(date('Y')) ?> Bushra Farming and Livestock. All rights reserved.
    </footer>
</div>
<?php
}

/* ================================================================
 * 7. PAGE MARKUP
 * ============================================================== */

/**
 * One tab pane: the account tree table of a type (or the empty message).
 *
 * @param string              $type     Account type.
 * @param array<string,mixed> $branch   Tree of the type (see dt_build_tree()).
 * @param array<int,float>    $balances Balance per account id.
 * @param bool                $active   True for the first (active) pane.
 *
 * @return void
 */
function dt_node_totals(int $id, array $children, array $balances, array $journal, array &$memo): array
{
    if (isset($memo[$id])) {
        return $memo[$id];
    }
    $t = [(float)($journal[$id]['debit'] ?? 0.0), (float)($journal[$id]['credit'] ?? 0.0), (float)($balances[$id] ?? 0.0)];
    foreach ($children[$id] ?? [] as $c) {
        $s     = dt_node_totals((int)$c['accountid'], $children, $balances, $journal, $memo);
        $t[0] += $s[0];
        $t[1] += $s[1];
        $t[2] += $s[2];
    }

    return $memo[$id] = $t;
}

function dt_render_node(array $a, int $level, array $branch, array $balances, array $journal, array &$memo): void
{
    $id          = (int)$a['accountid'];
    $children    = $branch['children'][$id] ?? [];
    [$d, $c, $b] = dt_node_totals($id, $branch['children'], $balances, $journal, $memo);
    $class       = 'acc-row' . (($level === 0 || $children) ? ' acc-sub' : '') . ($level > 0 ? ' child-row is-collapsed' : '');
    ?>
                        <tr class="<?= $class ?>"<?= dt_row_attrs($a) ?> data-level="<?= $level ?>">
                          <td class="text-center"><?php if ($children): ?><button type="button" class="toggle-icon" aria-expanded="false" aria-label="Expand <?= dt_e((string)$a['accountname']) ?>">+</button><?php endif; ?></td>
                          <td class="lvl-cell"><?= dt_e((string)$a['code']) ?></td>
                          <td class="lvl-cell"><?= dt_e((string)$a['accountname']) ?></td>
                          <td class="text-right"><?= dt_e(dt_money($d)) ?></td>
                          <td class="text-right"><?= dt_e(dt_money($c)) ?></td>
                          <td class="text-right"><?= dt_e(dt_money($b)) ?></td>
                          <td class="text-center"><?= dt_action_buttons() ?></td>
                        </tr>
<?php
    foreach ($children as $ch) {
        dt_render_node($ch, $level + 1, $branch, $balances, $journal, $memo);
    }
}

function dt_render_pane(string $type, array $branch, array $balances, array $journal, bool $active): void
{
    [$pane, $tableId, $mainLabel] = DT_TYPE_INFO[$type];
    $memo    = [];
    $gDebit  = 0.0;
    $gCredit = 0.0;
    $gBal    = 0.0;
    foreach ($branch['groups'] as $g) {
        [$sd, $sc, $sb] = dt_node_totals((int)$g['accountid'], $branch['children'], $balances, $journal, $memo);
        $gDebit  += $sd;
        $gCredit += $sc;
        $gBal    += $sb;
    }
    ?>
                  <div class="tab-pane fade<?= $active ? ' show active' : '' ?>" id="<?= dt_e($pane) ?>" role="tabpanel" aria-labelledby="tab-<?= dt_e($pane) ?>">
<?php if ($branch['count'] === 0): ?>
                    <p class="text-muted mb-0 py-3">No <?= dt_e($type) ?> accounts found.</p>
<?php else: ?>
                    <table id="<?= dt_e($tableId) ?>" class="table table-hover table-bordered acc-table" data-type="<?= dt_e($type) ?>">
                      <thead>
                        <tr>
                          <th class="px36"></th>
                          <th class="px110">Code</th>
                          <th>Account Name</th>
                          <th class="text-right px140">Debit</th>
                          <th class="text-right px140">Credit</th>
                          <th class="text-right px140"><?= $type === 'Assets' ? 'Running Balance' : 'Balance' ?></th>
                          <th class="text-center px120">Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr class="acc-main">
                          <td></td>
                          <td></td>
                          <td><?= dt_e($mainLabel) ?></td>
                          <td></td>
                          <td></td>
                          <td></td>
                          <td></td>
                        </tr>
<?php foreach ($branch['groups'] as $g) { dt_render_node($g, 0, $branch, $balances, $journal, $memo); } ?>
                      </tbody>
                      <tfoot>
                        <tr class="acc-main">
                          <td></td>
                          <td></td>
                          <td><?= dt_e($mainLabel) ?> TOTAL</td>
                          <td class="text-right"><?= dt_e(dt_money($gDebit)) ?></td>
                          <td class="text-right"><?= dt_e(dt_money($gCredit)) ?></td>
                          <td class="text-right"><?= dt_e(dt_money($gBal)) ?></td>
                          <td></td>
                        </tr>
                      </tfoot>
                    </table>
<?php endif; ?>
                  </div>
<?php
}

function dt_render_pane_v2_old(string $type, array $branch, array $balances, array $journal, bool $active): void
{
    [$pane, $tableId, $mainLabel] = DT_TYPE_INFO[$type];
    $gDebit  = 0.0;
    $gCredit = 0.0;
    $gBal    = 0.0;
    ?>
                  <div class="tab-pane fade<?= $active ? ' show active' : '' ?>" id="<?= dt_e($pane) ?>" role="tabpanel" aria-labelledby="tab-<?= dt_e($pane) ?>">
<?php if ($branch['count'] === 0): ?>
                    <p class="text-muted mb-0 py-3">No <?= dt_e($type) ?> accounts found.</p>
<?php else: ?>
                    <table id="<?= dt_e($tableId) ?>" class="table table-hover table-bordered acc-table" data-type="<?= dt_e($type) ?>">
                      <thead>
                        <tr>
                          <th class="px36"></th>
                          <th class="px110">Code</th>
                          <th>Account Name</th>
                          <th class="text-right px140">Debit</th>
                          <th class="text-right px140">Credit</th>
                          <th class="text-right px140"><?= $type === 'Assets' ? 'Running Balance' : 'Balance' ?></th>
                          <th class="text-center px120">Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr class="acc-main">
                          <td></td>
                          <td><?= dt_e($branch['mainCode']) ?></td>
                          <td><?= dt_e($mainLabel) ?></td>
                          <td></td>
                          <td></td>
                          <td></td>
                          <td></td>
                        </tr>
<?php foreach ($branch['groups'] as $g):
    $gid      = (int)$g['accountid'];
    $children = $branch['children'][$gid] ?? [];
    $sd       = (float)($journal[$gid]['debit'] ?? 0.0);
    $sc       = (float)($journal[$gid]['credit'] ?? 0.0);
    $sb       = (float)($balances[$gid] ?? 0.0);
    foreach ($children as $c) {
        $cid = (int)$c['accountid'];
        $sd += (float)($journal[$cid]['debit'] ?? 0.0);
        $sc += (float)($journal[$cid]['credit'] ?? 0.0);
        $sb += (float)($balances[$cid] ?? 0.0);
    }
    $gDebit  += $sd;
    $gCredit += $sc;
    $gBal    += $sb; ?>
                        <tr class="acc-sub"<?= dt_row_attrs($g) ?>>
                          <td class="text-center"><?php if ($children): ?><button type="button" class="toggle-icon" aria-expanded="false" aria-label="Expand <?= dt_e((string)$g['accountname']) ?>">+</button><?php endif; ?></td>
                          <td><?= dt_e((string)$g['code']) ?></td>
                          <td><?= dt_e((string)$g['accountname']) ?></td>
                          <td class="text-right"><?= dt_e(dt_money($sd)) ?></td>
                          <td class="text-right"><?= dt_e(dt_money($sc)) ?></td>
                          <td class="text-right"><?= dt_e(dt_money($sb)) ?></td>
                          <td class="text-center"><?= dt_action_buttons() ?></td>
                        </tr>
<?php foreach ($children as $c): $cid = (int)$c['accountid']; ?>
                        <tr class="child-row is-collapsed"<?= dt_row_attrs($c) ?>>
                          <td></td>
                          <td class="child-cell"><?= dt_e((string)$c['code']) ?></td>
                          <td class="child-cell"><?= dt_e((string)$c['accountname']) ?></td>
                          <td class="text-right"><?= dt_e(dt_money($journal[$cid]['debit'] ?? 0.0)) ?></td>
                          <td class="text-right"><?= dt_e(dt_money($journal[$cid]['credit'] ?? 0.0)) ?></td>
                          <td class="text-right"><?= dt_e(dt_money($balances[$cid] ?? 0.0)) ?></td>
                          <td class="text-center"><?= dt_action_buttons() ?></td>
                        </tr>
<?php endforeach; ?>
<?php endforeach; ?>
                      </tbody>
                      <tfoot>
                        <tr class="acc-main">
                          <td></td>
                          <td></td>
                          <td><?= dt_e($mainLabel) ?> TOTAL</td>
                          <td class="text-right"><?= dt_e(dt_money($gDebit)) ?></td>
                          <td class="text-right"><?= dt_e(dt_money($gCredit)) ?></td>
                          <td class="text-right"><?= dt_e(dt_money($gBal)) ?></td>
                          <td></td>
                        </tr>
                      </tfoot>
                    </table>
<?php endif; ?>
                  </div>
<?php
}

/** Old version, never called (delete it when you want). */
function dt_render_pane_old(string $type, array $branch, array $balances, bool $active): void
{
    [$pane, $tableId, $mainLabel] = DT_TYPE_INFO[$type];
    $isAssets = ($type === 'Assets');
    $grand    = 0.0;
    ?>
                  <div class="tab-pane fade<?= $active ? ' show active' : '' ?>" id="<?= dt_e($pane) ?>" role="tabpanel" aria-labelledby="tab-<?= dt_e($pane) ?>">
<?php if ($branch['count'] === 0): ?>
                    <p class="text-muted mb-0 py-3">No <?= dt_e($type) ?> accounts found.</p>
<?php else: ?>
                    <table id="<?= dt_e($tableId) ?>" class="table table-hover table-bordered acc-table" data-type="<?= dt_e($type) ?>">
                      <thead>
                        <tr>
                          <th class="px36"></th>
                          <th class="px110">Code</th>
                          <th>Account Name</th>
<?php if ($isAssets): ?>
                          <th class="text-right px140">Running Balance</th>
<?php endif; ?>
                          <th class="text-center px120">Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr class="acc-main">
                          <td></td>
                          <td><?= dt_e($branch['mainCode']) ?></td>
                          <td><?= dt_e($mainLabel) ?></td>
<?php if ($isAssets): ?>
                          <td></td>
<?php endif; ?>
                          <td></td>
                        </tr>
<?php foreach ($branch['groups'] as $g):
    $gid      = (int)$g['accountid'];
    $children = $branch['children'][$gid] ?? [];
    $sub      = 0.0;
    if ($isAssets) {
        foreach ($children as $c) {
            $sub += $balances[(int)$c['accountid']] ?? 0.0;
        }
        $grand += $sub;
    } ?>
                        <tr class="acc-sub"<?= dt_row_attrs($g) ?>>
                          <td class="text-center"><?php if ($children): ?><button type="button" class="toggle-icon" aria-expanded="false" aria-label="Expand <?= dt_e((string)$g['accountname']) ?>">+</button><?php endif; ?></td>
                          <td><?= dt_e((string)$g['code']) ?></td>
                          <td><?= dt_e((string)$g['accountname']) ?></td>
<?php if ($isAssets): ?>
                          <td class="text-right"><?= dt_e(dt_money($sub)) ?></td>
<?php endif; ?>
                          <td class="text-center"><?= dt_action_buttons() ?></td>
                        </tr>
<?php foreach ($children as $c): ?>
                        <tr class="child-row is-collapsed"<?= dt_row_attrs($c) ?>>
                          <td></td>
                          <td class="child-cell"><?= dt_e((string)$c['code']) ?></td>
                          <td class="child-cell"><?= dt_e((string)$c['accountname']) ?></td>
<?php if ($isAssets): ?>
                          <td class="text-right"><?= dt_e(dt_money($balances[(int)$c['accountid']] ?? 0.0)) ?></td>
<?php endif; ?>
                          <td class="text-center"><?= dt_action_buttons() ?></td>
                        </tr>
<?php endforeach; ?>
<?php endforeach; ?>
                      </tbody>
<?php if ($isAssets): ?>
                      <tfoot>
                        <tr class="acc-main">
                          <td></td>
                          <td></td>
                          <td>ASSETS TOTAL</td>
                          <td class="text-right"><?= dt_e(dt_money($grand)) ?></td>
                          <td></td>
                        </tr>
                      </tfoot>
<?php endif; ?>
                    </table>
<?php endif; ?>
                  </div>
<?php
}

/**
 * Account list card: header, tabs and one pane per type.
 *
 * @param array<string,array<string,mixed>> $tree     Output of dt_build_tree().
 * @param array<int,float>                  $balances Output of dt_build_balances().
 *
 * @return void
 */
function dt_view_list(array $tree, array $balances, array $journal): void
{
    ?>
        <div class="row">
          <div class="col-12 mb-4">
            <div class="card">
              <div class="card-body">
                <div class="card-head">
                  <h2 class="header-title">Account List</h2>
                  <button type="button" class="btn btn-primary btn-sm" id="btnAdd"><i class="fas fa-plus mr-1" aria-hidden="true"></i> Add Account</button>
                </div>

                <ul class="nav nav-tabs compact-tabs" id="accTabs" role="tablist">
<?php foreach (DT_ACC_TYPES as $i => $type): $pane = DT_TYPE_INFO[$type][0]; ?>
                  <li class="nav-item">
                    <a class="nav-link<?= $i === 0 ? ' active' : '' ?>" id="tab-<?= dt_e($pane) ?>" data-toggle="tab" href="#<?= dt_e($pane) ?>" role="tab" aria-controls="<?= dt_e($pane) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"><?= dt_e($type) ?></a>
                  </li>
<?php endforeach; ?>
                </ul>

                <div class="tab-content">
<?php foreach (DT_ACC_TYPES as $i => $type): ?>
<?php dt_render_pane($type, $tree[$type], $balances, $journal, $i === 0); ?>
<?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php
}

/** Old version, never called (delete it when you want). */
function dt_view_list_old(array $tree, array $balances): void
{
    ?>
        <div class="row">
          <div class="col-12 mb-4">
            <div class="card">
              <div class="card-body">
                <div class="card-head">
                  <h2 class="header-title">Account List</h2>
                  <button type="button" class="btn btn-primary btn-sm" id="btnAdd"><i class="fas fa-plus mr-1" aria-hidden="true"></i> Add Account</button>
                </div>

                <ul class="nav nav-tabs compact-tabs" id="accTabs" role="tablist">
<?php foreach (DT_ACC_TYPES as $i => $type): $pane = DT_TYPE_INFO[$type][0]; ?>
                  <li class="nav-item">
                    <a class="nav-link<?= $i === 0 ? ' active' : '' ?>" id="tab-<?= dt_e($pane) ?>" data-toggle="tab" href="#<?= dt_e($pane) ?>" role="tab" aria-controls="<?= dt_e($pane) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"><?= dt_e($type) ?></a>
                  </li>
<?php endforeach; ?>
                </ul>

                <div class="tab-content">
<?php foreach (DT_ACC_TYPES as $i => $type): ?>
<?php dt_render_pane($type, $tree[$type], $balances, $i === 0); ?>
<?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php
}

/**
 * Error card shown when the accounts could not be loaded.
 *
 * @return void
 */
function dt_view_error(): void
{
    ?>
        <div class="row">
          <div class="col-12 mb-4">
            <div class="card">
              <div class="card-body">
                <h2 class="header-title mb-2">Account List</h2>
                <p class="text-danger mb-0">The accounts could not be loaded. Please refresh the page.</p>
              </div>
            </div>
          </div>
        </div>
<?php
}

/**
 * Add / edit account modal.
 *
 * @return void
 */
function dt_modal_account(): void
{
    ?>
  <div class="modal fade" id="accountModal" tabindex="-1" role="dialog" aria-labelledby="accountModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form class="modal-content" id="accountForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="accountModalTitle">Add Account</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="aId" value="0">
          <div class="form-group">
            <label for="aType">Account type <span class="req">*</span></label>
            <select class="form-control" id="aType" required aria-describedby="aTypeFb">
              <option value="">Select type</option>
              <?php foreach (DT_ACC_TYPES as $type): ?>
                <option value="<?= dt_e($type) ?>"><?= dt_e($type) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback" id="aTypeFb">Account type is required.</div>
          </div>
          <div class="form-group">
            <label for="aParent">Parent group</label>
            <select class="form-control" id="aParent">
              <option value="">None (top-level group)</option>
            </select>
          </div>
          <div class="form-group">
            <label for="aCode">Code <span class="req">*</span></label>
            <input type="text" class="form-control" id="aCode" maxlength="<?= DT_MAX_CODE ?>" required aria-describedby="aCodeFb">
            <div class="invalid-feedback" id="aCodeFb">Code is required.</div>
          </div>
          <div class="form-group">
            <label for="aName">Account name <span class="req">*</span></label>
            <input type="text" class="form-control" id="aName" maxlength="<?= DT_MAX_NAME ?>" required aria-describedby="aNameFb">
            <div class="invalid-feedback" id="aNameFb">Account name is required.</div>
          </div>
          <div class="form-group mb-0">
            <label for="aStatus">Status</label>
            <select class="form-control" id="aStatus">
              <option value="ON" selected>ON</option>
              <option value="OFF">OFF</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveAccount"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Delete account modal.
 *
 * @return void
 */
function dt_modal_delete(): void
{
    ?>
  <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="deleteModalTitle">Delete Account</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          Delete <b id="delName"></b>? This cannot be undone.
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-danger btn-sm" id="btnConfirmDelete"><i class="fas fa-trash mr-1" aria-hidden="true"></i> Delete</button>
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </div>
    </div>
  </div>
<?php
}

/* ================================================================
 * 8. REQUEST FLOW
 * ============================================================== */

/* ================================================================
 * NO LOGIN: nothing is checked here for now (no session, no permission).
 * ============================================================== */

dt_security_headers();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    dt_handle_post(); // never returns
}

if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD, POST');
    dt_json(dt_fail('Method not allowed.'), 405);
}

/* ---------- GET: collect everything the page needs, then release the session ---------- */

$csrf       = dt_csrf_token();
$csrfField  = dt_csrf_field();
$userName   = 'Administrator';
$initial    = mb_strtoupper(mb_substr($userName, 0, 1)) ?: 'A';
$agencyName = 'Bushra Farming and Livestock';
$logoutUrl  = '../index.php';
$logoUrl    = dt_find_logo(DT_LOGO_FILE);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$tree     = [];
$balances = [];
$loadFail = false;
try {
    $pdo      = dt_pdo();
    $tree     = dt_build_tree($pdo);
    $balances = dt_build_balances($pdo);
    $journal  = dt_build_journal($pdo);
} catch (Throwable $e) {
    dt_log_error('[accounts view] ' . dt_describe_throwable($e));
    $loadFail = true;
}

/**
 * Sidebar: key, label, icon, page, submenu links (label, page). Same menu as pages/livestock.php.
 * ALL pages live flat in pages/ (no sales/, people/, accounting/ or reports/ sub-folders), so every link is just a file name.
 * Accounting is open and Chart of Accounts is the active link on this page.
 *
 * @var list<array{0:string,1:string,2:string,3:string,4:list<array{0:string,1:string}>}> $navigation
 */
$navigation = [
    ['dashboard',  'Dashboard',  'home',       'dashboard.php',  []],
    ['livestock',  'Livestock',  'livestock',  'livestock.php',  []],
    ['products',   'Products',   'products',   'products.php',   []],
    ['people',     'People',     'people',     'customers.php', [
        ['Customers',    'customers.php'],
        ['Suppliers',    'suppliers.php'],
        ['Shareholders', 'shareholders.php'],
        ['Employees',    'employees.php'],
    ]],
    ['accounting', 'Accounting', 'accounting', 'chartofaccounts.php', [
        ['Chart of Accounts', 'chartofaccounts.php'],
        ['Receipts',          'receipts.php'],
        ['Customer Deposit',  'customerdeposit.php'],
        ['Payments',          'payments.php'],
        ['Expenses',          'expenses.php'],
        ['Account Transfer',  'accounttransfer.php'],
        ["Owner's Equity",    'equity.php'],
        ['Payrolls',          'payrolls.php'],
        ['Assets',            'assets.php'],
    ]],
    ['reports',    'Reports',    'reports',    'salesreport.php', [
        ['Sales Report',      'salesreport.php'],
        ['Receipts',          'receiptsreport.php'],
        ['Deposit Report',    'depositreport.php'],
        ['Livestock',         'livestockreport.php'],
        ['Customers',         'customersreport.php'],
        ['Suppliers',         'suppliersreport.php'],
        ['Employees',         'employeesreport.php'],
        ['Shareholders',      'shareholdersreport.php'],
        ['Users',             'usersreport.php'],
        ['Chart of Accounts', 'chartofaccountsreport.php'],
        ['Receipts',          'receiptsreport.php'],
        ['Payments',          'paymentsreport.php'],
        ['Cash Report',       'cashreport.php'],
        ['Expenses',          'expensesreport.php'],
        ['Account Transfer',  'accounttransferreport.php'],
        ['Payrolls',          'payrollsreport.php'],
        ['Assets',            'assetsreport.php'],
        ['Income Statement',  'incomestatement.php'],
        ['Equity Statement',  'equitystatement.php'],
        ['Balance Sheet',     'balancesheet.php'],
        ['Cash Flow',         'cashflow.php'],
    ]],
    ['settings',   'Settings',   'settings',   'settings.php',   []],
];

/** @var list<array{0:string,1:string,2:string}> $userMenu label, page, icon (top-right menu) */
$userMenu = [
    ['Users',      'users.php',      'users'],
    ['Activities', 'activities.php', 'activities'],
];

$page = [
    'nav' => $navigation, 'userMenu' => $userMenu, 'userName' => $userName, 'initial' => $initial,
    'agencyName' => $agencyName, 'logoUrl' => $logoUrl, 'logoutUrl' => $logoutUrl,
    'csrfField' => $csrfField, 'docTitle' => 'Chart of Accounts', 'heading' => 'Chart of Accounts',
    'sub' => 'Manage the accounts used by receipts, payments, expenses and reports.',
];

/* ---------- Render ---------- */

if ($loadFail) {
    http_response_code(500);
    dt_render_open($page);
    dt_view_error();
    dt_render_close();
    echo "</body>\n</html>";
    exit;
}

dt_render_open($page);
dt_view_list($tree, $balances, $journal);
dt_render_close();
dt_modal_account();
dt_modal_delete();

$paneTypes = [];
foreach (DT_TYPE_INFO as $type => $info) {
    $paneTypes[$info[0]] = $type;
}
$cfg       = ['csrf' => $csrf, 'self' => 'chartofaccounts.php', 'paneType' => $paneTypes];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$n         = dt_n();

/* ================================================================
 * 9. JAVASCRIPT
 * ============================================================== */
?>

<a class="scroll-to-top" href="#main" aria-label="Back to top"><i class="fas fa-angle-up" aria-hidden="true"></i></a>

<!-- CDN: jQuery 3.5.1 + Bootstrap 4.6 bundle (includes Popper) -->
<script nonce="<?= $n ?>" src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- CDN: DataTables + Buttons (export) -->
<script nonce="<?= $n ?>" src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap4.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script nonce="<?= $n ?>" src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/2.1.4/toastr.min.js"></script>

<!-- ===================== Shell JavaScript (same behavior as dashboard.php) ===================== -->
<script nonce="<?= $n ?>">
(function () {
    'use strict';
    var body     = document.body;
    var toggle   = document.getElementById('menuToggle');
    var backdrop = document.getElementById('backdrop');
    var mq       = window.matchMedia('(max-width: 768px)');
    var KEY      = 'dt_sidebar';

    function syncAria() {
        var open = mq.matches ? body.classList.contains('sidebar-open') : !body.classList.contains('enlarged');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    function closeMobile() { body.classList.remove('sidebar-open'); syncAria(); }

    toggle.addEventListener('click', function () {
        if (mq.matches) {
            body.classList.toggle('sidebar-open');
        } else {
            body.classList.toggle('enlarged');
            try { localStorage.setItem(KEY, body.classList.contains('enlarged') ? 'collapsed' : 'open'); } catch (e) {}
        }
        syncAria();
    });
    backdrop.addEventListener('click', closeMobile);
    var onMq = function () { body.classList.remove('sidebar-open'); if (mq.matches) body.classList.remove('enlarged'); syncAria(); };
    if (mq.addEventListener) { mq.addEventListener('change', onMq); } else if (mq.addListener) { mq.addListener(onMq); }
    syncAria();

    // Sidebar sub-menus (accordion: one open at a time)
    var subBtns = document.querySelectorAll('[data-sub]');
    function setSub(btn, open) {
        var panel = document.getElementById(btn.getAttribute('aria-controls'));
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (panel) { panel.classList.toggle('show', open); }
    }
    Array.prototype.forEach.call(subBtns, function (btn) {
        btn.addEventListener('click', function () {
            // In the narrow (icon only) sidebar, first widen it.
            if (!mq.matches && body.classList.contains('enlarged')) { body.classList.remove('enlarged'); syncAria(); }
            var open = btn.getAttribute('aria-expanded') !== 'true';
            Array.prototype.forEach.call(subBtns, function (other) { setSub(other, false); });
            setSub(btn, open);
        });
    });

    // Logo file that fails to load: swap it for the brand name text.
    var logo      = document.getElementById('logo');
    var brandText = document.getElementById('brandText');
    if (logo) {
        logo.addEventListener('error', function () {
            logo.hidden = true;
            if (brandText) { brandText.hidden = false; }
        });
    }

    // User dropdown (own open/close logic, not Bootstrap's)
    var userBtn  = document.getElementById('userToggle');
    var userMenu = document.getElementById('userMenu');
    function setMenu(open) {
        userMenu.classList.toggle('show', open);
        userBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    userBtn.addEventListener('click', function (e) { e.stopPropagation(); setMenu(!userMenu.classList.contains('show')); });
    document.addEventListener('click', function (e) { if (!userMenu.contains(e.target)) setMenu(false); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (userMenu.classList.contains('show')) { setMenu(false); userBtn.focus(); }
            closeMobile();
        }
    });

    // Logout: confirm, then block double submit
    var logoutForm  = document.getElementById('logoutForm');
    var logoutBtn   = document.getElementById('logoutBtn');
    var logoutLabel = document.getElementById('logoutLabel');
    logoutForm.addEventListener('submit', function (e) {
        if (!window.confirm('Are you sure you want to log out?')) { e.preventDefault(); return; }
        logoutBtn.disabled = true;
        logoutLabel.textContent = 'Logging out\u2026';
    });
    window.addEventListener('pageshow', function () {
        logoutBtn.disabled = false;
        logoutLabel.textContent = 'Logout';
    });
})();
</script>

<!-- ===================== Common JavaScript ===================== -->
<script nonce="<?= $n ?>">
    var CFG = <?= json_encode($cfg, $jsonFlags) ?>;
    var CSRF = CFG.csrf;
    var URL_SELF = CFG.self;
    var PANE_TYPE = CFG.paneType;

    toastr.options = { closeButton: true, progressBar: true, positionClass: 'toast-top-right', timeOut: 5000 };

    /* ---------- Scroll to top ---------- */
    $(window).on('scroll', function () {
      $('.scroll-to-top').toggle($(this).scrollTop() > 100);
    });
    $('.scroll-to-top').on('click', function (e) {
      e.preventDefault();
      $('html, body').animate({ scrollTop: 0 }, 400);
    });

    /* ---------- Helpers ---------- */
    function esc(s) {
      return $('<div>').text(s == null ? '' : s).html();
    }

    // POST helper: always sends CSRF, expects JSON
    function api(data) {
      data.csrf_token = CSRF;
      return $.ajax({ url: URL_SELF, type: 'POST', data: data, dataType: 'json' })
        .fail(function (xhr) {
          var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Request failed. Please try again.';
          toastr.error(msg);
        });
    }
</script>

<!-- ===================== Page JavaScript ===================== -->
<script nonce="<?= $n ?>">
    /* ---------- Safe sessionStorage (storage can be blocked) ---------- */
    function ssGet(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
    function ssSet(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
    function ssDel(k) { try { sessionStorage.removeItem(k); } catch (e) {} }

    // Success: remember the message, reload (the tab and expanded groups are restored)
    function reloadWith(msg) {
      ssSet('ca_flash', msg);
      window.location.reload();
    }

    /* ---------- Expanded groups (kept across reloads) ---------- */
    var openGroups = {};
    try { openGroups = JSON.parse(ssGet('ca_open') || '{}') || {}; } catch (e) { openGroups = {}; }

    function saveOpen() { ssSet('ca_open', JSON.stringify(openGroups)); }

    function setToggle($icon, open) {
      $icon.text(open ? '-' : '+').attr('aria-expanded', open ? 'true' : 'false');
    }

    // Restore BEFORE DataTables starts (all rows are still in the DOM)
    $.each(openGroups, function (id, isOpen) {
      var gid = String(id).replace(/[^0-9]/g, '');
      if (!isOpen || gid === '') { return; }
      setToggle($('tr.acc-row[data-id="' + gid + '"] .toggle-icon'), true);
      /* row visibility for all levels is applied just before DataTables starts (see the block below) */
    });

    /* ---------- Multi-level rows: parent map, visibility, indentation ---------- */
    var PARENT_OF = {};
    $('tr.acc-row').each(function () { PARENT_OF[$(this).attr('data-id')] = $(this).attr('data-parent') || ''; });

    // A row is hidden when any ancestor is collapsed (any depth)
    function dtHidden(id) {
      var pid = PARENT_OF[id] || '', guard = 0;
      while (pid !== '' && guard++ < 100) {
        if (!openGroups[pid]) { return true; }
        pid = PARENT_OF[pid] || '';
      }
      return false;
    }

    $('tr.acc-row').each(function () {
      var $r = $(this);
      $r.toggleClass('is-collapsed', dtHidden($r.attr('data-id')));
      $r.find('td.lvl-cell').css('padding-left', (12 + (parseInt($r.attr('data-level'), 10) || 0) * 22) + 'px');
    });

    /* ---------- DataTables (one per account type) ---------- */
    function exportButtons(label, cols) {
      var title = 'Chart of Accounts - ' + label;
      var base  = { className: 'btn btn-secondary btn-sm', title: title, filename: title, footer: true, exportOptions: { columns: cols } };
      return [
        $.extend({}, base, { extend: 'copyHtml5',  text: '<i class="fas fa-copy"></i> Copy', title: '' }),
        $.extend({}, base, { extend: 'csvHtml5',   text: '<i class="fas fa-file-csv"></i> CSV' }),
        $.extend({}, base, { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel' }),
        $.extend({}, base, {
          extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf"></i> PDF',
          orientation: 'portrait', pageSize: 'A4',
          customize: function (doc) {
            var tbl = null;
            $.each(doc.content, function (_, c) { if (c.table) { tbl = c.table; } });
            if (!tbl) { return; }
            tbl.widths = [60, '*', 70, 70, 70];
            $.each(tbl.body, function (_, row) {
              $.each([2, 3, 4], function (__, k) { if (row[k]) { row[k].alignment = 'right'; } });
            });
          }
        }),
        $.extend({}, base, { extend: 'print',      text: '<i class="fas fa-print"></i> Print' })
      ];
    }

    function exportButtons_old(label, cols, withBalance) {
      var title = 'Chart of Accounts - ' + label;
      var base  = { className: 'btn btn-secondary btn-sm', title: title, filename: title, footer: withBalance, exportOptions: { columns: cols } };
      return [
        $.extend({}, base, { extend: 'copyHtml5',  text: '<i class="fas fa-copy"></i> Copy', title: '' }),
        $.extend({}, base, { extend: 'csvHtml5',   text: '<i class="fas fa-file-csv"></i> CSV' }),
        $.extend({}, base, { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel' }),
        $.extend({}, base, {
          extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf"></i> PDF',
          orientation: 'portrait', pageSize: 'A4',
          customize: function (doc) {
            var tbl = null;
            $.each(doc.content, function (_, c) { if (c.table) { tbl = c.table; } });
            if (!tbl) { return; }
            tbl.widths = withBalance ? [70, '*', 100] : [90, '*'];
            if (withBalance) {
              $.each(tbl.body, function (_, row) { if (row[2]) { row[2].alignment = 'right'; } });
            }
          }
        }),
        $.extend({}, base, { extend: 'print',      text: '<i class="fas fa-print"></i> Print' })
      ];
    }

    $('.acc-table').each(function () {
      var $t       = $(this);
      var label    = $t.attr('data-type');
      var isAssets = (label === 'Assets');
      var lastCol  = 6;

      var dt = $t.DataTable({
        paging: false,
        info: false,
        ordering: false,
        lengthChange: false,
        autoWidth: false,
        columnDefs: [{ targets: [0, lastCol], searchable: false }],
        dom: "<'dt-top'<'dt-len'><'dt-btns'B><'dt-find'f>>" +
             "<'table-scroll'tr>",
        buttons: exportButtons(label, [1, 2, 3, 4, 5]),
        language: { search: 'Search:', searchPlaceholder: 'Search account...', zeroRecords: 'No accounts found' }
      });

      // While searching, matching collapsed rows are shown (CSS .searching); cleared = collapsed state again
      dt.on('search.dt', function () {
        $t.toggleClass('searching', $.trim(dt.search()) !== '');
      });
    });

    /* ---------- Subgroup toggle (one delegated handler: the button or the whole row) ---------- */
    $('.tab-content').on('click', '.acc-table tbody tr.acc-row', function (e) {
      if ($(e.target).closest('.act-btn').length) { return; }

      var $tr   = $(this);
      var $icon = $tr.find('.toggle-icon');
      if (!$icon.length) { return; }

      var id   = $tr.attr('data-id');
      var open = !openGroups[id];
      openGroups[id] = open;
      saveOpen();
      setToggle($icon, open);

      // rows().nodes() also returns rows currently filtered out by the search
      $tr.closest('table').DataTable().rows().nodes().to$()
        .filter('tr.acc-row').each(function () { $(this).toggleClass('is-collapsed', dtHidden($(this).attr('data-id'))); });
    });

    /* ---------- Tabs: remember the active one, fix widths of hidden tables ---------- */
    $('#accTabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
      ssSet('ca_tab', String($(e.target).attr('href')).substring(1));
      $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    });

    var savedTab = ssGet('ca_tab');
    if (savedTab && Object.prototype.hasOwnProperty.call(PANE_TYPE, savedTab)) {
      $('#accTabs a[href="#' + savedTab + '"]').tab('show');
    }

    var flash = ssGet('ca_flash');
    if (flash) { ssDel('ca_flash'); toastr.success(flash); }

    /* ---------- Parent group options ---------- */
    var parentReq = 0;

    function loadParents(type, excludeId, selected) {
      var $p = $('#aParent');
      $p.html('<option value="">None (top-level group)</option>');
      if (!type) { return; }
      var req = ++parentReq;
      api({ action: 'parents', accounttype: type, excludeid: excludeId || 0 }).done(function (res) {
        if (req !== parentReq || !res.success) { return; }
        $.each(res.data, function (i, r) {
          $p.append($('<option>').val(r.accountid).text(new Array((r.level || 0) * 4 + 1).join('\u00A0') + r.code + ' ' + r.accountname));
        });
        if (selected) { $p.val(String(selected)); }
        if ($p.val() === null) { $p.val(''); }
      });
    }

    /* ---------- Add / Edit modal ---------- */
    var FIELDS = {
      accounttype: ['#aType', '#aTypeFb'],
      code:        ['#aCode', '#aCodeFb'],
      accountname: ['#aName', '#aNameFb']
    };
    var DEFAULTS = {};
    $.each(FIELDS, function (key, pair) { DEFAULTS[key] = $(pair[1]).text(); });

    function clearInvalid() {
      $.each(FIELDS, function (key, pair) {
        $(pair[0]).removeClass('is-invalid');
        $(pair[1]).text(DEFAULTS[key]);
      });
    }

    function markInvalid(key, message) {
      if (message) { $(FIELDS[key][1]).text(message); }
      $(FIELDS[key][0]).addClass('is-invalid').trigger('focus');
    }

    $('#aType').on('change', function () {
      $(this).removeClass('is-invalid');
      loadParents($(this).val(), $('#aId').val(), 0);
    });
    $('#aCode, #aName').on('input', function () { $(this).removeClass('is-invalid'); });
    $('#accountModal').on('shown.bs.modal', function () { $('#aType').trigger('focus'); });

    /* ---------- Add ---------- */
    $('#btnAdd').on('click', function () {
      $('#accountForm')[0].reset();
      clearInvalid();
      $('#aId').val(0);
      $('#aStatus').val('ON');

      // preselect the type of the active tab
      var pane = String($('#accTabs .nav-link.active').attr('href') || '').substring(1);
      var type = PANE_TYPE[pane] || '';
      $('#aType').val(type);
      loadParents(type, 0, 0);

      $('#accountModalTitle').text('Add Account');
      $('#accountModal').modal('show');
    });

    /* ---------- Edit ---------- */
    $('.tab-content').on('click', '.acc-table .btn-edit', function () {
      var $tr = $(this).closest('tr');
      $('#accountForm')[0].reset();
      clearInvalid();

      var id   = $tr.attr('data-id');
      var type = $tr.attr('data-type');
      $('#aId').val(id);
      $('#aType').val(type);
      $('#aCode').val($tr.attr('data-code'));
      $('#aName').val($tr.attr('data-name'));
      $('#aStatus').val(String($tr.attr('data-status') || 'ON').toUpperCase() === 'OFF' ? 'OFF' : 'ON');
      loadParents(type, id, $tr.attr('data-parent'));

      $('#accountModalTitle').text('Edit Account');
      $('#accountModal').modal('show');
    });

    /* ---------- Save (add / edit) ---------- */
    $('#accountForm').on('submit', function (e) {
      e.preventDefault();
      var type = $('#aType').val();
      var code = $.trim($('#aCode').val());
      var name = $.trim($('#aName').val());

      clearInvalid();
      var first = null;
      if (!type)       { $('#aType').addClass('is-invalid'); first = first || '#aType'; }
      if (code === '') { $('#aCode').addClass('is-invalid'); first = first || '#aCode'; }
      if (name === '') { $('#aName').addClass('is-invalid'); first = first || '#aName'; }
      if (first) { $(first).trigger('focus'); return; }

      var $btn = $('#btnSaveAccount').prop('disabled', true);
      api({
        action: 'save',
        accountid: $('#aId').val(),
        accounttype: type,
        parentaccountid: $('#aParent').val() || 0,
        code: code,
        accountname: name,
        status: $('#aStatus').val()
      }).done(function (res) {
        if (res.success) {
          $('#accountModal').modal('hide');
          reloadWith(res.message);
          return;
        }
        if (res.field && FIELDS[res.field]) {
          markInvalid(res.field, res.message);
        }
        toastr.error(res.message);
      }).always(function () { $btn.prop('disabled', false); });
    });

    /* ---------- Delete ---------- */
    var deleteId = 0;

    $('.tab-content').on('click', '.acc-table .btn-del', function () {
      var $tr = $(this).closest('tr');
      deleteId = $tr.attr('data-id');
      $('#delName').text($tr.attr('data-code') + ' ' + $tr.attr('data-name'));
      $('#deleteModal').modal('show');
    });

    $('#btnConfirmDelete').on('click', function () {
      var $btn = $(this).prop('disabled', true);
      api({ action: 'delete', accountid: deleteId }).done(function (res) {
        if (res.success) {
          reloadWith(res.message);
        } else if (res.blocked) {
          toastr.warning(res.message);
        } else {
          toastr.error(res.message);
        }
      }).always(function () {
        $('#deleteModal').modal('hide');
        $btn.prop('disabled', false);
      });
    });
</script>
</body>
</html>