<?php

declare(strict_types=1);

/**
 * pages/equity.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: Owner's Equity (sidebar > Accounting > Owner's Equity).
 *
 * Three tabs: Capital (money the shareholders put into the business), Drawing (money they take out)
 * and Shares (the percentage each owner or group of owners holds).
 * One page, seven AJAX actions (POST, JSON): list (server-side DataTable), balance, save, delete,
 * listshares, saveshare, deleteshare.
 * Shareholders and accounts are rendered by PHP on GET.
 *
 * Capital raises the balance of an asset account, Drawing lowers it and can never take more than
 * the balance of that account. Shares can never add up to more than 100.00 percent.
 * This page writes ONLY to equity, shareholdershares and activitylogs.
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call the auth helpers of ../db.php right after the
 * "NO LOGIN" marker in the request flow below (and pass the user id to dt_log_equity()).
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
const DT_MAX_NOTE   = 255;
const DT_MAX_GROUP  = 150;
const DT_MAX_AMOUNT = 1000000000;
const DT_MAX_PAGE   = 1000;
const DT_TYPES      = ['Capital', 'Drawing'];

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
 * Amount (or percentage) in whole hundredths, so comparisons never use floats.
 *
 * @param mixed $value Amount.
 *
 * @return int Cents (or hundredths of a percent).
 */
function dt_cents(mixed $value): int
{
    return (int)round(((float)$value) * 100);
}

/**
 * Hundredths as a plain 2-decimal string (no thousands separator): 2500 becomes "25.00".
 *
 * @param int $hundredths Hundredths.
 *
 * @return string Text.
 */
function dt_hundredths(int $hundredths): string
{
    return number_format($hundredths / 100, 2, '.', '');
}

/* ================================================================
 * 3. SHARED BUSINESS RULES (read and validate, load and lock, balance, shares, log)
 * ============================================================== */

/**
 * Read and validate the posted equity fields (used by save, before the transaction).
 *
 * @return array{id:int,holder:int,account:int,type:string,amount:float,date:string,desc:?string} Clean fields (empty description becomes null).
 *
 * @throws DtFail On invalid input.
 */
function dt_read_equity_input(): array
{
    $id        = max(0, (int)($_POST['equityid'] ?? 0));
    $holder    = (int)($_POST['shareholderid'] ?? 0);
    $account   = (int)($_POST['accountid'] ?? 0);
    $type      = trim((string)($_POST['equitytype'] ?? ''));
    $rawAmount = trim((string)($_POST['amount'] ?? ''));
    $date      = trim((string)($_POST['equitydate'] ?? ''));
    $desc      = trim((string)($_POST['description'] ?? ''));

    if (!in_array($type, DT_TYPES, true)) {
        throw new DtFail('Invalid type.', false, 'equitytype');
    }
    if ($holder <= 0) {
        throw new DtFail('Shareholder is required.', false, 'shareholderid');
    }
    if ($account <= 0) {
        throw new DtFail('Account is required.', false, 'accountid');
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
        throw new DtFail('A valid date is required.', false, 'equitydate');
    }
    if (mb_strlen($desc) > DT_MAX_DESC) {
        throw new DtFail('The description is too long (maximum ' . DT_MAX_DESC . ' characters).', false, 'description');
    }

    return [
        'id' => $id, 'holder' => $holder, 'account' => $account, 'type' => $type,
        'amount' => $amount, 'date' => $date, 'desc' => $desc === '' ? null : $desc,
    ];
}

/**
 * Name of a shareholder.
 *
 * @param PDO $pdo Connection.
 * @param int $id  Shareholder id.
 *
 * @return string|null Name, or null when the shareholder does not exist.
 */
function dt_shareholder_name(PDO $pdo, int $id): ?string
{
    $name = dt_q($pdo, 'SELECT name FROM shareholders WHERE shareholderid = :id', ['id' => $id])->fetchColumn();

    return $name === false ? null : (string)$name;
}

/**
 * Load and lock (FOR UPDATE) an equity row. Call inside a transaction.
 *
 * @param PDO $pdo      Connection.
 * @param int $equityId Equity id.
 *
 * @return array<string,mixed> Row (equityid, shareholderid, accountid, equitytype, amount).
 *
 * @throws DtFail When not found.
 */
function dt_lock_equity(PDO $pdo, int $equityId): array
{
    $row = dt_q(
        $pdo,
        'SELECT equityid, shareholderid, accountid, equitytype, amount FROM equity WHERE equityid = :id FOR UPDATE',
        ['id' => $equityId]
    )->fetch();
    if (!is_array($row)) {
        throw new DtFail('Record not found.');
    }

    return $row;
}

/**
 * Lock (FOR UPDATE) an account row. Call inside a transaction, after the equity row is locked.
 *
 * @param PDO $pdo       Connection.
 * @param int $accountId Account id.
 *
 * @return void
 *
 * @throws DtFail When the account does not exist.
 */
function dt_lock_account(PDO $pdo, int $accountId): void
{
    $found = dt_q($pdo, 'SELECT accountid FROM accounts WHERE accountid = :id FOR UPDATE', ['id' => $accountId])->fetchColumn();
    if ($found === false) {
        throw new DtFail('Select a valid active account.', false, 'accountid');
    }
}

/**
 * Check that the account is an Assets sub-account that is active, or the account the row being edited already uses.
 *
 * @param PDO $pdo        Connection.
 * @param int $accountId  Account id.
 * @param int $currentId  Account already used by the row being edited (0 for a new row).
 *
 * @return void
 *
 * @throws DtFail When the account is not valid.
 */
function dt_check_account(PDO $pdo, int $accountId, int $currentId): void
{
    $row = dt_q(
        $pdo,
        "SELECT status FROM accounts WHERE accountid = :a AND accounttype = 'Assets' AND parentaccountid IS NOT NULL",
        ['a' => $accountId]
    )->fetch();
    if (!is_array($row) || ((string)$row['status'] !== 'ON' && $accountId !== $currentId)) {
        throw new DtFail('Select a valid active account.', false, 'accountid');
    }
}

/**
 * Whether an account is an Assets sub-account (any status).
 *
 * @param PDO $pdo       Connection.
 * @param int $accountId Account id.
 *
 * @return bool True when it is.
 */
function dt_is_asset_account(PDO $pdo, int $accountId): bool
{
    if ($accountId <= 0) {
        return false;
    }

    return dt_q(
        $pdo,
        "SELECT accountid FROM accounts WHERE accountid = :a AND accounttype = 'Assets' AND parentaccountid IS NOT NULL",
        ['a' => $accountId]
    )->fetchColumn() !== false;
}

/**
 * Balance of an account in cents, in ONE statement (a different named placeholder for every occurrence).
 * Counts receipts, customer deposits, transfers in and capital; takes away payments, purchases paid from the account,
 * deposit refunds, sale refunds, expenses, transfers out and drawings. The equity row being edited is left out of both equity sums.
 * The balance may be negative.
 *
 * @param PDO $pdo       Connection.
 * @param int $accountId Account id.
 * @param int $exclude   Equity id left out of the equity sums (0 = none).
 *
 * @return int Balance in cents.
 */
