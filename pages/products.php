<?php

declare(strict_types=1);

/**
 * pages/products.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: Products list (sidebar > Products).
 *
 * Stock approach: stored integer column products.currentstock (in SALES units), set by Add / Edit (0 to 9,999,999).
 * A purchase made from this page adds its sales units to currentstock (same rule as pages/purchases.php).
 *
 * Tables and columns expected (single tenant, no agency id anywhere):
 *   products     (productid, name, unitcost, purchaseunit, salesunit, conversionfactor, wholesaleprice, retailprice, currentstock, expiredate)
 *                unitcost = cost of one SALES unit (decimal 9,2, default 0)
 *   activitylogs (userid, action, description, activitydate)   userid must accept NULL
 *
 * One page, five AJAX actions (POST, JSON): list, save, delete, purchaselookups, purchasesave.
 * Same shell as pages/livestock.php, same architecture as the Dalsan customers page.
 *
 * PURCHASE PRODUCT (this page only, products only, one line per purchase):
 *   Tables used (single tenant, all names lowercase, same as pages/purchases.php):
 *     purchases     (purchaseid, supplierid, purchasedate, reference, totalamount, paidamount, paidfromaccountid, note)
 *     purchaseitems (purchaseitemid, purchaseid, itemtype, livestockid, productid, quantity, unitprice, linetotal, stockadded)
 *     suppliers     (supplierid, name)
 *     accounts      (accountid, parentaccountid, code, accountname, accounttype, status)
 *     payments      (optional, see BF_PAYMENTS): a missing table or column is logged and skipped.
 *     Optional tables for the available balance of the paying account (same formula as pages/chartofaccounts.php):
 *       receipts.accountid, payments.accountid, accounttransfers.fromaccountid / toaccountid, expenses.paidfromaccountid
 *       (each needs an amount column; a missing table or column is logged and counts as 0).
 *   A purchase saved here is identical to one saved in pages/purchases.php (same columns, same stockadded rule,
 *   same products.unitcost update, same automatic payment row), so it can be edited or deleted there and the
 *   stock and the payment are reversed correctly.
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call the auth helpers of ../db.php right after the
 * "NO LOGIN" marker in the request flow below (and pass the user id to dt_log_product() / dt_log_purchase()).
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

const DT_MAX_NAME  = 150;
const DT_MAX_UNIT  = 50;
const DT_MAX_STOCK = 9999999;

const DT_MAX_REFERENCE = 50;
const DT_MAX_NOTE      = 255;

const DT_FACTOR_INT_DIGITS = 6;  // 999,999.9999
const DT_FACTOR_SCALE      = 4;
const DT_PRICE_INT_DIGITS  = 7;  // 9,999,999.99
const DT_PRICE_SCALE       = 2;

const DT_QTY_INT_DIGITS   = 6;  // 999,999.9999 (same limit as pages/purchases.php)
const DT_QTY_SCALE        = 4;  // purchaseitems.quantity is decimal(10,4)
const DT_TOTAL_INT_DIGITS = 9;  // 999,999,999.99 (decimal 11,2)
const DT_MAX_TOTAL_MINOR  = 99999999999;
const DT_MAX_COST_MINOR   = 999999999; // products.unitcost decimal(9,2)

const DT_LOGO_FILE = 'bushrofarmingandlivestocklogo.png'; // preferred logo file name
const DT_LOGO_DIRS = ['uploads', 'assets', 'images'];     // folders next to pages/ searched for the logo (in this order)

/** Table and column names of the products table (fixed values, never user input). Fix a name here if your schema differs. */
const BF_PRODUCTS = [
    'table'        => 'products',
    'id'           => 'productid',
    'name'         => 'name',
    'unitcost'     => 'unitcost',
    'purchaseunit' => 'purchaseunit',
    'salesunit'    => 'salesunit',
    'factor'       => 'conversionfactor',
    'wholesale'    => 'wholesaleprice',
    'retail'       => 'retailprice',
    'stock'        => 'currentstock',
    'expire'       => 'expiredate',
];

/** Table and column names of the purchases table (fixed values, never user input). */
const BF_PURCHASES = [
    'table'      => 'purchases',
    'id'         => 'purchaseid',
    'supplierid' => 'supplierid',
    'date'       => 'purchasedate',
    'reference'  => 'reference',
    'total'      => 'totalamount',
    'paid'       => 'paidamount',
    'account'    => 'paidfromaccountid',
    'note'       => 'note',
];

/** Table and column names of the purchase lines table (fixed values, never user input). */
const BF_PURCHASEITEMS = [
    'table'       => 'purchaseitems',
    'id'          => 'purchaseitemid',
    'purchaseid'  => 'purchaseid',
    'itemtype'    => 'itemtype',
    'livestockid' => 'livestockid',
    'productid'   => 'productid',
    'quantity'    => 'quantity',
    'unitprice'   => 'unitprice',
    'linetotal'   => 'linetotal',
    'stockadded'  => 'stockadded',
];

/**
 * Table and column names of the payments table (fixed values, never user input). Fix a name here if your schema differs.
 * The row written by this page carries BF_PAYMENT_NOTE in the note column so it can be told apart from payments
 * entered on the Payments page (same marker as pages/purchases.php). If the table or a column does not exist,
 * the payment row is skipped (logged).
 */
const BF_PAYMENTS = [
    'table'      => 'payments',
    'supplierid' => 'supplierid',
    'purchaseid' => 'purchaseid',
    'accountid'  => 'accountid',
    'amount'     => 'amount',
    'date'       => 'paymentdate',
    'note'       => 'note',
];

/** Exact note text that marks the automatic payment row of a purchase. */
const BF_PAYMENT_NOTE = 'Paid with purchase';

/**
 * Amount sources of the account balance (same formula as pages/chartofaccounts.php):
 * [table, account column, sign (1 adds, -1 subtracts)].
 * Fixed whitelist (never user input). A table or column that does not exist is logged and skipped.
 */
const DT_BALANCE_SOURCES = [
    ['receipts', 'accountid', 1],
    ['payments', 'accountid', -1],
    ['accounttransfers', 'toaccountid', 1],
    ['accounttransfers', 'fromaccountid', -1],
    ['expenses', 'paidfromaccountid', -1],
];

/** Unit suggestions for the modal datalist (the user can type anything). */
const DT_UNIT_SUGGESTIONS = ['Piece', 'Box', 'Carton', 'Pack', 'Bottle', 'Kg', 'Litre', 'Bag', 'Sack', 'Dozen'];

/**
 * Tables that reference the product id and block a delete: [table, column, noun].
 * Fixed whitelist (never user input). A table or column that does not exist is logged and skipped.
 */
