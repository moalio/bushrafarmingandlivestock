<?php

declare(strict_types=1);

/**
 * pages/expenses.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: Expenses (sidebar > Accounting > Expenses).
 *
 * Money spent by the business and paid to someone who is not a supplier of a purchase (rent, fuel, repairs, ...).
 * One page, three AJAX actions (POST, JSON): list (server-side DataTable), save, delete.
 * Expense accounts, paid-from accounts and payee suggestions are rendered by PHP on GET.
 *
 * This page writes ONLY to expenses and activitylogs. It never writes to purchases, payments or accounts.
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call the auth helpers of ../db.php right after the
 * "NO LOGIN" marker in the request flow below (and pass the user id to dt_log_expense()).
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

const DT_MAX_DESC   = 255;
const DT_MAX_PAYEE  = 150;
const DT_MAX_AMOUNT = 1000000000;
const DT_MAX_PAGE   = 1000;

const DT_LOGO_FILE = 'bushrofarmingandlivestocklogo.png'; // preferred logo file name
const DT_LOGO_DIRS = ['uploads', 'assets', 'images'];     // folders next to pages/ searched for the logo (in this order)

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
 * Whether a string is a real Y-m-d date.
 *
 * @param mixed $value Raw value.
 *
 * @return bool True when valid.
 */