function dt_account_balance_cents(PDO $pdo, int $accountId, int $exclude = 0): int
{
    $sql = "SELECT CAST(ROUND((
                  (SELECT COALESCE(SUM(amount), 0) FROM receipts WHERE accountid = :a1)
                + (SELECT COALESCE(SUM(amount), 0) FROM customerdeposits WHERE accountid = :a2)
                + (SELECT COALESCE(SUM(amount), 0) FROM accounttransfers WHERE toaccountid = :a3)
                + (SELECT COALESCE(SUM(amount), 0) FROM equity WHERE accountid = :a4 AND equitytype = 'Capital' AND equityid <> :x1)
                - (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE accountid = :a5)
                - (SELECT COALESCE(SUM(paidamount), 0) FROM purchases WHERE paidfromaccountid = :a6)
                - (SELECT COALESCE(SUM(amount), 0) FROM customerdepositrefunds WHERE accountid = :a7)
                - (SELECT COALESCE(SUM(amount), 0) FROM salerefunds WHERE accountid = :a8)
                - (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE paidfromaccountid = :a9)
                - (SELECT COALESCE(SUM(amount), 0) FROM accounttransfers WHERE fromaccountid = :a10)
                - (SELECT COALESCE(SUM(amount), 0) FROM equity WHERE accountid = :a11 AND equitytype = 'Drawing' AND equityid <> :x2)
            ) * 100, 0) AS SIGNED) AS cents";

    $params = ['x1' => $exclude, 'x2' => $exclude];
    for ($i = 1; $i <= 11; $i++) {
        $params['a' . $i] = $accountId;
    }

    return (int)dt_q($pdo, $sql, $params)->fetchColumn();
}

/**
 * Read and validate the posted share fields (used by saveshare, before the transaction).
 *
 * @return array{id:int,holder:int,group:?string,pct:int,note:?string} Clean fields (pct in hundredths, empty values become null).
 *
 * @throws DtFail On invalid input.
 */
function dt_read_share_input(): array
{
    $id        = max(0, (int)($_POST['shareid'] ?? 0));
    $holder    = max(0, (int)($_POST['shareholderid'] ?? 0));
    $group     = trim((string)($_POST['groupname'] ?? ''));
    $rawPct    = trim((string)($_POST['sharepercent'] ?? ''));
    $note      = trim((string)($_POST['note'] ?? ''));

    if (($holder > 0) === ($group !== '')) {
        throw new DtFail('Choose a shareholder or write a group name.', false, 'shareholderid');
    }
    if (mb_strlen($group) > DT_MAX_GROUP) {
        throw new DtFail('The group name is too long (maximum ' . DT_MAX_GROUP . ' characters).', false, 'groupname');
    }
    if ($rawPct === '' || !is_numeric($rawPct)) {
        throw new DtFail('Percentage must be between 0.01 and 100.', false, 'sharepercent');
    }
    $pct = dt_cents($rawPct);
    if ($pct < 1 || $pct > 10000) {
        throw new DtFail('Percentage must be between 0.01 and 100.', false, 'sharepercent');
    }
    if (mb_strlen($note) > DT_MAX_NOTE) {
        throw new DtFail('The note is too long (maximum ' . DT_MAX_NOTE . ' characters).', false, 'note');
    }

    return [
        'id' => $id, 'holder' => $holder, 'group' => $group === '' ? null : $group,
        'pct' => $pct, 'note' => $note === '' ? null : $note,
    ];
}

/**
 * Load and lock (FOR UPDATE) a share row. Call inside a transaction.
 *
 * @param PDO $pdo     Connection.
 * @param int $shareId Share id.
 *
 * @return array<string,mixed> Row (shareid, shareholderid, groupname, sharepercent).
 *
 * @throws DtFail When not found.
 */
function dt_lock_share(PDO $pdo, int $shareId): array
{
    $row = dt_q(
        $pdo,
        'SELECT shareid, shareholderid, groupname, sharepercent FROM shareholdershares WHERE shareid = :id FOR UPDATE',
        ['id' => $shareId]
    )->fetch();
    if (!is_array($row)) {
        throw new DtFail('Share not found.');
    }

    return $row;
}

/**
 * Lock ALL share rows (ordered by id) and return the sum of their percentages in hundredths, leaving out one row.
 *
 * @param PDO $pdo     Connection.
 * @param int $exclude Share id left out of the sum (0 = none).
 *
 * @return int Sum in hundredths.
 */
function dt_locked_share_total(PDO $pdo, int $exclude): int
{
    $total = 0;
    $rows  = dt_q($pdo, 'SELECT shareid, sharepercent FROM shareholdershares ORDER BY shareid FOR UPDATE')->fetchAll();
    foreach ($rows as $r) {
        if ((int)$r['shareid'] !== $exclude) {
            $total += dt_cents($r['sharepercent']);
        }
    }

    return $total;
}

/**
 * Name shown for a share row: the shareholder name, or the group name.
 *
 * @param PDO                 $pdo Connection.
 * @param array<string,mixed> $row Share row (shareholderid, groupname).
 *
 * @return string Holder name.
 */
function dt_share_holder_name(PDO $pdo, array $row): string
{
    if ($row['shareholderid'] !== null) {
        return dt_shareholder_name($pdo, (int)$row['shareholderid']) ?? '';
    }

    return (string)($row['groupname'] ?? '');
}

/**
 * Activity text of an equity record: "Capital added: 1,200.00 from Ali" / "Drawing deleted: 50.00 by Ali".
 *
 * @param string $type   Capital | Drawing.
 * @param string $verb   added | updated | deleted.
 * @param mixed  $amount Amount.
 * @param string $holder Shareholder name.
 *
 * @return string Description.
 */
function dt_equity_text(string $type, string $verb, mixed $amount, string $holder): string
{
    return $type . ' ' . $verb . ': ' . dt_money($amount) . ($type === 'Capital' ? ' from ' : ' by ') . $holder;
}

/**
 * Write an activity row (call before commit). Without login the user is unknown: userid is NULL ("System" on the dashboard).
 *
 * @param PDO    $pdo    Connection.
 * @param string $action Add | Update | Delete.
 * @param string $text   Description.
 *
 * @return void
 */