const DT_PRODUCT_REFS = [
    ['purchaseitems', 'productid', 'purchase line'],
    ['saleitems', 'productid', 'sale line'],
    ['quotationitems', 'productid', 'quotation line'],
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

/* ================================================================
 * 3. SHARED BUSINESS RULES (decimals, read and validate, load and lock, unique name, count references, log)
 * ============================================================== */

/**
 * Convert a validated decimal string (digits, optional dot) to an integer of minor units.
 * No float is ever involved: "12.5" with scale 2 gives 1250.
 *
 * @param string $raw   Validated decimal string.
 * @param int    $scale Number of decimals of the minor unit.
 *
 * @return int Minor units.
 */
function dt_decimal_to_minor(string $raw, int $scale): int
{
    $parts = explode('.', $raw, 2);
    $frac  = str_pad($parts[1] ?? '', $scale, '0');

    return (int)$parts[0] * (10 ** $scale) + (int)$frac;
}

/**
 * Convert minor units back to a fixed-scale decimal string ("1250" with scale 2 gives "12.50").
 *
 * @param int $minor Minor units.
 * @param int $scale Number of decimals.
 *
 * @return string Decimal string.
 */
function dt_minor_to_decimal(int $minor, int $scale): string
{
    $factor = 10 ** $scale;

    return intdiv($minor, $factor) . '.' . str_pad((string)($minor % $factor), $scale, '0', STR_PAD_LEFT);
}

/**
 * Convert signed minor units (scale 2) to a decimal string that may start with "-" ("-550" gives "-5.50").
 *
 * @param int $minor Signed minor units.
 *
 * @return string Decimal string.
 */
function dt_signed_decimal(int $minor): string
{
    return ($minor < 0 ? '-' : '') . dt_minor_to_decimal(abs($minor), 2);
}

/**
 * Convert a database SUM text (optional sign, digits, optional dot) to signed minor units of scale 2.
 * No float is involved; extra decimals beyond 2 are cut.
 *
 * @param string $raw SUM text such as "1234.50" or "-3.1".
 *
 * @return int Signed minor units.
 */
function dt_sum_to_minor(string $raw): int
{
    $raw = trim($raw);
    if ($raw === '') {
        return 0;
    }
    $neg   = $raw[0] === '-';
    $raw   = ltrim($raw, '+-');
    $parts = explode('.', $raw, 2);
    $int   = preg_match('/^\d+$/', $parts[0]) === 1 ? (int)$parts[0] : 0;
    $frac  = substr(str_pad(preg_match('/^\d+$/', $parts[1] ?? '') === 1 ? $parts[1] : '', 2, '0'), 0, 2);
    $minor = $int * 100 + (int)$frac;

    return $neg ? -$minor : $minor;
}

/**
 * Format signed minor units (scale 2) for a message: "$1,234.50" (a negative amount gives "-$5.00").
 *
 * @param int $minor Signed minor units.
 *
 * @return string Formatted amount.
 */
function dt_format_minor(int $minor): string
{
    $abs = abs($minor);

    return ($minor < 0 ? '-' : '') . '$' . number_format(intdiv($abs, 100)) . '.' . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
}

/**
 * Strictly parse a posted decimal string.
 *
 * @param string $raw       Trimmed posted text.
 * @param int    $intDigits Maximum digits before the dot.
 * @param int    $scale     Maximum digits after the dot.
 * @param bool   $positive  True when the value must be greater than zero.
 * @param string $message   Message when invalid.
 * @param string $field     Field name for the message.
 *
 * @return int Value in minor units.
 *
 * @throws DtFail When the text is not a valid decimal in range.
 */
function dt_parse_decimal(string $raw, int $intDigits, int $scale, bool $positive, string $message, string $field): int
{
    if (!preg_match('/^\d{1,' . $intDigits . '}(?:\.\d{1,' . $scale . '})?$/D', $raw)) {
        throw new DtFail($message, false, $field);
    }
    $minor = dt_decimal_to_minor($raw, $scale);
    if ($positive && $minor <= 0) {
        throw new DtFail($message, false, $field);
    }

    return $minor;
}

/**
 * Validate an optional Y-m-d date (the expire date of a product).
 *
 * @param string $raw Trimmed posted text (empty means no date).
 *
 * @return ?string Date as Y-m-d, or null when empty.
 *
 * @throws DtFail When not a real date.
 */
function dt_parse_date(string $raw): ?string
{
    if ($raw === '') {
        return null;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $raw);
    if (!$date instanceof DateTime || $date->format('Y-m-d') !== $raw) {
        throw new DtFail('Expire date must be a valid date.', false, 'expiredate');
    }

    return $raw;
}

/**
 * Validate a required Y-m-d date (the purchase date; same rule as dt_parse_date() of pages/purchases.php).
 *
 * @param string $raw     Trimmed posted text.
 * @param string $message Message when invalid.
 * @param string $field   Field key for the message.
 *
 * @return string Date as Y-m-d.
 *
 * @throws DtFail When not a real date.
 */
function dt_parse_required_date(string $raw, string $message, string $field): string
{
    $date = DateTime::createFromFormat('!Y-m-d', $raw);
    if (!$date instanceof DateTime || $date->format('Y-m-d') !== $raw) {
        throw new DtFail($message, false, $field);
    }

    return $raw;
}

/**
 * Read and validate the posted product fields (used by save).
 *
 * @return array{id:int,name:string,unitcost:string,purchaseunit:string,salesunit:string,factor:string,wholesale:string,retail:string,stock:int,expire:?string} Clean fields (decimals as fixed-scale strings).
 *
 * @throws DtFail On invalid input.
 */
function dt_read_product_input(): array
{
    $id           = max(0, (int)($_POST['productid'] ?? 0));
    $name         = trim((string)($_POST['name'] ?? ''));
    $purchaseUnit = trim((string)($_POST['purchaseunit'] ?? ''));
    $salesUnit    = trim((string)($_POST['salesunit'] ?? ''));
    $rawUnitCost  = trim((string)($_POST['unitcost'] ?? ''));
    $rawFactor    = trim((string)($_POST['conversionfactor'] ?? ''));
    $rawWholesale = trim((string)($_POST['wholesaleprice'] ?? ''));
    $rawRetail    = trim((string)($_POST['retailprice'] ?? ''));
    $rawStock     = trim((string)($_POST['currentstock'] ?? ''));
    $rawExpire    = trim((string)($_POST['expiredate'] ?? ''));

    /* Optional numbers: left empty means 0 (only name, purchase unit, sales unit and conversion factor are required). */
    $rawUnitCost  = $rawUnitCost === '' ? '0' : $rawUnitCost;
    $rawWholesale = $rawWholesale === '' ? '0' : $rawWholesale;
    $rawRetail    = $rawRetail === '' ? '0' : $rawRetail;
    $rawStock     = $rawStock === '' ? '0' : $rawStock;

    if ($name === '') {
        throw new DtFail('Name is required.', false, 'name');
    }
    if (mb_strlen($name) > DT_MAX_NAME) {
        throw new DtFail('Name is too long (maximum ' . DT_MAX_NAME . ' characters).', false, 'name');
    }
    if ($purchaseUnit === '') {
        throw new DtFail('Purchase unit is required.', false, 'purchaseunit');
    }
    if (mb_strlen($purchaseUnit) > DT_MAX_UNIT) {
        throw new DtFail('Purchase unit is too long (maximum ' . DT_MAX_UNIT . ' characters).', false, 'purchaseunit');
    }
    if ($salesUnit === '') {
        throw new DtFail('Sales unit is required.', false, 'salesunit');
    }
    if (mb_strlen($salesUnit) > DT_MAX_UNIT) {
        throw new DtFail('Sales unit is too long (maximum ' . DT_MAX_UNIT . ' characters).', false, 'salesunit');
    }

    $factor = dt_parse_decimal(
        $rawFactor,
        DT_FACTOR_INT_DIGITS,
        DT_FACTOR_SCALE,
        true,
        'Conversion factor must be greater than 0, with up to 4 decimals (maximum 999,999.9999).',
        'conversionfactor'
    );
    $priceMessage = ' must be 0 or more, with up to 2 decimals (maximum 9,999,999.99).';
    $wholesale = dt_parse_decimal($rawWholesale, DT_PRICE_INT_DIGITS, DT_PRICE_SCALE, false, 'Wholesale price' . $priceMessage, 'wholesaleprice');
    $retail    = dt_parse_decimal($rawRetail, DT_PRICE_INT_DIGITS, DT_PRICE_SCALE, false, 'Retail price' . $priceMessage, 'retailprice');
    $unitCost  = dt_parse_decimal($rawUnitCost, DT_PRICE_INT_DIGITS, DT_PRICE_SCALE, false, 'Unit cost' . $priceMessage, 'unitcost');
    if ($retail < $wholesale) {
        throw new DtFail('Retail price cannot be lower than the wholesale price.', false, 'retailprice');
    }

    if ($rawStock === '' || !ctype_digit($rawStock) || strlen($rawStock) > 10 || (int)$rawStock > DT_MAX_STOCK) {
        throw new DtFail('Current stock must be a whole number from 0 to ' . number_format(DT_MAX_STOCK) . '.', false, 'currentstock');
    }
    $expire = dt_parse_date($rawExpire);

    return [
        'id'           => $id,
        'name'         => $name,
        'unitcost'     => dt_minor_to_decimal($unitCost, DT_PRICE_SCALE),
        'purchaseunit' => $purchaseUnit,
        'salesunit'    => $salesUnit,
        'factor'       => dt_minor_to_decimal($factor, DT_FACTOR_SCALE),
        'wholesale'    => dt_minor_to_decimal($wholesale, DT_PRICE_SCALE),
        'retail'       => dt_minor_to_decimal($retail, DT_PRICE_SCALE),
        'stock'        => (int)$rawStock,
        'expire'       => $expire,
    ];
}

/**
 * Load and lock (FOR UPDATE) a product row. Call inside a transaction.
 *
 * @param PDO    $pdo   Connection.
 * @param int    $id    Product id.
 * @param string $field Field key returned to the browser when the row is not found (empty for none).
 *
 * @return array<string,mixed> Row (id, name, stock, factor).
 *
 * @throws DtFail When not found.
 */
function dt_lock_product(PDO $pdo, int $id, string $field = ''): array
{
    $s   = BF_PRODUCTS;
    $row = dt_q(
        $pdo,
        "SELECT `{$s['id']}` AS id, `{$s['name']}` AS name, `{$s['stock']}` AS stock, `{$s['factor']}` AS factor FROM `{$s['table']}` WHERE `{$s['id']}` = :id FOR UPDATE",
        ['id' => $id]
    )->fetch();
    if (!is_array($row)) {
        throw new DtFail('Product not found.', false, $field);
    }

    return $row;
}

/**
 * Make sure no other product row has the same name (case-insensitive).
 *
 * @param PDO    $pdo      Connection.
 * @param string $name     Name to check.
 * @param int    $exceptId Row to ignore (0 when adding).
 *
 * @return void
 *
 * @throws DtFail When the name is already used.
 */
function dt_assert_unique_name(PDO $pdo, string $name, int $exceptId): void
{
    $s     = BF_PRODUCTS;
    $count = (int)dt_q(
        $pdo,
        "SELECT COUNT(*) FROM `{$s['table']}` WHERE LOWER(`{$s['name']}`) = LOWER(:n) AND `{$s['id']}` <> :id",
        ['n' => $name, 'id' => $exceptId]
    )->fetchColumn();
    if ($count > 0) {
        throw new DtFail('A product named "' . $name . '" already exists.', false, 'name');
    }
}

/**
 * Count the records that use a product (see DT_PRODUCT_REFS).
 * A missing table or column (SQLSTATE 42S02 / 42S22) is logged and skipped; any other database error is rethrown.
 *
 * @param PDO $pdo Connection.
 * @param int $id  Product id.
 *
 * @return array<int,string> Texts such as "2 sale lines", empty when the product is unused.
 *
 * @throws PDOException On a database error other than a missing table or column.
 */
function dt_count_references(PDO $pdo, int $id): array
{
    $used = [];
    foreach (DT_PRODUCT_REFS as [$table, $column, $noun]) {
        try {
            $count = (int)dt_q($pdo, "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :id", ['id' => $id])->fetchColumn();
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
                dt_log_error('[products refs] skipped ' . $table . '.' . $column . ': ' . dt_describe_throwable($e));
                continue;
            }
            throw $e;
        }
        if ($count > 0) {
            $used[] = $count . ' ' . $noun . ($count > 1 ? 's' : '');
        }
    }

    return $used;
}

/**
 * Write a product activity row (call before commit). Without login the user is unknown: userid is NULL ("System" on the dashboard).
 *
 * @param PDO    $pdo    Connection.
 * @param string $action Add | Update | Delete.
 * @param string $text   Description.
 *
 * @return void
 */
function dt_log_product(PDO $pdo, string $action, string $text): void
{
    dt_q(
        $pdo,
        'INSERT INTO activitylogs (userid, action, description, activitydate) VALUES (NULL, :a, :d, NOW())',
        ['a' => $action, 'd' => mb_substr($text, 0, 255)]
    );
}

/* ---------- Purchase rules (copied from pages/purchases.php) ---------- */

/**
 * Read and validate the posted purchase fields (used by purchasesave, before the transaction).
 * Totals are never trusted from the browser: the line total and the purchase total are computed here in minor units
 * with the same rounding as pages/purchases.php (quantity scale 4 x price scale 2, half up to 2 decimals).
 *
 * @return array{supplierid:int,date:string,reference:?string,note:?string,productid:int,qty:int,price:int,total:int,paid:int,accountid:int} Clean fields (qty scale 4, price, total and paid scale 2).
 *
 * @throws DtFail On invalid input.
 */