function dt_valid_date(mixed $value): bool
{
    $value = trim((string)$value);
    $date  = DateTime::createFromFormat('Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}

/**
 * Format an amount: 2 decimals, thousands separator, never "-0.00", no currency symbol.
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
 * Plain 2-decimal string for database writes.
 *
 * @param float $value Amount.
 *
 * @return string e.g. "120.75".
 */
function dt_f2(float $value): string
{
    return number_format($value, 2, '.', '');
}

/**
 * Whether an account row is inactive.
 *
 * @param array<string,mixed> $row Account row.
 *
 * @return bool True when status is not ON.
 */
function dt_is_off(array $row): bool
{
    return strtoupper((string)($row['status'] ?? '')) !== 'ON';
}

/**
 * Option label of an account ("CODE NAME", with " (inactive)" when off).
 *
 * @param array<string,mixed> $row Account row.
 *
 * @return string Label.
 */
function dt_account_label(array $row): string
{
    return $row['code'] . ' ' . $row['accountname'] . (dt_is_off($row) ? ' (inactive)' : '');
}

/**
 * Extra option attributes of an account (inactive options are disabled until the JS unlocks the one in use).
 *
 * @param array<string,mixed> $row Account row.
 *
 * @return string Attribute string (static, trusted).
 */
function dt_account_attrs(array $row): string
{
    return dt_is_off($row) ? ' data-off="1" disabled' : '';
}

/**
 * Whether at least one account of the list is active.
 *
 * @param array<int,array<string,mixed>> $rows Account rows.
 *
 * @return bool True when an active account exists.
 */
function dt_has_active(array $rows): bool
{
    foreach ($rows as $r) {
        if (!dt_is_off($r)) {
            return true;
        }
    }

    return false;
}

/**
 * Print the <option> list of a select (escaped).
 *
 * @param array<int,array<string,mixed>> $rows  Source rows.
 * @param string                         $idKey Key holding the option value.
 * @param Closure                        $label Builds the option text from a row.
 * @param Closure|null                   $attrs Optional: returns extra (static, trusted) attributes from a row.
 *
 * @return void
 */
function dt_render_options(array $rows, string $idKey, Closure $label, ?Closure $attrs = null): void
{
    foreach ($rows as $r) {
        echo '<option value="' . (int)$r[$idKey] . '"' . ($attrs !== null ? $attrs($r) : '') . '>'
            . dt_e($label($r)) . '</option>', "\n";
    }
}

/* ================================================================
 * 3. SHARED BUSINESS RULES (read and validate, check accounts, load and lock, log)
 * ============================================================== */

/**
 * Read and validate the posted expense fields (used by save, before the transaction).
 *
 * @return array{id:int,payee:string,expaccount:int,paidfrom:int,amount:float,date:string,desc:string} Clean fields.
 *
 * @throws DtFail On invalid input.
 */
function dt_read_expense_input(): array
{
    $id         = max(0, (int)($_POST['expenseid'] ?? 0));
    $payee      = trim((string)($_POST['payee'] ?? ''));
    $expAccount = (int)($_POST['expenseaccountid'] ?? 0);
    $paidFrom   = (int)($_POST['paidfromaccountid'] ?? 0);
    $rawAmount  = trim((string)($_POST['amount'] ?? ''));
    $date       = trim((string)($_POST['expensedate'] ?? ''));
    $desc       = trim((string)($_POST['description'] ?? ''));

    if ($payee === '') {
        throw new DtFail('Payee is required.', false, 'payee');
    }
    if (mb_strlen($payee) > DT_MAX_PAYEE) {
        throw new DtFail('The payee is too long (maximum ' . DT_MAX_PAYEE . ' characters).', false, 'payee');
    }
    if ($expAccount <= 0) {
        throw new DtFail('Select a valid expense account.', false, 'expenseaccountid');
    }
    if ($paidFrom <= 0) {
        throw new DtFail('Select a valid account.', false, 'paidfromaccountid');
    }
    if ($rawAmount === '' || !is_numeric($rawAmount)) {
        throw new DtFail('Amount is required.', false, 'amount');
    }
    $amount = round((float)$rawAmount, 2);
    if ($amount <= 0) {
        throw new DtFail('Amount must be greater than 0.', false, 'amount');
    }
    if ($amount >= DT_MAX_AMOUNT) {
        throw new DtFail('Amount is too large.', false, 'amount');
    }
    if (!dt_valid_date($date)) {
        throw new DtFail('A valid date is required.', false, 'expensedate');
    }
    if ($desc === '') {
        throw new DtFail('Description is required.', false, 'description');
    }
    if (mb_strlen($desc) > DT_MAX_DESC) {
        throw new DtFail('The description is too long (maximum ' . DT_MAX_DESC . ' characters).', false, 'description');
    }

    return [
        'id' => $id, 'payee' => $payee, 'expaccount' => $expAccount, 'paidfrom' => $paidFrom,
        'amount' => $amount, 'date' => $date, 'desc' => $desc,
    ];
}

/**
 * Check that an account is a sub-account of the given type that is active, or the one the edited row already uses.
 *
 * @param PDO    $pdo       Connection.
 * @param int    $accountId Account id.
 * @param string $type      Expense | Assets.
 * @param int    $currentId Account currently used by the row being edited (0 = none).
 * @param string $message   Message when the account is not valid.
 * @param string $field     Field name sent with the message.
 *
 * @return string Account name.
 *
 * @throws DtFail When the account is not valid.
 */
function dt_check_account(PDO $pdo, int $accountId, string $type, int $currentId, string $message, string $field): string
{
    $name = dt_q(
        $pdo,
        "SELECT accountname FROM accounts
          WHERE accountid = :id AND accounttype = :t AND parentaccountid IS NOT NULL
            AND (status = 'ON' OR accountid = :cur)",
        ['id' => $accountId, 't' => $type, 'cur' => $currentId]
    )->fetchColumn();
    if ($name === false) {
        throw new DtFail($message, false, $field);
    }

    return (string)$name;
}

/**
 * Name of an account.
 *
 * @param PDO $pdo Connection.
 * @param int $id  Account id.
 *
 * @return string|null Name, or null when the account does not exist.
 */
function dt_account_name(PDO $pdo, int $id): ?string
{
    $name = dt_q($pdo, 'SELECT accountname FROM accounts WHERE accountid = :id', ['id' => $id])->fetchColumn();

    return $name === false ? null : (string)$name;
}

/**
 * Load and lock (FOR UPDATE) an expense. Call inside a transaction.
 *
 * @param PDO $pdo       Connection.
 * @param int $expenseId Expense id.
 *
 * @return array<string,mixed> Row (expenseid, payee, expenseaccountid, paidfromaccountid, amount).
 *
 * @throws DtFail When not found.
 */
function dt_lock_expense(PDO $pdo, int $expenseId): array
{
    $row = dt_q(
        $pdo,
        'SELECT expenseid, payee, expenseaccountid, paidfromaccountid, amount FROM expenses WHERE expenseid = :id FOR UPDATE',
        ['id' => $expenseId]
    )->fetch();
    if (!is_array($row)) {
        throw new DtFail('Expense not found.');
    }

    return $row;
}

/**
 * Write an expense activity row (call before commit). Without login the user is unknown: userid is NULL ("System" on the dashboard).
 *
 * @param PDO    $pdo     Connection.
 * @param string $action  Add | Update | Delete.
 * @param string $verb    added | updated | deleted.
 * @param mixed  $amount  Amount.
 * @param string $payee   Payee.
 * @param string $purpose Expense account name.
 *
 * @return void
 */
function dt_log_expense(PDO $pdo, string $action, string $verb, mixed $amount, string $payee, string $purpose): void
{
    $text = 'Expense ' . $verb . ': ' . dt_money($amount) . ' to ' . $payee . ' (' . $purpose . ')';
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
 * List expenses (DataTables server-side).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_list(PDO $pdo): array
{
    $draw   = (int)($_POST['draw'] ?? 0);
    $start  = max(0, (int)($_POST['start'] ?? 0));
    $length = (int)($_POST['length'] ?? 10);
    if ($length !== -1) {
        $length = max(1, min(DT_MAX_PAGE, $length));
    }
    $search = '';
    if (isset($_POST['search']) && is_array($_POST['search'])) {
        $search = trim((string)($_POST['search']['value'] ?? ''));
    }

    $orderMap = [
        1 => 'e.expenseid', 2 => 'e.payee', 3 => 'ea.accountname', 4 => 'pa.accountname',
        5 => 'e.description', 6 => 'e.amount', 7 => 'e.expensedate',
    ];
    $colIdx = 1;
    $dir    = 'DESC';
    if (isset($_POST['order'][0]) && is_array($_POST['order'][0])) {
        $colIdx = (int)($_POST['order'][0]['column'] ?? 1);
        $dir    = ((string)($_POST['order'][0]['dir'] ?? '') === 'asc') ? 'ASC' : 'DESC';
    }
    $orderCol = $orderMap[$colIdx] ?? 'e.expenseid';

    $from = 'FROM expenses e
             JOIN accounts ea ON ea.accountid = e.expenseaccountid
             LEFT JOIN accounts pa ON pa.accountid = e.paidfromaccountid';

    $recordsTotal = (int)dt_q($pdo, 'SELECT COUNT(*) FROM expenses')->fetchColumn();

    $where  = '';
    $params = [];
    if ($search !== '') {
        $like    = '%' . addcslashes($search, '\\%_') . '%';
        $likeNum = '%' . addcslashes(str_replace(',', '', $search), '\\%_') . '%';
        $where   = 'WHERE (CAST(e.expenseid AS CHAR) LIKE :s1
                      OR e.payee LIKE :s2
                      OR CONCAT(COALESCE(ea.code, \'\'), \' \', COALESCE(ea.accountname, \'\')) LIKE :s3
                      OR CONCAT(COALESCE(pa.code, \'\'), \' \', COALESCE(pa.accountname, \'\')) LIKE :s4
                      OR e.description LIKE :s5
                      OR CAST(ROUND(e.amount, 2) AS CHAR) LIKE :s6
                      OR CAST(e.expensedate AS CHAR) LIKE :s7)';
        $params  = ['s1' => $like, 's2' => $like, 's3' => $like, 's4' => $like, 's5' => $like, 's6' => $likeNum, 's7' => $like];
    }

    $recordsFiltered = (int)dt_q($pdo, "SELECT COUNT(*) {$from} {$where}", $params)->fetchColumn();

    $limit = $length > 0 ? ' LIMIT ' . $length . ' OFFSET ' . $start : '';
    $rows  = dt_q(
        $pdo,
        "SELECT e.expenseid, e.payee, e.expenseaccountid, ea.code AS expensecode, ea.accountname AS expensename,
                e.paidfromaccountid, pa.code AS paidcode, pa.accountname AS paidname,
                e.description, e.amount, e.expensedate
           {$from} {$where}
       ORDER BY {$orderCol} {$dir}, e.expenseid DESC" . $limit,
        $params
    )->fetchAll();

    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'expenseid'           => (int)$r['expenseid'],
            'payee'               => (string)($r['payee'] ?? ''),
            'expenseaccountid'    => (int)$r['expenseaccountid'],
            'expenseaccountname'  => trim((string)($r['expensecode'] ?? '') . ' ' . (string)($r['expensename'] ?? '')),
            'paidfromaccountid'   => $r['paidfromaccountid'] !== null ? (int)$r['paidfromaccountid'] : null,
            'paidfromaccountname' => trim((string)($r['paidcode'] ?? '') . ' ' . (string)($r['paidname'] ?? '')),
            'description'         => (string)($r['description'] ?? ''),
            'amount'              => round((float)$r['amount'], 2),
            'expensedate'         => substr((string)$r['expensedate'], 0, 10),
        ];
    }

    return [
        'success' => true, 'draw' => $draw, 'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered, 'data' => $data,
    ];
}

/**
 * Add (id = 0) or edit an expense (one transaction).
 * Lock order: the expense being edited first, then the accounts are validated.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_save(PDO $pdo): array
{
    $d = dt_read_expense_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        $curExpense = 0;
        $curPaid    = 0;
        if ($d['id'] > 0) {
            $old        = dt_lock_expense($pdo, $d['id']);
            $curExpense = (int)$old['expenseaccountid'];
            $curPaid    = (int)$old['paidfromaccountid'];
        }

        $purpose = dt_check_account($pdo, $d['expaccount'], 'Expense', $curExpense, 'Select a valid expense account.', 'expenseaccountid');
        dt_check_account($pdo, $d['paidfrom'], 'Assets', $curPaid, 'Select a valid account.', 'paidfromaccountid');

        $values = [
            'p'  => $d['payee'],
            'e'  => $d['expaccount'],
            'f'  => $d['paidfrom'],
            'd'  => $d['desc'],
            'm'  => dt_f2($d['amount']),
            'dt' => $d['date'],
        ];

        if ($d['id'] > 0) {
            dt_q(
                $pdo,
                'UPDATE expenses
                    SET payee = :p, expenseaccountid = :e, paidfromaccountid = :f,
                        description = :d, amount = :m, expensedate = :dt
                  WHERE expenseid = :id',
                $values + ['id' => $d['id']]
            );
            dt_log_expense($pdo, 'Update', 'updated', $d['amount'], $d['payee'], $purpose);

            return ['success' => true, 'message' => 'Expense updated successfully.'];
        }

        dt_q(
            $pdo,
            'INSERT INTO expenses (payee, expenseaccountid, paidfromaccountid, description, amount, expensedate)
             VALUES (:p, :e, :f, :d, :m, :dt)',
            $values
        );
        dt_log_expense($pdo, 'Add', 'added', $d['amount'], $d['payee'], $purpose);

        return ['success' => true, 'message' => 'Expense added successfully.'];
    });
}

/**
 * Delete an expense (one transaction). Always allowed.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found.
 */
function dt_act_delete(PDO $pdo): array
{
    $expenseId = max(0, (int)($_POST['expenseid'] ?? 0));

    return dt_transaction(static function (PDO $pdo) use ($expenseId): array {
        $e       = dt_lock_expense($pdo, $expenseId);
        $purpose = dt_account_name($pdo, (int)$e['expenseaccountid']) ?? '';

        dt_q($pdo, 'DELETE FROM expenses WHERE expenseid = :id', ['id' => $expenseId]);
        dt_log_expense($pdo, 'Delete', 'deleted', $e['amount'], (string)$e['payee'], $purpose);

        return ['success' => true, 'message' => 'Expense deleted successfully.'];
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

    $handlers = ['list' => 'dt_act_list', 'save' => 'dt_act_save', 'delete' => 'dt_act_delete'];
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
        dt_log_error('[expenses ' . $action . '] ' . dt_describe_throwable($e));
        if ($action === 'save' && $e->getCode() === '23000') {
            dt_json(dt_fail('The selected account no longer exists.'));
        }
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[expenses ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    }
}

/* ================================================================
 * 5. PAGE DATA (expense accounts, paid-from accounts, payees)
 * ============================================================== */

/**
 * Sub-accounts of a type: active ones plus inactive ones still used by an expense, sorted naturally by code.
 *
 * @param PDO    $pdo  Connection.
 * @param string $type Expense (used as expenses.expenseaccountid) | Assets (used as expenses.paidfromaccountid).
 *
 * @return array<int,array<string,mixed>> Rows (accountid, code, accountname, status).
 */
function dt_load_accounts(PDO $pdo, string $type): array
{
    $used = $type === 'Expense'
        ? 'SELECT expenseaccountid FROM expenses'
        : 'SELECT paidfromaccountid FROM expenses';

    $rows = dt_q(
        $pdo,
        "SELECT accountid, code, accountname, status FROM accounts
          WHERE accounttype = :t AND parentaccountid IS NOT NULL
            AND (status = 'ON' OR accountid IN ({$used}))",
        ['t' => $type]
    )->fetchAll();
    usort($rows, static fn (array $a, array $b): int => strnatcmp((string)$a['code'], (string)$b['code']));

    return $rows;
}

/**
 * Existing payees for the suggestion list.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<int,string> Payee names.
 */
function dt_load_payees(PDO $pdo): array
{
    return array_map(
        'strval',
        dt_q($pdo, 'SELECT DISTINCT payee FROM expenses ORDER BY payee LIMIT 200')->fetchAll(PDO::FETCH_COLUMN)
    );
}

/* ================================================================
 * 6. HTML SHELL (same look as payments.php): CSS, head, topbar, sidebar, footer
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
button, select, input, textarea { font-family: inherit; }
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
textarea.form-control { height: auto; }
.form-control:focus { border-color: var(--brand-accent); box-shadow: 0 0 0 2px rgba(22, 160, 74, 0.35); outline: 0; color: var(--text); }
.form-control.is-invalid { border-color: var(--danger); background-image: none; padding-right: 10px; }
.form-control.is-invalid:focus { box-shadow: 0 0 0 2px rgba(157, 29, 34, 0.3); }
.invalid-feedback { color: var(--danger); font-size: 13px; font-weight: 500; }
label { color: var(--heading); font-weight: 500; font-size: 14px; }
label .req { color: var(--danger); }
.text-muted { color: var(--muted) !important; }

/* ===== Select2 ===== */
.select2-container { width: 100% !important; }
.select2-container--bootstrap4 .select2-selection { border-radius: 0; border: 1px solid var(--line); min-height: 38px; background: #ffffff; }
.select2-container--bootstrap4 .select2-selection--single { height: 38px !important; }
.select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered { line-height: 36px; color: var(--text); font-size: 15px; }
.select2-container--bootstrap4 .select2-selection--single .select2-selection__arrow { height: 36px; }
.select2-container--bootstrap4.select2-container--focus .select2-selection,
.select2-container--bootstrap4.select2-container--open .select2-selection { border-color: var(--brand-accent); box-shadow: 0 0 0 2px rgba(22, 160, 74, 0.35); }
.select2-container--bootstrap4 .select2-dropdown { border-radius: 0; border-color: var(--brand-accent); font-size: 14px; }
.select2-container--bootstrap4 .select2-results__option--highlighted[aria-selected] { background: var(--brand-primary); color: #ffffff; }
.select2-container--bootstrap4 .select2-results__option[aria-selected=true] { background: var(--surface); color: var(--heading); }
.select2-container--bootstrap4 .select2-search--dropdown .select2-search__field { border-radius: 0; border-color: var(--line); }
.select2-container--bootstrap4 .select2-search--dropdown .select2-search__field:focus { border-color: var(--brand-accent); outline: 0; box-shadow: 0 0 0 2px rgba(22, 160, 74, 0.35); }
select.is-invalid + .select2-container--bootstrap4 .select2-selection { border-color: var(--danger); }
.modal .select2-container { z-index: 2000; }

/* ===== Modals ===== */
.modal-content { border: 0; border-radius: 0; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25); }
.modal-header { background: var(--brand-primary); color: #ffffff; border-radius: 0; border-bottom: 0; padding: 10px 20px; }
.modal-header .modal-title { color: #ffffff; font-size: 16px; font-weight: 500; margin: 0; }
.modal-header .close { color: #ffffff; opacity: .9; text-shadow: none; }
.modal-header .close:hover { opacity: 1; color: #ffffff; }
.modal-footer { border-top: 1px solid var(--line); padding: 10px 20px; }

/* ===== DataTable ===== */
#expensesTable { width: 100% !important; font-size: 14px; }
#expensesTable thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); white-space: nowrap; font-weight: 600; }
#expensesTable td { vertical-align: middle; }
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
.px60 { width: 60px; } .px120 { width: 120px; }

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

  <!-- CDN: Poppins, Bootstrap 4.6, Font Awesome 5 (content icons), DataTables, Toastr, Select2 -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap4.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/2.1.4/toastr.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">

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
                            <a href="<?= dt_e($subPage) ?>"<?= ($isAccounting && $subPage === 'expenses.php') ? ' class="active" aria-current="page"' : '' ?>><span><?= dt_e($subLabel) ?></span></a>
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
 * Expense list card.
 *
 * @return void
 */
function dt_view_list(): void
{
    ?>
        <div class="row">
          <div class="col-12 mb-4">
            <div class="card">
              <div class="card-body">
                <div class="card-head">
                  <h2 class="header-title">Expense List</h2>
                  <button type="button" class="btn btn-primary btn-sm" id="btnAdd"><i class="fas fa-plus mr-1" aria-hidden="true"></i> Add Expense</button>
                </div>

                <table id="expensesTable" class="table table-hover table-bordered">
                  <thead>
                    <tr>
                      <th class="px60">#</th>
                      <th>Expense</th>
                      <th>Payee</th>
                      <th>Purpose</th>
                      <th>Account</th>
                      <th>Description</th>
                      <th class="text-right">Amount</th>
                      <th>Date</th>
                      <th class="px120 text-center">Action</th>
                    </tr>
                  </thead>
                  <tbody></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
<?php
}

/**
 * Add / edit expense modal.
 *
 * @param array<int,array<string,mixed>> $expenseAccounts Expense accounts (inactive ones disabled).
 * @param array<int,array<string,mixed>> $paidAccounts    Paid-from accounts (inactive ones disabled).
 * @param array<int,string>              $payees           Existing payees.
 * @param string                         $today            Today (Y-m-d).
 *
 * @return void
 */
function dt_modal_expense(array $expenseAccounts, array $paidAccounts, array $payees, string $today): void
{
    ?>
  <div class="modal fade" id="expenseModal" tabindex="-1" role="dialog" aria-labelledby="expenseModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
      <form class="modal-content" id="expenseForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="expenseModalTitle">Add Expense</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="eId" value="0">
          <div class="form-row">
            <div class="col-md-6">
              <div class="form-group">
                <label for="ePayee">Payee <span class="req">*</span></label>
                <input type="text" class="form-control" id="ePayee" maxlength="<?= DT_MAX_PAYEE ?>" list="payeeList" required>
                <datalist id="payeeList">
                  <?php foreach ($payees as $payee): ?>
                    <option value="<?= dt_e($payee) ?>"></option>
                  <?php endforeach; ?>
                </datalist>
                <div class="invalid-feedback" id="ePayeeFb">Payee is required.</div>
              </div>
              <div class="form-group">
                <label for="eAmount">Amount <span class="req">*</span></label>
                <input type="number" class="form-control" id="eAmount" step="0.01" min="0.01" required>
                <div class="invalid-feedback" id="eAmountFb">Enter an amount greater than 0.</div>
              </div>
              <div class="form-group">
                <label for="eAccount">Expense Account <span class="req">*</span></label>
                <select class="form-control" id="eAccount" required>
                  <option value="">Select expense account</option>
                  <?php dt_render_options(
                      $expenseAccounts,
                      'accountid',
                      static fn (array $r): string => dt_account_label($r),
                      static fn (array $r): string => dt_account_attrs($r)
                  ); ?>
                </select>
                <div class="invalid-feedback" id="eAccountFb">Expense account is required.</div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-group">
                <label for="ePaidFrom">Account <span class="req">*</span></label>
                <select class="form-control" id="ePaidFrom" required>
                  <option value="">Select account</option>
                  <?php dt_render_options(
                      $paidAccounts,
                      'accountid',
                      static fn (array $r): string => dt_account_label($r),
                      static fn (array $r): string => dt_account_attrs($r)
                  ); ?>
                </select>
                <div class="invalid-feedback" id="ePaidFromFb">Account is required.</div>
              </div>
              <div class="form-group">
                <label for="eDesc">Description <span class="req">*</span></label>
                <textarea class="form-control" id="eDesc" rows="3" maxlength="<?= DT_MAX_DESC ?>" required></textarea>
                <div class="invalid-feedback" id="eDescFb">Description is required.</div>
              </div>
              <div class="form-group mb-0">
                <label for="eDate">Date <span class="req">*</span></label>
                <input type="date" class="form-control" id="eDate" value="<?= dt_e($today) ?>" required>
                <div class="invalid-feedback" id="eDateFb">Date is required.</div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveExpense"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Delete expense modal.
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
          <h5 class="modal-title" id="deleteModalTitle">Delete Expense</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body" id="delText"></div>
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
$today      = date('Y-m-d');

$expenseAccounts = [];
$paidAccounts    = [];
$payees          = [];
try {
    $expenseAccounts = dt_load_accounts(dt_pdo(), 'Expense');
    $paidAccounts    = dt_load_accounts(dt_pdo(), 'Assets');
    $payees          = dt_load_payees(dt_pdo());
} catch (Throwable $e) {
    dt_log_error('[expenses view] ' . dt_describe_throwable($e));
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Server error. Please try again.';
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/**
 * Sidebar: key, label, icon, page, submenu links (label, page). Same menu as pages/livestock.php.
 * ALL pages live flat in pages/ (no sales/, people/, accounting/ or reports/ sub-folders), so every link is just a file name.
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
        ['Livestock',         'livestockreport.php'],
        ['Customers',         'customersreport.php'],
        ['Suppliers',         'suppliersreport.php'],
        ['Employees',         'employeesreport.php'],
        ['Shareholders',      'shareholdersreport.php'],
        ['Users',             'usersreport.php'],
        ['Chart of Accounts', 'chartofaccountsreport.php'],
        ['Receipts',          'receiptsreport.php'],
        ['Deposit Report',    'depositreport.php'],
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
    'csrfField' => $csrfField, 'docTitle' => 'Expenses', 'heading' => 'Expenses',
    'sub' => 'Record money spent and the account it was paid from.',
];

/* ---------- Render ---------- */

dt_render_open($page);
dt_view_list();
dt_render_close();
dt_modal_expense($expenseAccounts, $paidAccounts, $payees, $today);
dt_modal_delete();

$cfg = [
    'csrf' => $csrf, 'self' => 'expenses.php', 'today' => $today,
    'hasExpenseAccounts' => dt_has_active($expenseAccounts),
    'hasAssetAccounts'   => dt_has_active($paidAccounts),
];
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
<script nonce="<?= $n ?>" src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

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
    var TODAY = CFG.today;

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

    function fmt(v) {
      return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
    /* ---------- Export ALL matching rows: page length -> -1, redraw, run the standard export, restore the page ---------- */
    function exportAll(name) {
      return function (e, dt, button, config) {
        var self   = this;
        var oldLen = dt.page.len();
        var run    = $.fn.dataTable.ext.buttons[name].action;

        if (oldLen === -1) { run.call(self, e, dt, button, config); return; }

        var oldStart = dt.settings()[0]._iDisplayStart;
        dt.one('draw', function () {
          run.call(self, e, dt, button, config);
          dt.page.len(oldLen);
          dt.settings()[0]._iDisplayStart = oldStart;
          dt.draw(false);
        });
        dt.page.len(-1).draw(false);
      };
    }

    var exportBase = {
      className: 'btn btn-secondary btn-sm',
      title: 'Expenses',
      filename: 'Expenses',
      exportOptions: { columns: [1, 2, 3, 4, 5, 6, 7] }
    };

    /* ---------- DataTable (server-side) ---------- */
    var table = $('#expensesTable').DataTable({
      serverSide: true,
      autoWidth: false,
      searchDelay: 400,
      ajax: {
        url: URL_SELF,
        type: 'POST',
        data: function (d) { d.action = 'list'; d.csrf_token = CSRF; },
        dataSrc: 'data',
        error: function (xhr) { if (window.console) { console.warn('expenses list request failed (HTTP ' + (xhr && xhr.status) + ')'); } toastr.error('Could not load expenses.'); }
      },
      columns: [
        {
          data: null, orderable: false, searchable: false,
          render: function (d, type, row, meta) { return meta.row + meta.settings._iDisplayStart + 1; }
        },
        { data: 'expenseid',           render: function (d) { return esc(d); } },
        { data: 'payee',               render: function (d) { return esc(d); } },
        { data: 'expenseaccountname',  render: function (d) { return esc(d); } },
        { data: 'paidfromaccountname', render: function (d) { return esc(d); } },
        { data: 'description',         render: function (d) { return esc(d); } },
        { data: 'amount',              className: 'text-right', render: function (d) { return esc(fmt(d)); } },
        { data: 'expensedate',         render: function (d) { return esc(d); } },
        {
          data: null, orderable: false, searchable: false, className: 'text-center',
          render: function () {
            return '<button type="button" class="btn btn-primary act-btn btn-edit" title="Edit" aria-label="Edit expense"><i class="fas fa-edit" aria-hidden="true"></i></button> ' +
                   '<button type="button" class="btn btn-danger act-btn btn-del" title="Delete" aria-label="Delete expense"><i class="fas fa-trash" aria-hidden="true"></i></button>';
          }
        }
      ],
      order: [[1, 'desc']],
      pageLength: 10,
      lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
      dom: "<'dt-top'<'dt-len'l><'dt-btns'B><'dt-find'f>>" +
           "<'table-scroll'tr>" +
           "<'row mt-2'<'col-md-5'i><'col-md-7'p>>",
      buttons: [
        $.extend({}, exportBase, { extend: 'copyHtml5',  text: '<i class="fas fa-copy"></i> Copy',        title: '', action: exportAll('copyHtml5') }),
        $.extend({}, exportBase, { extend: 'csvHtml5',   text: '<i class="fas fa-file-csv"></i> CSV',     action: exportAll('csvHtml5') }),
        $.extend({}, exportBase, { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel', action: exportAll('excelHtml5') }),
        $.extend({}, exportBase, {
          extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf"></i> PDF',
          orientation: 'landscape', pageSize: 'A4',
          action: exportAll('pdfHtml5'),
          customize: function (doc) {
            var tbl = null;
            $.each(doc.content, function (i, c) { if (c.table) { tbl = c.table; } });
            if (!tbl) { return; }
            tbl.widths = [42, '*', '*', '*', '*', 70, 62];
            $.each(tbl.body, function (i, row) { if (row[5]) { row[5].alignment = 'right'; } });
          }
        }),
        $.extend({}, exportBase, { extend: 'print',      text: '<i class="fas fa-print"></i> Print',      action: exportAll('print') })
      ],
      language: { search: 'Search:', searchPlaceholder: 'Search expense...', emptyTable: 'No expenses found', zeroRecords: 'No expenses found' }
    });

    function rowData(el) { return table.row($(el).closest('tr')).data(); }

    /* ---------- Select2 ---------- */
    $('#eAccount, #ePaidFrom').select2({ theme: 'bootstrap4', width: '100%', dropdownParent: $('#expenseModal') });

    /* ---------- Fields: server field name => input selector (feedback element is selector + "Fb") ---------- */
    var FIELDS = {
      payee: '#ePayee',
      expenseaccountid: '#eAccount',
      paidfromaccountid: '#ePaidFrom',
      amount: '#eAmount',
      expensedate: '#eDate',
      description: '#eDesc'
    };
    var DEFAULT_FB = {};
    $.each(FIELDS, function (key, sel) { DEFAULT_FB[key] = $(sel + 'Fb').text(); });

    function clearInvalid() {
      $.each(FIELDS, function (key, sel) {
        $(sel).removeClass('is-invalid');
        $(sel + 'Fb').text(DEFAULT_FB[key]);
      });
    }

    function markInvalid(key, message) {
      var sel = FIELDS[key];
      if (message) { $(sel + 'Fb').text(message); }
      $(sel).addClass('is-invalid');
      return sel;
    }

    function focusField(sel) {
      if ($(sel).is('select')) { $(sel).select2('open'); } else { $(sel).trigger('focus'); }
    }

    $('#ePayee, #eAccount, #ePaidFrom, #eAmount, #eDate, #eDesc').on('input change', function () { $(this).removeClass('is-invalid'); });

    // Inactive accounts are selectable only while editing the row that currently uses them
    function lockOff($sel, keep) {
      var k = keep ? String(keep) : '';
      $sel.find('option[data-off="1"]').each(function () {
        this.disabled = (this.value !== k);
      });
    }

    function setSelect($sel, val) {
      $sel.val(val ? String(val) : '');
      if ($sel.val() === null) { $sel.val(''); }
      $sel.trigger('change.select2');
    }

    /* ---------- Add ---------- */
    $('#btnAdd').on('click', function () {
      if (!CFG.hasExpenseAccounts) {
        toastr.warning('Add an active expense account in Chart of Accounts first.');
        return;
      }
      if (!CFG.hasAssetAccounts) {
        toastr.warning('Add an active asset account in Chart of Accounts first.');
        return;
      }
      $('#expenseForm')[0].reset();
      clearInvalid();
      $('#eId').val(0);
      $('#eDate').val(TODAY);
      lockOff($('#eAccount'), 0);
      lockOff($('#ePaidFrom'), 0);
      setSelect($('#eAccount'), '');
      setSelect($('#ePaidFrom'), '');
      $('#expenseModalTitle').text('Add Expense');
      $('#expenseModal').modal('show');
    });

    /* ---------- Edit ---------- */
    $('#expensesTable tbody').on('click', '.btn-edit', function () {
      var d = rowData(this);
      if (!d) { return; }

      $('#expenseForm')[0].reset();
      clearInvalid();
      $('#eId').val(d.expenseid);
      $('#ePayee').val(d.payee);
      $('#eAmount').val(Number(d.amount).toFixed(2));
      $('#eDate').val(d.expensedate);
      $('#eDesc').val(d.description || '');
      lockOff($('#eAccount'), d.expenseaccountid);
      lockOff($('#ePaidFrom'), d.paidfromaccountid);
      setSelect($('#eAccount'), d.expenseaccountid);
      setSelect($('#ePaidFrom'), d.paidfromaccountid);

      $('#expenseModalTitle').text('Edit Expense');
      $('#expenseModal').modal('show');
    });

    /* ---------- Save (add / edit) ---------- */
    $('#expenseForm').on('submit', function (e) {
      e.preventDefault();
      clearInvalid();

      var payee   = $.trim($('#ePayee').val());
      var amount  = parseFloat($('#eAmount').val());
      var account = $('#eAccount').val();
      var paid    = $('#ePaidFrom').val();
      var desc    = $.trim($('#eDesc').val());
      var date    = $('#eDate').val();

      if (!payee)                       { focusField(markInvalid('payee')); return; }
      if (isNaN(amount) || amount <= 0) { focusField(markInvalid('amount')); return; }
      if (!account)                     { focusField(markInvalid('expenseaccountid')); return; }
      if (!paid)                        { focusField(markInvalid('paidfromaccountid')); return; }
      if (!desc)                        { focusField(markInvalid('description')); return; }
      if (!date)                        { focusField(markInvalid('expensedate')); return; }

      var $btn = $('#btnSaveExpense').prop('disabled', true);
      api({
        action: 'save',
        expenseid: $('#eId').val(),
        payee: payee,
        expenseaccountid: account,
        paidfromaccountid: paid,
        amount: $('#eAmount').val(),
        expensedate: date,
        description: desc
      }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#expenseModal').modal('hide');
          table.ajax.reload(null, false);
          return;
        }
        if (res.field && FIELDS[res.field]) {
          focusField(markInvalid(res.field, res.message));
        }
        toastr.error(res.message);
      }).always(function () { $btn.prop('disabled', false); });
    });

    /* ---------- Delete ---------- */
    var deleteId = 0;

    $('#expensesTable tbody').on('click', '.btn-del', function () {
      var d = rowData(this);
      if (!d) { return; }
      deleteId = d.expenseid;
      $('#delText').empty().append(
        document.createTextNode('Delete expense '),
        $('<b>').text('#' + d.expenseid),
        document.createTextNode(' (' + fmt(d.amount) + ', ' + (d.payee || '') + ')? This cannot be undone.')
      );
      $('#deleteModal').modal('show');
    });

    $('#btnConfirmDelete').on('click', function () {
      var $btn = $(this).prop('disabled', true);
      api({ action: 'delete', expenseid: deleteId }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          table.ajax.reload(null, false);
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