function dt_log_equity(PDO $pdo, string $action, string $text): void
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
 * List equity rows of one type (DataTables server-side).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_list(PDO $pdo): array
{
    $type   = ((string)($_POST['type'] ?? '') === 'Drawing') ? 'Drawing' : 'Capital';
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
        1 => 'e.equityid', 2 => 's.name', 3 => 'a.accountname',
        4 => 'e.description', 5 => 'e.amount', 6 => 'e.equitydate',
    ];
    $colIdx = 1;
    $dir    = 'DESC';
    if (isset($_POST['order'][0]) && is_array($_POST['order'][0])) {
        $colIdx = (int)($_POST['order'][0]['column'] ?? 1);
        $dir    = ((string)($_POST['order'][0]['dir'] ?? '') === 'asc') ? 'ASC' : 'DESC';
    }
    $orderCol = $orderMap[$colIdx] ?? 'e.equityid';

    $from = 'FROM equity e
             JOIN shareholders s ON s.shareholderid = e.shareholderid
             LEFT JOIN accounts a ON a.accountid = e.accountid';

    $recordsTotal = (int)dt_q($pdo, 'SELECT COUNT(*) FROM equity WHERE equitytype = :t', ['t' => $type])->fetchColumn();

    $where  = 'WHERE e.equitytype = :type';
    $params = ['type' => $type];
    if ($search !== '') {
        $like    = '%' . addcslashes($search, '\\%_') . '%';
        $likeNum = '%' . addcslashes(str_replace(',', '', $search), '\\%_') . '%';
        $where  .= ' AND (CAST(e.equityid AS CHAR) LIKE :s1
                      OR s.name LIKE :s2
                      OR CONCAT(COALESCE(a.code, \'\'), \' \', COALESCE(a.accountname, \'\')) LIKE :s3
                      OR e.description LIKE :s4
                      OR CAST(ROUND(e.amount, 2) AS CHAR) LIKE :s5
                      OR CAST(e.equitydate AS CHAR) LIKE :s6)';
        $params += ['s1' => $like, 's2' => $like, 's3' => $like, 's4' => $like, 's5' => $likeNum, 's6' => $like];
    }

    $recordsFiltered = (int)dt_q($pdo, "SELECT COUNT(*) {$from} {$where}", $params)->fetchColumn();

    $limit = $length > 0 ? ' LIMIT ' . $length . ' OFFSET ' . $start : '';
    $rows  = dt_q(
        $pdo,
        "SELECT e.equityid, e.shareholderid, s.name AS shareholdername, e.accountid,
                a.code AS accountcode, a.accountname, e.equitytype, e.description, e.amount, e.equitydate
           {$from} {$where}
       ORDER BY {$orderCol} {$dir}, e.equityid DESC" . $limit,
        $params
    )->fetchAll();

    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'equityid'        => (int)$r['equityid'],
            'shareholderid'   => (int)$r['shareholderid'],
            'shareholdername' => (string)($r['shareholdername'] ?? ''),
            'accountid'       => $r['accountid'] !== null ? (int)$r['accountid'] : null,
            'accountname'     => trim((string)($r['accountcode'] ?? '') . ' ' . (string)($r['accountname'] ?? '')),
            'equitytype'      => (string)$r['equitytype'],
            'description'     => (string)($r['description'] ?? ''),
            'amount'          => round((float)$r['amount'], 2),
            'equitydate'      => substr((string)$r['equitydate'], 0, 10),
        ];
    }

    return [
        'success' => true, 'draw' => $draw, 'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered, 'data' => $data,
    ];
}

/**
 * Balance of an account, leaving out the equity row being edited (read-only, no locks).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload: balance is a number, or null when the account is 0 or not an Assets sub-account.
 */
function dt_act_balance(PDO $pdo): array
{
    $accountId = (int)($_POST['accountid'] ?? 0);
    $equityId  = max(0, (int)($_POST['equityid'] ?? 0));

    if (!dt_is_asset_account($pdo, $accountId)) {
        return ['success' => true, 'balance' => null];
    }

    return ['success' => true, 'balance' => dt_account_balance_cents($pdo, $accountId, $equityId) / 100];
}

/**
 * Add (id = 0) or edit an equity row (one transaction).
 * Locks, in this order: the equity row being edited, the account row, then the balance is read.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_save(PDO $pdo): array
{
    $d = dt_read_equity_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        $currentAccount = 0;
        if ($d['id'] > 0) {
            $old            = dt_lock_equity($pdo, $d['id']);
            $currentAccount = (int)$old['accountid'];
        }

        dt_lock_account($pdo, $d['account']);

        $holderName = dt_shareholder_name($pdo, $d['holder']);
        if ($holderName === null) {
            throw new DtFail('The selected shareholder does not exist.', false, 'shareholderid');
        }
        dt_check_account($pdo, $d['account'], $currentAccount);

        if ($d['type'] === 'Drawing') {
            $balance = dt_account_balance_cents($pdo, $d['account'], $d['id']);
            if (dt_cents($d['amount']) > $balance) {
                throw new DtFail('Amount exceeds the account balance (' . dt_money($balance / 100) . ').', false, 'amount');
            }
        }

        $values = [
            's' => $d['holder'],
            'a' => $d['account'],
            't' => $d['type'],
            'm' => dt_f2($d['amount']),
            'd' => $d['date'],
            'n' => $d['desc'],
        ];

        if ($d['id'] > 0) {
            dt_q(
                $pdo,
                'UPDATE equity
                    SET shareholderid = :s, accountid = :a, equitytype = :t, amount = :m, equitydate = :d, description = :n
                  WHERE equityid = :id',
                $values + ['id' => $d['id']]
            );
            dt_log_equity($pdo, 'Update', dt_equity_text($d['type'], 'updated', $d['amount'], $holderName));

            return ['success' => true, 'message' => $d['type'] . ' updated successfully.'];
        }

        dt_q(
            $pdo,
            'INSERT INTO equity (shareholderid, accountid, equitytype, amount, equitydate, description)
             VALUES (:s, :a, :t, :m, :d, :n)',
            $values
        );
        dt_log_equity($pdo, 'Add', dt_equity_text($d['type'], 'added', $d['amount'], $holderName));

        return ['success' => true, 'message' => $d['type'] . ' added successfully.'];
    });
}

/**
 * Delete an equity row (one transaction). Always allowed.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found.
 */
function dt_act_delete(PDO $pdo): array
{
    $equityId = max(0, (int)($_POST['equityid'] ?? 0));

    return dt_transaction(static function (PDO $pdo) use ($equityId): array {
        $r          = dt_lock_equity($pdo, $equityId);
        $type       = (string)$r['equitytype'];
        $holderName = dt_shareholder_name($pdo, (int)$r['shareholderid']) ?? '';

        dt_q($pdo, 'DELETE FROM equity WHERE equityid = :id', ['id' => $equityId]);
        dt_log_equity($pdo, 'Delete', dt_equity_text($type, 'deleted', $r['amount'], $holderName));

        return ['success' => true, 'message' => $type . ' deleted successfully.'];
    });
}

