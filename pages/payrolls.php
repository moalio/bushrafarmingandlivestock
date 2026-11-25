<?php

declare(strict_types=1);

/**
 * pages/payrolls.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: Payrolls (sidebar > Accounting > Payrolls).
 *
 * Salary bills for several employees at once and the payments of those salaries.
 * Tables written by this page: payrollbills (header), payrollbillitems (one line per employee),
 * payrollpayments (money paid on a line) and activitylogs.
 *
 * One page, seven AJAX actions (POST, JSON): list, bill, bill_pay, save, delete, pay, pay_delete.
 * Money is compared in integer cents. amountpaid and status of a line are always recomputed
 * from the sum of its payments, never incremented. A bill with payments is frozen.
 * Lock order: bill, then its lines, then payments (pay_delete: line first, then payment).
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call the auth helpers of ../db.php right after the "NO LOGIN" marker
 * in the request flow below. Single tenant: no agency id in any query.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';

if (!defined('DT_ALLOW_CDN')) {
    define('DT_ALLOW_CDN', true); // opt-in: allows the vetted CDN hosts in the CSP for this page only
}

/* ================================================================
 * 1. CONSTANTS AND CONFIG
 * ============================================================== */

const DT_MAX_MEMO   = 255;
const DT_MAX_AMOUNT = 1000000000; // exclusive upper limit of one amount
const DT_MAX_LINES  = 1000;
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
if (!function_exists('dt_valid_date')) {
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
}
if (!function_exists('dt_money')) {
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
}

/**
 * Run a prepared query. Placeholders are named, each name used once per statement
 * (positional placeholders only in statements that use no named ones).
 *
 * @param PDO                     $pdo    Connection.
 * @param string                  $sql    SQL with named placeholders (each name used once) or positional ones (never mixed).
 * @param array<int|string,mixed> $params Bound values.
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

/* ================================================================
 * 3. SHARED BUSINESS RULES (cents, read and validate, load and lock, recompute, log)
 * ============================================================== */

/**
 * Convert a decimal value (string, int or float) to integer cents.
 *
 * @param mixed $value Amount.
 *
 * @return int Cents.
 */
function dt_cents(mixed $value): int
{
    return (int)round((float)$value * 100);
}

/**
 * Integer cents as a plain 2-decimal string for database writes.
 *
 * @param int $cents Cents.
 *
 * @return string e.g. "120.75".
 */
function dt_cents_text(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

/**
 * Integer cents formatted for messages.
 *
 * @param int $cents Cents.
 *
 * @return string e.g. "1,234.50".
 */
function dt_cents_money(int $cents): string
{
    return dt_money($cents / 100);
}

/**
 * Count text: "1 employee" or "N employees".
 *
 * @param int $n Count.
 *
 * @return string Text.
 */
function dt_employees_text(int $n): string
{
    return $n === 1 ? '1 employee' : $n . ' employees';
}

/**
 * Balance of a line in cents: 0 when PAID, otherwise amount minus amountpaid, never below 0.
 *
 * @param int    $amountCents Line amount in cents.
 * @param int    $paidCents   Amount paid in cents.
 * @param string $status      Stored status (UNPAID | PAID).
 *
 * @return int Balance in cents.
 */
function dt_line_balance(int $amountCents, int $paidCents, string $status): int
{
    return $status === 'PAID' ? 0 : max(0, $amountCents - $paidCents);
}

/**
 * Label of a line: PAID, PARTIALLY PAID or UNPAID.
 *
 * @param string $status    Stored status (UNPAID | PAID).
 * @param int    $paidCents Amount paid in cents.
 *
 * @return string Label.
 */
function dt_line_label(string $status, int $paidCents): string
{
    if ($status === 'PAID') {
        return 'PAID';
    }

    return $paidCents > 0 ? 'PARTIALLY PAID' : 'UNPAID';
}

/**
 * Read and validate the posted bill header (used by save, before the transaction).
 *
 * @return array{id:int,memo:string,date:string} Clean fields.
 *
 * @throws DtFail On invalid input.
 */
function dt_read_bill_input(): array
{
    $id   = max(0, (int)($_POST['payrollbillid'] ?? 0));
    $memo = trim((string)($_POST['memo'] ?? ''));
    $date = trim((string)($_POST['billdate'] ?? ''));

    if ($memo === '') {
        throw new DtFail('Memo is required.', false, 'memo');
    }
    if (mb_strlen($memo) > DT_MAX_MEMO) {
        throw new DtFail('The memo is too long (maximum ' . DT_MAX_MEMO . ' characters).', false, 'memo');
    }
    if (!dt_valid_date($date)) {
        throw new DtFail('A valid bill date is required.', false, 'billdate');
    }

    return ['id' => $id, 'memo' => $memo, 'date' => $date];
}

/**
 * Read and validate the posted lines (JSON array "items" of {employeeid, amount}), sorted by employee id.
 *
 * @return list<array{employeeid:int,cents:int}> Clean lines.
 *
 * @throws DtFail On invalid input (line errors carry no field).
 */
function dt_read_lines(): array
{
    $raw  = $_POST['items'] ?? '[]';
    $list = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($list) || !array_is_list($list) || $list === []) {
        throw new DtFail('Select at least one employee.');
    }
    if (count($list) > DT_MAX_LINES) {
        throw new DtFail('A bill can have at most ' . DT_MAX_LINES . ' employees.');
    }

    $seen = [];
    $out  = [];
    foreach ($list as $item) {
        if (!is_array($item)) {
            throw new DtFail('Select a valid employee.');
        }
        $eid = $item['employeeid'] ?? null;
        $eid = (is_int($eid) || (is_string($eid) && ctype_digit($eid))) ? (int)$eid : 0;
        if ($eid <= 0) {
            throw new DtFail('Select a valid employee.');
        }
        if (isset($seen[$eid])) {
            throw new DtFail('An employee can appear only once in a bill.');
        }
        $seen[$eid] = true;

        $rawAmount = $item['amount'] ?? '';
        if (is_int($rawAmount) || is_float($rawAmount)) {
            $rawAmount = (string)$rawAmount;
        }
        $rawAmount = is_string($rawAmount) ? trim($rawAmount) : '';
        if ($rawAmount === '' || !is_numeric($rawAmount)) {
            throw new DtFail('Enter a valid amount for every employee.');
        }
        $f = (float)$rawAmount;
        if (!is_finite($f)) {
            throw new DtFail('Enter a valid amount for every employee.');
        }
        if ($f <= 0) {
            throw new DtFail('Every amount must be greater than 0.');
        }
        if ($f >= DT_MAX_AMOUNT) {
            throw new DtFail('Amount is too large.');
        }
        $cents = (int)round($f * 100);
        if ($cents <= 0) {
            throw new DtFail('Every amount must be greater than 0.');
        }
        if ($cents >= DT_MAX_AMOUNT * 100) {
            throw new DtFail('Amount is too large.');
        }
        $out[] = ['employeeid' => $eid, 'cents' => $cents];
    }

    usort($out, static fn(array $a, array $b): int => $a['employeeid'] <=> $b['employeeid']);

    return $out;
}

/**
 * Sum of the cents of a list of lines.
 *
 * @param list<array{employeeid:int,cents:int}> $lines Clean lines.
 *
 * @return int Total in cents.
 */
function dt_lines_cents(array $lines): int
{
    $total = 0;
    foreach ($lines as $l) {
        $total += $l['cents'];
    }

    return $total;
}

/**
 * Check that every employee exists (positional placeholders only).
 *
 * @param PDO                                   $pdo   Connection.
 * @param list<array{employeeid:int,cents:int}> $lines Clean lines.
 *
 * @return void
 *
 * @throws DtFail When one employee does not exist.
 */
function dt_check_employees(PDO $pdo, array $lines): void
{
    $ids = array_values(array_column($lines, 'employeeid'));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $n   = (int)dt_q($pdo, "SELECT COUNT(*) FROM employees WHERE employeeid IN ({$ph})", $ids)->fetchColumn();
    if ($n !== count($ids)) {
        throw new DtFail('Select a valid employee.');
    }
}

/**
 * Load and lock (FOR UPDATE) a bill row. Call inside a transaction. First lock of every action (bill, then lines, then payments).
 *
 * @param PDO $pdo Connection.
 * @param int $id  Bill id.
 *
 * @return array<string,mixed>|null Row (payrollbillid, memo, billdate) or null when not found.
 */
function dt_lock_bill(PDO $pdo, int $id): ?array
{
    $row = dt_q(
        $pdo,
        'SELECT payrollbillid, memo, billdate FROM payrollbills WHERE payrollbillid = :id FOR UPDATE',
        ['id' => $id]
    )->fetch();

    return is_array($row) ? $row : null;
}

/**
 * Lock (FOR UPDATE) the lines of a bill, ordered by line id. Call inside a transaction, after the bill row is locked.
 *
 * @param PDO $pdo    Connection.
 * @param int $billId Bill id.
 *
 * @return list<array<string,mixed>> Rows (payrollbillitemid, employeeid, amount, amountpaid, status).
 */
function dt_lock_lines(PDO $pdo, int $billId): array
{
    return dt_q(
        $pdo,
        'SELECT payrollbillitemid, employeeid, amount, amountpaid, status
           FROM payrollbillitems WHERE payrollbillid = :b ORDER BY payrollbillitemid FOR UPDATE',
        ['b' => $billId]
    )->fetchAll();
}

