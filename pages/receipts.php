<?php

declare(strict_types=1);

/**
 * pages/receipts.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: Receipts (sidebar > Accounting > Receipts).
 *
 * Money received from CUSTOMERS only (supplier money goes in Payments, customer deposits in their own page).
 * One page, four AJAX actions (POST, JSON): list (server-side DataTable), invoices, save, delete.
 * Customers and receiving accounts are rendered by PHP on GET.
 *
 * Automatic receipts: a receipt whose note is exactly DT_AUTO_NOTE was written by the Sales page together
 * with an invoice. It is shown in the list but it is read-only here (page and server).
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call the auth helpers of ../db.php right after the
 * "NO LOGIN" marker in the request flow below (and pass the user id to dt_log_receipt()).
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
const DT_MAX_PAGE   = 1000;

/** Note written by the Sales page with an invoice: such receipts are read-only here. */
const DT_AUTO_NOTE = 'Received with invoice';

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

/* ================================================================
 * 3. SHARED BUSINESS RULES (read and validate, load and lock, balance, invoice sync, log)
 * ============================================================== */

/**
 * Read and validate the posted receipt fields (used by save, before the transaction).
 *
 * @return array{id:int,customer:int,invoice:int,account:int,amount:float,date:string,note:?string} Clean fields (empty note becomes null).
 *
 * @throws DtFail On invalid input.
 */
function dt_read_receipt_input(): array
{
    $id        = max(0, (int)($_POST['receiptid'] ?? 0));
    $customer  = (int)($_POST['customerid'] ?? 0);
    $invoice   = max(0, (int)($_POST['invoiceid'] ?? 0));
    $account   = (int)($_POST['accountid'] ?? 0);
    $rawAmount = trim((string)($_POST['amount'] ?? ''));
    $date      = trim((string)($_POST['receiptdate'] ?? ''));
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
        throw new DtFail('A valid date is required.', false, 'receiptdate');
    }
    if (mb_strlen($note) > DT_MAX_NOTE) {
        throw new DtFail('The note is too long (maximum ' . DT_MAX_NOTE . ' characters).', false, 'note');
    }
    if ($note !== '' && mb_strtolower($note) === mb_strtolower(DT_AUTO_NOTE)) {
        throw new DtFail('This note is reserved.', false, 'note');
    }

    return [
        'id' => $id, 'customer' => $customer, 'invoice' => $invoice, 'account' => $account,
        'amount' => $amount, 'date' => $date, 'note' => $note === '' ? null : $note,
    ];
}

/**
 * Name of a customer (Walk-in included).
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
 * Load and lock (FOR UPDATE) a receipt. Call inside a transaction.
 *
 * @param PDO $pdo       Connection.
 * @param int $receiptId Receipt id.
 *
 * @return array<string,mixed> Receipt row (receiptid, customerid, invoiceid, amount, note).
 *
 * @throws DtFail When not found.
 */
function dt_lock_receipt(PDO $pdo, int $receiptId): array
{
    $row = dt_q(
        $pdo,
        'SELECT receiptid, customerid, invoiceid, amount, note FROM receipts WHERE receiptid = :id FOR UPDATE',
        ['id' => $receiptId]
    )->fetch();
    if (!is_array($row)) {
        throw new DtFail('Receipt not found.');
    }

    return $row;
}

/**
 * Whether a receipt row was written by the Sales page together with an invoice.
 *
 * @param array<string,mixed> $row Receipt row with a note.
 *
 * @return bool True for an automatic receipt.
 */
function dt_is_auto(array $row): bool
{
    return (string)($row['note'] ?? '') === DT_AUTO_NOTE;
}

/**
 * Refuse a change to an automatic receipt.
 *
 * @param array<string,mixed> $row Locked receipt row.
 *
 * @return void
 *
 * @throws DtFail When the receipt is automatic.
 */
function dt_assert_manual(array $row): void
{
    if (dt_is_auto($row)) {
        throw new DtFail('This receipt was written with the invoice. Change it from the sale.');
    }
}

/**
 * Load and lock (FOR UPDATE) an invoice. Call inside a transaction.
 *
 * @param PDO $pdo       Connection.
 * @param int $invoiceId Invoice id.
 *
 * @return array<string,mixed>|null Row (invoiceid, customerid, invoicedate, totalamount), or null when missing.
 */