/**
 * List all share rows (client-side DataTable) with the registered and remaining percentage.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_listshares(PDO $pdo): array
{
    $rows = dt_q(
        $pdo,
        'SELECT ss.shareid, ss.shareholderid, ss.groupname, ss.sharepercent, ss.note, s.name AS shareholdername
           FROM shareholdershares ss
           LEFT JOIN shareholders s ON s.shareholderid = ss.shareholderid
       ORDER BY ss.sharepercent DESC, ss.shareid ASC'
    )->fetchAll();

    $total = 0;
    $data  = [];
    foreach ($rows as $r) {
        $isShareholder = $r['shareholderid'] !== null;
        $total        += dt_cents($r['sharepercent']);
        $data[]        = [
            'shareid'      => (int)$r['shareid'],
            'shareholderid' => $isShareholder ? (int)$r['shareholderid'] : null,
            'holdername'   => $isShareholder ? (string)($r['shareholdername'] ?? '') : (string)($r['groupname'] ?? ''),
            'holdertype'   => $isShareholder ? 'Shareholder' : 'Group',
            'sharepercent' => round(dt_cents($r['sharepercent']) / 100, 2),
            'note'         => (string)($r['note'] ?? ''),
        ];
    }

    return [
        'success' => true, 'data' => $data,
        'totalpercent' => round($total / 100, 2),
        'remainingpercent' => round(max(0, 10000 - $total) / 100, 2),
    ];
}

/**
 * Add (id = 0) or edit a share row (one transaction).
 * Locks the row being edited, then ALL share rows, and sums them in hundredths.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_saveshare(PDO $pdo): array
{
    $d = dt_read_share_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        if ($d['id'] > 0) {
            dt_lock_share($pdo, $d['id']);
        }
        $others = dt_locked_share_total($pdo, $d['id']);

        if ($d['holder'] > 0) {
            $holderName = dt_shareholder_name($pdo, $d['holder']);
            if ($holderName === null) {
                throw new DtFail('The selected shareholder does not exist.', false, 'shareholderid');
            }
            $dup = dt_q(
                $pdo,
                'SELECT shareid FROM shareholdershares WHERE shareholderid = :s AND shareid <> :id',
                ['s' => $d['holder'], 'id' => $d['id']]
            )->fetchColumn();
            if ($dup !== false) {
                throw new DtFail('This shareholder already has a share. Edit it instead.', false, 'shareholderid');
            }
        } else {
            $holderName = (string)$d['group'];
            $dup        = dt_q(
                $pdo,
                'SELECT shareid FROM shareholdershares WHERE LOWER(groupname) = LOWER(:g) AND shareid <> :id',
                ['g' => $holderName, 'id' => $d['id']]
            )->fetchColumn();
            if ($dup !== false) {
                throw new DtFail('This group name already exists.', false, 'groupname');
            }
        }

        if ($others + $d['pct'] > 10000) {
            throw new DtFail(
                'The total would be ' . dt_hundredths($others + $d['pct']) . '% (maximum 100%). Remaining: '
                . dt_hundredths(max(0, 10000 - $others)) . '%.',
                false,
                'sharepercent'
            );
        }

        $values = [
            's' => $d['holder'] > 0 ? $d['holder'] : null,
            'g' => $d['holder'] > 0 ? null : $d['group'],
            'p' => dt_hundredths($d['pct']),
            'n' => $d['note'],
        ];
        $text = dt_hundredths($d['pct']) . '% ' . $holderName;

        if ($d['id'] > 0) {
            dt_q(
                $pdo,
                'UPDATE shareholdershares
                    SET shareholderid = :s, groupname = :g, sharepercent = :p, note = :n
                  WHERE shareid = :id',
                $values + ['id' => $d['id']]
            );
            dt_log_equity($pdo, 'Update', 'Share updated: ' . $text);

            return ['success' => true, 'message' => 'Share updated successfully.'];
        }

        dt_q(
            $pdo,
            'INSERT INTO shareholdershares (shareholderid, groupname, sharepercent, note) VALUES (:s, :g, :p, :n)',
            $values
        );
        dt_log_equity($pdo, 'Add', 'Share added: ' . $text);

        return ['success' => true, 'message' => 'Share added successfully.'];
    });
}

/**
 * Delete a share row (one transaction). Always allowed.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found.
 */