function dt_read_purchase_input(): array
{
    $supplierId = max(0, (int)($_POST['supplierid'] ?? 0));
    $rawDate    = trim((string)($_POST['purchasedate'] ?? ''));
    $reference  = trim((string)($_POST['reference'] ?? ''));
    $note       = trim((string)($_POST['note'] ?? ''));
    $productId  = max(0, (int)($_POST['productid'] ?? 0));
    $rawQty     = trim((string)($_POST['quantity'] ?? ''));
    $rawPrice   = trim((string)($_POST['unitprice'] ?? ''));
    $rawPaid    = trim((string)($_POST['paidamount'] ?? ''));
    $accountId  = max(0, (int)($_POST['paidfromaccountid'] ?? 0));

    if ($supplierId <= 0) {
        throw new DtFail('Supplier is required.', false, 'supplierid');
    }
    $date = dt_parse_required_date($rawDate, 'Purchase date must be a valid date.', 'purchasedate');
    if (mb_strlen($reference) > DT_MAX_REFERENCE) {
        throw new DtFail('Reference is too long (maximum ' . DT_MAX_REFERENCE . ' characters).', false, 'reference');
    }
    if (mb_strlen($note) > DT_MAX_NOTE) {
        throw new DtFail('Note is too long (maximum ' . DT_MAX_NOTE . ' characters).', false, 'note');
    }
    if ($productId <= 0) {
        throw new DtFail('Select a product.', false, 'productid');
    }

    $qty   = dt_parse_decimal($rawQty, DT_QTY_INT_DIGITS, DT_QTY_SCALE, true, 'Quantity must be greater than 0, with up to 4 decimals.', 'quantity');
    $price = dt_parse_decimal($rawPrice, DT_PRICE_INT_DIGITS, DT_PRICE_SCALE, false, 'Price must be 0 or more, with up to 2 decimals.', 'unitprice');

    /* quantity (scale 4) x price (scale 2) gives scale 6: divide by 10^4 with half-up rounding. Guard the integer range first. */
    if ($price > 0 && $qty > intdiv(PHP_INT_MAX - 5000, $price)) {
        throw new DtFail('The purchase total is too large.', false, 'quantity');
    }
    $total = intdiv($qty * $price + 5000, 10000);
    if ($total > DT_MAX_TOTAL_MINOR) {
        throw new DtFail('The purchase total is too large.', false, 'quantity');
    }

    $paid = dt_parse_decimal(
        $rawPaid === '' ? '0' : $rawPaid,
        DT_TOTAL_INT_DIGITS,
        2,
        false,
        'Paid amount must be 0 or more, with up to 2 decimals.',
        'paidamount'
    );
    if ($paid > $total) {
        throw new DtFail('Paid amount cannot be more than the total.', false, 'paidamount');
    }
    if ($paid > 0 && $accountId <= 0) {
        throw new DtFail('Select the account the payment came from.', false, 'paidfromaccountid');
    }

    return [
        'supplierid' => $supplierId,
        'date'       => $date,
        'reference'  => $reference === '' ? null : $reference,
        'note'       => $note === '' ? null : $note,
        'productid'  => $productId,
        'qty'        => $qty,
        'price'      => $price,
        'total'      => $total,
        'paid'       => $paid,
        'accountid'  => $paid > 0 ? $accountId : 0,
    ];
}

/**
 * Lock account rows FOR UPDATE, always in ascending id order. Call inside a transaction.
 *
 * @param PDO       $pdo Connection.
 * @param list<int> $ids Account ids (any order, duplicates allowed).
 *
 * @return void
 */
function dt_lock_accounts(PDO $pdo, array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
    if ($ids === []) {
        return;
    }
    sort($ids);
    $marks  = [];
    $params = [];
    foreach ($ids as $k => $id) {
        $marks[]          = ':a' . $k;
        $params['a' . $k] = $id;
    }
    dt_q($pdo, 'SELECT accountid FROM accounts WHERE accountid IN (' . implode(', ', $marks) . ') ORDER BY accountid ASC FOR UPDATE', $params)->fetchAll();
}

/**
 * Balance of the accounts in signed minor units (scale 2), with the same formula as pages/chartofaccounts.php:
 * receipts - payments + transfers in - transfers out - expenses paid from the account (see DT_BALANCE_SOURCES).
 * A missing table or column (SQLSTATE 42S02 / 42S22) is logged and skipped: its amounts count as 0.
 * Any other database error is rethrown.
 *
 * @param PDO      $pdo  Connection.
 * @param int|null $only Account id to compute, or null for every account that has movements.
 *
 * @return array<int,int> Balance per account id (an account without movements is absent: 0).
 *
 * @throws PDOException On a database error other than a missing table or column.
 */
function dt_account_balances(PDO $pdo, ?int $only = null): array
{
    $balances = [];
    foreach (DT_BALANCE_SOURCES as [$table, $column, $sign]) {
        $sql    = "SELECT `{$column}` AS accountid, SUM(amount) AS total FROM `{$table}` WHERE `{$column}` IS NOT NULL"
            . ($only !== null ? " AND `{$column}` = :id" : '') . " GROUP BY `{$column}`";
        $params = $only !== null ? ['id' => $only] : [];
        try {
            $rows = dt_q($pdo, $sql, $params)->fetchAll();
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
                dt_log_error('[products balances] skipped ' . $table . '.' . $column . ': ' . dt_describe_throwable($e));
                continue;
            }
            throw $e;
        }
        foreach ($rows as $r) {
            $id            = (int)$r['accountid'];
            $balances[$id] = ($balances[$id] ?? 0) + $sign * dt_sum_to_minor((string)($r['total'] ?? '0'));
        }
    }

    return $balances;
}

/**
 * Create, update or remove the automatic payment row of a purchase (see BF_PAYMENTS).
 * A missing table or column (SQLSTATE 42S02 / 42S22) is logged and skipped; any other database error is rethrown.
 *
 * @param PDO    $pdo        Connection.
 * @param int    $purchaseId Purchase id.
 * @param int    $supplierId Supplier id.
 * @param int    $accountId  Account the money came from.
 * @param int    $paidMinor  Paid amount in minor units (0 removes the row).
 * @param string $date       Payment date (the purchase date).
 *
 * @return void
 *
 * @throws PDOException On a database error other than a missing table or column.
 */
function dt_sync_payment(PDO $pdo, int $purchaseId, int $supplierId, int $accountId, int $paidMinor, string $date): void
{
    $s = BF_PAYMENTS;
    try {
        $count = (int)dt_q(
            $pdo,
            "SELECT COUNT(*) FROM `{$s['table']}` WHERE `{$s['purchaseid']}` = :p AND `{$s['note']}` = :m",
            ['p' => $purchaseId, 'm' => BF_PAYMENT_NOTE]
        )->fetchColumn();

        if ($paidMinor <= 0) {
            if ($count > 0) {
                dt_q($pdo, "DELETE FROM `{$s['table']}` WHERE `{$s['purchaseid']}` = :p AND `{$s['note']}` = :m", ['p' => $purchaseId, 'm' => BF_PAYMENT_NOTE]);
            }

            return;
        }

        $amount = dt_minor_to_decimal($paidMinor, 2);
        if ($count > 0) {
            dt_q(
                $pdo,
                "UPDATE `{$s['table']}` SET `{$s['supplierid']}` = :s, `{$s['accountid']}` = :a, `{$s['amount']}` = :amt, `{$s['date']}` = :d
                  WHERE `{$s['purchaseid']}` = :p AND `{$s['note']}` = :m",
                ['s' => $supplierId, 'a' => $accountId, 'amt' => $amount, 'd' => $date, 'p' => $purchaseId, 'm' => BF_PAYMENT_NOTE]
            );

            return;
        }
        dt_q(
            $pdo,
            "INSERT INTO `{$s['table']}` (`{$s['supplierid']}`, `{$s['purchaseid']}`, `{$s['accountid']}`, `{$s['amount']}`, `{$s['date']}`, `{$s['note']}`)
             VALUES (:s, :p, :a, :amt, :d, :m)",
            ['s' => $supplierId, 'p' => $purchaseId, 'a' => $accountId, 'amt' => $amount, 'd' => $date, 'm' => BF_PAYMENT_NOTE]
        );
    } catch (PDOException $e) {
        if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
            dt_log_error('[products payment] skipped ' . $s['table'] . ': ' . dt_describe_throwable($e));

            return;
        }
        throw $e;
    }
}

/**
 * Write a purchase activity row (call before commit). Without login the user is unknown: userid is NULL.
 *
 * @param PDO    $pdo    Connection.
 * @param string $action Add | Update | Delete.
 * @param string $text   Description.
 *
 * @return void
 */
function dt_log_purchase(PDO $pdo, string $action, string $text): void
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
 * List all products ordered by name.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_list(PDO $pdo): array
{
    $s    = BF_PRODUCTS;
    $rows = dt_q(
        $pdo,
        "SELECT `{$s['id']}` AS productid, `{$s['name']}` AS name, `{$s['unitcost']}` AS unitcost, `{$s['purchaseunit']}` AS purchaseunit, `{$s['salesunit']}` AS salesunit,
                `{$s['factor']}` AS conversionfactor, `{$s['wholesale']}` AS wholesaleprice, `{$s['retail']}` AS retailprice,
                `{$s['stock']}` AS currentstock, `{$s['expire']}` AS expiredate
           FROM `{$s['table']}` ORDER BY `{$s['name']}` ASC"
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $expire = $r['expiredate'] ?? null;
        $out[] = [
            'productid'        => (int)$r['productid'],
            'name'             => (string)($r['name'] ?? ''),
            'unitcost'         => (float)($r['unitcost'] ?? 0),
            'purchaseunit'     => (string)($r['purchaseunit'] ?? ''),
            'salesunit'        => (string)($r['salesunit'] ?? ''),
            'conversionfactor' => (float)($r['conversionfactor'] ?? 0),
            'wholesaleprice'   => (float)($r['wholesaleprice'] ?? 0),
            'retailprice'      => (float)($r['retailprice'] ?? 0),
            'currentstock'     => (int)($r['currentstock'] ?? 0),
            'expiredate'       => ($expire === null || $expire === '') ? '' : substr((string)$expire, 0, 10),
        ];
    }

    return ['success' => true, 'data' => $out];
}

