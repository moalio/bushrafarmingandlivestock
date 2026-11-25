<?php

declare(strict_types=1);

/**
 * pages/customerdeposit.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: Customer Deposit (sidebar > Accounting > Customer Deposit).
 *
 * Money customers pay in advance (deposits) and give back (refunds). One card with three tabs:
 * Deposits, Refunds and Balances (balances are read only).
 * One page, four AJAX actions (POST, JSON): list, save, delete, balance.
 * Customers and receiving accounts are rendered by PHP on GET.
 *
 * Tables (single tenant, lowercase names):
 *   customerdeposits       (depositid, customerid, accountid, amount, depositdate, note)
 *   customerdepositusages  (usageid, customerid, invoiceid, amount, usagedate, note)  READ ONLY here: written by the Sales page
 *   customerdepositrefunds (depositrefundid, customerid, accountid, amount, refunddate, note)
 *
 * Customer deposit balance = SUM(deposits) - SUM(usages) - SUM(refunds), always per customer.
 * No operation of this page can leave a balance below 0 (checked inside the transaction, after locking the customers).
 * The cash side is only the account chosen on the deposit (adds to it) or the refund (takes from it).
 * This page never writes to invoices or receipts.
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call the auth helpers of ../db.php right after the
 * "NO LOGIN" marker in the request flow below (and pass the user id to dt_log_deposit()).
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

const DT_MAX_NOTE   = 255;
const DT_MAX_AMOUNT = 1000000000;

/** Name of the customers row used for unregistered customers: it cannot have a deposit. */
const BF_WALKIN_NAME = 'Walk-in';

const DT_LOGO_FILE = 'bushrofarmingandlivestocklogo.png'; // preferred logo file name
const DT_LOGO_DIRS = ['uploads', 'assets', 'images'];     // folders next to pages/ searched for the logo (in this order)

/**
 * The two kinds of record this page writes (fixed values, never user input):
 * table, id column, date column (also the posted field name), noun for messages, prefix of the activity text.
 */