/**
 * Assert that no line of a bill has a payment (amountpaid 0, status UNPAID and no payrollpayments row).
 *
 * @param PDO                        $pdo     Connection.
 * @param int                        $billId  Bill id.
 * @param list<array<string,mixed>>  $lines   Locked lines.
 * @param string                     $message Failure message.
 *
 * @return void
 *
 * @throws DtFail When the bill has any payment.
 */
function dt_assert_unpaid(PDO $pdo, int $billId, array $lines, string $message): void
{
    foreach ($lines as $l) {
        if (dt_cents($l['amountpaid']) !== 0 || (string)$l['status'] !== 'UNPAID') {
            throw new DtFail($message);
        }
    }
    $count = (int)dt_q(
        $pdo,
        'SELECT COUNT(*) FROM payrollpayments p JOIN payrollbillitems i ON i.payrollbillitemid = p.payrollbillitemid WHERE i.payrollbillid = :b',
        ['b' => $billId]
    )->fetchColumn();
    if ($count > 0) {
        throw new DtFail($message);
    }
}

/**
 * Assert that the stored amountpaid of a line equals the sum of its payments (in cents).
 *
 * @param PDO $pdo         Connection.
 * @param int $lineId      Line id.
 * @param int $storedCents Stored amountpaid in cents.
 *
 * @return void
 *
 * @throws DtFail When they differ.
 */
function dt_assert_reconciled(PDO $pdo, int $lineId, int $storedCents): void
{
    $sum = dt_cents(dt_q(
        $pdo,
        'SELECT COALESCE(SUM(amount), 0) FROM payrollpayments WHERE payrollbillitemid = :i',
        ['i' => $lineId]
    )->fetchColumn());
    if ($sum !== $storedCents) {
        throw new DtFail('This bill has payments recorded outside this page and cannot be changed here.');
    }
}

/**
 * Recompute amountpaid and status of a line from the sum of its payments (never incremented).
 *
 * @param PDO $pdo         Connection.
 * @param int $lineId      Line id.
 * @param int $amountCents Line amount in cents.
 *
 * @return void
 */
function dt_recompute_line(PDO $pdo, int $lineId, int $amountCents): void
{
    $paid = dt_cents(dt_q(
        $pdo,
        'SELECT COALESCE(SUM(amount), 0) FROM payrollpayments WHERE payrollbillitemid = :i',
        ['i' => $lineId]
    )->fetchColumn());

    dt_q(
        $pdo,
        'UPDATE payrollbillitems SET amountpaid = :p, status = :s WHERE payrollbillitemid = :id',
        ['p' => dt_cents_text($paid), 's' => $paid >= $amountCents ? 'PAID' : 'UNPAID', 'id' => $lineId]
    );
}

/**
 * Names of employees by id (no lock).
 *
 * @param PDO       $pdo Connection.
 * @param list<int> $ids Employee ids.
 *
 * @return array<int,string> Map employee id => name.
 */
function dt_employee_names(PDO $pdo, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $rows = dt_q($pdo, "SELECT employeeid, name FROM employees WHERE employeeid IN ({$ph})", array_values($ids))->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[(int)$r['employeeid']] = (string)$r['name'];
    }

    return $out;
}

/**
 * Write an activity row (call before commit). Without login the user is unknown: userid is NULL.
 *
 * @param PDO    $pdo    Connection.
 * @param string $action Add | Update | Delete | Payment.
 * @param string $text   Description.
 *
 * @return void
 */
function dt_log_payroll(PDO $pdo, string $action, string $text): void
{
    dt_q(
        $pdo,
        'INSERT INTO activitylogs (userid, action, description, activitydate) VALUES (NULL, :a, :d, NOW())',
        ['a' => $action, 'd' => mb_substr($text, 0, 255)]
    );
}

/**
 * Read and validate the posted payment (used by pay, before the transaction).
 *
 * @return array{bill:int,account:int,memo:string,date:string} Clean fields.
 *
 * @throws DtFail On invalid input.
 */
function dt_read_pay_input(): array
{
    $bill    = max(0, (int)($_POST['payrollbillid'] ?? 0));
    $account = max(0, (int)($_POST['accountid'] ?? 0));
    $memo    = trim((string)($_POST['memo'] ?? ''));
    $date    = trim((string)($_POST['paymentdate'] ?? ''));

    if ($bill <= 0) {
        throw new DtFail('Bill not found.');
    }
    if ($account <= 0) {
        throw new DtFail('Select a valid active account.', false, 'accountid');
    }
    if ($memo === '') {
        throw new DtFail('Memo is required.', false, 'memo');
    }
    if (mb_strlen($memo) > DT_MAX_MEMO) {
        throw new DtFail('The memo is too long (maximum ' . DT_MAX_MEMO . ' characters).', false, 'memo');
    }
    if (!dt_valid_date($date)) {
        throw new DtFail('A valid payment date is required.', false, 'paymentdate');
    }
    if ($date > date('Y-m-d')) {
        throw new DtFail('Payment date cannot be in the future.', false, 'paymentdate');
    }

    return ['bill' => $bill, 'account' => $account, 'memo' => $memo, 'date' => $date];
}

/* ================================================================
 * 4. AJAX ACTIONS (one small function per action)
 * ============================================================== */

/**
 * Read the DataTables paging, search and order input (never trusted).
 *
 * @return array{draw:int,search:string,col:int,dir:string,limit:string} Clean input (limit is a ready LIMIT clause or '').
 */
function dt_read_table_input(): array
{
    $start  = max(0, (int)($_POST['start'] ?? 0));
    $length = (int)($_POST['length'] ?? 10);
    if ($length !== -1) {
        $length = max(1, min(DT_MAX_PAGE, $length));
    }
    $search = '';
    if (isset($_POST['search']) && is_array($_POST['search'])) {
        $search = trim((string)($_POST['search']['value'] ?? ''));
    }
    $col = -1;
    $dir = 'DESC';
    if (isset($_POST['order']) && is_array($_POST['order']) && isset($_POST['order'][0]) && is_array($_POST['order'][0])) {
        $col = (int)($_POST['order'][0]['column'] ?? -1);
        $dir = ((string)($_POST['order'][0]['dir'] ?? '') === 'asc') ? 'ASC' : 'DESC';
    }

    return [
        'draw'   => (int)($_POST['draw'] ?? 0),
        'search' => $search,
        'col'    => $col,
        'dir'    => $dir,
        'limit'  => $length > 0 ? ' LIMIT ' . $length . ' OFFSET ' . $start : '',
    ];
}

/**
 * List the bills (DataTables server-side), one row per bill.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_list(PDO $pdo): array
{
    $in = dt_read_table_input();

    $amtE    = 'COALESCE(t.totalamount, 0)';
    $paidE   = 'COALESCE(t.totalpaid, 0)';
    $balE    = 'COALESCE(t.totalbalance, 0)';
    $statusE = "CASE WHEN {$paidE} = 0 THEN 'UNPAID' WHEN COALESCE(t.unpaidcount, 0) = 0 THEN 'PAID' ELSE 'PARTIALLY PAID' END";

    // Whitelisted order columns (DataTables column index => SQL expression).
    $orderMap = [
        1 => 'b.payrollbillid', 2 => 'b.memo', 3 => 'b.billdate',
        4 => $amtE, 5 => $paidE, 6 => $balE, 7 => $statusE,
    ];
    $orderSql = isset($orderMap[$in['col']]) ? $orderMap[$in['col']] . ' ' . $in['dir'] . ', b.payrollbillid DESC' : 'b.payrollbillid DESC';

    $from = 'FROM payrollbills b
             LEFT JOIN (SELECT payrollbillid,
                               SUM(amount) AS totalamount,
                               SUM(amountpaid) AS totalpaid,
                               SUM(CASE WHEN status = \'PAID\' THEN 0 ELSE GREATEST(amount - amountpaid, 0) END) AS totalbalance,
                               COUNT(*) AS linecount,
                               SUM(CASE WHEN status <> \'PAID\' THEN 1 ELSE 0 END) AS unpaidcount
                          FROM payrollbillitems GROUP BY payrollbillid) t ON t.payrollbillid = b.payrollbillid';

    $recordsTotal = (int)dt_q($pdo, 'SELECT COUNT(*) FROM payrollbills')->fetchColumn();

    $where  = '';
    $params = [];
    if ($in['search'] !== '') {
        $like    = '%' . addcslashes($in['search'], '\\%_') . '%';
        $likeNum = '%' . addcslashes(str_replace(',', '', $in['search']), '\\%_') . '%';
        $where   = "WHERE (CAST(b.payrollbillid AS CHAR) LIKE :s1
                      OR b.memo LIKE :s2
                      OR CAST(b.billdate AS CHAR) LIKE :s3
                      OR CAST(ROUND({$amtE}, 2) AS CHAR) LIKE :s4
                      OR CAST(ROUND({$paidE}, 2) AS CHAR) LIKE :s5
                      OR CAST(ROUND({$balE}, 2) AS CHAR) LIKE :s6
                      OR ({$statusE}) LIKE :s7
                      OR EXISTS (SELECT 1 FROM payrollbillitems si JOIN employees se ON se.employeeid = si.employeeid
                                  WHERE si.payrollbillid = b.payrollbillid AND se.name LIKE :s8))";
        $params  = ['s1' => $like, 's2' => $like, 's3' => $like, 's4' => $likeNum, 's5' => $likeNum, 's6' => $likeNum, 's7' => $like, 's8' => $like];
    }

    $recordsFiltered = (int)dt_q($pdo, "SELECT COUNT(*) {$from} {$where}", $params)->fetchColumn();

    $rows = dt_q(
        $pdo,
        "SELECT b.payrollbillid, b.memo, b.billdate,
                {$amtE} AS totalamount, {$paidE} AS totalpaid, {$balE} AS totalbalance, {$statusE} AS billstatus,
                COALESCE(t.linecount, 0) AS linecount,
                (SELECT COUNT(*) FROM payrollpayments pp JOIN payrollbillitems pi ON pi.payrollbillitemid = pp.payrollbillitemid
                  WHERE pi.payrollbillid = b.payrollbillid) AS paymentcount
           {$from} {$where}
       ORDER BY {$orderSql}" . $in['limit'],
        $params
    )->fetchAll();

    $data = [];
    foreach ($rows as $r) {
        $balance = dt_cents($r['totalbalance']);
        $data[] = [
            'payrollbillid' => (int)$r['payrollbillid'],
            'memo'          => (string)($r['memo'] ?? ''),
            'billdate'      => (string)$r['billdate'],
            'amount'        => dt_cents($r['totalamount']) / 100,
            'amountpaid'    => dt_cents($r['totalpaid']) / 100,
            'balance'       => $balance / 100,
            'status'        => (string)$r['billstatus'],
            'employees'     => (int)$r['linecount'],
            'editable'      => (int)$r['paymentcount'] === 0,
            'payable'       => $balance > 0,
        ];
    }

    return [
        'success' => true, 'draw' => $in['draw'], 'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered, 'data' => $data,
    ];
}

/**
 * One bill with its lines and payments (read-only).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When the bill does not exist.
 */