function dt_lock_invoice(PDO $pdo, int $invoiceId): ?array
{
    $row = dt_q(
        $pdo,
        'SELECT invoiceid, customerid, invoicedate, totalamount FROM invoices WHERE invoiceid = :id FOR UPDATE',
        ['id' => $invoiceId]
    )->fetch();

    return is_array($row) ? $row : null;
}

/**
 * Sum of the receipts of an invoice.
 *
 * @param PDO $pdo       Connection.
 * @param int $invoiceId Invoice id.
 * @param int $exclude   Receipt id left out of the sum (0 = none).
 *
 * @return float Received total.
 */
function dt_received_total(PDO $pdo, int $invoiceId, int $exclude = 0): float
{
    return round((float)dt_q(
        $pdo,
        'SELECT COALESCE(SUM(amount), 0) FROM receipts WHERE invoiceid = :i AND receiptid <> :r',
        ['i' => $invoiceId, 'r' => $exclude]
    )->fetchColumn(), 2);
}

/* ---------------------------------------------------------------
 * INVOICE PAID SYNC (start): remove this function and its calls in
 * dt_act_save() and dt_act_delete() if the invoices table must not be touched.
 * ------------------------------------------------------------ */

/**
 * Re-sync the paid amount of an invoice from its receipts (the invoice must already be locked by the caller).
 * paidamount = LEAST(totalamount, SUM(receipts)); receivedinaccountid is filled from the latest receipt
 * when the new paid amount is above 0 and the column is empty; when the sum is 0 it is kept as it is.
 *
 * @param PDO $pdo       Connection.
 * @param int $invoiceId Invoice id.
 *
 * @return void
 */
function dt_sync_invoice(PDO $pdo, int $invoiceId): void
{
    $sum = dt_f2(dt_received_total($pdo, $invoiceId));
    dt_q(
        $pdo,
        'UPDATE invoices
            SET paidamount = LEAST(totalamount, :s1),
                receivedinaccountid = CASE
                    WHEN LEAST(totalamount, :s2) > 0 AND receivedinaccountid IS NULL
                        THEN (SELECT r.accountid FROM receipts r WHERE r.invoiceid = :i1 ORDER BY r.receiptid DESC LIMIT 1)
                    ELSE receivedinaccountid
                END
          WHERE invoiceid = :i2',
        ['s1' => $sum, 's2' => $sum, 'i1' => $invoiceId, 'i2' => $invoiceId]
    );
}

/* ---------------------------------------------------------------
 * INVOICE PAID SYNC (end)
 * ------------------------------------------------------------ */

/**
 * Activity text of a receipt.
 *
 * @param string $verb      added | updated | deleted.
 * @param int    $receiptId Receipt id.
 * @param int    $invoiceId Invoice id (0 = none).
 * @param string $customer  Customer name.
 *
 * @return string Description.
 */
function dt_receipt_text(string $verb, int $receiptId, int $invoiceId, string $customer): string
{
    return 'Receipt ' . $verb . ': #' . $receiptId . ($invoiceId > 0 ? ' for invoice #' . $invoiceId : '') . ' ' . $customer;
}

/**
 * Write a receipt activity row (call before commit). Without login the user is unknown: userid is NULL ("System" on the dashboard).
 *
 * @param PDO    $pdo    Connection.
 * @param string $action Add | Update | Delete.
 * @param string $text   Description.
 *
 * @return void
 */