/**
 * Add (id = 0) or edit a product (one transaction).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input, a duplicate name or a missing row.
 */
function dt_act_save(PDO $pdo): array
{
    $d = dt_read_product_input();
    $s = BF_PRODUCTS;

    return dt_transaction(static function (PDO $pdo) use ($d, $s): array {
        $params = [
            'n'  => $d['name'],
            'uc' => $d['unitcost'],
            'pu' => $d['purchaseunit'],
            'su' => $d['salesunit'],
            'f'  => $d['factor'],
            'w'  => $d['wholesale'],
            'r'  => $d['retail'],
            'q'  => $d['stock'],
            'e'  => $d['expire'],
        ];

        if ($d['id'] > 0) {
            dt_lock_product($pdo, $d['id']);
            dt_assert_unique_name($pdo, $d['name'], $d['id']);
            dt_q(
                $pdo,
                "UPDATE `{$s['table']}` SET `{$s['name']}` = :n, `{$s['unitcost']}` = :uc, `{$s['purchaseunit']}` = :pu, `{$s['salesunit']}` = :su, `{$s['factor']}` = :f,
                        `{$s['wholesale']}` = :w, `{$s['retail']}` = :r, `{$s['stock']}` = :q, `{$s['expire']}` = :e
                  WHERE `{$s['id']}` = :id",
                $params + ['id' => $d['id']]
            );
            dt_log_product($pdo, 'Update', 'Product updated: ' . $d['name']);

            return ['success' => true, 'message' => 'Product updated successfully.'];
        }

        dt_assert_unique_name($pdo, $d['name'], 0);
        dt_q(
            $pdo,
            "INSERT INTO `{$s['table']}` (`{$s['name']}`, `{$s['unitcost']}`, `{$s['purchaseunit']}`, `{$s['salesunit']}`, `{$s['factor']}`, `{$s['wholesale']}`, `{$s['retail']}`, `{$s['stock']}`, `{$s['expire']}`)
             VALUES (:n, :uc, :pu, :su, :f, :w, :r, :q, :e)",
            $params
        );
        dt_log_product($pdo, 'Add', 'Product added: ' . $d['name']);

        return ['success' => true, 'message' => 'Product added successfully.'];
    });
}

/**
 * Delete a product unless other records use it or its stock is not zero (one transaction).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found, still used or the stock is not zero (blocked).
 */
function dt_act_delete(PDO $pdo): array
{
    $id = max(0, (int)($_POST['productid'] ?? 0));
    $s  = BF_PRODUCTS;

    return dt_transaction(static function (PDO $pdo) use ($id, $s): array {
        $row = dt_lock_product($pdo, $id);

        $used = dt_count_references($pdo, $id);
        if ($used) {
            throw new DtFail('Cannot delete "' . $row['name'] . '": the product has ' . implode(', ', $used) . '.', true);
        }
        if ((int)$row['stock'] > 0) {
            throw new DtFail('Cannot delete: current stock is not zero.', true);
        }

        dt_q($pdo, "DELETE FROM `{$s['table']}` WHERE `{$s['id']}` = :id", ['id' => $id]);
        dt_log_product($pdo, 'Delete', 'Product deleted: ' . $row['name']);

        return ['success' => true, 'message' => 'Product deleted successfully.'];
    });
}

/**
 * Options for the purchase modal: suppliers, products (with units, factor, cost and stock) and the Assets sub-accounts
 * that can pay (with their balance). The suppliers table has no status column, so every supplier is listed.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_purchaselookups(PDO $pdo): array
{
    $r = BF_PRODUCTS;

    $suppliers = [];
    foreach (dt_q($pdo, 'SELECT supplierid, name FROM suppliers ORDER BY name ASC')->fetchAll() as $row) {
        $suppliers[] = ['supplierid' => (int)$row['supplierid'], 'name' => (string)$row['name']];
    }

    $products = [];
    foreach (dt_q(
        $pdo,
        "SELECT `{$r['id']}` AS productid, `{$r['name']}` AS name, `{$r['purchaseunit']}` AS purchaseunit, `{$r['salesunit']}` AS salesunit,
                `{$r['factor']}` AS conversionfactor, `{$r['unitcost']}` AS unitcost, `{$r['stock']}` AS currentstock
           FROM `{$r['table']}` ORDER BY `{$r['name']}` ASC"
    )->fetchAll() as $row) {
        $products[] = [
            'productid'        => (int)$row['productid'],
            'name'             => (string)$row['name'],
            'purchaseunit'     => (string)$row['purchaseunit'],
            'salesunit'        => (string)$row['salesunit'],
            'conversionfactor' => (string)$row['conversionfactor'],
            'unitcost'         => (string)$row['unitcost'],
            'currentstock'     => (int)$row['currentstock'],
        ];
    }

    $rows = dt_q(
        $pdo,
        "SELECT accountid, code, accountname FROM accounts WHERE accounttype = 'Assets' AND status = 'ON' AND parentaccountid IS NOT NULL"
    )->fetchAll();
    usort($rows, static fn (array $a, array $b): int => strnatcmp((string)$a['code'], (string)$b['code']));
    $balances = dt_account_balances($pdo);
    $accounts = [];
    foreach ($rows as $row) {
        $accounts[] = [
            'accountid'   => (int)$row['accountid'],
            'code'        => (string)$row['code'],
            'accountname' => (string)$row['accountname'],
            'balance'     => dt_signed_decimal($balances[(int)$row['accountid']] ?? 0),
        ];
    }

    return ['success' => true, 'data' => ['suppliers' => $suppliers, 'products' => $products, 'accounts' => $accounts]];
}

/**
 * Create a product purchase: header, one line, stock, unit cost and payment (one transaction).
 * Same rows and same rules as a purchase saved in pages/purchases.php, so it can be edited or deleted there.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_purchasesave(PDO $pdo): array
{
    $d = dt_read_purchase_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        $p = BF_PURCHASES;
        $i = BF_PURCHASEITEMS;
        $s = BF_PRODUCTS;

        $supplier = dt_q($pdo, 'SELECT name FROM suppliers WHERE supplierid = :id', ['id' => $d['supplierid']])->fetchColumn();
        if ($supplier === false) {
            throw new DtFail('The selected supplier does not exist.', false, 'supplierid');
        }
        $supplier = (string)$supplier;

        $accountLabel = '';
        if ($d['paid'] > 0) {
            $account = dt_q($pdo, 'SELECT accounttype, code, accountname FROM accounts WHERE accountid = :id', ['id' => $d['accountid']])->fetch();
            if (!is_array($account)) {
                throw new DtFail('The selected account does not exist.', false, 'paidfromaccountid');
            }
            if ((string)$account['accounttype'] !== 'Assets') {
                throw new DtFail('The paying account must be an Assets account.', false, 'paidfromaccountid');
            }
            $accountLabel = (string)$account['code'] . ' ' . (string)$account['accountname'];
        }

        /* a. Lock the product row (two quick purchases of the same product queue here: no lost update). */
        $product = dt_lock_product($pdo, $d['productid'], 'productid');

        /* b. Sales units to add: quantity x conversion factor, rounded half up (same as pages/purchases.php), and the stock limit. */
        $factor = dt_decimal_to_minor((string)$product['factor'], 4);
        if ($factor <= 0 || $d['qty'] > intdiv(PHP_INT_MAX - 50000000, $factor)) {
            throw new DtFail('The stock to add is too large.', false, 'quantity');
        }
        $added = intdiv($d['qty'] * $factor + 50000000, 100000000);   // sales units
        $cost  = intdiv($d['price'] * 20000 + $factor, 2 * $factor);  // price / factor, 2 decimals, half up (cost of one sales unit)
        if ($cost > DT_MAX_COST_MINOR) {
            throw new DtFail('The cost of one sales unit is too large.', false, 'unitprice');
        }
        if ($added > DT_MAX_STOCK || (int)$product['stock'] + $added > DT_MAX_STOCK) {
            throw new DtFail('Cannot purchase: ' . $product['name'] . ' stock would exceed ' . number_format(DT_MAX_STOCK) . '.', false, 'quantity');
        }

        /* c. Balance rule: lock the paying account, recompute its balance inside this transaction. */
        if ($d['paid'] > 0) {
            dt_lock_accounts($pdo, [$d['accountid']]);
            $available = dt_account_balances($pdo, $d['accountid'])[$d['accountid']] ?? 0;
            if ($d['paid'] > $available) {
                throw new DtFail(
                    'Insufficient balance in ' . $accountLabel . ': available ' . dt_format_minor($available) . ', required ' . dt_format_minor($d['paid']) . '.',
                    false,
                    'paidamount'
                );
            }
        }

        /* d. Purchase header and its single product line. */
        dt_q(
            $pdo,
            "INSERT INTO `{$p['table']}` (`{$p['supplierid']}`, `{$p['date']}`, `{$p['reference']}`, `{$p['total']}`, `{$p['paid']}`, `{$p['account']}`, `{$p['note']}`)
             VALUES (:sup, :dt, :ref, :tot, :pd, :acc, :nt)",
            [
                'sup' => $d['supplierid'],
                'dt'  => $d['date'],
                'ref' => $d['reference'],
                'tot' => dt_minor_to_decimal($d['total'], 2),
                'pd'  => dt_minor_to_decimal($d['paid'], 2),
                'acc' => $d['paid'] > 0 ? $d['accountid'] : null,
                'nt'  => $d['note'],
            ]
        );
        $purchaseId = (int)$pdo->lastInsertId();

        dt_q(
            $pdo,
            "INSERT INTO `{$i['table']}` (`{$i['purchaseid']}`, `{$i['itemtype']}`, `{$i['livestockid']}`, `{$i['productid']}`, `{$i['quantity']}`, `{$i['unitprice']}`, `{$i['linetotal']}`, `{$i['stockadded']}`)
             VALUES (:p, 'Product', NULL, :pr, :q, :u, :lt, :sa)",
            [
                'p'  => $purchaseId,
                'pr' => $d['productid'],
                'q'  => dt_minor_to_decimal($d['qty'], DT_QTY_SCALE),
                'u'  => dt_minor_to_decimal($d['price'], 2),
                'lt' => dt_minor_to_decimal($d['total'], 2),
                'sa' => $added,
            ]
        );

        /* e. Stock (sales units) and unit cost of one sales unit (same rule as pages/purchases.php). */
        dt_q(
            $pdo,
            "UPDATE `{$s['table']}` SET `{$s['stock']}` = `{$s['stock']}` + :q, `{$s['unitcost']}` = :c WHERE `{$s['id']}` = :id",
            ['q' => $added, 'c' => dt_minor_to_decimal($cost, 2), 'id' => $d['productid']]
        );

        /* f. Automatic payment row (same helper, table, columns and note marker as pages/purchases.php). */
        if ($d['paid'] > 0) {
            dt_sync_payment($pdo, $purchaseId, $d['supplierid'], $d['accountid'], $d['paid'], $d['date']);
        }

        dt_log_purchase($pdo, 'Add', 'Purchase added: #' . $purchaseId . ' ' . $supplier);

        return ['success' => true, 'message' => 'Purchase #' . $purchaseId . ' saved.', 'purchaseid' => $purchaseId];
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
        'list'            => 'dt_act_list',
        'save'            => 'dt_act_save',
        'delete'          => 'dt_act_delete',
        'purchaselookups' => 'dt_act_purchaselookups',
        'purchasesave'    => 'dt_act_purchasesave',
    ];
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
        dt_log_error('[products ' . $action . '] ' . dt_describe_throwable($e));
        if ($e->getCode() === '23000' && $action === 'delete') {
            dt_json(['success' => false, 'blocked' => true, 'message' => 'Cannot delete: the product is used in other records.']);
        }
        if ($e->getCode() === '23000' && $action === 'save') {
            dt_json(['success' => false, 'field' => 'name', 'message' => 'A product with this name already exists.']);
        }
        if ($e->getCode() === '23000' && $action === 'purchasesave') {
            dt_json(dt_fail('Cannot save: a supplier, account or product of this purchase is missing or in use.'));
        }
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[products ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    }
}