function dt_act_bill(PDO $pdo): array
{
    $id   = max(0, (int)($_POST['payrollbillid'] ?? 0));
    $bill = dt_q($pdo, 'SELECT payrollbillid, memo, billdate FROM payrollbills WHERE payrollbillid = :id', ['id' => $id])->fetch();
    if (!is_array($bill)) {
        throw new DtFail('Bill not found.');
    }

    $lineRows = dt_q(
        $pdo,
        'SELECT i.payrollbillitemid, i.employeeid, e.name AS employeename, i.amount, i.amountpaid, i.status
           FROM payrollbillitems i JOIN employees e ON e.employeeid = i.employeeid
          WHERE i.payrollbillid = :b ORDER BY e.name, i.payrollbillitemid',
        ['b' => $id]
    )->fetchAll();

    $items = [];
    foreach ($lineRows as $r) {
        $amount = dt_cents($r['amount']);
        $paid   = dt_cents($r['amountpaid']);
        $status = (string)$r['status'];
        $items[] = [
            'employeeid'   => (int)$r['employeeid'],
            'employeename' => (string)($r['employeename'] ?? ''),
            'amount'       => $amount / 100,
            'amountpaid'   => $paid / 100,
            'balance'      => dt_line_balance($amount, $paid, $status) / 100,
            'status'       => dt_line_label($status, $paid),
        ];
    }

    $payRows = dt_q(
        $pdo,
        'SELECT p.payrollpaymentid, p.paymentdate, e.name AS employeename, p.description, a.code, a.accountname, p.amount
           FROM payrollpayments p
           JOIN payrollbillitems i ON i.payrollbillitemid = p.payrollbillitemid
           JOIN employees e ON e.employeeid = i.employeeid
           JOIN accounts a ON a.accountid = p.accountid
          WHERE i.payrollbillid = :b
       ORDER BY p.paymentdate DESC, p.payrollpaymentid DESC',
        ['b' => $id]
    )->fetchAll();

    $payments = [];
    foreach ($payRows as $r) {
        $payments[] = [
            'payrollpaymentid' => (int)$r['payrollpaymentid'],
            'paymentdate'      => (string)$r['paymentdate'],
            'employeename'     => (string)($r['employeename'] ?? ''),
            'description'      => (string)($r['description'] ?? ''),
            'account'          => (string)$r['code'] . ' - ' . (string)$r['accountname'],
            'amount'           => dt_cents($r['amount']) / 100,
        ];
    }

    return [
        'success'       => true,
        'payrollbillid' => (int)$bill['payrollbillid'],
        'memo'          => (string)$bill['memo'],
        'billdate'      => (string)$bill['billdate'],
        'editable'      => $payments === [],
        'items'         => $items,
        'payments'      => $payments,
    ];
}

/**
 * The lines of one bill with their balances, to fill the Pay modal (read-only).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When the bill does not exist.
 */
function dt_act_bill_pay(PDO $pdo): array
{
    $id   = max(0, (int)($_POST['payrollbillid'] ?? 0));
    $bill = dt_q($pdo, 'SELECT payrollbillid, memo, billdate FROM payrollbills WHERE payrollbillid = :id', ['id' => $id])->fetch();
    if (!is_array($bill)) {
        throw new DtFail('Bill not found.');
    }

    $rows = dt_q(
        $pdo,
        'SELECT i.employeeid, e.name AS employeename, i.amount, i.amountpaid, i.status
           FROM payrollbillitems i JOIN employees e ON e.employeeid = i.employeeid
          WHERE i.payrollbillid = :b ORDER BY e.name, i.payrollbillitemid',
        ['b' => $id]
    )->fetchAll();

    $items = [];
    foreach ($rows as $r) {
        $amount = dt_cents($r['amount']);
        $paid   = dt_cents($r['amountpaid']);
        $items[] = [
            'employeeid'   => (int)$r['employeeid'],
            'employeename' => (string)($r['employeename'] ?? ''),
            'amount'       => $amount / 100,
            'amountpaid'   => $paid / 100,
            'balance'      => dt_line_balance($amount, $paid, (string)$r['status']) / 100,
        ];
    }

    return [
        'success' => true, 'payrollbillid' => (int)$bill['payrollbillid'],
        'memo' => (string)$bill['memo'], 'billdate' => (string)$bill['billdate'], 'items' => $items,
    ];
}

/**
 * Add (payrollbillid = 0) or edit a bill (one transaction). Edit is allowed only while no line has a payment.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_save(PDO $pdo): array
{
    $d     = dt_read_bill_input();
    $lines = dt_read_lines();

    return dt_transaction(static function (PDO $pdo) use ($d, $lines): array {
        $total = dt_lines_cents($lines);
        $count = count($lines);

        if ($d['id'] > 0) {
            if (dt_lock_bill($pdo, $d['id']) === null) {
                throw new DtFail('Bill not found.');
            }
            $old = dt_lock_lines($pdo, $d['id']);
            dt_assert_unpaid($pdo, $d['id'], $old, 'A bill with payments cannot be edited.');
            dt_check_employees($pdo, $lines);

            dt_q(
                $pdo,
                'UPDATE payrollbills SET memo = :m, billdate = :d WHERE payrollbillid = :id',
                ['m' => $d['memo'], 'd' => $d['date'], 'id' => $d['id']]
            );
            dt_q($pdo, 'DELETE FROM payrollbillitems WHERE payrollbillid = :id', ['id' => $d['id']]);
            $billId = $d['id'];
        } else {
            dt_check_employees($pdo, $lines);
            dt_q($pdo, 'INSERT INTO payrollbills (memo, billdate) VALUES (:m, :d)', ['m' => $d['memo'], 'd' => $d['date']]);
            $billId = (int)$pdo->lastInsertId();
        }

        foreach ($lines as $l) {
            dt_q(
                $pdo,
                'INSERT INTO payrollbillitems (payrollbillid, employeeid, amount) VALUES (:b, :e, :a)',
                ['b' => $billId, 'e' => $l['employeeid'], 'a' => dt_cents_text($l['cents'])]
            );
        }

        $suffix = ': ' . dt_cents_money($total) . ' (' . dt_employees_text($count) . ') - ' . $d['memo'];
        if ($d['id'] > 0) {
            dt_log_payroll($pdo, 'Update', 'Payroll bill updated' . $suffix);

            return ['success' => true, 'message' => 'Payroll bill updated successfully.'];
        }
        dt_log_payroll($pdo, 'Add', 'Payroll bill added' . $suffix);

        return ['success' => true, 'message' => 'Payroll bill added successfully.'];
    });
}

/**
 * Delete a bill unless a line has a payment (one transaction); the lines go with it (ON DELETE CASCADE).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found or the bill has payments.
 */
function dt_act_delete(PDO $pdo): array
{
    $id = max(0, (int)($_POST['payrollbillid'] ?? 0));

    return dt_transaction(static function (PDO $pdo) use ($id): array {
        $bill = dt_lock_bill($pdo, $id);
        if ($bill === null) {
            throw new DtFail('Bill not found.');
        }
        $lines = dt_lock_lines($pdo, $id);
        dt_assert_unpaid($pdo, $id, $lines, 'A bill with payments cannot be deleted.');

        $total = 0;
        foreach ($lines as $l) {
            $total += dt_cents($l['amount']);
        }

        dt_q($pdo, 'DELETE FROM payrollbills WHERE payrollbillid = :id', ['id' => $id]);
        dt_log_payroll(
            $pdo,
            'Delete',
            'Payroll bill deleted: ' . dt_cents_money($total) . ' (' . dt_employees_text(count($lines)) . ') - ' . (string)$bill['memo']
        );

        return ['success' => true, 'message' => 'Payroll bill deleted successfully.'];
    });
}