function dt_log_receipt(PDO $pdo, string $action, string $text): void
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
 * List receipts (DataTables server-side).
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
        1 => 'r.receiptid', 2 => 'c.name', 3 => 'r.invoiceid',
        4 => 'a.accountname', 5 => 'r.amount', 6 => 'r.receiptdate',
    ];
    $colIdx = 1;
    $dir    = 'DESC';
    if (isset($_POST['order'][0]) && is_array($_POST['order'][0])) {
        $colIdx = (int)($_POST['order'][0]['column'] ?? 1);
        $dir    = ((string)($_POST['order'][0]['dir'] ?? '') === 'asc') ? 'ASC' : 'DESC';
    }
    $orderCol = $orderMap[$colIdx] ?? 'r.receiptid';

    $from = 'FROM receipts r
             JOIN customers c ON c.customerid = r.customerid
             LEFT JOIN accounts a ON a.accountid = r.accountid';

    $recordsTotal = (int)dt_q($pdo, 'SELECT COUNT(*) FROM receipts')->fetchColumn();

    $where  = '';
    $params = [];
    if ($search !== '') {
        $like    = '%' . addcslashes($search, '\\%_') . '%';
        $likeNum = '%' . addcslashes(str_replace(',', '', $search), '\\%_') . '%';
        $where   = 'WHERE (CAST(r.receiptid AS CHAR) LIKE :s1
                      OR c.name LIKE :s2
                      OR CAST(r.invoiceid AS CHAR) LIKE :s3
                      OR CONCAT(COALESCE(a.code, \'\'), \' \', COALESCE(a.accountname, \'\')) LIKE :s4
                      OR CAST(ROUND(r.amount, 2) AS CHAR) LIKE :s5
                      OR CAST(r.receiptdate AS CHAR) LIKE :s6
                      OR r.note LIKE :s7)';
        $params  = ['s1' => $like, 's2' => $like, 's3' => $like, 's4' => $like, 's5' => $likeNum, 's6' => $like, 's7' => $like];
    }

    $recordsFiltered = (int)dt_q($pdo, "SELECT COUNT(*) {$from} {$where}", $params)->fetchColumn();

    $limit = $length > 0 ? ' LIMIT ' . $length . ' OFFSET ' . $start : '';
    $rows  = dt_q(
        $pdo,
        "SELECT r.receiptid, r.customerid, c.name AS customername, r.invoiceid, r.accountid,
                a.code AS accountcode, a.accountname, r.amount, r.receiptdate, r.note
           {$from} {$where}
       ORDER BY {$orderCol} {$dir}, r.receiptid DESC" . $limit,
        $params
    )->fetchAll();

    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'receiptid'    => (int)$r['receiptid'],
            'customerid'   => (int)$r['customerid'],
            'customername' => (string)($r['customername'] ?? ''),
            'invoiceid'    => $r['invoiceid'] !== null ? (int)$r['invoiceid'] : null,
            'accountid'    => $r['accountid'] !== null ? (int)$r['accountid'] : null,
            'accountname'  => trim((string)($r['accountcode'] ?? '') . ' ' . (string)($r['accountname'] ?? '')),
            'amount'       => round((float)$r['amount'], 2),
            'receiptdate'  => substr((string)$r['receiptdate'], 0, 10),
            'note'         => (string)($r['note'] ?? ''),
            'auto'         => dt_is_auto($r),
        ];
    }

    return [
        'success' => true, 'draw' => $draw, 'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered, 'data' => $data,
    ];
}