const DT_KINDS = [
    'deposit' => ['table' => 'customerdeposits',       'id' => 'depositid',       'date' => 'depositdate', 'noun' => 'Deposit', 'log' => 'Deposit'],
    'refund'  => ['table' => 'customerdepositrefunds', 'id' => 'depositrefundid', 'date' => 'refunddate',  'noun' => 'Refund',  'log' => 'Deposit refund'],
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
 * Convert an amount (number or decimal text) to integer cents, so balances are compared without float drift.
 *
 * @param mixed $value Amount.
 *
 * @return int Cents.
 */
function dt_cents(mixed $value): int
{
    return (int)round((float)$value * 100);
}

/* ================================================================
 * 3. SHARED BUSINESS RULES (read and validate, check, lock, balance, log)
 * ============================================================== */

/**
 * Read the posted kind (deposit | refund) and return its configuration.
 *
 * @return array{kind:string,table:string,id:string,date:string,noun:string,log:string} Configuration with the kind.
 *
 * @throws DtFail When the kind is unknown.
 */
function dt_cfg(): array
{
    $kind = (string)($_POST['kind'] ?? '');
    if (!isset(DT_KINDS[$kind])) {
        throw new DtFail('Unknown kind.');
    }

    return ['kind' => $kind] + DT_KINDS[$kind];
}

/**
 * Read and validate the posted fields of a deposit or a refund (used by save, before the transaction).
 * The date is posted under its own field name (depositdate or refunddate).
 *
 * @param array<string,string> $cfg Configuration of dt_cfg().
 *
 * @return array{id:int,customer:int,account:int,amount:float,cents:int,date:string,note:?string} Clean fields (empty note becomes null).
 *
 * @throws DtFail On invalid input.
 */
function dt_read_input(array $cfg): array
{
    $dateField = $cfg['date'];
    $id        = max(0, (int)($_POST['id'] ?? 0));
    $customer  = (int)($_POST['customerid'] ?? 0);
    $account   = (int)($_POST['accountid'] ?? 0);
    $rawAmount = trim((string)($_POST['amount'] ?? ''));
    $date      = trim((string)($_POST[$dateField] ?? ''));
    $note      = trim((string)($_POST['note'] ?? ''));

    if ($customer <= 0) {
        throw new DtFail('Customer is required.', false, 'customerid');
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
        throw new DtFail('A valid date is required.', false, $dateField);
    }
    if (mb_strlen($note) > DT_MAX_NOTE) {
        throw new DtFail('The note is too long (maximum ' . DT_MAX_NOTE . ' characters).', false, 'note');
    }

    return [
        'id' => $id, 'customer' => $customer, 'account' => $account,
        'amount' => $amount, 'cents' => dt_cents($amount), 'date' => $date, 'note' => $note === '' ? null : $note,
    ];
}

/**
 * Name of a customer.
 *
 * @param PDO $pdo Connection.
 * @param int $id  Customer id.
 *
 * @return string|null Name, or null when the customer does not exist.
 */
function dt_customer_name(PDO $pdo, int $id): ?string
{
    $name = dt_q($pdo, 'SELECT name FROM customers WHERE customerid = :id', ['id' => $id])->fetchColumn();

    return $name === false ? null : (string)$name;
}

/**
 * Check that the customer exists and is not the Walk-in customer.
 *
 * @param PDO $pdo Connection.
 * @param int $id  Customer id.
 *
 * @return string Customer name.
 *
 * @throws DtFail When the customer does not exist or is Walk-in (field customerid).
 */
function dt_check_customer(PDO $pdo, int $id): string
{
    $name = dt_customer_name($pdo, $id);
    if ($name === null) {
        throw new DtFail('The selected customer does not exist.', false, 'customerid');
    }
    if (strcasecmp($name, BF_WALKIN_NAME) === 0) {
        throw new DtFail('Walk-in cannot have a deposit.', false, 'customerid');
    }

    return $name;
}

/**
 * Check that the account is an active Assets sub-account.
 *
 * @param PDO $pdo       Connection.
 * @param int $accountId Account id.
 *
 * @return void
 *
 * @throws DtFail When the account is not valid.
 */
function dt_check_account(PDO $pdo, int $accountId): void
{
    $found = dt_q(
        $pdo,
        "SELECT accountid FROM accounts
          WHERE accountid = :a AND status = 'ON' AND accounttype = 'Assets' AND parentaccountid IS NOT NULL",
        ['a' => $accountId]
    )->fetchColumn();
    if ($found === false) {
        throw new DtFail('Select a valid active account.', false, 'accountid');
    }
}

/**
 * Lock customer rows FOR UPDATE, always in ASCENDING customerid order (serializes changes per customer, avoids deadlocks).
 * Call inside a transaction, before computing any balance.
 *
 * @param PDO       $pdo Connection.
 * @param list<int> $ids Customer ids (any order, duplicates and zeros are ignored).
 *
 * @return void
 */
function dt_lock_customers(PDO $pdo, array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
    sort($ids);
    foreach ($ids as $id) {
        dt_q($pdo, 'SELECT customerid FROM customers WHERE customerid = :id FOR UPDATE', ['id' => $id])->fetchAll();
    }
}

/**
 * Read (without a lock) the customer id of a deposit or refund row, to know which customers to lock first.
 *
 * @param PDO                  $pdo Connection.
 * @param array<string,string> $cfg Configuration of dt_cfg().
 * @param int                  $id  Row id.
 *
 * @return int Customer id.
 *
 * @throws DtFail When the row does not exist.
 */
function dt_peek_customer(PDO $pdo, array $cfg, int $id): int
{
    $value = dt_q($pdo, "SELECT customerid FROM `{$cfg['table']}` WHERE `{$cfg['id']}` = :id", ['id' => $id])->fetchColumn();
    if ($value === false) {
        throw new DtFail($cfg['noun'] . ' not found.');
    }

    return (int)$value;
}

/**
 * Load and lock (FOR UPDATE) a deposit or refund row. Call inside a transaction, after the customers are locked.
 *
 * @param PDO                  $pdo Connection.
 * @param array<string,string> $cfg Configuration of dt_cfg().
 * @param int                  $id  Row id.
 *
 * @return array<string,mixed> Row (id, customerid, amount).
 *
 * @throws DtFail When not found.
 */
function dt_lock_row(PDO $pdo, array $cfg, int $id): array
{
    $row = dt_q(
        $pdo,
        "SELECT `{$cfg['id']}` AS id, customerid, amount FROM `{$cfg['table']}` WHERE `{$cfg['id']}` = :id FOR UPDATE",
        ['id' => $id]
    )->fetch();
    if (!is_array($row)) {
        throw new DtFail($cfg['noun'] . ' not found.');
    }

    return $row;
}

/**
 * Deposit balance of a customer in cents: deposits - usages - refunds.
 * Usages are only read here (the Sales page writes them). One deposit and one refund can be left out of the sums.
 *
 * @param PDO $pdo         Connection.
 * @param int $customerId  Customer id.
 * @param int $skipDeposit Deposit id left out (0 = none).
 * @param int $skipRefund  Refund id left out (0 = none).
 *
 * @return int Balance in cents (never rounded to a float).
 */
function dt_balance_cents(PDO $pdo, int $customerId, int $skipDeposit = 0, int $skipRefund = 0): int
{
    $row = dt_q(
        $pdo,
        'SELECT
            (SELECT COALESCE(SUM(d.amount), 0) FROM customerdeposits d WHERE d.customerid = :c1 AND d.depositid <> :xd) AS deposited,
            (SELECT COALESCE(SUM(u.amount), 0) FROM customerdepositusages u WHERE u.customerid = :c2) AS used,
            (SELECT COALESCE(SUM(f.amount), 0) FROM customerdepositrefunds f WHERE f.customerid = :c3 AND f.depositrefundid <> :xr) AS refunded',
        ['c1' => $customerId, 'c2' => $customerId, 'c3' => $customerId, 'xd' => $skipDeposit, 'xr' => $skipRefund]
    )->fetch();

    return dt_cents($row['deposited'] ?? 0) - dt_cents($row['used'] ?? 0) - dt_cents($row['refunded'] ?? 0);
}

/**
 * Message of a deposit change that would leave a customer balance negative.
 *
 * @param string $customer Customer name.
 * @param int    $cents    The negative balance in cents.
 *
 * @return string Message.
 */
function dt_negative_message(string $customer, int $cents): string
{
    return 'This change would make the deposit balance of ' . $customer . ' negative (' . dt_money($cents / 100)
        . '). Part of it was already used or refunded.';
}

/**
 * Activity text of a deposit or refund.
 *
 * @param array<string,string> $cfg      Configuration of dt_cfg().
 * @param string               $verb     added | updated | deleted.
 * @param int                  $id       Row id.
 * @param string               $customer Customer name.
 * @param float                $amount   Amount.
 *
 * @return string Description.
 */
function dt_text(array $cfg, string $verb, int $id, string $customer, float $amount): string
{
    return $cfg['log'] . ' ' . $verb . ': #' . $id . ' ' . $customer . ' ' . dt_money($amount);
}

/**
 * Write a deposit activity row (call before commit). Without login the user is unknown: userid is NULL ("System" on the dashboard).
 *
 * @param PDO    $pdo    Connection.
 * @param string $action Add | Update | Delete.
 * @param string $text   Description.
 *
 * @return void
 */
function dt_log_deposit(PDO $pdo, string $action, string $text): void
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
 * List deposits or refunds (client-side DataTable).
 *
 * @param PDO    $pdo  Connection.
 * @param string $kind deposit | refund.
 *
 * @return array<string,mixed> Payload.
 */
function dt_list_movements(PDO $pdo, string $kind): array
{
    $cfg  = DT_KINDS[$kind];
    $rows = dt_q(
        $pdo,
        "SELECT m.`{$cfg['id']}` AS id, m.customerid, c.name AS customername, m.accountid,
                a.code AS accountcode, a.accountname, m.amount, m.`{$cfg['date']}` AS movedate, m.note
           FROM `{$cfg['table']}` m
           JOIN customers c ON c.customerid = m.customerid
      LEFT JOIN accounts a ON a.accountid = m.accountid
       ORDER BY m.`{$cfg['id']}` DESC"
    )->fetchAll();

    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'id'           => (int)$r['id'],
            'customerid'   => (int)$r['customerid'],
            'customername' => (string)($r['customername'] ?? ''),
            'accountid'    => (int)$r['accountid'],
            'accountname'  => trim((string)($r['accountcode'] ?? '') . ' ' . (string)($r['accountname'] ?? '')),
            'amount'       => round((float)$r['amount'], 2),
            'date'         => substr((string)$r['movedate'], 0, 10),
            'note'         => (string)($r['note'] ?? ''),
        ];
    }

    return ['success' => true, 'data' => $data];
}