/**
 * Pay one, several or all employees of a bill, partially or fully (one transaction).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_pay(PDO $pdo): array
{
    $d     = dt_read_pay_input();
    $lines = dt_read_lines();

    return dt_transaction(static function (PDO $pdo) use ($d, $lines): array {
        $bill = dt_lock_bill($pdo, $d['bill']);
        if ($bill === null) {
            throw new DtFail('Bill not found.');
        }
        $locked = dt_lock_lines($pdo, $d['bill']);
        if ($locked === []) {
            throw new DtFail('Bill not found.');
        }
        if ($d['date'] < (string)$bill['billdate']) {
            throw new DtFail('The payment date cannot be before the bill date.', false, 'paymentdate');
        }

        $byEmployee = [];
        foreach ($locked as $l) {
            $byEmployee[(int)$l['employeeid']] = $l;
        }
        $ids = [];
        foreach ($lines as $l) {
            if (!isset($byEmployee[$l['employeeid']])) {
                throw new DtFail('Select a valid employee.');
            }
            $ids[] = $l['employeeid'];
        }

        foreach ($lines as $l) {
            $line = $byEmployee[$l['employeeid']];
            dt_assert_reconciled($pdo, (int)$line['payrollbillitemid'], dt_cents($line['amountpaid']));
        }

        $names = dt_employee_names($pdo, $ids);
        foreach ($lines as $l) {
            $line    = $byEmployee[$l['employeeid']];
            $balance = dt_line_balance(dt_cents($line['amount']), dt_cents($line['amountpaid']), (string)$line['status']);
            if ($l['cents'] > $balance) {
                throw new DtFail('Amount for ' . ($names[$l['employeeid']] ?? '#' . $l['employeeid']) . ' cannot exceed the balance (' . dt_cents_money($balance) . ').');
            }
        }

        $account = dt_q(
            $pdo,
            "SELECT accountid FROM accounts
              WHERE accountid = :id AND accounttype = 'Assets' AND parentaccountid IS NOT NULL AND status = 'ON' LOCK IN SHARE MODE",
            ['id' => $d['account']]
        )->fetchColumn();
        if ($account === false) {
            throw new DtFail('Select a valid active account.', false, 'accountid');
        }

        foreach ($lines as $l) {
            $line = $byEmployee[$l['employeeid']];
            dt_q(
                $pdo,
                'INSERT INTO payrollpayments (payrollbillitemid, accountid, amount, paymentdate, description)
                 VALUES (:i, :a, :m, :d, :ds)',
                [
                    'i' => (int)$line['payrollbillitemid'], 'a' => $d['account'], 'm' => dt_cents_text($l['cents']),
                    'd' => $d['date'], 'ds' => $d['memo'],
                ]
            );
        }
        foreach ($lines as $l) {
            $line = $byEmployee[$l['employeeid']];
            dt_recompute_line($pdo, (int)$line['payrollbillitemid'], dt_cents($line['amount']));
        }

        dt_log_payroll(
            $pdo,
            'Payment',
            'Payroll payment added: ' . dt_cents_money(dt_lines_cents($lines)) . ' (' . dt_employees_text(count($lines)) . ') - ' . $d['memo']
        );

        return ['success' => true, 'message' => 'Payroll payment added successfully.'];
    });
}

/**
 * Delete one payment (always allowed). Lock order: line first, then payment.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When the payment does not exist.
 */