/* ================================================================
 * 5. HTML SHELL (same look as livestock.php): CSS, head, topbar, sidebar, footer
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
.table-scroll { overflow-x: auto; scrollbar-width: none; -ms-overflow-style: none; }
.table-scroll::-webkit-scrollbar { display: none; }

/* ===== Cards ===== */
.card {
    background: #ffffff; min-width: 0; border: 0; border-radius: 0;
    box-shadow: 0 -3px 31px 0 rgba(0, 0, 0, 0.04), 0 6px 20px 0 rgba(0, 0, 0, 0.03);
}
.card-body { padding: 20px; }
.card-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
.card-actions { display: flex; flex-wrap: wrap; gap: 8px; }
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

/* ===== Badges ===== */
.badge { display: inline-block; padding: 2px 8px; font-size: 12.5px; font-weight: 600; line-height: 1.5; color: #ffffff; background: var(--muted); border-radius: 0; }
.badge.st-active { background: var(--brand-accent-dark); }
.badge.st-inactive { background: #6c757d; }
.badge.st-expired { background: var(--danger); }
.badge.st-soon { background: var(--warning); }

/* ===== Modals ===== */
.modal-content { border: 0; border-radius: 0; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25); }
.modal-header { background: var(--brand-primary); color: #ffffff; border-radius: 0; border-bottom: 0; padding: 10px 20px; }
.modal-header .modal-title { color: #ffffff; font-size: 16px; font-weight: 500; margin: 0; }
.modal-header .close { color: #ffffff; opacity: .9; text-shadow: none; }
.modal-header .close:hover { opacity: 1; color: #ffffff; }
.modal-footer { border-top: 1px solid var(--line); padding: 10px 20px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
.helper-line { margin-top: 4px; font-size: 13px; color: var(--muted); }
.totals-box { display: flex; flex-wrap: wrap; justify-content: flex-start; gap: 4px 24px; font-size: 14px; }
.totals-box strong { color: var(--brand-primary); }
.modal-footer .totals-box { margin-right: auto; }

/* ===== DataTable ===== */
#productsTable { width: 100% !important; font-size: 14px; }
#productsTable thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); white-space: nowrap; font-weight: 600; padding-left: 4px; padding-right: 20px; }
#productsTable thead th.sorting_disabled { padding-right: 4px; }
#productsTable th.pin-expire, #productsTable td.pin-expire { position: sticky; right: 120px; z-index: 2; background: #ffffff; box-shadow: inset 1px 0 0 var(--line); }
#productsTable th.pin-action, #productsTable td.pin-action { position: sticky; right: 0; z-index: 2; width: 120px; min-width: 120px; background: #ffffff; box-shadow: inset 1px 0 0 var(--line); white-space: nowrap; }
#productsTable thead th.pin-expire, #productsTable thead th.pin-action { background: var(--surface); z-index: 3; }
#productsTable tbody tr:hover td.pin-expire, #productsTable tbody tr:hover td.pin-action { background: #f1f9f4; }
#productsTable td { vertical-align: middle; white-space: nowrap; padding-left: 4px; padding-right: 4px; }
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
@media (max-width: 575px) { .user-name, .chev { display: none; } .form-grid { grid-template-columns: 1fr; } }
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
        <?php foreach ($p['nav'] as [$key, $label, $icon, $page, $subs]): ?>
            <li>
                <?php if ($subs): ?>
                    <button type="button" class="nav-btn" data-sub title="<?= dt_e($label) ?>" aria-expanded="false" aria-controls="sub-<?= dt_e($key) ?>">
                        <?= dt_icon($icon) ?> <span><?= dt_e($label) ?></span><?= dt_icon('caret') ?>
                    </button>
                    <div class="sub" id="sub-<?= dt_e($key) ?>">
                        <?php foreach ($subs as [$subLabel, $subPage]): ?>
                            <a href="<?= dt_e($subPage) ?>"><span><?= dt_e($subLabel) ?></span></a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <a href="<?= dt_e($page) ?>" title="<?= dt_e($label) ?>"<?= $key === 'products' ? ' class="active" aria-current="page"' : '' ?>>
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
 * Product list card (one flat header row, 11 plain columns).
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
                  <h2 class="header-title">Product List</h2>
                  <div class="card-actions">
                    <button type="button" class="btn btn-success btn-sm" id="btnPurchase"><i class="fas fa-shopping-cart mr-1" aria-hidden="true"></i> Purchase Product</button>
                    <button type="button" class="btn btn-primary btn-sm" id="btnAdd"><i class="fas fa-plus mr-1" aria-hidden="true"></i> Add Product</button>
                  </div>
                </div>

                <table id="productsTable" class="table table-hover table-bordered">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Name</th>
                      <th>Unit Cost</th>
                      <th>Wholesale Price</th>
                      <th>Retail Price</th>
                      <th>Current Stock</th>
                      <th>Purchase Unit</th>
                      <th>Sales Unit</th>
                      <th>Conversion Factor</th>
                      <th class="pin-expire">Expire Date</th>
                      <th class="text-center pin-action">Action</th>
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
 * Add / edit product modal.
 *
 * @return void
 */
function dt_modal_product(): void
{
    ?>
  <div class="modal fade" id="productModal" tabindex="-1" role="dialog" aria-labelledby="productModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
      <form class="modal-content" id="productForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="productModalTitle">Add Product</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="pId" value="0">
          <datalist id="unitList">
            <?php foreach (DT_UNIT_SUGGESTIONS as $unit): ?>
              <option value="<?= dt_e($unit) ?>"></option>
            <?php endforeach; ?>
          </datalist>
          <div class="form-grid">
            <div class="form-group">
              <label for="pName">Name <span class="req">*</span></label>
              <input type="text" class="form-control" id="pName" maxlength="<?= DT_MAX_NAME ?>" required aria-describedby="pNameFb">
              <div class="invalid-feedback" id="pNameFb">Name is required.</div>
            </div>
            <div class="form-group">
              <label for="pUnitCost">Unit Cost ($)</label>
              <input type="number" class="form-control" id="pUnitCost" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="0" aria-describedby="pUnitCostFb">
              <div class="invalid-feedback" id="pUnitCostFb">Unit cost must be 0 or more, with up to 2 decimals (maximum 9,999,999.99).</div>
            </div>
            <div class="form-group">
              <label for="pPurchase">Purchase Unit <span class="req">*</span></label>
              <input type="text" class="form-control" id="pPurchase" list="unitList" maxlength="<?= DT_MAX_UNIT ?>" value="Carton" required aria-describedby="pPurchaseFb">
              <div class="invalid-feedback" id="pPurchaseFb">Purchase unit is required.</div>
            </div>
            <div class="form-group">
              <label for="pSales">Sales Unit <span class="req">*</span></label>
              <input type="text" class="form-control" id="pSales" list="unitList" maxlength="<?= DT_MAX_UNIT ?>" value="Piece" required aria-describedby="pSalesFb">
              <div class="invalid-feedback" id="pSalesFb">Sales unit is required.</div>
            </div>
            <div class="form-group">
              <label for="pFactor">Conversion Factor <span class="req">*</span></label>
              <input type="number" class="form-control" id="pFactor" min="0.0001" max="999999.9999" step="0.0001" inputmode="decimal" value="1" required aria-describedby="pFactorHelp pFactorFb">
              <div class="helper-line" id="pFactorHelp"></div>
              <div class="invalid-feedback" id="pFactorFb">Conversion factor must be greater than 0, with up to 4 decimals (maximum 999,999.9999).</div>
            </div>
            <div class="form-group">
              <label for="pWholesale">Wholesale Price ($)</label>
              <input type="number" class="form-control" id="pWholesale" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="0" aria-describedby="pWholesaleFb">
              <div class="invalid-feedback" id="pWholesaleFb">Wholesale price must be 0 or more, with up to 2 decimals (maximum 9,999,999.99).</div>
            </div>
            <div class="form-group">
              <label for="pRetail">Retail Price ($)</label>
              <input type="number" class="form-control" id="pRetail" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="0" aria-describedby="pRetailFb">
              <div class="invalid-feedback" id="pRetailFb">Retail price must be 0 or more, with up to 2 decimals (maximum 9,999,999.99).</div>
            </div>
            <div class="form-group mb-0">
              <label for="pStock">Current Stock</label>
              <input type="number" class="form-control" id="pStock" min="0" max="<?= DT_MAX_STOCK ?>" step="1" inputmode="numeric" value="0" aria-describedby="pStockFb">
              <div class="invalid-feedback" id="pStockFb">Enter a whole number from 0 to <?= number_format(DT_MAX_STOCK) ?>.</div>
            </div>
            <div class="form-group mb-0">
              <label for="pExpire">Expire Date</label>
              <input type="date" class="form-control" id="pExpire" aria-describedby="pExpireFb">
              <div class="invalid-feedback" id="pExpireFb">Expire date must be a valid date.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveProduct"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Purchase product modal (one product per purchase).
 *
 * @return void
 */
function dt_modal_purchase(): void
{
    ?>
  <div class="modal fade" id="purchaseModal" tabindex="-1" role="dialog" aria-labelledby="purchaseModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable" role="document">
      <form class="modal-content" id="purchaseForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="purchaseModalTitle">Purchase Product</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-grid">
            <div class="form-group">
              <label for="puSupplier">Supplier <span class="req">*</span></label>
              <select class="form-control" id="puSupplier" required aria-label="Supplier" aria-describedby="puSupplierFb">
                <option value="">Select supplier</option>
              </select>
              <div class="invalid-feedback" id="puSupplierFb">Supplier is required.</div>
            </div>
            <div class="form-group">
              <label for="puDate">Purchase Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="puDate" required aria-label="Purchase date" aria-describedby="puDateFb">
              <div class="invalid-feedback" id="puDateFb">Purchase date must be a valid date.</div>
            </div>
            <div class="form-group">
              <label for="puRef">Reference</label>
              <input type="text" class="form-control" id="puRef" maxlength="<?= DT_MAX_REFERENCE ?>" aria-label="Reference" aria-describedby="puRefHelp puRefFb">
              <div class="helper-line" id="puRefHelp">Supplier invoice number.</div>
              <div class="invalid-feedback" id="puRefFb">Reference is too long.</div>
            </div>
            <div class="form-group">
              <label for="puProduct">Product <span class="req">*</span></label>
              <select class="form-control" id="puProduct" required aria-label="Product" aria-describedby="puProductHelp puProductFb">
                <option value="">Select product</option>
              </select>
              <div class="helper-line" id="puProductHelp" aria-live="polite"></div>
              <div class="invalid-feedback" id="puProductFb">Select a product.</div>
            </div>
            <div class="form-group">
              <label for="puQty">Quantity (<span id="puQtyUnit">purchase unit</span>) <span class="req">*</span></label>
              <input type="number" class="form-control" id="puQty" min="0.0001" max="999999.9999" step="0.0001" inputmode="decimal" required aria-label="Quantity in purchase units" aria-describedby="puQtyHelp puQtyFb">
              <div class="helper-line" id="puQtyHelp" aria-live="polite"></div>
              <div class="invalid-feedback" id="puQtyFb">Quantity must be greater than 0, with up to 4 decimals.</div>
            </div>
            <div class="form-group">
              <label for="puPrice">Price per <span id="puPriceUnit">purchase unit</span> ($) <span class="req">*</span></label>
              <input type="number" class="form-control" id="puPrice" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="0" required aria-label="Price per purchase unit" aria-describedby="puPriceFb">
              <div class="invalid-feedback" id="puPriceFb">Price must be 0 or more, with up to 2 decimals.</div>
            </div>
            <div class="form-group">
              <label for="puTotal">Total</label>
              <input type="text" class="form-control" id="puTotal" value="$0.00" readonly aria-readonly="true" aria-label="Total">
            </div>
            <div class="form-group">
              <label for="puPaid">Paid Amount ($)</label>
              <input type="number" class="form-control" id="puPaid" min="0" max="999999999.99" step="0.01" inputmode="decimal" value="0" aria-label="Paid amount" aria-describedby="puPaidFb">
              <div class="invalid-feedback" id="puPaidFb">Paid amount must be 0 or more, with up to 2 decimals.</div>
            </div>
            <div class="form-group">
              <label for="puAccount">Paid From Account <span class="req" id="puAccountReq" hidden>*</span></label>
              <select class="form-control" id="puAccount" aria-label="Paid from account" aria-describedby="puAccountHelp puAccountFb">
                <option value="">Select account</option>
              </select>
              <div class="helper-line" id="puAccountHelp" aria-live="polite"></div>
              <div class="invalid-feedback" id="puAccountFb">Select the account the payment came from.</div>
            </div>
            <div class="form-group">
              <label for="puNote">Note</label>
              <input type="text" class="form-control" id="puNote" maxlength="<?= DT_MAX_NOTE ?>" aria-label="Note" aria-describedby="puNoteFb">
              <div class="invalid-feedback" id="puNoteFb">Note is too long.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <div class="totals-box" aria-live="polite">
            <div>Total: <strong id="puFootTotal">$0.00</strong></div>
            <div>Paid: <strong id="puFootPaid">$0.00</strong></div>
            <div>Balance due: <strong id="puFootBalance">$0.00</strong></div>
          </div>
          <button type="submit" class="btn btn-success btn-sm" id="btnSavePurchase"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Delete product modal.
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
          <h5 class="modal-title" id="deleteModalTitle">Delete Product</h5>
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
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/**
 * Sidebar: key, label, icon, page, submenu links (label, page). Same menu as pages/customers.php.
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
    'csrfField' => $csrfField, 'docTitle' => 'Products', 'heading' => 'Products',
    'sub' => 'Manage the products, units, prices and stock.',
];

/* ---------- Render ---------- */

dt_render_open($page);
dt_view_list();
dt_render_close();
dt_modal_product();
dt_modal_purchase();
dt_modal_delete();

$cfg       = ['csrf' => $csrf, 'self' => 'products.php', 'maxStock' => DT_MAX_STOCK];
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
    /* ---------- Formatting helpers ---------- */
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(date) { return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate()); }
    function money(v) { return '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtDate(s) { return s.substr(8, 2) + ' ' + MONTHS[parseInt(s.substr(5, 2), 10) - 1] + ' ' + s.substr(0, 4); }

    /* ---------- DataTable ---------- */
    var exportCols = { columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9], orthogonal: 'export' };

    var table = $('#productsTable').DataTable({
      ajax: {
        url: URL_SELF,
        type: 'POST',
        data: function (d) { d.action = 'list'; d.csrf_token = CSRF; },
        dataSrc: 'data',
        error: function (xhr) { if (window.console) { console.warn('products list request failed (HTTP ' + (xhr && xhr.status) + ')'); } }
      },
      columns: [
        { data: 'productid' },
        { data: 'name', render: function (d) { return esc(d); } },
        {
          data: 'unitcost', className: 'text-right',
          render: function (d, type) { return type === 'display' ? money(d) : d; }
        },
        {
          data: 'wholesaleprice', className: 'text-right',
          render: function (d, type) { return type === 'display' ? money(d) : d; }
        },
        {
          data: 'retailprice', className: 'text-right',
          render: function (d, type) { return type === 'display' ? money(d) : d; }
        },
        {
          data: 'currentstock', className: 'text-right',
          render: function (d, type) { return type === 'display' ? Number(d).toLocaleString('en-US') : d; }
        },
        { data: 'purchaseunit', render: function (d) { return esc(d); } },
        { data: 'salesunit',    render: function (d) { return esc(d); } },
        {
          data: 'conversionfactor', className: 'text-right',
          render: function (d, type) { return type === 'display' ? Number(d).toLocaleString('en-US', { maximumFractionDigits: 4 }) : d; }
        },
        {
          data: 'expiredate', className: 'pin-expire',
          render: function (d, type) {
            if (type === 'sort' || type === 'type') { return d === '' ? '9999-99-99' : d; }
            if (type !== 'display') { return d; }
            if (d === '') { return '\u2014'; }
            var now   = new Date();
            var today = ymd(now);
            var limit = ymd(new Date(now.getFullYear(), now.getMonth(), now.getDate() + 30));
            var text  = esc(fmtDate(d));
            if (d < today)  { return text + ' <span class="badge st-expired">Expired</span>'; }
            if (d <= limit) { return text + ' <span class="badge st-soon">Expires soon</span>'; }
            return text;
          }
        },
        {
          data: null, orderable: false, searchable: false, className: 'text-center pin-action',
          render: function () {
            return '<button type="button" class="btn btn-success act-btn btn-buy" title="Purchase" aria-label="Purchase product"><i class="fas fa-shopping-cart" aria-hidden="true"></i></button> ' +
                   '<button type="button" class="btn btn-primary act-btn btn-edit" title="Edit" aria-label="Edit product"><i class="fas fa-edit" aria-hidden="true"></i></button> ' +
                   '<button type="button" class="btn btn-danger act-btn btn-del" title="Delete" aria-label="Delete product"><i class="fas fa-trash" aria-hidden="true"></i></button>';
          }
        }
      ],
      order: [[1, 'asc']],
      pageLength: 10,
      lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
      dom: "<'dt-top'<'dt-len'l><'dt-btns'B><'dt-find'f>>" +
           "<'table-scroll'tr>" +
           "<'row mt-2'<'col-md-5'i><'col-md-7'p>>",
      buttons: [
        { extend: 'copyHtml5',  className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-copy"></i> Copy',        exportOptions: exportCols },
        { extend: 'csvHtml5',   className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-csv"></i> CSV',     exportOptions: exportCols },
        { extend: 'excelHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-excel"></i> Excel', title: 'Products', exportOptions: exportCols },
        { extend: 'pdfHtml5',   className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-pdf"></i> PDF',     title: 'Products', exportOptions: exportCols },
        { extend: 'print',      className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-print"></i> Print',      title: 'Products', exportOptions: exportCols }
      ],
      language: { search: 'Search:', searchPlaceholder: 'Search product...', emptyTable: 'No products found' }
    });

    function rowData(el) { return table.row($(el).closest('tr')).data(); }

    /* ---------- Add / Edit modal ---------- */
    var FIELDS = {
      name:             ['#pName',      '#pNameFb'],
      unitcost:         ['#pUnitCost',  '#pUnitCostFb'],
      purchaseunit:     ['#pPurchase',  '#pPurchaseFb'],
      salesunit:        ['#pSales',     '#pSalesFb'],
      conversionfactor: ['#pFactor',    '#pFactorFb'],
      wholesaleprice:   ['#pWholesale', '#pWholesaleFb'],
      retailprice:      ['#pRetail',    '#pRetailFb'],
      currentstock:     ['#pStock',     '#pStockFb'],
      expiredate:       ['#pExpire',    '#pExpireFb']
    };
    var DEFAULTS = {};
    $.each(FIELDS, function (key, pair) { DEFAULTS[key] = $(pair[1]).text(); });

    var RETAIL_LOW = 'Retail price cannot be lower than the wholesale price.';

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

    function updateHelper() {
      var pu = $.trim($('#pPurchase').val()) || 'purchase unit';
      var su = $.trim($('#pSales').val()) || 'sales unit';
      var f  = $.trim($('#pFactor').val()) || '?';
      $('#pFactorHelp').text('1 ' + pu + ' = ' + f + ' ' + su);
    }

    function openProductModal(d) {
      $('#productForm')[0].reset();
      clearInvalid();
      $('#pId').val(d ? d.productid : 0);
      $('#pName').val(d ? d.name : '');
      $('#pUnitCost').val(d ? Number(d.unitcost).toFixed(2) : '0');
      $('#pPurchase').val(d ? d.purchaseunit : 'Carton');
      $('#pSales').val(d ? d.salesunit : 'Piece');
      $('#pFactor').val(d ? String(d.conversionfactor) : '1');
      $('#pWholesale').val(d ? Number(d.wholesaleprice).toFixed(2) : '0');
      $('#pRetail').val(d ? Number(d.retailprice).toFixed(2) : '0');
      $('#pStock').val(d ? d.currentstock : 0);
      $('#pExpire').val(d ? d.expiredate : '');
      $('#productModalTitle').text(d ? 'Edit Product' : 'Add Product');
      updateHelper();
      $('#productModal').modal('show');
    }

    $('#btnAdd').on('click', function () { openProductModal(null); });
    $('#productsTable tbody').on('click', '.btn-edit', function () { openProductModal(rowData(this)); });
    $('#productModal').on('shown.bs.modal', function () { $('#pName').trigger('focus'); });
    $('#productForm input').on('input', function () { $(this).removeClass('is-invalid'); });
    $('#pPurchase, #pSales, #pFactor').on('input', updateHelper);

    /* ---------- Client validation (the server repeats every rule) ---------- */
    function cents(s) {
      var p = s.split('.');
      return parseInt(p[0], 10) * 100 + parseInt((p[1] || '').padEnd(2, '0'), 10);
    }

    function validate(v) {
      if (v.name === '') { return ['name', null]; }
      if (!/^\d{1,7}(\.\d{1,2})?$/.test(v.unitcost)) { return ['unitcost', null]; }
      if (v.purchaseunit === '') { return ['purchaseunit', null]; }
      if (v.salesunit === '') { return ['salesunit', null]; }
      if (!/^\d{1,6}(\.\d{1,4})?$/.test(v.conversionfactor) || !/[1-9]/.test(v.conversionfactor)) { return ['conversionfactor', null]; }
      if (!/^\d{1,7}(\.\d{1,2})?$/.test(v.wholesaleprice)) { return ['wholesaleprice', null]; }
      if (!/^\d{1,7}(\.\d{1,2})?$/.test(v.retailprice)) { return ['retailprice', null]; }
      if (cents(v.retailprice) < cents(v.wholesaleprice)) { return ['retailprice', RETAIL_LOW]; }
      if (!/^\d+$/.test(v.currentstock) || Number(v.currentstock) > CFG.maxStock) { return ['currentstock', null]; }
      return null;
    }

    /* ---------- Save (add / edit) ---------- */
    $('#productForm').on('submit', function (e) {
      e.preventDefault();
      clearInvalid();

      var values = {
        name:             $.trim($('#pName').val()),
        unitcost:         $.trim($('#pUnitCost').val()),
        purchaseunit:     $.trim($('#pPurchase').val()),
        salesunit:        $.trim($('#pSales').val()),
        conversionfactor: $.trim($('#pFactor').val()),
        wholesaleprice:   $.trim($('#pWholesale').val()),
        retailprice:      $.trim($('#pRetail').val()),
        currentstock:     $.trim($('#pStock').val()),
        expiredate:       $.trim($('#pExpire').val())
      };

      $.each(['unitcost', 'wholesaleprice', 'retailprice', 'currentstock'], function (i, k) {
        if (values[k] === '') { values[k] = '0'; }
      });

      var problem = validate(values);
      if (problem) {
        markInvalid(problem[0], problem[1]);
        return;
      }

      var $btn = $('#btnSaveProduct').prop('disabled', true);
      api($.extend({ action: 'save', productid: $('#pId').val() }, values)).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#productModal').modal('hide');
          table.ajax.reload(null, false);
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

    $('#productsTable tbody').on('click', '.btn-del', function () {
      var d = rowData(this);
      deleteId = d.productid;
      $('#delName').text(d.name);
      $('#deleteModal').modal('show');
    });

    $('#btnConfirmDelete').on('click', function () {
      var $btn = $(this).prop('disabled', true);
      api({ action: 'delete', productid: deleteId }).done(function (res) {
        $('#deleteModal').modal('hide');
        if (res.success) {
          toastr.success(res.message);
          table.ajax.reload(null, false);
        } else if (res.blocked) {
          toastr.warning(res.message);
        } else {
          toastr.error(res.message);
        }
      }).fail(function () {
        $('#deleteModal').modal('hide');
      }).always(function () { $btn.prop('disabled', false); });
    });

    /* ================================================================
     * Purchase Product (exact money math with BigInt, no floats)
     * ============================================================== */
    var RE_QTY    = /^\d{1,6}(\.\d{1,4})?$/;
    var RE_PRICE  = /^\d{1,7}(\.\d{1,2})?$/;
    var RE_PAID   = /^\d{1,9}(\.\d{1,2})?$/;
    var MAX_TOTAL = 99999999999n;
    var MAX_COST  = 999999999n;

    function fmtInt(n) { return Number(n).toLocaleString('en-US'); }
    function trimZeros(s) { s = String(s); return s.indexOf('.') >= 0 ? s.replace(/0+$/, '').replace(/\.$/, '') : s; }

    function toMinor(s, scale) {
      var p = String(s).split('.');
      return BigInt(p[0]) * (10n ** BigInt(scale)) + BigInt((p[1] || '').padEnd(scale, '0'));
    }
    // Signed decimal text from the server ("-5.50", "1234.50") to BigInt minor units (scale 2)
    function toMinorSigned(s) {
      s = String(s == null ? '0' : s);
      var neg = s.charAt(0) === '-';
      var m = toMinor(neg ? s.substring(1) : s, 2);
      return neg ? -m : m;
    }
    // BigInt minor units (scale 2) to "$1,234.50" (negative: "-$5.00")
    function minorMoney(m) {
      var neg = m < 0n;
      var a = neg ? -m : m;
      var whole = (a / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      return (neg ? '-' : '') + '$' + whole + '.' + (a % 100n).toString().padStart(2, '0');
    }

    var PL = { suppliers: [], products: [], accounts: [] };
    var PPROD = {};
    var PACC  = {};
    var puSaving = false;
    var puOpening = false;
    var puBalanceFlagged = false;
    var puBalanceBlocked = false;

    function indexPurchaseLookups() {
      PPROD = {};
      PACC  = {};
      $.each(PL.products || [], function (i, r) { PPROD[String(r.productid)] = r; });
      $.each(PL.accounts || [], function (i, r) {
        PACC[String(r.accountid)] = { balance: toMinorSigned(r.balance), label: r.code + ' ' + r.accountname };
      });
    }

    /* ---------- Field map (server field keys) ---------- */
    var PU_FIELDS = {
      supplierid:        ['#puSupplier', '#puSupplierFb'],
      purchasedate:      ['#puDate',     '#puDateFb'],
      reference:         ['#puRef',      '#puRefFb'],
      productid:         ['#puProduct',  '#puProductFb'],
      quantity:          ['#puQty',      '#puQtyFb'],
      unitprice:         ['#puPrice',    '#puPriceFb'],
      paidamount:        ['#puPaid',     '#puPaidFb'],
      paidfromaccountid: ['#puAccount',  '#puAccountFb'],
      note:              ['#puNote',     '#puNoteFb']
    };
    var PU_DEFAULTS = {};
    $.each(PU_FIELDS, function (key, pair) { PU_DEFAULTS[key] = $(pair[1]).text(); });

    function puResetField(key) {
      $(PU_FIELDS[key][0]).removeClass('is-invalid');
      $(PU_FIELDS[key][1]).text(PU_DEFAULTS[key]);
    }

    function puClearInvalid() {
      $.each(PU_FIELDS, function (key) { puResetField(key); });
      puBalanceFlagged = false;
    }

    function puMark(key, message) {
      if (message) { $(PU_FIELDS[key][1]).text(message); }
      $(PU_FIELDS[key][0]).addClass('is-invalid').trigger('focus');
    }

    function puShowServerError(field, message) {
      if (field && PU_FIELDS[field]) { puMark(field, message); }
    }

    /* ---------- Money and stock math (same rules as the server and pages/purchases.php) ---------- */
    function puQtyOk(qty) { return RE_QTY.test(qty) && /[1-9]/.test(qty); }

    // Line total in minor units (quantity scale 4 x price scale 2, half up to 2 decimals); null when a field is not valid yet
    function puLineTotal(qty, price) {
      if (!puQtyOk(qty) || !RE_PRICE.test(price)) { return null; }
      return (toMinor(qty, 4) * toMinor(price, 2) + 5000n) / 10000n;
    }

    // Sales units added by the quantity (quantity x conversion factor, half up); null when the quantity is not valid yet
    function puAddedUnits(p, qty) {
      if (!p || !puQtyOk(qty)) { return null; }
      return (toMinor(qty, 4) * toMinor(p.conversionfactor, 4) + 50000000n) / 100000000n;
    }

    /* ---------- Available balance of the paying account ---------- */
    function puAvailableMinor() {
      var acc = String($('#puAccount').val() || '');
      if (!acc || !PACC[acc]) { return null; }
      return PACC[acc].balance;
    }

    function puBalanceMessage(paid) {
      var acc = String($('#puAccount').val() || '');
      var avail = puAvailableMinor();
      if (paid <= 0n || avail === null || paid <= avail) { return ''; }
      return 'Insufficient balance in ' + PACC[acc].label + ': available ' + minorMoney(avail) + ', required ' + minorMoney(paid) + '.';
    }

    /* ---------- Live recalculation ---------- */
    function puRecalc() {
      var p     = PPROD[String($('#puProduct').val() || '')];
      var qty   = $.trim($('#puQty').val());
      var price = $.trim($('#puPrice').val());
      var line  = puLineTotal(qty, price);
      var total = line === null ? 0n : line;

      var paidRaw = $.trim($('#puPaid').val()) || '0';
      var paid    = RE_PAID.test(paidRaw) ? toMinor(paidRaw, 2) : 0n;
      var bal     = total - paid;

      $('#puQtyUnit').text(p ? p.purchaseunit : 'purchase unit');
      $('#puPriceUnit').text(p ? p.purchaseunit : 'purchase unit');
      $('#puTotal').val(minorMoney(total));
      $('#puFootTotal').text(minorMoney(total));
      $('#puFootPaid').text(minorMoney(paid));
      $('#puFootBalance').text(minorMoney(bal < 0n ? 0n : bal));
      $('#puAccountReq').prop('hidden', paid <= 0n);
      $('#puAccount').attr('aria-required', paid > 0n ? 'true' : 'false');

      if (p) {
        $('#puProductHelp').text('1 ' + p.purchaseunit + ' = ' + trimZeros(p.conversionfactor) + ' ' + p.salesunit + '. On hand: ' + fmtInt(p.currentstock) + ' ' + p.salesunit + '.');
      } else {
        $('#puProductHelp').text('');
      }
      var added = puAddedUnits(p, qty);
      if (added !== null) {
        $('#puQtyHelp').text('This purchase adds ' + fmtInt(added) + ' ' + p.salesunit + '. Stock after purchase: ' + fmtInt(BigInt(p.currentstock) + added) + ' ' + p.salesunit + '.');
      } else {
        $('#puQtyHelp').text('');
      }

      var avail = puAvailableMinor();
      $('#puAccountHelp').text(avail === null ? '' : 'Available balance: ' + minorMoney(avail));

      var msg = puBalanceMessage(paid);
      puBalanceBlocked = msg !== '';
      if (msg) {
        $('#puPaid').addClass('is-invalid');
        $('#puPaidFb').text(msg);
      } else if (puBalanceFlagged) {
        $('#puPaid').removeClass('is-invalid');
        $('#puPaidFb').text(PU_DEFAULTS.paidamount);
      }
      puBalanceFlagged = puBalanceBlocked;
      $('#btnSavePurchase').prop('disabled', puBalanceBlocked || puSaving);
    }

    $('#puSupplier, #puDate, #puRef, #puNote').on('input change', function () {
      var id = '#' + this.id;
      $.each(PU_FIELDS, function (key, pair) { if (pair[0] === id) { puResetField(key); } });
    });
    $('#puProduct').on('change', function () { puResetField('productid'); puResetField('quantity'); puResetField('unitprice'); puRecalc(); });
    $('#puQty').on('input', function () { puResetField('quantity'); puRecalc(); });
    $('#puPrice').on('input', function () { puResetField('unitprice'); puRecalc(); });
    $('#puPaid').on('input', function () {
      $(this).removeClass('is-invalid');
      $('#puPaidFb').text(PU_DEFAULTS.paidamount);
      puRecalc();
    });
    $('#puAccount').on('change', function () { puResetField('paidfromaccountid'); puRecalc(); });
    $('#purchaseModal').on('shown.bs.modal', function () { $('#puSupplier').trigger('focus'); });

    /* ---------- Open the modal ---------- */
    function puFillSelects() {
      var s = '<option value="">Select supplier</option>';
      $.each(PL.suppliers || [], function (i, r) { s += '<option value="' + Number(r.supplierid) + '">' + esc(r.name) + '</option>'; });
      $('#puSupplier').html(s);

      var l = '<option value="">Select product</option>';
      $.each(PL.products || [], function (i, r) { l += '<option value="' + Number(r.productid) + '">' + esc(r.name) + '</option>'; });
      $('#puProduct').html(l);

      var a = '<option value="">Select account</option>';
      $.each(PL.accounts || [], function (i, r) { a += '<option value="' + Number(r.accountid) + '">' + esc(r.code + ' ' + r.accountname) + '</option>'; });
      $('#puAccount').html(a);

      indexPurchaseLookups();
    }

    function showPurchaseModal(productId) {
      $('#purchaseForm')[0].reset();
      puClearInvalid();
      puFillSelects();
      puSaving = false;
      $('#puDate').val(ymd(new Date()));
      $('#puPrice').val('0');
      $('#puPaid').val('0');
      if (productId && PPROD[String(productId)]) { $('#puProduct').val(String(productId)); }
      puRecalc();
      $('#purchaseModal').modal('show');
    }

    // Reload the lookups (fresh account balances and stock) without touching the open form
    function puRefreshLookups() {
      return api({ action: 'purchaselookups' }).done(function (res) {
        if (res.success) { PL = res.data; indexPurchaseLookups(); puRecalc(); }
      });
    }

    function openPurchaseModal(productId) {
      if (puOpening) { return; }
      puOpening = true;
      api({ action: 'purchaselookups' }).done(function (res) {
        if (!res.success) { toastr.error(res.message); return; }
        PL = res.data;
        showPurchaseModal(productId);
      }).always(function () { puOpening = false; });
    }

    $('#btnPurchase').on('click', function () { openPurchaseModal(0); });
    $('#productsTable tbody').on('click', '.btn-buy', function () { openPurchaseModal(rowData(this).productid); });

    /* ---------- Client validation (the server repeats every rule) ---------- */
    function puValidate() {
      puClearInvalid();

      if (!$('#puSupplier').val()) { puMark('supplierid', null); return false; }

      var date = $.trim($('#puDate').val());
      var real = /^\d{4}-\d{2}-\d{2}$/.test(date) && ymd(new Date(date + 'T00:00:00')) === date;
      if (!real) { puMark('purchasedate', null); return false; }

      var pid = String($('#puProduct').val() || '');
      if (!pid) { puMark('productid', null); return false; }
      var p = PPROD[pid];

      var qty = $.trim($('#puQty').val());
      if (!puQtyOk(qty)) { puMark('quantity', 'Quantity must be greater than 0, with up to 4 decimals.'); return false; }

      var price = $.trim($('#puPrice').val());
      if (!RE_PRICE.test(price)) { puMark('unitprice', null); return false; }

      var total = puLineTotal(qty, price);
      if (total > MAX_TOTAL) { puMark('quantity', 'The purchase total is too large.'); return false; }

      if (p) {
        var factor = toMinor(p.conversionfactor, 4);
        if (factor <= 0n) { puMark('quantity', 'The stock to add is too large.'); return false; }
        var cost = (toMinor(price, 2) * 20000n + factor) / (2n * factor);
        if (cost > MAX_COST) { puMark('unitprice', 'The cost of one sales unit is too large.'); return false; }
        var added = puAddedUnits(p, qty);
        if (added > BigInt(CFG.maxStock) || BigInt(p.currentstock) + added > BigInt(CFG.maxStock)) {
          puMark('quantity', 'Cannot purchase: ' + p.name + ' stock would exceed ' + fmtInt(CFG.maxStock) + '.');
          return false;
        }
      }

      var paidRaw = $.trim($('#puPaid').val()) || '0';
      if (!RE_PAID.test(paidRaw)) { puMark('paidamount', null); return false; }
      var paid = toMinor(paidRaw, 2);
      if (paid > total) { puMark('paidamount', 'Paid amount cannot be more than the total.'); return false; }
      if (paid > 0n && !$('#puAccount').val()) { puMark('paidfromaccountid', null); return false; }
      var balMsg = puBalanceMessage(paid);
      if (balMsg) { puBalanceFlagged = true; puMark('paidamount', balMsg); return false; }
      return true;
    }

    /* ---------- Save purchase ---------- */
    $('#purchaseForm').on('submit', function (e) {
      e.preventDefault();
      if (puSaving || !puValidate()) { return; }

      puSaving = true;
      $('#btnSavePurchase').prop('disabled', true);
      api({
        action: 'purchasesave',
        supplierid: $('#puSupplier').val(),
        purchasedate: $.trim($('#puDate').val()),
        reference: $.trim($('#puRef').val()),
        productid: $('#puProduct').val(),
        quantity: $.trim($('#puQty').val()),
        unitprice: $.trim($('#puPrice').val()),
        paidamount: $.trim($('#puPaid').val()) || '0',
        paidfromaccountid: $('#puAccount').val() || 0,
        note: $.trim($('#puNote').val())
      }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#purchaseModal').modal('hide');
          table.ajax.reload(null, false);
          puRefreshLookups();
          return;
        }
        if (res.field === 'paidamount' && /^Insufficient/.test(res.message || '')) { puBalanceFlagged = true; }
        puShowServerError(res.field, res.message);
        toastr.error(res.message);
        if (res.field === 'paidamount' || res.field === 'quantity') { puRefreshLookups(); }
      }).always(function () {
        puSaving = false;
        $('#btnSavePurchase').prop('disabled', puBalanceBlocked);
      });
    });
</script>
</body>
</html>