/**
 * Invoice options of a customer: invoices with a balance above 0, plus the currently selected one.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_invoices(PDO $pdo): array
{
    $customerId = (int)($_POST['customerid'] ?? 0);
    $receiptId  = max(0, (int)($_POST['receiptid'] ?? 0));
    $current    = max(0, (int)($_POST['invoiceid'] ?? 0));
    if ($customerId <= 0) {
        return ['success' => true, 'data' => []];
    }

    $rows = dt_q(
        $pdo,
        'SELECT t.invoiceid, t.invoicedate, t.balance
           FROM (SELECT i.invoiceid, i.invoicedate,
                        i.totalamount - COALESCE((SELECT SUM(r.amount) FROM receipts r
                                                   WHERE r.invoiceid = i.invoiceid AND r.receiptid <> :rid), 0) AS balance
                   FROM invoices i
                  WHERE i.customerid = :cid) t
          WHERE t.balance > 0.004 OR t.invoiceid = :cur
       ORDER BY t.invoiceid DESC',
        ['rid' => $receiptId, 'cid' => $customerId, 'cur' => $current]
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $balance = max(0.0, round((float)$r['balance'], 2));
        $out[]   = [
            'invoiceid' => (int)$r['invoiceid'],
            'balance'   => $balance,
            'label'     => '#' . (int)$r['invoiceid'] . ' - ' . substr((string)$r['invoicedate'], 0, 10) . ' - balance ' . dt_money($balance),
        ];
    }

    return ['success' => true, 'data' => $out];
}

/**
 * Add (id = 0) or edit a manual receipt (one transaction).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_save(PDO $pdo): array
{
    $d = dt_read_receipt_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        $old = null;
        if ($d['id'] > 0) {
            $old = dt_lock_receipt($pdo, $d['id']);
            dt_assert_manual($old);
        }

        $customerName = dt_customer_name($pdo, $d['customer']);
        if ($customerName === null) {
            throw new DtFail('The selected customer does not exist.', false, 'customerid');
        }
        dt_check_account($pdo, $d['account']);

        $oldInvoice = ($old !== null && $old['invoiceid'] !== null) ? (int)$old['invoiceid'] : 0;

        // Lock every touched invoice in ascending id order (avoids deadlocks).
        $ids = array_values(array_unique(array_filter([$d['invoice'], $oldInvoice], static fn (int $v): bool => $v > 0)));
        sort($ids);
        $locked = [];
        foreach ($ids as $invoiceId) {
            $locked[$invoiceId] = dt_lock_invoice($pdo, $invoiceId);
        }

        if ($d['invoice'] > 0) {
            $inv = $locked[$d['invoice']] ?? null;
            if ($inv === null || $inv['customerid'] === null || (int)$inv['customerid'] !== $d['customer']) {
                throw new DtFail('The selected invoice does not belong to this customer.', false, 'invoiceid');
            }
            if ($d['date'] < substr((string)$inv['invoicedate'], 0, 10)) {
                throw new DtFail('The receipt date cannot be before the invoice date.', false, 'receiptdate');
            }
            $balance = max(0.0, round((float)$inv['totalamount'] - dt_received_total($pdo, $d['invoice'], $d['id']), 2));
            if ($d['amount'] - $balance > 0.000005) {
                throw new DtFail('Amount exceeds the invoice balance (' . dt_money($balance) . ').', false, 'amount');
            }
        }

        $values = [
            'c' => $d['customer'],
            'i' => $d['invoice'] > 0 ? $d['invoice'] : null,
            'a' => $d['account'],
            'm' => dt_f2($d['amount']),
            'd' => $d['date'],
            'n' => $d['note'],
        ];

        if ($d['id'] > 0) {
            dt_q(
                $pdo,
                'UPDATE receipts
                    SET customerid = :c, invoiceid = :i, accountid = :a, amount = :m, receiptdate = :d, note = :n
                  WHERE receiptid = :id',
                $values + ['id' => $d['id']]
            );
            $receiptId = $d['id'];
        } else {
            dt_q(
                $pdo,
                'INSERT INTO receipts (customerid, invoiceid, accountid, amount, receiptdate, note)
                 VALUES (:c, :i, :a, :m, :d, :n)',
                $values
            );
            $receiptId = (int)$pdo->lastInsertId();
        }

        /* INVOICE PAID SYNC (start): old and new invoice, ascending order, already locked above */
        foreach ($ids as $invoiceId) {
            if ($locked[$invoiceId] !== null) {
                dt_sync_invoice($pdo, $invoiceId);
            }
        }
        /* INVOICE PAID SYNC (end) */

        if ($d['id'] > 0) {
            dt_log_receipt($pdo, 'Update', dt_receipt_text('updated', $receiptId, $d['invoice'], $customerName));

            return ['success' => true, 'message' => 'Receipt updated successfully.'];
        }
        dt_log_receipt($pdo, 'Add', dt_receipt_text('added', $receiptId, $d['invoice'], $customerName));

        return ['success' => true, 'message' => 'Receipt added successfully.'];
    });
}

/**
 * Delete a manual receipt (one transaction).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found or automatic.
 */