/**
 * List the balances: one row per customer that has any deposit, usage or refund, plus the total held.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_list_balances(PDO $pdo): array
{
    $rows = dt_q(
        $pdo,
        'SELECT c.customerid, c.name,
                COALESCE(d.total, 0) AS deposited, COALESCE(u.total, 0) AS used, COALESCE(f.total, 0) AS refunded
           FROM customers c
      LEFT JOIN (SELECT customerid, SUM(amount) AS total FROM customerdeposits GROUP BY customerid) d ON d.customerid = c.customerid
      LEFT JOIN (SELECT customerid, SUM(amount) AS total FROM customerdepositusages GROUP BY customerid) u ON u.customerid = c.customerid
      LEFT JOIN (SELECT customerid, SUM(amount) AS total FROM customerdepositrefunds GROUP BY customerid) f ON f.customerid = c.customerid
          WHERE d.customerid IS NOT NULL OR u.customerid IS NOT NULL OR f.customerid IS NOT NULL
       ORDER BY c.name ASC, c.customerid ASC'
    )->fetchAll();

    $data       = [];
    $totalCents = 0;
    foreach ($rows as $r) {
        $dep     = dt_cents($r['deposited']);
        $used    = dt_cents($r['used']);
        $ref     = dt_cents($r['refunded']);
        $balance = $dep - $used - $ref;
        $totalCents += $balance;
        $data[] = [
            'customerid'   => (int)$r['customerid'],
            'customername' => (string)($r['name'] ?? ''),
            'deposited'    => round($dep / 100, 2),
            'used'         => round($used / 100, 2),
            'refunded'     => round($ref / 100, 2),
            'balance'      => round($balance / 100, 2),
        ];
    }

    return [
        'success' => true, 'data' => $data,
        'total' => round($totalCents / 100, 2), 'totaltext' => dt_money($totalCents / 100),
    ];
}

/**
 * List action: kind = deposits | refunds | balances (anything else = deposits).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_list(PDO $pdo): array
{
    $kind = (string)($_POST['kind'] ?? '');
    if ($kind === 'balances') {
        return dt_list_balances($pdo);
    }

    return dt_list_movements($pdo, $kind === 'refunds' ? 'refund' : 'deposit');
}

/**
 * Available deposit balance of a customer (for the refund modal). An optional refundid is left out (the refund being edited).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_balance(PDO $pdo): array
{
    $customerId = max(0, (int)($_POST['customerid'] ?? 0));
    $refundId   = max(0, (int)($_POST['refundid'] ?? 0));
    $cents      = $customerId > 0 ? dt_balance_cents($pdo, $customerId, 0, $refundId) : 0;

    return ['success' => true, 'balance' => round($cents / 100, 2)];
}

/**
 * Add (id = 0) or edit a deposit or a refund (one transaction).
 * Lock order: the touched customers in ascending id order, then the row being edited.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_save(PDO $pdo): array
{
    $cfg = dt_cfg();
    $d   = dt_read_input($cfg);

    return dt_transaction(static function (PDO $pdo) use ($cfg, $d): array {
        $customerName = dt_check_customer($pdo, $d['customer']);
        dt_check_account($pdo, $d['account']);

        $oldCustomer = 0;
        if ($d['id'] > 0) {
            $oldCustomer = dt_peek_customer($pdo, $cfg, $d['id']);
        }

        // Customers first (ascending id order), then the row being edited.
        dt_lock_customers($pdo, [$d['customer'], $oldCustomer]);
        if ($d['id'] > 0) {
            $row = dt_lock_row($pdo, $cfg, $d['id']);
            if ((int)$row['customerid'] !== $oldCustomer) {
                throw new DtFail('This record was changed by someone else. Refresh the page and try again.');
            }
        }

        if ($cfg['kind'] === 'deposit' && $d['id'] > 0) {
            // After the change, no touched customer may end below 0.
            foreach (array_values(array_unique([$d['customer'], $oldCustomer])) as $cid) {
                $after = dt_balance_cents($pdo, $cid, $d['id'], 0) + ($cid === $d['customer'] ? $d['cents'] : 0);
                if ($after < 0) {
                    $name = $cid === $d['customer'] ? $customerName : (dt_customer_name($pdo, $cid) ?? '');
                    throw new DtFail(dt_negative_message($name, $after), false, 'amount');
                }
            }
        }

        if ($cfg['kind'] === 'refund') {
            // The refund being edited is left out of the balance used for the check.
            $available = dt_balance_cents($pdo, $d['customer'], 0, $d['id']);
            if ($d['cents'] > $available) {
                throw new DtFail("Amount exceeds the customer's deposit balance (" . dt_money($available / 100) . ').', false, 'amount');
            }
        }

        $values = [
            'c' => $d['customer'],
            'a' => $d['account'],
            'm' => dt_f2($d['amount']),
            'd' => $d['date'],
            'n' => $d['note'],
        ];

        if ($d['id'] > 0) {
            dt_q(
                $pdo,
                "UPDATE `{$cfg['table']}`
                    SET customerid = :c, accountid = :a, amount = :m, `{$cfg['date']}` = :d, note = :n
                  WHERE `{$cfg['id']}` = :id",
                $values + ['id' => $d['id']]
            );
            $rowId = $d['id'];
            dt_log_deposit($pdo, 'Update', dt_text($cfg, 'updated', $rowId, $customerName, $d['amount']));

            return ['success' => true, 'message' => $cfg['noun'] . ' updated successfully.'];
        }

        dt_q(
            $pdo,
            "INSERT INTO `{$cfg['table']}` (customerid, accountid, amount, `{$cfg['date']}`, note)
             VALUES (:c, :a, :m, :d, :n)",
            $values
        );
        $rowId = (int)$pdo->lastInsertId();
        dt_log_deposit($pdo, 'Add', dt_text($cfg, 'added', $rowId, $customerName, $d['amount']));

        return ['success' => true, 'message' => $cfg['noun'] . ' added successfully.'];
    });
}

/**
 * Delete a deposit or a refund (one transaction). A refund can always be deleted;
 * a deposit only when its customer balance without it stays at 0 or more.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found or the balance would become negative.
 */