function dt_act_pay_delete(PDO $pdo): array
{
    $id = max(0, (int)($_POST['payrollpaymentid'] ?? 0));

    return dt_transaction(static function (PDO $pdo) use ($id): array {
        $peek = dt_q(
            $pdo,
            'SELECT payrollpaymentid, payrollbillitemid FROM payrollpayments WHERE payrollpaymentid = :id',
            ['id' => $id]
        )->fetch();
        if (!is_array($peek)) {
            throw new DtFail('Payment not found.');
        }
        $lineId = (int)$peek['payrollbillitemid'];

        $line = dt_q(
            $pdo,
            'SELECT payrollbillitemid, employeeid, amount, amountpaid, status
               FROM payrollbillitems WHERE payrollbillitemid = :i FOR UPDATE',
            ['i' => $lineId]
        )->fetch();
        if (!is_array($line)) {
            throw new DtFail('Payment not found.');
        }

        $pay = dt_q(
            $pdo,
            'SELECT payrollpaymentid, amount FROM payrollpayments WHERE payrollpaymentid = :id AND payrollbillitemid = :i FOR UPDATE',
            ['id' => $id, 'i' => $lineId]
        )->fetch();
        if (!is_array($pay)) {
            throw new DtFail('Payment not found.');
        }

        dt_assert_reconciled($pdo, $lineId, dt_cents($line['amountpaid']));

        dt_q($pdo, 'DELETE FROM payrollpayments WHERE payrollpaymentid = :id', ['id' => $id]);
        dt_recompute_line($pdo, $lineId, dt_cents($line['amount']));

        $names = dt_employee_names($pdo, [(int)$line['employeeid']]);
        dt_log_payroll(
            $pdo,
            'Delete',
            'Payroll payment deleted: #' . $id . ', ' . dt_cents_money(dt_cents($pay['amount'])) . ' - ' . ($names[(int)$line['employeeid']] ?? '')
        );

        return ['success' => true, 'message' => 'Payroll payment deleted successfully.'];
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
        'list' => 'dt_act_list', 'bill' => 'dt_act_bill', 'bill_pay' => 'dt_act_bill_pay',
        'save' => 'dt_act_save', 'delete' => 'dt_act_delete',
        'pay' => 'dt_act_pay', 'pay_delete' => 'dt_act_pay_delete',
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
        dt_log_error('[payrolls ' . $action . '] ' . dt_describe_throwable($e));
        if ($e->getCode() === '23000') {
            if ($action === 'pay') {
                dt_json(dt_fail('The bill, employee or account no longer exists.'));
            }
            if ($action === 'save') {
                dt_json(dt_fail('Select a valid employee.'));
            }
        }
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[payrolls ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    }
}

/* ================================================================
 * 5. HTML SHELL (same look as employees.php): CSS, head, topbar, sidebar, footer
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
    --info: #545b64;
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
input[type="checkbox"] { accent-color: var(--brand-primary); width: 16px; height: 16px; cursor: pointer; vertical-align: middle; }
input[type="checkbox"]:disabled { cursor: default; }
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
.btn:focus, .btn.focus { box-shadow: none; }
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
.form-control:disabled, .form-control[readonly] { background: var(--surface); }
.form-control.is-invalid { border-color: var(--danger); background-image: none; padding-right: 10px; }
.form-control.is-invalid:focus { box-shadow: 0 0 0 2px rgba(157, 29, 34, 0.3); }
.invalid-feedback { color: var(--danger); font-size: 13px; font-weight: 500; }
label { color: var(--heading); font-weight: 500; font-size: 14px; }
label .req { color: var(--danger); }
.text-muted { color: var(--muted) !important; }
.amt-input { width: 150px; margin-left: auto; text-align: right; height: 34px; }

/* ===== Badges ===== */
.badge { display: inline-block; padding: 2px 8px; font-size: 12px; font-weight: 600; line-height: 1.5; color: #ffffff; background: var(--muted); border-radius: 0; }
.badge-st { color: #ffffff; }
.badge-st-paid { background: #3aaa55; }
.badge-st-partial { background: #ffa426; }
.badge-st-unpaid { background: #CC0000; }

/* ===== Modals ===== */
.modal-content { border: 0; border-radius: 0; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25); }
.modal-header { background: var(--brand-primary); color: #ffffff; border-radius: 0; border-bottom: 0; padding: 10px 20px; }
.modal-header .modal-title { color: #ffffff; font-size: 16px; font-weight: 500; margin: 0; }
.modal-header .close { color: #ffffff; opacity: .9; text-shadow: none; }
.modal-header .close:hover { opacity: 1; color: #ffffff; }
.modal-footer { border-top: 1px solid var(--line); padding: 10px 20px; }
.modal-footer .total-box { margin-right: auto; font-weight: 600; color: var(--heading); }

/* ===== Line tables inside modals ===== */
.lines-wrap { max-height: 340px; overflow: auto; border: 1px solid var(--line); }
.lines-wrap table { margin: 0; font-size: 14px; }
.lines-wrap thead th { position: sticky; top: 0; z-index: 1; background: var(--surface); color: var(--heading); border-top: 0; border-bottom: 1px solid var(--line); white-space: nowrap; font-weight: 600; }
.lines-wrap td { vertical-align: middle; }
table.info-lines, table.pay-lines { font-size: 14px; }
table.info-lines thead th, table.pay-lines thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); white-space: nowrap; font-weight: 600; }
table.info-lines tfoot td { font-weight: 600; border-top: 2px solid var(--line); }
.info-grid { display: flex; flex-wrap: wrap; gap: 8px 32px; margin-bottom: 14px; }
.info-grid .lbl { font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; }
.info-grid .val { font-size: 15px; font-weight: 500; color: var(--heading); }
.px40 { width: 40px; } .px60 { width: 60px; } .px120 { width: 120px; } .px130 { width: 130px; }

/* ===== Tabs ===== */
.nav-tabs { border-bottom: 1px solid var(--line); margin-bottom: 14px; }
.nav-tabs .nav-link {
    padding: 6px 16px; font-size: 14px; font-weight: 600; border-radius: 0; border: 1px solid transparent;
    background: var(--surface); color: var(--brand-primary); margin-right: 2px;
}
.nav-tabs .nav-link:hover { color: var(--brand-accent-dark); border-color: transparent; }
.nav-tabs .nav-link.active { background: var(--brand-primary); color: #ffffff; border-color: var(--brand-primary); }
.nav-tabs .badge { background: var(--line); color: var(--heading); margin-left: 4px; }
.nav-tabs .nav-link.active .badge { background: #ffffff; color: var(--brand-primary); }

/* ===== DataTable ===== */
#payrollsTable { width: 100% !important; font-size: 14px; }
#payrollsTable thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); white-space: nowrap; font-weight: 600; }
#payrollsTable td { vertical-align: middle; }
#payrollsTable a.bill-link { color: var(--brand-primary); font-weight: 600; }
#payrollsTable a.bill-link:hover { color: var(--brand-accent-dark); text-decoration: underline; }
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
.page-item .page-link:focus { box-shadow: 0 0 0 2px rgba(22, 160, 74, 0.35); }
.page-item.active .page-link { background: var(--brand-primary); border-color: var(--brand-primary); color: #ffffff; }
.page-item.disabled .page-link { color: var(--muted); }
.page-link:hover { color: var(--brand-accent-dark); background: var(--surface); }
table.dataTable thead .sorting:before, table.dataTable thead .sorting:after { opacity: .3; }

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
.select2-container--bootstrap4 .select2-search--dropdown .select2-search__field:focus { border-color: var(--brand-accent); outline: 0; }
select.is-invalid + .select2-container--bootstrap4 .select2-selection { border-color: var(--danger); }
.modal .select2-container { z-index: 2000; }

/* ===== Toastr ===== */
#toast-container > div { border-radius: 0; opacity: .97; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18); font-family: inherit; font-size: 14px; }
#toast-container > div:hover { box-shadow: 0 8px 24px rgba(0, 0, 0, 0.28); opacity: 1; }
.toast-success { background-color: var(--brand-accent-dark); }
.toast-error { background-color: var(--danger); }
.toast-warning { background-color: var(--warning); }
.toast-info { background-color: var(--info); }
.toast-progress { background-color: rgba(255, 255, 255, 0.55); opacity: 1; }
.toast-close-button { color: #ffffff; text-shadow: none; }

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
                            <a href="<?= dt_e($subPage) ?>"<?= ($isAccounting && $subPage === 'payrolls.php') ? ' class="active" aria-current="page"' : '' ?>><span><?= dt_e($subLabel) ?></span></a>
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
 * 6. PAGE MARKUP
 * ============================================================== */

/**
 * Payroll bills list card.
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
                  <h2 class="header-title">Payroll Bills</h2>
                  <button type="button" class="btn btn-primary btn-sm" id="btnAdd"><i class="fas fa-plus mr-1" aria-hidden="true"></i> New Bill</button>
                </div>

                <table id="payrollsTable" class="table table-hover table-bordered">
                  <thead>
                    <tr>
                      <th class="px60">#</th>
                      <th>Bill</th>
                      <th>Memo</th>
                      <th>Bill Date</th>
                      <th class="text-right">Amount</th>
                      <th class="text-right">Amount Paid</th>
                      <th class="text-right">Balance</th>
                      <th>Status</th>
                      <th class="px130 text-center">Action</th>
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
 * Add / edit bill modal.
 *
 * @param string $today Today (Y-m-d).
 *
 * @return void
 */
function dt_modal_bill(string $today): void
{
    ?>
  <div class="modal fade" id="billModal" tabindex="-1" role="dialog" aria-labelledby="billModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
      <form class="modal-content" id="billForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="billModalTitle">New Payroll Bill</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="bId" value="0">
          <div class="form-row">
            <div class="form-group col-md-8">
              <label for="bMemo">Memo <span class="req">*</span></label>
              <input type="text" class="form-control" id="bMemo" maxlength="<?= DT_MAX_MEMO ?>" placeholder="November 2026 salaries" required aria-describedby="bMemoFb">
              <div class="invalid-feedback" id="bMemoFb">Memo is required.</div>
            </div>
            <div class="form-group col-md-4">
              <label for="bDate">Bill Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="bDate" value="<?= dt_e($today) ?>" required aria-describedby="bDateFb">
              <div class="invalid-feedback" id="bDateFb">A valid bill date is required.</div>
            </div>
          </div>

          <div class="lines-wrap">
            <table class="table table-sm table-hover mb-0">
              <thead>
                <tr>
                  <th class="px40"><input type="checkbox" id="bChkAll" aria-label="Select all employees"></th>
                  <th class="px60">No</th>
                  <th>Name</th>
                  <th>Contact</th>
                  <th>Address</th>
                  <th class="text-right px120">Amount</th>
                </tr>
              </thead>
              <tbody id="billLinesBody"></tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer">
          <div class="total-box">Total: <span id="bTotal">0.00</span></div>
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveBill" disabled><i class="fas fa-check mr-1" aria-hidden="true"></i> Charge</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Pay modal.
 *
 * @param string $today Today (Y-m-d).
 *
 * @return void
 */
function dt_modal_pay(string $today): void
{
    ?>
  <div class="modal fade" id="payModal" tabindex="-1" role="dialog" aria-labelledby="payModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
      <form class="modal-content" id="payForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="payModalTitle">Pay Payroll Bill</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="pBill" value="0">
          <div class="form-row">
            <div class="form-group col-md-4">
              <label for="pAccount">Account <span class="req">*</span></label>
              <select class="form-control" id="pAccount" required aria-describedby="pAccountFb">
                <option value="">Select account</option>
              </select>
              <div class="invalid-feedback" id="pAccountFb">Select a valid active account.</div>
            </div>
            <div class="form-group col-md-5">
              <label for="pMemo">Memo <span class="req">*</span></label>
              <input type="text" class="form-control" id="pMemo" maxlength="<?= DT_MAX_MEMO ?>" required aria-describedby="pMemoFb">
              <div class="invalid-feedback" id="pMemoFb">Memo is required.</div>
            </div>
            <div class="form-group col-md-3">
              <label for="pDate">Payment Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="pDate" value="<?= dt_e($today) ?>" max="<?= dt_e($today) ?>" required aria-describedby="pDateFb">
              <div class="invalid-feedback" id="pDateFb">A valid payment date is required.</div>
            </div>
          </div>

          <div class="lines-wrap">
            <table class="table table-sm table-hover mb-0">
              <thead>
                <tr>
                  <th class="px40"><input type="checkbox" id="pChkAll" aria-label="Select all employees"></th>
                  <th class="px60">No</th>
                  <th>Employee Name</th>
                  <th class="text-right px130">Balance</th>
                  <th class="text-right px120">Amount to pay</th>
                </tr>
              </thead>
              <tbody id="payLinesBody"></tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer">
          <div class="total-box">Total: <span id="pTotal">0.00</span></div>
          <button type="submit" class="btn btn-success btn-sm" id="btnSavePay" disabled><i class="fas fa-check mr-1" aria-hidden="true"></i> Create Payment</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * View modal (tabs INFO and PAYMENTS).
 *
 * @return void
 */
function dt_modal_view(): void
{
    ?>
  <div class="modal fade" id="viewModal" tabindex="-1" role="dialog" aria-labelledby="viewModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="viewModalTitle">Payroll Bill</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <ul class="nav nav-tabs" id="viewTabs" role="tablist">
            <li class="nav-item">
              <a class="nav-link active" id="vTabInfo" data-toggle="tab" href="#vPaneInfo" role="tab" aria-controls="vPaneInfo" aria-selected="true">INFO</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" id="vTabPay" data-toggle="tab" href="#vPanePay" role="tab" aria-controls="vPanePay" aria-selected="false">PAYMENTS <span class="badge" id="vPayCount">0</span></a>
            </li>
          </ul>
          <div class="tab-content">
            <div class="tab-pane fade show active" id="vPaneInfo" role="tabpanel" aria-labelledby="vTabInfo">
              <div class="info-grid">
                <div><div class="lbl">Bill</div><div class="val" id="vNumber"></div></div>
                <div><div class="lbl">Memo</div><div class="val" id="vMemo"></div></div>
                <div><div class="lbl">Date</div><div class="val" id="vDate"></div></div>
              </div>
              <div class="table-scroll">
                <table class="table table-sm table-bordered info-lines mb-0">
                  <thead>
                    <tr>
                      <th>Employee</th>
                      <th class="text-right">Amount</th>
                      <th class="text-right">Paid</th>
                      <th class="text-right">Due</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody id="vLines"></tbody>
                  <tfoot id="vLinesFoot"></tfoot>
                </table>
              </div>
            </div>
            <div class="tab-pane fade" id="vPanePay" role="tabpanel" aria-labelledby="vTabPay">
              <div class="table-scroll">
                <table class="table table-sm table-bordered pay-lines mb-0">
                  <thead>
                    <tr>
                      <th>Payment</th>
                      <th>Date</th>
                      <th>Employee</th>
                      <th>Memo</th>
                      <th>Account</th>
                      <th class="text-right">Amount</th>
                      <th class="px60 text-center">Action</th>
                    </tr>
                  </thead>
                  <tbody id="vPays"></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
<?php
}

/**
 * Delete bill modal.
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
          <h5 class="modal-title" id="deleteModalTitle">Delete Payroll Bill</h5>
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

/**
 * Delete payment confirm modal (opened over the view modal).
 *
 * @return void
 */
function dt_modal_delete_payment(): void
{
    ?>
  <div class="modal fade" id="payDelModal" tabindex="-1" role="dialog" aria-labelledby="payDelModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="payDelModalTitle">Delete Payment</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body" id="payDelText"></div>
        <div class="modal-footer">
          <button type="button" class="btn btn-danger btn-sm" id="btnConfirmPayDel"><i class="fas fa-trash mr-1" aria-hidden="true"></i> Delete</button>
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </div>
    </div>
  </div>
<?php
}

/* ================================================================
 * 7. REQUEST FLOW
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
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/** @var list<array{employeeid:int,name:string,contact:string,address:string,salary:string}> $employees All employees, ordered by name. */
$employees = [];
/** @var list<array{id:int,text:string}> $accounts Active Assets sub-accounts, natural sort by code. */
$accounts = [];

try {
    $pdoGet = dt_pdo();

    $empRows = dt_q($pdoGet, 'SELECT employeeid, name, contact, address, salary FROM employees ORDER BY name, employeeid')->fetchAll();
    foreach ($empRows as $r) {
        $employees[] = [
            'employeeid' => (int)$r['employeeid'],
            'name'       => (string)($r['name'] ?? ''),
            'contact'    => (string)($r['contact'] ?? ''),
            'address'    => (string)($r['address'] ?? ''),
            'salary'     => number_format((float)($r['salary'] ?? 0), 2, '.', ''),
        ];
    }

    $accRows = dt_q(
        $pdoGet,
        "SELECT accountid, code, accountname FROM accounts
          WHERE accounttype = 'Assets' AND parentaccountid IS NOT NULL AND status = 'ON'"
    )->fetchAll();
    usort($accRows, static fn(array $a, array $b): int => strnatcmp((string)$a['code'], (string)$b['code']));
    foreach ($accRows as $r) {
        $accounts[] = ['id' => (int)$r['accountid'], 'text' => (string)$r['code'] . ' ' . (string)$r['accountname']];
    }
} catch (Throwable $e) {
    dt_log_error('[payrolls page] ' . dt_describe_throwable($e));
}

/**
 * Sidebar: key, label, icon, page, submenu links (label, page). Same menu as pages/payments.php.
 * ALL pages live flat in pages/ (no sub-folders), so every link is just a file name.
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
    'csrfField' => $csrfField, 'docTitle' => 'Payrolls', 'heading' => 'Payrolls',
    'sub' => 'Charge salaries to employees and pay them, many employees in one bill.',
];

/* ---------- Render ---------- */

dt_render_open($page);
dt_view_list();
dt_render_close();
dt_modal_bill($today);
dt_modal_pay($today);
dt_modal_view();
dt_modal_delete();
dt_modal_delete_payment();

$cfg = [
    'csrf' => $csrf, 'self' => 'payrolls.php', 'today' => $today,
    'hasAccounts' => $accounts !== [], 'employees' => $employees, 'accounts' => $accounts,
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$n         = dt_n();

/* ================================================================
 * 8. JAVASCRIPT
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
    var EMPLOYEES = CFG.employees;
    var ACCOUNTS = CFG.accounts;
    var HAS_ACCOUNTS = CFG.hasAccounts;
    var MAX_AMOUNT = 1000000000;

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

    // DOM node with a text node (never HTML strings with data)
    function el(tag, cls, text) {
      var e = document.createElement(tag);
      if (cls) { e.className = cls; }
      if (text != null) { e.appendChild(document.createTextNode(String(text))); }
      return e;
    }

    function badgeClass(label) {
      return label === 'PAID' ? 'badge-st-paid' : (label === 'PARTIALLY PAID' ? 'badge-st-partial' : 'badge-st-unpaid');
    }

    function badgeNode(label) {
      return el('span', 'badge badge-st ' + badgeClass(label), label);
    }

    // Amount typed by the user -> cents, or null when not a valid amount (> 0 and below the limit)
    function textToCents(text) {
      text = $.trim(String(text == null ? '' : text));
      if (text === '') { return null; }
      var v = Number(text);
      if (isNaN(v) || !isFinite(v) || v <= 0 || v >= MAX_AMOUNT) { return null; }
      var c = Math.round(v * 100);
      return c > 0 ? c : null;
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

    var exportOpts = { columns: [1, 2, 3, 4, 5, 6, 7] };

    /* ---------- DataTable (server-side) ---------- */
    var table = $('#payrollsTable').DataTable({
      serverSide: true,
      autoWidth: false,
      searchDelay: 400,
      ajax: {
        url: URL_SELF,
        type: 'POST',
        data: function (d) { d.action = 'list'; d.csrf_token = CSRF; },
        dataSrc: 'data',
        error: function () { toastr.error('Could not load the list.'); }
      },
      columns: [
        { data: null, orderable: false, searchable: false, render: function (d, type, row, meta) { return meta.row + meta.settings._iDisplayStart + 1; } },
        { data: 'payrollbillid', render: function (d, type) {
            return type === 'display' ? '<a href="#" class="bill-link" title="View bill">' + esc(d) + '</a>' : d;
        } },
        { data: 'memo',     render: function (d) { return esc(d); } },
        { data: 'billdate', render: function (d) { return esc(d); } },
        { data: 'amount',     className: 'text-right', render: function (d, type) { return type === 'display' ? esc(fmt(d)) : d; } },
        { data: 'amountpaid', className: 'text-right', render: function (d, type) { return type === 'display' ? esc(fmt(d)) : d; } },
        { data: 'balance',    className: 'text-right', render: function (d, type) { return type === 'display' ? esc(fmt(d)) : d; } },
        { data: 'status', render: function (d, type) {
            return type === 'display' ? '<span class="badge badge-st ' + badgeClass(d) + '">' + esc(d) + '</span>' : d;
        } },
        {
          data: null, orderable: false, searchable: false, className: 'text-center',
          render: function (d, type, row) {
            var h = '<button type="button" class="btn btn-secondary act-btn btn-view" title="View" aria-label="View bill"><i class="fas fa-eye" aria-hidden="true"></i></button>';
            if (row.payable) {
              h += ' <button type="button" class="btn btn-success act-btn btn-pay" title="Pay" aria-label="Pay bill"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></button>';
            }
            if (row.editable) {
              h += ' <button type="button" class="btn btn-primary act-btn btn-edit" title="Edit" aria-label="Edit bill"><i class="fas fa-edit" aria-hidden="true"></i></button>' +
                   ' <button type="button" class="btn btn-danger act-btn btn-del" title="Delete" aria-label="Delete bill"><i class="fas fa-trash" aria-hidden="true"></i></button>';
            }
            return h;
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
        { extend: 'copyHtml5',  className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-copy"></i> Copy',        title: '', exportOptions: exportOpts, action: exportAll('copyHtml5') },
        { extend: 'csvHtml5',   className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-csv"></i> CSV',     filename: 'Payroll Bills', exportOptions: exportOpts, action: exportAll('csvHtml5') },
        { extend: 'excelHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-excel"></i> Excel', title: 'Payroll Bills', filename: 'Payroll Bills', exportOptions: exportOpts, action: exportAll('excelHtml5') },
        {
          extend: 'pdfHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-pdf"></i> PDF',
          title: 'Payroll Bills', filename: 'Payroll Bills', orientation: 'landscape', pageSize: 'A4',
          exportOptions: exportOpts, action: exportAll('pdfHtml5'),
          customize: function (doc) {
            var tbl = null;
            $.each(doc.content, function (i, c) { if (c.table) { tbl = c.table; } });
            if (!tbl) { return; }
            tbl.widths = [45, '*', 75, 85, 85, 85, 90];
            $.each(tbl.body, function (i, row) {
              $.each([3, 4, 5], function (j, k) { if (row[k]) { row[k].alignment = 'right'; } });
            });
          }
        },
        { extend: 'print',      className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-print"></i> Print',      title: 'Payroll Bills', exportOptions: exportOpts, action: exportAll('print') }
      ],
      language: { search: 'Search:', searchPlaceholder: 'Search payroll...', emptyTable: 'No payroll bills found', zeroRecords: 'No payroll bills found' }
    });

    function rowData(el0) { return table.row($(el0).closest('tr')).data(); }

    /* ---------- Invalid-field marking: one FIELDS map per modal (feedback element id = input id + "Fb") ---------- */
    var BILL_FIELDS = { memo: '#bMemo', billdate: '#bDate' };
    var PAY_FIELDS  = { accountid: '#pAccount', memo: '#pMemo', paymentdate: '#pDate' };

    function captureDefaults(fields) {
      var out = {};
      $.each(fields, function (key, sel) { out[key] = $(sel + 'Fb').text(); });
      return out;
    }
    var BILL_FB = captureDefaults(BILL_FIELDS);
    var PAY_FB  = captureDefaults(PAY_FIELDS);

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

    function focusField(sel) {
      if (sel === '#pAccount') { $('#pAccount').select2('open'); } else { $(sel).trigger('focus'); }
    }

    $('#bMemo, #bDate').on('input change', function () { $(this).removeClass('is-invalid'); });
    $('#pAccount, #pMemo, #pDate').on('input change', function () { $(this).removeClass('is-invalid'); });

    /* ================================================================
     * BILL MODAL (rows built once with DOM nodes)
     * ============================================================== */
    var billRows = {};      // employee id -> { chk, amt, def }
    var billBusy = false;
    var billReq  = 0;       // discards late answers when editing

    function defaultAmount(emp) {
      var s = parseFloat(emp.salary);
      return (!isNaN(s) && s > 0) ? s.toFixed(2) : '';
    }

    (function buildBillRows() {
      var tb = document.getElementById('billLinesBody');
      $.each(EMPLOYEES, function (i, emp) {
        var tr   = document.createElement('tr');
        var tdC  = document.createElement('td');
        var chk  = document.createElement('input');
        chk.type = 'checkbox';
        chk.setAttribute('aria-label', 'Select ' + emp.name);
        tdC.appendChild(chk);
        var tdA  = document.createElement('td');
        tdA.className = 'text-right';
        var amt  = document.createElement('input');
        amt.type = 'number'; amt.step = '0.01'; amt.min = '0.01';
        amt.className = 'form-control amt-input';
        amt.disabled = true;
        amt.setAttribute('aria-label', 'Amount for ' + emp.name);
        tdA.appendChild(amt);
        tr.appendChild(tdC);
        tr.appendChild(el('td', null, i + 1));
        tr.appendChild(el('td', null, emp.name));
        tr.appendChild(el('td', null, emp.contact));
        tr.appendChild(el('td', null, emp.address));
        tr.appendChild(tdA);
        tb.appendChild(tr);
        billRows[emp.employeeid] = { chk: chk, amt: amt, def: defaultAmount(emp) };
        amt.value = billRows[emp.employeeid].def;

        chk.addEventListener('change', function () { setBillRow(emp.employeeid, chk.checked); updateBill(); });
        amt.addEventListener('input', function () { amt.classList.remove('is-invalid'); updateBill(); });
      });
    })();

    function setBillRow(eid, checked) {
      var r = billRows[eid];
      r.chk.checked = checked;
      r.amt.disabled = !checked;
      if (!checked) { r.amt.value = r.def; r.amt.classList.remove('is-invalid'); }
    }

    function updateBill() {
      var count = 0, total = 0, all = EMPLOYEES.length;
      $.each(billRows, function (eid, r) {
        if (r.chk.checked) {
          count++;
          var c = textToCents(r.amt.value);
          if (c !== null) { total += c; }
        }
      });
      var chkAll = document.getElementById('bChkAll');
      chkAll.checked = (all > 0 && count === all);
      chkAll.indeterminate = (count > 0 && count < all);
      $('#bTotal').text(fmt(total / 100));
      $('#btnSaveBill').prop('disabled', billBusy || count === 0);
    }

    $('#bChkAll').on('change', function () {
      var on = this.checked;
      $.each(billRows, function (eid) { setBillRow(eid, on); });
      updateBill();
    });

    function resetBillForm() {
      clearInvalid(BILL_FIELDS, BILL_FB);
      $('#bId').val(0);
      $('#bMemo').val('');
      $('#bDate').val(TODAY);
      $.each(billRows, function (eid) { setBillRow(eid, false); });
      updateBill();
    }

    function showBillModal(title) {
      $('#billModalTitle').text(title);
      updateBill();
      $('#billModal').modal('show');
    }

    $('#btnAdd').on('click', function () {
      if (!EMPLOYEES.length) { toastr.warning('Add an employee first.'); return; }
      resetBillForm();
      showBillModal('New Payroll Bill');
    });

    $('#payrollsTable tbody').on('click', '.btn-edit', function () {
      var d = rowData(this);
      var req = ++billReq;
      api({ action: 'bill', payrollbillid: d.payrollbillid }).done(function (res) {
        if (req !== billReq) { return; }
        if (!res.success) { toastr.error(res.message); return; }
        if (!res.editable) {
          toastr.warning('A bill with payments cannot be edited.');
          table.ajax.reload(null, false);
          return;
        }
        resetBillForm();
        $('#bId').val(res.payrollbillid);
        $('#bMemo').val(res.memo);
        $('#bDate').val(res.billdate);
        $.each(res.items, function (i, it) {
          var r = billRows[it.employeeid];
          if (!r) { return; }
          setBillRow(it.employeeid, true);
          r.amt.value = Number(it.amount).toFixed(2);
        });
        showBillModal('Edit Payroll Bill');
      });
    });

    $('#billModal').on('shown.bs.modal', function () { $('#bMemo').trigger('focus'); });

    $('#billForm').on('submit', function (e) {
      e.preventDefault();
      clearInvalid(BILL_FIELDS, BILL_FB);

      var memo = $.trim($('#bMemo').val());
      var date = $('#bDate').val();
      if (memo === '') { focusField(markInvalid(BILL_FIELDS, 'memo')); return; }
      if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) { focusField(markInvalid(BILL_FIELDS, 'billdate')); return; }

      var items = [];
      var bad = null;
      $.each(EMPLOYEES, function (i, emp) {
        var r = billRows[emp.employeeid];
        if (!r.chk.checked) { return; }
        var c = textToCents(r.amt.value);
        if (c === null) { if (!bad) { bad = r.amt; } return; }
        items.push({ employeeid: emp.employeeid, amount: (c / 100).toFixed(2) });
      });
      if (bad) {
        $(bad).addClass('is-invalid').trigger('focus');
        toastr.error('Enter a valid amount for every employee.');
        return;
      }
      if (!items.length) { toastr.error('Select at least one employee.'); return; }

      billBusy = true;
      updateBill();
      api({ action: 'save', payrollbillid: $('#bId').val(), memo: memo, billdate: date, items: JSON.stringify(items) }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#billModal').modal('hide');
          table.ajax.reload(null, false);
          return;
        }
        if (res.field && BILL_FIELDS[res.field]) {
          focusField(markInvalid(BILL_FIELDS, res.field, res.message));
        }
        toastr.error(res.message);
      }).always(function () { billBusy = false; updateBill(); });
    });

    /* ================================================================
     * PAY MODAL
     * ============================================================== */
    var payRows = [];       // [{ eid, balanceCents, chk, amt }]
    var payBusy = false;
    var payReq  = 0;
    var payBillDate = '';

    $('#pAccount').select2({ theme: 'bootstrap4', width: '100%', dropdownParent: $('#payModal') });
    (function fillAccounts() {
      var s = document.getElementById('pAccount');
      $.each(ACCOUNTS, function (i, a) {
        var o = document.createElement('option');
        o.value = String(a.id);
        o.appendChild(document.createTextNode(a.text));
        s.appendChild(o);
      });
    })();

    function updatePay() {
      var count = 0, total = 0;
      $.each(payRows, function (i, r) {
        if (r.chk.checked) {
          count++;
          var c = textToCents(r.amt.value);
          if (c !== null) { total += c; }
        }
      });
      var chkAll = document.getElementById('pChkAll');
      chkAll.checked = (payRows.length > 0 && count === payRows.length);
      chkAll.indeterminate = (count > 0 && count < payRows.length);
      $('#pTotal').text(fmt(total / 100));
      $('#btnSavePay').prop('disabled', payBusy || count === 0);
    }

    function setPayRow(r, checked) {
      r.chk.checked = checked;
      r.amt.disabled = !checked;
      r.amt.value = (r.balanceCents / 100).toFixed(2);   // unchecking restores the full balance
      r.amt.classList.remove('is-invalid');
    }

    function buildPayRows(items) {
      var tb = document.getElementById('payLinesBody');
      while (tb.firstChild) { tb.removeChild(tb.firstChild); }
      payRows = [];
      var no = 0;
      $.each(items, function (i, it) {
        var bal = Math.round(Number(it.balance) * 100);
        if (bal <= 0) { return; }
        no++;
        var tr  = document.createElement('tr');
        var tdC = document.createElement('td');
        var chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.setAttribute('aria-label', 'Select ' + it.employeename);
        tdC.appendChild(chk);
        var tdA = document.createElement('td');
        tdA.className = 'text-right';
        var amt = document.createElement('input');
        amt.type = 'number'; amt.step = '0.01'; amt.min = '0.01'; amt.max = (bal / 100).toFixed(2);
        amt.className = 'form-control amt-input';
        amt.setAttribute('aria-label', 'Amount to pay to ' + it.employeename);
        tdA.appendChild(amt);
        var tdB = el('td', 'text-right', fmt(bal / 100));
        tr.appendChild(tdC);
        tr.appendChild(el('td', null, no));
        tr.appendChild(el('td', null, it.employeename));
        tr.appendChild(tdB);
        tr.appendChild(tdA);
        tb.appendChild(tr);

        var row = { eid: it.employeeid, name: it.employeename, balanceCents: bal, chk: chk, amt: amt };
        setPayRow(row, false);
        chk.addEventListener('change', function () { setPayRow(row, chk.checked); updatePay(); });
        amt.addEventListener('input', function () { amt.classList.remove('is-invalid'); updatePay(); });
        payRows.push(row);
      });
    }

    $('#pChkAll').on('change', function () {
      var on = this.checked;
      $.each(payRows, function (i, r) { setPayRow(r, on); });
      updatePay();
    });

    $('#payrollsTable tbody').on('click', '.btn-pay', function () {
      if (!HAS_ACCOUNTS) { toastr.warning('Add an active asset account in Chart of Accounts first.'); return; }
      var d = rowData(this);
      var req = ++payReq;
      api({ action: 'bill_pay', payrollbillid: d.payrollbillid }).done(function (res) {
        if (req !== payReq) { return; }
        if (!res.success) { toastr.error(res.message); return; }

        clearInvalid(PAY_FIELDS, PAY_FB);
        payBillDate = res.billdate;
        $('#pBill').val(res.payrollbillid);
        $('#pAccount').val('').trigger('change.select2');
        $('#pMemo').val(res.memo);
        $('#pDate').val(TODAY);
        buildPayRows(res.items);
        if (!payRows.length) {
          toastr.warning('This bill has no balance to pay.');
          table.ajax.reload(null, false);
          return;
        }
        $('#payModalTitle').text('Pay Payroll Bill #' + res.payrollbillid);
        updatePay();
        $('#payModal').modal('show');
      });
    });

    $('#payForm').on('submit', function (e) {
      e.preventDefault();
      clearInvalid(PAY_FIELDS, PAY_FB);

      var account = $('#pAccount').val();
      var memo    = $.trim($('#pMemo').val());
      var date    = $('#pDate').val();

      if (!account) { focusField(markInvalid(PAY_FIELDS, 'accountid')); return; }
      if (memo === '') { focusField(markInvalid(PAY_FIELDS, 'memo')); return; }
      if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) { focusField(markInvalid(PAY_FIELDS, 'paymentdate')); return; }
      if (date > TODAY) { focusField(markInvalid(PAY_FIELDS, 'paymentdate', 'Payment date cannot be in the future.')); return; }
      if (payBillDate && date < payBillDate) { focusField(markInvalid(PAY_FIELDS, 'paymentdate', 'The payment date cannot be before the bill date.')); return; }

      var items = [];
      var bad = null, badMsg = '';
      $.each(payRows, function (i, r) {
        if (!r.chk.checked) { return; }
        var c = textToCents(r.amt.value);
        if (c === null) { if (!bad) { bad = r.amt; badMsg = 'Enter a valid amount for every employee.'; } return; }
        if (c > r.balanceCents) {
          if (!bad) { bad = r.amt; badMsg = 'Amount for ' + r.name + ' cannot exceed the balance (' + fmt(r.balanceCents / 100) + ').'; }
          return;
        }
        items.push({ employeeid: r.eid, amount: (c / 100).toFixed(2) });
      });
      if (bad) { $(bad).addClass('is-invalid').trigger('focus'); toastr.error(badMsg); return; }
      if (!items.length) { toastr.error('Select at least one employee.'); return; }

      payBusy = true;
      updatePay();
      api({
        action: 'pay', payrollbillid: $('#pBill').val(), accountid: account, memo: memo,
        paymentdate: date, items: JSON.stringify(items)
      }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#payModal').modal('hide');
          table.ajax.reload(null, false);
          return;
        }
        if (res.field && PAY_FIELDS[res.field]) {
          focusField(markInvalid(PAY_FIELDS, res.field, res.message));
        }
        toastr.error(res.message);
      }).always(function () { payBusy = false; updatePay(); });
    });

    /* ================================================================
     * VIEW MODAL (INFO and PAYMENTS tabs)
     * ============================================================== */
    var viewReq = 0;
    var viewId  = 0;
    var payDelId = 0;

    function clearNode(n) { while (n.firstChild) { n.removeChild(n.firstChild); } }

    function fillView(res) {
      viewId = res.payrollbillid;
      $('#viewModalTitle').text('Payroll Bill #' + res.payrollbillid);
      $('#vNumber').text('#' + res.payrollbillid);
      $('#vMemo').text(res.memo);
      $('#vDate').text(res.billdate);

      var tb = document.getElementById('vLines');
      clearNode(tb);
      var tAmount = 0, tPaid = 0, tDue = 0;
      $.each(res.items, function (i, it) {
        var tr = document.createElement('tr');
        tr.appendChild(el('td', null, it.employeename));
        tr.appendChild(el('td', 'text-right', fmt(it.amount)));
        tr.appendChild(el('td', 'text-right', fmt(it.amountpaid)));
        tr.appendChild(el('td', 'text-right', fmt(it.balance)));
        var td = document.createElement('td');
        td.appendChild(badgeNode(it.status));
        tr.appendChild(td);
        tb.appendChild(tr);
        tAmount += Math.round(it.amount * 100);
        tPaid   += Math.round(it.amountpaid * 100);
        tDue    += Math.round(it.balance * 100);
      });
      var tf = document.getElementById('vLinesFoot');
      clearNode(tf);
      var ft = document.createElement('tr');
      ft.appendChild(el('td', null, 'Total'));
      ft.appendChild(el('td', 'text-right', fmt(tAmount / 100)));
      ft.appendChild(el('td', 'text-right', fmt(tPaid / 100)));
      ft.appendChild(el('td', 'text-right', fmt(tDue / 100)));
      ft.appendChild(el('td', null, ''));
      tf.appendChild(ft);

      $('#vPayCount').text(res.payments.length);
      var pb = document.getElementById('vPays');
      clearNode(pb);
      if (!res.payments.length) {
        var er = document.createElement('tr');
        var ec = el('td', 'text-center text-muted', 'No payments yet');
        ec.colSpan = 7;
        er.appendChild(ec);
        pb.appendChild(er);
      }
      $.each(res.payments, function (i, p) {
        var tr = document.createElement('tr');
        tr.appendChild(el('td', null, '#' + p.payrollpaymentid));
        tr.appendChild(el('td', null, p.paymentdate));
        tr.appendChild(el('td', null, p.employeename));
        tr.appendChild(el('td', null, p.description));
        tr.appendChild(el('td', null, p.account));
        tr.appendChild(el('td', 'text-right', fmt(p.amount)));
        var td  = el('td', 'text-center');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-danger act-btn';
        btn.title = 'Delete payment';
        btn.setAttribute('aria-label', 'Delete payment #' + p.payrollpaymentid);
        var ic = document.createElement('i');
        ic.className = 'fas fa-trash';
        ic.setAttribute('aria-hidden', 'true');
        btn.appendChild(ic);
        btn.addEventListener('click', function () { askDeletePayment(p); });
        td.appendChild(btn);
        tr.appendChild(td);
        pb.appendChild(tr);
      });
    }

    // Open (or reload) the view modal; tab = 'info' | 'pay'
    function openView(id, tab) {
      var req = ++viewReq;
      api({ action: 'bill', payrollbillid: id }).done(function (res) {
        if (req !== viewReq) { return; }
        if (!res.success) { toastr.error(res.message); return; }
        fillView(res);
        $(tab === 'pay' ? '#vTabPay' : '#vTabInfo').tab('show');
        if (!$('#viewModal').hasClass('show')) { $('#viewModal').modal('show'); }
      });
    }

    $('#payrollsTable tbody').on('click', '.bill-link', function (e) { e.preventDefault(); openView(rowData(this).payrollbillid, 'info'); });
    $('#payrollsTable tbody').on('click', '.btn-view', function () { openView(rowData(this).payrollbillid, 'info'); });

    /* ---------- Delete a payment (confirm modal over the view modal) ---------- */
    function askDeletePayment(p) {
      payDelId = p.payrollpaymentid;
      $('#payDelText').empty().append(
        document.createTextNode('Delete payment '),
        $('<b>').text('#' + p.payrollpaymentid),
        document.createTextNode(' (' + fmt(p.amount) + ', ' + (p.employeename || '') + ')? This cannot be undone.')
      );
      $('#payDelModal').modal('show');
    }

    // Closing the confirm modal must keep the scroll lock of the view modal
    $('#payDelModal').on('hidden.bs.modal', function () {
      if ($('#viewModal').hasClass('show')) { $('body').addClass('modal-open'); }
    });

    $('#btnConfirmPayDel').on('click', function () {
      var $btn = $(this).prop('disabled', true);
      api({ action: 'pay_delete', payrollpaymentid: payDelId }).done(function (res) {
        $('#payDelModal').modal('hide');
        if (res.success) {
          toastr.success(res.message);
        } else {
          toastr.error(res.message);
        }
        table.ajax.reload(null, false);
        openView(viewId, 'pay');
      }).fail(function () {
        $('#payDelModal').modal('hide');
      }).always(function () { $btn.prop('disabled', false); });
    });

    /* ================================================================
     * DELETE BILL
     * ============================================================== */
    var deleteId = 0;

    $('#payrollsTable tbody').on('click', '.btn-del', function () {
      var d = rowData(this);
      deleteId = d.payrollbillid;
      $('#delText').empty().append(
        document.createTextNode('Delete payroll bill '),
        $('<b>').text('#' + d.payrollbillid),
        document.createTextNode(' (' + fmt(d.amount) + ', ' + (d.employees === 1 ? '1 employee' : d.employees + ' employees') + ')? This cannot be undone.')
      );
      $('#deleteModal').modal('show');
    });

    $('#btnConfirmDelete').on('click', function () {
      var $btn = $(this).prop('disabled', true);
      api({ action: 'delete', payrollbillid: deleteId }).done(function (res) {
        $('#deleteModal').modal('hide');
        if (res.success) {
          toastr.success(res.message);
        } else if (res.blocked) {
          toastr.warning(res.message);
        } else {
          toastr.error(res.message);
        }
        table.ajax.reload(null, false);
      }).fail(function () {
        $('#deleteModal').modal('hide');
      }).always(function () { $btn.prop('disabled', false); });
    });
</script>
</body>
</html>