function dt_act_delete(PDO $pdo): array
{
    $receiptId = max(0, (int)($_POST['receiptid'] ?? 0));

    return dt_transaction(static function (PDO $pdo) use ($receiptId): array {
        $r = dt_lock_receipt($pdo, $receiptId);
        dt_assert_manual($r);

        $invoiceId    = $r['invoiceid'] !== null ? (int)$r['invoiceid'] : 0;
        $customerName = dt_customer_name($pdo, (int)$r['customerid']) ?? '';

        $inv = $invoiceId > 0 ? dt_lock_invoice($pdo, $invoiceId) : null;

        dt_q($pdo, 'DELETE FROM receipts WHERE receiptid = :id', ['id' => $receiptId]);

        /* INVOICE PAID SYNC (start) */
        if ($inv !== null) {
            dt_sync_invoice($pdo, $invoiceId);
        }
        /* INVOICE PAID SYNC (end) */

        dt_log_receipt($pdo, 'Delete', dt_receipt_text('deleted', $receiptId, $invoiceId, $customerName));

        return ['success' => true, 'message' => 'Receipt deleted successfully.'];
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

    $handlers = ['list' => 'dt_act_list', 'invoices' => 'dt_act_invoices', 'save' => 'dt_act_save', 'delete' => 'dt_act_delete'];
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
        dt_log_error('[receipts ' . $action . '] ' . dt_describe_throwable($e));
        if ($action === 'save' && $e->getCode() === '23000') {
            dt_json(dt_fail('The selected customer, invoice or account no longer exists.'));
        }
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[receipts ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    }
}

/* ================================================================
 * 5. PAGE DATA (customers and receiving accounts)
 * ============================================================== */

/**
 * Customers for the select (Walk-in included), ordered by name.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<int,array<string,mixed>> Rows (customerid, name).
 */
function dt_load_customers(PDO $pdo): array
{
    return dt_q($pdo, 'SELECT customerid, name FROM customers ORDER BY name ASC')->fetchAll();
}

/**
 * Receiving accounts: active Assets sub-accounts, sorted naturally by code.
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
 * 6. HTML SHELL (same look as employees.php): CSS, head, topbar, sidebar, footer
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
.text-muted { color: var(--muted) !important; }

/* ===== Badges and lock note (receipts) ===== */
.badge { display: inline-block; padding: 2px 8px; font-size: 12px; font-weight: 600; line-height: 1.5; color: #ffffff; border-radius: 0; }
.badge-auto { background: #6c757d; }
.badge-manual { background: var(--brand-accent-dark); }
.lock-note { color: var(--muted); font-size: 13px; white-space: nowrap; }

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
#receiptsTable { width: 100% !important; font-size: 14px; }
#receiptsTable thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); white-space: nowrap; font-weight: 600; }
#receiptsTable td { vertical-align: middle; }
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
                            <a href="<?= dt_e($subPage) ?>"<?= ($isAccounting && $subPage === 'receipts.php') ? ' class="active" aria-current="page"' : '' ?>><span><?= dt_e($subLabel) ?></span></a>
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
 * Receipt list card.
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
                  <h2 class="header-title">Receipt List</h2>
                  <button type="button" class="btn btn-primary btn-sm" id="btnAdd"><i class="fas fa-plus mr-1" aria-hidden="true"></i> Add Receipt</button>
                </div>

                <table id="receiptsTable" class="table table-hover table-bordered">
                  <thead>
                    <tr>
                      <th class="px60">#</th>
                      <th>Receipt</th>
                      <th>Customer</th>
                      <th>Invoice</th>
                      <th>Account</th>
                      <th class="text-right">Amount</th>
                      <th>Date</th>
                      <th>Type</th>
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
 * Add / edit receipt modal.
 *
 * @param array<int,array<string,mixed>> $customers Customers.
 * @param array<int,array<string,mixed>> $accounts  Receiving accounts.
 * @param string                         $today     Today (Y-m-d).
 *
 * @return void
 */
function dt_modal_receipt(array $customers, array $accounts, string $today): void
{
    ?>
  <div class="modal fade" id="receiptModal" tabindex="-1" role="dialog" aria-labelledby="receiptModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form class="modal-content" id="receiptForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="receiptModalTitle">Add Receipt</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="rId" value="0">
          <div class="form-group">
            <label for="rCustomer">Customer <span class="req">*</span></label>
            <select class="form-control" id="rCustomer" required>
              <option value="">Select customer</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['customerid'] ?>"><?= dt_e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback" id="rCustomerFb">Customer is required.</div>
          </div>
          <div class="form-group">
            <label for="rInvoice">Invoice</label>
            <select class="form-control" id="rInvoice">
              <option value="">No invoice</option>
            </select>
            <div class="invalid-feedback" id="rInvoiceFb">The selected invoice does not belong to this customer.</div>
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
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveReceipt"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Delete receipt modal.
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
          <h5 class="modal-title" id="deleteModalTitle">Delete Receipt</h5>
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
    dt_log_error('[receipts view] ' . dt_describe_throwable($e));
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
    'csrfField' => $csrfField, 'docTitle' => 'Receipts', 'heading' => 'Receipts',
    'sub' => 'Record money received from customers.',
];

/* ---------- Render ---------- */

dt_render_open($page);
dt_view_list();
dt_render_close();
dt_modal_receipt($customers, $accounts, $today);
dt_modal_delete();

$cfg       = ['csrf' => $csrf, 'self' => 'receipts.php', 'today' => $today, 'hasAccounts' => count($accounts) > 0];
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
      title: 'Receipts',
      filename: 'Receipts',
      exportOptions: { columns: [1, 2, 3, 4, 5, 6] }
    };

    /* ---------- DataTable (server-side) ---------- */
    var table = $('#receiptsTable').DataTable({
      serverSide: true,
      autoWidth: false,
      searchDelay: 400,
      ajax: {
        url: URL_SELF,
        type: 'POST',
        data: function (d) { d.action = 'list'; d.csrf_token = CSRF; },
        dataSrc: 'data',
        error: function (xhr) { if (window.console) { console.warn('receipts list request failed (HTTP ' + (xhr && xhr.status) + ')'); } toastr.error('Could not load receipts.'); }
      },
      columns: [
        {
          data: null, orderable: false, searchable: false,
          render: function (d, type, row, meta) { return meta.row + meta.settings._iDisplayStart + 1; }
        },
        { data: 'receiptid',    render: function (d) { return esc(d); } },
        { data: 'customername', render: function (d) { return esc(d); } },
        { data: 'invoiceid',    render: function (d) { return d ? '#' + esc(d) : '-'; } },
        { data: 'accountname',  render: function (d) { return esc(d); } },
        { data: 'amount',       className: 'text-right', render: function (d) { return esc(fmt(d)); } },
        { data: 'receiptdate',  render: function (d) { return esc(d); } },
        {
          data: 'auto', orderable: false, searchable: false,
          render: function (d) {
            return d ? '<span class="badge badge-auto">With invoice</span>' : '<span class="badge badge-manual">Manual</span>';
          }
        },
        {
          data: null, orderable: false, searchable: false, className: 'text-center',
          render: function (d, type, row) {
            if (row.auto) {
              return '<span class="lock-note"><i class="fas fa-lock" aria-hidden="true"></i> Change it in Sales</span>';
            }
            return '<button type="button" class="btn btn-primary act-btn btn-edit" title="Edit" aria-label="Edit receipt"><i class="fas fa-edit" aria-hidden="true"></i></button> ' +
                   '<button type="button" class="btn btn-danger act-btn btn-del" title="Delete" aria-label="Delete receipt"><i class="fas fa-trash" aria-hidden="true"></i></button>';
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
          orientation: 'portrait', pageSize: 'A4',
          action: exportAll('pdfHtml5'),
          customize: function (doc) {
            var tbl = null;
            $.each(doc.content, function (i, c) { if (c.table) { tbl = c.table; } });
            if (!tbl) { return; }
            tbl.widths = [42, '*', 42, '*', 70, 62];
            $.each(tbl.body, function (i, row) { if (row[4]) { row[4].alignment = 'right'; } });
          }
        }),
        $.extend({}, exportBase, { extend: 'print',      text: '<i class="fas fa-print"></i> Print',      action: exportAll('print') })
      ],
      language: { search: 'Search:', searchPlaceholder: 'Search receipt...', emptyTable: 'No receipts found', zeroRecords: 'No receipts found' }
    });

    function rowData(el) { return table.row($(el).closest('tr')).data(); }

    /* ---------- Select2 ---------- */
    $('#rCustomer, #rInvoice, #rAccount').select2({ theme: 'bootstrap4', width: '100%', dropdownParent: $('#receiptModal') });

    /* ---------- Fields: server field name => input selector (feedback element is selector + "Fb") ---------- */
    var FIELDS = {
      customerid: '#rCustomer',
      invoiceid: '#rInvoice',
      accountid: '#rAccount',
      amount: '#rAmount',
      receiptdate: '#rDate',
      note: '#rNote'
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

    $('#rCustomer, #rInvoice, #rAccount, #rAmount, #rDate, #rNote').on('input change', function () { $(this).removeClass('is-invalid'); });

    /* ---------- Invoices of the chosen customer (late answers are ignored with a request counter) ---------- */
    var invReq = 0;
    var invBalance = {};

    function resetInvoices() {
      invReq++;
      invBalance = {};
      $('#rInvoice').html('<option value="">No invoice</option>').val('').trigger('change.select2');
    }

    function loadInvoices(customerId, selected, done) {
      resetInvoices();
      if (!customerId) { return; }
      var req = ++invReq;
      var $i  = $('#rInvoice');
      api({ action: 'invoices', customerid: customerId, receiptid: $('#rId').val(), invoiceid: selected || 0 }).done(function (res) {
        if (req !== invReq || !res.success) { return; }
        $.each(res.data, function (k, r) {
          invBalance[r.invoiceid] = r.balance;
          $i.append($('<option>').val(r.invoiceid).text(r.label));
        });
        $i.val(selected ? String(selected) : '');
        if ($i.val() === null) { $i.val(''); }
        $i.trigger('change.select2');
        if (done) { done(); }
      });
    }

    $('#rCustomer').on('change', function () {
      loadInvoices($(this).val(), 0);
    });

    // Choosing an invoice fills the amount with its balance when the amount is empty
    $('#rInvoice').on('change', function () {
      var id = $(this).val();
      if (id && $.trim($('#rAmount').val()) === '' && invBalance[id] > 0) {
        $('#rAmount').val(Number(invBalance[id]).toFixed(2));
      }
    });

    /* ---------- Add ---------- */
    $('#btnAdd').on('click', function () {
      if (!CFG.hasAccounts) {
        toastr.warning('Add an active asset account in Chart of Accounts first.');
        return;
      }
      $('#receiptForm')[0].reset();
      clearInvalid();
      $('#rId').val(0);
      $('#rDate').val(TODAY);
      $('#rCustomer').val('').trigger('change.select2');
      $('#rAccount').val('').trigger('change.select2');
      resetInvoices();
      $('#receiptModalTitle').text('Add Receipt');
      $('#receiptModal').modal('show');
    });

    /* ---------- Edit (manual receipts only) ---------- */
    $('#receiptsTable tbody').on('click', '.btn-edit', function () {
      var d = rowData(this);
      if (!d || d.auto) { return; }

      $('#receiptForm')[0].reset();
      clearInvalid();
      $('#rId').val(d.receiptid);
      $('#rCustomer').val(String(d.customerid));
      if ($('#rCustomer').val() === null) { $('#rCustomer').val(''); }
      $('#rCustomer').trigger('change.select2');
      $('#rAccount').val(d.accountid ? String(d.accountid) : '');
      if ($('#rAccount').val() === null) { $('#rAccount').val(''); }
      $('#rAccount').trigger('change.select2');
      $('#rAmount').val(Number(d.amount).toFixed(2));
      $('#rDate').val(d.receiptdate);
      $('#rNote').val(d.note || '');

      loadInvoices(d.customerid, d.invoiceid || 0);

      $('#receiptModalTitle').text('Edit Receipt');
      $('#receiptModal').modal('show');
    });

    /* ---------- Save (add / edit) ---------- */
    $('#receiptForm').on('submit', function (e) {
      e.preventDefault();
      clearInvalid();

      var customer = $('#rCustomer').val();
      var account  = $('#rAccount').val();
      var amount   = parseFloat($('#rAmount').val());
      var date     = $('#rDate').val();

      if (!customer)                    { focusField(markInvalid('customerid')); return; }
      if (!account)                     { focusField(markInvalid('accountid')); return; }
      if (isNaN(amount) || amount <= 0) { focusField(markInvalid('amount')); return; }
      if (!date)                        { focusField(markInvalid('receiptdate')); return; }

      var $btn = $('#btnSaveReceipt').prop('disabled', true);
      api({
        action: 'save',
        receiptid: $('#rId').val(),
        customerid: customer,
        invoiceid: $('#rInvoice').val() || 0,
        accountid: account,
        amount: $('#rAmount').val(),
        receiptdate: date,
        note: $.trim($('#rNote').val())
      }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#receiptModal').modal('hide');
          table.ajax.reload(null, false);
          return;
        }
        if (res.field && FIELDS[res.field]) {
          focusField(markInvalid(res.field, res.message));
        }
        toastr.error(res.message);
      }).always(function () { $btn.prop('disabled', false); });
    });

    /* ---------- Delete (manual receipts only) ---------- */
    var deleteId = 0;

    $('#receiptsTable tbody').on('click', '.btn-del', function () {
      var d = rowData(this);
      if (!d || d.auto) { return; }
      deleteId = d.receiptid;
      $('#delText').empty().append(
        document.createTextNode('Delete receipt '),
        $('<b>').text('#' + d.receiptid),
        document.createTextNode(' (' + fmt(d.amount) + ', ' + (d.customername || '') + ')? This cannot be undone.')
      );
      $('#deleteModal').modal('show');
    });

    $('#btnConfirmDelete').on('click', function () {
      var $btn = $(this).prop('disabled', true);
      api({ action: 'delete', receiptid: deleteId }).done(function (res) {
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