function dt_act_delete(PDO $pdo): array
{
    $cfg = dt_cfg();
    $id  = max(0, (int)($_POST['id'] ?? 0));

    return dt_transaction(static function (PDO $pdo) use ($cfg, $id): array {
        $customerId = dt_peek_customer($pdo, $cfg, $id);

        dt_lock_customers($pdo, [$customerId]);
        $row = dt_lock_row($pdo, $cfg, $id);
        if ((int)$row['customerid'] !== $customerId) {
            throw new DtFail('This record was changed by someone else. Refresh the page and try again.');
        }

        $customerName = dt_customer_name($pdo, $customerId) ?? '';

        if ($cfg['kind'] === 'deposit') {
            $after = dt_balance_cents($pdo, $customerId, $id, 0);
            if ($after < 0) {
                throw new DtFail(dt_negative_message($customerName, $after));
            }
        }

        dt_q($pdo, "DELETE FROM `{$cfg['table']}` WHERE `{$cfg['id']}` = :id", ['id' => $id]);
        dt_log_deposit($pdo, 'Delete', dt_text($cfg, 'deleted', $id, $customerName, (float)$row['amount']));

        return ['success' => true, 'message' => $cfg['noun'] . ' deleted successfully.'];
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

    $handlers = ['list' => 'dt_act_list', 'save' => 'dt_act_save', 'delete' => 'dt_act_delete', 'balance' => 'dt_act_balance'];
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
        dt_log_error('[customerdeposit ' . $action . '] ' . dt_describe_throwable($e));
        if ($action === 'save' && $e->getCode() === '23000') {
            dt_json(dt_fail('The selected customer or account no longer exists.'));
        }
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[customerdeposit ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    }
}

/* ================================================================
 * 5. PAGE DATA (customers and receiving accounts)
 * ============================================================== */

/**
 * Customers for the selects (Walk-in is not listed), ordered by name.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<int,array<string,mixed>> Rows (customerid, name).
 */
function dt_load_customers(PDO $pdo): array
{
    return dt_q(
        $pdo,
        'SELECT customerid, name FROM customers WHERE LOWER(name) <> LOWER(:w) ORDER BY name ASC',
        ['w' => BF_WALKIN_NAME]
    )->fetchAll();
}

/**
 * Accounts: active Assets sub-accounts, sorted naturally by code.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<int,array<string,mixed>> Rows (accountid, code, accountname).
 */
function dt_load_accounts(PDO $pdo): array
{
    $rows = dt_q(
        $pdo,
        "SELECT accountid, code, accountname FROM accounts
          WHERE accounttype = 'Assets' AND parentaccountid IS NOT NULL AND status = 'ON'"
    )->fetchAll();
    usort($rows, static fn (array $a, array $b): int => strnatcmp((string)$a['code'], (string)$b['code']));

    return $rows;
}

/* ================================================================
 * 6. HTML SHELL (same look as receipts.php): CSS, head, topbar, sidebar, footer
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

/* ===== Compact tabs ===== */
.nav-tabs { border-bottom: 1px solid var(--line); margin-bottom: 16px; flex-wrap: nowrap; overflow-x: auto; overflow-y: hidden; }
.nav-tabs .nav-item { margin-bottom: -1px; }
.nav-tabs .nav-link {
    border-radius: 0; padding: 6px 16px; font-size: 14px; font-weight: 500; color: var(--text);
    white-space: nowrap; border: 1px solid transparent; border-top: 3px solid transparent;
}
.nav-tabs .nav-link:hover, .nav-tabs .nav-link:focus { color: var(--brand-primary); border-color: transparent; border-top-color: transparent; }
.nav-tabs .nav-link.active {
    color: var(--brand-primary); font-weight: 600; background: #ffffff;
    border-color: var(--line) var(--line) #ffffff; border-top: 3px solid var(--brand-accent);
}
.nav-tabs .nav-link:focus-visible { outline: 3px solid var(--brand-accent); outline-offset: -3px; }

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
.text-muted { color: var(--muted) !important; }
.help-line { margin-top: 4px; font-size: 13px; color: var(--muted); min-height: 19px; }

/* ===== Balances ===== */
.summary-line { margin-bottom: 12px; font-size: 14px; color: var(--text); }
.summary-line strong { color: var(--brand-primary); }
.bal-pos { color: var(--brand-accent-dark); font-weight: 700; }
.bal-zero { color: var(--danger); font-weight: 700; }

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

/* ===== DataTables ===== */
#dTable, #rTable, #bTable { width: 100% !important; font-size: 14px; }
#dTable thead th, #rTable thead th, #bTable thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); white-space: nowrap; font-weight: 600; }
#dTable td, #rTable td, #bTable td { vertical-align: middle; }
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
                            <a href="<?= dt_e($subPage) ?>"<?= ($isAccounting && $subPage === 'customerdeposit.php') ? ' class="active" aria-current="page"' : '' ?>><span><?= dt_e($subLabel) ?></span></a>
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
 * The card with the three tabs: Deposits, Refunds, Balances.
 *
 * @return void
 */
function dt_view_tabs(): void
{
    ?>
        <div class="row">
          <div class="col-12 mb-4">
            <div class="card">
              <div class="card-body">
                <ul class="nav nav-tabs" id="depTabs" role="tablist">
                  <li class="nav-item" role="presentation">
                    <a class="nav-link active" id="tabDepLink" data-toggle="tab" href="#paneDep" role="tab" aria-controls="paneDep" aria-selected="true">Deposits</a>
                  </li>
                  <li class="nav-item" role="presentation">
                    <a class="nav-link" id="tabRefLink" data-toggle="tab" href="#paneRef" role="tab" aria-controls="paneRef" aria-selected="false">Refunds</a>
                  </li>
                  <li class="nav-item" role="presentation">
                    <a class="nav-link" id="tabBalLink" data-toggle="tab" href="#paneBal" role="tab" aria-controls="paneBal" aria-selected="false">Balances</a>
                  </li>
                </ul>

                <div class="tab-content">
                  <div class="tab-pane fade show active" id="paneDep" role="tabpanel" aria-labelledby="tabDepLink">
                    <div class="card-head">
                      <h2 class="header-title">Deposit List</h2>
                      <button type="button" class="btn btn-primary btn-sm" id="btnAddDeposit"><i class="fas fa-plus mr-1" aria-hidden="true"></i> Add Deposit</button>
                    </div>
                    <table id="dTable" class="table table-hover table-bordered">
                      <thead>
                        <tr>
                          <th class="px60">#</th>
                          <th>Deposit</th>
                          <th>Customer</th>
                          <th>Account</th>
                          <th class="text-right">Amount</th>
                          <th>Date</th>
                          <th>Note</th>
                          <th class="px120 text-center">Action</th>
                        </tr>
                      </thead>
                      <tbody></tbody>
                    </table>
                  </div>

                  <div class="tab-pane fade" id="paneRef" role="tabpanel" aria-labelledby="tabRefLink">
                    <div class="card-head">
                      <h2 class="header-title">Refund List</h2>
                      <button type="button" class="btn btn-primary btn-sm" id="btnAddRefund"><i class="fas fa-plus mr-1" aria-hidden="true"></i> Add Refund</button>
                    </div>
                    <table id="rTable" class="table table-hover table-bordered">
                      <thead>
                        <tr>
                          <th class="px60">#</th>
                          <th>Refund</th>
                          <th>Customer</th>
                          <th>Account</th>
                          <th class="text-right">Amount</th>
                          <th>Date</th>
                          <th>Note</th>
                          <th class="px120 text-center">Action</th>
                        </tr>
                      </thead>
                      <tbody></tbody>
                    </table>
                  </div>

                  <div class="tab-pane fade" id="paneBal" role="tabpanel" aria-labelledby="tabBalLink">
                    <div class="card-head">
                      <h2 class="header-title">Deposit Balances</h2>
                    </div>
                    <p class="summary-line">Total deposits held: <strong id="bTotal">0.00</strong></p>
                    <table id="bTable" class="table table-hover table-bordered">
                      <thead>
                        <tr>
                          <th class="px60">#</th>
                          <th>Customer</th>
                          <th class="text-right">Deposited</th>
                          <th class="text-right">Used on invoices</th>
                          <th class="text-right">Refunded</th>
                          <th class="text-right">Balance</th>
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
 * Add / edit deposit modal.
 *
 * @param array<int,array<string,mixed>> $customers Customers (Walk-in not listed).
 * @param array<int,array<string,mixed>> $accounts  Receiving accounts.
 * @param string                         $today     Today (Y-m-d).
 *
 * @return void
 */
function dt_modal_deposit(array $customers, array $accounts, string $today): void
{
    ?>
  <div class="modal fade" id="dModal" tabindex="-1" role="dialog" aria-labelledby="dModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form class="modal-content" id="dForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="dModalTitle">Add Deposit</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="dId" value="0">
          <div class="form-group">
            <label for="dCustomer">Customer <span class="req">*</span></label>
            <select class="form-control" id="dCustomer" required>
              <option value="">Select customer</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['customerid'] ?>"><?= dt_e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback" id="dCustomerFb">Customer is required.</div>
          </div>
          <div class="form-group">
            <label for="dAccount">Account <span class="req">*</span></label>
            <select class="form-control" id="dAccount" required>
              <option value="">Select account</option>
              <?php foreach ($accounts as $a): ?>
                <option value="<?= (int)$a['accountid'] ?>"><?= dt_e($a['code'] . ' ' . $a['accountname']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback" id="dAccountFb">Account is required.</div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="dAmount">Amount <span class="req">*</span></label>
              <input type="number" class="form-control" id="dAmount" step="0.01" min="0.01" required>
              <div class="invalid-feedback" id="dAmountFb">Enter an amount greater than 0.</div>
            </div>
            <div class="form-group col-md-6">
              <label for="dDate">Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="dDate" value="<?= dt_e($today) ?>" required>
              <div class="invalid-feedback" id="dDateFb">Date is required.</div>
            </div>
          </div>
          <div class="form-group mb-0">
            <label for="dNote">Note</label>
            <input type="text" class="form-control" id="dNote" maxlength="<?= DT_MAX_NOTE ?>">
            <div class="invalid-feedback" id="dNoteFb">The note is too long.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveDeposit"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Add / edit refund modal (shows the available deposit balance of the chosen customer).
 *
 * @param array<int,array<string,mixed>> $customers Customers (Walk-in not listed).
 * @param array<int,array<string,mixed>> $accounts  Paying accounts.
 * @param string                         $today     Today (Y-m-d).
 *
 * @return void
 */
function dt_modal_refund(array $customers, array $accounts, string $today): void
{
    ?>
  <div class="modal fade" id="rModal" tabindex="-1" role="dialog" aria-labelledby="rModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form class="modal-content" id="rForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="rModalTitle">Add Refund</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="rId" value="0">
          <div class="form-group">
            <label for="rCustomer">Customer <span class="req">*</span></label>
            <select class="form-control" id="rCustomer" required aria-describedby="rBalance">
              <option value="">Select customer</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['customerid'] ?>"><?= dt_e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback" id="rCustomerFb">Customer is required.</div>
            <div class="help-line" id="rBalance" aria-live="polite"></div>
          </div>
          <div class="form-group">
            <label for="rAccount">Account <span class="req">*</span></label>
            <select class="form-control" id="rAccount" required>
              <option value="">Select account</option>
              <?php foreach ($accounts as $a): ?>
                <option value="<?= (int)$a['accountid'] ?>"><?= dt_e($a['code'] . ' ' . $a['accountname']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback" id="rAccountFb">Account is required.</div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="rAmount">Amount <span class="req">*</span></label>
              <input type="number" class="form-control" id="rAmount" step="0.01" min="0.01" required>
              <div class="invalid-feedback" id="rAmountFb">Enter an amount greater than 0.</div>
            </div>
            <div class="form-group col-md-6">
              <label for="rDate">Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="rDate" value="<?= dt_e($today) ?>" required>
              <div class="invalid-feedback" id="rDateFb">Date is required.</div>
            </div>
          </div>
          <div class="form-group mb-0">
            <label for="rNote">Note</label>
            <input type="text" class="form-control" id="rNote" maxlength="<?= DT_MAX_NOTE ?>">
            <div class="invalid-feedback" id="rNoteFb">The note is too long.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveRefund"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Delete deposit / refund modal.
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
          <h5 class="modal-title" id="deleteModalTitle">Delete Deposit</h5>
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

$customers = [];
$accounts  = [];
try {
    $customers = dt_load_customers(dt_pdo());
    $accounts  = dt_load_accounts(dt_pdo());
} catch (Throwable $e) {
    dt_log_error('[customerdeposit view] ' . dt_describe_throwable($e));
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
    'csrfField' => $csrfField, 'docTitle' => 'Customer Deposit', 'heading' => 'Customer Deposit',
    'sub' => 'Record money customers pay in advance, and give it back.',
];

/* ---------- Render ---------- */

dt_render_open($page);
dt_view_tabs();
dt_render_close();
dt_modal_deposit($customers, $accounts, $today);
dt_modal_refund($customers, $accounts, $today);
dt_modal_delete();

$cfg       = ['csrf' => $csrf, 'self' => 'customerdeposit.php', 'today' => $today, 'hasAccounts' => count($accounts) > 0];
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
    /* ---------- Tabs: remembered in sessionStorage, each DataTable is created on its first show ---------- */
    var TABS = { paneDep: true, paneRef: true, paneBal: true };
    var TAB_KEY = 'dt_deposit_tab';
    var tables = {};

    function readTab() {
      try {
        var v = sessionStorage.getItem(TAB_KEY);
        return (v && Object.prototype.hasOwnProperty.call(TABS, v)) ? v : 'paneDep';
      } catch (e) { return 'paneDep'; }
    }
    function saveTab(id) {
      try { sessionStorage.setItem(TAB_KEY, id); } catch (e) {}
    }

    /* ---------- DataTable builders (client side, fed by AJAX) ---------- */
    function numberCol() {
      return { data: null, orderable: false, searchable: false, defaultContent: '' };
    }
    function textCol(key) {
      return { data: key, render: function (d, type) { return type === 'display' ? esc(d) : d; } };
    }
    function amountCol(key) {
      return {
        data: key, className: 'text-right',
        render: function (d, type) { return (type === 'display' || type === 'filter') ? fmt(d) : d; }
      };
    }
    function actionCol(word) {
      return {
        data: null, orderable: false, searchable: false, className: 'text-center',
        defaultContent: '<button type="button" class="btn btn-primary act-btn btn-edit" title="Edit" aria-label="Edit ' + word + '"><i class="fas fa-edit" aria-hidden="true"></i></button> ' +
                        '<button type="button" class="btn btn-danger act-btn btn-del" title="Delete" aria-label="Delete ' + word + '"><i class="fas fa-trash" aria-hidden="true"></i></button>'
      };
    }

    // PDF (A4): column widths and the columns whose text is right aligned
    function pdfCustomize(widths, rightCols) {
      return function (doc) {
        var tbl = null;
        $.each(doc.content, function (i, c) { if (c.table) { tbl = c.table; } });
        if (!tbl) { return; }
        tbl.widths = widths;
        $.each(tbl.body, function (i, row) {
          $.each(rightCols, function (j, idx) { if (row[idx]) { row[idx].alignment = 'right'; } });
        });
      };
    }

    function makeTable(sel, listKind, columns, order, exportCols, pdfFn, texts) {
      var base = {
        className: 'btn btn-secondary btn-sm',
        title: texts.title,
        filename: texts.title,
        exportOptions: { columns: exportCols }
      };

      var dt = $(sel).DataTable({
        autoWidth: false,
        ajax: {
          url: URL_SELF,
          type: 'POST',
          data: function (d) { d.action = 'list'; d.kind = listKind; d.csrf_token = CSRF; },
          dataSrc: function (json) {
            if (!json || !json.success) {
              toastr.error((json && json.message) || texts.loadError);
              return [];
            }
            if (listKind === 'balances') { $('#bTotal').text(json.totaltext); }
            return json.data;
          },
          error: function (xhr) {
            if (window.console) { console.warn(listKind + ' list request failed (HTTP ' + (xhr && xhr.status) + ')'); }
            toastr.error(texts.loadError);
          }
        },
        columns: columns,
        order: order,
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        dom: "<'dt-top'<'dt-len'l><'dt-btns'B><'dt-find'f>>" +
             "<'table-scroll'tr>" +
             "<'row mt-2'<'col-md-5'i><'col-md-7'p>>",
        buttons: [
          $.extend({}, base, { extend: 'copyHtml5',  text: '<i class="fas fa-copy"></i> Copy',        title: '' }),
          $.extend({}, base, { extend: 'csvHtml5',   text: '<i class="fas fa-file-csv"></i> CSV' }),
          $.extend({}, base, { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel' }),
          $.extend({}, base, { extend: 'pdfHtml5',   text: '<i class="fas fa-file-pdf"></i> PDF', orientation: 'portrait', pageSize: 'A4', customize: pdfFn }),
          $.extend({}, base, { extend: 'print',      text: '<i class="fas fa-print"></i> Print' })
        ],
        drawCallback: function () {
          var api = this.api();
          var start = api.page.info().start;
          api.column(0, { page: 'current' }).nodes().each(function (cell, i) { cell.textContent = start + i + 1; });
        },
        initComplete: function () { this.api().columns.adjust(); },
        language: { search: 'Search:', searchPlaceholder: texts.search, emptyTable: texts.empty, zeroRecords: texts.empty }
      });
      dt.columns.adjust();

      return dt;
    }

    function movementColumns(word) {
      return [
        numberCol(),
        { data: 'id' },
        textCol('customername'),
        textCol('accountname'),
        amountCol('amount'),
        { data: 'date', render: function (d, type) { return type === 'display' ? esc(d) : d; } },
        textCol('note'),
        actionCol(word)
      ];
    }

    function showPane(id) {
      if (tables[id]) {
        tables[id].columns.adjust();
        return;
      }
      if (id === 'paneDep') {
        tables[id] = makeTable('#dTable', 'deposits', movementColumns('deposit'), [[1, 'desc']], [1, 2, 3, 4, 5, 6],
          pdfCustomize([42, '*', '*', 62, 58, '*'], [3]),
          { title: 'Customer Deposits', search: 'Search deposit...', empty: 'No deposits found', loadError: 'Could not load deposits.' });
      } else if (id === 'paneRef') {
        tables[id] = makeTable('#rTable', 'refunds', movementColumns('refund'), [[1, 'desc']], [1, 2, 3, 4, 5, 6],
          pdfCustomize([42, '*', '*', 62, 58, '*'], [3]),
          { title: 'Deposit Refunds', search: 'Search refund...', empty: 'No refunds found', loadError: 'Could not load refunds.' });
      } else if (id === 'paneBal') {
        tables[id] = makeTable('#bTable', 'balances', [
          numberCol(),
          textCol('customername'),
          amountCol('deposited'),
          amountCol('used'),
          amountCol('refunded'),
          {
            data: 'balance', className: 'text-right',
            render: function (d, type) {
              if (type !== 'display') { return (type === 'filter') ? fmt(d) : d; }
              return '<span class="' + (Number(d) > 0 ? 'bal-pos' : 'bal-zero') + '">' + esc(fmt(d)) + '</span>';
            }
          }
        ], [[1, 'asc']], [1, 2, 3, 4, 5],
          pdfCustomize(['*', 70, 70, 70, 70], [1, 2, 3, 4]),
          { title: 'Deposit Balances', search: 'Search customer...', empty: 'No deposit balances found', loadError: 'Could not load balances.' });
      }
    }

    // Reload every table that already exists (a deposit or refund changes the balances too)
    function reloadAll() {
      $.each(tables, function (id, t) { t.ajax.reload(null, false); });
    }

    $('#depTabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
      var id = String($(e.target).attr('href')).substring(1);
      saveTab(id);
      showPane(id);
    });

    function rowData(id, el) { return tables[id].row($(el).closest('tr')).data(); }

    /* ---------- Select2 ---------- */
    $('#dCustomer, #dAccount').select2({ theme: 'bootstrap4', width: '100%', dropdownParent: $('#dModal') });
    $('#rCustomer, #rAccount').select2({ theme: 'bootstrap4', width: '100%', dropdownParent: $('#rModal') });

    /* ---------- Per-kind setup: server field name => input selector (feedback element is selector + "Fb") ---------- */
    var K = {
      deposit: {
        modal: '#dModal', form: '#dForm', title: '#dModalTitle', id: '#dId', btn: '#btnSaveDeposit', dateKey: 'depositdate', word: 'Deposit',
        fields: { customerid: '#dCustomer', accountid: '#dAccount', amount: '#dAmount', depositdate: '#dDate', note: '#dNote' }
      },
      refund: {
        modal: '#rModal', form: '#rForm', title: '#rModalTitle', id: '#rId', btn: '#btnSaveRefund', dateKey: 'refunddate', word: 'Refund',
        fields: { customerid: '#rCustomer', accountid: '#rAccount', amount: '#rAmount', refunddate: '#rDate', note: '#rNote' }
      }
    };

    $.each(K, function (kind, k) {
      k.defaults = {};
      $.each(k.fields, function (key, sel) { k.defaults[key] = $(sel + 'Fb').text(); });
      k.all = $.map(k.fields, function (sel) { return sel; }).join(', ');
      $(k.all).on('input change', function () { $(this).removeClass('is-invalid'); });
    });

    function clearInvalid(k) {
      $.each(k.fields, function (key, sel) {
        $(sel).removeClass('is-invalid');
        $(sel + 'Fb').text(k.defaults[key]);
      });
    }

    function markInvalid(k, key, message) {
      var sel = k.fields[key];
      if (message) { $(sel + 'Fb').text(message); }
      $(sel).addClass('is-invalid');
      return sel;
    }

    function focusField(sel) {
      if ($(sel).is('select')) { $(sel).select2('open'); } else { $(sel).trigger('focus'); }
    }

    function setSelect(sel, value) {
      $(sel).val(value ? String(value) : '');
      if ($(sel).val() === null) { $(sel).val(''); }
      $(sel).trigger('change.select2');
    }

    /* ---------- Available balance in the refund modal (late answers are ignored with a request counter) ---------- */
    var balReq = 0;

    function loadRefundBalance() {
      var customer = $('#rCustomer').val();
      var req = ++balReq;
      $('#rBalance').text('');
      if (!customer) { return; }
      api({ action: 'balance', customerid: customer, refundid: $('#rId').val() }).done(function (res) {
        if (req !== balReq || !res.success) { return; }
        $('#rBalance').text('Available balance: ' + fmt(res.balance));
      });
    }

    $('#rCustomer').on('change', loadRefundBalance);

    /* ---------- Add / Edit ---------- */
    function openModal(kind, d) {
      var k = K[kind];
      if (!CFG.hasAccounts) {
        toastr.warning('Add an active asset account in Chart of Accounts first.');
        return;
      }
      $(k.form)[0].reset();
      clearInvalid(k);
      if (kind === 'refund') { balReq++; $('#rBalance').text(''); }

      $(k.id).val(d ? d.id : 0);
      $(k.fields[k.dateKey]).val(d ? d.date : TODAY);
      setSelect(k.fields.customerid, d ? d.customerid : '');
      setSelect(k.fields.accountid, d ? d.accountid : '');
      $(k.fields.amount).val(d ? Number(d.amount).toFixed(2) : '');
      $(k.fields.note).val(d ? (d.note || '') : '');
      $(k.title).text((d ? 'Edit ' : 'Add ') + k.word);

      if (kind === 'refund' && d) { loadRefundBalance(); }
      $(k.modal).modal('show');
    }

    $('#btnAddDeposit').on('click', function () { openModal('deposit', null); });
    $('#btnAddRefund').on('click', function () { openModal('refund', null); });

    $('#dTable tbody').on('click', '.btn-edit', function () {
      var d = rowData('paneDep', this);
      if (d) { openModal('deposit', d); }
    });
    $('#rTable tbody').on('click', '.btn-edit', function () {
      var d = rowData('paneRef', this);
      if (d) { openModal('refund', d); }
    });

    /* ---------- Save (add / edit) ---------- */
    $.each(K, function (kind, k) {
      $(k.form).on('submit', function (e) {
        e.preventDefault();
        clearInvalid(k);

        var customer = $(k.fields.customerid).val();
        var account  = $(k.fields.accountid).val();
        var amount   = parseFloat($(k.fields.amount).val());
        var date     = $(k.fields[k.dateKey]).val();

        if (!customer)                    { focusField(markInvalid(k, 'customerid')); return; }
        if (!account)                     { focusField(markInvalid(k, 'accountid')); return; }
        if (isNaN(amount) || amount <= 0) { focusField(markInvalid(k, 'amount')); return; }
        if (!date)                        { focusField(markInvalid(k, dateKeyOf(k))); return; }

        var data = {
          action: 'save',
          kind: kind,
          id: $(k.id).val(),
          customerid: customer,
          accountid: account,
          amount: $(k.fields.amount).val(),
          note: $.trim($(k.fields.note).val())
        };
        data[k.dateKey] = date;

        var $btn = $(k.btn).prop('disabled', true);
        api(data).done(function (res) {
          if (res.success) {
            toastr.success(res.message);
            $(k.modal).modal('hide');
            reloadAll();
            return;
          }
          if (res.field && k.fields[res.field]) {
            focusField(markInvalid(k, res.field, res.message));
          }
          toastr.error(res.message);
        }).always(function () { $btn.prop('disabled', false); });
      });
    });

    function dateKeyOf(k) { return k.dateKey; }

    /* ---------- Delete ---------- */
    var deleteId = 0;
    var deleteKind = 'deposit';

    function askDelete(kind, d) {
      deleteKind = kind;
      deleteId = d.id;
      var word = K[kind].word;
      $('#deleteModalTitle').text('Delete ' + word);
      $('#delText').empty().append(
        document.createTextNode('Delete ' + word.toLowerCase() + ' '),
        $('<b>').text('#' + d.id),
        document.createTextNode(' (' + fmt(d.amount) + ', ' + (d.customername || '') + ')? This cannot be undone.')
      );
      $('#deleteModal').modal('show');
    }

    $('#dTable tbody').on('click', '.btn-del', function () {
      var d = rowData('paneDep', this);
      if (d) { askDelete('deposit', d); }
    });
    $('#rTable tbody').on('click', '.btn-del', function () {
      var d = rowData('paneRef', this);
      if (d) { askDelete('refund', d); }
    });

    $('#btnConfirmDelete').on('click', function () {
      var $btn = $(this).prop('disabled', true);
      api({ action: 'delete', kind: deleteKind, id: deleteId }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          reloadAll();
        } else {
          toastr.error(res.message);
        }
      }).always(function () {
        $('#deleteModal').modal('hide');
        $btn.prop('disabled', false);
      });
    });

    /* ---------- Start on the remembered tab ---------- */
    (function () {
      var start = readTab();
      if (start === 'paneDep') {
        showPane('paneDep');
      } else {
        $('#depTabs a[href="#' + start + '"]').tab('show');
      }
    })();
</script>
</body>
</html>