function dt_act_deleteshare(PDO $pdo): array
{
    $shareId = max(0, (int)($_POST['shareid'] ?? 0));

    return dt_transaction(static function (PDO $pdo) use ($shareId): array {
        $r          = dt_lock_share($pdo, $shareId);
        $holderName = dt_share_holder_name($pdo, $r);

        dt_q($pdo, 'DELETE FROM shareholdershares WHERE shareid = :id', ['id' => $shareId]);
        dt_log_equity($pdo, 'Delete', 'Share deleted: ' . dt_hundredths(dt_cents($r['sharepercent'])) . '% ' . $holderName);

        return ['success' => true, 'message' => 'Share deleted successfully.'];
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

    $handlers = [
        'list' => 'dt_act_list', 'balance' => 'dt_act_balance', 'save' => 'dt_act_save', 'delete' => 'dt_act_delete',
        'listshares' => 'dt_act_listshares', 'saveshare' => 'dt_act_saveshare', 'deleteshare' => 'dt_act_deleteshare',
    ];
    $action = (string)($_POST['action'] ?? '');
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
        dt_log_error('[equity ' . $action . '] ' . dt_describe_throwable($e));
        if ($e->getCode() === '23000') {
            if ($action === 'save') {
                dt_json(dt_fail('The selected shareholder or account no longer exists.'));
            }
            if ($action === 'saveshare') {
                $msg = $e->getMessage();
                if (str_contains($msg, 'uqshareholdersharesgroupname')) {
                    $out = dt_fail('This group name already exists.');
                    $out['field'] = 'groupname';
                    dt_json($out);
                }
                if (str_contains($msg, 'uqshareholdersharesshareholderid')) {
                    $out = dt_fail('This shareholder already has a share. Edit it instead.');
                    $out['field'] = 'shareholderid';
                    dt_json($out);
                }
                if (str_contains($msg, 'fkshareholdersharesshareholderid')) {
                    dt_json(dt_fail('The selected shareholder no longer exists.'));
                }
                dt_json(dt_fail('This holder already has a share.'));
            }
        }
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[equity ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    }
}

/* ================================================================
 * 5. PAGE DATA (shareholders and accounts)
 * ============================================================== */

/**
 * Shareholders for the selects, ordered by name.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<int,array<string,mixed>> Rows (shareholderid, name).
 */
function dt_load_shareholders(PDO $pdo): array
{
    return dt_q($pdo, 'SELECT shareholderid, name FROM shareholders ORDER BY name ASC')->fetchAll();
}

/**
 * Accounts for the select: active Assets sub-accounts plus inactive ones still used by an equity row, sorted naturally by code.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<int,array<string,mixed>> Rows (accountid, code, accountname, status).
 */
function dt_load_accounts(PDO $pdo): array
{
    $rows = dt_q(
        $pdo,
        "SELECT accountid, code, accountname, status FROM accounts
          WHERE accounttype = 'Assets' AND parentaccountid IS NOT NULL
            AND (status = 'ON' OR accountid IN (SELECT DISTINCT accountid FROM equity))"
    )->fetchAll();
    usort($rows, static fn (array $a, array $b): int => strnatcmp((string)$a['code'], (string)$b['code']));

    return $rows;
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
.form-control[readonly] { background: var(--surface); }
.form-control.is-invalid { border-color: var(--danger); background-image: none; padding-right: 10px; }
.form-control.is-invalid:focus { box-shadow: 0 0 0 2px rgba(157, 29, 34, 0.3); }
.invalid-feedback { color: var(--danger); font-size: 13px; font-weight: 500; }
label { color: var(--heading); font-weight: 500; font-size: 14px; }
label .req { color: var(--danger); }
.text-muted { color: var(--muted) !important; }
.form-text { font-size: 13px; }

/* ===== Badges ===== */
.badge { display: inline-block; padding: 2px 8px; font-size: 12px; font-weight: 600; line-height: 1.5; color: #ffffff; border-radius: 0; }
.badge-holder { background: var(--brand-accent-dark); }
.badge-group { background: #6c757d; }

/* ===== Tabs ===== */
.nav-tabs { border-bottom: 1px solid var(--line); }
.nav-tabs.compact-tabs { margin-top: 8px; }
.nav-tabs.compact-tabs .nav-link {
    padding: 5px 14px; font-size: 13px; font-weight: 600; border-radius: 0; border: 1px solid transparent;
    background: var(--surface); color: var(--brand-primary); margin-right: 2px;
}
.nav-tabs.compact-tabs .nav-link:hover { color: var(--brand-accent-dark); }
.nav-tabs.compact-tabs .nav-link.active { background: var(--brand-primary); color: #ffffff; border-color: var(--brand-primary); }
.tab-content { padding-top: 16px; }
.share-summary { margin: 0 0 12px; font-weight: 600; color: var(--heading); }

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
.eq-table { width: 100% !important; font-size: 14px; }
.eq-table thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); white-space: nowrap; font-weight: 600; }
.eq-table td { vertical-align: middle; }
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
                            <a href="<?= dt_e($subPage) ?>"<?= ($isAccounting && $subPage === 'equity.php') ? ' class="active" aria-current="page"' : '' ?>><span><?= dt_e($subLabel) ?></span></a>
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
 * One server-side equity table (capital or drawing pane).
 *
 * @param string $tableId      Table id.
 * @param string $amountHeader Header of the amount column.
 *
 * @return void
 */
function dt_render_equity_table(string $tableId, string $amountHeader): void
{
    ?>
                    <table id="<?= dt_e($tableId) ?>" class="table table-hover table-bordered eq-table">
                      <thead>
                        <tr>
                          <th class="px60">#</th>
                          <th>Equity</th>
                          <th>Shareholder</th>
                          <th>Account</th>
                          <th>Description</th>
                          <th class="text-right"><?= dt_e($amountHeader) ?></th>
                          <th>Date</th>
                          <th class="px120 text-center">Action</th>
                        </tr>
                      </thead>
                      <tbody></tbody>
                    </table>
<?php
}

/**
 * Owner's equity list card: header, tabs and the three tables.
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
                  <h2 class="header-title">Owner's Equity List</h2>
                  <button type="button" class="btn btn-primary btn-sm" id="btnAdd"><i class="fas fa-plus mr-1" aria-hidden="true"></i> <span id="btnAddText">Add Capital</span></button>
                </div>

                <ul class="nav nav-tabs compact-tabs" id="eqTabs" role="tablist">
                  <li class="nav-item">
                    <a class="nav-link active" id="tab-capital" data-toggle="tab" href="#capital" role="tab" aria-controls="capital" aria-selected="true">Capital</a>
                  </li>
                  <li class="nav-item">
                    <a class="nav-link" id="tab-drawing" data-toggle="tab" href="#drawing" role="tab" aria-controls="drawing" aria-selected="false">Drawing</a>
                  </li>
                  <li class="nav-item">
                    <a class="nav-link" id="tab-shares" data-toggle="tab" href="#shares" role="tab" aria-controls="shares" aria-selected="false">Shares</a>
                  </li>
                </ul>

                <div class="tab-content">
                  <div class="tab-pane fade show active" id="capital" role="tabpanel" aria-labelledby="tab-capital">
<?php dt_render_equity_table('tblCapital', 'Added Amount'); ?>
                  </div>
                  <div class="tab-pane fade" id="drawing" role="tabpanel" aria-labelledby="tab-drawing">
<?php dt_render_equity_table('tblDrawing', 'Withdrawn Amount'); ?>
                  </div>
                  <div class="tab-pane fade" id="shares" role="tabpanel" aria-labelledby="tab-shares">
                    <p class="share-summary" id="sharesSummary">Registered: 0.00% | Remaining: 100.00%</p>
                    <table id="tblShares" class="table table-hover table-bordered eq-table">
                      <thead>
                        <tr>
                          <th class="px60">#</th>
                          <th>Holder</th>
                          <th>Type</th>
                          <th class="text-right">Percentage</th>
                          <th>Note</th>
                          <th class="px120 text-center">Action</th>
                        </tr>
                      </thead>
                      <tbody></tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php
}

/**
 * Add / edit equity modal (Capital or Drawing).
 *
 * @param array<int,array<string,mixed>> $holders  Shareholders.
 * @param array<int,array<string,mixed>> $accounts Accounts.
 * @param string                         $today    Today (Y-m-d).
 *
 * @return void
 */
function dt_modal_equity(array $holders, array $accounts, string $today): void
{
    ?>
  <div class="modal fade" id="equityModal" tabindex="-1" role="dialog" aria-labelledby="equityModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form class="modal-content" id="equityForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="equityModalTitle">Add Capital</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="eId" value="0">
          <div class="form-group">
            <label for="eType">Type <span class="req">*</span></label>
            <select class="form-control" id="eType">
              <?php foreach (DT_TYPES as $type): ?>
                <option value="<?= dt_e($type) ?>"><?= dt_e($type) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback" id="eTypeFb">Invalid type.</div>
          </div>
          <div class="form-group">
            <label for="eHolder">Shareholder <span class="req">*</span></label>
            <select class="form-control" id="eHolder" required>
              <option value="">Select shareholder</option>
              <?php foreach ($holders as $h): ?>
                <option value="<?= (int)$h['shareholderid'] ?>"><?= dt_e($h['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback" id="eHolderFb">Shareholder is required.</div>
          </div>
          <div class="form-group">
            <label for="eAccount">Account <span class="req">*</span></label>
            <select class="form-control" id="eAccount" required>
              <option value="">Select account</option>
              <?php foreach ($accounts as $a):
                  $off = ((string)$a['status'] !== 'ON'); ?>
                <option value="<?= (int)$a['accountid'] ?>"<?= $off ? ' data-off="1" disabled' : '' ?>><?= dt_e($a['code'] . ' ' . $a['accountname'] . ($off ? ' (inactive)' : '')) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback" id="eAccountFb">Account is required.</div>
          </div>
          <div class="form-group" id="eBalanceGroup" hidden>
            <label for="eBalance">Balance</label>
            <input type="number" class="form-control" id="eBalance" step="0.01" readonly tabindex="-1">
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="eAmount">Amount <span class="req">*</span></label>
              <input type="number" class="form-control" id="eAmount" step="0.01" min="0.01" required>
              <div class="invalid-feedback" id="eAmountFb">Enter an amount greater than 0.</div>
            </div>
            <div class="form-group col-md-6">
              <label for="eDate">Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="eDate" value="<?= dt_e($today) ?>" required>
              <div class="invalid-feedback" id="eDateFb">Date is required.</div>
            </div>
          </div>
          <div class="form-group mb-0">
            <label for="eDesc">Description</label>
            <input type="text" class="form-control" id="eDesc" maxlength="<?= DT_MAX_DESC ?>">
            <div class="invalid-feedback" id="eDescFb">The description is too long.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveEquity"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Add / edit share modal.
 *
 * @param array<int,array<string,mixed>> $holders Shareholders.
 *
 * @return void
 */
function dt_modal_share(array $holders): void
{
    ?>
  <div class="modal fade" id="shareModal" tabindex="-1" role="dialog" aria-labelledby="shareModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form class="modal-content" id="shareForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="shareModalTitle">Add Share</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="sId" value="0">
          <div class="form-group">
            <label for="sKind">Holder <span class="req">*</span></label>
            <select class="form-control" id="sKind">
              <option value="shareholder">Shareholder</option>
              <option value="group">Group</option>
            </select>
          </div>
          <div class="form-group" id="sHolderBox">
            <label for="sHolder">Shareholder <span class="req">*</span></label>
            <select class="form-control" id="sHolder">
              <option value="">Select shareholder</option>
              <?php foreach ($holders as $h): ?>
                <option value="<?= (int)$h['shareholderid'] ?>"><?= dt_e($h['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback" id="sHolderFb">Choose a shareholder or write a group name.</div>
          </div>
          <div class="form-group" id="sGroupBox" hidden>
            <label for="sGroup">Group name <span class="req">*</span></label>
            <input type="text" class="form-control" id="sGroup" maxlength="<?= DT_MAX_GROUP ?>">
            <div class="invalid-feedback" id="sGroupFb">Group name is required.</div>
          </div>
          <div class="form-group">
            <label for="sPercent">Percentage <span class="req">*</span></label>
            <input type="number" class="form-control" id="sPercent" step="0.01" min="0.01" max="100" required>
            <small class="form-text text-muted" id="sRemain">Remaining: 100.00%</small>
            <div class="invalid-feedback" id="sPercentFb">Percentage must be between 0.01 and 100.</div>
          </div>
          <div class="form-group mb-0">
            <label for="sNote">Note</label>
            <input type="text" class="form-control" id="sNote" maxlength="<?= DT_MAX_NOTE ?>">
            <div class="invalid-feedback" id="sNoteFb">The note is too long.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveShare"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Delete modal shared by equity rows and share rows.
 *
 * @return void
 */
function dt_modal_delete(): void
{
    ?>
  <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="delTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="delTitle">Delete Capital</h5>
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

$holders  = [];
$accounts = [];
try {
    $holders  = dt_load_shareholders(dt_pdo());
    $accounts = dt_load_accounts(dt_pdo());
} catch (Throwable $e) {
    dt_log_error('[equity view] ' . dt_describe_throwable($e));
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

$hasAccounts = false;
foreach ($accounts as $a) {
    if ((string)$a['status'] === 'ON') {
        $hasAccounts = true;
        break;
    }
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
    'csrfField' => $csrfField, 'docTitle' => "Owner's Equity", 'heading' => "Owner's Equity",
    'sub' => 'Record capital added, drawings taken and the share percentage of each owner.',
];

/* ---------- Render ---------- */

dt_render_open($page);
dt_view_list();
dt_render_close();
dt_modal_equity($holders, $accounts, $today);
dt_modal_share($holders);
dt_modal_delete();

$cfg = [
    'csrf' => $csrf, 'self' => 'equity.php', 'today' => $today, 'hasAccounts' => $hasAccounts,
    'paneType' => ['capital' => 'Capital', 'drawing' => 'Drawing', 'shares' => 'Shares'],
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

    function fmt(v) {
      return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function apiFail(xhr) {
      var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Request failed. Please try again.';
      toastr.error(msg);
    }

    // POST helper: always sends CSRF, expects JSON
    function api(data) {
      data.csrf_token = CSRF;
      return $.ajax({ url: URL_SELF, type: 'POST', data: data, dataType: 'json' }).fail(apiFail);
    }

    /* ---------- Safe sessionStorage (storage can be blocked) ---------- */
    function ssGet(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
    function ssSet(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
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

    var DOM = "<'dt-top'<'dt-len'l><'dt-btns'B><'dt-find'f>>" +
              "<'table-scroll'tr>" +
              "<'row mt-2'<'col-md-5'i><'col-md-7'p>>";

    /* ---------- Tables ---------- */
    var tables = {};      // Capital, Drawing (server-side) and Shares (client-side), created lazily
    var sharesInfo = { total: 0, remaining: 100 };

    function equityButtons(label) {
      var base = {
        className: 'btn btn-secondary btn-sm',
        title: label,
        filename: label,
        exportOptions: { columns: [1, 2, 3, 4, 5, 6] }
      };
      return [
        $.extend({}, base, { extend: 'copyHtml5',  text: '<i class="fas fa-copy"></i> Copy',        title: '', action: exportAll('copyHtml5') }),
        $.extend({}, base, { extend: 'csvHtml5',   text: '<i class="fas fa-file-csv"></i> CSV',     action: exportAll('csvHtml5') }),
        $.extend({}, base, { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel', action: exportAll('excelHtml5') }),
        $.extend({}, base, {
          extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf"></i> PDF',
          orientation: 'landscape', pageSize: 'A4',
          action: exportAll('pdfHtml5'),
          customize: function (doc) {
            var tbl = null;
            $.each(doc.content, function (i, c) { if (c.table) { tbl = c.table; } });
            if (!tbl) { return; }
            tbl.widths = [50, '*', '*', '*', 90, 70];
            $.each(tbl.body, function (i, row) { if (row[4]) { row[4].alignment = 'right'; } });
          }
        }),
        $.extend({}, base, { extend: 'print',      text: '<i class="fas fa-print"></i> Print',      action: exportAll('print') })
      ];
    }

    function initEquityTable(type) {
      var isCap = (type === 'Capital');

      tables[type] = $(isCap ? '#tblCapital' : '#tblDrawing').DataTable({
        serverSide: true,
        autoWidth: false,
        searchDelay: 400,
        ajax: {
          url: URL_SELF,
          type: 'POST',
          data: function (d) { d.action = 'list'; d.type = type; d.csrf_token = CSRF; },
          dataSrc: 'data',
          error: function (xhr) { if (window.console) { console.warn('equity list request failed (HTTP ' + (xhr && xhr.status) + ')'); } toastr.error('Could not load records.'); }
        },
        columns: [
          {
            data: null, orderable: false, searchable: false,
            render: function (d, t, row, meta) { return meta.row + meta.settings._iDisplayStart + 1; }
          },
          { data: 'equityid',        render: function (d) { return esc(d); } },
          { data: 'shareholdername', render: function (d) { return esc(d); } },
          { data: 'accountname',     render: function (d) { return esc(d); } },
          { data: 'description',     render: function (d) { return esc(d); } },
          { data: 'amount', className: 'text-right', render: function (d) { return esc(fmt(d)); } },
          { data: 'equitydate',      render: function (d) { return esc(d); } },
          {
            data: null, orderable: false, searchable: false, className: 'text-center',
            render: function () {
              return '<button type="button" class="btn btn-primary act-btn btn-edit" title="Edit" aria-label="Edit record"><i class="fas fa-edit" aria-hidden="true"></i></button> ' +
                     '<button type="button" class="btn btn-danger act-btn btn-del" title="Delete" aria-label="Delete record"><i class="fas fa-trash" aria-hidden="true"></i></button>';
            }
          }
        ],
        order: [[1, 'desc']],
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        dom: DOM,
        buttons: equityButtons(isCap ? 'Owners Capital' : 'Owners Drawing'),
        language: {
          search: 'Search:',
          searchPlaceholder: isCap ? 'Search capital...' : 'Search drawing...',
          emptyTable: isCap ? 'No capital found' : 'No drawings found',
          zeroRecords: isCap ? 'No capital found' : 'No drawings found'
        }
      });
    }

    function updateSharesSummary() {
      $('#sharesSummary').text('Registered: ' + fmt(sharesInfo.total) + '% | Remaining: ' + fmt(sharesInfo.remaining) + '%');
    }

    function initSharesTable() {
      var base = {
        className: 'btn btn-secondary btn-sm',
        title: 'Owners Shares',
        filename: 'Owners Shares',
        exportOptions: { columns: [1, 2, 3, 4] }
      };

      tables.Shares = $('#tblShares').DataTable({
        autoWidth: false,
        ajax: {
          url: URL_SELF,
          type: 'POST',
          data: function (d) { d.action = 'listshares'; d.csrf_token = CSRF; },
          dataSrc: function (json) {
            sharesInfo = { total: Number(json.totalpercent || 0), remaining: Number(json.remainingpercent || 0) };
            updateSharesSummary();
            return json.data || [];
          },
          error: function (xhr) { if (window.console) { console.warn('shares list request failed (HTTP ' + (xhr && xhr.status) + ')'); } toastr.error('Could not load shares.'); }
        },
        columns: [
          {
            data: null, orderable: false, searchable: false,
            render: function (d, t, row, meta) { return meta.row + 1; }
          },
          { data: 'holdername', render: function (d) { return esc(d); } },
          {
            data: 'holdertype',
            render: function (d, type) {
              if (type !== 'display') { return d; }
              return d === 'Group' ? '<span class="badge badge-group">Group</span>' : '<span class="badge badge-holder">Shareholder</span>';
            }
          },
          {
            data: 'sharepercent', className: 'text-right',
            render: function (d, type) { return type === 'display' ? esc(fmt(d)) + '%' : d; }
          },
          { data: 'note', render: function (d) { return esc(d); } },
          {
            data: null, orderable: false, searchable: false, className: 'text-center',
            render: function () {
              return '<button type="button" class="btn btn-primary act-btn btn-edit" title="Edit" aria-label="Edit share"><i class="fas fa-edit" aria-hidden="true"></i></button> ' +
                     '<button type="button" class="btn btn-danger act-btn btn-del" title="Delete" aria-label="Delete share"><i class="fas fa-trash" aria-hidden="true"></i></button>';
            }
          }
        ],
        order: [],
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        dom: DOM,
        buttons: [
          $.extend({}, base, { extend: 'copyHtml5',  text: '<i class="fas fa-copy"></i> Copy',        title: '' }),
          $.extend({}, base, { extend: 'csvHtml5',   text: '<i class="fas fa-file-csv"></i> CSV' }),
          $.extend({}, base, { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel' }),
          $.extend({}, base, {
            extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf"></i> PDF',
            orientation: 'portrait', pageSize: 'A4',
            customize: function (doc) {
              var tbl = null;
              $.each(doc.content, function (i, c) { if (c.table) { tbl = c.table; } });
              if (!tbl) { return; }
              tbl.widths = ['*', 80, 70, '*'];
              $.each(tbl.body, function (i, row) { if (row[2]) { row[2].alignment = 'right'; } });
            }
          }),
          $.extend({}, base, { extend: 'print',      text: '<i class="fas fa-print"></i> Print' })
        ],
        language: { search: 'Search:', searchPlaceholder: 'Search share...', emptyTable: 'No shares found', zeroRecords: 'No shares found' }
      });
    }

    function initTable(type) {
      if (type === 'Shares') { initSharesTable(); } else { initEquityTable(type); }
    }

    function reloadEquity() {
      $.each(['Capital', 'Drawing'], function (i, type) { if (tables[type]) { tables[type].ajax.reload(null, false); } });
    }

    function reloadShares() {
      if (tables.Shares) { tables.Shares.ajax.reload(null, false); }
    }

    function activeType() {
      var pane = String($('#eqTabs .nav-link.active').attr('href') || '#capital').substring(1);
      return PANE_TYPE[pane] || 'Capital';
    }

    function setAddLabel(type) {
      $('#btnAddText').text(type === 'Shares' ? 'Add Share' : 'Add ' + type);
    }

    /* ---------- Tabs: remember the active one, lazy init, fix widths ---------- */
    $('#eqTabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
      var pane = String($(e.target).attr('href')).substring(1);
      var type = PANE_TYPE[pane];
      ssSet('eq_tab', pane);
      setAddLabel(type);
      if (!tables[type]) { initTable(type); }
      else { tables[type].columns.adjust(); }
    });

    var savedPane = ssGet('eq_tab');
    if (savedPane === 'drawing' || savedPane === 'shares') {
      setAddLabel(PANE_TYPE[savedPane]);
      $('#eqTabs a[href="#' + savedPane + '"]').tab('show');
    } else {
      initTable('Capital');
    }

    /* ---------- Select2 ---------- */
    $('#eHolder, #eAccount').select2({ theme: 'bootstrap4', width: '100%', dropdownParent: $('#equityModal') });
    $('#sHolder').select2({ theme: 'bootstrap4', width: '100%', dropdownParent: $('#shareModal') });

    /* Inactive accounts stay disabled; only the one used by the row being edited is enabled */
    function lockOff($s) { $s.find('option[data-off="1"]').prop('disabled', true); }

    function setSelect($s, value) {
      var v = value ? String(value) : '';
      lockOff($s);
      $s.find('option').filter(function () { return this.value === v; }).prop('disabled', false);
      $s.val(v);
      if ($s.val() === null) { $s.val(''); }
      $s.trigger('change.select2');
    }

    function focusField(sel) {
      if ($(sel).is('select')) { $(sel).select2('open'); } else { $(sel).trigger('focus'); }
    }

    /* ---------- Fields: server field name => input selector (feedback element is selector + "Fb"), one map per modal ---------- */
    var EQ_FIELDS = {
      shareholderid: '#eHolder',
      accountid: '#eAccount',
      equitytype: '#eType',
      amount: '#eAmount',
      equitydate: '#eDate',
      description: '#eDesc'
    };
    var SH_FIELDS = {
      shareholderid: '#sHolder',
      groupname: '#sGroup',
      sharepercent: '#sPercent',
      note: '#sNote'
    };
    var EQ_FB = {}, SH_FB = {};
    $.each(EQ_FIELDS, function (key, sel) { EQ_FB[key] = $(sel + 'Fb').text(); });
    $.each(SH_FIELDS, function (key, sel) { SH_FB[key] = $(sel + 'Fb').text(); });

    function clearInvalid(fields, defaults) {
      $.each(fields, function (key, sel) {
        $(sel).removeClass('is-invalid');
        $(sel + 'Fb').text(defaults[key]);
      });
    }

    function markInvalid(fields, key, message) {
      var sel = fields[key];
      if (message) { $(sel + 'Fb').text(message); }
      $(sel).addClass('is-invalid');
      return sel;
    }

    $('#eType, #eHolder, #eAccount, #eAmount, #eDate, #eDesc').on('input change', function () { $(this).removeClass('is-invalid'); });
    $('#sHolder, #sGroup, #sPercent, #sNote').on('input change', function () { $(this).removeClass('is-invalid'); });

    /* ================= Equity modal (Capital / Drawing) ================= */

    /* Balance of the chosen account (late answers are ignored with a request counter) */
    var balReq = 0;

    function clearBalance() {
      balReq++;
      $('#eBalance').val('');
    }

    function toggleBalance() {
      $('#eBalanceGroup').prop('hidden', $('#eType').val() !== 'Drawing');
    }

    function loadBalance() {
      var account = $('#eAccount').val();
      if ($('#eType').val() !== 'Drawing' || !account) { clearBalance(); return; }
      var req = ++balReq;
      api({ action: 'balance', accountid: account, equityid: $('#eId').val() }).done(function (res) {
        if (req !== balReq || !res.success) { return; }
        $('#eBalance').val(res.balance === null ? '' : Number(res.balance).toFixed(2));
      });
    }

    function setEquityTitle() {
      var verb = (parseInt($('#eId').val(), 10) > 0) ? 'Edit ' : 'Add ';
      $('#equityModalTitle').text(verb + $('#eType').val());
    }

    $('#eType').on('change', function () {
      setEquityTitle();
      toggleBalance();
      loadBalance();
    });
    $('#eAccount').on('change', loadBalance);

    function openEquityAdd(type) {
      if (!CFG.hasAccounts) {
        toastr.warning('Add an active asset account in Chart of Accounts first.');
        return;
      }
      $('#equityForm')[0].reset();
      clearInvalid(EQ_FIELDS, EQ_FB);
      clearBalance();
      $('#eId').val(0);
      $('#eType').val(type);
      setSelect($('#eHolder'), '');
      setSelect($('#eAccount'), '');
      $('#eDate').val(TODAY);
      toggleBalance();
      setEquityTitle();
      $('#equityModal').modal('show');
    }

    function openEquityEdit(d) {
      $('#equityForm')[0].reset();
      clearInvalid(EQ_FIELDS, EQ_FB);
      clearBalance();
      $('#eId').val(d.equityid);
      $('#eType').val(d.equitytype);
      setSelect($('#eHolder'), d.shareholderid);
      setSelect($('#eAccount'), d.accountid);
      $('#eAmount').val(Number(d.amount).toFixed(2));
      $('#eDate').val(d.equitydate);
      $('#eDesc').val(d.description || '');
      toggleBalance();
      setEquityTitle();
      loadBalance();
      $('#equityModal').modal('show');
    }

    $('#equityForm').on('submit', function (e) {
      e.preventDefault();
      clearInvalid(EQ_FIELDS, EQ_FB);

      var holder  = $('#eHolder').val();
      var account = $('#eAccount').val();
      var amount  = parseFloat($('#eAmount').val());
      var date    = $('#eDate').val();

      if (!holder)                      { focusField(markInvalid(EQ_FIELDS, 'shareholderid')); return; }
      if (!account)                     { focusField(markInvalid(EQ_FIELDS, 'accountid')); return; }
      if (isNaN(amount) || amount <= 0) { focusField(markInvalid(EQ_FIELDS, 'amount')); return; }
      if (!date)                        { focusField(markInvalid(EQ_FIELDS, 'equitydate')); return; }

      var $btn = $('#btnSaveEquity').prop('disabled', true);
      api({
        action: 'save',
        equityid: $('#eId').val(),
        equitytype: $('#eType').val(),
        shareholderid: holder,
        accountid: account,
        amount: $('#eAmount').val(),
        equitydate: date,
        description: $.trim($('#eDesc').val())
      }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#equityModal').modal('hide');
          reloadEquity();
          return;
        }
        if (res.field && EQ_FIELDS[res.field]) {
          focusField(markInvalid(EQ_FIELDS, res.field, res.message));
        }
        toastr.error(res.message);
      }).always(function () { $btn.prop('disabled', false); });
    });

    /* ================= Share modal ================= */

    var shareEditPct = 0; // percentage of the row being edited (added back to the remaining text)

    function updateRemain() {
      var rem = Math.max(0, 100 - (sharesInfo.total - shareEditPct));
      $('#sRemain').text('Remaining: ' + fmt(rem) + '%');
    }

    /* Show the field of the chosen holder kind and clear the unused one */
    function applyKind() {
      var group = ($('#sKind').val() === 'group');
      $('#sHolderBox').prop('hidden', group);
      $('#sGroupBox').prop('hidden', !group);
      if (group) { setSelect($('#sHolder'), ''); } else { $('#sGroup').val(''); }
      $('#sHolder, #sGroup').removeClass('is-invalid');
    }

    $('#sKind').on('change', applyKind);

    function shareMark(key, message) {
      var k = key;
      if (key === 'shareholderid' && $('#sKind').val() === 'group') { k = 'groupname'; }
      return markInvalid(SH_FIELDS, k, message);
    }

    function openShareAdd() {
      $('#shareForm')[0].reset();
      clearInvalid(SH_FIELDS, SH_FB);
      $('#sId').val(0);
      $('#sKind').val('shareholder');
      applyKind();
      shareEditPct = 0;
      updateRemain();
      $('#shareModalTitle').text('Add Share');
      $('#shareModal').modal('show');
    }

    function openShareEdit(d) {
      $('#shareForm')[0].reset();
      clearInvalid(SH_FIELDS, SH_FB);
      $('#sId').val(d.shareid);
      $('#sKind').val(d.holdertype === 'Group' ? 'group' : 'shareholder');
      applyKind();
      if (d.holdertype === 'Group') { $('#sGroup').val(d.holdername || ''); } else { setSelect($('#sHolder'), d.shareholderid); }
      $('#sPercent').val(Number(d.sharepercent).toFixed(2));
      $('#sNote').val(d.note || '');
      shareEditPct = Number(d.sharepercent) || 0;
      updateRemain();
      $('#shareModalTitle').text('Edit Share');
      $('#shareModal').modal('show');
    }

    $('#shareForm').on('submit', function (e) {
      e.preventDefault();
      clearInvalid(SH_FIELDS, SH_FB);

      var group  = ($('#sKind').val() === 'group');
      var holder = group ? '' : $('#sHolder').val();
      var name   = group ? $.trim($('#sGroup').val()) : '';
      var pct    = parseFloat($('#sPercent').val());

      if (group && name === '')         { focusField(shareMark('groupname')); return; }
      if (!group && !holder)            { focusField(shareMark('shareholderid')); return; }
      if (isNaN(pct) || pct < 0.01 || pct > 100) { focusField(markInvalid(SH_FIELDS, 'sharepercent')); return; }

      var $btn = $('#btnSaveShare').prop('disabled', true);
      api({
        action: 'saveshare',
        shareid: $('#sId').val(),
        shareholderid: holder || 0,
        groupname: name,
        sharepercent: $('#sPercent').val(),
        note: $.trim($('#sNote').val())
      }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#shareModal').modal('hide');
          reloadShares();
          return;
        }
        if (res.field && SH_FIELDS[res.field]) {
          focusField(shareMark(res.field, res.message));
        }
        toastr.error(res.message);
      }).always(function () { $btn.prop('disabled', false); });
    });

    /* ================= Add button (follows the active tab) ================= */
    $('#btnAdd').on('click', function () {
      var type = activeType();
      if (type === 'Shares') { openShareAdd(); } else { openEquityAdd(type); }
    });

    /* ================= Edit ================= */
    $('#capital, #drawing').on('click', '.btn-edit', function () {
      var d = $(this).closest('table').DataTable().row($(this).closest('tr')).data();
      if (d) { openEquityEdit(d); }
    });

    $('#tblShares tbody').on('click', '.btn-edit', function () {
      var d = tables.Shares.row($(this).closest('tr')).data();
      if (d) { openShareEdit(d); }
    });

    /* ================= Delete (one shared modal) ================= */
    var deleteKind = 'equity';
    var deleteId = 0;

    $('#capital, #drawing').on('click', '.btn-del', function () {
      var d = $(this).closest('table').DataTable().row($(this).closest('tr')).data();
      if (!d) { return; }
      deleteKind = 'equity';
      deleteId = d.equityid;
      $('#delTitle').text('Delete ' + d.equitytype);
      $('#delText').empty().append(
        document.createTextNode('Delete ' + String(d.equitytype).toLowerCase() + ' '),
        $('<b>').text('#' + d.equityid),
        document.createTextNode(' (' + fmt(d.amount) + ', ' + (d.shareholdername || '') + ')? This cannot be undone.')
      );
      $('#deleteModal').modal('show');
    });

    $('#tblShares tbody').on('click', '.btn-del', function () {
      var d = tables.Shares.row($(this).closest('tr')).data();
      if (!d) { return; }
      deleteKind = 'share';
      deleteId = d.shareid;
      $('#delTitle').text('Delete Share');
      $('#delText').empty().append(
        document.createTextNode('Delete share '),
        $('<b>').text('#' + d.shareid),
        document.createTextNode(' (' + fmt(d.sharepercent) + '%, ' + (d.holdername || '') + ')? This cannot be undone.')
      );
      $('#deleteModal').modal('show');
    });

    $('#btnConfirmDelete').on('click', function () {
      var $btn = $(this).prop('disabled', true);
      var isShare = (deleteKind === 'share');
      var data = isShare ? { action: 'deleteshare', shareid: deleteId } : { action: 'delete', equityid: deleteId };
      api(data).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          if (isShare) { reloadShares(); } else { reloadEquity(); }
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