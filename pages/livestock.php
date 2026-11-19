<?php

declare(strict_types=1);

/**
 * pages/livestock.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: Livestock list (sidebar > Livestock).
 *
 * Stock approach: stored integer column livestock.currentstock, set by Add / Edit (0 to 9,999,999).
 * A purchase made from this page adds its head count to currentstock (same rule as pages/purchases.php).
 * A sale made from this page takes its head count out of currentstock (never below 0).
 * A return of a sale puts its head count back into currentstock (never above 9,999,999).
 * Deleting an invoice puts back (animals sold minus animals already returned).
 * A quotation never changes stock. A refund never changes stock.
 *
 * Price names (the ONLY three used in the database, the backend and the frontend):
 *   Purchase Cost ($) = purchasecost  total paid for all animals of a purchase (typed in the Purchase form)
 *   Cost Each ($)     = costeach      Purchase Cost / Quantity, rounded half up to 2 decimals (the server recomputes it)
 *   Selling Price ($) = sellingprice  price you sell one animal for (typed)
 *
 * Columns: livestockid, name, type, currentstock, costeach, sellingprice, stage.
 *   costeach     = cost of one animal (0.00 to 9,999,999.99); set by Add / Edit and by the latest purchase
 *   sellingprice = selling price of one animal (0.00 to 9,999,999.99); set by Add / Edit and by the purchase form
 *
 * Required schema (already applied; see the SQL of PART 0, the ALTER below is only a reminder):
 *   ALTER TABLE `livestock`
 *     ADD COLUMN `costeach`  DECIMAL(9,2) NOT NULL DEFAULT 0.00 AFTER `currentstock`,
 *     ADD COLUMN `sellingprice`DECIMAL(9,2) NOT NULL DEFAULT 0.00 AFTER `costeach`;
 * Required tables for the sale and quotation modals: quotations, quotationitems, receipts
 * (see bushrafarmingandlivestock_quotations_receipts.sql).
 * Required tables for the invoice, return and refund modals: salereturns, salereturnitems, salerefunds
 * (see the SQL that came with this page). All table and column names are lowercase.
 *
 * One page, fifteen AJAX actions (POST, JSON): list, save, delete, purchaselookups, purchasesave,
 * salelookups, salesave, quotationlookups, quotationsave, saleslist, saleget, returnsave, refundlookups,
 * refundsave, invoicedelete.
 * Same shell as pages/dashboard.php, same architecture as the Dalsan customers page.
 * There is no invoices.php and no quotations.php: the invoice, the sales list, returns, refunds and the
 * invoice delete are modals of this page.
 *
 * PURCHASE LIVESTOCK (livestock only, one line per purchase):
 *   Tables used (single tenant, all names lowercase, same as pages/purchases.php):
 *     purchases     (purchaseid, supplierid, purchasedate, reference, totalamount, paidamount, paidfromaccountid, note)
 *     purchaseitems (purchaseitemid, purchaseid, itemtype, livestockid, productid, quantity, costeach, sellingprice, purchasecost, stockadded)
 *     suppliers     (supplierid, name)
 *     accounts      (accountid, parentaccountid, code, accountname, accounttype, status)
 *     payments      (optional, see BF_PAYMENTS): a missing table or column is logged and skipped.
 *     Optional tables for the available balance of the paying account (same formula as pages/chartofaccounts.php):
 *       receipts.accountid, payments.accountid, accounttransfers.fromaccountid / toaccountid, expenses.paidfromaccountid,
 *       salerefunds.accountid
 *       (each needs an amount column; a missing table or column is logged and counts as 0).
 *
 * ADD NEW SALE (livestock only, one line per sale, written as an invoice):
 *     invoices     (invoiceid, customerid, invoicedate, reference, totalamount, paidamount, receivedinaccountid, note)
 *     invoiceitems (invoiceitemid, invoiceid, itemtype, livestockid, productid, quantity, sellingprice, costeach, linetotal)
 *     customers    (customerid, name, primarycontact, address)
 *     receipts     (optional, see BF_RECEIPTS): a missing table or column is logged and skipped.
 *   Database triggers keep invoices (receivedinaccountid when paidamount > 0) and invoiceitems (itemtype) valid.
 *
 * INVOICE, SALES LIST, RETURN, REFUND, DELETE INVOICE (all modals of this page):
 *     salereturns     (returnid, invoiceid, customerid, returndate, totalamount, note)
 *     salereturnitems (returnitemid, returnid, invoiceitemid, livestockid, quantity, sellingprice, linetotal)
 *     salerefunds     (refundid, invoiceid, returnid, customerid, accountid, amount, refunddate, note)
 *   Net total   = invoice total minus the value of its returns.
 *   Balance due = net total minus (paid minus refunded), never below 0 (an overpaid invoice shows a credit).
 *   invoices.totalamount and invoices.paidamount are never changed: returns and refunds are separate rows.
 *   Delete invoice removes the refunds, returns, return lines, the automatic receipt, the lines and the invoice in one
 *   transaction. It is blocked when the invoice has product lines or receipts entered on the Receipts page, or when
 *   removing its receipt would make the receiving account balance negative.
 *
 * MAKE QUOTATION (livestock only, 1 to 200 lines, no stock change):
 *     quotations     (quotationid, customerid, quotationdate, validuntil, reference, totalamount, status, note)
 *     quotationitems (quotationitemid, quotationid, livestockid, quantity, sellingprice, linetotal)
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call the auth helpers of ../db.php right after the
 * "NO LOGIN" marker in the request flow below (and pass the user id to dt_log_livestock() / dt_log_purchase() /
 * dt_log_sale() / dt_log_quotation() / dt_log_salereturn() / dt_log_salerefund()).
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

const DT_MAX_NAME   = 150;
const DT_MAX_TYPE   = 100;
const DT_MAX_STOCK  = 9999999;
const DT_MAX_STAGE  = 50;
const DT_MAX_MONEY  = '9,999,999.99'; // display text of the largest cost each / selling price (DECIMAL(9,2))

const DT_MAX_REFERENCE = 50;
const DT_MAX_NOTE      = 255;
const DT_MAX_LINES     = 200;

const DT_QTY_INT_DIGITS   = 6;  // 999,999 animals per purchase, sale or quotation line (same limit as pages/purchases.php)
const DT_QTY_SCALE        = 4;  // purchaseitems.quantity is decimal(10,4)
const DT_PRICE_INT_DIGITS = 7;  // 9,999,999.99
const DT_PRICE_SCALE      = 2;
const DT_TOTAL_INT_DIGITS = 9;  // 999,999,999.99 (decimal 11,2)
const DT_MAX_TOTAL_MINOR  = 99999999999;
const DT_MAX_PRICE_MINOR  = 999999999; // 9,999,999.99 in minor units (largest cost each / selling price)

/**
 * Cost Each = Purchase Cost / Quantity, rounded half up to 2 decimals. Integer minor units only, no float.
 *
 * @param int $costMinor Purchase cost in minor units (scale 2).
 * @param int $qty       Quantity (head count), greater than 0.
 *
 * @return int Cost each in minor units (scale 2).
 */
function dt_cost_each(int $costMinor, int $qty): int
{
    return intdiv(2 * $costMinor + $qty, 2 * $qty);
}

const DT_QUOTATION_STATUSES = ['Draft', 'Sent', 'Accepted', 'Rejected', 'Expired'];

const BF_WALKIN_NAME = 'Walk-in'; // name of the customers row used for unregistered customers
const DT_MAX_WALKIN  = 100;       // maximum length of the optional walk-in name

const BF_NO_SUPPLIER_NAME = 'None'; // name of the suppliers row used for purchases without a supplier

const DT_LOGO_FILE = 'bushrofarmingandlivestocklogo.png'; // preferred logo file name
const DT_LOGO_DIRS = ['uploads', 'assets', 'images'];     // folders next to pages/ searched for the logo (in this order)

/** Table and column names of the livestock table (fixed values, never user input). Fix a name here if your schema differs. */
const BF_LIVESTOCK = [
    'table'  => 'livestock',
    'id'     => 'livestockid',
    'name'   => 'name',
    'type'   => 'type',
    'stock'  => 'currentstock',
    'cost'   => 'costeach',
    'price'  => 'sellingprice',
    'stage'   => 'stage',
];

/** Max lengths of the contact and address of a quick supplier or customer (the name reuses DT_MAX_NAME). */
const DT_MAX_CONTACT = 50;
const DT_MAX_ADDRESS = 255;

/** Table and column names of the livestock types lookup table (fixed values, never user input). */
const BF_LIVESTOCKTYPES = [
    'table' => 'livestocktypes',
    'id'    => 'livestocktypeid',
    'name'  => 'name',
];

/** Table and column names of the livestock statuses lookup table (fixed values, never user input). */
const BF_LIVESTOCKSTAGES = [
    'table' => 'livestockstages',
    'id'    => 'livestockstageid',
    'name'  => 'name',
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
    'table'        => 'purchaseitems',
    'id'           => 'purchaseitemid',
    'purchaseid'   => 'purchaseid',
    'itemtype'     => 'itemtype',
    'livestockid'  => 'livestockid',
    'productid'    => 'productid',
    'quantity'     => 'quantity',
    'costeach'     => 'costeach',
    'sellingprice' => 'sellingprice',
    'purchasecost' => 'purchasecost',
    'stockadded'   => 'stockadded',
];

/** Old version, never used (delete it when you want). */
const BF_PURCHASEITEMS_OLD = [
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

/** Table and column names of the invoices table (fixed values, never user input). A sale is one invoice. */
const BF_INVOICES = [
    'table'      => 'invoices',
    'id'         => 'invoiceid',
    'customerid' => 'customerid',
    'date'       => 'invoicedate',
    'reference'  => 'reference',
    'total'      => 'totalamount',
    'paid'       => 'paidamount',
    'account'    => 'receivedinaccountid',
    'note'       => 'note',
];

/** Table and column names of the invoice lines table (fixed values, never user input). */
const BF_INVOICEITEMS = [
    'table'        => 'invoiceitems',
    'id'           => 'invoiceitemid',
    'invoiceid'    => 'invoiceid',
    'itemtype'     => 'itemtype',
    'livestockid'  => 'livestockid',
    'productid'    => 'productid',
    'quantity'     => 'quantity',
    'sellingprice' => 'sellingprice',
    'costeach'     => 'costeach',
    'linetotal'    => 'linetotal',
];

/** Old version, never used (delete it when you want). */
const BF_INVOICEITEMS_OLD = [
    'table'       => 'invoiceitems',
    'id'          => 'invoiceitemid',
    'invoiceid'   => 'invoiceid',
    'itemtype'    => 'itemtype',
    'livestockid' => 'livestockid',
    'productid'   => 'productid',
    'quantity'    => 'quantity',
    'unitprice'   => 'unitprice',
    'unitcost'    => 'unitcost',
    'linetotal'   => 'linetotal',
];

/** Table and column names of the sale returns table (fixed values, never user input). */
const BF_SALERETURNS = [
    'table'      => 'salereturns',
    'id'         => 'returnid',
    'invoiceid'  => 'invoiceid',
    'customerid' => 'customerid',
    'date'       => 'returndate',
    'total'      => 'totalamount',
    'note'       => 'note',
];

/** Table and column names of the sale return lines table (fixed values, never user input). */
const BF_SALERETURNITEMS = [
    'table'         => 'salereturnitems',
    'id'            => 'returnitemid',
    'returnid'      => 'returnid',
    'invoiceitemid' => 'invoiceitemid',
    'livestockid'   => 'livestockid',
    'quantity'      => 'quantity',
    'sellingprice'  => 'sellingprice',
    'linetotal'     => 'linetotal',
];

/** Old version, never used (delete it when you want). */
const BF_SALERETURNITEMS_OLD = [
    'table'         => 'salereturnitems',
    'id'            => 'returnitemid',
    'returnid'      => 'returnid',
    'invoiceitemid' => 'invoiceitemid',
    'livestockid'   => 'livestockid',
    'quantity'      => 'quantity',
    'unitprice'     => 'unitprice',
    'linetotal'     => 'linetotal',
];

/** Table and column names of the sale refunds table (fixed values, never user input). */
const BF_SALEREFUNDS = [
    'table'      => 'salerefunds',
    'id'         => 'refundid',
    'invoiceid'  => 'invoiceid',
    'returnid'   => 'returnid',
    'customerid' => 'customerid',
    'accountid'  => 'accountid',
    'amount'     => 'amount',
    'date'       => 'refunddate',
    'note'       => 'note',
];

/** Table and column names of the quotations table (fixed values, never user input). */
const BF_QUOTATIONS = [
    'table'      => 'quotations',
    'id'         => 'quotationid',
    'customerid' => 'customerid',
    'date'       => 'quotationdate',
    'validuntil' => 'validuntil',
    'reference'  => 'reference',
    'total'      => 'totalamount',
    'status'     => 'status',
    'note'       => 'note',
];

/** Table and column names of the quotation lines table (fixed values, never user input). */
const BF_QUOTATIONITEMS = [
    'table'        => 'quotationitems',
    'id'           => 'quotationitemid',
    'quotationid'  => 'quotationid',
    'livestockid'  => 'livestockid',
    'quantity'     => 'quantity',
    'sellingprice' => 'sellingprice',
    'linetotal'    => 'linetotal',
];

/** Old version, never used (delete it when you want). */
const BF_QUOTATIONITEMS_OLD = [
    'table'       => 'quotationitems',
    'id'          => 'quotationitemid',
    'quotationid' => 'quotationid',
    'livestockid' => 'livestockid',
    'quantity'    => 'quantity',
    'unitprice'   => 'unitprice',
    'linetotal'   => 'linetotal',
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
 * Table and column names of the receipts table (fixed values, never user input). Fix a name here if your schema differs.
 * The row written by this page carries BF_RECEIPT_NOTE in the note column so it can be told apart from receipts
 * entered on the Receipts page. If the table or a column does not exist, the receipt row is skipped (logged).
 */
const BF_RECEIPTS = [
    'table'      => 'receipts',
    'customerid' => 'customerid',
    'invoiceid'  => 'invoiceid',
    'accountid'  => 'accountid',
    'amount'     => 'amount',
    'date'       => 'receiptdate',
    'note'       => 'note',
];

/** Exact note text that marks the automatic receipt row of a sale. */
const BF_RECEIPT_NOTE = 'Received with invoice';

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
    ['salerefunds', 'accountid', -1],
];

/**
 * Tables that reference the livestock id and block a delete: [table, column, noun].
 * Fixed whitelist (never user input). A table or column that does not exist is logged and skipped.
 */
const DT_LIVESTOCK_REFS = [
    ['purchaseitems', 'livestockid', 'purchase line'],
    ['invoiceitems', 'livestockid', 'invoice line'],
    ['quotationitems', 'livestockid', 'quotation line'],
    ['salereturnitems', 'livestockid', 'sale return line'],
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
        'menu'       => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'logout'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'caret'      => '<polyline points="6 9 12 15 18 9"/>',
    ];

    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
}

/* ================================================================
 * 3. SHARED BUSINESS RULES (read and validate, load and lock, unique name, count references, log)
 * ============================================================== */

/**
 * Validate a money field (cost each or selling price): digits with up to 2 decimals, 0 to 9,999,999.99.
 *
 * @param string $raw   Posted text.
 * @param string $label Field label for the message.
 * @param string $field Field key returned to the browser.
 *
 * @return string Normalized amount with exactly 2 decimals (for example "30.00").
 *
 * @throws DtFail When the value is not a valid amount.
 */
function dt_clean_money(string $raw, string $label, string $field): string
{
    $raw = trim($raw);
    if ($raw === '' || preg_match('/^\d{1,7}(\.\d{1,2})?$/', $raw) !== 1) {
        throw new DtFail($label . ' must be 0 or more, with up to 2 decimals.', false, $field);
    }

    return number_format((float)$raw, 2, '.', '');
}

/**
 * Read and validate the posted livestock fields (used by save).
 *
 * @return array{id:int,name:string,type:string,stock:int,cost:string,price:string,stage:string} Clean fields.
 *
 * @throws DtFail On invalid input.
 */
function dt_read_livestock_input(): array
{
    $id       = max(0, (int)($_POST['livestockid'] ?? 0));
    $name     = trim((string)($_POST['name'] ?? ''));
    $type     = trim((string)($_POST['type'] ?? ''));
    $rawStock = trim((string)($_POST['currentstock'] ?? ''));
    $stage    = trim((string)($_POST['stage'] ?? ''));

    if ($name === '') {
        throw new DtFail('Name is required.', false, 'name');
    }
    if (mb_strlen($name) > DT_MAX_NAME) {
        throw new DtFail('Name is too long (maximum ' . DT_MAX_NAME . ' characters).', false, 'name');
    }
    if ($type === '') {
        throw new DtFail('Type is required.', false, 'type');
    }
    if (mb_strlen($type) > DT_MAX_TYPE) {
        throw new DtFail('Type is too long (maximum ' . DT_MAX_TYPE . ' characters).', false, 'type');
    }
    if ($rawStock === '' || !ctype_digit($rawStock) || strlen($rawStock) > 10 || (int)$rawStock > DT_MAX_STOCK) {
        throw new DtFail('Current stock must be a whole number from 0 to ' . number_format(DT_MAX_STOCK) . '.', false, 'currentstock');
    }
    $cost  = dt_clean_money((string)($_POST['costeach'] ?? ''), 'Cost each', 'costeach');
    $price = dt_clean_money((string)($_POST['sellingprice'] ?? ''), 'Selling price', 'sellingprice');
    if ($stage === '') {
        throw new DtFail('Stage is required.', false, 'stage');
    }
    if (mb_strlen($stage) > DT_MAX_STAGE) {
        throw new DtFail('Stage is too long (maximum ' . DT_MAX_STAGE . ' characters).', false, 'stage');
    }

    return ['id' => $id, 'name' => $name, 'type' => $type, 'stock' => (int)$rawStock, 'cost' => $cost, 'price' => $price, 'stage' => $stage];
}

/** Old version, never called (delete it when you want). */
function dt_read_livestock_input_pre(): array
{
    $id       = max(0, (int)($_POST['livestockid'] ?? 0));
    $name     = trim((string)($_POST['name'] ?? ''));
    $type     = trim((string)($_POST['type'] ?? ''));
    $rawStock = trim((string)($_POST['currentstock'] ?? ''));
    $stage    = trim((string)($_POST['stage'] ?? ''));

    if ($name === '') {
        throw new DtFail('Name is required.', false, 'name');
    }
    if (mb_strlen($name) > DT_MAX_NAME) {
        throw new DtFail('Name is too long (maximum ' . DT_MAX_NAME . ' characters).', false, 'name');
    }
    if ($type === '') {
        throw new DtFail('Type is required.', false, 'type');
    }
    if (mb_strlen($type) > DT_MAX_TYPE) {
        throw new DtFail('Type is too long (maximum ' . DT_MAX_TYPE . ' characters).', false, 'type');
    }
    if ($rawStock === '' || !ctype_digit($rawStock) || strlen($rawStock) > 10 || (int)$rawStock > DT_MAX_STOCK) {
        throw new DtFail('Current stock must be a whole number from 0 to ' . number_format(DT_MAX_STOCK) . '.', false, 'currentstock');
    }
    $cost  = '0.00'; // the purchase sets Cost Each
    $price = '0.00'; // the purchase sets Selling Price
    if ($stage === '') {
        throw new DtFail('Stage is required.', false, 'stage');
    }
    if (mb_strlen($stage) > DT_MAX_STAGE) {
        throw new DtFail('Stage is too long (maximum ' . DT_MAX_STAGE . ' characters).', false, 'stage');
    }

    return ['id' => $id, 'name' => $name, 'type' => $type, 'stock' => (int)$rawStock, 'cost' => $cost, 'price' => $price, 'stage' => $stage];
}

/** Old version that read status, no longer called (delete it when you want). */
function dt_read_livestock_input_oldstatus(): array
{
    $id       = max(0, (int)($_POST['livestockid'] ?? 0));
    $name     = trim((string)($_POST['name'] ?? ''));
    $type     = trim((string)($_POST['type'] ?? ''));
    $rawStock = trim((string)($_POST['currentstock'] ?? ''));
    $status   = trim((string)($_POST['status'] ?? ''));

    if ($name === '') {
        throw new DtFail('Name is required.', false, 'name');
    }
    if (mb_strlen($name) > DT_MAX_NAME) {
        throw new DtFail('Name is too long (maximum ' . DT_MAX_NAME . ' characters).', false, 'name');
    }
    if ($type === '') {
        throw new DtFail('Type is required.', false, 'type');
    }
    if (mb_strlen($type) > DT_MAX_TYPE) {
        throw new DtFail('Type is too long (maximum ' . DT_MAX_TYPE . ' characters).', false, 'type');
    }
    if ($rawStock === '' || !ctype_digit($rawStock) || strlen($rawStock) > 10 || (int)$rawStock > DT_MAX_STOCK) {
        throw new DtFail('Current stock must be a whole number from 0 to ' . number_format(DT_MAX_STOCK) . '.', false, 'currentstock');
    }
    $cost  = '0.00'; // the purchase sets Cost Each
    $price = '0.00'; // the purchase sets Selling Price
    if ($status === '') {
        throw new DtFail('Stage is required.', false, 'status');
    }
    if (mb_strlen($status) > DT_MAX_STAGE) {
        throw new DtFail('Status is too long (maximum ' . DT_MAX_STAGE . ' characters).', false, 'status');
    }

    return ['id' => $id, 'name' => $name, 'type' => $type, 'stock' => (int)$rawStock, 'cost' => $cost, 'price' => $price, 'status' => $status];
}

/**
 * Load and lock (FOR UPDATE) a livestock row. Call inside a transaction.
 * The row also carries costeach and sellingprice (decimal text) for the sale; save, delete and purchasesave ignore them.
 *
 * @param PDO    $pdo   Connection.
 * @param int    $id    Livestock id.
 * @param string $field Field key returned to the browser when the row is not found (empty for none).
 *
 * @return array<string,mixed> Row (id, name, stock, costeach, sellingprice).
 *
 * @throws DtFail When not found.
 */
function dt_lock_livestock(PDO $pdo, int $id, string $field = ''): array
{
    $s   = BF_LIVESTOCK;
    $row = dt_q(
        $pdo,
        "SELECT `{$s['id']}` AS id, `{$s['name']}` AS name, `{$s['stock']}` AS stock, `{$s['cost']}` AS costeach, `{$s['price']}` AS sellingprice
           FROM `{$s['table']}` WHERE `{$s['id']}` = :id FOR UPDATE",
        ['id' => $id]
    )->fetch();
    if (!is_array($row)) {
        throw new DtFail('Livestock not found.', false, $field);
    }

    return $row;
}

/**
 * Make sure no other livestock row has the same name (case-insensitive).
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
    $s     = BF_LIVESTOCK;
    $count = (int)dt_q(
        $pdo,
        "SELECT COUNT(*) FROM `{$s['table']}` WHERE LOWER(`{$s['name']}`) = LOWER(:n) AND `{$s['id']}` <> :id",
        ['n' => $name, 'id' => $exceptId]
    )->fetchColumn();
    if ($count > 0) {
        throw new DtFail('A livestock item named "' . $name . '" already exists.', false, 'name');
    }
}

/**
 * Count the records that use a livestock item (see DT_LIVESTOCK_REFS).
 * A missing table or column (SQLSTATE 42S02 / 42S22) is logged and skipped; any other database error is rethrown.
 *
 * @param PDO $pdo Connection.
 * @param int $id  Livestock id.
 *
 * @return array<int,string> Texts such as "2 invoice lines", empty when the item is unused.
 *
 * @throws PDOException On a database error other than a missing table or column.
 */
function dt_count_references(PDO $pdo, int $id): array
{
    $used = [];
    foreach (DT_LIVESTOCK_REFS as [$table, $column, $noun]) {
        try {
            $count = (int)dt_q($pdo, "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :id", ['id' => $id])->fetchColumn();
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
                dt_log_error('[livestock refs] skipped ' . $table . '.' . $column . ': ' . dt_describe_throwable($e));
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
 * Write a livestock activity row (call before commit). Without login the user is unknown: userid is NULL ("System" on the dashboard).
 *
 * @param PDO    $pdo    Connection.
 * @param string $action Add | Update | Delete.
 * @param string $text   Description.
 *
 * @return void
 */
function dt_log_livestock(PDO $pdo, string $action, string $text): void
{
    dt_q(
        $pdo,
        'INSERT INTO activitylogs (userid, action, description, activitydate) VALUES (NULL, :a, :d, NOW())',
        ['a' => $action, 'd' => mb_substr($text, 0, 255)]
    );
}

/* ---------- Lookup rules (types, statuses) and quick add rules (supplier, customer) ---------- */

/**
 * Rows of a lookup table ordered by name.
 *
 * @param PDO                  $pdo Connection.
 * @param array<string,string> $cfg BF_LIVESTOCKTYPES or BF_LIVESTOCKSTAGES.
 *
 * @return list<array<string,mixed>> Rows: the id column (int) and name (string).
 */
function dt_lookup_rows(PDO $pdo, array $cfg): array
{
    $out = [];
    foreach (dt_q($pdo, "SELECT `{$cfg['id']}` AS id, `{$cfg['name']}` AS name FROM `{$cfg['table']}` ORDER BY `{$cfg['name']}` ASC")->fetchAll() as $row) {
        $out[] = [$cfg['id'] => (int)$row['id'], 'name' => (string)$row['name']];
    }

    return $out;
}

/**
 * Saved livestock types.
 *
 * @param PDO $pdo Connection.
 *
 * @return list<array<string,mixed>> Rows (livestocktypeid, name).
 */
function dt_lookup_types(PDO $pdo): array
{
    return dt_lookup_rows($pdo, BF_LIVESTOCKTYPES);
}

/**
 * Saved livestock statuses.
 *
 * @param PDO $pdo Connection.
 *
 * @return list<array<string,mixed>> Rows (livestockstageid, name).
 */
function dt_lookup_stages(PDO $pdo): array
{
    return dt_lookup_rows($pdo, BF_LIVESTOCKSTAGES);
}

/**
 * Validate the name of a lookup row: trimmed, required, not longer than $max.
 *
 * @param string $raw   Posted text.
 * @param int    $max   Maximum length.
 * @param string $label "Type" or "Status".
 *
 * @return string Clean name.
 *
 * @throws DtFail When empty or too long (field "name").
 */
function dt_clean_lookup_name(string $raw, int $max, string $label): string
{
    $name = trim($raw);
    if ($name === '') {
        throw new DtFail($label . ' name is required.', false, 'name');
    }
    if (mb_strlen($name) > $max) {
        throw new DtFail($label . ' name is too long (maximum ' . $max . ' characters).', false, 'name');
    }

    return $name;
}

/**
 * Make sure no lookup row has this name yet (case-insensitive).
 *
 * @param PDO                  $pdo  Connection.
 * @param array<string,string> $cfg  BF_LIVESTOCKTYPES or BF_LIVESTOCKSTAGES.
 * @param string               $name Name to check.
 * @param string               $noun "type" or "status" (for the message).
 *
 * @return void
 *
 * @throws DtFail When the name exists (field "name").
 */
function dt_assert_lookup_free(PDO $pdo, array $cfg, string $name, string $noun): void
{
    $count = (int)dt_q(
        $pdo,
        "SELECT COUNT(*) FROM `{$cfg['table']}` WHERE LOWER(`{$cfg['name']}`) = LOWER(:n)",
        ['n' => $name]
    )->fetchColumn();
    if ($count > 0) {
        throw new DtFail('A ' . $noun . ' named "' . $name . '" already exists.', false, 'name');
    }
}

/**
 * Make sure a lookup row with this name exists (case-insensitive). Call inside the transaction that uses the value.
 *
 * @param PDO                  $pdo     Connection.
 * @param array<string,string> $cfg     BF_LIVESTOCKTYPES or BF_LIVESTOCKSTAGES.
 * @param string               $name    Posted name.
 * @param string               $field   Field key for the browser ("type" or "status").
 * @param string               $message Message when missing.
 *
 * @return string The name as saved in the lookup table.
 *
 * @throws DtFail When the name is not in the lookup table.
 */
function dt_assert_lookup_exists(PDO $pdo, array $cfg, string $name, string $field, string $message): string
{
    $saved = dt_q(
        $pdo,
        "SELECT `{$cfg['name']}` FROM `{$cfg['table']}` WHERE LOWER(`{$cfg['name']}`) = LOWER(:n) LIMIT 1",
        ['n' => $name]
    )->fetchColumn();
    if ($saved === false) {
        throw new DtFail($message, false, $field);
    }

    return (string)$saved;
}

/**
 * Read and validate the posted fields of a quick supplier or customer (name, contact, address).
 * Duplicate names are allowed; only the reserved name is refused.
 *
 * @param string $label    "Supplier" or "Customer".
 * @param string $reserved BF_NO_SUPPLIER_NAME or BF_WALKIN_NAME (compared case-insensitively).
 *
 * @return array{name:string,contact:?string,address:?string} Clean fields.
 *
 * @throws DtFail On invalid input (field "name", "contact" or "address").
 */
function dt_clean_party_input(string $label, string $reserved): array
{
    $name    = trim((string)($_POST['name'] ?? ''));
    $contact = trim((string)($_POST['contact'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));

    if ($name === '') {
        throw new DtFail($label . ' name is required.', false, 'name');
    }
    if (mb_strlen($name) > DT_MAX_NAME) {
        throw new DtFail($label . ' name is too long (maximum ' . DT_MAX_NAME . ' characters).', false, 'name');
    }
    if (strcasecmp($name, $reserved) === 0) {
        throw new DtFail('The name "' . $reserved . '" is reserved. Choose another name.', false, 'name');
    }
    if (mb_strlen($contact) > DT_MAX_CONTACT) {
        throw new DtFail('Contact is too long (maximum ' . DT_MAX_CONTACT . ' characters).', false, 'contact');
    }
    if (mb_strlen($address) > DT_MAX_ADDRESS) {
        throw new DtFail('Address is too long (maximum ' . DT_MAX_ADDRESS . ' characters).', false, 'address');
    }

    return [
        'name'    => $name,
        'contact' => $contact === '' ? null : $contact,
        'address' => $address === '' ? null : $address,
    ];
}

/* ---------- Lookup CRUD rules (types, statuses): usage count, lock, rename, delete ---------- */

/**
 * Rows of a lookup table with the number of livestock items that use each name (case-insensitive).
 *
 * @param PDO                  $pdo             Connection.
 * @param array<string,string> $cfg             BF_LIVESTOCKTYPES or BF_LIVESTOCKSTAGES.
 * @param string               $livestockColumn BF_LIVESTOCK['type'] or ['status'] (fixed value, never user input).
 *
 * @return list<array<string,mixed>> Rows: the id column (int), name (string), used (int).
 */
function dt_lookup_usage_rows(PDO $pdo, array $cfg, string $livestockColumn): array
{
    $s   = BF_LIVESTOCK;
    $out = [];
    foreach (dt_q(
        $pdo,
        "SELECT c.`{$cfg['id']}` AS id, c.`{$cfg['name']}` AS name,
                (SELECT COUNT(*) FROM `{$s['table']}` l WHERE LOWER(l.`{$livestockColumn}`) = LOWER(c.`{$cfg['name']}`)) AS used
           FROM `{$cfg['table']}` c ORDER BY c.`{$cfg['name']}` ASC"
    )->fetchAll() as $row) {
        $out[] = [$cfg['id'] => (int)$row['id'], 'name' => (string)$row['name'], 'used' => (int)$row['used']];
    }

    return $out;
}

/**
 * Number of livestock items that use a type or Stage name (case-insensitive).
 *
 * @param PDO    $pdo             Connection.
 * @param string $livestockColumn BF_LIVESTOCK['type'] or ['status'].
 * @param string $name            Lookup name.
 *
 * @return int Count.
 */
function dt_count_lookup_usage(PDO $pdo, string $livestockColumn, string $name): int
{
    $s = BF_LIVESTOCK;

    return (int)dt_q(
        $pdo,
        "SELECT COUNT(*) FROM `{$s['table']}` WHERE LOWER(`{$livestockColumn}`) = LOWER(:n)",
        ['n' => $name]
    )->fetchColumn();
}

/**
 * Load and lock (FOR UPDATE) a lookup row. Call inside a transaction.
 *
 * @param PDO                  $pdo  Connection.
 * @param array<string,string> $cfg  BF_LIVESTOCKTYPES or BF_LIVESTOCKSTAGES.
 * @param int                  $id   Lookup id.
 * @param string               $noun "type" or "status".
 *
 * @return array<string,mixed> Row (id, name).
 *
 * @throws DtFail When not found ("Type not found." / "Status not found.").
 */
function dt_lock_lookup(PDO $pdo, array $cfg, int $id, string $noun): array
{
    $row = dt_q(
        $pdo,
        "SELECT `{$cfg['id']}` AS id, `{$cfg['name']}` AS name FROM `{$cfg['table']}` WHERE `{$cfg['id']}` = :id FOR UPDATE",
        ['id' => $id]
    )->fetch();
    if (!is_array($row)) {
        throw new DtFail(ucfirst($noun) . ' not found.');
    }

    return $row;
}

/**
 * Make sure no OTHER lookup row has this name (case-insensitive). The row itself is excluded,
 * so changing only the capital letters of a name is allowed.
 *
 * @param PDO                  $pdo      Connection.
 * @param array<string,string> $cfg      BF_LIVESTOCKTYPES or BF_LIVESTOCKSTAGES.
 * @param string               $name     New name.
 * @param string               $noun     "type" or "status".
 * @param int                  $exceptId Row to ignore.
 *
 * @return void
 *
 * @throws DtFail When the name is used by another row (field "name").
 */
function dt_assert_lookup_free_except(PDO $pdo, array $cfg, string $name, string $noun, int $exceptId): void
{
    $count = (int)dt_q(
        $pdo,
        "SELECT COUNT(*) FROM `{$cfg['table']}` WHERE LOWER(`{$cfg['name']}`) = LOWER(:n) AND `{$cfg['id']}` <> :id",
        ['n' => $name, 'id' => $exceptId]
    )->fetchColumn();
    if ($count > 0) {
        throw new DtFail('A ' . $noun . ' named "' . $name . '" already exists.', false, 'name');
    }
}

/**
 * Rename a lookup row and the livestock items that use the old name (one transaction).
 * Reads the posted id (under the id column name of $cfg) and the posted name. Never touches currentstock or other columns.
 *
 * @param PDO                  $pdo             Connection.
 * @param array<string,string> $cfg             BF_LIVESTOCKTYPES or BF_LIVESTOCKSTAGES.
 * @param string               $livestockColumn BF_LIVESTOCK['type'] or ['status'].
 * @param string               $noun            "type" or "status".
 * @param string               $label           "Type" or "Status".
 * @param int                  $max             Maximum name length.
 *
 * @return array<string,mixed> Payload with row (id, name, used) and updated (livestock rows changed).
 *
 * @throws DtFail On invalid input, a duplicate name or a missing row.
 */
function dt_lookup_update(PDO $pdo, array $cfg, string $livestockColumn, string $noun, string $label, int $max): array
{
    $id   = max(0, (int)($_POST[$cfg['id']] ?? 0));
    $name = dt_clean_lookup_name((string)($_POST['name'] ?? ''), $max, $label);

    return dt_transaction(static function (PDO $pdo) use ($cfg, $livestockColumn, $noun, $id, $name): array {
        $s = BF_LIVESTOCK;

        $row = dt_lock_lookup($pdo, $cfg, $id, $noun);
        $old = (string)$row['name'];
        dt_assert_lookup_free_except($pdo, $cfg, $name, $noun, $id);

        dt_q(
            $pdo,
            "UPDATE `{$cfg['table']}` SET `{$cfg['name']}` = :n WHERE `{$cfg['id']}` = :id",
            ['n' => $name, 'id' => $id]
        );
        $updated = dt_q(
            $pdo,
            "UPDATE `{$s['table']}` SET `{$livestockColumn}` = :n WHERE LOWER(`{$livestockColumn}`) = LOWER(:o)",
            ['n' => $name, 'o' => $old]
        )->rowCount();
        $used = dt_count_lookup_usage($pdo, $livestockColumn, $name);

        dt_log_livestock($pdo, 'Update', 'Livestock ' . $noun . ' updated: ' . $old . ' to ' . $name);

        return [
            'success' => true,
            'message' => ucfirst($noun) . ' updated successfully.',
            'row'     => [$cfg['id'] => $id, 'name' => $name, 'used' => $used],
            'updated' => (int)$updated,
        ];
    });
}

/**
 * Delete a lookup row unless livestock items use its name (one transaction).
 * Reads the posted id (under the id column name of $cfg).
 *
 * @param PDO                  $pdo             Connection.
 * @param array<string,string> $cfg             BF_LIVESTOCKTYPES or BF_LIVESTOCKSTAGES.
 * @param string               $livestockColumn BF_LIVESTOCK['type'] or ['status'].
 * @param string               $noun            "type" or "status".
 *
 * @return array<string,mixed> Payload with the deleted row (id, name).
 *
 * @throws DtFail When not found or still used (blocked).
 */
function dt_lookup_delete(PDO $pdo, array $cfg, string $livestockColumn, string $noun): array
{
    $id = max(0, (int)($_POST[$cfg['id']] ?? 0));

    return dt_transaction(static function (PDO $pdo) use ($cfg, $livestockColumn, $noun, $id): array {
        $row  = dt_lock_lookup($pdo, $cfg, $id, $noun);
        $name = (string)$row['name'];

        $used = dt_count_lookup_usage($pdo, $livestockColumn, $name);
        if ($used > 0) {
            throw new DtFail('Cannot delete ' . $noun . ' "' . $name . '": ' . $used . ' livestock item(s) use it.', true);
        }

        dt_q($pdo, "DELETE FROM `{$cfg['table']}` WHERE `{$cfg['id']}` = :id", ['id' => $id]);
        dt_log_livestock($pdo, 'Delete', 'Livestock ' . $noun . ' deleted: ' . $name);

        return ['success' => true, 'message' => ucfirst($noun) . ' deleted successfully.', 'row' => [$cfg['id'] => $id, 'name' => $name]];
    });
}

/* ---------- Purchase rules (copied from pages/purchases.php) ---------- */

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
 * @param string $field     Field key for the message.
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
 * Validate a required Y-m-d date.
 *
 * @param string $raw     Trimmed posted text.
 * @param string $message Message when invalid.
 * @param string $field   Field key for the message.
 *
 * @return string Date as Y-m-d.
 *
 * @throws DtFail When not a real date.
 */
function dt_parse_date(string $raw, string $message, string $field): string
{
    $date = DateTime::createFromFormat('!Y-m-d', $raw);
    if (!$date instanceof DateTime || $date->format('Y-m-d') !== $raw) {
        throw new DtFail($message, false, $field);
    }

    return $raw;
}

/**
 * Read and validate the posted purchase fields (used by purchasesave, before the transaction).
 * Totals are never trusted from the browser: the line total and the purchase total are computed here in minor units.
 *
 * @return array{supplierid:int,nosupplier:bool,date:string,reference:?string,note:?string,livestockid:int,qty:int,purchasecost:int,costeach:int,sellingprice:int,paid:int,accountid:int} Clean fields.
 *
 * @throws DtFail On invalid input.
 */
function dt_read_purchase_input(): array
{
    $rawSupplier = trim((string)($_POST['supplierid'] ?? ''));
    $none        = strtolower($rawSupplier) === 'none';
    $supplierId  = $none ? 0 : max(0, (int)$rawSupplier);
    $rawDate     = trim((string)($_POST['purchasedate'] ?? ''));
    $reference   = trim((string)($_POST['reference'] ?? ''));
    $note        = trim((string)($_POST['note'] ?? ''));
    $livestockId = max(0, (int)($_POST['livestockid'] ?? 0));
    $rawQty      = trim((string)($_POST['quantity'] ?? ''));
    $rawCost     = trim((string)($_POST['purchasecost'] ?? ''));
    $rawSelling  = trim((string)($_POST['sellingprice'] ?? ''));
    $rawPaid     = trim((string)($_POST['paidamount'] ?? ''));
    $accountId   = max(0, (int)($_POST['paidfromaccountid'] ?? 0));

    if (!$none && $supplierId <= 0) {
        throw new DtFail('Supplier is required.', false, 'supplierid');
    }
    $date = dt_parse_date($rawDate, 'Purchase date must be a valid date.', 'purchasedate');
    if (mb_strlen($reference) > DT_MAX_REFERENCE) {
        throw new DtFail('Reference is too long (maximum ' . DT_MAX_REFERENCE . ' characters).', false, 'reference');
    }
    if (mb_strlen($note) > DT_MAX_NOTE) {
        throw new DtFail('Note is too long (maximum ' . DT_MAX_NOTE . ' characters).', false, 'note');
    }
    if ($livestockId <= 0) {
        throw new DtFail('Select a livestock item.', false, 'livestockid');
    }
    if (!preg_match('/^\d{1,' . DT_QTY_INT_DIGITS . '}$/D', $rawQty) || (int)$rawQty <= 0) {
        throw new DtFail('Quantity must be a whole number greater than 0.', false, 'quantity');
    }
    $qty = (int)$rawQty;

    /* Purchase Cost is typed (total of all animals). Cost Each is never read from the browser: it is computed here. */
    $cost = dt_parse_decimal($rawCost, DT_TOTAL_INT_DIGITS, 2, false, 'Purchase cost must be 0 or more, with up to 2 decimals.', 'purchasecost');
    $each = dt_cost_each($cost, $qty);
    if ($each > DT_MAX_PRICE_MINOR) {
        throw new DtFail('Cost each is too large (maximum ' . DT_MAX_MONEY . '). Check the purchase cost and the quantity.', false, 'purchasecost');
    }
    $selling = dt_parse_decimal($rawSelling, DT_PRICE_INT_DIGITS, DT_PRICE_SCALE, false, 'Selling price must be 0 or more, with up to 2 decimals.', 'sellingprice');

    $paid = dt_parse_decimal(
        $rawPaid === '' ? '0' : $rawPaid,
        DT_TOTAL_INT_DIGITS,
        2,
        false,
        'Paid amount must be 0 or more, with up to 2 decimals.',
        'paidamount'
    );
    if ($paid > $cost) {
        throw new DtFail('Paid amount cannot be more than the purchase cost.', false, 'paidamount');
    }
    if ($none && $paid !== $cost) {
        throw new DtFail('A purchase without a supplier must be paid in full.', false, 'paidamount');
    }
    if ($paid > 0 && $accountId <= 0) {
        throw new DtFail('Select the account the payment came from.', false, 'paidfromaccountid');
    }

    return [
        'supplierid'   => $supplierId,
        'nosupplier'   => $none,
        'date'         => $date,
        'reference'    => $reference === '' ? null : $reference,
        'note'         => $note === '' ? null : $note,
        'livestockid'  => $livestockId,
        'qty'          => $qty,
        'purchasecost' => $cost,
        'total'        => $cost, // header purchases.totalamount = Purchase Cost
        'costeach'     => $each,
        'sellingprice' => $selling,
        'paid'         => $paid,
        'accountid'    => $paid > 0 ? $accountId : 0,
    ];
}

/** Old version, never called (delete it when you want). */
function dt_read_purchase_input_pre(): array
{
    $rawSupplier = trim((string)($_POST['supplierid'] ?? ''));
    $none        = strtolower($rawSupplier) === 'none';
    $supplierId  = $none ? 0 : max(0, (int)$rawSupplier);
    $rawDate     = trim((string)($_POST['purchasedate'] ?? ''));
    $reference   = trim((string)($_POST['reference'] ?? ''));
    $note        = trim((string)($_POST['note'] ?? ''));
    $livestockId = max(0, (int)($_POST['livestockid'] ?? 0));
    $rawQty      = trim((string)($_POST['quantity'] ?? ''));
    $rawPrice    = trim((string)($_POST['unitprice'] ?? ''));
    $rawPaid     = trim((string)($_POST['paidamount'] ?? ''));
    $accountId   = max(0, (int)($_POST['paidfromaccountid'] ?? 0));

    if (!$none && $supplierId <= 0) {
        throw new DtFail('Supplier is required.', false, 'supplierid');
    }
    $date = dt_parse_date($rawDate, 'Purchase date must be a valid date.', 'purchasedate');
    if (mb_strlen($reference) > DT_MAX_REFERENCE) {
        throw new DtFail('Reference is too long (maximum ' . DT_MAX_REFERENCE . ' characters).', false, 'reference');
    }
    if (mb_strlen($note) > DT_MAX_NOTE) {
        throw new DtFail('Note is too long (maximum ' . DT_MAX_NOTE . ' characters).', false, 'note');
    }
    if ($livestockId <= 0) {
        throw new DtFail('Select a livestock item.', false, 'livestockid');
    }
    if (!preg_match('/^\d{1,' . DT_QTY_INT_DIGITS . '}$/D', $rawQty) || (int)$rawQty <= 0) {
        throw new DtFail('Quantity must be a whole number greater than 0.', false, 'quantity');
    }
    $qty   = (int)$rawQty;
    $price = dt_parse_decimal($rawPrice, DT_PRICE_INT_DIGITS, DT_PRICE_SCALE, false, 'Price per animal must be 0 or more, with up to 2 decimals.', 'unitprice');

    $total = $qty * $price;
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
    if ($none && $paid !== $total) {
        throw new DtFail('A purchase without a supplier must be paid in full.', false, 'paidamount');
    }
    if ($paid > 0 && $accountId <= 0) {
        throw new DtFail('Select the account the payment came from.', false, 'paidfromaccountid');
    }

    return [
        'supplierid'  => $supplierId,
        'nosupplier'  => $none,
        'date'        => $date,
        'reference'   => $reference === '' ? null : $reference,
        'note'        => $note === '' ? null : $note,
        'livestockid' => $livestockId,
        'qty'         => $qty,
        'price'       => $price,
        'total'       => $total,
        'paid'        => $paid,
        'accountid'   => $paid > 0 ? $accountId : 0,
    ];
}

/** Old version without the "none" supplier, no longer called (delete it when you want). */
function dt_read_purchase_input_old(): array
{
    $supplierId  = max(0, (int)($_POST['supplierid'] ?? 0));
    $rawDate     = trim((string)($_POST['purchasedate'] ?? ''));
    $reference   = trim((string)($_POST['reference'] ?? ''));
    $note        = trim((string)($_POST['note'] ?? ''));
    $livestockId = max(0, (int)($_POST['livestockid'] ?? 0));
    $rawQty      = trim((string)($_POST['quantity'] ?? ''));
    $rawPrice    = trim((string)($_POST['unitprice'] ?? ''));
    $rawPaid     = trim((string)($_POST['paidamount'] ?? ''));
    $accountId   = max(0, (int)($_POST['paidfromaccountid'] ?? 0));

    if ($supplierId <= 0) {
        throw new DtFail('Supplier is required.', false, 'supplierid');
    }
    $date = dt_parse_date($rawDate, 'Purchase date must be a valid date.', 'purchasedate');
    if (mb_strlen($reference) > DT_MAX_REFERENCE) {
        throw new DtFail('Reference is too long (maximum ' . DT_MAX_REFERENCE . ' characters).', false, 'reference');
    }
    if (mb_strlen($note) > DT_MAX_NOTE) {
        throw new DtFail('Note is too long (maximum ' . DT_MAX_NOTE . ' characters).', false, 'note');
    }
    if ($livestockId <= 0) {
        throw new DtFail('Select a livestock item.', false, 'livestockid');
    }
    if (!preg_match('/^\d{1,' . DT_QTY_INT_DIGITS . '}$/D', $rawQty) || (int)$rawQty <= 0) {
        throw new DtFail('Quantity must be a whole number greater than 0.', false, 'quantity');
    }
    $qty   = (int)$rawQty;
    $price = dt_parse_decimal($rawPrice, DT_PRICE_INT_DIGITS, DT_PRICE_SCALE, false, 'Price per animal must be 0 or more, with up to 2 decimals.', 'unitprice');

    /* qty <= 999,999 and price <= 999,999,999 minor units: the product cannot overflow a 64-bit integer. */
    $total = $qty * $price;
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
        'supplierid'  => $supplierId,
        'date'        => $date,
        'reference'   => $reference === '' ? null : $reference,
        'note'        => $note === '' ? null : $note,
        'livestockid' => $livestockId,
        'qty'         => $qty,
        'price'       => $price,
        'total'       => $total,
        'paid'        => $paid,
        'accountid'   => $paid > 0 ? $accountId : 0,
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
 * receipts - payments + transfers in - transfers out - expenses paid from the account - sale refunds (see DT_BALANCE_SOURCES).
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
                dt_log_error('[livestock balances] skipped ' . $table . '.' . $column . ': ' . dt_describe_throwable($e));
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
            dt_log_error('[livestock payment] skipped ' . $s['table'] . ': ' . dt_describe_throwable($e));

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

/* ---------- None supplier, purchase list rules, quotation list rules, customer position ---------- */

/**
 * Id of the "None" supplier: the suppliers row named BF_NO_SUPPLIER_NAME (case-insensitive), created once when missing.
 * suppliers has only one NOT NULL column besides its id (name); contact and address are nullable, so they get NULL.
 * Call it only inside a transaction (same GET_LOCK pattern as dt_walkin_customer_id).
 *
 * @param PDO $pdo Connection.
 *
 * @return int Supplier id.
 *
 * @throws DtFail When the lock cannot be taken.
 */
function dt_none_supplier_id(PDO $pdo): int
{
    $find = static function (bool $lock) use ($pdo): int {
        $id = dt_q(
            $pdo,
            'SELECT supplierid FROM suppliers WHERE LOWER(name) = LOWER(:n) ORDER BY supplierid ASC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
            ['n' => BF_NO_SUPPLIER_NAME]
        )->fetchColumn();

        return $id === false ? 0 : (int)$id;
    };

    $id = $find(false);
    if ($id > 0) {
        return $id;
    }

    $key = 'bf_none_supplier';
    if ((int)dt_q($pdo, 'SELECT GET_LOCK(:k, 10)', ['k' => $key])->fetchColumn() !== 1) {
        throw new DtFail('Cannot prepare the None supplier. Please try again.');
    }
    register_shutdown_function(static function () use ($pdo, $key): void {
        try {
            dt_q($pdo, 'SELECT RELEASE_LOCK(:k)', ['k' => $key])->fetchColumn();
        } catch (Throwable $e) {
            dt_log_error('[livestock none supplier] release lock failed: ' . dt_describe_throwable($e));
        }
    });

    $id = $find(true);
    if ($id === 0) {
        dt_q($pdo, 'INSERT INTO suppliers (name, contact, address) VALUES (:n, NULL, NULL)', ['n' => BF_NO_SUPPLIER_NAME]);
        $id = (int)$pdo->lastInsertId();
    }

    return $id;
}

/**
 * Quantity text of a decimal(10,4) column without useless zeros ("60.0000" gives "60", "2.5000" gives "2.5").
 *
 * @param string $q Decimal text.
 *
 * @return string Short text.
 */
function dt_trim_qty(string $q): string
{
    return str_contains($q, '.') ? rtrim(rtrim($q, '0'), '.') : $q;
}

/**
 * SQL of one purchase row with its supplier name and the number of lines (all lines, livestock lines).
 *
 * @param string $tail SQL appended after the join (WHERE and ORDER BY), fixed text, never user input.
 *
 * @return string SQL with the named placeholder of the tail.
 */
function dt_purchase_sql(string $tail): string
{
    $p = BF_PURCHASES;
    $i = BF_PURCHASEITEMS;

    return "SELECT p.`{$p['id']}` AS purchaseid, p.`{$p['supplierid']}` AS supplierid, s.name AS suppliername,
                   p.`{$p['date']}` AS purchasedate, p.`{$p['reference']}` AS reference, p.`{$p['note']}` AS note,
                   p.`{$p['total']}` AS totalamount, p.`{$p['paid']}` AS paidamount, p.`{$p['account']}` AS accountid,
                   (SELECT COUNT(*) FROM `{$i['table']}` x WHERE x.`{$i['purchaseid']}` = p.`{$p['id']}`) AS linecount,
                   (SELECT COUNT(*) FROM `{$i['table']}` x WHERE x.`{$i['purchaseid']}` = p.`{$p['id']}` AND x.`{$i['itemtype']}` = 'Livestock') AS livestockcount
              FROM `{$p['table']}` p
        LEFT JOIN suppliers s ON s.supplierid = p.`{$p['supplierid']}` " . $tail;
}

/**
 * Turn a row of dt_purchase_sql() into the payload of the purchases list, view and edit (integer minor units, money as text).
 * Edit needs exactly one livestock line; delete needs every line to be a livestock line.
 *
 * @param array<string,mixed> $r Row of dt_purchase_sql().
 *
 * @return array<string,mixed> Payload.
 */
function dt_purchase_row(array $r): array
{
    $total = dt_sum_to_minor((string)$r['totalamount']);
    $paid  = dt_sum_to_minor((string)$r['paidamount']);
    $due   = max(0, $total - $paid);
    $name  = trim((string)($r['suppliername'] ?? ''));
    $none  = $name === '' || strcasecmp($name, BF_NO_SUPPLIER_NAME) === 0;
    $lines = (int)$r['linecount'];
    $stock = (int)$r['livestockcount'];

    return [
        'purchaseid'   => (int)$r['purchaseid'],
        'supplierid'   => (int)$r['supplierid'],
        'suppliername' => $none ? BF_NO_SUPPLIER_NAME : $name,
        'nosupplier'   => $none,
        'purchasedate' => (string)$r['purchasedate'],
        'reference'    => (string)($r['reference'] ?? ''),
        'note'         => (string)($r['note'] ?? ''),
        'total'        => dt_signed_decimal($total),
        'paid'         => dt_signed_decimal($paid),
        'balance'      => dt_minor_to_decimal($due, 2),
        'status'       => $due <= 0 ? 'Paid' : ($paid > 0 ? 'Partial' : 'Unpaid'),
        'accountid'    => (int)($r['accountid'] ?? 0),
        'editable'     => $lines === 1 && $stock === 1,
        'deletable'    => $lines > 0 && $stock === $lines,
    ];
}

/**
 * Payments of a purchase (see BF_PAYMENTS): the automatic one of this page (amount and account) and whether
 * payments entered on the Payments page exist. A missing table or column is logged and counts as none.
 *
 * @param PDO $pdo        Connection.
 * @param int $purchaseId Purchase id.
 *
 * @return array{auto:int,account:int,manual:bool} Automatic amount (minor units), its account and the manual flag.
 *
 * @throws PDOException On a database error other than a missing table or column.
 */
function dt_purchase_payments(PDO $pdo, int $purchaseId): array
{
    $s   = BF_PAYMENTS;
    $out = ['auto' => 0, 'account' => 0, 'manual' => false];
    try {
        foreach (dt_q(
            $pdo,
            "SELECT `{$s['accountid']}` AS accountid, `{$s['amount']}` AS amount, `{$s['note']}` AS note FROM `{$s['table']}` WHERE `{$s['purchaseid']}` = :id",
            ['id' => $purchaseId]
        )->fetchAll() as $row) {
            if ((string)($row['note'] ?? '') === BF_PAYMENT_NOTE) {
                $out['auto']   += dt_sum_to_minor((string)$row['amount']);
                $out['account'] = (int)$row['accountid'];
            } else {
                $out['manual'] = true;
            }
        }
    } catch (PDOException $e) {
        if (!in_array($e->getCode(), ['42S02', '42S22'], true)) {
            throw $e;
        }
        dt_log_error('[livestock purchase payments] skipped ' . $s['table'] . ': ' . dt_describe_throwable($e));
    }

    return $out;
}

/**
 * Split the note of a Walk-in sale or quotation ("Walk-in: NAME - note") into the walk-in name and the user note.
 *
 * @param string $customerName Customer name of the row.
 * @param string $note         Stored note.
 *
 * @return array{is:bool,name:string,note:string} Parsed values.
 */
function dt_parse_walkin_note(string $customerName, string $note): array
{
    $is     = strcasecmp($customerName, BF_WALKIN_NAME) === 0;
    $out    = ['is' => $is, 'name' => '', 'note' => $note];
    $prefix = BF_WALKIN_NAME . ': ';
    if ($is && strncmp($note, $prefix, strlen($prefix)) === 0) {
        $rest        = substr($note, strlen($prefix));
        $k           = strpos($rest, ' - ');
        $out['name'] = $k === false ? $rest : substr($rest, 0, $k);
        $out['note'] = $k === false ? '' : substr($rest, $k + 3);
    }

    return $out;
}

/**
 * SQL of one quotation row with its customer and the displayed status (Draft or Sent past its valid-until date shows Expired).
 *
 * @param string $tail SQL appended after the join (WHERE and ORDER BY), fixed text, never user input.
 *
 * @return string SQL with the named placeholder of the tail.
 */
function dt_quotation_sql(string $tail): string
{
    $q = BF_QUOTATIONS;

    return "SELECT q.`{$q['id']}` AS quotationid, q.`{$q['customerid']}` AS customerid, COALESCE(c.name, '(customer deleted)') AS customername,
                   c.primarycontact AS primarycontact, c.address AS address,
                   q.`{$q['date']}` AS quotationdate, q.`{$q['validuntil']}` AS validuntil, q.`{$q['reference']}` AS reference,
                   q.`{$q['note']}` AS note, q.`{$q['total']}` AS totalamount, q.`{$q['status']}` AS status,
                   CASE WHEN q.`{$q['validuntil']}` IS NOT NULL AND q.`{$q['validuntil']}` < CURDATE() AND q.`{$q['status']}` IN ('Draft', 'Sent')
                        THEN 'Expired' ELSE q.`{$q['status']}` END AS displaystatus
              FROM `{$q['table']}` q
        LEFT JOIN customers c ON c.customerid = q.`{$q['customerid']}` " . $tail;
}

/**
 * Turn a row of dt_quotation_sql() into the payload of the quotations list, view and edit.
 *
 * @param array<string,mixed> $r Row of dt_quotation_sql().
 *
 * @return array<string,mixed> Payload (money as decimal text).
 */
function dt_quotation_row(array $r): array
{
    $w     = dt_parse_walkin_note((string)$r['customername'], (string)($r['note'] ?? ''));
    $label = $w['is'] ? BF_WALKIN_NAME . ($w['name'] !== '' ? ' (' . $w['name'] . ')' : '') : (string)$r['customername'];

    return [
        'quotationid'    => (int)$r['quotationid'],
        'customerid'     => (int)$r['customerid'],
        'customername'   => $label,
        'walkin'         => $w['is'],
        'walkinname'     => $w['name'],
        'primarycontact' => (string)($r['primarycontact'] ?? ''),
        'address'        => (string)($r['address'] ?? ''),
        'quotationdate'  => (string)$r['quotationdate'],
        'validuntil'     => (string)($r['validuntil'] ?? ''),
        'reference'      => (string)($r['reference'] ?? ''),
        'note'           => (string)($r['note'] ?? ''),
        'usernote'       => $w['note'],
        'total'          => dt_signed_decimal(dt_sum_to_minor((string)$r['totalamount'])),
        'status'         => (string)$r['status'],
        'displaystatus'  => (string)$r['displaystatus'],
    ];
}

/**
 * Make sure every livestock item of the posted quotation lines exists.
 *
 * @param PDO                              $pdo   Connection.
 * @param list<array<string,int>>          $lines Clean lines of dt_read_quotation_input().
 *
 * @return void
 *
 * @throws DtFail When a livestock item does not exist (field "lines:N:livestock").
 */
function dt_assert_livestock_exist(PDO $pdo, array $lines): void
{
    $s   = BF_LIVESTOCK;
    $ids = [];
    foreach ($lines as $l) {
        $ids[$l['livestockid']] = true;
    }
    $marks  = [];
    $params = [];
    foreach (array_keys($ids) as $k => $id) {
        $marks[]          = ':i' . $k;
        $params['i' . $k] = $id;
    }
    $found = [];
    foreach (dt_q($pdo, "SELECT `{$s['id']}` FROM `{$s['table']}` WHERE `{$s['id']}` IN (" . implode(', ', $marks) . ')', $params)->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $found[(int)$id] = true;
    }
    foreach ($lines as $k => $l) {
        if (!isset($found[$l['livestockid']])) {
            throw new DtFail('Line ' . ($k + 1) . ': the selected livestock item does not exist.', false, 'lines:' . ($k + 1) . ':livestock');
        }
    }
}

/**
 * Position of a registered customer (information only, nothing is stored or blocked), all in integer minor units.
 *   Receivable = sum over his invoices of the balance due (net total - (paid - refunded), never below 0), from dt_sale_sql().
 *   Credit     = sum over his invoices of the overpaid amount.
 *   Deposit    = receipts of the customer that pay no invoice (receipts.invoiceid NULL or 0). This is the ONLY deposit source in
 *                the database (there is no deposit table or column); the amount is not reduced by anything else.
 *   Payable    = credit + deposit. Net = receivable - payable decides the status.
 * A missing table or column (42S02 / 42S22) is logged and counts as 0.
 *
 * @param PDO $pdo              Connection.
 * @param int $customerId       Customer id.
 * @param int $excludeInvoiceId Invoice not counted (the one being edited), 0 for none.
 *
 * @return array<string,string> Receivable, payable, deposit, amount (absolute net) as decimal text, and the status.
 *
 * @throws PDOException On a database error other than a missing table or column.
 */
function dt_customer_position(PDO $pdo, int $customerId, int $excludeInvoiceId): array
{
    $v          = BF_INVOICES;
    $rc         = BF_RECEIPTS;
    $receivable = 0;
    $credit     = 0;
    $deposit    = 0;

    try {
        $tail   = "WHERE i.`{$v['customerid']}` = :c";
        $params = ['c' => $customerId];
        if ($excludeInvoiceId > 0) {
            $tail      .= " AND i.`{$v['id']}` <> :x";
            $params['x'] = $excludeInvoiceId;
        }
        foreach (dt_q($pdo, dt_sale_sql($tail), $params)->fetchAll() as $row) {
            $r           = dt_sale_row($row);
            $receivable += dt_sum_to_minor((string)$r['balance']);
            $credit     += dt_sum_to_minor((string)$r['credit']);
        }
    } catch (PDOException $e) {
        if (!in_array($e->getCode(), ['42S02', '42S22'], true)) {
            throw $e;
        }
        dt_log_error('[livestock customer position] skipped invoices: ' . dt_describe_throwable($e));
    }

    try {
        $raw     = dt_q(
            $pdo,
            "SELECT COALESCE(SUM(`{$rc['amount']}`), 0) FROM `{$rc['table']}` WHERE `{$rc['customerid']}` = :c AND (`{$rc['invoiceid']}` IS NULL OR `{$rc['invoiceid']}` = 0)",
            ['c' => $customerId]
        )->fetchColumn();
        $deposit = dt_sum_to_minor((string)$raw);
    } catch (PDOException $e) {
        if (!in_array($e->getCode(), ['42S02', '42S22'], true)) {
            throw $e;
        }
        dt_log_error('[livestock customer position] skipped ' . $rc['table'] . ': ' . dt_describe_throwable($e));
    }

    $payable = $credit + $deposit;
    $net     = $receivable - $payable;

    return [
        'receivable' => dt_minor_to_decimal($receivable, 2),
        'payable'    => dt_minor_to_decimal($payable, 2),
        'deposit'    => dt_minor_to_decimal($deposit, 2),
        'amount'     => dt_minor_to_decimal(abs($net), 2),
        'status'     => $net > 0 ? 'Receivable' : ($net < 0 ? 'Payable' : 'Settled'),
    ];
}

/* ---------- Supplier position rules ---------- */

/**
 * SQL of the purchases of one supplier with the extra payments of each purchase (the ONLY place of the "extra paid" formula).
 * Extra paid = SUM(payments.amount) of the purchase where the note is empty or is not BF_PAYMENT_NOTE
 * (the automatic payment of this page is already inside purchases.paidamount, so it is never counted twice).
 * Fixed text, never user input. Named placeholders: :s always, :m when $withPayments, :x when $excludeOne.
 *
 * @param bool $withPayments True to include the payments subquery (false when the payments table is missing).
 * @param bool $excludeOne   True to leave one purchase out (the one being edited).
 *
 * @return string SQL.
 */
function dt_supplier_purchase_sql(bool $withPayments, bool $excludeOne): string
{
    $p     = BF_PURCHASES;
    $y     = BF_PAYMENTS;
    $extra = $withPayments
        ? "COALESCE((SELECT SUM(y.`{$y['amount']}`) FROM `{$y['table']}` y
                      WHERE y.`{$y['purchaseid']}` = p.`{$p['id']}` AND (y.`{$y['note']}` IS NULL OR y.`{$y['note']}` <> :m)), 0)"
        : '0';

    return "SELECT p.`{$p['id']}` AS purchaseid, p.`{$p['total']}` AS totalamount, p.`{$p['paid']}` AS paidamount, {$extra} AS extrapaid
              FROM `{$p['table']}` p
             WHERE p.`{$p['supplierid']}` = :s" . ($excludeOne ? " AND p.`{$p['id']}` <> :x" : '');
}

/**
 * Effective paid amount of a row of dt_supplier_purchase_sql(), in minor units (scale 2).
 *
 * @param array<string,mixed> $row Row.
 *
 * @return int Minor units.
 */
function dt_purchase_effective_paid(array $row): int
{
    return dt_sum_to_minor((string)$row['paidamount']) + dt_sum_to_minor((string)$row['extrapaid']);
}

/**
 * Position of a supplier (information only, nothing is stored or blocked), all in integer minor units.
 *   Due        = per purchase max(0, total - effective paid). Credit = per purchase max(0, effective paid - total).
 *   Advance    = payments of the supplier that pay no purchase (purchaseid NULL or 0).
 *   Payable    = sum of Due (we owe the supplier).
 *   Receivable = sum of Credit + Advance (the supplier owes us).
 *   Net = Payable - Receivable decides the status.
 * A missing table or column (42S02 / 42S22) is logged and counts as 0.
 *
 * @param PDO $pdo               Connection.
 * @param int $supplierId        Supplier id.
 * @param int $excludePurchaseId Purchase not counted (the one being edited), 0 for none.
 *
 * @return array<string,string> payable, receivable, advance, amount (absolute net) as decimal text, and status.
 *
 * @throws PDOException On a database error other than a missing table or column.
 */
function dt_supplier_position(PDO $pdo, int $supplierId, int $excludePurchaseId): array
{
    $y       = BF_PAYMENTS;
    $payable = 0;
    $credit  = 0;
    $advance = 0;
    $rows    = [];

    foreach ([true, false] as $withPayments) {
        try {
            $params = ['s' => $supplierId];
            if ($withPayments) {
                $params['m'] = BF_PAYMENT_NOTE;
            }
            if ($excludePurchaseId > 0) {
                $params['x'] = $excludePurchaseId;
            }
            $rows = dt_q($pdo, dt_supplier_purchase_sql($withPayments, $excludePurchaseId > 0), $params)->fetchAll();
            break;
        } catch (PDOException $e) {
            if (!in_array($e->getCode(), ['42S02', '42S22'], true)) {
                throw $e;
            }
            dt_log_error('[livestock supplier position] skipped ' . ($withPayments ? 'payments' : 'purchases') . ': ' . dt_describe_throwable($e));
            $rows = [];
        }
    }

    foreach ($rows as $row) {
        $total    = dt_sum_to_minor((string)$row['totalamount']);
        $paid     = dt_purchase_effective_paid($row);
        $payable += max(0, $total - $paid);
        $credit  += max(0, $paid - $total);
    }

    try {
        $raw     = dt_q(
            $pdo,
            "SELECT COALESCE(SUM(`{$y['amount']}`), 0) FROM `{$y['table']}`
              WHERE `{$y['supplierid']}` = :s AND (`{$y['purchaseid']}` IS NULL OR `{$y['purchaseid']}` = 0)",
            ['s' => $supplierId]
        )->fetchColumn();
        $advance = dt_sum_to_minor((string)$raw);
    } catch (PDOException $e) {
        if (!in_array($e->getCode(), ['42S02', '42S22'], true)) {
            throw $e;
        }
        dt_log_error('[livestock supplier position] skipped ' . $y['table'] . ': ' . dt_describe_throwable($e));
    }

    $receivable = $credit + $advance;
    $net        = $payable - $receivable;

    return [
        'payable'    => dt_minor_to_decimal($payable, 2),
        'receivable' => dt_minor_to_decimal($receivable, 2),
        'advance'    => dt_minor_to_decimal($advance, 2),
        'amount'     => dt_minor_to_decimal(abs($net), 2),
        'status'     => $net > 0 ? 'Payable' : ($net < 0 ? 'Receivable' : 'Settled'),
    ];
}

/* ---------- Sale rules ---------- */

/**
 * Read and validate the posted sale fields (used by salesave, before the transaction).
 * Totals are never trusted from the browser: the line total and the invoice total are computed here in minor units.
 *
 * @return array{customerid:int,date:string,reference:?string,note:?string,livestockid:int,qty:int,price:int,total:int,paid:int,accountid:int} Clean fields.
 *
 * @throws DtFail On invalid input.
 */
function dt_read_sale_input(): array
{
    $rawCustomer = trim((string)($_POST['customerid'] ?? ''));
    $walkin      = strtolower($rawCustomer) === 'walkin';
    $customerId  = $walkin ? 0 : max(0, (int)$rawCustomer);
    $walkinName  = $walkin ? trim((string)($_POST['walkinname'] ?? '')) : '';
    $rawDate     = trim((string)($_POST['saledate'] ?? ''));
    $reference   = trim((string)($_POST['reference'] ?? ''));
    $note        = trim((string)($_POST['note'] ?? ''));
    $rawPaid     = trim((string)($_POST['paidamount'] ?? ''));
    $accountId   = max(0, (int)($_POST['receivedinaccountid'] ?? 0));

    if (!$walkin && $customerId <= 0) {
        throw new DtFail('Customer is required.', false, 'customerid');
    }
    if (mb_strlen($walkinName) > DT_MAX_WALKIN) {
        throw new DtFail('Walk-in name is too long (maximum ' . DT_MAX_WALKIN . ' characters).', false, 'walkinname');
    }
    $date = dt_parse_date($rawDate, 'Sale date must be a valid date.', 'saledate');
    if (mb_strlen($reference) > DT_MAX_REFERENCE) {
        throw new DtFail('Reference is too long (maximum ' . DT_MAX_REFERENCE . ' characters).', false, 'reference');
    }
    if (mb_strlen($note) > DT_MAX_NOTE) {
        throw new DtFail('Note is too long (maximum ' . DT_MAX_NOTE . ' characters).', false, 'note');
    }
    $finalNote = dt_compose_note($walkin, $walkinName, $note);

    $decoded = json_decode((string)($_POST['lines'] ?? ''), true);
    if (!is_array($decoded) || $decoded === []) {
        throw new DtFail('Add at least one line.', false, 'lines');
    }
    if (count($decoded) > DT_MAX_LINES) {
        throw new DtFail('A sale can have at most ' . DT_MAX_LINES . ' lines.', false, 'lines');
    }

    $lines = [];
    $total = 0;
    foreach (array_values($decoded) as $k => $raw) {
        $line    = dt_read_quotation_line($raw, $k + 1); // same rules and field keys: lines:N:livestock|quantity|sellingprice
        $total  += $line['total'];
        $lines[] = $line;
        if ($total > DT_MAX_TOTAL_MINOR) {
            throw new DtFail('The sale total is too large.', false, 'lines');
        }
    }

    $paid = dt_parse_decimal($rawPaid === '' ? '0' : $rawPaid, DT_TOTAL_INT_DIGITS, 2, false, 'Paid amount must be 0 or more, with up to 2 decimals.', 'paidamount');
    if ($paid > $total) {
        throw new DtFail('Paid amount cannot be more than the total.', false, 'paidamount');
    }
    if ($walkin && $paid !== $total) {
        throw new DtFail('A walk-in sale must be paid in full.', false, 'paidamount');
    }
    if ($paid > 0 && $accountId <= 0) {
        throw new DtFail('Select the account the money was received into.', false, 'receivedinaccountid');
    }

    return [
        'customerid' => $customerId,
        'walkin'     => $walkin,
        'date'       => $date,
        'reference'  => $reference === '' ? null : $reference,
        'note'       => $finalNote,
        'total'      => $total,
        'paid'       => $paid,
        'accountid'  => $paid > 0 ? $accountId : 0,
        'lines'      => $lines,
    ];
}

/** Replaced by the Walk-in version above (not called, delete it when you want). */
function dt_read_sale_input_prev(): array
{
    $customerId = max(0, (int)($_POST['customerid'] ?? 0));
    $rawDate    = trim((string)($_POST['saledate'] ?? ''));
    $reference  = trim((string)($_POST['reference'] ?? ''));
    $note       = trim((string)($_POST['note'] ?? ''));
    $rawPaid    = trim((string)($_POST['paidamount'] ?? ''));
    $accountId  = max(0, (int)($_POST['receivedinaccountid'] ?? 0));

    if ($customerId <= 0) {
        throw new DtFail('Customer is required.', false, 'customerid');
    }
    $date = dt_parse_date($rawDate, 'Sale date must be a valid date.', 'saledate');
    if (mb_strlen($reference) > DT_MAX_REFERENCE) {
        throw new DtFail('Reference is too long (maximum ' . DT_MAX_REFERENCE . ' characters).', false, 'reference');
    }
    if (mb_strlen($note) > DT_MAX_NOTE) {
        throw new DtFail('Note is too long (maximum ' . DT_MAX_NOTE . ' characters).', false, 'note');
    }

    $decoded = json_decode((string)($_POST['lines'] ?? ''), true);
    if (!is_array($decoded) || $decoded === []) {
        throw new DtFail('Add at least one line.', false, 'lines');
    }
    if (count($decoded) > DT_MAX_LINES) {
        throw new DtFail('A sale can have at most ' . DT_MAX_LINES . ' lines.', false, 'lines');
    }

    $lines = [];
    $total = 0;
    foreach (array_values($decoded) as $k => $raw) {
        $line    = dt_read_quotation_line($raw, $k + 1); // same rules and field keys: lines:N:livestock|quantity|sellingprice
        $total  += $line['total'];
        $lines[] = $line;
        if ($total > DT_MAX_TOTAL_MINOR) {
            throw new DtFail('The sale total is too large.', false, 'lines');
        }
    }

    $paid = dt_parse_decimal($rawPaid === '' ? '0' : $rawPaid, DT_TOTAL_INT_DIGITS, 2, false, 'Paid amount must be 0 or more, with up to 2 decimals.', 'paidamount');
    if ($paid > $total) {
        throw new DtFail('Paid amount cannot be more than the total.', false, 'paidamount');
    }
    if ($paid > 0 && $accountId <= 0) {
        throw new DtFail('Select the account the money was received into.', false, 'receivedinaccountid');
    }

    return [
        'customerid' => $customerId,
        'date'       => $date,
        'reference'  => $reference === '' ? null : $reference,
        'note'       => $note === '' ? null : $note,
        'total'      => $total,
        'paid'       => $paid,
        'accountid'  => $paid > 0 ? $accountId : 0,
        'lines'      => $lines,
    ];
}

/** Old one-animal version, no longer used (delete it when you want). */
function dt_read_sale_input_old(): array
{
    $customerId  = max(0, (int)($_POST['customerid'] ?? 0));
    $rawDate     = trim((string)($_POST['saledate'] ?? ''));
    $reference   = trim((string)($_POST['reference'] ?? ''));
    $note        = trim((string)($_POST['note'] ?? ''));
    $livestockId = max(0, (int)($_POST['livestockid'] ?? 0));
    $rawQty      = trim((string)($_POST['quantity'] ?? ''));
    $rawPrice    = trim((string)($_POST['unitprice'] ?? ''));
    $rawPaid     = trim((string)($_POST['paidamount'] ?? ''));
    $accountId   = max(0, (int)($_POST['receivedinaccountid'] ?? 0));

    if ($customerId <= 0) {
        throw new DtFail('Customer is required.', false, 'customerid');
    }
    $date = dt_parse_date($rawDate, 'Sale date must be a valid date.', 'saledate');
    if (mb_strlen($reference) > DT_MAX_REFERENCE) {
        throw new DtFail('Reference is too long (maximum ' . DT_MAX_REFERENCE . ' characters).', false, 'reference');
    }
    if ($livestockId <= 0) {
        throw new DtFail('Select a livestock item.', false, 'livestockid');
    }
    if (!preg_match('/^\d{1,' . DT_QTY_INT_DIGITS . '}$/D', $rawQty) || (int)$rawQty <= 0) {
        throw new DtFail('Quantity must be a whole number greater than 0.', false, 'quantity');
    }
    $qty   = (int)$rawQty;
    $price = dt_parse_decimal($rawPrice, DT_PRICE_INT_DIGITS, DT_PRICE_SCALE, false, 'Selling price must be 0 or more, with up to 2 decimals.', 'unitprice');

    /* qty <= 999,999 and price <= 999,999,999 minor units: the product cannot overflow a 64-bit integer. */
    $total = $qty * $price;
    if ($total > DT_MAX_TOTAL_MINOR) {
        throw new DtFail('The sale total is too large.', false, 'quantity');
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
        throw new DtFail('Select the account the money was received into.', false, 'receivedinaccountid');
    }
    if (mb_strlen($note) > DT_MAX_NOTE) {
        throw new DtFail('Note is too long (maximum ' . DT_MAX_NOTE . ' characters).', false, 'note');
    }

    return [
        'customerid'  => $customerId,
        'date'        => $date,
        'reference'   => $reference === '' ? null : $reference,
        'note'        => $note === '' ? null : $note,
        'livestockid' => $livestockId,
        'qty'         => $qty,
        'price'       => $price,
        'total'       => $total,
        'paid'        => $paid,
        'accountid'   => $paid > 0 ? $accountId : 0,
    ];
}

/**
 * Create, update or remove the automatic receipt row of an invoice (see BF_RECEIPTS).
 * A missing table or column (SQLSTATE 42S02 / 42S22) is logged and skipped; any other database error is rethrown.
 *
 * @param PDO    $pdo        Connection.
 * @param int    $invoiceId  Invoice id.
 * @param int    $customerId Customer id.
 * @param int    $accountId  Account the money was received into.
 * @param int    $paidMinor  Paid amount in minor units (0 removes the row).
 * @param string $date       Receipt date (the sale date).
 *
 * @return void
 *
 * @throws PDOException On a database error other than a missing table or column.
 */
function dt_sync_receipt(PDO $pdo, int $invoiceId, int $customerId, int $accountId, int $paidMinor, string $date): void
{
    $s = BF_RECEIPTS;
    try {
        $count = (int)dt_q(
            $pdo,
            "SELECT COUNT(*) FROM `{$s['table']}` WHERE `{$s['invoiceid']}` = :p AND `{$s['note']}` = :m",
            ['p' => $invoiceId, 'm' => BF_RECEIPT_NOTE]
        )->fetchColumn();

        if ($paidMinor <= 0) {
            if ($count > 0) {
                dt_q($pdo, "DELETE FROM `{$s['table']}` WHERE `{$s['invoiceid']}` = :p AND `{$s['note']}` = :m", ['p' => $invoiceId, 'm' => BF_RECEIPT_NOTE]);
            }

            return;
        }

        $amount = dt_minor_to_decimal($paidMinor, 2);
        if ($count > 0) {
            dt_q(
                $pdo,
                "UPDATE `{$s['table']}` SET `{$s['customerid']}` = :c, `{$s['accountid']}` = :a, `{$s['amount']}` = :amt, `{$s['date']}` = :d
                  WHERE `{$s['invoiceid']}` = :p AND `{$s['note']}` = :m",
                ['c' => $customerId, 'a' => $accountId, 'amt' => $amount, 'd' => $date, 'p' => $invoiceId, 'm' => BF_RECEIPT_NOTE]
            );

            return;
        }
        dt_q(
            $pdo,
            "INSERT INTO `{$s['table']}` (`{$s['customerid']}`, `{$s['invoiceid']}`, `{$s['accountid']}`, `{$s['amount']}`, `{$s['date']}`, `{$s['note']}`)
             VALUES (:c, :p, :a, :amt, :d, :m)",
            ['c' => $customerId, 'p' => $invoiceId, 'a' => $accountId, 'amt' => $amount, 'd' => $date, 'm' => BF_RECEIPT_NOTE]
        );
    } catch (PDOException $e) {
        if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
            dt_log_error('[livestock receipt] skipped ' . $s['table'] . ': ' . dt_describe_throwable($e));

            return;
        }
        throw $e;
    }
}

/**
 * Write a sale activity row (call before commit). Without login the user is unknown: userid is NULL.
 *
 * @param PDO    $pdo    Connection.
 * @param string $action Add | Update | Delete.
 * @param string $text   Description.
 *
 * @return void
 */
function dt_log_sale(PDO $pdo, string $action, string $text): void
{
    dt_q(
        $pdo,
        'INSERT INTO activitylogs (userid, action, description, activitydate) VALUES (NULL, :a, :d, NOW())',
        ['a' => $action, 'd' => mb_substr($text, 0, 255)]
    );
}

/** Lock livestock rows FOR UPDATE in ascending id order. $firstLine maps id => first line number (for the field key). */
function dt_lock_livestock_set(PDO $pdo, array $ids, array $firstLine = []): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);
    $out = [];
    foreach ($ids as $lid) {
        try {
            $out[$lid] = dt_lock_livestock($pdo, $lid);
        } catch (DtFail $e) {
            $n = $firstLine[$lid] ?? 0;
            if ($n > 0) {
                throw new DtFail('Line ' . $n . ': the selected livestock item does not exist.', false, 'lines:' . $n . ':livestock');
            }
            throw $e;
        }
    }

    return $out;
}

/** Stock rule with the SUM per animal over the lines. Returns animals taken per livestock id. */
function dt_check_sale_stock(array $lines, array $available, array $animals): array
{
    $taken = [];
    foreach ($lines as $k => $l) {
        $lid  = (int)$l['livestockid'];
        $left = (int)$available[$lid] - ($taken[$lid] ?? 0);
        if ((int)$l['qty'] > $left) {
            throw new DtFail('Line ' . ($k + 1) . ': only ' . number_format(max(0, $left)) . ' of ' . $animals[$lid]['name'] . ' available.', false, 'lines:' . ($k + 1) . ':quantity');
        }
        $taken[$lid] = ($taken[$lid] ?? 0) + (int)$l['qty'];
    }

    return $taken;
}

/** Edit flags of an invoice: returns, refunds (minor units), first return/refund date, receipts entered on the Receipts page. */
function dt_invoice_flags(PDO $pdo, int $id): array
{
    $r  = BF_SALERETURNS;
    $f  = BF_SALEREFUNDS;
    $rc = BF_RECEIPTS;

    $returns = (int)dt_q($pdo, "SELECT COUNT(*) FROM `{$r['table']}` WHERE `{$r['invoiceid']}` = :id", ['id' => $id])->fetchColumn();
    $refund  = dt_q($pdo, "SELECT COUNT(*) AS n, COALESCE(SUM(`{$f['amount']}`), 0) AS total FROM `{$f['table']}` WHERE `{$f['invoiceid']}` = :id", ['id' => $id])->fetch();
    $first   = dt_q(
        $pdo,
        "SELECT MIN(x.d) FROM (SELECT `{$r['date']}` AS d FROM `{$r['table']}` WHERE `{$r['invoiceid']}` = :a
                                UNION ALL SELECT `{$f['date']}` AS d FROM `{$f['table']}` WHERE `{$f['invoiceid']}` = :b) x",
        ['a' => $id, 'b' => $id]
    )->fetchColumn();

    $manual = false;
    try {
        $manual = (int)dt_q(
            $pdo,
            "SELECT COUNT(*) FROM `{$rc['table']}` WHERE `{$rc['invoiceid']}` = :id AND (`{$rc['note']}` IS NULL OR `{$rc['note']}` <> :m)",
            ['id' => $id, 'm' => BF_RECEIPT_NOTE]
        )->fetchColumn() > 0;
    } catch (PDOException $e) {
        if (!in_array($e->getCode(), ['42S02', '42S22'], true)) {
            throw $e;
        }
        dt_log_error('[livestock invoice flags] skipped ' . $rc['table'] . ': ' . dt_describe_throwable($e));
    }

    return [
        'returns'   => $returns,
        'refunds'   => (int)$refund['n'],
        'refunded'  => dt_sum_to_minor((string)$refund['total']),
        'firstdate' => ($first === false || $first === null) ? null : (string)$first,
        'manual'    => $manual,
    ];
}

/* ---------- Quotation rules ---------- */

/**
 * Read and validate one posted quotation line (shape, livestock id, quantity, price, line total). Existence is checked later.
 *
 * @param mixed $l Decoded line.
 * @param int   $n Line number (1 based) for messages and field keys.
 *
 * @return array{livestockid:int,qty:int,price:int,total:int} Clean line (price and total in minor units, scale 2).
 *
 * @throws DtFail On invalid input.
 */
function dt_read_quotation_line(mixed $l, int $n): array
{
    if (!is_array($l)) {
        throw new DtFail('Line ' . $n . ' is not valid.', false, 'lines');
    }
    $livestockId = max(0, (int)($l['livestockid'] ?? 0));
    if ($livestockId <= 0) {
        throw new DtFail('Line ' . $n . ': select a livestock item.', false, 'lines:' . $n . ':livestock');
    }
    $rawQty = trim((string)($l['quantity'] ?? ''));
    if (!preg_match('/^\d{1,' . DT_QTY_INT_DIGITS . '}$/D', $rawQty) || (int)$rawQty <= 0) {
        throw new DtFail('Line ' . $n . ': quantity must be a whole number greater than 0.', false, 'lines:' . $n . ':quantity');
    }
    $qty   = (int)$rawQty;
    $price = dt_parse_decimal(
        trim((string)($l['sellingprice'] ?? '')),
        DT_PRICE_INT_DIGITS,
        DT_PRICE_SCALE,
        false,
        'Line ' . $n . ': selling price must be 0 or more, with up to 2 decimals.',
        'lines:' . $n . ':sellingprice'
    );
    $total = $qty * $price;
    if ($total > DT_MAX_TOTAL_MINOR) {
        throw new DtFail('Line ' . $n . ': the line total is too large.', false, 'lines:' . $n . ':quantity');
    }

    return ['livestockid' => $livestockId, 'qty' => $qty, 'price' => $price, 'total' => $total];
}

/**
 * Read and validate the posted quotation fields and lines (used by quotationsave, before the transaction).
 *
 * @return array{customerid:int,date:string,validuntil:?string,reference:?string,status:string,note:?string,total:int,lines:list<array<string,int>>} Clean fields.
 *
 * @throws DtFail On invalid input.
 */
function dt_read_quotation_input(): array
{
    $rawCustomer = trim((string)($_POST['customerid'] ?? ''));
    $walkin      = strtolower($rawCustomer) === 'walkin';
    $customerId  = $walkin ? 0 : max(0, (int)$rawCustomer);
    $walkinName  = $walkin ? trim((string)($_POST['walkinname'] ?? '')) : '';
    $rawDate     = trim((string)($_POST['quotationdate'] ?? ''));
    $rawValid    = trim((string)($_POST['validuntil'] ?? ''));
    $reference   = trim((string)($_POST['reference'] ?? ''));
    $status      = trim((string)($_POST['status'] ?? ''));
    $note        = trim((string)($_POST['note'] ?? ''));

    if (!$walkin && $customerId <= 0) {
        throw new DtFail('Customer is required.', false, 'customerid');
    }
    if (mb_strlen($walkinName) > DT_MAX_WALKIN) {
        throw new DtFail('Walk-in name is too long (maximum ' . DT_MAX_WALKIN . ' characters).', false, 'walkinname');
    }
    $date       = dt_parse_date($rawDate, 'Quotation date must be a valid date.', 'quotationdate');
    $validUntil = null;
    if ($rawValid !== '') {
        $validUntil = dt_parse_date($rawValid, 'Valid until must be a valid date.', 'validuntil');
        if ($validUntil < $date) {
            throw new DtFail('Valid until cannot be before the quotation date.', false, 'validuntil');
        }
    }
    if (mb_strlen($reference) > DT_MAX_REFERENCE) {
        throw new DtFail('Reference is too long (maximum ' . DT_MAX_REFERENCE . ' characters).', false, 'reference');
    }
    if (!in_array($status, DT_QUOTATION_STATUSES, true)) {
        throw new DtFail('Select a valid status.', false, 'status');
    }
    if (mb_strlen($note) > DT_MAX_NOTE) {
        throw new DtFail('Note is too long (maximum ' . DT_MAX_NOTE . ' characters).', false, 'note');
    }
    $finalNote = dt_compose_note($walkin, $walkinName, $note);

    $decoded = json_decode((string)($_POST['lines'] ?? ''), true);
    if (!is_array($decoded) || $decoded === []) {
        throw new DtFail('Add at least one line.', false, 'lines');
    }
    if (count($decoded) > DT_MAX_LINES) {
        throw new DtFail('A quotation can have at most ' . DT_MAX_LINES . ' lines.', false, 'lines');
    }

    $lines = [];
    $total = 0;
    foreach (array_values($decoded) as $k => $raw) {
        $line    = dt_read_quotation_line($raw, $k + 1);
        $total  += $line['total'];
        $lines[] = $line;
        if ($total > DT_MAX_TOTAL_MINOR) {
            throw new DtFail('The quotation total is too large.', false, 'lines');
        }
    }

    return [
        'customerid' => $customerId,
        'walkin'     => $walkin,
        'date'       => $date,
        'validuntil' => $validUntil,
        'reference'  => $reference === '' ? null : $reference,
        'status'     => $status,
        'note'       => $finalNote,
        'total'      => $total,
        'lines'      => $lines,
    ];
}

/** Replaced by the Walk-in version above (not called, delete it when you want). */
function dt_read_quotation_input_prev(): array
{
    $customerId = max(0, (int)($_POST['customerid'] ?? 0));
    $rawDate    = trim((string)($_POST['quotationdate'] ?? ''));
    $rawValid   = trim((string)($_POST['validuntil'] ?? ''));
    $reference  = trim((string)($_POST['reference'] ?? ''));
    $status     = trim((string)($_POST['status'] ?? ''));
    $note       = trim((string)($_POST['note'] ?? ''));

    if ($customerId <= 0) {
        throw new DtFail('Customer is required.', false, 'customerid');
    }
    $date       = dt_parse_date($rawDate, 'Quotation date must be a valid date.', 'quotationdate');
    $validUntil = null;
    if ($rawValid !== '') {
        $validUntil = dt_parse_date($rawValid, 'Valid until must be a valid date.', 'validuntil');
        if ($validUntil < $date) {
            throw new DtFail('Valid until cannot be before the quotation date.', false, 'validuntil');
        }
    }
    if (mb_strlen($reference) > DT_MAX_REFERENCE) {
        throw new DtFail('Reference is too long (maximum ' . DT_MAX_REFERENCE . ' characters).', false, 'reference');
    }
    if (!in_array($status, DT_QUOTATION_STATUSES, true)) {
        throw new DtFail('Select a valid status.', false, 'status');
    }
    if (mb_strlen($note) > DT_MAX_NOTE) {
        throw new DtFail('Note is too long (maximum ' . DT_MAX_NOTE . ' characters).', false, 'note');
    }

    $decoded = json_decode((string)($_POST['lines'] ?? ''), true);
    if (!is_array($decoded) || $decoded === []) {
        throw new DtFail('Add at least one line.', false, 'lines');
    }
    if (count($decoded) > DT_MAX_LINES) {
        throw new DtFail('A quotation can have at most ' . DT_MAX_LINES . ' lines.', false, 'lines');
    }

    $lines = [];
    $total = 0;
    foreach (array_values($decoded) as $k => $raw) {
        $line    = dt_read_quotation_line($raw, $k + 1);
        $total  += $line['total'];
        $lines[] = $line;
        if ($total > DT_MAX_TOTAL_MINOR) {
            throw new DtFail('The quotation total is too large.', false, 'lines');
        }
    }

    return [
        'customerid' => $customerId,
        'date'       => $date,
        'validuntil' => $validUntil,
        'reference'  => $reference === '' ? null : $reference,
        'status'     => $status,
        'note'       => $note === '' ? null : $note,
        'total'      => $total,
        'lines'      => $lines,
    ];
}

/**
 * Write a quotation activity row (call before commit). Without login the user is unknown: userid is NULL.
 *
 * @param PDO    $pdo    Connection.
 * @param string $action Add | Update | Delete.
 * @param string $text   Description.
 *
 * @return void
 */
function dt_log_quotation(PDO $pdo, string $action, string $text): void
{
    dt_q(
        $pdo,
        'INSERT INTO activitylogs (userid, action, description, activitydate) VALUES (NULL, :a, :d, NOW())',
        ['a' => $action, 'd' => mb_substr($text, 0, 255)]
    );
}

/**
 * Id of the Walk-in customer: the customers row named BF_WALKIN_NAME (case-insensitive), created once when missing.
 * Call it only inside a transaction. A named lock (GET_LOCK) makes two first sales at the same time create only one row.
 * The lock is released at the end of the request (after the commit), so the second request always sees the committed row.
 *
 * @param PDO $pdo Connection.
 *
 * @return int Customer id.
 *
 * @throws DtFail When the lock cannot be taken.
 */
function dt_walkin_customer_id(PDO $pdo): int
{
    $find = static function (bool $latest) use ($pdo): int {
        $id = dt_q(
            $pdo,
            'SELECT customerid FROM customers WHERE LOWER(name) = LOWER(:n) ORDER BY customerid ASC LIMIT 1' . ($latest ? ' FOR UPDATE' : ''),
            ['n' => BF_WALKIN_NAME]
        )->fetchColumn();

        return $id === false ? 0 : (int)$id;
    };

    $id = $find(false);
    if ($id > 0) {
        return $id;
    }

    $key = 'bf_walkin_customer';
    if ((int)dt_q($pdo, 'SELECT GET_LOCK(:k, 10)', ['k' => $key])->fetchColumn() !== 1) {
        throw new DtFail('Cannot prepare the Walk-in customer. Please try again.');
    }
    register_shutdown_function(static function () use ($pdo, $key): void {
        try {
            dt_q($pdo, 'SELECT RELEASE_LOCK(:k)', ['k' => $key])->fetchColumn();
        } catch (Throwable $e) {
            dt_log_error('[livestock walkin] release lock failed: ' . dt_describe_throwable($e));
        }
    });

    $id = $find(true); // locking read: sees a row committed by another request after our snapshot started
    if ($id === 0) {
        dt_q($pdo, 'INSERT INTO customers (name, primarycontact, address) VALUES (:n, NULL, NULL)', ['n' => BF_WALKIN_NAME]);
        $id = (int)$pdo->lastInsertId();
    }

    return $id;
}

/**
 * Note of a sale or quotation: "Walk-in: NAME - note" (the prefix only for a walk-in with a name).
 *
 * @param bool   $walkin     True for a walk-in customer.
 * @param string $walkinName Optional walk-in name (already trimmed).
 * @param string $note       Note typed by the user (already trimmed).
 *
 * @return string|null Final note, or null when empty.
 *
 * @throws DtFail When the final note is longer than DT_MAX_NOTE (field "note").
 */
function dt_compose_note(bool $walkin, string $walkinName, string $note): ?string
{
    $parts = [];
    if ($walkin && $walkinName !== '') {
        $parts[] = BF_WALKIN_NAME . ': ' . $walkinName;
    }
    if ($note !== '') {
        $parts[] = $note;
    }
    $out = implode(' - ', $parts);
    if (mb_strlen($out) > DT_MAX_NOTE) {
        throw new DtFail('Note is too long (maximum ' . DT_MAX_NOTE . ' characters, including the walk-in name).', false, 'note');
    }

    return $out === '' ? null : $out;
}

/**
 * Customers for the sale and quotation modals (every customer except the Walk-in row, which the modals list once on top).
 *
 * @param PDO $pdo Connection.
 *
 * @return list<array{customerid:int,name:string}> Customers ordered by name.
 */
function dt_lookup_customers(PDO $pdo): array
{
    $out = [];
    foreach (dt_q($pdo, 'SELECT customerid, name FROM customers WHERE LOWER(name) <> LOWER(:w) ORDER BY name ASC', ['w' => BF_WALKIN_NAME])->fetchAll() as $row) {
        $out[] = ['customerid' => (int)$row['customerid'], 'name' => (string)$row['name']];
    }

    return $out;
}

/**
 * Livestock for the sale and quotation modals (decimal values stay text, no float).
 *
 * @param PDO  $pdo      Connection.
 * @param bool $withCost True to include costeach (sale only).
 *
 * @return list<array<string,mixed>> Livestock ordered by name.
 */
function dt_lookup_livestock(PDO $pdo, bool $withCost): array
{
    $l   = BF_LIVESTOCK;
    $out = [];
    foreach (dt_q(
        $pdo,
        "SELECT `{$l['id']}` AS livestockid, `{$l['name']}` AS name, `{$l['type']}` AS type, `{$l['stock']}` AS currentstock,
                `{$l['cost']}` AS costeach, `{$l['price']}` AS sellingprice
           FROM `{$l['table']}` ORDER BY `{$l['name']}` ASC"
    )->fetchAll() as $row) {
        $item = [
            'livestockid'  => (int)$row['livestockid'],
            'name'         => (string)$row['name'],
            'type'         => (string)$row['type'],
            'currentstock' => (int)$row['currentstock'],
            'sellingprice' => (string)$row['sellingprice'],
        ];
        if ($withCost) {
            $item['costeach'] = (string)$row['costeach'];
        }
        $out[] = $item;
    }

    return $out;
}

/* ---------- Invoice, return and refund rules ---------- */

/**
 * SQL of one invoice row with its customer and the totals computed in SQL (returns, refunds, animals sold, animals returned).
 * Shared by saleslist, saleget and refundlookups so every screen shows the same numbers.
 *
 * @param string $tail SQL appended after the joins (WHERE and ORDER BY), fixed text, never user input.
 *
 * @return string SQL with the named placeholder of the tail.
 */
function dt_sale_sql(string $tail): string
{
    $v  = BF_INVOICES;
    $it = BF_INVOICEITEMS;
    $r  = BF_SALERETURNS;
    $ri = BF_SALERETURNITEMS;
    $f  = BF_SALEREFUNDS;

    return "SELECT i.`{$v['id']}` AS invoiceid, i.`{$v['customerid']}` AS customerid, COALESCE(c.name, '(customer deleted)') AS customername,
                   c.primarycontact AS primarycontact, c.address AS address,
                   i.`{$v['date']}` AS invoicedate, i.`{$v['reference']}` AS reference, i.`{$v['note']}` AS note,
                   i.`{$v['total']}` AS totalamount, i.`{$v['paid']}` AS paidamount,
                   COALESCE((SELECT SUM(r.`{$r['total']}`) FROM `{$r['table']}` r WHERE r.`{$r['invoiceid']}` = i.`{$v['id']}`), 0) AS returnedamount,
                   COALESCE((SELECT SUM(f.`{$f['amount']}`) FROM `{$f['table']}` f WHERE f.`{$f['invoiceid']}` = i.`{$v['id']}`), 0) AS refundedamount,
                   COALESCE((SELECT SUM(x.`{$it['quantity']}`) FROM `{$it['table']}` x WHERE x.`{$it['invoiceid']}` = i.`{$v['id']}` AND x.`{$it['itemtype']}` = 'Livestock'), 0) AS soldqty,
                   COALESCE((SELECT SUM(q.`{$ri['quantity']}`) FROM `{$ri['table']}` q INNER JOIN `{$r['table']}` r2 ON r2.`{$r['id']}` = q.`{$ri['returnid']}` WHERE r2.`{$r['invoiceid']}` = i.`{$v['id']}`), 0) AS returnedqty
              FROM `{$v['table']}` i
        LEFT JOIN customers c ON c.customerid = i.`{$v['customerid']}` " . $tail;
}

/**
 * Turn an invoice row of dt_sale_sql() into the payload used by the sales list, the invoice, the return and the refund modals.
 * All arithmetic is done in integer minor units: net total = total - returns; balance = net - (paid - refunded), never below 0.
 *
 * @param array<string,mixed> $r Row of dt_sale_sql().
 *
 * @return array<string,mixed> Invoice payload (money as decimal text).
 */
function dt_sale_row(array $r): array
{
    $total    = dt_sum_to_minor((string)$r['totalamount']);
    $paid     = dt_sum_to_minor((string)$r['paidamount']);
    $returned = dt_sum_to_minor((string)$r['returnedamount']);
    $refunded = dt_sum_to_minor((string)$r['refundedamount']);

    $net     = $total - $returned;
    $netPaid = $paid - $refunded;
    $balance = $net - $netPaid;
    $due     = max(0, $balance);
    $credit  = max(0, -$balance);
    $status  = $due <= 0 ? 'Paid' : ($netPaid > 0 ? 'Partial' : 'Unpaid');

    return [
        'invoiceid'      => (int)$r['invoiceid'],
        'customerid'     => (int)$r['customerid'],
        'customername'   => (string)$r['customername'],
        'primarycontact' => (string)($r['primarycontact'] ?? ''),
        'address'        => (string)($r['address'] ?? ''),
        'invoicedate'    => (string)$r['invoicedate'],
        'reference'      => (string)($r['reference'] ?? ''),
        'note'           => (string)($r['note'] ?? ''),
        'total'          => dt_signed_decimal($total),
        'returned'       => dt_signed_decimal($returned),
        'net'            => dt_signed_decimal($net),
        'paid'           => dt_signed_decimal($paid),
        'refunded'       => dt_signed_decimal($refunded),
        'balance'        => dt_minor_to_decimal($due, 2),
        'credit'         => dt_minor_to_decimal($credit, 2),
        'status'         => $status,
        'returnable'     => max(0, (int)$r['soldqty'] - (int)$r['returnedqty']),
        'refundable'     => dt_minor_to_decimal(max(0, $paid - $refunded), 2),
    ];
}

/**
 * Load and lock (FOR UPDATE) an invoice row, with the customer name. Call inside a transaction.
 *
 * @param PDO    $pdo   Connection.
 * @param int    $id    Invoice id.
 * @param string $field Field key returned to the browser when the invoice is not found (empty for none).
 *
 * @return array<string,mixed> Row (invoiceid, customerid, invoicedate, totalamount, paidamount, customername).
 *
 * @throws DtFail When not found.
 */
function dt_lock_invoice(PDO $pdo, int $id, string $field = ''): array
{
    $v   = BF_INVOICES;
    $row = dt_q(
        $pdo,
        "SELECT `{$v['id']}` AS invoiceid, `{$v['customerid']}` AS customerid, `{$v['date']}` AS invoicedate,
                `{$v['total']}` AS totalamount, `{$v['paid']}` AS paidamount
           FROM `{$v['table']}` WHERE `{$v['id']}` = :id FOR UPDATE",
        ['id' => $id]
    )->fetch();
    if (!is_array($row)) {
        throw new DtFail('Invoice not found.', false, $field);
    }
    $name                = dt_q($pdo, 'SELECT name FROM customers WHERE customerid = :id', ['id' => (int)$row['customerid']])->fetchColumn();
    $row['customername'] = $name === false ? '' : (string)$name;

    return $row;
}

/**
 * Read and validate the posted return fields and lines (used by returnsave, before the transaction).
 * The posted lines are the rows of the modal in order (quantity 0 means "not returned"); "Line N" is the row number.
 * Prices and totals are never read from the browser: they come from the invoice lines on the server.
 *
 * @return array{invoiceid:int,date:string,note:?string,lines:list<array{n:int,itemid:int,qty:int}>} Clean fields.
 *
 * @throws DtFail On invalid input.
 */
function dt_read_return_input(): array
{
    $invoiceId = max(0, (int)($_POST['invoiceid'] ?? 0));
    $rawDate   = trim((string)($_POST['returndate'] ?? ''));
    $note      = trim((string)($_POST['note'] ?? ''));

    if ($invoiceId <= 0) {
        throw new DtFail('Invoice is required.', false, 'invoiceid');
    }
    $date = dt_parse_date($rawDate, 'Return date must be a valid date.', 'returndate');
    if (mb_strlen($note) > DT_MAX_NOTE) {
        throw new DtFail('Note is too long (maximum ' . DT_MAX_NOTE . ' characters).', false, 'note');
    }

    $decoded = json_decode((string)($_POST['lines'] ?? ''), true);
    if (!is_array($decoded) || $decoded === []) {
        throw new DtFail('Add at least one line.', false, 'lines');
    }
    if (count($decoded) > DT_MAX_LINES) {
        throw new DtFail('A return can have at most ' . DT_MAX_LINES . ' lines.', false, 'lines');
    }

    $lines = [];
    $seen  = [];
    $any   = false;
    foreach (array_values($decoded) as $k => $raw) {
        $n = $k + 1;
        if (!is_array($raw)) {
            throw new DtFail('Line ' . $n . ' is not valid.', false, 'lines');
        }
        $itemId = max(0, (int)($raw['invoiceitemid'] ?? 0));
        if ($itemId <= 0 || isset($seen[$itemId])) {
            throw new DtFail('Line ' . $n . ' is not valid.', false, 'lines');
        }
        $seen[$itemId] = true;
        $rawQty        = trim((string)($raw['quantity'] ?? ''));
        if (!preg_match('/^\d{1,' . DT_QTY_INT_DIGITS . '}$/D', $rawQty)) {
            throw new DtFail('Line ' . $n . ': quantity must be a whole number, 0 or more.', false, 'lines:' . $n . ':quantity');
        }
        $qty = (int)$rawQty;
        if ($qty > 0) {
            $any = true;
        }
        $lines[] = ['n' => $n, 'itemid' => $itemId, 'qty' => $qty];
    }
    if (!$any) {
        throw new DtFail('Enter a quantity to return on at least one line.', false, 'lines');
    }

    return ['invoiceid' => $invoiceId, 'date' => $date, 'note' => $note === '' ? null : $note, 'lines' => $lines];
}

/**
 * Read and validate the posted refund fields (used by refundsave, before the transaction).
 *
 * @return array{invoiceid:int,date:string,returnid:int,amount:int,accountid:int,note:?string} Clean fields (amount in minor units).
 *
 * @throws DtFail On invalid input.
 */
function dt_read_refund_input(): array
{
    $invoiceId = max(0, (int)($_POST['invoiceid'] ?? 0));
    $rawDate   = trim((string)($_POST['refunddate'] ?? ''));
    $returnId  = max(0, (int)($_POST['returnid'] ?? 0));
    $rawAmount = trim((string)($_POST['amount'] ?? ''));
    $accountId = max(0, (int)($_POST['accountid'] ?? 0));
    $note      = trim((string)($_POST['note'] ?? ''));

    if ($invoiceId <= 0) {
        throw new DtFail('Invoice is required.', false, 'invoiceid');
    }
    $date   = dt_parse_date($rawDate, 'Refund date must be a valid date.', 'refunddate');
    $amount = dt_parse_decimal($rawAmount, DT_TOTAL_INT_DIGITS, 2, true, 'Amount must be greater than 0, with up to 2 decimals.', 'amount');
    if ($accountId <= 0) {
        throw new DtFail('Select the account the refund is paid from.', false, 'accountid');
    }
    if (mb_strlen($note) > DT_MAX_NOTE) {
        throw new DtFail('Note is too long (maximum ' . DT_MAX_NOTE . ' characters).', false, 'note');
    }

    return ['invoiceid' => $invoiceId, 'date' => $date, 'returnid' => $returnId, 'amount' => $amount, 'accountid' => $accountId, 'note' => $note === '' ? null : $note];
}

/**
 * Write a sale return activity row (call before commit). Without login the user is unknown: userid is NULL.
 *
 * @param PDO    $pdo    Connection.
 * @param string $action Add | Update | Delete.
 * @param string $text   Description.
 *
 * @return void
 */
function dt_log_salereturn(PDO $pdo, string $action, string $text): void
{
    dt_q(
        $pdo,
        'INSERT INTO activitylogs (userid, action, description, activitydate) VALUES (NULL, :a, :d, NOW())',
        ['a' => $action, 'd' => mb_substr($text, 0, 255)]
    );
}

/**
 * Write a sale refund activity row (call before commit). Without login the user is unknown: userid is NULL.
 *
 * @param PDO    $pdo    Connection.
 * @param string $action Add | Update | Delete.
 * @param string $text   Description.
 *
 * @return void
 */
function dt_log_salerefund(PDO $pdo, string $action, string $text): void
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
 * List all livestock ordered by name.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_list(PDO $pdo): array
{
    $s    = BF_LIVESTOCK;
    $rows = dt_q(
        $pdo,
        "SELECT `{$s['id']}` AS livestockid, `{$s['name']}` AS name, `{$s['type']}` AS type, `{$s['stock']}` AS currentstock,
                `{$s['cost']}` AS costeach, `{$s['price']}` AS sellingprice, `{$s['stage']}` AS stage
           FROM `{$s['table']}` ORDER BY `{$s['name']}` ASC"
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'livestockid'  => (int)$r['livestockid'],
            'name'         => (string)($r['name'] ?? ''),
            'type'         => (string)($r['type'] ?? ''),
            'currentstock' => (int)($r['currentstock'] ?? 0),
            'costeach'     => round((float)($r['costeach'] ?? 0), 2),
            'sellingprice' => round((float)($r['sellingprice'] ?? 0), 2),
            'stage'        => (string)($r['stage'] ?? ''),
        ];
    }

    return ['success' => true, 'data' => $out];
}

/** Old version, never called (delete it when you want). */
function dt_act_list_OLD(PDO $pdo): array
{
    $s    = BF_LIVESTOCK;
    $rows = dt_q(
        $pdo,
        "SELECT `{$s['id']}` AS livestockid, `{$s['name']}` AS name, `{$s['type']}` AS type, `{$s['stock']}` AS currentstock,
                `{$s['cost']}` AS unitcost, `{$s['price']}` AS salesprice, `{$s['stage']}` AS stage
           FROM `{$s['table']}` ORDER BY `{$s['name']}` ASC"
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'livestockid'  => (int)$r['livestockid'],
            'name'         => (string)($r['name'] ?? ''),
            'type'         => (string)($r['type'] ?? ''),
            'currentstock' => (int)($r['currentstock'] ?? 0),
            'unitcost'     => round((float)($r['unitcost'] ?? 0), 2),
            'salesprice'   => round((float)($r['salesprice'] ?? 0), 2),
            'stage'        => (string)($r['stage'] ?? ''),
        ];
    }

    return ['success' => true, 'data' => $out];
}

/**
 * Add (id = 0) or edit a livestock item (one transaction).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input, a duplicate name or a missing row.
 */
function dt_act_save(PDO $pdo): array
{
    $d = dt_read_livestock_input();
    $s = BF_LIVESTOCK;

    return dt_transaction(static function (PDO $pdo) use ($d, $s): array {
        if ($d['id'] > 0) {
            dt_lock_livestock($pdo, $d['id']);
            dt_assert_unique_name($pdo, $d['name'], $d['id']);
            $d['type']   = dt_assert_lookup_exists($pdo, BF_LIVESTOCKTYPES, $d['type'], 'type', 'Select a valid type.');
            $d['stage'] = dt_assert_lookup_exists($pdo, BF_LIVESTOCKSTAGES, $d['stage'], 'stage', 'Select a valid stage.');
            dt_q(
                $pdo,
                "UPDATE `{$s['table']}` SET `{$s['name']}` = :n, `{$s['type']}` = :t, `{$s['stock']}` = :q, `{$s['cost']}` = :c, `{$s['price']}` = :p, `{$s['stage']}` = :st WHERE `{$s['id']}` = :id",
                ['n' => $d['name'], 't' => $d['type'], 'q' => $d['stock'], 'c' => $d['cost'], 'p' => $d['price'], 'st' => $d['stage'], 'id' => $d['id']]
            );
            dt_log_livestock($pdo, 'Update', 'Livestock updated: ' . $d['name']);

            return ['success' => true, 'message' => 'Livestock updated successfully.'];
        }

        dt_assert_unique_name($pdo, $d['name'], 0);
        $d['type']   = dt_assert_lookup_exists($pdo, BF_LIVESTOCKTYPES, $d['type'], 'type', 'Select a valid type.');
        $d['stage'] = dt_assert_lookup_exists($pdo, BF_LIVESTOCKSTAGES, $d['stage'], 'stage', 'Select a valid stage.');
        dt_q(
            $pdo,
            "INSERT INTO `{$s['table']}` (`{$s['name']}`, `{$s['type']}`, `{$s['stock']}`, `{$s['cost']}`, `{$s['price']}`, `{$s['stage']}`) VALUES (:n, :t, :q, :c, :p, :st)",
            ['n' => $d['name'], 't' => $d['type'], 'q' => $d['stock'], 'c' => $d['cost'], 'p' => $d['price'], 'st' => $d['stage']]
        );
        dt_log_livestock($pdo, 'Add', 'Livestock added: ' . $d['name']);

        return ['success' => true, 'message' => 'Livestock added successfully.'];
    });
}

function dt_act_save_old(PDO $pdo): array
{
    $d = dt_read_livestock_input();
    $s = BF_LIVESTOCK;

    return dt_transaction(static function (PDO $pdo) use ($d, $s): array {
        if ($d['id'] > 0) {
            dt_lock_livestock($pdo, $d['id']);
            dt_assert_unique_name($pdo, $d['name'], $d['id']);
            dt_q(
                $pdo,
                "UPDATE `{$s['table']}` SET `{$s['name']}` = :n, `{$s['type']}` = :t, `{$s['stock']}` = :q, `{$s['cost']}` = :c, `{$s['price']}` = :p, `{$s['stage']}` = :st WHERE `{$s['id']}` = :id",
                ['n' => $d['name'], 't' => $d['type'], 'q' => $d['stock'], 'c' => $d['cost'], 'p' => $d['price'], 'st' => $d['stage'], 'id' => $d['id']]
            );
            dt_log_livestock($pdo, 'Update', 'Livestock updated: ' . $d['name']);

            return ['success' => true, 'message' => 'Livestock updated successfully.'];
        }

        dt_assert_unique_name($pdo, $d['name'], 0);
        dt_q(
            $pdo,
            "INSERT INTO `{$s['table']}` (`{$s['name']}`, `{$s['type']}`, `{$s['stock']}`, `{$s['cost']}`, `{$s['price']}`, `{$s['stage']}`) VALUES (:n, :t, :q, :c, :p, :st)",
            ['n' => $d['name'], 't' => $d['type'], 'q' => $d['stock'], 'c' => $d['cost'], 'p' => $d['price'], 'st' => $d['stage']]
        );
        dt_log_livestock($pdo, 'Add', 'Livestock added: ' . $d['name']);

        return ['success' => true, 'message' => 'Livestock added successfully.'];
    });
}

/**
 * Delete a livestock item unless other records use it or its stock is not zero (one transaction).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found, still used or the stock is not zero (blocked).
 */
function dt_act_delete(PDO $pdo): array
{
    $id = max(0, (int)($_POST['livestockid'] ?? 0));
    $s  = BF_LIVESTOCK;

    return dt_transaction(static function (PDO $pdo) use ($id, $s): array {
        $row = dt_lock_livestock($pdo, $id);

        $used = dt_count_references($pdo, $id);
        if ($used) {
            throw new DtFail('Cannot delete "' . $row['name'] . '": the livestock item has ' . implode(', ', $used) . '.', true);
        }
        if ((int)$row['stock'] > 0) {
            throw new DtFail('Cannot delete: current stock is not zero.', true);
        }

        dt_q($pdo, "DELETE FROM `{$s['table']}` WHERE `{$s['id']}` = :id", ['id' => $id]);
        dt_log_livestock($pdo, 'Delete', 'Livestock deleted: ' . $row['name']);

        return ['success' => true, 'message' => 'Livestock deleted successfully.'];
    });
}

/**
 * Options for the purchase modal: suppliers, livestock (with current stock) and the Assets sub-accounts that can pay (with their balance).
 * The suppliers table has no status column, so every supplier is listed.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_purchaselookups(PDO $pdo): array
{
    $l = BF_LIVESTOCK;

    $suppliers = [];
    foreach (dt_q($pdo, 'SELECT supplierid, name FROM suppliers WHERE LOWER(name) <> LOWER(:n) ORDER BY name ASC', ['n' => BF_NO_SUPPLIER_NAME])->fetchAll() as $row) {
        $suppliers[] = ['supplierid' => (int)$row['supplierid'], 'name' => (string)$row['name']];
    }

    $livestock = [];
    foreach (dt_q($pdo, "SELECT `{$l['id']}` AS livestockid, `{$l['name']}` AS name, `{$l['type']}` AS type, `{$l['stock']}` AS currentstock FROM `{$l['table']}` ORDER BY `{$l['name']}` ASC")->fetchAll() as $row) {
        $livestock[] = [
            'livestockid'  => (int)$row['livestockid'],
            'name'         => (string)$row['name'],
            'type'         => (string)$row['type'],
            'currentstock' => (int)$row['currentstock'],
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

    return ['success' => true, 'data' => [
        'suppliers' => $suppliers,
        'livestock' => $livestock,
        'accounts'  => $accounts,
        'types'     => dt_lookup_types($pdo),
        'stages'    => dt_lookup_stages($pdo),
    ]];
}

function dt_act_purchaselookups_v1(PDO $pdo): array
{
    $l = BF_LIVESTOCK;

    $suppliers = [];
    foreach (dt_q($pdo, 'SELECT supplierid, name FROM suppliers WHERE LOWER(name) <> LOWER(:n) ORDER BY name ASC', ['n' => BF_NO_SUPPLIER_NAME])->fetchAll() as $row) {
        $suppliers[] = ['supplierid' => (int)$row['supplierid'], 'name' => (string)$row['name']];
    }

    $livestock = [];
    foreach (dt_q($pdo, "SELECT `{$l['id']}` AS livestockid, `{$l['name']}` AS name, `{$l['type']}` AS type, `{$l['stock']}` AS currentstock FROM `{$l['table']}` ORDER BY `{$l['name']}` ASC")->fetchAll() as $row) {
        $livestock[] = [
            'livestockid'  => (int)$row['livestockid'],
            'name'         => (string)$row['name'],
            'type'         => (string)$row['type'],
            'currentstock' => (int)$row['currentstock'],
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

    return ['success' => true, 'data' => ['suppliers' => $suppliers, 'livestock' => $livestock, 'accounts' => $accounts]];
}

/** Old version that also listed the None row, no longer called (delete it when you want). */
function dt_act_purchaselookups_old(PDO $pdo): array
{
    $l = BF_LIVESTOCK;

    $suppliers = [];
    foreach (dt_q($pdo, 'SELECT supplierid, name FROM suppliers ORDER BY name ASC')->fetchAll() as $row) {
        $suppliers[] = ['supplierid' => (int)$row['supplierid'], 'name' => (string)$row['name']];
    }

    $livestock = [];
    foreach (dt_q($pdo, "SELECT `{$l['id']}` AS livestockid, `{$l['name']}` AS name, `{$l['type']}` AS type, `{$l['stock']}` AS currentstock FROM `{$l['table']}` ORDER BY `{$l['name']}` ASC")->fetchAll() as $row) {
        $livestock[] = [
            'livestockid'  => (int)$row['livestockid'],
            'name'         => (string)$row['name'],
            'type'         => (string)$row['type'],
            'currentstock' => (int)$row['currentstock'],
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

    return ['success' => true, 'data' => ['suppliers' => $suppliers, 'livestock' => $livestock, 'accounts' => $accounts]];
}

/**
 * Create a livestock purchase: header, one line, stock and payment (one transaction).
 * Same rows as a purchase saved in pages/purchases.php, so it can be edited or deleted there.
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
        $s = BF_LIVESTOCK;

        if ($d['nosupplier']) {
            $d['supplierid'] = dt_none_supplier_id($pdo);
        }

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

        /* a. Lock the livestock row. */
        $animal = dt_lock_livestock($pdo, $d['livestockid'], 'livestockid');

        /* b. Stock limit. */
        if ((int)$animal['stock'] + $d['qty'] > DT_MAX_STOCK) {
            throw new DtFail('Cannot purchase: ' . $animal['name'] . ' stock would exceed ' . number_format(DT_MAX_STOCK) . '.', false, 'quantity');
        }

        /* c. Balance rule. */
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

        /* d. Purchase header and its single livestock line. */
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
            "INSERT INTO `{$i['table']}` (`{$i['purchaseid']}`, `{$i['itemtype']}`, `{$i['livestockid']}`, `{$i['productid']}`, `{$i['quantity']}`, `{$i['costeach']}`, `{$i['sellingprice']}`, `{$i['purchasecost']}`, `{$i['stockadded']}`)
             VALUES (:p, 'Livestock', :l, NULL, :q, :u, :sp, :lt, :sa)",
            [
                'p'  => $purchaseId,
                'l'  => $d['livestockid'],
                'q'  => dt_minor_to_decimal($d['qty'] * (10 ** DT_QTY_SCALE), DT_QTY_SCALE),
                'u'  => dt_minor_to_decimal($d['costeach'], 2),
                'sp' => dt_minor_to_decimal($d['sellingprice'], 2),
                'lt' => dt_minor_to_decimal($d['total'], 2),
                'sa' => $d['qty'],
            ]
        );

        /* e. Stock. */
        dt_q(
            $pdo,
            "UPDATE `{$s['table']}` SET `{$s['stock']}` = `{$s['stock']}` + :q WHERE `{$s['id']}` = :id",
            ['q' => $d['qty'], 'id' => $d['livestockid']]
        );

        /* e2. The latest purchase decides Cost Each and Selling Price of the livestock item (row already locked). */
        dt_q(
            $pdo,
            "UPDATE `{$s['table']}` SET `{$s['cost']}` = :c, `{$s['price']}` = :p WHERE `{$s['id']}` = :id",
            ['c' => dt_minor_to_decimal($d['costeach'], 2), 'p' => dt_minor_to_decimal($d['sellingprice'], 2), 'id' => $d['livestockid']]
        );

        /* f. Automatic payment row. */
        if ($d['paid'] > 0) {
            dt_sync_payment($pdo, $purchaseId, $d['supplierid'], $d['accountid'], $d['paid'], $d['date']);
        }

        dt_log_purchase($pdo, 'Add', 'Purchase added: #' . $purchaseId . ' ' . $supplier);

        return ['success' => true, 'message' => 'Purchase #' . $purchaseId . ' saved.', 'purchaseid' => $purchaseId];
    });
}

/** Old version without the None supplier, no longer called (delete it when you want). */
function dt_act_purchasesave_old(PDO $pdo): array
{
    $d = dt_read_purchase_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        $p = BF_PURCHASES;
        $i = BF_PURCHASEITEMS;
        $s = BF_LIVESTOCK;

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

        /* a. Lock the livestock row (two quick purchases of the same animal queue here: no lost update). */
        $animal = dt_lock_livestock($pdo, $d['livestockid'], 'livestockid');

        /* b. Stock limit. */
        if ((int)$animal['stock'] + $d['qty'] > DT_MAX_STOCK) {
            throw new DtFail('Cannot purchase: ' . $animal['name'] . ' stock would exceed ' . number_format(DT_MAX_STOCK) . '.', false, 'quantity');
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

        /* d. Purchase header and its single livestock line. */
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
            "INSERT INTO `{$i['table']}` (`{$i['purchaseid']}`, `{$i['itemtype']}`, `{$i['livestockid']}`, `{$i['productid']}`, `{$i['quantity']}`, `{$i['costeach']}`, `{$i['sellingprice']}`, `{$i['purchasecost']}`, `{$i['stockadded']}`)
             VALUES (:p, 'Livestock', :l, NULL, :q, :u, :sp, :lt, :sa)",
            [
                'p'  => $purchaseId,
                'l'  => $d['livestockid'],
                'q'  => dt_minor_to_decimal($d['qty'] * (10 ** DT_QTY_SCALE), DT_QTY_SCALE),
                'u'  => dt_minor_to_decimal($d['costeach'], 2),
                'sp' => dt_minor_to_decimal($d['sellingprice'], 2),
                'lt' => dt_minor_to_decimal($d['total'], 2),
                'sa' => $d['qty'],
            ]
        );

        /* e. Stock. */
        dt_q(
            $pdo,
            "UPDATE `{$s['table']}` SET `{$s['stock']}` = `{$s['stock']}` + :q WHERE `{$s['id']}` = :id",
            ['q' => $d['qty'], 'id' => $d['livestockid']]
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
 * Options for the sale modal: customers, livestock (stock, cost each, selling price) and the Assets sub-accounts that can receive money.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_salelookups(PDO $pdo): array
{
    $rows = dt_q(
        $pdo,
        "SELECT accountid, code, accountname FROM accounts WHERE accounttype = 'Assets' AND status = 'ON' AND parentaccountid IS NOT NULL"
    )->fetchAll();
    usort($rows, static fn (array $a, array $b): int => strnatcmp((string)$a['code'], (string)$b['code']));
    $accounts = [];
    foreach ($rows as $row) {
        $accounts[] = ['accountid' => (int)$row['accountid'], 'code' => (string)$row['code'], 'accountname' => (string)$row['accountname']];
    }

    return ['success' => true, 'data' => [
        'customers' => dt_lookup_customers($pdo),
        'livestock' => dt_lookup_livestock($pdo, true),
        'accounts'  => $accounts,
    ]];
}

/**
 * Create a livestock sale: invoice, one invoice line, stock and receipt (one transaction).
 * Lock order: the livestock row first, then the receiving account row.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_salesave(PDO $pdo): array
{
    $d = dt_read_sale_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        $p = BF_INVOICES;
        $i = BF_INVOICEITEMS;
        $s = BF_LIVESTOCK;

        if ($d['walkin']) {
            $d['customerid'] = dt_walkin_customer_id($pdo);
        }

        $customer = dt_q($pdo, 'SELECT name FROM customers WHERE customerid = :id', ['id' => $d['customerid']])->fetchColumn();
        if ($customer === false) {
            throw new DtFail('The selected customer does not exist.', false, 'customerid');
        }
        $customer = (string)$customer;

        if ($d['paid'] > 0) {
            $type = dt_q($pdo, 'SELECT accounttype FROM accounts WHERE accountid = :id', ['id' => $d['accountid']])->fetchColumn();
            if ($type === false) {
                throw new DtFail('The selected account does not exist.', false, 'receivedinaccountid');
            }
            if ((string)$type !== 'Assets') {
                throw new DtFail('The receiving account must be an Assets account.', false, 'receivedinaccountid');
            }
        }

        /* a. Lock all livestock rows in ascending id order, then the account. */
        $first = [];
        foreach ($d['lines'] as $k => $l) {
            $first[$l['livestockid']] ??= $k + 1;
        }
        $animals = dt_lock_livestock_set($pdo, array_keys($first), $first);
        if ($d['paid'] > 0) {
            dt_lock_accounts($pdo, [$d['accountid']]);
        }

        /* b. Stock rule: SUM per animal. */
        $available = [];
        foreach ($animals as $lid => $a) {
            $available[$lid] = (int)$a['stock'];
        }
        $taken = dt_check_sale_stock($d['lines'], $available, $animals);

        /* c. Invoice and one line per posted line (cost each = the animal's cost today). */
        dt_q(
            $pdo,
            "INSERT INTO `{$p['table']}` (`{$p['customerid']}`, `{$p['date']}`, `{$p['reference']}`, `{$p['total']}`, `{$p['paid']}`, `{$p['account']}`, `{$p['note']}`)
             VALUES (:cus, :dt, :ref, :tot, :pd, :acc, :nt)",
            [
                'cus' => $d['customerid'],
                'dt'  => $d['date'],
                'ref' => $d['reference'],
                'tot' => dt_minor_to_decimal($d['total'], 2),
                'pd'  => dt_minor_to_decimal($d['paid'], 2),
                'acc' => $d['paid'] > 0 ? $d['accountid'] : null,
                'nt'  => $d['note'],
            ]
        );
        $invoiceId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare(
            "INSERT INTO `{$i['table']}` (`{$i['invoiceid']}`, `{$i['itemtype']}`, `{$i['livestockid']}`, `{$i['productid']}`, `{$i['quantity']}`, `{$i['sellingprice']}`, `{$i['costeach']}``, `{$i['linetotal']}`)
             VALUES (:p, 'Livestock', :l, NULL, :q, :u, :uc, :lt)"
        );
        foreach ($d['lines'] as $l) {
            $ins->execute([
                'p'  => $invoiceId,
                'l'  => $l['livestockid'],
                'q'  => $l['qty'],
                'u'  => dt_minor_to_decimal($l['price'], 2),
                'uc' => (string)$animals[$l['livestockid']]['costeach'],
                'lt' => dt_minor_to_decimal($l['total'], 2),
            ]);
        }

        /* d. Stock (never below 0), ascending id order. */
        ksort($taken);
        foreach ($taken as $lid => $qty) {
            dt_q($pdo, "UPDATE `{$s['table']}` SET `{$s['stock']}` = :q WHERE `{$s['id']}` = :id", ['q' => max(0, $available[$lid] - $qty), 'id' => $lid]);
        }

        /* e. Automatic receipt. */
        if ($d['paid'] > 0) {
            dt_sync_receipt($pdo, $invoiceId, $d['customerid'], $d['accountid'], $d['paid'], $d['date']);
        }

        dt_log_sale($pdo, 'Add', 'Sale added: #' . $invoiceId . ' ' . $customer);

        return ['success' => true, 'message' => 'Sale #' . $invoiceId . ' saved.', 'invoiceid' => $invoiceId];
    });
}

/** Replaced by the Walk-in version above (not called, delete it when you want). */
function dt_act_salesave_prev(PDO $pdo): array
{
    $d = dt_read_sale_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        $p = BF_INVOICES;
        $i = BF_INVOICEITEMS;
        $s = BF_LIVESTOCK;

        $customer = dt_q($pdo, 'SELECT name FROM customers WHERE customerid = :id', ['id' => $d['customerid']])->fetchColumn();
        if ($customer === false) {
            throw new DtFail('The selected customer does not exist.', false, 'customerid');
        }
        $customer = (string)$customer;

        if ($d['paid'] > 0) {
            $type = dt_q($pdo, 'SELECT accounttype FROM accounts WHERE accountid = :id', ['id' => $d['accountid']])->fetchColumn();
            if ($type === false) {
                throw new DtFail('The selected account does not exist.', false, 'receivedinaccountid');
            }
            if ((string)$type !== 'Assets') {
                throw new DtFail('The receiving account must be an Assets account.', false, 'receivedinaccountid');
            }
        }

        /* a. Lock all livestock rows in ascending id order, then the account. */
        $first = [];
        foreach ($d['lines'] as $k => $l) {
            $first[$l['livestockid']] ??= $k + 1;
        }
        $animals = dt_lock_livestock_set($pdo, array_keys($first), $first);
        if ($d['paid'] > 0) {
            dt_lock_accounts($pdo, [$d['accountid']]);
        }

        /* b. Stock rule: SUM per animal. */
        $available = [];
        foreach ($animals as $lid => $a) {
            $available[$lid] = (int)$a['stock'];
        }
        $taken = dt_check_sale_stock($d['lines'], $available, $animals);

        /* c. Invoice and one line per posted line (cost each = the animal's cost today). */
        dt_q(
            $pdo,
            "INSERT INTO `{$p['table']}` (`{$p['customerid']}`, `{$p['date']}`, `{$p['reference']}`, `{$p['total']}`, `{$p['paid']}`, `{$p['account']}`, `{$p['note']}`)
             VALUES (:cus, :dt, :ref, :tot, :pd, :acc, :nt)",
            [
                'cus' => $d['customerid'],
                'dt'  => $d['date'],
                'ref' => $d['reference'],
                'tot' => dt_minor_to_decimal($d['total'], 2),
                'pd'  => dt_minor_to_decimal($d['paid'], 2),
                'acc' => $d['paid'] > 0 ? $d['accountid'] : null,
                'nt'  => $d['note'],
            ]
        );
        $invoiceId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare(
            "INSERT INTO `{$i['table']}` (`{$i['invoiceid']}`, `{$i['itemtype']}`, `{$i['livestockid']}`, `{$i['productid']}`, `{$i['quantity']}`, `{$i['sellingprice']}`, `{$i['costeach']}``, `{$i['linetotal']}`)
             VALUES (:p, 'Livestock', :l, NULL, :q, :u, :uc, :lt)"
        );
        foreach ($d['lines'] as $l) {
            $ins->execute([
                'p'  => $invoiceId,
                'l'  => $l['livestockid'],
                'q'  => $l['qty'],
                'u'  => dt_minor_to_decimal($l['price'], 2),
                'uc' => (string)$animals[$l['livestockid']]['costeach'],
                'lt' => dt_minor_to_decimal($l['total'], 2),
            ]);
        }

        /* d. Stock (never below 0), ascending id order. */
        ksort($taken);
        foreach ($taken as $lid => $qty) {
            dt_q($pdo, "UPDATE `{$s['table']}` SET `{$s['stock']}` = :q WHERE `{$s['id']}` = :id", ['q' => max(0, $available[$lid] - $qty), 'id' => $lid]);
        }

        /* e. Automatic receipt. */
        if ($d['paid'] > 0) {
            dt_sync_receipt($pdo, $invoiceId, $d['customerid'], $d['accountid'], $d['paid'], $d['date']);
        }

        dt_log_sale($pdo, 'Add', 'Sale added: #' . $invoiceId . ' ' . $customer);

        return ['success' => true, 'message' => 'Sale #' . $invoiceId . ' saved.', 'invoiceid' => $invoiceId];
    });
}

/** Old one-animal version, no longer used (delete it when you want). */
function dt_act_salesave_old(PDO $pdo): array
{
    $d = dt_read_sale_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        $p = BF_INVOICES;
        $i = BF_INVOICEITEMS;
        $s = BF_LIVESTOCK;

        $customer = dt_q($pdo, 'SELECT name FROM customers WHERE customerid = :id', ['id' => $d['customerid']])->fetchColumn();
        if ($customer === false) {
            throw new DtFail('The selected customer does not exist.', false, 'customerid');
        }
        $customer = (string)$customer;

        if ($d['paid'] > 0) {
            $type = dt_q($pdo, 'SELECT accounttype FROM accounts WHERE accountid = :id', ['id' => $d['accountid']])->fetchColumn();
            if ($type === false) {
                throw new DtFail('The selected account does not exist.', false, 'receivedinaccountid');
            }
            if ((string)$type !== 'Assets') {
                throw new DtFail('The receiving account must be an Assets account.', false, 'receivedinaccountid');
            }
        }

        /* a. Lock the livestock row (two quick sales of the same animal queue here: no lost update), then the account. */
        $animal = dt_lock_livestock($pdo, $d['livestockid'], 'livestockid');
        if ($d['paid'] > 0) {
            dt_lock_accounts($pdo, [$d['accountid']]);
        }

        /* b. Stock rule. */
        $stock = (int)$animal['stock'];
        if ($d['qty'] > $stock) {
            throw new DtFail('Only ' . number_format($stock) . ' of ' . $animal['name'] . ' available.', false, 'quantity');
        }

        /* c. Invoice header and its single livestock line (cost each = the animal's cost today, for profit reports). */
        dt_q(
            $pdo,
            "INSERT INTO `{$p['table']}` (`{$p['customerid']}`, `{$p['date']}`, `{$p['reference']}`, `{$p['total']}`, `{$p['paid']}`, `{$p['account']}`, `{$p['note']}`)
             VALUES (:cus, :dt, :ref, :tot, :pd, :acc, :nt)",
            [
                'cus' => $d['customerid'],
                'dt'  => $d['date'],
                'ref' => $d['reference'],
                'tot' => dt_minor_to_decimal($d['total'], 2),
                'pd'  => dt_minor_to_decimal($d['paid'], 2),
                'acc' => $d['paid'] > 0 ? $d['accountid'] : null,
                'nt'  => $d['note'],
            ]
        );
        $invoiceId = (int)$pdo->lastInsertId();

        dt_q(
            $pdo,
            "INSERT INTO `{$i['table']}` (`{$i['invoiceid']}`, `{$i['itemtype']}`, `{$i['livestockid']}`, `{$i['productid']}`, `{$i['quantity']}`, `{$i['sellingprice']}`, `{$i['costeach']}``, `{$i['linetotal']}`)
             VALUES (:p, 'Livestock', :l, NULL, :q, :u, :uc, :lt)",
            [
                'p'  => $invoiceId,
                'l'  => $d['livestockid'],
                'q'  => $d['qty'],
                'u'  => dt_minor_to_decimal($d['costeach'], 2),
                'sp' => dt_minor_to_decimal($d['sellingprice'], 2),
                'uc' => (string)$animal['unitcost'],
                'lt' => dt_minor_to_decimal($d['total'], 2),
            ]
        );

        /* d. Stock (never below 0). */
        dt_q(
            $pdo,
            "UPDATE `{$s['table']}` SET `{$s['stock']}` = :q WHERE `{$s['id']}` = :id",
            ['q' => max(0, $stock - $d['qty']), 'id' => $d['livestockid']]
        );

        /* e. Automatic receipt row (note marker BF_RECEIPT_NOTE). */
        if ($d['paid'] > 0) {
            dt_sync_receipt($pdo, $invoiceId, $d['customerid'], $d['accountid'], $d['paid'], $d['date']);
        }

        dt_log_sale($pdo, 'Add', 'Sale added: #' . $invoiceId . ' ' . $customer);

        return ['success' => true, 'message' => 'Sale #' . $invoiceId . ' saved.', 'invoiceid' => $invoiceId];
    });
}

/**
 * Options and values for the Edit Sale modal: customers, livestock (stock, cost each, selling price), accounts, the invoice and its lines.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_saleeditlookups(PDO $pdo): array
{
    $id  = max(0, (int)($_POST['invoiceid'] ?? 0));
    $row = dt_q($pdo, dt_sale_sql('WHERE i.invoiceid = :id'), ['id' => $id])->fetch();
    if (!is_array($row)) {
        throw new DtFail('Invoice not found.');
    }
    $v  = BF_INVOICES;
    $it = BF_INVOICEITEMS;

    $account = (int)dt_q($pdo, "SELECT COALESCE(`{$v['account']}`, 0) FROM `{$v['table']}` WHERE `{$v['id']}` = :id", ['id' => $id])->fetchColumn();

    $lines = [];
    $held  = [];
    foreach (dt_q(
        $pdo,
        "SELECT `{$it['itemtype']}` AS itemtype, `{$it['livestockid']}` AS livestockid, `{$it['quantity']}` AS quantity, `{$it['sellingprice']}` AS sellingprice
           FROM `{$it['table']}` WHERE `{$it['invoiceid']}` = :id ORDER BY `{$it['id']}` ASC",
        ['id' => $id]
    )->fetchAll() as $r) {
        if ((string)$r['itemtype'] !== 'Livestock') {
            throw new DtFail('Cannot edit invoice #' . $id . ': it has product lines.', true);
        }
        $lid        = (int)$r['livestockid'];
        $held[$lid] = ($held[$lid] ?? 0) + (int)$r['quantity'];
        $lines[]    = ['livestockid' => $lid, 'quantity' => (int)$r['quantity'], 'sellingprice' => dt_signed_decimal(dt_sum_to_minor((string)$r['sellingprice']))];
    }

    $flags  = dt_invoice_flags($pdo, $id);
    $locked = $flags['returns'] > 0 || $flags['refunds'] > 0;

    $data = dt_act_salelookups($pdo)['data'];
    foreach ($data['livestock'] as $k => $a) {
        $data['livestock'][$k]['available'] = (int)$a['currentstock'] + ($locked ? 0 : ($held[(int)$a['livestockid']] ?? 0));
    }

    return ['success' => true, 'data' => [
        'customers'           => $data['customers'],
        'livestock'           => $data['livestock'],
        'accounts'            => $data['accounts'],
        'invoice'             => dt_sale_row($row),
        'receivedinaccountid' => $account,
        'lines'               => $lines,
        'locked'              => $locked,
        'manual'              => $flags['manual'],
    ]];
}

/**
 * Update a sale (one transaction). Lock order: invoice, old lines, livestock rows (old and new, ascending id), accounts (ascending id).
 * With returns or refunds only date, reference, note and paid amount change (paid never below the refunds made).
 */
function dt_act_saleupdate(PDO $pdo): array
{
    $id = max(0, (int)($_POST['invoiceid'] ?? 0));
    $d  = dt_read_sale_input();

    return dt_transaction(static function (PDO $pdo) use ($id, $d): array {
        $v  = BF_INVOICES;
        $it = BF_INVOICEITEMS;
        $s  = BF_LIVESTOCK;

        $invoice    = dt_lock_invoice($pdo, $id, 'invoiceid');
        if ($d['walkin']) {
            $d['customerid'] = dt_walkin_customer_id($pdo);
        }
        $oldAccount = (int)dt_q($pdo, "SELECT COALESCE(`{$v['account']}`, 0) FROM `{$v['table']}` WHERE `{$v['id']}` = :id", ['id' => $id])->fetchColumn();
        $oldPaid    = dt_sum_to_minor((string)$invoice['paidamount']);
        $oldTotal   = dt_sum_to_minor((string)$invoice['totalamount']);
        $flags      = dt_invoice_flags($pdo, $id);
        $locked     = $flags['returns'] > 0 || $flags['refunds'] > 0;

        $old = [];
        foreach (dt_q(
            $pdo,
            "SELECT `{$it['itemtype']}` AS itemtype, `{$it['livestockid']}` AS livestockid, `{$it['quantity']}` AS quantity,
                    `{$it['sellingprice']}` AS sellingprice, `{$it['costeach']}` AS costeach, `{$it['linetotal']}` AS linetotal
               FROM `{$it['table']}` WHERE `{$it['invoiceid']}` = :id ORDER BY `{$it['id']}` ASC FOR UPDATE",
            ['id' => $id]
        )->fetchAll() as $row) {
            if ((string)$row['itemtype'] !== 'Livestock') {
                throw new DtFail('Cannot change invoice #' . $id . ': it has product lines.', true);
            }
            $old[] = [
                'livestockid' => (int)$row['livestockid'],
                'qty'         => (int)$row['quantity'],
                'price'       => dt_decimal_to_minor((string)$row['sellingprice'], 2),
                'total'       => dt_decimal_to_minor((string)$row['linetotal'], 2),
                'cost'        => (string)$row['costeach'],
            ];
        }

        $customer = dt_q($pdo, 'SELECT name FROM customers WHERE customerid = :id', ['id' => $d['customerid']])->fetchColumn();
        if ($customer === false) {
            throw new DtFail('The selected customer does not exist.', false, 'customerid');
        }
        $customer = (string)$customer;
        if ($locked) {
            if ($d['customerid'] !== (int)$invoice['customerid']) {
                throw new DtFail('The customer cannot change: this invoice has returns or refunds.', false, 'customerid');
            }
            if ($flags['firstdate'] !== null && $d['date'] > $flags['firstdate']) {
                throw new DtFail('Sale date cannot be after the first return or refund (' . $flags['firstdate'] . ').', false, 'saledate');
            }
        }

        if ($locked) {
            $lines = [];
            foreach ($old as $o) {
                $lines[] = ['livestockid' => $o['livestockid'], 'qty' => $o['qty'], 'price' => $o['price'], 'total' => $o['total']];
            }
            $total = $oldTotal;
        } else {
            $lines = $d['lines'];
            $total = $d['total'];
        }

        $paid = $d['paid'];
        if ($d['walkin'] && $paid !== $total) {
            throw new DtFail('A walk-in sale must be paid in full.', false, 'paidamount');
        }
        if ($paid > $total) {
            throw new DtFail('Paid amount cannot be more than the total.', false, 'paidamount');
        }
        if ($paid < $flags['refunded']) {
            throw new DtFail('Paid amount cannot be less than the refunds already made (' . dt_format_minor($flags['refunded']) . ').', false, 'paidamount');
        }
        if ($flags['manual'] && $paid !== $oldPaid) {
            throw new DtFail('Cannot change the paid amount: this invoice has receipts entered on the Receipts page. Change those receipts instead.', false, 'paidamount');
        }
        $newAccount = $flags['manual'] ? $oldAccount : ($paid > 0 ? $d['accountid'] : 0);
        if (!$flags['manual'] && $paid > 0) {
            $type = dt_q($pdo, 'SELECT accounttype FROM accounts WHERE accountid = :id', ['id' => $newAccount])->fetchColumn();
            if ($type === false) {
                throw new DtFail('The selected account does not exist.', false, 'receivedinaccountid');
            }
            if ((string)$type !== 'Assets') {
                throw new DtFail('The receiving account must be an Assets account.', false, 'receivedinaccountid');
            }
        }

        /* Locks: livestock rows (old and new, ascending), then accounts (ascending). */
        $animals = [];
        if (!$locked) {
            $first = [];
            foreach ($lines as $k => $l) {
                $first[$l['livestockid']] ??= $k + 1;
            }
            $ids = array_keys($first);
            foreach ($old as $o) {
                $ids[] = $o['livestockid'];
            }
            $animals = dt_lock_livestock_set($pdo, $ids, $first);
        }
        dt_lock_accounts($pdo, [$oldAccount, $newAccount]);

        /* Stock: put the old quantities back, then take the new ones out. */
        if (!$locked) {
            $available = [];
            foreach ($animals as $lid => $a) {
                $available[$lid] = (int)$a['stock'];
            }
            $back = [];
            foreach ($old as $o) {
                $back[$o['livestockid']] = ($back[$o['livestockid']] ?? 0) + $o['qty'];
            }
            foreach ($back as $lid => $qty) {
                $available[$lid] += $qty;
                if ($available[$lid] > DT_MAX_STOCK) {
                    throw new DtFail('Cannot change this invoice: ' . $animals[$lid]['name'] . ' stock would exceed ' . number_format(DT_MAX_STOCK) . '.', false, 'lines');
                }
            }
            $taken = dt_check_sale_stock($lines, $available, $animals);
            ksort($available);
            foreach ($available as $lid => $qty) {
                $new = max(0, $qty - ($taken[$lid] ?? 0));
                if ($new !== (int)$animals[$lid]['stock']) {
                    dt_q($pdo, "UPDATE `{$s['table']}` SET `{$s['stock']}` = :q WHERE `{$s['id']}` = :id", ['q' => $new, 'id' => $lid]);
                }
            }
        }

        /* Header. */
        dt_q(
            $pdo,
            "UPDATE `{$v['table']}` SET `{$v['customerid']}` = :cus, `{$v['date']}` = :dt, `{$v['reference']}` = :ref, `{$v['total']}` = :tot,
                    `{$v['paid']}` = :pd, `{$v['account']}` = :acc, `{$v['note']}` = :nt WHERE `{$v['id']}` = :id",
            [
                'cus' => $d['customerid'],
                'dt'  => $d['date'],
                'ref' => $d['reference'],
                'tot' => dt_minor_to_decimal($total, 2),
                'pd'  => dt_minor_to_decimal($paid, 2),
                'acc' => $newAccount > 0 ? $newAccount : null,
                'nt'  => $d['note'],
                'id'  => $id,
            ]
        );

        /* Lines: replaced only without returns/refunds; an item already on the invoice keeps its old cost each. */
        if (!$locked) {
            $costOf = [];
            foreach ($old as $o) {
                $costOf[$o['livestockid']] ??= $o['cost'];
            }
            dt_q($pdo, "DELETE FROM `{$it['table']}` WHERE `{$it['invoiceid']}` = :id", ['id' => $id]);
            $ins = $pdo->prepare(
                "INSERT INTO `{$it['table']}` (`{$it['invoiceid']}`, `{$it['itemtype']}`, `{$it['livestockid']}`, `{$it['productid']}`, `{$it['quantity']}`, `{$it['sellingprice']}`, `{$it['costeach']}``, `{$it['linetotal']}`)
                 VALUES (:p, 'Livestock', :l, NULL, :q, :u, :uc, :lt)"
            );
            foreach ($lines as $l) {
                $ins->execute([
                    'p'  => $id,
                    'l'  => $l['livestockid'],
                    'q'  => $l['qty'],
                    'u'  => dt_minor_to_decimal($l['price'], 2),
                    'uc' => $costOf[$l['livestockid']] ?? (string)$animals[$l['livestockid']]['costeach'],
                    'lt' => dt_minor_to_decimal($l['total'], 2),
                ]);
            }
        }

        /* Automatic receipt (skipped when receipts were entered on the Receipts page). */
        if (!$flags['manual']) {
            dt_sync_receipt($pdo, $id, $d['customerid'], $newAccount, $paid, $d['date']);

            /* Balance rule: money taken out of an account must not make it negative. */
            $changes = [];
            if ($oldAccount > 0 && $oldPaid > 0) {
                $changes[$oldAccount] = ($changes[$oldAccount] ?? 0) - $oldPaid;
            }
            if ($newAccount > 0 && $paid > 0) {
                $changes[$newAccount] = ($changes[$newAccount] ?? 0) + $paid;
            }
            ksort($changes);
            foreach ($changes as $aid => $change) {
                if ($change >= 0) {
                    continue;
                }
                $balance = dt_account_balances($pdo, $aid)[$aid] ?? 0;
                if ($balance < 0) {
                    $acc   = dt_q($pdo, 'SELECT code, accountname FROM accounts WHERE accountid = :id', ['id' => $aid])->fetch();
                    $label = is_array($acc) ? (string)$acc['code'] . ' ' . (string)$acc['accountname'] : '#' . $aid;
                    throw new DtFail('Insufficient balance in ' . $label . ': available ' . dt_format_minor($balance - $change) . ', required ' . dt_format_minor(-$change) . '.', false, 'paidamount');
                }
            }
        }

        dt_log_sale($pdo, 'Update', 'Sale updated: #' . $id . ' ' . $customer);

        return ['success' => true, 'message' => 'Invoice #' . $id . ' updated.', 'invoiceid' => $id];
    });
}

function dt_act_quotationlookups(PDO $pdo): array
{
    return ['success' => true, 'data' => [
        'customers' => dt_lookup_customers($pdo),
        'livestock' => dt_lookup_livestock($pdo, false),
    ]];
}

/**
 * Create a quotation with its lines (one transaction). A quotation never changes stock: no stock lock.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_quotationsave(PDO $pdo): array
{
    $d = dt_read_quotation_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        $p = BF_QUOTATIONS;
        $i = BF_QUOTATIONITEMS;
        $s = BF_LIVESTOCK;

        if ($d['walkin']) {
            $d['customerid'] = dt_walkin_customer_id($pdo);
        }

        $customer = dt_q($pdo, 'SELECT name FROM customers WHERE customerid = :id', ['id' => $d['customerid']])->fetchColumn();
        if ($customer === false) {
            throw new DtFail('The selected customer does not exist.', false, 'customerid');
        }
        $customer = (string)$customer;

        /* Every quoted livestock item must exist. */
        $ids = [];
        foreach ($d['lines'] as $l) {
            $ids[$l['livestockid']] = true;
        }
        $ids    = array_keys($ids);
        $marks  = [];
        $params = [];
        foreach ($ids as $k => $id) {
            $marks[]          = ':i' . $k;
            $params['i' . $k] = $id;
        }
        $found = [];
        foreach (dt_q($pdo, "SELECT `{$s['id']}` FROM `{$s['table']}` WHERE `{$s['id']}` IN (" . implode(', ', $marks) . ')', $params)->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $found[(int)$id] = true;
        }
        foreach ($d['lines'] as $k => $l) {
            if (!isset($found[$l['livestockid']])) {
                throw new DtFail('Line ' . ($k + 1) . ': the selected livestock item does not exist.', false, 'lines:' . ($k + 1) . ':livestock');
            }
        }

        dt_q(
            $pdo,
            "INSERT INTO `{$p['table']}` (`{$p['customerid']}`, `{$p['date']}`, `{$p['validuntil']}`, `{$p['reference']}`, `{$p['total']}`, `{$p['status']}`, `{$p['note']}`)
             VALUES (:cus, :dt, :vu, :ref, :tot, :st, :nt)",
            [
                'cus' => $d['customerid'],
                'dt'  => $d['date'],
                'vu'  => $d['validuntil'],
                'ref' => $d['reference'],
                'tot' => dt_minor_to_decimal($d['total'], 2),
                'st'  => $d['status'],
                'nt'  => $d['note'],
            ]
        );
        $quotationId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare(
            "INSERT INTO `{$i['table']}` (`{$i['quotationid']}`, `{$i['livestockid']}`, `{$i['quantity']}`, `{$i['sellingprice']}`, `{$i['linetotal']}`)
             VALUES (:q, :l, :n, :u, :lt)"
        );
        foreach ($d['lines'] as $l) {
            $ins->execute([
                'q'  => $quotationId,
                'l'  => $l['livestockid'],
                'n'  => $l['qty'],
                'u'  => dt_minor_to_decimal($l['price'], 2),
                'lt' => dt_minor_to_decimal($l['total'], 2),
            ]);
        }

        dt_log_quotation($pdo, 'Add', 'Quotation added: #' . $quotationId . ' ' . $customer);

        return ['success' => true, 'message' => 'Quotation #' . $quotationId . ' saved.', 'quotationid' => $quotationId];
    });
}

/** Replaced by the Walk-in version above (not called, delete it when you want). */
function dt_act_quotationsave_prev(PDO $pdo): array
{
    $d = dt_read_quotation_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        $p = BF_QUOTATIONS;
        $i = BF_QUOTATIONITEMS;
        $s = BF_LIVESTOCK;

        $customer = dt_q($pdo, 'SELECT name FROM customers WHERE customerid = :id', ['id' => $d['customerid']])->fetchColumn();
        if ($customer === false) {
            throw new DtFail('The selected customer does not exist.', false, 'customerid');
        }
        $customer = (string)$customer;

        /* Every quoted livestock item must exist. */
        $ids = [];
        foreach ($d['lines'] as $l) {
            $ids[$l['livestockid']] = true;
        }
        $ids    = array_keys($ids);
        $marks  = [];
        $params = [];
        foreach ($ids as $k => $id) {
            $marks[]          = ':i' . $k;
            $params['i' . $k] = $id;
        }
        $found = [];
        foreach (dt_q($pdo, "SELECT `{$s['id']}` FROM `{$s['table']}` WHERE `{$s['id']}` IN (" . implode(', ', $marks) . ')', $params)->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $found[(int)$id] = true;
        }
        foreach ($d['lines'] as $k => $l) {
            if (!isset($found[$l['livestockid']])) {
                throw new DtFail('Line ' . ($k + 1) . ': the selected livestock item does not exist.', false, 'lines:' . ($k + 1) . ':livestock');
            }
        }

        dt_q(
            $pdo,
            "INSERT INTO `{$p['table']}` (`{$p['customerid']}`, `{$p['date']}`, `{$p['validuntil']}`, `{$p['reference']}`, `{$p['total']}`, `{$p['status']}`, `{$p['note']}`)
             VALUES (:cus, :dt, :vu, :ref, :tot, :st, :nt)",
            [
                'cus' => $d['customerid'],
                'dt'  => $d['date'],
                'vu'  => $d['validuntil'],
                'ref' => $d['reference'],
                'tot' => dt_minor_to_decimal($d['total'], 2),
                'st'  => $d['status'],
                'nt'  => $d['note'],
            ]
        );
        $quotationId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare(
            "INSERT INTO `{$i['table']}` (`{$i['quotationid']}`, `{$i['livestockid']}`, `{$i['quantity']}`, `{$i['sellingprice']}`, `{$i['linetotal']}`)
             VALUES (:q, :l, :n, :u, :lt)"
        );
        foreach ($d['lines'] as $l) {
            $ins->execute([
                'q'  => $quotationId,
                'l'  => $l['livestockid'],
                'n'  => $l['qty'],
                'u'  => dt_minor_to_decimal($l['price'], 2),
                'lt' => dt_minor_to_decimal($l['total'], 2),
            ]);
        }

        dt_log_quotation($pdo, 'Add', 'Quotation added: #' . $quotationId . ' ' . $customer);

        return ['success' => true, 'message' => 'Quotation #' . $quotationId . ' saved.', 'quotationid' => $quotationId];
    });
}

/**
 * Sales list: every invoice (newest first) with its customer name and the totals computed in SQL.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_saleslist(PDO $pdo): array
{
    $out = [];
    header('Cache-Control: no-store');
    foreach (dt_q($pdo, dt_sale_sql('ORDER BY i.invoicedate DESC, i.invoiceid DESC'))->fetchAll() as $row) {
        $out[] = dt_sale_row($row);
    }

    return ['success' => true, 'data' => $out];
}

/**
 * One invoice with its lines, returns (with their lines) and refunds.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When the invoice does not exist.
 */
function dt_act_saleget(PDO $pdo): array
{
    $id  = max(0, (int)($_POST['invoiceid'] ?? 0));
    $row = dt_q($pdo, dt_sale_sql('WHERE i.invoiceid = :id'), ['id' => $id])->fetch();
    if (!is_array($row)) {
        throw new DtFail('Invoice not found.');
    }

    $it = BF_INVOICEITEMS;
    $ri = BF_SALERETURNITEMS;
    $l  = BF_LIVESTOCK;
    $rt = BF_SALERETURNS;
    $rf = BF_SALEREFUNDS;

    $lines = [];
    foreach (dt_q(
        $pdo,
        "SELECT x.`{$it['id']}` AS invoiceitemid, x.`{$it['itemtype']}` AS itemtype, x.`{$it['quantity']}` AS quantity,
                x.`{$it['sellingprice']}` AS sellingprice, x.`{$it['linetotal']}` AS linetotal,
                lv.`{$l['name']}` AS livestockname, lv.`{$l['type']}` AS livestocktype, p.name AS productname,
                COALESCE((SELECT SUM(q.`{$ri['quantity']}`) FROM `{$ri['table']}` q WHERE q.`{$ri['invoiceitemid']}` = x.`{$it['id']}`), 0) AS returnedqty
           FROM `{$it['table']}` x
      LEFT JOIN `{$l['table']}` lv ON lv.`{$l['id']}` = x.`{$it['livestockid']}`
      LEFT JOIN products p ON p.productid = x.`{$it['productid']}`
          WHERE x.`{$it['invoiceid']}` = :id
          ORDER BY x.`{$it['id']}` ASC",
        ['id' => $id]
    )->fetchAll() as $r) {
        $isLivestock = (string)$r['itemtype'] === 'Livestock';
        $quantity    = (int)$r['quantity'];
        $returnedQty = (int)$r['returnedqty'];
        $lines[]     = [
            'invoiceitemid' => (int)$r['invoiceitemid'],
            'itemtype'      => (string)$r['itemtype'],
            'name'          => $isLivestock ? (string)($r['livestockname'] ?? '') : (string)($r['productname'] ?? ''),
            'type'          => $isLivestock ? (string)($r['livestocktype'] ?? '') : 'Product',
            'quantity'      => $quantity,
            'returnedqty'   => $returnedQty,
            'returnable'    => $isLivestock ? max(0, $quantity - $returnedQty) : 0,
            'sellingprice'  => dt_signed_decimal(dt_sum_to_minor((string)$r['sellingprice'])),
            'linetotal'     => dt_signed_decimal(dt_sum_to_minor((string)$r['linetotal'])),
        ];
    }

    $returns = [];
    foreach (dt_q(
        $pdo,
        "SELECT `{$rt['id']}` AS returnid, `{$rt['date']}` AS returndate, `{$rt['total']}` AS totalamount, `{$rt['note']}` AS note
           FROM `{$rt['table']}` WHERE `{$rt['invoiceid']}` = :id
          ORDER BY `{$rt['date']}` ASC, `{$rt['id']}` ASC",
        ['id' => $id]
    )->fetchAll() as $r) {
        $returns[(int)$r['returnid']] = [
            'returnid'    => (int)$r['returnid'],
            'returndate'  => (string)$r['returndate'],
            'totalamount' => dt_signed_decimal(dt_sum_to_minor((string)$r['totalamount'])),
            'note'        => (string)($r['note'] ?? ''),
            'items'       => [],
        ];
    }
    foreach (dt_q(
        $pdo,
        "SELECT q.`{$ri['returnid']}` AS returnid, q.`{$ri['quantity']}` AS quantity, lv.`{$l['name']}` AS name
           FROM `{$ri['table']}` q
     INNER JOIN `{$rt['table']}` r ON r.`{$rt['id']}` = q.`{$ri['returnid']}`
      LEFT JOIN `{$l['table']}` lv ON lv.`{$l['id']}` = q.`{$ri['livestockid']}`
          WHERE r.`{$rt['invoiceid']}` = :id
          ORDER BY q.`{$ri['id']}` ASC",
        ['id' => $id]
    )->fetchAll() as $r) {
        $rid = (int)$r['returnid'];
        if (isset($returns[$rid])) {
            $returns[$rid]['items'][] = ['name' => (string)($r['name'] ?? ''), 'quantity' => (int)$r['quantity']];
        }
    }

    $refunds = [];
    foreach (dt_q(
        $pdo,
        "SELECT f.`{$rf['id']}` AS refundid, f.`{$rf['returnid']}` AS returnid, f.`{$rf['date']}` AS refunddate,
                f.`{$rf['amount']}` AS amount, f.`{$rf['note']}` AS note, a.code AS code, a.accountname AS accountname
           FROM `{$rf['table']}` f
      LEFT JOIN accounts a ON a.accountid = f.`{$rf['accountid']}`
          WHERE f.`{$rf['invoiceid']}` = :id
          ORDER BY f.`{$rf['date']}` ASC, f.`{$rf['id']}` ASC",
        ['id' => $id]
    )->fetchAll() as $r) {
        $refunds[] = [
            'refundid'   => (int)$r['refundid'],
            'returnid'   => $r['returnid'] === null ? 0 : (int)$r['returnid'],
            'refunddate' => (string)$r['refunddate'],
            'amount'     => dt_signed_decimal(dt_sum_to_minor((string)$r['amount'])),
            'account'    => trim((string)($r['code'] ?? '') . ' ' . (string)($r['accountname'] ?? '')),
            'note'       => (string)($r['note'] ?? ''),
        ];
    }

    return ['success' => true, 'data' => [
        'invoice' => dt_sale_row($row),
        'lines'   => $lines,
        'returns' => array_values($returns),
        'refunds' => $refunds,
    ]];
}

/**
 * Create a sale return: header and lines, put the animals back into stock (one transaction).
 * Lock order: the invoice, then the livestock rows in ascending id order. A return does not move money.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_returnsave(PDO $pdo): array
{
    $d = dt_read_return_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        $r  = BF_SALERETURNS;
        $ri = BF_SALERETURNITEMS;
        $it = BF_INVOICEITEMS;
        $s  = BF_LIVESTOCK;

        /* a. Lock the invoice. */
        $invoice = dt_lock_invoice($pdo, $d['invoiceid'], 'invoiceid');
        if ($d['date'] < (string)$invoice['invoicedate']) {
            throw new DtFail('Return date cannot be before the invoice date.', false, 'returndate');
        }

        /* b. The livestock lines of this invoice (the only lines that can be returned). */
        $invoiceLines = [];
        foreach (dt_q(
            $pdo,
            "SELECT `{$it['id']}` AS invoiceitemid, `{$it['livestockid']}` AS livestockid, `{$it['quantity']}` AS quantity, `{$it['sellingprice']}` AS sellingprice
               FROM `{$it['table']}` WHERE `{$it['invoiceid']}` = :id AND `{$it['itemtype']}` = 'Livestock'",
            ['id' => $d['invoiceid']]
        )->fetchAll() as $row) {
            $invoiceLines[(int)$row['invoiceitemid']] = $row;
        }

        $lockIds = [];
        foreach ($d['lines'] as $l) {
            if ($l['qty'] <= 0) {
                continue;
            }
            if (!isset($invoiceLines[$l['itemid']])) {
                throw new DtFail('Line ' . $l['n'] . ': the invoice line does not exist.', false, 'lines:' . $l['n'] . ':quantity');
            }
            $lockIds[(int)$invoiceLines[$l['itemid']]['livestockid']] = true;
        }

        /* c. Lock the affected livestock rows in ascending id order. */
        $lockIds = array_keys($lockIds);
        sort($lockIds);
        $animals = [];
        foreach ($lockIds as $lid) {
            $animals[$lid] = dt_lock_livestock($pdo, $lid);
        }

        /* d. Recompute everything on the server in minor units (the price always comes from the invoice line). */
        $total   = 0;
        $running = [];
        $added   = [];
        $rows    = [];
        foreach ($d['lines'] as $l) {
            if ($l['qty'] <= 0) {
                continue;
            }
            $line    = $invoiceLines[$l['itemid']];
            $lid     = (int)$line['livestockid'];
            $already = (int)dt_q(
                $pdo,
                "SELECT COALESCE(SUM(`{$ri['quantity']}`), 0) FROM `{$ri['table']}` WHERE `{$ri['invoiceitemid']}` = :id",
                ['id' => $l['itemid']]
            )->fetchColumn();
            $returnable = (int)$line['quantity'] - $already;
            if ($l['qty'] > $returnable) {
                throw new DtFail('Line ' . $l['n'] . ': only ' . number_format(max(0, $returnable)) . ' can be returned.', false, 'lines:' . $l['n'] . ':quantity');
            }

            $running[$lid] = ($running[$lid] ?? (int)$animals[$lid]['stock']) + $l['qty'];
            if ($running[$lid] > DT_MAX_STOCK) {
                throw new DtFail('Cannot return: ' . $animals[$lid]['name'] . ' stock would exceed ' . number_format(DT_MAX_STOCK) . '.', false, 'lines:' . $l['n'] . ':quantity');
            }
            $added[$lid] = ($added[$lid] ?? 0) + $l['qty'];

            $price     = dt_decimal_to_minor((string)$line['sellingprice'], 2);
            $lineTotal = $l['qty'] * $price;
            $total    += $lineTotal;
            if ($total > DT_MAX_TOTAL_MINOR) {
                throw new DtFail('The return total is too large.', false, 'lines');
            }
            $rows[] = ['itemid' => $l['itemid'], 'livestockid' => $lid, 'qty' => $l['qty'], 'price' => $price, 'total' => $lineTotal];
        }

        /* e. Return header and its lines. */
        dt_q(
            $pdo,
            "INSERT INTO `{$r['table']}` (`{$r['invoiceid']}`, `{$r['customerid']}`, `{$r['date']}`, `{$r['total']}`, `{$r['note']}`)
             VALUES (:inv, :cus, :dt, :tot, :nt)",
            [
                'inv' => $d['invoiceid'],
                'cus' => (int)$invoice['customerid'],
                'dt'  => $d['date'],
                'tot' => dt_minor_to_decimal($total, 2),
                'nt'  => $d['note'],
            ]
        );
        $returnId = (int)$pdo->lastInsertId();

        foreach ($rows as $row) {
            dt_q(
                $pdo,
                "INSERT INTO `{$ri['table']}` (`{$ri['returnid']}`, `{$ri['invoiceitemid']}`, `{$ri['livestockid']}`, `{$ri['quantity']}`, `{$ri['sellingprice']}`, `{$ri['linetotal']}`)
                 VALUES (:r, :ii, :l, :q, :u, :lt)",
                [
                    'r'  => $returnId,
                    'ii' => $row['itemid'],
                    'l'  => $row['livestockid'],
                    'q'  => $row['qty'],
                    'u'  => dt_minor_to_decimal($row['price'], 2),
                    'lt' => dt_minor_to_decimal($row['total'], 2),
                ]
            );
        }

        /* f. Stock: the animals go back (ascending id order, same order as the locks). */
        ksort($added);
        foreach ($added as $lid => $qty) {
            dt_q(
                $pdo,
                "UPDATE `{$s['table']}` SET `{$s['stock']}` = `{$s['stock']}` + :q WHERE `{$s['id']}` = :id",
                ['q' => $qty, 'id' => $lid]
            );
        }

        dt_log_salereturn($pdo, 'Add', 'Return added: #' . $returnId . ' for invoice #' . $d['invoiceid'] . ' ' . (string)$invoice['customername']);

        return ['success' => true, 'message' => 'Return #' . $returnId . ' saved.', 'returnid' => $returnId];
    });
}

/**
 * Options for the refund modal: the invoice summary, its returns and the Assets sub-accounts that can pay (with their balance).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When the invoice does not exist.
 */
function dt_act_refundlookups(PDO $pdo): array
{
    $id  = max(0, (int)($_POST['invoiceid'] ?? 0));
    $row = dt_q($pdo, dt_sale_sql('WHERE i.invoiceid = :id'), ['id' => $id])->fetch();
    if (!is_array($row)) {
        throw new DtFail('Invoice not found.');
    }
    $invoice = dt_sale_row($row);

    $rt      = BF_SALERETURNS;
    $returns = [];
    foreach (dt_q(
        $pdo,
        "SELECT `{$rt['id']}` AS returnid, `{$rt['date']}` AS returndate, `{$rt['total']}` AS totalamount
           FROM `{$rt['table']}` WHERE `{$rt['invoiceid']}` = :id ORDER BY `{$rt['date']}` ASC, `{$rt['id']}` ASC",
        ['id' => $id]
    )->fetchAll() as $r) {
        $returns[] = [
            'returnid'    => (int)$r['returnid'],
            'returndate'  => (string)$r['returndate'],
            'totalamount' => dt_signed_decimal(dt_sum_to_minor((string)$r['totalamount'])),
        ];
    }

    $rows = dt_q(
        $pdo,
        "SELECT accountid, code, accountname FROM accounts WHERE accounttype = 'Assets' AND status = 'ON' AND parentaccountid IS NOT NULL"
    )->fetchAll();
    usort($rows, static fn (array $a, array $b): int => strnatcmp((string)$a['code'], (string)$b['code']));
    $balances = dt_account_balances($pdo);
    $accounts = [];
    foreach ($rows as $r) {
        $accounts[] = [
            'accountid'   => (int)$r['accountid'],
            'code'        => (string)$r['code'],
            'accountname' => (string)$r['accountname'],
            'balance'     => dt_signed_decimal($balances[(int)$r['accountid']] ?? 0),
        ];
    }

    return ['success' => true, 'data' => [
        'invoice'  => [
            'invoiceid'    => $invoice['invoiceid'],
            'invoicedate'  => $invoice['invoicedate'],
            'customername' => $invoice['customername'],
            'paid'         => $invoice['paid'],
            'refunded'     => $invoice['refunded'],
            'refundable'   => $invoice['refundable'],
        ],
        'returns'  => $returns,
        'accounts' => $accounts,
    ]];
}

/**
 * Create a sale refund (one transaction). Lock order: the invoice, then the paying account.
 * The amount cannot pass (paid - refunds already made) or the balance of the account, recomputed inside the transaction.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_refundsave(PDO $pdo): array
{
    $d = dt_read_refund_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        $f  = BF_SALEREFUNDS;
        $rt = BF_SALERETURNS;

        /* a. Lock the invoice. */
        $invoice = dt_lock_invoice($pdo, $d['invoiceid'], 'invoiceid');
        if ($d['date'] < (string)$invoice['invoicedate']) {
            throw new DtFail('Refund date cannot be before the invoice date.', false, 'refunddate');
        }

        if ($d['returnid'] > 0) {
            $belongs = (int)dt_q(
                $pdo,
                "SELECT COUNT(*) FROM `{$rt['table']}` WHERE `{$rt['id']}` = :r AND `{$rt['invoiceid']}` = :i",
                ['r' => $d['returnid'], 'i' => $d['invoiceid']]
            )->fetchColumn();
            if ($belongs === 0) {
                throw new DtFail('The selected return does not belong to this invoice.', false, 'returnid');
            }
        }

        $account = dt_q($pdo, 'SELECT accounttype, code, accountname FROM accounts WHERE accountid = :id', ['id' => $d['accountid']])->fetch();
        if (!is_array($account)) {
            throw new DtFail('The selected account does not exist.', false, 'accountid');
        }
        if ((string)$account['accounttype'] !== 'Assets') {
            throw new DtFail('The paying account must be an Assets account.', false, 'accountid');
        }
        $accountLabel = (string)$account['code'] . ' ' . (string)$account['accountname'];

        /* b. Lock the paying account. */
        dt_lock_accounts($pdo, [$d['accountid']]);

        /* c. Paid minus refunds already made. */
        $paid = dt_sum_to_minor((string)$invoice['paidamount']);
        if ($paid <= 0) {
            throw new DtFail('Nothing was paid on this invoice.', false, 'amount');
        }
        $refunded   = dt_sum_to_minor((string)dt_q(
            $pdo,
            "SELECT COALESCE(SUM(`{$f['amount']}`), 0) FROM `{$f['table']}` WHERE `{$f['invoiceid']}` = :id",
            ['id' => $d['invoiceid']]
        )->fetchColumn());
        $refundable = max(0, $paid - $refunded);
        if ($d['amount'] > $refundable) {
            throw new DtFail('Refund cannot be more than ' . dt_format_minor($refundable) . ' (paid minus refunds already made).', false, 'amount');
        }

        /* d. Balance rule: recompute the balance of the account inside this transaction. */
        $available = dt_account_balances($pdo, $d['accountid'])[$d['accountid']] ?? 0;
        if ($d['amount'] > $available) {
            throw new DtFail(
                'Insufficient balance in ' . $accountLabel . ': available ' . dt_format_minor($available) . ', required ' . dt_format_minor($d['amount']) . '.',
                false,
                'amount'
            );
        }

        dt_q(
            $pdo,
            "INSERT INTO `{$f['table']}` (`{$f['invoiceid']}`, `{$f['returnid']}`, `{$f['customerid']}`, `{$f['accountid']}`, `{$f['amount']}`, `{$f['date']}`, `{$f['note']}`)
             VALUES (:inv, :ret, :cus, :acc, :amt, :dt, :nt)",
            [
                'inv' => $d['invoiceid'],
                'ret' => $d['returnid'] > 0 ? $d['returnid'] : null,
                'cus' => (int)$invoice['customerid'],
                'acc' => $d['accountid'],
                'amt' => dt_minor_to_decimal($d['amount'], 2),
                'dt'  => $d['date'],
                'nt'  => $d['note'],
            ]
        );
        $refundId = (int)$pdo->lastInsertId();

        dt_log_salerefund($pdo, 'Add', 'Refund added: #' . $refundId . ' for invoice #' . $d['invoiceid'] . ' ' . (string)$invoice['customername']);

        return ['success' => true, 'message' => 'Refund #' . $refundId . ' saved.', 'refundid' => $refundId];
    });
}

/**
 * Delete a wrong invoice and reverse everything it did (one transaction).
 * Lock order: the invoice, the livestock rows (ascending id), then the accounts (ascending id).
 * Stock back = animals sold minus animals already returned (the returns already put theirs back).
 * Removes refunds, returns and their lines, the automatic receipt, the invoice lines and the invoice.
 * Blocked when the invoice has product lines, receipts entered on the Receipts page,
 * or when removing its receipt would make the receiving account balance negative.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found or blocked.
 */
function dt_act_invoicedelete(PDO $pdo): array
{
    $id = max(0, (int)($_POST['invoiceid'] ?? 0));

    return dt_transaction(static function (PDO $pdo) use ($id): array {
        $v  = BF_INVOICES;
        $it = BF_INVOICEITEMS;
        $r  = BF_SALERETURNS;
        $ri = BF_SALERETURNITEMS;
        $f  = BF_SALEREFUNDS;
        $rc = BF_RECEIPTS;
        $s  = BF_LIVESTOCK;

        /* a. Lock the invoice. */
        $invoice = dt_lock_invoice($pdo, $id, 'invoiceid');

        /* b. Receipts: only the automatic receipt of this page can be removed with the invoice. */
        $receiptAccounts = [];
        $hasReceipts     = true;
        try {
            foreach (dt_q(
                $pdo,
                "SELECT `{$rc['accountid']}` AS accountid, `{$rc['note']}` AS note FROM `{$rc['table']}` WHERE `{$rc['invoiceid']}` = :id",
                ['id' => $id]
            )->fetchAll() as $row) {
                if ((string)($row['note'] ?? '') !== BF_RECEIPT_NOTE) {
                    throw new DtFail('Cannot delete invoice #' . $id . ': it has receipts entered on the Receipts page. Delete those receipts first.', true);
                }
                $receiptAccounts[(int)$row['accountid']] = true;
            }
        } catch (PDOException $e) {
            if (!in_array($e->getCode(), ['42S02', '42S22'], true)) {
                throw $e;
            }
            dt_log_error('[livestock invoicedelete] skipped ' . $rc['table'] . ': ' . dt_describe_throwable($e));
            $hasReceipts = false;
        }

        /* c. Animals sold minus animals already returned, per livestock item. */
        $back = [];
        foreach (dt_q(
            $pdo,
            "SELECT `{$it['itemtype']}` AS itemtype, `{$it['livestockid']}` AS livestockid, `{$it['quantity']}` AS quantity
               FROM `{$it['table']}` WHERE `{$it['invoiceid']}` = :id",
            ['id' => $id]
        )->fetchAll() as $row) {
            if ((string)$row['itemtype'] !== 'Livestock') {
                throw new DtFail('Cannot delete invoice #' . $id . ': it has product lines.', true);
            }
            $lid        = (int)$row['livestockid'];
            $back[$lid] = ($back[$lid] ?? 0) + (int)$row['quantity'];
        }
        foreach (dt_q(
            $pdo,
            "SELECT q.`{$ri['livestockid']}` AS livestockid, SUM(q.`{$ri['quantity']}`) AS qty
               FROM `{$ri['table']}` q INNER JOIN `{$r['table']}` r ON r.`{$r['id']}` = q.`{$ri['returnid']}`
              WHERE r.`{$r['invoiceid']}` = :id GROUP BY q.`{$ri['livestockid']}`",
            ['id' => $id]
        )->fetchAll() as $row) {
            $lid        = (int)$row['livestockid'];
            $back[$lid] = ($back[$lid] ?? 0) - (int)$row['qty'];
        }

        /* d. Lock the livestock rows in ascending id order and put the animals back. */
        ksort($back);
        foreach ($back as $lid => $qty) {
            $animal = dt_lock_livestock($pdo, (int)$lid);
            $qty    = max(0, (int)$qty);
            if ($qty <= 0) {
                continue;
            }
            if ((int)$animal['stock'] + $qty > DT_MAX_STOCK) {
                throw new DtFail('Cannot delete: ' . $animal['name'] . ' stock would exceed ' . number_format(DT_MAX_STOCK) . '.', true);
            }
            dt_q(
                $pdo,
                "UPDATE `{$s['table']}` SET `{$s['stock']}` = `{$s['stock']}` + :q WHERE `{$s['id']}` = :id",
                ['q' => $qty, 'id' => (int)$lid]
            );
        }

        /* e. Lock the accounts of the receipt and of the refunds, ascending ids. */
        $accountIds = array_keys($receiptAccounts);
        foreach (dt_q(
            $pdo,
            "SELECT DISTINCT `{$f['accountid']}` FROM `{$f['table']}` WHERE `{$f['invoiceid']}` = :id",
            ['id' => $id]
        )->fetchAll(PDO::FETCH_COLUMN) as $aid) {
            $accountIds[] = (int)$aid;
        }
        dt_lock_accounts($pdo, $accountIds);

        /* f. Delete: refunds, return lines, returns, receipts, invoice lines, invoice. */
        dt_q($pdo, "DELETE FROM `{$f['table']}` WHERE `{$f['invoiceid']}` = :id", ['id' => $id]);
        dt_q(
            $pdo,
            "DELETE FROM `{$ri['table']}` WHERE `{$ri['returnid']}` IN (SELECT `{$r['id']}` FROM `{$r['table']}` WHERE `{$r['invoiceid']}` = :id)",
            ['id' => $id]
        );
        dt_q($pdo, "DELETE FROM `{$r['table']}` WHERE `{$r['invoiceid']}` = :id", ['id' => $id]);
        if ($hasReceipts) {
            dt_q($pdo, "DELETE FROM `{$rc['table']}` WHERE `{$rc['invoiceid']}` = :id", ['id' => $id]);
        }
        dt_q($pdo, "DELETE FROM `{$it['table']}` WHERE `{$it['invoiceid']}` = :id", ['id' => $id]);
        dt_q($pdo, "DELETE FROM `{$v['table']}` WHERE `{$v['id']}` = :id", ['id' => $id]);

        /* g. Balance rule: removing the receipt must not make the receiving account negative. */
        foreach (array_keys($receiptAccounts) as $aid) {
            if ($aid <= 0) {
                continue;
            }
            $balance = dt_account_balances($pdo, $aid)[$aid] ?? 0;
            if ($balance < 0) {
                $acc   = dt_q($pdo, 'SELECT code, accountname FROM accounts WHERE accountid = :id', ['id' => $aid])->fetch();
                $label = is_array($acc) ? (string)$acc['code'] . ' ' . (string)$acc['accountname'] : '#' . $aid;
                throw new DtFail('Cannot delete: the money of this invoice was already spent. The balance of ' . $label . ' would be ' . dt_format_minor($balance) . '.', true);
            }
        }

        dt_log_sale($pdo, 'Delete', 'Sale deleted: #' . $id . ' ' . (string)$invoice['customername']);

        return ['success' => true, 'message' => 'Invoice #' . $id . ' deleted.'];
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
        'list'             => 'dt_act_list',
        'save'             => 'dt_act_save',
        'delete'           => 'dt_act_delete',
        'purchaselookups'  => 'dt_act_purchaselookups',
        'purchasesave'     => 'dt_act_purchasesave',
        'salelookups'      => 'dt_act_salelookups',
        'salesave'         => 'dt_act_salesave',
        'quotationlookups' => 'dt_act_quotationlookups',
        'quotationsave'    => 'dt_act_quotationsave',
        'saleslist'        => 'dt_act_saleslist',
        'saleget'          => 'dt_act_saleget',
        'returnsave'       => 'dt_act_returnsave',
        'refundlookups'    => 'dt_act_refundlookups',
        'refundsave'       => 'dt_act_refundsave',
        'invoicedelete'    => 'dt_act_invoicedelete',
        'saleupdate'       => 'dt_act_saleupdate',
        'saleeditlookups'  => 'dt_act_saleeditlookups',
        'quotationslist'       => 'dt_act_quotationslist',
        'quotationget'         => 'dt_act_quotationget',
        'quotationeditlookups' => 'dt_act_quotationeditlookups',
        'quotationupdate'      => 'dt_act_quotationupdate',
        'quotationdelete'      => 'dt_act_quotationdelete',
        'purchaseslist'        => 'dt_act_purchaseslist',
        'purchaseget'          => 'dt_act_purchaseget',
        'purchaseeditlookups'  => 'dt_act_purchaseeditlookups',
        'purchaseupdate'       => 'dt_act_purchaseupdate',
        'purchasedelete'       => 'dt_act_purchasedelete',
        'customerposition'     => 'dt_act_customerposition',
        'livestocklookups'     => 'dt_act_livestocklookups',
        'typesave'             => 'dt_act_typesave',
        'stagesave'           => 'dt_act_stagesave',
        'suppliersave'         => 'dt_act_suppliersave',
        'customersave'         => 'dt_act_customersave',
        'livestockquicksave'   => 'dt_act_livestockquicksave',
        'typeupdate'           => 'dt_act_typeupdate',
        'typedelete'           => 'dt_act_typedelete',
        'stageupdate'         => 'dt_act_stageupdate',
        'stagedelete'         => 'dt_act_stagedelete',
        'supplierposition'     => 'dt_act_supplierposition',
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
        dt_log_error('[livestock ' . $action . '] ' . dt_describe_throwable($e));
        if ($e->getCode() === '45000' && in_array($action, ['salesave', 'saleupdate'], true)) { // database trigger safety net: never show the raw message
            dt_json(dt_fail('Cannot save: the sale is not valid. Check the livestock and the receipt account.'));
        }
        if ($e->getCode() === '23000' && in_array($action, ['typesave', 'stagesave'], true)) { // two quick adds at the same time: unique index
            $noun = $action === 'typesave' ? 'type' : 'stage';
            dt_json(['success' => false, 'field' => 'name', 'message' => 'A ' . $noun . ' named "' . trim((string)($_POST['name'] ?? '')) . '" already exists.']);
        }
        if ($e->getCode() === '23000' && in_array($action, ['typeupdate', 'stageupdate'], true)) { // two renames at the same time: unique index
            $noun = $action === 'typeupdate' ? 'type' : 'stage';
            dt_json(['success' => false, 'field' => 'name', 'message' => 'A ' . $noun . ' named "' . trim((string)($_POST['name'] ?? '')) . '" already exists.']);
        }
        if ($e->getCode() === '23000' && in_array($action, ['typedelete', 'stagedelete'], true)) {
            $noun = $action === 'typedelete' ? 'type' : 'stage';
            dt_json(['success' => false, 'blocked' => true, 'message' => 'Cannot delete: the ' . $noun . ' is used in other records.']);
        }
        if ($e->getCode() === '23000' && $action === 'livestockquicksave') {
            dt_json(['success' => false, 'field' => 'name', 'message' => 'A livestock item named "' . trim((string)($_POST['name'] ?? '')) . '" already exists.']);
        }
        if ($e->getCode() === '23000' && $action === 'delete') {
            dt_json(['success' => false, 'blocked' => true, 'message' => 'Cannot delete: the livestock item is used in other records.']);
        }
        if ($e->getCode() === '23000' && $action === 'save') {
            dt_json(['success' => false, 'field' => 'name', 'message' => 'A livestock item with this name already exists.']);
        }
        if (in_array($e->getCode(), ['23000', '45000'], true) && in_array($action, ['purchasesave', 'purchaseupdate'], true)) {
            dt_json(dt_fail('Cannot save: a supplier, account or livestock item of this purchase is missing or in use.'));
        }
        if (in_array($e->getCode(), ['23000', '45000'], true) && in_array($action, ['salesave', 'saleupdate'], true)) {
            dt_json(dt_fail('Cannot save: the customer, account or livestock item of this sale is missing or in use.'));
        }
        if ($e->getCode() === '23000' && $action === 'quotationdelete') {
            dt_json(['success' => false, 'blocked' => true, 'message' => 'Cannot delete: the quotation is used in other records.']);
        }
        if (in_array($e->getCode(), ['23000', '45000'], true) && $action === 'purchasedelete') {
            dt_json(['success' => false, 'blocked' => true, 'message' => 'Cannot delete: the purchase is used in other records.']);
        }
        if ($e->getCode() === '23000' && in_array($action, ['quotationsave', 'quotationupdate'], true)) {
            dt_json(dt_fail('Cannot save: the customer or a livestock item of this quotation is missing or in use.'));
        }
        if (in_array($e->getCode(), ['23000', '45000'], true) && $action === 'returnsave') {
            dt_json(dt_fail('Cannot save: the invoice, customer or a livestock item of this return is missing or in use.'));
        }
        if (in_array($e->getCode(), ['23000', '45000'], true) && $action === 'refundsave') {
            dt_json(dt_fail('Cannot save: the invoice, return, customer or account of this refund is missing or in use.'));
        }
        if (in_array($e->getCode(), ['23000', '45000'], true) && $action === 'invoicedelete') {
            dt_json(['success' => false, 'blocked' => true, 'message' => 'Cannot delete: the invoice is used in other records.']);
        }
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[livestock ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    }
}

function dt_handle_post_old(): never
{
    if (!dt_csrf_valid($_POST['csrf_token'] ?? null)) {
        dt_json(dt_fail('Invalid security token. Refresh the page.'), 403);
    }

    $handlers = [
        'list'             => 'dt_act_list',
        'save'             => 'dt_act_save',
        'delete'           => 'dt_act_delete',
        'purchaselookups'  => 'dt_act_purchaselookups',
        'purchasesave'     => 'dt_act_purchasesave',
        'salelookups'      => 'dt_act_salelookups',
        'salesave'         => 'dt_act_salesave',
        'quotationlookups' => 'dt_act_quotationlookups',
        'quotationsave'    => 'dt_act_quotationsave',
        'saleslist'        => 'dt_act_saleslist',
        'saleget'          => 'dt_act_saleget',
        'returnsave'       => 'dt_act_returnsave',
        'refundlookups'    => 'dt_act_refundlookups',
        'refundsave'       => 'dt_act_refundsave',
        'invoicedelete'    => 'dt_act_invoicedelete',
        'saleupdate'       => 'dt_act_saleupdate',
        'saleeditlookups'  => 'dt_act_saleeditlookups',
        'quotationslist'       => 'dt_act_quotationslist',
        'quotationget'         => 'dt_act_quotationget',
        'quotationeditlookups' => 'dt_act_quotationeditlookups',
        'quotationupdate'      => 'dt_act_quotationupdate',
        'quotationdelete'      => 'dt_act_quotationdelete',
        'purchaseslist'        => 'dt_act_purchaseslist',
        'purchaseget'          => 'dt_act_purchaseget',
        'purchaseeditlookups'  => 'dt_act_purchaseeditlookups',
        'purchaseupdate'       => 'dt_act_purchaseupdate',
        'purchasedelete'       => 'dt_act_purchasedelete',
        'customerposition'     => 'dt_act_customerposition',
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
        dt_log_error('[livestock ' . $action . '] ' . dt_describe_throwable($e));
        if ($e->getCode() === '45000' && in_array($action, ['salesave', 'saleupdate'], true)) { // database trigger safety net: never show the raw message
            dt_json(dt_fail('Cannot save: the sale is not valid. Check the livestock and the receipt account.'));
        }
        if ($e->getCode() === '23000' && $action === 'delete') {
            dt_json(['success' => false, 'blocked' => true, 'message' => 'Cannot delete: the livestock item is used in other records.']);
        }
        if ($e->getCode() === '23000' && $action === 'save') {
            dt_json(['success' => false, 'field' => 'name', 'message' => 'A livestock item with this name already exists.']);
        }
        if (in_array($e->getCode(), ['23000', '45000'], true) && in_array($action, ['purchasesave', 'purchaseupdate'], true)) {
            dt_json(dt_fail('Cannot save: a supplier, account or livestock item of this purchase is missing or in use.'));
        }
        if (in_array($e->getCode(), ['23000', '45000'], true) && in_array($action, ['salesave', 'saleupdate'], true)) {
            dt_json(dt_fail('Cannot save: the customer, account or livestock item of this sale is missing or in use.'));
        }
        if ($e->getCode() === '23000' && $action === 'quotationdelete') {
            dt_json(['success' => false, 'blocked' => true, 'message' => 'Cannot delete: the quotation is used in other records.']);
        }
        if (in_array($e->getCode(), ['23000', '45000'], true) && $action === 'purchasedelete') {
            dt_json(['success' => false, 'blocked' => true, 'message' => 'Cannot delete: the purchase is used in other records.']);
        }
        if ($e->getCode() === '23000' && in_array($action, ['quotationsave', 'quotationupdate'], true)) {
            dt_json(dt_fail('Cannot save: the customer or a livestock item of this quotation is missing or in use.'));
        }
        if (in_array($e->getCode(), ['23000', '45000'], true) && $action === 'returnsave') {
            dt_json(dt_fail('Cannot save: the invoice, customer or a livestock item of this return is missing or in use.'));
        }
        if (in_array($e->getCode(), ['23000', '45000'], true) && $action === 'refundsave') {
            dt_json(dt_fail('Cannot save: the invoice, return, customer or account of this refund is missing or in use.'));
        }
        if (in_array($e->getCode(), ['23000', '45000'], true) && $action === 'invoicedelete') {
            dt_json(['success' => false, 'blocked' => true, 'message' => 'Cannot delete: the invoice is used in other records.']);
        }
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[livestock ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    }
}

/* ================================================================
 * 4b. NEW ACTIONS: quotations list, purchases list, customer position
 * ============================================================== */

/**
 * Quotations list: every quotation, newest first.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_quotationslist(PDO $pdo): array
{
    $q   = BF_QUOTATIONS;
    $out = [];
    header('Cache-Control: no-store');
    foreach (dt_q($pdo, dt_quotation_sql("ORDER BY q.`{$q['date']}` DESC, q.`{$q['id']}` DESC"))->fetchAll() as $row) {
        $out[] = dt_quotation_row($row);
    }

    return ['success' => true, 'data' => $out];
}

/**
 * One quotation with its lines.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When the quotation does not exist.
 */
function dt_act_quotationget(PDO $pdo): array
{
    $id  = max(0, (int)($_POST['quotationid'] ?? 0));
    $q   = BF_QUOTATIONS;
    $row = dt_q($pdo, dt_quotation_sql("WHERE q.`{$q['id']}` = :id"), ['id' => $id])->fetch();
    if (!is_array($row)) {
        throw new DtFail('Quotation not found.');
    }

    $i     = BF_QUOTATIONITEMS;
    $l     = BF_LIVESTOCK;
    $lines = [];
    foreach (dt_q(
        $pdo,
        "SELECT lv.`{$l['name']}` AS name, lv.`{$l['type']}` AS type, x.`{$i['quantity']}` AS quantity,
                x.`{$i['sellingprice']}` AS sellingprice, x.`{$i['linetotal']}` AS linetotal
           FROM `{$i['table']}` x
      LEFT JOIN `{$l['table']}` lv ON lv.`{$l['id']}` = x.`{$i['livestockid']}`
          WHERE x.`{$i['quotationid']}` = :id ORDER BY x.`{$i['id']}` ASC",
        ['id' => $id]
    )->fetchAll() as $r) {
        $lines[] = [
            'name'      => (string)($r['name'] ?? ''),
            'type'      => (string)($r['type'] ?? ''),
            'quantity'  => (string)(int)$r['quantity'],
            'sellingprice' => dt_signed_decimal(dt_sum_to_minor((string)$r['sellingprice'])),
            'linetotal' => dt_signed_decimal(dt_sum_to_minor((string)$r['linetotal'])),
        ];
    }

    return ['success' => true, 'data' => ['quotation' => dt_quotation_row($row), 'lines' => $lines]];
}

/**
 * Options and values for the Make Quotation modal in edit mode.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When the quotation does not exist.
 */
function dt_act_quotationeditlookups(PDO $pdo): array
{
    $id  = max(0, (int)($_POST['quotationid'] ?? 0));
    $q   = BF_QUOTATIONS;
    $row = dt_q($pdo, dt_quotation_sql("WHERE q.`{$q['id']}` = :id"), ['id' => $id])->fetch();
    if (!is_array($row)) {
        throw new DtFail('Quotation not found.');
    }

    $i     = BF_QUOTATIONITEMS;
    $lines = [];
    foreach (dt_q(
        $pdo,
        "SELECT `{$i['livestockid']}` AS livestockid, `{$i['quantity']}` AS quantity, `{$i['sellingprice']}` AS sellingprice
           FROM `{$i['table']}` WHERE `{$i['quotationid']}` = :id ORDER BY `{$i['id']}` ASC",
        ['id' => $id]
    )->fetchAll() as $r) {
        $lines[] = [
            'livestockid' => (int)$r['livestockid'],
            'quantity'    => (int)$r['quantity'],
            'sellingprice' => dt_signed_decimal(dt_sum_to_minor((string)$r['sellingprice'])),
        ];
    }

    return ['success' => true, 'data' => [
        'customers' => dt_lookup_customers($pdo),
        'livestock' => dt_lookup_livestock($pdo, false),
        'quotation' => dt_quotation_row($row),
        'lines'     => $lines,
    ]];
}

/**
 * Update a quotation: header and lines replaced in one transaction. A quotation never changes stock: no stock lock.
 * Every total is recomputed here in minor units.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_quotationupdate(PDO $pdo): array
{
    $id = max(0, (int)($_POST['quotationid'] ?? 0));
    $d  = dt_read_quotation_input();

    return dt_transaction(static function (PDO $pdo) use ($id, $d): array {
        $p = BF_QUOTATIONS;
        $i = BF_QUOTATIONITEMS;

        $exists = dt_q($pdo, "SELECT `{$p['id']}` FROM `{$p['table']}` WHERE `{$p['id']}` = :id FOR UPDATE", ['id' => $id])->fetchColumn();
        if ($exists === false) {
            throw new DtFail('Quotation not found.', false, 'quotationid');
        }

        if ($d['walkin']) {
            $d['customerid'] = dt_walkin_customer_id($pdo);
        }
        $customer = dt_q($pdo, 'SELECT name FROM customers WHERE customerid = :id', ['id' => $d['customerid']])->fetchColumn();
        if ($customer === false) {
            throw new DtFail('The selected customer does not exist.', false, 'customerid');
        }
        $customer = (string)$customer;

        dt_assert_livestock_exist($pdo, $d['lines']);

        dt_q(
            $pdo,
            "UPDATE `{$p['table']}` SET `{$p['customerid']}` = :cus, `{$p['date']}` = :dt, `{$p['validuntil']}` = :vu, `{$p['reference']}` = :ref,
                    `{$p['total']}` = :tot, `{$p['status']}` = :st, `{$p['note']}` = :nt WHERE `{$p['id']}` = :id",
            [
                'cus' => $d['customerid'],
                'dt'  => $d['date'],
                'vu'  => $d['validuntil'],
                'ref' => $d['reference'],
                'tot' => dt_minor_to_decimal($d['total'], 2),
                'st'  => $d['status'],
                'nt'  => $d['note'],
                'id'  => $id,
            ]
        );

        dt_q($pdo, "DELETE FROM `{$i['table']}` WHERE `{$i['quotationid']}` = :id", ['id' => $id]);
        $ins = $pdo->prepare(
            "INSERT INTO `{$i['table']}` (`{$i['quotationid']}`, `{$i['livestockid']}`, `{$i['quantity']}`, `{$i['sellingprice']}`, `{$i['linetotal']}`)
             VALUES (:q, :l, :n, :u, :lt)"
        );
        foreach ($d['lines'] as $l) {
            $ins->execute([
                'q'  => $id,
                'l'  => $l['livestockid'],
                'n'  => $l['qty'],
                'u'  => dt_minor_to_decimal($l['price'], 2),
                'lt' => dt_minor_to_decimal($l['total'], 2),
            ]);
        }

        dt_log_quotation($pdo, 'Update', 'Quotation updated: #' . $id . ' ' . $customer);

        return ['success' => true, 'message' => 'Quotation #' . $id . ' updated.', 'quotationid' => $id];
    });
}

/**
 * Delete a quotation: lines first, then the header (one transaction). A quotation never touches stock.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found.
 */
function dt_act_quotationdelete(PDO $pdo): array
{
    $id = max(0, (int)($_POST['quotationid'] ?? 0));

    return dt_transaction(static function (PDO $pdo) use ($id): array {
        $p = BF_QUOTATIONS;
        $i = BF_QUOTATIONITEMS;

        $row = dt_q(
            $pdo,
            "SELECT COALESCE(c.name, '(customer deleted)') FROM `{$p['table']}` q LEFT JOIN customers c ON c.customerid = q.`{$p['customerid']}`
              WHERE q.`{$p['id']}` = :id FOR UPDATE",
            ['id' => $id]
        )->fetchColumn();
        if ($row === false) {
            throw new DtFail('Quotation not found.', false, 'quotationid');
        }

        dt_q($pdo, "DELETE FROM `{$i['table']}` WHERE `{$i['quotationid']}` = :id", ['id' => $id]);
        dt_q($pdo, "DELETE FROM `{$p['table']}` WHERE `{$p['id']}` = :id", ['id' => $id]);
        dt_log_quotation($pdo, 'Delete', 'Quotation deleted: #' . $id . ' ' . (string)$row);

        return ['success' => true, 'message' => 'Quotation #' . $id . ' deleted.'];
    });
}

/**
 * Purchases list: EVERY purchase of the database (also the ones made in pages/purchases.php), newest first.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_purchaseslist(PDO $pdo): array
{
    $p   = BF_PURCHASES;
    $out = [];
    header('Cache-Control: no-store');
    foreach (dt_q($pdo, dt_purchase_sql("ORDER BY p.`{$p['date']}` DESC, p.`{$p['id']}` DESC"))->fetchAll() as $row) {
        $out[] = dt_purchase_row($row);
    }

    return ['success' => true, 'data' => $out];
}

/**
 * One purchase with its lines (livestock and product lines) and the paying account.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When the purchase does not exist.
 */
function dt_act_purchaseget(PDO $pdo): array
{
    $id  = max(0, (int)($_POST['purchaseid'] ?? 0));
    $row = dt_q($pdo, dt_purchase_sql('WHERE p.`' . BF_PURCHASES['id'] . '` = :id'), ['id' => $id])->fetch();
    if (!is_array($row)) {
        throw new DtFail('Purchase not found.');
    }
    $purchase = dt_purchase_row($row);

    $account = '';
    if ($purchase['accountid'] > 0) {
        $a = dt_q($pdo, 'SELECT code, accountname FROM accounts WHERE accountid = :id', ['id' => $purchase['accountid']])->fetch();
        if (is_array($a)) {
            $account = trim((string)$a['code'] . ' ' . (string)$a['accountname']);
        }
    }
    $purchase['account'] = $account;

    $i     = BF_PURCHASEITEMS;
    $l     = BF_LIVESTOCK;
    $lines = [];
    foreach (dt_q(
        $pdo,
        "SELECT x.`{$i['itemtype']}` AS itemtype, x.`{$i['quantity']}` AS quantity, x.`{$i['costeach']}` AS costeach,
                x.`{$i['sellingprice']}` AS sellingprice, x.`{$i['purchasecost']}` AS purchasecost,
                lv.`{$l['name']}` AS livestockname, lv.`{$l['type']}` AS livestocktype, pr.name AS productname
           FROM `{$i['table']}` x
      LEFT JOIN `{$l['table']}` lv ON lv.`{$l['id']}` = x.`{$i['livestockid']}`
      LEFT JOIN products pr ON pr.productid = x.`{$i['productid']}`
          WHERE x.`{$i['purchaseid']}` = :id ORDER BY x.`{$i['id']}` ASC",
        ['id' => $id]
    )->fetchAll() as $r) {
        $isLivestock = (string)$r['itemtype'] === 'Livestock';
        $lines[]     = [
            'itemtype'     => (string)$r['itemtype'],
            'name'         => $isLivestock ? (string)($r['livestockname'] ?? '') : (string)($r['productname'] ?? ''),
            'type'         => $isLivestock ? (string)($r['livestocktype'] ?? '') : 'Product',
            'quantity'     => dt_trim_qty((string)$r['quantity']),
            'costeach'     => dt_signed_decimal(dt_sum_to_minor((string)$r['costeach'])),
            'sellingprice' => dt_signed_decimal(dt_sum_to_minor((string)$r['sellingprice'])),
            'purchasecost' => dt_signed_decimal(dt_sum_to_minor((string)$r['purchasecost'])),
        ];
    }

    return ['success' => true, 'data' => ['purchase' => $purchase, 'lines' => $lines]];
}

/** Old version, never called (delete it when you want). */
function dt_act_purchaseget_v1(PDO $pdo): array
{
    $id  = max(0, (int)($_POST['purchaseid'] ?? 0));
    $row = dt_q($pdo, dt_purchase_sql('WHERE p.`' . BF_PURCHASES['id'] . '` = :id'), ['id' => $id])->fetch();
    if (!is_array($row)) {
        throw new DtFail('Purchase not found.');
    }
    $purchase = dt_purchase_row($row);

    $account = '';
    if ($purchase['accountid'] > 0) {
        $a = dt_q($pdo, 'SELECT code, accountname FROM accounts WHERE accountid = :id', ['id' => $purchase['accountid']])->fetch();
        if (is_array($a)) {
            $account = trim((string)$a['code'] . ' ' . (string)$a['accountname']);
        }
    }
    $purchase['account'] = $account;

    $i     = BF_PURCHASEITEMS;
    $l     = BF_LIVESTOCK;
    $lines = [];
    foreach (dt_q(
        $pdo,
        "SELECT x.`{$i['itemtype']}` AS itemtype, x.`{$i['quantity']}` AS quantity, x.`{$i['sellingprice']}` AS sellingprice, x.`{$i['linetotal']}` AS linetotal,
                lv.`{$l['name']}` AS livestockname, lv.`{$l['type']}` AS livestocktype, pr.name AS productname
           FROM `{$i['table']}` x
      LEFT JOIN `{$l['table']}` lv ON lv.`{$l['id']}` = x.`{$i['livestockid']}`
      LEFT JOIN products pr ON pr.productid = x.`{$i['productid']}`
          WHERE x.`{$i['purchaseid']}` = :id ORDER BY x.`{$i['id']}` ASC",
        ['id' => $id]
    )->fetchAll() as $r) {
        $isLivestock = (string)$r['itemtype'] === 'Livestock';
        $lines[]     = [
            'itemtype'  => (string)$r['itemtype'],
            'name'      => $isLivestock ? (string)($r['livestockname'] ?? '') : (string)($r['productname'] ?? ''),
            'type'      => $isLivestock ? (string)($r['livestocktype'] ?? '') : 'Product',
            'quantity'  => dt_trim_qty((string)$r['quantity']),
            'sellingprice' => dt_signed_decimal(dt_sum_to_minor((string)$r['sellingprice'])),
            'linetotal' => dt_signed_decimal(dt_sum_to_minor((string)$r['linetotal'])),
        ];
    }

    return ['success' => true, 'data' => ['purchase' => $purchase, 'lines' => $lines]];
}

/**
 * Options and values for the Purchase Livestock modal in edit mode.
 * The animal of the purchase shows its stock without the animals this purchase added; the paying account shows its balance
 * plus the automatic payment of this purchase (that money is given back when the purchase is saved again).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When the purchase does not exist or cannot be edited here (blocked).
 */
function dt_act_purchaseeditlookups(PDO $pdo): array
{
    $id  = max(0, (int)($_POST['purchaseid'] ?? 0));
    $row = dt_q($pdo, dt_purchase_sql('WHERE p.`' . BF_PURCHASES['id'] . '` = :id'), ['id' => $id])->fetch();
    if (!is_array($row)) {
        throw new DtFail('Purchase not found.');
    }
    $purchase = dt_purchase_row($row);
    if (!$purchase['editable']) {
        throw new DtFail('Edit this purchase in Purchases page.', true);
    }

    $i    = BF_PURCHASEITEMS;
    $line = dt_q(
        $pdo,
        "SELECT `{$i['livestockid']}` AS livestockid, `{$i['quantity']}` AS quantity, `{$i['purchasecost']}` AS purchasecost, `{$i['sellingprice']}` AS sellingprice, `{$i['stockadded']}` AS stockadded
           FROM `{$i['table']}` WHERE `{$i['purchaseid']}` = :id AND `{$i['itemtype']}` = 'Livestock'",
        ['id' => $id]
    )->fetch();
    if (!is_array($line)) {
        throw new DtFail('Edit this purchase in Purchases page.', true);
    }

    $pay  = dt_purchase_payments($pdo, $id);
    $data = dt_act_purchaselookups($pdo)['data'];
    foreach ($data['livestock'] as $k => $a) {
        if ((int)$a['livestockid'] === (int)$line['livestockid']) {
            $data['livestock'][$k]['currentstock'] = max(0, (int)$a['currentstock'] - (int)$line['stockadded']);
        }
    }
    foreach ($data['accounts'] as $k => $a) {
        if ($pay['account'] > 0 && (int)$a['accountid'] === $pay['account']) {
            $data['accounts'][$k]['balance'] = dt_signed_decimal(dt_sum_to_minor((string)$a['balance']) + $pay['auto']);
        }
    }

    return ['success' => true, 'data' => [
        'suppliers' => $data['suppliers'],
        'livestock' => $data['livestock'],
        'accounts'  => $data['accounts'],
        'purchase'  => $purchase,
        'line'      => [
            'livestockid' => (int)$line['livestockid'],
            'quantity'    => (int)explode('.', (string)$line['quantity'])[0],
            'purchasecost' => dt_signed_decimal(dt_sum_to_minor((string)$line['purchasecost'])),
            'sellingprice' => dt_signed_decimal(dt_sum_to_minor((string)$line['sellingprice'])),
        ],
        'manual'    => $pay['manual'],
    ]];
}

/**
 * Update a purchase with one livestock line (one transaction).
 * Lock order: purchase, old line, livestock rows (old and new, ascending id), accounts (ascending id).
 * Stock: the old stockadded comes out, the new quantity goes in (never below 0, never above DT_MAX_STOCK).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_purchaseupdate(PDO $pdo): array
{
    $id = max(0, (int)($_POST['purchaseid'] ?? 0));
    $d  = dt_read_purchase_input();

    return dt_transaction(static function (PDO $pdo) use ($id, $d): array {
        $p = BF_PURCHASES;
        $i = BF_PURCHASEITEMS;
        $s = BF_LIVESTOCK;

        /* a. Lock the purchase and its lines. */
        $head = dt_q(
            $pdo,
            "SELECT `{$p['paid']}` AS paidamount, `{$p['account']}` AS accountid FROM `{$p['table']}` WHERE `{$p['id']}` = :id FOR UPDATE",
            ['id' => $id]
        )->fetch();
        if (!is_array($head)) {
            throw new DtFail('Purchase not found.', false, 'purchaseid');
        }
        $lines = dt_q(
            $pdo,
            "SELECT `{$i['id']}` AS itemid, `{$i['itemtype']}` AS itemtype, `{$i['livestockid']}` AS livestockid, `{$i['stockadded']}` AS stockadded
               FROM `{$i['table']}` WHERE `{$i['purchaseid']}` = :id ORDER BY `{$i['id']}` ASC FOR UPDATE",
            ['id' => $id]
        )->fetchAll();
        if (count($lines) !== 1 || (string)$lines[0]['itemtype'] !== 'Livestock') {
            throw new DtFail('Cannot change purchase #' . $id . ': edit this purchase in Purchases page.', true);
        }
        $oldItem    = (int)$lines[0]['itemid'];
        $oldLid     = (int)$lines[0]['livestockid'];
        $oldAdded   = (int)$lines[0]['stockadded'];
        $oldPaid    = dt_sum_to_minor((string)$head['paidamount']);
        $oldAccount = (int)($head['accountid'] ?? 0);
        $pay        = dt_purchase_payments($pdo, $id);

        /* b. Supplier (None is created once when needed). */
        if ($d['nosupplier']) {
            $d['supplierid'] = dt_none_supplier_id($pdo);
        }
        $supplier = dt_q($pdo, 'SELECT name FROM suppliers WHERE supplierid = :id', ['id' => $d['supplierid']])->fetchColumn();
        if ($supplier === false) {
            throw new DtFail('The selected supplier does not exist.', false, 'supplierid');
        }
        $supplier = (string)$supplier;

        /* c. Payments entered on the Payments page: the paid amount cannot change here. */
        if ($pay['manual'] && $d['paid'] !== $oldPaid) {
            throw new DtFail('Cannot change the paid amount: this purchase has payments entered on the Payments page. Change those payments instead.', false, 'paidamount');
        }
        $newAccount   = $pay['manual'] ? $oldAccount : $d['accountid'];
        $accountLabel = '';
        if (!$pay['manual'] && $d['paid'] > 0) {
            $account = dt_q($pdo, 'SELECT accounttype, code, accountname FROM accounts WHERE accountid = :id', ['id' => $d['accountid']])->fetch();
            if (!is_array($account)) {
                throw new DtFail('The selected account does not exist.', false, 'paidfromaccountid');
            }
            if ((string)$account['accounttype'] !== 'Assets') {
                throw new DtFail('The paying account must be an Assets account.', false, 'paidfromaccountid');
            }
            $accountLabel = (string)$account['code'] . ' ' . (string)$account['accountname'];
        }

        /* d. Locks: livestock rows (old and new, ascending), then accounts (ascending). */
        $ids = array_values(array_unique([$oldLid, $d['livestockid']]));
        sort($ids);
        $animals = [];
        foreach ($ids as $lid) {
            $animals[$lid] = dt_lock_livestock($pdo, $lid, $lid === $d['livestockid'] ? 'livestockid' : '');
        }
        dt_lock_accounts($pdo, [$oldAccount, $pay['account'], $newAccount]);

        /* e. Stock: old animals out, new animals in. */
        $stock = [];
        foreach ($animals as $lid => $a) {
            $stock[$lid] = (int)$a['stock'];
        }
        $stock[$oldLid] -= $oldAdded;
        if ($stock[$oldLid] < 0) {
            throw new DtFail('Cannot change: ' . number_format(-$stock[$oldLid]) . ' animals of ' . $animals[$oldLid]['name'] . ' were already sold.', false, 'quantity');
        }
        $stock[$d['livestockid']] += $d['qty'];
        if ($stock[$d['livestockid']] > DT_MAX_STOCK) {
            throw new DtFail('Cannot purchase: ' . $animals[$d['livestockid']]['name'] . ' stock would exceed ' . number_format(DT_MAX_STOCK) . '.', false, 'quantity');
        }

        /* f. Balance rule: the automatic payment of this purchase is given back before the new one is checked. */
        if (!$pay['manual'] && $d['paid'] > 0) {
            $available = (dt_account_balances($pdo, $d['accountid'])[$d['accountid']] ?? 0) + ($pay['account'] === $d['accountid'] ? $pay['auto'] : 0);
            if ($d['paid'] > $available) {
                throw new DtFail(
                    'Insufficient balance in ' . $accountLabel . ': available ' . dt_format_minor($available) . ', required ' . dt_format_minor($d['paid']) . '.',
                    false,
                    'paidamount'
                );
            }
        }

        /* g. Header and the single line. */
        $accountValue = $pay['manual'] ? ($oldAccount > 0 ? $oldAccount : null) : ($d['paid'] > 0 ? $d['accountid'] : null);
        dt_q(
            $pdo,
            "UPDATE `{$p['table']}` SET `{$p['supplierid']}` = :sup, `{$p['date']}` = :dt, `{$p['reference']}` = :ref, `{$p['total']}` = :tot,
                    `{$p['paid']}` = :pd, `{$p['account']}` = :acc, `{$p['note']}` = :nt WHERE `{$p['id']}` = :id",
            [
                'sup' => $d['supplierid'],
                'dt'  => $d['date'],
                'ref' => $d['reference'],
                'tot' => dt_minor_to_decimal($d['total'], 2),
                'pd'  => dt_minor_to_decimal($d['paid'], 2),
                'acc' => $accountValue,
                'nt'  => $d['note'],
                'id'  => $id,
            ]
        );
        dt_q(
            $pdo,
            "UPDATE `{$i['table']}` SET `{$i['livestockid']}` = :l, `{$i['quantity']}` = :q, `{$i['costeach']}` = :u, `{$i['sellingprice']}` = :sp, `{$i['purchasecost']}` = :lt, `{$i['stockadded']}` = :sa
              WHERE `{$i['id']}` = :id",
            [
                'l'  => $d['livestockid'],
                'q'  => dt_minor_to_decimal($d['qty'] * (10 ** DT_QTY_SCALE), DT_QTY_SCALE),
                'u'  => dt_minor_to_decimal($d['costeach'], 2),
                'sp' => dt_minor_to_decimal($d['sellingprice'], 2),
                'lt' => dt_minor_to_decimal($d['total'], 2),
                'sa' => $d['qty'],
                'id' => $oldItem,
            ]
        );

        /* h. Stock rows (ascending id order, same as the locks). */
        ksort($stock);
        foreach ($stock as $lid => $qty) {
            if ($qty !== (int)$animals[$lid]['stock']) {
                dt_q($pdo, "UPDATE `{$s['table']}` SET `{$s['stock']}` = :q WHERE `{$s['id']}` = :id", ['q' => $qty, 'id' => $lid]);
            }
        }

        /* h2. The purchase sets Cost Each and Selling Price of the livestock item (row already locked). */
        dt_q(
            $pdo,
            "UPDATE `{$s['table']}` SET `{$s['cost']}` = :c, `{$s['price']}` = :p WHERE `{$s['id']}` = :id",
            ['c' => dt_minor_to_decimal($d['costeach'], 2), 'p' => dt_minor_to_decimal($d['sellingprice'], 2), 'id' => $d['livestockid']]
        );

        /* i. Automatic payment row (skipped when payments were entered on the Payments page). */
        if (!$pay['manual']) {
            dt_sync_payment($pdo, $id, $d['supplierid'], $d['accountid'], $d['paid'], $d['date']);
        }

        dt_log_purchase($pdo, 'Update', 'Purchase updated: #' . $id . ' ' . $supplier);

        return ['success' => true, 'message' => 'Purchase #' . $id . ' updated.', 'purchaseid' => $id];
    });
}

/**
 * Delete a purchase made only of livestock lines and reverse it (one transaction).
 * Blocked when it has product lines, payments entered on the Payments page, or when the stock would become negative.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found or blocked.
 */
function dt_act_purchasedelete(PDO $pdo): array
{
    $id = max(0, (int)($_POST['purchaseid'] ?? 0));

    return dt_transaction(static function (PDO $pdo) use ($id): array {
        $p = BF_PURCHASES;
        $i = BF_PURCHASEITEMS;
        $s = BF_LIVESTOCK;

        $head = dt_q($pdo, "SELECT `{$p['supplierid']}` AS supplierid FROM `{$p['table']}` WHERE `{$p['id']}` = :id FOR UPDATE", ['id' => $id])->fetch();
        if (!is_array($head)) {
            throw new DtFail('Purchase not found.', false, 'purchaseid');
        }
        $name = dt_q($pdo, 'SELECT name FROM suppliers WHERE supplierid = :id', ['id' => (int)$head['supplierid']])->fetchColumn();

        $back = [];
        foreach (dt_q(
            $pdo,
            "SELECT `{$i['itemtype']}` AS itemtype, `{$i['livestockid']}` AS livestockid, `{$i['stockadded']}` AS stockadded
               FROM `{$i['table']}` WHERE `{$i['purchaseid']}` = :id FOR UPDATE",
            ['id' => $id]
        )->fetchAll() as $row) {
            if ((string)$row['itemtype'] !== 'Livestock') {
                throw new DtFail('Cannot delete purchase #' . $id . ': it has product lines. Delete it in Purchases page.', true);
            }
            $lid        = (int)$row['livestockid'];
            $back[$lid] = ($back[$lid] ?? 0) + (int)$row['stockadded'];
        }

        $pay = dt_purchase_payments($pdo, $id);
        if ($pay['manual']) {
            throw new DtFail('Cannot delete purchase #' . $id . ': it has payments entered on the Payments page. Delete those payments first.', true);
        }

        /* Stock goes down by stockadded; never below 0 (ascending id order). */
        ksort($back);
        $new = [];
        foreach ($back as $lid => $qty) {
            $animal = dt_lock_livestock($pdo, (int)$lid);
            if ((int)$animal['stock'] - $qty < 0) {
                throw new DtFail('Cannot delete: ' . number_format($qty - (int)$animal['stock']) . ' animals of ' . $animal['name'] . ' were already sold.', true);
            }
            $new[$lid] = (int)$animal['stock'] - $qty;
        }
        foreach ($new as $lid => $qty) {
            dt_q($pdo, "UPDATE `{$s['table']}` SET `{$s['stock']}` = :q WHERE `{$s['id']}` = :id", ['q' => $qty, 'id' => (int)$lid]);
        }

        dt_sync_payment($pdo, $id, 0, 0, 0, '');
        dt_q($pdo, "DELETE FROM `{$i['table']}` WHERE `{$i['purchaseid']}` = :id", ['id' => $id]);
        dt_q($pdo, "DELETE FROM `{$p['table']}` WHERE `{$p['id']}` = :id", ['id' => $id]);

        dt_log_purchase($pdo, 'Delete', 'Purchase deleted: #' . $id . ' ' . ($name === false ? '' : (string)$name));

        return ['success' => true, 'message' => 'Purchase #' . $id . ' deleted.'];
    });
}

/**
 * Customer position for the sale and quotation modals (information only). See dt_customer_position().
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When the customer does not exist.
 */
function dt_act_customerposition(PDO $pdo): array
{
    $id   = max(0, (int)($_POST['customerid'] ?? 0));
    $name = dt_q($pdo, 'SELECT name FROM customers WHERE customerid = :id', ['id' => $id])->fetchColumn();
    if ($name === false) {
        throw new DtFail('The selected customer does not exist.', false, 'customerid');
    }
    if (strcasecmp((string)$name, BF_WALKIN_NAME) === 0) {
        return ['success' => true, 'data' => ['walkin' => true]];
    }

    $data           = dt_customer_position($pdo, $id, max(0, (int)($_POST['invoiceid'] ?? 0)));
    $data['walkin'] = false;

    return ['success' => true, 'data' => $data];
}

/* ================================================================
 * 4c. QUICK ADD ACTIONS: livestock lookups, type, status, supplier, customer, quick livestock
 * ============================================================== */

/**
 * Saved types and statuses for the Livestock modal and the quick livestock panel.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_livestocklookups(PDO $pdo): array
{
    return ['success' => true, 'data' => [
        'types'    => dt_lookup_usage_rows($pdo, BF_LIVESTOCKTYPES, BF_LIVESTOCK['type']),
        'stages'   => dt_lookup_usage_rows($pdo, BF_LIVESTOCKSTAGES, BF_LIVESTOCK['stage']),
    ]];
}

/** Old version without the "used" counts, no longer called (delete it when you want). */
function dt_act_livestocklookups_old(PDO $pdo): array
{
    return ['success' => true, 'data' => ['types' => dt_lookup_types($pdo), 'statuses' => dt_lookup_statuses($pdo)]];
}

/**
 * Register a livestock type (one transaction).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload with the new row.
 *
 * @throws DtFail On invalid input or a duplicate name.
 */
function dt_act_typesave(PDO $pdo): array
{
    $name = dt_clean_lookup_name((string)($_POST['name'] ?? ''), DT_MAX_TYPE, 'Type');

    return dt_transaction(static function (PDO $pdo) use ($name): array {
        $c = BF_LIVESTOCKTYPES;
        dt_assert_lookup_free($pdo, $c, $name, 'type');
        dt_q($pdo, "INSERT INTO `{$c['table']}` (`{$c['name']}`) VALUES (:n)", ['n' => $name]);
        $id = (int)$pdo->lastInsertId();
        dt_log_livestock($pdo, 'Add', 'Livestock type added: ' . $name);

        return ['success' => true, 'message' => 'Type added successfully.', 'row' => ['livestocktypeid' => $id, 'name' => $name]];
    });
}

/**
 * Register a livestock status (one transaction).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload with the new row.
 *
 * @throws DtFail On invalid input or a duplicate name.
 */
function dt_act_stagesave(PDO $pdo): array
{
    $name = dt_clean_lookup_name((string)($_POST['name'] ?? ''), DT_MAX_STAGE, 'Stage');

    return dt_transaction(static function (PDO $pdo) use ($name): array {
        $c = BF_LIVESTOCKSTAGES;
        dt_assert_lookup_free($pdo, $c, $name, 'stage');
        dt_q($pdo, "INSERT INTO `{$c['table']}` (`{$c['name']}`) VALUES (:n)", ['n' => $name]);
        $id = (int)$pdo->lastInsertId();
        dt_log_livestock($pdo, 'Add', 'Livestock stage added: ' . $name);

        return ['success' => true, 'message' => 'Stage added successfully.', 'row' => ['livestockstageid' => $id, 'name' => $name]];
    });
}

/**
 * Quick add of a supplier (one transaction). "None" is reserved, duplicate names are allowed.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload with the new row.
 *
 * @throws DtFail On invalid input.
 */
function dt_act_suppliersave(PDO $pdo): array
{
    $d = dt_clean_party_input('Supplier', BF_NO_SUPPLIER_NAME);

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        dt_q($pdo, 'INSERT INTO suppliers (name, contact, address) VALUES (:n, :c, :a)', ['n' => $d['name'], 'c' => $d['contact'], 'a' => $d['address']]);
        $id = (int)$pdo->lastInsertId();
        dt_log_purchase($pdo, 'Add', 'Supplier added: ' . $d['name']);

        return ['success' => true, 'message' => 'Supplier added successfully.', 'row' => ['supplierid' => $id, 'name' => $d['name']]];
    });
}

/**
 * Quick add of a customer (one transaction). "Walk-in" is reserved, duplicate names are allowed.
 * The posted "contact" is stored in customers.primarycontact.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload with the new row.
 *
 * @throws DtFail On invalid input.
 */
function dt_act_customersave(PDO $pdo): array
{
    $d = dt_clean_party_input('Customer', BF_WALKIN_NAME);

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        dt_q($pdo, 'INSERT INTO customers (name, primarycontact, address) VALUES (:n, :c, :a)', ['n' => $d['name'], 'c' => $d['contact'], 'a' => $d['address']]);
        $id = (int)$pdo->lastInsertId();
        dt_log_sale($pdo, 'Add', 'Customer added: ' . $d['name']);

        return ['success' => true, 'message' => 'Customer added successfully.', 'row' => ['customerid' => $id, 'name' => $d['name']]];
    });
}

/**
 * Quick add of a livestock item from the purchase modal (one transaction). Stock is always 0: the purchase adds it.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload with the new row.
 *
 * @throws DtFail On invalid input, a duplicate name, or a type or status that is not in the saved lists.
 */
/**
 * Rename a livestock type and the livestock items that use it.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input, a duplicate name or a missing row.
 */
function dt_act_typeupdate(PDO $pdo): array
{
    return dt_lookup_update($pdo, BF_LIVESTOCKTYPES, BF_LIVESTOCK['type'], 'type', 'Type', DT_MAX_TYPE);
}

/**
 * Delete a livestock type that no livestock item uses.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found or still used (blocked).
 */
function dt_act_typedelete(PDO $pdo): array
{
    return dt_lookup_delete($pdo, BF_LIVESTOCKTYPES, BF_LIVESTOCK['type'], 'type');
}

/**
 * Rename a livestock status and the livestock items that use it.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input, a duplicate name or a missing row.
 */
function dt_act_stageupdate(PDO $pdo): array
{
    return dt_lookup_update($pdo, BF_LIVESTOCKSTAGES, BF_LIVESTOCK['stage'], 'stage', 'Stage', DT_MAX_STAGE);
}

/**
 * Delete a livestock status that no livestock item uses.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found or still used (blocked).
 */
function dt_act_stagedelete(PDO $pdo): array
{
    return dt_lookup_delete($pdo, BF_LIVESTOCKSTAGES, BF_LIVESTOCK['stage'], 'stage');
}

/**
 * Supplier position for the Purchase modal (information only). See dt_supplier_position().
 * The None supplier has no balance. The optional purchaseid is the purchase being edited (not counted).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When the supplier does not exist.
 */
function dt_act_supplierposition(PDO $pdo): array
{
    $id   = max(0, (int)($_POST['supplierid'] ?? 0));
    $name = dt_q($pdo, 'SELECT name FROM suppliers WHERE supplierid = :id', ['id' => $id])->fetchColumn();
    if ($name === false) {
        throw new DtFail('The selected supplier does not exist.', false, 'supplierid');
    }
    if (strcasecmp((string)$name, BF_NO_SUPPLIER_NAME) === 0) {
        return ['success' => true, 'data' => ['none' => true]];
    }

    $data         = dt_supplier_position($pdo, $id, max(0, (int)($_POST['purchaseid'] ?? 0)));
    $data['none'] = false;

    return ['success' => true, 'data' => $data];
}

/**
 * Quick add of a livestock item from the purchase modal (one transaction). Stock is always 0: the purchase adds it.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload with the new row.
 *
 * @throws DtFail On invalid input, a duplicate name, or a type or status that is not in the saved lists.
 */
function dt_act_livestockquicksave(PDO $pdo): array
{
    $name  = trim((string)($_POST['name'] ?? ''));
    $type  = trim((string)($_POST['type'] ?? ''));
    $stage = trim((string)($_POST['stage'] ?? ''));

    if ($name === '') {
        throw new DtFail('Name is required.', false, 'name');
    }
    if (mb_strlen($name) > DT_MAX_NAME) {
        throw new DtFail('Name is too long (maximum ' . DT_MAX_NAME . ' characters).', false, 'name');
    }
    if ($type === '') {
        throw new DtFail('Select a valid type.', false, 'type');
    }
    if ($stage === '') {
        throw new DtFail('Select a valid stage.', false, 'stage');
    }
    $cost  = '0.00'; // the purchase sets Cost Each
    $price = '0.00'; // the purchase sets Selling Price

    return dt_transaction(static function (PDO $pdo) use ($name, $type, $stage, $cost, $price): array {
        $s = BF_LIVESTOCK;
        dt_assert_unique_name($pdo, $name, 0);
        $type  = dt_assert_lookup_exists($pdo, BF_LIVESTOCKTYPES, $type, 'type', 'Select a valid type.');
        $stage = dt_assert_lookup_exists($pdo, BF_LIVESTOCKSTAGES, $stage, 'stage', 'Select a valid stage.');
        dt_q(
            $pdo,
            "INSERT INTO `{$s['table']}` (`{$s['name']}`, `{$s['type']}`, `{$s['stock']}`, `{$s['cost']}`, `{$s['price']}`, `{$s['stage']}`) VALUES (:n, :t, :q, :c, :p, :st)",
            ['n' => $name, 't' => $type, 'q' => 0, 'c' => $cost, 'p' => $price, 'st' => $stage]
        );
        $id = (int)$pdo->lastInsertId();
        dt_log_livestock($pdo, 'Add', 'Livestock added: ' . $name);

        return ['success' => true, 'message' => 'Livestock added successfully.', 'row' => [
            'livestockid'  => $id,
            'name'         => $name,
            'type'         => $type,
            'currentstock' => 0,
            'costeach'     => $cost,
            'sellingprice' => $price,
        ]];
    });
}

/** Old version that used status, no longer called (delete it when you want). */
function dt_act_livestockquicksave_oldstatus(PDO $pdo): array
{
    $name   = trim((string)($_POST['name'] ?? ''));
    $type   = trim((string)($_POST['type'] ?? ''));
    $status = trim((string)($_POST['status'] ?? ''));

    if ($name === '') {
        throw new DtFail('Name is required.', false, 'name');
    }
    if (mb_strlen($name) > DT_MAX_NAME) {
        throw new DtFail('Name is too long (maximum ' . DT_MAX_NAME . ' characters).', false, 'name');
    }
    if ($type === '') {
        throw new DtFail('Select a valid type.', false, 'type');
    }
    if ($status === '') {
        throw new DtFail('Select a valid status.', false, 'status');
    }
    $cost  = '0.00'; // the purchase sets Cost Each
    $price = '0.00'; // the purchase sets Selling Price

    return dt_transaction(static function (PDO $pdo) use ($name, $type, $status, $cost, $price): array {
        $s = BF_LIVESTOCK;
        dt_assert_unique_name($pdo, $name, 0);
        $type   = dt_assert_lookup_exists($pdo, BF_LIVESTOCKTYPES, $type, 'type', 'Select a valid type.');
        $status = dt_assert_lookup_exists($pdo, BF_LIVESTOCKSTAGES, $status, 'status', 'Select a valid status.');
        dt_q(
            $pdo,
            "INSERT INTO `{$s['table']}` (`{$s['name']}`, `{$s['type']}`, `{$s['stock']}`, `{$s['cost']}`, `{$s['price']}`, `{$s['stage']}`) VALUES (:n, :t, :q, :c, :p, :st)",
            ['n' => $name, 't' => $type, 'q' => 0, 'c' => $cost, 'p' => $price, 'st' => $status]
        );
        $id = (int)$pdo->lastInsertId();
        dt_log_livestock($pdo, 'Add', 'Livestock added: ' . $name);

        return ['success' => true, 'message' => 'Livestock added successfully.', 'row' => [
            'livestockid'  => $id,
            'name'         => $name,
            'type'         => $type,
            'currentstock' => 0,
            'costeach'     => $cost,
            'sellingprice' => $price,
        ]];
    });
}

/* ================================================================
 * 5. HTML SHELL (same look as dashboard.php): CSS, head, topbar, sidebar, footer
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
.btn-info { background: var(--info); border-color: var(--info); color: #ffffff; }
.btn-info:hover, .btn-info:focus { background: #174a78; border-color: #174a78; color: #ffffff; box-shadow: none; }
.btn-info:disabled { background: var(--info); border-color: var(--info); color: #ffffff; opacity: .6; }
.btn-danger { background: var(--danger); border-color: var(--danger); color: #ffffff; }
.btn-danger:hover, .btn-danger:focus { background: var(--brand-primary-dark); border-color: var(--brand-primary-dark); color: #ffffff; box-shadow: none; }
.btn-danger:disabled { background: var(--danger); border-color: var(--danger); color: #ffffff; opacity: .6; }
.btn-secondary { background: #545b64; border-color: #545b64; color: #ffffff; }
.btn-secondary:hover, .btn-secondary:focus { background: #3f454c; border-color: #3f454c; color: #ffffff; box-shadow: none; }
.btn-secondary:disabled { background: #545b64; border-color: #545b64; color: #ffffff; opacity: .6; }
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
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
.helper-line { margin-top: 4px; font-size: 13px; color: var(--muted); }
.line-warn { margin-top: 4px; font-size: 13px; font-weight: 500; color: var(--warning); }
.totals-box { display: flex; flex-wrap: wrap; justify-content: flex-start; gap: 4px 24px; font-size: 14px; }
.totals-box strong { color: var(--brand-primary); }
.modal-footer .totals-box { margin-right: auto; }

/* ===== Badges ===== */
.badge { display: inline-block; padding: 2px 8px; font-size: 12.5px; font-weight: 600; line-height: 1.5; color: #ffffff; background: var(--muted); border-radius: 0; }
.badge.st-active { background: var(--brand-accent-dark); }
.badge.st-inactive { background: #6c757d; }
.badge.st-soon { background: var(--warning); }
.badge.st-expired { background: var(--danger); }

/* ===== Modals ===== */
.modal-content { border: 0; border-radius: 0; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25); }
.modal-header { background: var(--brand-primary); color: #ffffff; border-radius: 0; border-bottom: 0; padding: 10px 20px; }
.modal-header .modal-title { color: #ffffff; font-size: 16px; font-weight: 500; margin: 0; }
.modal-header .close { color: #ffffff; opacity: .9; text-shadow: none; }
.modal-header .close:hover { opacity: 1; color: #ffffff; }
.modal-footer { border-top: 1px solid var(--line); padding: 10px 20px; }
.modal-xl { max-width: min(1140px, 96vw); }

/* ===== Quotation, return lines ===== */
.lines-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 6px 0 8px; }
.lines-title { font-size: 15px; font-weight: 600; color: var(--brand-primary); }
.lines-table { min-width: 760px; margin-bottom: 0; font-size: 14px; }
.lines-table thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); white-space: nowrap; font-weight: 600; padding: 6px 8px; }
.lines-table td { vertical-align: top; padding: 6px 8px; }
.lines-table .form-control { height: 34px; padding: 4px 8px; font-size: 14px; }
.lines-table .w-qty { width: 140px; }
.lines-table .w-price { width: 150px; }
.lines-table .w-total { width: 150px; }
.lines-table .w-rm { width: 56px; }
.lines-table .ln-total-cell { text-align: right; white-space: nowrap; padding-top: 12px; font-weight: 500; }
.lines-table output { color: var(--heading); }
.lines-table.returns-table { min-width: 900px; }
.lines-table.returns-table .num-cell { text-align: right; white-space: nowrap; padding-top: 12px; }

/* ===== DataTable ===== */
#livestockTable, #salesTable, #invLinesTable { width: 100% !important; font-size: 14px; }
#livestockTable thead th, #salesTable thead th, #invLinesTable thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); white-space: nowrap; font-weight: 600; }
#livestockTable td, #salesTable td, #invLinesTable td { vertical-align: middle; }
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

/* ===== Invoice modal (also printed on A4) ===== */
.invoice-sheet { position: relative; overflow: hidden; background: #ffffff; color: var(--text); }
.invoice-sheet > *:not(.inv-watermark) { position: relative; z-index: 1; }
.inv-watermark { position: absolute; z-index: 0; top: 50%; left: 50%; width: 60%; height: auto; transform: translate(-50%, -50%); opacity: .08; pointer-events: none; user-select: none; }
.inv-watermark-text { width: 90%; text-align: center; font-size: 56px; font-weight: 700; line-height: 1.1; color: var(--brand-primary); transform: translate(-50%, -50%) rotate(-30deg); }
.inv-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 12px; border-bottom: 3px solid var(--brand-primary); padding-bottom: 12px; margin-bottom: 14px; }
.inv-brand { display: flex; align-items: center; gap: 12px; }
.inv-brand img { height: 56px; width: auto; max-width: 160px; object-fit: contain; display: block; }
.inv-farm { font-size: 18px; font-weight: 600; color: var(--brand-primary); }
.inv-title { text-align: right; }
.inv-title h2 { font-size: 22px; font-weight: 600; color: var(--brand-primary); letter-spacing: .5px; }
.inv-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 24px; margin-bottom: 14px; font-size: 14px; }
.inv-meta .lbl { color: var(--muted); font-size: 13px; }
.inv-lines { margin-bottom: 14px; }
.inv-totals { margin-left: auto; width: 100%; max-width: 380px; font-size: 14px; }
.inv-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 4px 0; border-bottom: 1px solid var(--line); }
.inv-row.grand { font-weight: 600; color: var(--brand-primary); border-bottom: 2px solid var(--brand-primary); }
.inv-history { list-style: none; margin: 6px 0 0; padding: 0; font-size: 13.5px; }
.inv-history li { padding: 5px 0; border-bottom: 1px solid var(--line); }
.modal-footer .inv-btns { margin-right: auto; max-width: 100%; }
.inv-btns .dt-buttons { flex-wrap: wrap; }

/* ===== Bulk sale modal, compact action buttons ===== */
.sale-head { grid-template-columns: 1fr 1fr 1fr; }
@media (max-width: 767px) { .sale-head { grid-template-columns: 1fr 1fr; } }
@media (max-width: 575px) { .sale-head { grid-template-columns: 1fr; } }
.sale-lines-scroll { max-height: 42vh; overflow: auto; border: 1px solid var(--line); }
.sale-lines-scroll .lines-table thead th { position: sticky; top: 0; z-index: 2; box-shadow: 0 1px 0 var(--line); }
#livestockTable thead th, #salesTable thead th { white-space: normal; }
#livestockTable td:last-child, #salesTable td:last-child { white-space: nowrap; font-size: 0; text-align: center; }
#livestockTable td:last-child .act-btn, #salesTable td:last-child .act-btn {
    font-size: 13px; width: 28px; height: 28px; padding: 0; margin: 0 1px;
    display: inline-flex; align-items: center; justify-content: center; vertical-align: middle;
}
#livestockTable td, #salesTable td { padding: .5rem .4rem; }
#livestockTable th.px60 { width: 44px !important; }
#livestockTable th.px140 { width: 92px !important; }
#livestockTable th.px120 { width: 86px !important; }
#livestockTable th.px220 { width: 80px !important; }
#salesTable th.px220 { width: 156px !important; }
#livestockTable .badge { white-space: normal; text-align: left; }

/* ===== Quotations and Purchases lists, view modals, customer position ===== */
#quotationsTable, #purchasesTable, #qvLinesTable, #pvLinesTable { width: 100% !important; font-size: 14px; }
#quotationsTable thead th, #purchasesTable thead th, #qvLinesTable thead th, #pvLinesTable thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); white-space: normal; font-weight: 600; }
#quotationsTable td, #purchasesTable td, #qvLinesTable td, #pvLinesTable td { vertical-align: middle; padding: .5rem .4rem; }
#quotationsTable td:last-child, #purchasesTable td:last-child { white-space: nowrap; font-size: 0; text-align: center; }
#quotationsTable td:last-child .act-btn, #purchasesTable td:last-child .act-btn {
    font-size: 13px; width: 28px; height: 28px; padding: 0; margin: 0 1px;
    display: inline-flex; align-items: center; justify-content: center; vertical-align: middle;
}
#quotationsTable th.px220, #purchasesTable th.px220 { width: 100px !important; }
.badge.st-sent { background: var(--info); }
.cust-pos { border: 1px solid var(--line); background: var(--surface); padding: 6px 10px; color: var(--text); }
.cust-pos .cp-nums { display: flex; flex-wrap: wrap; gap: 2px 14px; margin-top: 4px; }
.cust-pos .cp-nums strong { color: var(--heading); }

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
.px60 { width: 60px; } .px120 { width: 120px; } .px140 { width: 140px; } .px220 { width: 220px; }

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
@media (max-width: 575px) { .user-name, .chev { display: none; } .form-grid { grid-template-columns: 1fr; } .inv-meta { grid-template-columns: 1fr; } .inv-title { text-align: left; } }
@media (max-width: 419px) { .topbar-left { display: none; } }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition: none !important; } }

/* ===== Print: only the open quotation or purchase (A4), same rules as the invoice ===== */
@media print {
    body.qview-open, body.pview-open { background: #ffffff !important; overflow: visible !important; padding: 0 !important; display: block; }
    body.qview-open > *:not(#quotationViewModal), body.pview-open > *:not(#purchaseViewModal) { display: none !important; }
    body.qview-open #quotationViewModal, body.pview-open #purchaseViewModal { position: static !important; display: block !important; overflow: visible !important; background: #ffffff; opacity: 1 !important; }
    body.qview-open #quotationViewModal .modal-dialog, body.pview-open #purchaseViewModal .modal-dialog { max-width: none !important; width: 100% !important; margin: 0 !important; height: auto !important; max-height: none !important; transform: none !important; }
    body.qview-open #quotationViewModal .modal-content, body.pview-open #purchaseViewModal .modal-content { border: 0; box-shadow: none; height: auto !important; max-height: none !important; overflow: visible !important; }
    body.qview-open #quotationViewModal .modal-body, body.pview-open #purchaseViewModal .modal-body { overflow: visible !important; padding: 0; }
    body.qview-open #quotationViewModal .modal-header, body.qview-open #quotationViewModal .modal-footer, body.qview-open #quotationViewModal .no-print,
    body.pview-open #purchaseViewModal .modal-header, body.pview-open #purchaseViewModal .modal-footer, body.pview-open #purchaseViewModal .no-print { display: none !important; }
    body.qview-open #quotationViewModal .table-scroll, body.pview-open #purchaseViewModal .table-scroll { overflow: visible !important; }
    body.qview-open #quotationViewModal tr, body.pview-open #purchaseViewModal tr { page-break-inside: avoid; }
    body.qview-open #quotationViewModal .invoice-sheet, body.pview-open #purchaseViewModal .invoice-sheet { overflow: visible; }
    body.qview-open #quotationViewModal .inv-watermark, body.pview-open #purchaseViewModal .inv-watermark { position: fixed; }
}

/* ===== Print: only the invoice (A4), without the shell, sidebar, buttons or other modals ===== */
@media print {
    @page { size: A4; margin: 12mm; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body.invoice-open { background: #ffffff !important; overflow: visible !important; padding: 0 !important; display: block; }
    body.invoice-open > *:not(#invoiceModal) { display: none !important; }
    body.invoice-open #invoiceModal { position: static !important; display: block !important; overflow: visible !important; background: #ffffff; opacity: 1 !important; }
    body.invoice-open #invoiceModal .modal-dialog { max-width: none !important; width: 100% !important; margin: 0 !important; height: auto !important; max-height: none !important; transform: none !important; }
    body.invoice-open #invoiceModal .modal-content { border: 0; box-shadow: none; height: auto !important; max-height: none !important; overflow: visible !important; }
    body.invoice-open #invoiceModal .modal-body { overflow: visible !important; padding: 0; }
    body.invoice-open #invoiceModal .modal-header,
    body.invoice-open #invoiceModal .modal-footer,
    body.invoice-open #invoiceModal .no-print { display: none !important; }
    body.invoice-open #invoiceModal .table-scroll { overflow: visible !important; }
    body.invoice-open #invoiceModal tr { page-break-inside: avoid; }
    body.invoice-open #invoiceModal .invoice-sheet { overflow: visible; }
    body.invoice-open #invoiceModal .inv-watermark { position: fixed; }
}
/* ===== Quick add: "+" button and inline panels next to the selects ===== */
.input-group-append .btn { height: 38px; border-radius: 0; }
.btn-quick-add { background: #ffffff; border: 1px solid var(--line); color: var(--brand-accent-dark); padding: 0 12px; }
.btn-quick-add:hover { background: var(--surface); color: var(--brand-accent-dark); border-color: var(--brand-accent); }
.btn-quick-add[aria-expanded="true"] { background: var(--brand-accent-dark); border-color: var(--brand-accent-dark); color: #ffffff; }
.btn-quick-add:disabled { opacity: .6; cursor: not-allowed; }
.btn-quick-add:focus, .btn-quick-add:focus-visible { outline: 3px solid var(--brand-accent); outline-offset: 2px; box-shadow: none; }
.input-group > .invalid-feedback { width: 100%; }
.quick-panel { border-left: 3px solid var(--brand-accent); background: var(--surface); padding: 12px 14px; margin-top: 8px; }
.form-grid > .quick-panel { grid-column: 1 / -1; margin: 0 0 1rem; }
.quick-panel .quick-panel { background: #ffffff; }
.quick-panel .quick-title { font-size: 14px; font-weight: 600; color: var(--brand-primary); margin-bottom: 8px; }
.quick-panel .form-group { margin-bottom: 8px; }
.quick-panel .form-control:focus, .quick-panel .btn:focus-visible { outline-color: var(--brand-accent); }
.quick-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
.quick-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; }
.quick-ok { margin-top: 4px; font-size: 13px; font-weight: 500; color: var(--brand-accent-dark); }
.quick-ok:empty { margin-top: 0; }
@media (max-width: 575px) {
    .quick-panel { padding: 10px; }
    .quick-grid { grid-template-columns: 1fr; }
    .quick-actions .btn { flex: 1 1 auto; }
    .btn-quick-add { padding: 0 10px; }
}
/* ===== Manage types and statuses: list, inline rename, inline delete confirm ===== */
.manage-list { max-height: 220px; overflow-y: auto; border: 1px solid var(--line); background: #ffffff; margin-bottom: 8px; }
.manage-empty { padding: 8px 10px; font-size: 13px; color: var(--muted); }
.manage-row { display: flex; align-items: center; gap: 8px; padding: 6px 10px; border-bottom: 1px solid var(--line); }
.manage-row:last-child { border-bottom: 0; }
.manage-name { flex: 1 1 auto; min-width: 0; overflow-wrap: anywhere; font-weight: 500; color: var(--heading); }
.manage-row .badge { flex: none; }
.manage-btns { flex: none; display: flex; align-items: center; gap: 4px; }
.manage-btns .act-btn {
    width: 28px; height: 28px; padding: 0; font-size: 13px;
    display: inline-flex; align-items: center; justify-content: center;
}
.manage-btns .btn-sm { white-space: nowrap; }
.manage-btns .btn:disabled { opacity: .5; cursor: not-allowed; }
.manage-btns .btn:focus-visible { outline: 3px solid var(--brand-accent); outline-offset: 2px; box-shadow: none; }
.manage-row.is-edit { align-items: flex-start; background: var(--surface); }
.manage-edit { flex: 1 1 auto; min-width: 0; }
.manage-edit .form-control { height: 34px; padding: 4px 8px; font-size: 14px; }
.manage-row.is-confirm { background: var(--surface); border-left: 3px solid var(--danger); }
.manage-q { color: var(--danger); }
@media (max-width: 575px) {
    .manage-row { flex-wrap: wrap; }
    .manage-name, .manage-edit { flex: 1 1 100%; }
    .manage-btns { width: 100%; flex-wrap: wrap; }
}
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
                    <a href="<?= dt_e($page) ?>" title="<?= dt_e($label) ?>"<?= $key === 'livestock' ? ' class="active" aria-current="page"' : '' ?>>
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
 * Livestock list card.
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
                  <h2 class="header-title">Livestock List</h2>
                  <div class="card-actions">
                    <button type="button" class="btn btn-success btn-sm" id="btnPurchase"><i class="fas fa-shopping-cart mr-1" aria-hidden="true"></i> Purchase Livestock</button>
                    <button type="button" class="btn btn-success btn-sm" id="btnPurchases"><i class="fas fa-list mr-1" aria-hidden="true"></i> Purchases</button>
                    <button type="button" class="btn btn-info btn-sm" id="btnSale"><i class="fas fa-cash-register mr-1" aria-hidden="true"></i> Add New Sale</button>
                    <button type="button" class="btn btn-info btn-sm" id="btnSales"><i class="fas fa-file-invoice-dollar mr-1" aria-hidden="true"></i> Sales</button>
                    <button type="button" class="btn btn-secondary btn-sm" id="btnQuotation"><i class="fas fa-file-alt mr-1" aria-hidden="true"></i> Make Quotation</button>
                    <button type="button" class="btn btn-secondary btn-sm" id="btnQuotations"><i class="fas fa-list-alt mr-1" aria-hidden="true"></i> Quotations</button>
                    <button type="button" class="btn btn-primary btn-sm" id="btnAdd"><i class="fas fa-plus mr-1" aria-hidden="true"></i> Add Livestock</button>
                  </div>
                </div>

                <table id="livestockTable" class="table table-hover table-bordered">
                  <thead>
                    <tr>
                      <th class="px60">#</th>
                      <th>Name</th>
                      <th class="px140">Type</th>
                      <th class="px140">Current Stock</th>
                      <th class="px120">Cost Each</th>
                      <th class="px120">Selling Price</th>
                      <th class="px120">Stage</th>
                      <th class="px220 text-center">Action</th>
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
 * Add / edit livestock modal.
 *
 * @return void
 */
function dt_modal_livestock(): void
{
    ?>
  <div class="modal fade" id="livestockModal" tabindex="-1" role="dialog" aria-labelledby="livestockModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" role="document">
      <form class="modal-content" id="livestockForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="livestockModalTitle">Add Livestock</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="lId" value="0">
          <div class="form-group">
            <label for="lName">Name <span class="req">*</span></label>
            <input type="text" class="form-control" id="lName" maxlength="<?= DT_MAX_NAME ?>" required aria-describedby="lNameFb">
            <div class="invalid-feedback" id="lNameFb">Name is required.</div>
          </div>
          <div class="form-group">
            <label for="lType">Type <span class="req">*</span></label>
            <div class="input-group">
              <select class="form-control" id="lType" required aria-describedby="lTypeFb qtyOk">
                <option value="">Select type</option>
              </select>
              <div class="input-group-append">
                <button type="button" class="btn btn-quick-add btn-manage" id="btnMtyOpen" aria-expanded="false" aria-controls="mtyPanel" aria-label="Manage types" title="Manage types"><i class="fas fa-list" aria-hidden="true"></i></button>
                <button type="button" class="btn btn-quick-add" id="btnQtyAdd" aria-expanded="false" aria-controls="qtyPanel" aria-label="Add new type" title="Add new type"><i class="fas fa-plus" aria-hidden="true"></i></button>
              </div>
              <div class="invalid-feedback" id="lTypeFb">Type is required.</div>
            </div>
            <div class="quick-ok" id="qtyOk" role="status" aria-live="polite"></div>
            <div class="quick-panel" id="mtyPanel" role="group" aria-labelledby="mtyTitle" hidden>
              <div class="quick-title" id="mtyTitle">Saved types</div>
              <div class="manage-list" id="mtyList"></div>
              <div class="quick-ok" id="mtyOk" role="status" aria-live="polite"></div>
              <div class="quick-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="btnMtyClose">Close</button>
              </div>
            </div>
            <div class="quick-panel" id="qtyPanel" role="group" aria-labelledby="qtyTitle" hidden>
              <div class="quick-title" id="qtyTitle">New type</div>
              <div class="form-group">
                <label for="qtyName">Type name <span class="req">*</span></label>
                <input type="text" class="form-control" id="qtyName" maxlength="<?= DT_MAX_TYPE ?>" aria-describedby="qtyNameFb">
                <div class="invalid-feedback" id="qtyNameFb" data-default="Type name is required.">Type name is required.</div>
              </div>
              <div class="quick-actions">
                <button type="button" class="btn btn-success btn-sm btn-quick-save" id="btnQtySave"><i class="fas fa-check mr-1" aria-hidden="true"></i> Save</button>
                <button type="button" class="btn btn-secondary btn-sm" id="btnQtyCancel">Cancel</button>
              </div>
            </div>
          </div>
          <div class="form-group">
            <label for="lStock">Current Stock <span class="req">*</span></label>
            <input type="number" class="form-control" id="lStock" min="0" max="<?= DT_MAX_STOCK ?>" step="1" inputmode="numeric" value="0" required aria-describedby="lStockFb">
            <div class="invalid-feedback" id="lStockFb">Enter a whole number from 0 to <?= number_format(DT_MAX_STOCK) ?>.</div>
          </div>
          <div class="form-row">
            <div class="form-group col-sm-6">
              <label for="lCostEach">Cost Each <span class="req">*</span></label>
              <input type="number" class="form-control" id="lCostEach" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="0.00" required aria-describedby="lCostEachFb">
              <div class="invalid-feedback" id="lCostEachFb">Cost each must be 0 or more, with up to 2 decimals.</div>
            </div>
            <div class="form-group col-sm-6">
              <label for="lSellingPrice">Selling Price <span class="req">*</span></label>
              <input type="number" class="form-control" id="lSellingPrice" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="0.00" required aria-describedby="lSellingPriceFb">
              <div class="invalid-feedback" id="lSellingPriceFb">Selling price must be 0 or more, with up to 2 decimals.</div>
            </div>
          </div>
          <div class="form-group mb-0">
            <label for="lStage">Stage <span class="req">*</span></label>
            <div class="input-group">
              <select class="form-control" id="lStage" required aria-describedby="lStageFb QsgOk">
                <option value="">Select stage</option>
              </select>
              <div class="input-group-append">
                <button type="button" class="btn btn-quick-add btn-manage" id="btnMsgOpen" aria-expanded="false" aria-controls="MsgPanel" aria-label="Manage stages" title="Manage stages"><i class="fas fa-list" aria-hidden="true"></i></button>
                <button type="button" class="btn btn-quick-add" id="btnQsgAdd" aria-expanded="false" aria-controls="QsgPanel" aria-label="Add new stage" title="Add new stage"><i class="fas fa-plus" aria-hidden="true"></i></button>
              </div>
              <div class="invalid-feedback" id="lStageFb">Stage is required.</div>
            </div>
            <div class="quick-ok" id="QsgOk" role="status" aria-live="polite"></div>
            <div class="quick-panel" id="MsgPanel" role="group" aria-labelledby="MsgTitle" hidden>
              <div class="quick-title" id="MsgTitle">Saved stages</div>
              <div class="manage-list" id="MsgList"></div>
              <div class="quick-ok" id="MsgOk" role="status" aria-live="polite"></div>
              <div class="quick-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="btnMsgClose">Close</button>
              </div>
            </div>
            <div class="quick-panel" id="QsgPanel" role="group" aria-labelledby="QsgTitle" hidden>
              <div class="quick-title" id="QsgTitle">New stage</div>
              <div class="form-group">
                <label for="QsgName">Stage name <span class="req">*</span></label>
                <input type="text" class="form-control" id="QsgName" maxlength="<?= DT_MAX_STAGE ?>" aria-describedby="QsgNameFb">
                <div class="invalid-feedback" id="QsgNameFb" data-default="Stage name is required.">Stage name is required.</div>
              </div>
              <div class="quick-actions">
                <button type="button" class="btn btn-success btn-sm btn-quick-save" id="btnQsgSave"><i class="fas fa-check mr-1" aria-hidden="true"></i> Save</button>
                <button type="button" class="btn btn-secondary btn-sm" id="btnQsgCancel">Cancel</button>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveLivestock"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

function dt_modal_livestock_old(): void
{
    ?>
  <div class="modal fade" id="livestockModal" tabindex="-1" role="dialog" aria-labelledby="livestockModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form class="modal-content" id="livestockForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="livestockModalTitle">Add Livestock</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="lId" value="0">
          <div class="form-group">
            <label for="lName">Name <span class="req">*</span></label>
            <input type="text" class="form-control" id="lName" maxlength="<?= DT_MAX_NAME ?>" required aria-describedby="lNameFb">
            <div class="invalid-feedback" id="lNameFb">Name is required.</div>
          </div>
          <div class="form-group">
            <label for="lType">Type <span class="req">*</span></label>
            <input type="text" class="form-control" id="lType" maxlength="<?= DT_MAX_TYPE ?>" required aria-describedby="lTypeFb">
            <div class="invalid-feedback" id="lTypeFb">Type is required.</div>
          </div>
          <div class="form-group">
            <label for="lStock">Current Stock <span class="req">*</span></label>
            <input type="number" class="form-control" id="lStock" min="0" max="<?= DT_MAX_STOCK ?>" step="1" inputmode="numeric" value="0" required aria-describedby="lStockFb">
            <div class="invalid-feedback" id="lStockFb">Enter a whole number from 0 to <?= number_format(DT_MAX_STOCK) ?>.</div>
          </div>
          <div class="form-row">
            <div class="form-group col-sm-6">
              <label for="lCostEach">Cost Each <span class="req">*</span></label>
              <input type="number" class="form-control" id="lCostEach" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="0.00" required aria-describedby="lCostEachFb">
              <div class="invalid-feedback" id="lCostEachFb">Cost each must be 0 or more, with up to 2 decimals.</div>
            </div>
            <div class="form-group col-sm-6">
              <label for="lSellingPrice">Selling Price <span class="req">*</span></label>
              <input type="number" class="form-control" id="lSellingPrice" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="0.00" required aria-describedby="lSellingPriceFb">
              <div class="invalid-feedback" id="lSellingPriceFb">Selling price must be 0 or more, with up to 2 decimals.</div>
            </div>
          </div>
          <div class="form-group mb-0">
            <label for="lStage">Stage <span class="req">*</span></label>
            <input type="text" class="form-control" id="lStage" maxlength="<?= DT_MAX_STAGE ?>" value="Active" required aria-describedby="lStageFb">
            <div class="invalid-feedback" id="lStageFb">Stage is required.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveLivestock"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Purchase livestock modal (one livestock item per purchase).
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
          <h5 class="modal-title" id="purchaseModalTitle">Purchase Livestock</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-grid">
            <div class="form-group">
              <label for="puSupplier">Supplier <span class="req">*</span></label>
              <div class="input-group">
                <select class="form-control" id="puSupplier" required aria-describedby="puSupplierHelp puSupplierFb qspOk">
                  <option value="">Select supplier</option>
                </select>
                <div class="input-group-append">
                  <button type="button" class="btn btn-quick-add" id="btnQspAdd" aria-expanded="false" aria-controls="qspPanel" aria-label="Add new supplier" title="Add new supplier"><i class="fas fa-plus" aria-hidden="true"></i></button>
                </div>
                <div class="invalid-feedback" id="puSupplierFb">Supplier is required.</div>
              </div>
              <div class="helper-line" id="puSupplierHelp" aria-live="polite"></div>
              <div class="quick-ok" id="qspOk" role="status" aria-live="polite"></div>
              <div class="helper-line cust-pos" id="puSupPos" aria-live="polite" hidden></div>
            </div>
            <div class="form-group">
              <label for="puDate">Purchase Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="puDate" required aria-describedby="puDateFb">
              <div class="invalid-feedback" id="puDateFb">Purchase date must be a valid date.</div>
            </div>
            <div class="quick-panel" id="qspPanel" role="group" aria-labelledby="qspTitle" hidden>
              <div class="quick-title" id="qspTitle">New supplier</div>
              <div class="quick-grid">
                <div class="form-group">
                  <label for="qspName">Supplier name <span class="req">*</span></label>
                  <input type="text" class="form-control" id="qspName" maxlength="<?= DT_MAX_NAME ?>" aria-describedby="qspNameFb">
                  <div class="invalid-feedback" id="qspNameFb" data-default="Supplier name is required.">Supplier name is required.</div>
                </div>
                <div class="form-group">
                  <label for="qspContact">Contact</label>
                  <input type="text" class="form-control" id="qspContact" maxlength="<?= DT_MAX_CONTACT ?>" aria-describedby="qspContactFb">
                  <div class="invalid-feedback" id="qspContactFb" data-default="Contact is too long (maximum <?= DT_MAX_CONTACT ?> characters).">Contact is too long (maximum <?= DT_MAX_CONTACT ?> characters).</div>
                </div>
              </div>
              <div class="form-group">
                <label for="qspAddress">Address</label>
                <input type="text" class="form-control" id="qspAddress" maxlength="<?= DT_MAX_ADDRESS ?>" aria-describedby="qspAddressFb">
                <div class="invalid-feedback" id="qspAddressFb" data-default="Address is too long (maximum <?= DT_MAX_ADDRESS ?> characters).">Address is too long (maximum <?= DT_MAX_ADDRESS ?> characters).</div>
              </div>
              <div class="quick-actions">
                <button type="button" class="btn btn-success btn-sm btn-quick-save" id="btnQspSave"><i class="fas fa-check mr-1" aria-hidden="true"></i> Save</button>
                <button type="button" class="btn btn-secondary btn-sm" id="btnQspCancel">Cancel</button>
              </div>
            </div>
            <div class="form-group">
              <label for="puRef">Reference</label>
              <input type="text" class="form-control" id="puRef" maxlength="<?= DT_MAX_REFERENCE ?>" aria-describedby="puRefHelp puRefFb">
              <div class="helper-line" id="puRefHelp">Supplier invoice number.</div>
              <div class="invalid-feedback" id="puRefFb">Reference is too long.</div>
            </div>
            <div class="form-group">
              <label for="puLivestock">Livestock <span class="req">*</span></label>
              <div class="input-group">
                <select class="form-control" id="puLivestock" required aria-describedby="puLivestockHelp puLivestockFb qlvOk">
                  <option value="">Select livestock</option>
                </select>
                <div class="input-group-append">
                  <button type="button" class="btn btn-quick-add" id="btnQlvAdd" aria-expanded="false" aria-controls="qlvPanel" aria-label="Add new livestock" title="Add new livestock"><i class="fas fa-plus" aria-hidden="true"></i></button>
                </div>
                <div class="invalid-feedback" id="puLivestockFb">Select a livestock item.</div>
              </div>
              <div class="helper-line" id="puLivestockHelp" aria-live="polite"></div>
              <div class="quick-ok" id="qlvOk" role="status" aria-live="polite"></div>
            </div>
            <div class="quick-panel" id="qlvPanel" role="group" aria-labelledby="qlvTitle" hidden>
              <div class="quick-title" id="qlvTitle">New livestock (stock starts at 0, this purchase adds it)</div>
              <div class="form-group">
                <label for="qlvName">Livestock name <span class="req">*</span></label>
                <input type="text" class="form-control" id="qlvName" maxlength="<?= DT_MAX_NAME ?>" aria-describedby="qlvNameFb">
                <div class="invalid-feedback" id="qlvNameFb" data-default="Name is required.">Name is required.</div>
              </div>
              <div class="quick-grid">
                <div class="form-group">
                  <label for="qlvType">Type <span class="req">*</span></label>
                  <div class="input-group">
                    <select class="form-control" id="qlvType" aria-describedby="qlvTypeFb qptOk">
                      <option value="">Select type</option>
                    </select>
                    <div class="input-group-append">
                      <button type="button" class="btn btn-quick-add" id="btnQptAdd" aria-expanded="false" aria-controls="qptPanel" aria-label="Add new type" title="Add new type"><i class="fas fa-plus" aria-hidden="true"></i></button>
                    </div>
                    <div class="invalid-feedback" id="qlvTypeFb" data-default="Select a valid type.">Select a valid type.</div>
                  </div>
                  <div class="quick-ok" id="qptOk" role="status" aria-live="polite"></div>
                  <div class="quick-panel" id="qptPanel" role="group" aria-labelledby="qptTitle" hidden>
                    <div class="quick-title" id="qptTitle">New type</div>
                    <div class="form-group">
                      <label for="qptName">Type name <span class="req">*</span></label>
                      <input type="text" class="form-control" id="qptName" maxlength="<?= DT_MAX_TYPE ?>" aria-describedby="qptNameFb">
                      <div class="invalid-feedback" id="qptNameFb" data-default="Type name is required.">Type name is required.</div>
                    </div>
                    <div class="quick-actions">
                      <button type="button" class="btn btn-success btn-sm btn-quick-save" id="btnQptSave"><i class="fas fa-check mr-1" aria-hidden="true"></i> Save</button>
                      <button type="button" class="btn btn-secondary btn-sm" id="btnQptCancel">Cancel</button>
                    </div>
                  </div>
                </div>
                <div class="form-group">
                  <label for="qlvStage">Stage <span class="req">*</span></label>
                  <div class="input-group">
                    <select class="form-control" id="qlvStage" aria-describedby="qlvStageFb QpgOk">
                      <option value="">Select stage</option>
                    </select>
                    <div class="input-group-append">
                      <button type="button" class="btn btn-quick-add" id="btnQpgAdd" aria-expanded="false" aria-controls="QpgPanel" aria-label="Add new stage" title="Add new stage"><i class="fas fa-plus" aria-hidden="true"></i></button>
                    </div>
                    <div class="invalid-feedback" id="qlvStageFb" data-default="Select a valid stage.">Select a valid stage.</div>
                  </div>
                  <div class="quick-ok" id="QpgOk" role="status" aria-live="polite"></div>
                  <div class="quick-panel" id="QpgPanel" role="group" aria-labelledby="QpgTitle" hidden>
                    <div class="quick-title" id="QpgTitle">New stage</div>
                    <div class="form-group">
                      <label for="QpgName">Stage name <span class="req">*</span></label>
                      <input type="text" class="form-control" id="QpgName" maxlength="<?= DT_MAX_STAGE ?>" aria-describedby="QpgNameFb">
                      <div class="invalid-feedback" id="QpgNameFb" data-default="Stage name is required.">Stage name is required.</div>
                    </div>
                    <div class="quick-actions">
                      <button type="button" class="btn btn-success btn-sm btn-quick-save" id="btnQpgSave"><i class="fas fa-check mr-1" aria-hidden="true"></i> Save</button>
                      <button type="button" class="btn btn-secondary btn-sm" id="btnQpgCancel">Cancel</button>
                    </div>
                  </div>
                </div>
                <div class="form-group">
                  </div><!-- cost each and selling price are not typed here: the purchase sets them
                  <input type="number" class="form-control" id="qlvCost" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="0.00" aria-describedby="qlvCostFb">
                  <div class="invalid-feedback" id="qlvCostFb" data-default="Enter an amount from 0.00 to <?= DT_MAX_MONEY ?>.">Enter an amount from 0.00 to <?= DT_MAX_MONEY ?>.</div>
                </div>
                <div class="form-group">
    
                  <input type="number" class="form-control" id="qlvPrice" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="0.00" aria-describedby="qlvPriceFb">
                  <div class="invalid-feedback" id="qlvPriceFb" data-default="Enter an amount from 0.00 to <?= DT_MAX_MONEY ?>.">Enter an amount from 0.00 to <?= DT_MAX_MONEY ?>.</div>
                </div>
              </div>
              <div class="quick-actions">
                --></div>
              <div class="quick-actions">
                <button type="button" class="btn btn-success btn-sm btn-quick-save" id="btnQlvSave"><i class="fas fa-check mr-1" aria-hidden="true"></i> Save</button>
                <button type="button" class="btn btn-secondary btn-sm" id="btnQlvCancel">Cancel</button>
              </div>
            </div>
            <div class="form-group">
              <label for="puQty">Quantity (head count) <span class="req">*</span></label>
              <input type="number" class="form-control" id="puQty" min="1" max="999999" step="1" inputmode="numeric" required aria-describedby="puQtyHelp puQtyFb">
              <div class="helper-line" id="puQtyHelp" aria-live="polite"></div>
              <div class="invalid-feedback" id="puQtyFb">Quantity must be a whole number greater than 0.</div>
            </div>
            <div class="form-group">
              <label for="puPurchaseCost">Purchase Cost ($) <span class="req">*</span></label>
              <input type="number" class="form-control" id="puPurchaseCost" min="0" max="999999999.99" step="0.01" inputmode="decimal" value="0" required aria-describedby="puPurchaseCostHelp puPurchaseCostFb">
              <div class="helper-line" id="puPurchaseCostHelp">Total cost of all animals in this purchase.</div>
              <div class="invalid-feedback" id="puPurchaseCostFb">Purchase cost must be 0 or more, with up to 2 decimals.</div>
            </div>
            <div class="form-group">
              <label for="puCostEach">Cost Each ($)</label>
              <input type="text" class="form-control" id="puCostEach" value="" readonly aria-readonly="true" aria-describedby="puCostEachHelp">
              <div class="helper-line" id="puCostEachHelp">Purchase Cost divided by Quantity.</div>
            </div>
            <div class="form-group">
              <label for="puSellingPrice">Selling Price ($) <span class="req">*</span></label>
              <input type="number" class="form-control" id="puSellingPrice" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="0" required aria-describedby="puSellingPriceHelp puSellingPriceFb">
              <div class="helper-line" id="puSellingPriceHelp">Selling price of one animal.</div>
              <div class="invalid-feedback" id="puSellingPriceFb">Selling price must be 0 or more, with up to 2 decimals.</div>
            </div>
            <div class="form-group">
              <label for="puPaid">Paid Amount ($)</label>
              <input type="number" class="form-control" id="puPaid" min="0" max="999999999.99" step="0.01" inputmode="decimal" value="0" aria-describedby="puPaidFb">
              <div class="invalid-feedback" id="puPaidFb">Paid amount must be 0 or more, with up to 2 decimals.</div>
            </div>
            <div class="form-group">
              <label for="puAccount">Paid From Account <span class="req" id="puAccountReq" hidden>*</span></label>
              <select class="form-control" id="puAccount" aria-describedby="puAccountHelp puAccountFb">
                <option value="">Select account</option>
              </select>
              <div class="helper-line" id="puAccountHelp" aria-live="polite"></div>
              <div class="invalid-feedback" id="puAccountFb">Select the account the payment came from.</div>
            </div>
            <div class="form-group">
              <label for="puNote">Note</label>
              <input type="text" class="form-control" id="puNote" maxlength="<?= DT_MAX_NOTE ?>" aria-describedby="puNoteFb">
              <div class="invalid-feedback" id="puNoteFb">Note is too long.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <div class="totals-box" aria-live="polite">
            <div>Purchase Cost: <strong id="puFootPurchaseCost">$0.00</strong></div>
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

function dt_modal_purchase_old(): void
{
    ?>
  <div class="modal fade" id="purchaseModal" tabindex="-1" role="dialog" aria-labelledby="purchaseModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable" role="document">
      <form class="modal-content" id="purchaseForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="purchaseModalTitle">Purchase Livestock</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-grid">
            <div class="form-group">
              <label for="puSupplier">Supplier <span class="req">*</span></label>
              <select class="form-control" id="puSupplier" required aria-describedby="puSupplierHelp puSupplierFb">
                <option value="">Select supplier</option>
              </select>
              <div class="helper-line" id="puSupplierHelp" aria-live="polite"></div>
              <div class="invalid-feedback" id="puSupplierFb">Supplier is required.</div>
            </div>
            <div class="form-group">
              <label for="puDate">Purchase Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="puDate" required aria-describedby="puDateFb">
              <div class="invalid-feedback" id="puDateFb">Purchase date must be a valid date.</div>
            </div>
            <div class="form-group">
              <label for="puRef">Reference</label>
              <input type="text" class="form-control" id="puRef" maxlength="<?= DT_MAX_REFERENCE ?>" aria-describedby="puRefHelp puRefFb">
              <div class="helper-line" id="puRefHelp">Supplier invoice number.</div>
              <div class="invalid-feedback" id="puRefFb">Reference is too long.</div>
            </div>
            <div class="form-group">
              <label for="puLivestock">Livestock <span class="req">*</span></label>
              <select class="form-control" id="puLivestock" required aria-describedby="puLivestockHelp puLivestockFb">
                <option value="">Select livestock</option>
              </select>
              <div class="helper-line" id="puLivestockHelp" aria-live="polite"></div>
              <div class="invalid-feedback" id="puLivestockFb">Select a livestock item.</div>
            </div>
            <div class="form-group">
              <label for="puQty">Quantity (head count) <span class="req">*</span></label>
              <input type="number" class="form-control" id="puQty" min="1" max="999999" step="1" inputmode="numeric" required aria-describedby="puQtyHelp puQtyFb">
              <div class="helper-line" id="puQtyHelp" aria-live="polite"></div>
              <div class="invalid-feedback" id="puQtyFb">Quantity must be a whole number greater than 0.</div>
            </div>
            <div class="form-group">
              <label for="puPurchaseCost">Purchase Cost ($) <span class="req">*</span></label>
              <input type="number" class="form-control" id="puPurchaseCost" min="0" max="999999999.99" step="0.01" inputmode="decimal" value="0" required aria-describedby="puPurchaseCostHelp puPurchaseCostFb">
              <div class="helper-line" id="puPurchaseCostHelp">Total cost of all animals in this purchase.</div>
              <div class="invalid-feedback" id="puPurchaseCostFb">Purchase cost must be 0 or more, with up to 2 decimals.</div>
            </div>
            <div class="form-group">
              <label for="puCostEach">Cost Each ($)</label>
              <input type="text" class="form-control" id="puCostEach" value="" readonly aria-readonly="true" aria-describedby="puCostEachHelp">
              <div class="helper-line" id="puCostEachHelp">Purchase Cost divided by Quantity.</div>
            </div>
            <div class="form-group">
              <label for="puSellingPrice">Selling Price ($) <span class="req">*</span></label>
              <input type="number" class="form-control" id="puSellingPrice" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="0" required aria-describedby="puSellingPriceHelp puSellingPriceFb">
              <div class="helper-line" id="puSellingPriceHelp">Selling price of one animal.</div>
              <div class="invalid-feedback" id="puSellingPriceFb">Selling price must be 0 or more, with up to 2 decimals.</div>
            </div>
            <div class="form-group">
              <label for="puPaid">Paid Amount ($)</label>
              <input type="number" class="form-control" id="puPaid" min="0" max="999999999.99" step="0.01" inputmode="decimal" value="0" aria-describedby="puPaidFb">
              <div class="invalid-feedback" id="puPaidFb">Paid amount must be 0 or more, with up to 2 decimals.</div>
            </div>
            <div class="form-group">
              <label for="puAccount">Paid From Account <span class="req" id="puAccountReq" hidden>*</span></label>
              <select class="form-control" id="puAccount" aria-describedby="puAccountHelp puAccountFb">
                <option value="">Select account</option>
              </select>
              <div class="helper-line" id="puAccountHelp" aria-live="polite"></div>
              <div class="invalid-feedback" id="puAccountFb">Select the account the payment came from.</div>
            </div>
            <div class="form-group">
              <label for="puNote">Note</label>
              <input type="text" class="form-control" id="puNote" maxlength="<?= DT_MAX_NOTE ?>" aria-describedby="puNoteFb">
              <div class="invalid-feedback" id="puNoteFb">Note is too long.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <div class="totals-box" aria-live="polite">
            <div>Purchase Cost: <strong id="puFootPurchaseCost">$0.00</strong></div>
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
 * Add New Sale modal (customer or walk-in, one or more livestock lines, saved as an invoice).
 *
 * @return void
 */
function dt_modal_sale(): void
{
    ?>
  <div class="modal fade" id="saleModal" tabindex="-1" role="dialog" aria-labelledby="saleModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable" role="document">
      <form class="modal-content" id="saleForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="saleModalTitle">Add New Sale</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning" id="saLockMsg" role="status" hidden></div>
          <div class="form-grid sale-head">
            <div class="form-group">
              <label for="saCustomer">Customer <span class="req">*</span></label>
              <div class="input-group">
                <select class="form-control" id="saCustomer" required aria-describedby="saCustomerFb qcsOk"><option value="">Select customer</option></select>
                <div class="input-group-append">
                  <button type="button" class="btn btn-quick-add" id="btnQcsAdd" aria-expanded="false" aria-controls="qcsPanel" aria-label="Add new customer" title="Add new customer"><i class="fas fa-plus" aria-hidden="true"></i></button>
                </div>
                <div class="invalid-feedback" id="saCustomerFb">Customer is required.</div>
              </div>
              <div class="quick-ok" id="qcsOk" role="status" aria-live="polite"></div>
              <div class="helper-line cust-pos" id="saCustPos" aria-live="polite" hidden></div>
              <div class="mt-2" id="saWalkinWrap" hidden>
                <label for="saWalkinName">Walk-in name (optional)</label>
                <input type="text" class="form-control" id="saWalkinName" maxlength="<?= DT_MAX_WALKIN ?>" aria-describedby="saWalkinNameHelp saWalkinNameFb">
                <div class="helper-line" id="saWalkinNameHelp">A walk-in sale must be paid in full.</div>
                <div class="invalid-feedback" id="saWalkinNameFb">Walk-in name is too long (maximum <?= DT_MAX_WALKIN ?> characters).</div>
              </div>
            </div>
            <div class="form-group">
              <label for="saDate">Sale Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="saDate" required aria-describedby="saDateFb">
              <div class="invalid-feedback" id="saDateFb">Sale date must be a valid date.</div>
            </div>
            <div class="form-group">
              <label for="saRef">Reference</label>
              <input type="text" class="form-control" id="saRef" maxlength="<?= DT_MAX_REFERENCE ?>" aria-describedby="saRefFb">
              <div class="invalid-feedback" id="saRefFb">Reference is too long.</div>
            </div>
            <div class="quick-panel" id="qcsPanel" role="group" aria-labelledby="qcsTitle" hidden>
              <div class="quick-title" id="qcsTitle">New customer</div>
              <div class="quick-grid">
                <div class="form-group">
                  <label for="qcsName">Customer name <span class="req">*</span></label>
                  <input type="text" class="form-control" id="qcsName" maxlength="<?= DT_MAX_NAME ?>" aria-describedby="qcsNameFb">
                  <div class="invalid-feedback" id="qcsNameFb" data-default="Customer name is required.">Customer name is required.</div>
                </div>
                <div class="form-group">
                  <label for="qcsContact">Contact</label>
                  <input type="text" class="form-control" id="qcsContact" maxlength="<?= DT_MAX_CONTACT ?>" aria-describedby="qcsContactFb">
                  <div class="invalid-feedback" id="qcsContactFb" data-default="Contact is too long (maximum <?= DT_MAX_CONTACT ?> characters).">Contact is too long (maximum <?= DT_MAX_CONTACT ?> characters).</div>
                </div>
              </div>
              <div class="form-group">
                <label for="qcsAddress">Address</label>
                <input type="text" class="form-control" id="qcsAddress" maxlength="<?= DT_MAX_ADDRESS ?>" aria-describedby="qcsAddressFb">
                <div class="invalid-feedback" id="qcsAddressFb" data-default="Address is too long (maximum <?= DT_MAX_ADDRESS ?> characters).">Address is too long (maximum <?= DT_MAX_ADDRESS ?> characters).</div>
              </div>
              <div class="quick-actions">
                <button type="button" class="btn btn-success btn-sm btn-quick-save" id="btnQcsSave"><i class="fas fa-check mr-1" aria-hidden="true"></i> Save</button>
                <button type="button" class="btn btn-secondary btn-sm" id="btnQcsCancel">Cancel</button>
              </div>
            </div>
            <div class="form-group">
              <label for="saPaid">Paid Amount ($)</label>
              <input type="number" class="form-control" id="saPaid" min="0" max="999999999.99" step="0.01" inputmode="decimal" value="0" aria-describedby="saPaidFb">
              <div class="invalid-feedback" id="saPaidFb">Paid amount must be 0 or more, with up to 2 decimals.</div>
            </div>
            <div class="form-group">
              <label for="saAccount">Receipt To Account <span class="req" id="saAccountReq" hidden>*</span></label>
              <select class="form-control" id="saAccount" aria-describedby="saAccountFb"><option value="">Select account</option></select>
              <div class="invalid-feedback" id="saAccountFb">Select the account the money was received into.</div>
            </div>
            <div class="form-group">
              <label for="saNote">Note</label>
              <input type="text" class="form-control" id="saNote" maxlength="<?= DT_MAX_NOTE ?>" aria-describedby="saNoteFb">
              <div class="invalid-feedback" id="saNoteFb">Note is too long.</div>
            </div>
          </div>

          <div class="lines-head">
            <h6 class="lines-title" id="saLinesHeading">Lines</h6>
            <button type="button" class="btn btn-primary btn-sm" id="btnAddSaleLine"><i class="fas fa-plus mr-1" aria-hidden="true"></i> Add line</button>
          </div>
          <div class="table-scroll sale-lines-scroll">
            <table class="table table-bordered lines-table" aria-labelledby="saLinesHeading">
              <thead>
                <tr>
                  <th>Livestock</th>
                  <th class="w-qty">Quantity</th>
                  <th class="w-price">Selling Price ($)</th>
                  <th class="w-total text-right">Line Total</th>
                  <th class="w-rm text-center"><span class="sr-only">Remove</span></th>
                </tr>
              </thead>
              <tbody id="saLinesBody"></tbody>
            </table>
          </div>
          <div class="invalid-feedback d-block" id="saLinesFb" role="alert" hidden>Add at least one line.</div>
        </div>
        <div class="modal-footer">
          <div class="totals-box" aria-live="polite">
            <div>Total: <strong id="saFootTotal">$0.00</strong></div>
            <div>Paid: <strong id="saFootPaid">$0.00</strong></div>
            <div>Balance due: <strong id="saFootBalance">$0.00</strong></div>
          </div>
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveSale"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

function dt_modal_sale_v1(): void
{
    ?>
  <div class="modal fade" id="saleModal" tabindex="-1" role="dialog" aria-labelledby="saleModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable" role="document">
      <form class="modal-content" id="saleForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="saleModalTitle">Add New Sale</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning" id="saLockMsg" role="status" hidden></div>
          <div class="form-grid sale-head">
            <div class="form-group">
              <label for="saCustomer">Customer <span class="req">*</span></label>
              <select class="form-control" id="saCustomer" required aria-describedby="saCustomerFb"><option value="">Select customer</option></select>
              <div class="invalid-feedback" id="saCustomerFb">Customer is required.</div>
              <div class="helper-line cust-pos" id="saCustPos" aria-live="polite" hidden></div>
              <div class="mt-2" id="saWalkinWrap" hidden>
                <label for="saWalkinName">Walk-in name (optional)</label>
                <input type="text" class="form-control" id="saWalkinName" maxlength="<?= DT_MAX_WALKIN ?>" aria-describedby="saWalkinNameHelp saWalkinNameFb">
                <div class="helper-line" id="saWalkinNameHelp">A walk-in sale must be paid in full.</div>
                <div class="invalid-feedback" id="saWalkinNameFb">Walk-in name is too long (maximum <?= DT_MAX_WALKIN ?> characters).</div>
              </div>
            </div>
            <div class="form-group">
              <label for="saDate">Sale Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="saDate" required aria-describedby="saDateFb">
              <div class="invalid-feedback" id="saDateFb">Sale date must be a valid date.</div>
            </div>
            <div class="form-group">
              <label for="saRef">Reference</label>
              <input type="text" class="form-control" id="saRef" maxlength="<?= DT_MAX_REFERENCE ?>" aria-describedby="saRefFb">
              <div class="invalid-feedback" id="saRefFb">Reference is too long.</div>
            </div>
            <div class="form-group">
              <label for="saPaid">Paid Amount ($)</label>
              <input type="number" class="form-control" id="saPaid" min="0" max="999999999.99" step="0.01" inputmode="decimal" value="0" aria-describedby="saPaidFb">
              <div class="invalid-feedback" id="saPaidFb">Paid amount must be 0 or more, with up to 2 decimals.</div>
            </div>
            <div class="form-group">
              <label for="saAccount">Receipt To Account <span class="req" id="saAccountReq" hidden>*</span></label>
              <select class="form-control" id="saAccount" aria-describedby="saAccountFb"><option value="">Select account</option></select>
              <div class="invalid-feedback" id="saAccountFb">Select the account the money was received into.</div>
            </div>
            <div class="form-group">
              <label for="saNote">Note</label>
              <input type="text" class="form-control" id="saNote" maxlength="<?= DT_MAX_NOTE ?>" aria-describedby="saNoteFb">
              <div class="invalid-feedback" id="saNoteFb">Note is too long.</div>
            </div>
          </div>

          <div class="lines-head">
            <h6 class="lines-title" id="saLinesHeading">Lines</h6>
            <button type="button" class="btn btn-primary btn-sm" id="btnAddSaleLine"><i class="fas fa-plus mr-1" aria-hidden="true"></i> Add line</button>
          </div>
          <div class="table-scroll sale-lines-scroll">
            <table class="table table-bordered lines-table" aria-labelledby="saLinesHeading">
              <thead>
                <tr>
                  <th>Livestock</th>
                  <th class="w-qty">Quantity</th>
                  <th class="w-price">Selling Price ($)</th>
                  <th class="w-total text-right">Line Total</th>
                  <th class="w-rm text-center"><span class="sr-only">Remove</span></th>
                </tr>
              </thead>
              <tbody id="saLinesBody"></tbody>
            </table>
          </div>
          <div class="invalid-feedback d-block" id="saLinesFb" role="alert" hidden>Add at least one line.</div>
        </div>
        <div class="modal-footer">
          <div class="totals-box" aria-live="polite">
            <div>Total: <strong id="saFootTotal">$0.00</strong></div>
            <div>Paid: <strong id="saFootPaid">$0.00</strong></div>
            <div>Balance due: <strong id="saFootBalance">$0.00</strong></div>
          </div>
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveSale"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/** Replaced by the Walk-in version above (not called, delete it when you want). */
function dt_modal_sale_prev(): void
{
    ?>
  <div class="modal fade" id="saleModal" tabindex="-1" role="dialog" aria-labelledby="saleModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable" role="document">
      <form class="modal-content" id="saleForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="saleModalTitle">Add New Sale</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning" id="saLockMsg" role="status" hidden></div>
          <div class="form-grid sale-head">
            <div class="form-group">
              <label for="saCustomer">Customer <span class="req">*</span></label>
              <select class="form-control" id="saCustomer" required aria-describedby="saCustomerFb"><option value="">Select customer</option></select>
              <div class="invalid-feedback" id="saCustomerFb">Customer is required.</div>
            </div>
            <div class="form-group">
              <label for="saDate">Sale Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="saDate" required aria-describedby="saDateFb">
              <div class="invalid-feedback" id="saDateFb">Sale date must be a valid date.</div>
            </div>
            <div class="form-group">
              <label for="saRef">Reference</label>
              <input type="text" class="form-control" id="saRef" maxlength="<?= DT_MAX_REFERENCE ?>" aria-describedby="saRefFb">
              <div class="invalid-feedback" id="saRefFb">Reference is too long.</div>
            </div>
            <div class="form-group">
              <label for="saPaid">Paid Amount ($)</label>
              <input type="number" class="form-control" id="saPaid" min="0" max="999999999.99" step="0.01" inputmode="decimal" value="0" aria-describedby="saPaidFb">
              <div class="invalid-feedback" id="saPaidFb">Paid amount must be 0 or more, with up to 2 decimals.</div>
            </div>
            <div class="form-group">
              <label for="saAccount">Receipt To Account <span class="req" id="saAccountReq" hidden>*</span></label>
              <select class="form-control" id="saAccount" aria-describedby="saAccountFb"><option value="">Select account</option></select>
              <div class="invalid-feedback" id="saAccountFb">Select the account the money was received into.</div>
            </div>
            <div class="form-group">
              <label for="saNote">Note</label>
              <input type="text" class="form-control" id="saNote" maxlength="<?= DT_MAX_NOTE ?>" aria-describedby="saNoteFb">
              <div class="invalid-feedback" id="saNoteFb">Note is too long.</div>
            </div>
          </div>

          <div class="lines-head">
            <h6 class="lines-title" id="saLinesHeading">Lines</h6>
            <button type="button" class="btn btn-primary btn-sm" id="btnAddSaleLine"><i class="fas fa-plus mr-1" aria-hidden="true"></i> Add line</button>
          </div>
          <div class="table-scroll sale-lines-scroll">
            <table class="table table-bordered lines-table" aria-labelledby="saLinesHeading">
              <thead>
                <tr>
                  <th>Livestock</th>
                  <th class="w-qty">Quantity</th>
                  <th class="w-price">Selling Price ($)</th>
                  <th class="w-total text-right">Line Total</th>
                  <th class="w-rm text-center"><span class="sr-only">Remove</span></th>
                </tr>
              </thead>
              <tbody id="saLinesBody"></tbody>
            </table>
          </div>
          <div class="invalid-feedback d-block" id="saLinesFb" role="alert" hidden>Add at least one line.</div>
        </div>
        <div class="modal-footer">
          <div class="totals-box" aria-live="polite">
            <div>Total: <strong id="saFootTotal">$0.00</strong></div>
            <div>Paid: <strong id="saFootPaid">$0.00</strong></div>
            <div>Balance due: <strong id="saFootBalance">$0.00</strong></div>
          </div>
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveSale"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/** Old one-animal modal, no longer used (delete it when you want). */
function dt_modal_sale_old(): void
{
    ?>
  <div class="modal fade" id="saleModal" tabindex="-1" role="dialog" aria-labelledby="saleModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable" role="document">
      <form class="modal-content" id="saleForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="saleModalTitle">Add New Sale</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-grid">
            <div class="form-group">
              <label for="saCustomer">Customer <span class="req">*</span></label>
              <select class="form-control" id="saCustomer" required aria-describedby="saCustomerFb">
                <option value="">Select customer</option>
              </select>
              <div class="invalid-feedback" id="saCustomerFb">Customer is required.</div>
            </div>
            <div class="form-group">
              <label for="saDate">Sale Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="saDate" required aria-describedby="saDateFb">
              <div class="invalid-feedback" id="saDateFb">Sale date must be a valid date.</div>
            </div>
            <div class="form-group">
              <label for="saRef">Reference</label>
              <input type="text" class="form-control" id="saRef" maxlength="<?= DT_MAX_REFERENCE ?>" aria-describedby="saRefHelp saRefFb">
              <div class="helper-line" id="saRefHelp">Customer order or reference number.</div>
              <div class="invalid-feedback" id="saRefFb">Reference is too long.</div>
            </div>
            <div class="form-group">
              <label for="saLivestock">Livestock <span class="req">*</span></label>
              <select class="form-control" id="saLivestock" required aria-describedby="saLivestockHelp saLivestockFb">
                <option value="">Select livestock</option>
              </select>
              <div class="helper-line" id="saLivestockHelp" aria-live="polite"></div>
              <div class="invalid-feedback" id="saLivestockFb">Select a livestock item.</div>
            </div>
            <div class="form-group">
              <label for="saQty">Quantity (head count) <span class="req">*</span></label>
              <input type="number" class="form-control" id="saQty" min="1" max="999999" step="1" inputmode="numeric" required aria-describedby="saQtyHelp saQtyFb">
              <div class="helper-line" id="saQtyHelp" aria-live="polite"></div>
              <div class="invalid-feedback" id="saQtyFb">Quantity must be a whole number greater than 0.</div>
            </div>
            <div class="form-group">
              <label for="saPrice">Selling Price ($) <span class="req">*</span></label>
              <input type="number" class="form-control" id="saPrice" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="0" required aria-describedby="saPriceHelp saPriceFb">
              <div class="helper-line" id="saPriceHelp" aria-live="polite"></div>
              <div class="invalid-feedback" id="saPriceFb">Selling price must be 0 or more, with up to 2 decimals.</div>
            </div>
            <div class="form-group">
              <label for="saTotal">Total</label>
              <input type="text" class="form-control" id="saTotal" value="$0.00" readonly aria-readonly="true">
            </div>
            <div class="form-group">
              <label for="saPaid">Paid Amount ($)</label>
              <input type="number" class="form-control" id="saPaid" min="0" max="999999999.99" step="0.01" inputmode="decimal" value="0" aria-describedby="saPaidFb">
              <div class="invalid-feedback" id="saPaidFb">Paid amount must be 0 or more, with up to 2 decimals.</div>
            </div>
            <div class="form-group">
              <label for="saAccount">Receipt To Account <span class="req" id="saAccountReq" hidden>*</span></label>
              <select class="form-control" id="saAccount" aria-describedby="saAccountFb">
                <option value="">Select account</option>
              </select>
              <div class="invalid-feedback" id="saAccountFb">Select the account the money was received into.</div>
            </div>
            <div class="form-group">
              <label for="saNote">Note</label>
              <input type="text" class="form-control" id="saNote" maxlength="<?= DT_MAX_NOTE ?>" aria-describedby="saNoteFb">
              <div class="invalid-feedback" id="saNoteFb">Note is too long.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <div class="totals-box" aria-live="polite">
            <div>Total: <strong id="saFootTotal">$0.00</strong></div>
            <div>Paid: <strong id="saFootPaid">$0.00</strong></div>
            <div>Balance due: <strong id="saFootBalance">$0.00</strong></div>
          </div>
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveSale"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Make quotation modal (header fields, livestock lines table, grand total).
 *
 * @return void
 */
function dt_modal_quotation(): void
{
    ?>
  <div class="modal fade" id="quotationModal" tabindex="-1" role="dialog" aria-labelledby="quotationModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable" role="document">
      <form class="modal-content" id="quotationForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="quotationModalTitle">Make Quotation</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-grid">
            <div class="form-group">
              <label for="quCustomer">Customer <span class="req">*</span></label>
              <div class="input-group">
                <select class="form-control" id="quCustomer" required aria-describedby="quCustomerFb qcqOk">
                  <option value="">Select customer</option>
                </select>
                <div class="input-group-append">
                  <button type="button" class="btn btn-quick-add" id="btnQcqAdd" aria-expanded="false" aria-controls="qcqPanel" aria-label="Add new customer" title="Add new customer"><i class="fas fa-plus" aria-hidden="true"></i></button>
                </div>
                <div class="invalid-feedback" id="quCustomerFb">Customer is required.</div>
              </div>
              <div class="quick-ok" id="qcqOk" role="status" aria-live="polite"></div>
              <div class="helper-line cust-pos" id="quCustPos" aria-live="polite" hidden></div>
              <div class="mt-2" id="quWalkinWrap" hidden>
                <label for="quWalkinName">Walk-in name (optional)</label>
                <input type="text" class="form-control" id="quWalkinName" maxlength="<?= DT_MAX_WALKIN ?>" aria-describedby="quWalkinNameFb">
                <div class="invalid-feedback" id="quWalkinNameFb">Walk-in name is too long (maximum <?= DT_MAX_WALKIN ?> characters).</div>
              </div>
            </div>
            <div class="form-group">
              <label for="quDate">Quotation Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="quDate" required aria-describedby="quDateFb">
              <div class="invalid-feedback" id="quDateFb">Quotation date must be a valid date.</div>
            </div>
            <div class="quick-panel" id="qcqPanel" role="group" aria-labelledby="qcqTitle" hidden>
              <div class="quick-title" id="qcqTitle">New customer</div>
              <div class="quick-grid">
                <div class="form-group">
                  <label for="qcqName">Customer name <span class="req">*</span></label>
                  <input type="text" class="form-control" id="qcqName" maxlength="<?= DT_MAX_NAME ?>" aria-describedby="qcqNameFb">
                  <div class="invalid-feedback" id="qcqNameFb" data-default="Customer name is required.">Customer name is required.</div>
                </div>
                <div class="form-group">
                  <label for="qcqContact">Contact</label>
                  <input type="text" class="form-control" id="qcqContact" maxlength="<?= DT_MAX_CONTACT ?>" aria-describedby="qcqContactFb">
                  <div class="invalid-feedback" id="qcqContactFb" data-default="Contact is too long (maximum <?= DT_MAX_CONTACT ?> characters).">Contact is too long (maximum <?= DT_MAX_CONTACT ?> characters).</div>
                </div>
              </div>
              <div class="form-group">
                <label for="qcqAddress">Address</label>
                <input type="text" class="form-control" id="qcqAddress" maxlength="<?= DT_MAX_ADDRESS ?>" aria-describedby="qcqAddressFb">
                <div class="invalid-feedback" id="qcqAddressFb" data-default="Address is too long (maximum <?= DT_MAX_ADDRESS ?> characters).">Address is too long (maximum <?= DT_MAX_ADDRESS ?> characters).</div>
              </div>
              <div class="quick-actions">
                <button type="button" class="btn btn-success btn-sm btn-quick-save" id="btnQcqSave"><i class="fas fa-check mr-1" aria-hidden="true"></i> Save</button>
                <button type="button" class="btn btn-secondary btn-sm" id="btnQcqCancel">Cancel</button>
              </div>
            </div>
            <div class="form-group">
              <label for="quValid">Valid Until</label>
              <input type="date" class="form-control" id="quValid" aria-describedby="quValidHelp quValidFb">
              <div class="helper-line" id="quValidHelp">Optional. Not before the quotation date.</div>
              <div class="invalid-feedback" id="quValidFb">Valid until must be a valid date, not before the quotation date.</div>
            </div>
            <div class="form-group">
              <label for="quRef">Reference</label>
              <input type="text" class="form-control" id="quRef" maxlength="<?= DT_MAX_REFERENCE ?>" aria-describedby="quRefHelp quRefFb">
              <div class="helper-line" id="quRefHelp">Customer request or reference number.</div>
              <div class="invalid-feedback" id="quRefFb">Reference is too long.</div>
            </div>
            <div class="form-group">
              <label for="quStatus">Status <span class="req">*</span></label>
              <select class="form-control" id="quStatus" required aria-describedby="quStatusFb">
<?php foreach (DT_QUOTATION_STATUSES as $st): ?>
                <option value="<?= dt_e($st) ?>"><?= dt_e($st) ?></option>
<?php endforeach; ?>
              </select>
              <div class="invalid-feedback" id="quStatusFb">Select a valid status.</div>
            </div>
            <div class="form-group">
              <label for="quNote">Note</label>
              <input type="text" class="form-control" id="quNote" maxlength="<?= DT_MAX_NOTE ?>" aria-describedby="quNoteFb">
              <div class="invalid-feedback" id="quNoteFb">Note is too long.</div>
            </div>
          </div>

          <div class="lines-head">
            <h6 class="lines-title" id="quLinesHeading">Lines</h6>
            <button type="button" class="btn btn-primary btn-sm" id="btnAddQuoteLine"><i class="fas fa-plus mr-1" aria-hidden="true"></i> Add line</button>
          </div>
          <div class="table-scroll">
            <table class="table table-bordered lines-table" aria-labelledby="quLinesHeading">
              <thead>
                <tr>
                  <th>Livestock</th>
                  <th class="w-qty">Quantity</th>
                  <th class="w-price">Selling Price ($)</th>
                  <th class="w-total text-right">Line Total</th>
                  <th class="w-rm text-center"><span class="sr-only">Remove</span></th>
                </tr>
              </thead>
              <tbody id="quLinesBody"></tbody>
            </table>
          </div>
          <div class="invalid-feedback d-block" id="quLinesFb" role="alert" hidden>Add at least one line.</div>
        </div>
        <div class="modal-footer">
          <div class="totals-box" aria-live="polite">
            <div>Grand total: <strong id="quGrandTotal">$0.00</strong></div>
          </div>
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveQuotation"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

function dt_modal_quotation_old(): void
{
    ?>
  <div class="modal fade" id="quotationModal" tabindex="-1" role="dialog" aria-labelledby="quotationModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable" role="document">
      <form class="modal-content" id="quotationForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="quotationModalTitle">Make Quotation</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-grid">
            <div class="form-group">
              <label for="quCustomer">Customer <span class="req">*</span></label>
              <select class="form-control" id="quCustomer" required aria-describedby="quCustomerFb">
                <option value="">Select customer</option>
              </select>
              <div class="invalid-feedback" id="quCustomerFb">Customer is required.</div>
              <div class="helper-line cust-pos" id="quCustPos" aria-live="polite" hidden></div>
              <div class="mt-2" id="quWalkinWrap" hidden>
                <label for="quWalkinName">Walk-in name (optional)</label>
                <input type="text" class="form-control" id="quWalkinName" maxlength="<?= DT_MAX_WALKIN ?>" aria-describedby="quWalkinNameFb">
                <div class="invalid-feedback" id="quWalkinNameFb">Walk-in name is too long (maximum <?= DT_MAX_WALKIN ?> characters).</div>
              </div>
            </div>
            <div class="form-group">
              <label for="quDate">Quotation Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="quDate" required aria-describedby="quDateFb">
              <div class="invalid-feedback" id="quDateFb">Quotation date must be a valid date.</div>
            </div>
            <div class="form-group">
              <label for="quValid">Valid Until</label>
              <input type="date" class="form-control" id="quValid" aria-describedby="quValidHelp quValidFb">
              <div class="helper-line" id="quValidHelp">Optional. Not before the quotation date.</div>
              <div class="invalid-feedback" id="quValidFb">Valid until must be a valid date, not before the quotation date.</div>
            </div>
            <div class="form-group">
              <label for="quRef">Reference</label>
              <input type="text" class="form-control" id="quRef" maxlength="<?= DT_MAX_REFERENCE ?>" aria-describedby="quRefHelp quRefFb">
              <div class="helper-line" id="quRefHelp">Customer request or reference number.</div>
              <div class="invalid-feedback" id="quRefFb">Reference is too long.</div>
            </div>
            <div class="form-group">
              <label for="quStatus">Status <span class="req">*</span></label>
              <select class="form-control" id="quStatus" required aria-describedby="quStatusFb">
<?php foreach (DT_QUOTATION_STATUSES as $st): ?>
                <option value="<?= dt_e($st) ?>"><?= dt_e($st) ?></option>
<?php endforeach; ?>
              </select>
              <div class="invalid-feedback" id="quStatusFb">Select a valid status.</div>
            </div>
            <div class="form-group">
              <label for="quNote">Note</label>
              <input type="text" class="form-control" id="quNote" maxlength="<?= DT_MAX_NOTE ?>" aria-describedby="quNoteFb">
              <div class="invalid-feedback" id="quNoteFb">Note is too long.</div>
            </div>
          </div>

          <div class="lines-head">
            <h6 class="lines-title" id="quLinesHeading">Lines</h6>
            <button type="button" class="btn btn-primary btn-sm" id="btnAddQuoteLine"><i class="fas fa-plus mr-1" aria-hidden="true"></i> Add line</button>
          </div>
          <div class="table-scroll">
            <table class="table table-bordered lines-table" aria-labelledby="quLinesHeading">
              <thead>
                <tr>
                  <th>Livestock</th>
                  <th class="w-qty">Quantity</th>
                  <th class="w-price">Selling Price ($)</th>
                  <th class="w-total text-right">Line Total</th>
                  <th class="w-rm text-center"><span class="sr-only">Remove</span></th>
                </tr>
              </thead>
              <tbody id="quLinesBody"></tbody>
            </table>
          </div>
          <div class="invalid-feedback d-block" id="quLinesFb" role="alert" hidden>Add at least one line.</div>
        </div>
        <div class="modal-footer">
          <div class="totals-box" aria-live="polite">
            <div>Grand total: <strong id="quGrandTotal">$0.00</strong></div>
          </div>
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveQuotation"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Sales list modal: every invoice with returned, refunded, paid and balance; opens the invoice, return and refund modals.
 *
 * @return void
 */
function dt_modal_sales(): void
{
    ?>
  <div class="modal fade" id="salesModal" tabindex="-1" role="dialog" aria-labelledby="salesModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="salesModalTitle">Sales</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <table id="salesTable" class="table table-hover table-bordered">
            <thead>
              <tr>
                <th class="px60">#</th>
                <th class="px120">Date</th>
                <th>Customer</th>
                <th class="px120">Reference</th>
                <th class="px120">Total</th>
                <th class="px120">Returned</th>
                <th class="px120">Refunded</th>
                <th class="px120">Paid</th>
                <th class="px140">Balance</th>
                <th class="px220 text-center">Action</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
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
 * Invoice modal: printable invoice, export buttons, history of returns and refunds, Make Return, Make Refund, Delete Invoice.
 *
 * @return void
 */
function dt_modal_invoice(): void
{
    ?>
  <div class="modal fade" id="invoiceModal" tabindex="-1" role="dialog" aria-labelledby="invoiceModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="invoiceModalTitle">Invoice</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="invoice-sheet" id="invoiceSheet">
            <img class="inv-watermark" id="invWatermark" alt="" aria-hidden="true" hidden>
            <div class="inv-watermark inv-watermark-text" id="invWatermarkText" aria-hidden="true" hidden></div>
            <div class="inv-head">
              <div class="inv-brand">
                <img id="invLogo" alt="Bushra Farming and Livestock logo" hidden>
                <div class="inv-farm" id="invFarm"></div>
              </div>
              <div class="inv-title">
                <h2>INVOICE #<span id="invNo"></span></h2>
                <div>Date: <strong id="invDate"></strong></div>
              </div>
            </div>

            <div class="inv-meta">
              <div><span class="lbl">Customer</span><br><strong id="invCustomer"></strong></div>
              <div><span class="lbl">Reference</span><br><span id="invRef"></span></div>
              <div><span class="lbl">Contact</span><br><span id="invContact"></span></div>
              <div><span class="lbl">Address</span><br><span id="invAddress"></span></div>
              <div><span class="lbl">Note</span><br><span id="invNote"></span></div>
            </div>

            <div class="table-scroll">
              <table id="invLinesTable" class="table table-bordered inv-lines" aria-label="Invoice lines">
                <thead>
                  <tr>
                    <th class="px60">#</th>
                    <th>Livestock</th>
                    <th class="px140">Type</th>
                    <th class="px120 text-right">Quantity</th>
                    <th class="px140 text-right">Selling Price</th>
                    <th class="px140 text-right">Line total</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>

            <div class="inv-totals" aria-live="polite">
              <div class="inv-row"><span>Total</span><span id="invTotal"></span></div>
              <div class="inv-row"><span>Returned (value of returns)</span><span id="invReturned"></span></div>
              <div class="inv-row"><span>Net total</span><strong id="invNet"></strong></div>
              <div class="inv-row"><span>Paid</span><span id="invPaid"></span></div>
              <div class="inv-row"><span>Refunded</span><span id="invRefunded"></span></div>
              <div class="inv-row grand"><span id="invBalLabel">Balance due</span><span><strong id="invBalance"></strong> <span class="badge" id="invStatus"></span></span></div>
            </div>

            <h6 class="lines-title mt-3" id="invHistoryHeading">Returns and refunds</h6>
            <ul class="inv-history" id="invHistory" aria-labelledby="invHistoryHeading"></ul>
          </div>
          <p class="helper-line no-print mt-2" id="invActionHelp" aria-live="polite"></p>
        </div>
        <div class="modal-footer">
          <div class="inv-btns" id="invBtns"></div>
          <button type="button" class="btn btn-secondary btn-sm" id="btnInvEdit"><i class="fas fa-edit mr-1" aria-hidden="true"></i> Edit</button>
          <button type="button" class="btn btn-primary btn-sm" id="btnInvReturn"><i class="fas fa-undo mr-1" aria-hidden="true"></i> Make Return</button>
          <button type="button" class="btn btn-success btn-sm" id="btnInvRefund"><i class="fas fa-hand-holding-usd mr-1" aria-hidden="true"></i> Make Refund</button>
          <button type="button" class="btn btn-danger btn-sm" id="btnInvDelete"><i class="fas fa-trash mr-1" aria-hidden="true"></i> Delete Invoice</button>
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
<?php
}

/**
 * Make return modal (one row per livestock line of the invoice; ids start with "re").
 *
 * @return void
 */
function dt_modal_return(): void
{
    ?>
  <div class="modal fade" id="returnModal" tabindex="-1" role="dialog" aria-labelledby="returnModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable" role="document">
      <form class="modal-content" id="returnForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="returnModalTitle">Make Return</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <p class="helper-line mb-3" id="reInfo" aria-live="polite"></p>
          <div class="form-grid">
            <div class="form-group">
              <label for="reDate">Return Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="reDate" required aria-describedby="reDateHelp reDateFb">
              <div class="helper-line" id="reDateHelp">Not before the invoice date.</div>
              <div class="invalid-feedback" id="reDateFb">Return date must be a valid date, not before the invoice date.</div>
            </div>
            <div class="form-group">
              <label for="reNote">Note</label>
              <input type="text" class="form-control" id="reNote" maxlength="<?= DT_MAX_NOTE ?>" aria-describedby="reNoteFb">
              <div class="invalid-feedback" id="reNoteFb">Note is too long.</div>
            </div>
          </div>

          <div class="lines-head">
            <h6 class="lines-title" id="reLinesHeading">Animals to return</h6>
          </div>
          <div class="table-scroll">
            <table class="table table-bordered lines-table returns-table" aria-labelledby="reLinesHeading">
              <thead>
                <tr>
                  <th>Livestock</th>
                  <th class="text-right">Sold</th>
                  <th class="text-right">Already returned</th>
                  <th class="text-right">Returnable</th>
                  <th class="w-qty">Return quantity</th>
                  <th class="w-price text-right">Selling Price</th>
                  <th class="w-total text-right">Line total</th>
                </tr>
              </thead>
              <tbody id="reLinesBody"></tbody>
            </table>
          </div>
          <div class="invalid-feedback d-block" id="reLinesFb" role="alert" hidden>Enter a quantity to return on at least one line.</div>
        </div>
        <div class="modal-footer">
          <div class="totals-box" aria-live="polite">
            <div>Return total: <strong id="reFootReturn">$0.00</strong></div>
            <div>New net total: <strong id="reFootNet">$0.00</strong></div>
          </div>
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveReturn"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Make refund modal (ids start with "rf").
 *
 * @return void
 */
function dt_modal_refund(): void
{
    ?>
  <div class="modal fade" id="refundModal" tabindex="-1" role="dialog" aria-labelledby="refundModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable" role="document">
      <form class="modal-content" id="refundForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="refundModalTitle">Make Refund</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <p class="helper-line mb-3" id="rfInfo" aria-live="polite"></p>
          <div class="form-grid">
            <div class="form-group">
              <label for="rfDate">Refund Date <span class="req">*</span></label>
              <input type="date" class="form-control" id="rfDate" required aria-describedby="rfDateHelp rfDateFb">
              <div class="helper-line" id="rfDateHelp">Not before the invoice date.</div>
              <div class="invalid-feedback" id="rfDateFb">Refund date must be a valid date, not before the invoice date.</div>
            </div>
            <div class="form-group">
              <label for="rfReturn">Return</label>
              <select class="form-control" id="rfReturn" aria-describedby="rfReturnFb">
                <option value="0">No return</option>
              </select>
              <div class="invalid-feedback" id="rfReturnFb">The selected return does not belong to this invoice.</div>
            </div>
            <div class="form-group">
              <label for="rfAmount">Amount ($) <span class="req">*</span></label>
              <input type="number" class="form-control" id="rfAmount" min="0.01" max="999999999.99" step="0.01" inputmode="decimal" required aria-describedby="rfAmountHelp rfAmountFb">
              <div class="helper-line" id="rfAmountHelp" aria-live="polite"></div>
              <div class="invalid-feedback" id="rfAmountFb">Amount must be greater than 0, with up to 2 decimals.</div>
            </div>
            <div class="form-group">
              <label for="rfAccount">Paid From Account <span class="req">*</span></label>
              <select class="form-control" id="rfAccount" required aria-describedby="rfAccountHelp rfAccountFb">
                <option value="">Select account</option>
              </select>
              <div class="helper-line" id="rfAccountHelp" aria-live="polite"></div>
              <div class="invalid-feedback" id="rfAccountFb">Select the account the refund is paid from.</div>
            </div>
            <div class="form-group">
              <label for="rfNote">Note</label>
              <input type="text" class="form-control" id="rfNote" maxlength="<?= DT_MAX_NOTE ?>" aria-describedby="rfNoteFb">
              <div class="invalid-feedback" id="rfNoteFb">Note is too long.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <div class="totals-box" aria-live="polite">
            <div>Refund: <strong id="rfFootAmount">$0.00</strong></div>
            <div>Left to refund after this: <strong id="rfFootLeft">$0.00</strong></div>
          </div>
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveRefund"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Delete invoice confirmation modal (ids start with "id").
 *
 * @return void
 */
function dt_modal_invoicedelete(): void
{
    ?>
  <div class="modal fade" id="invDeleteModal" tabindex="-1" role="dialog" aria-labelledby="invDeleteModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="invDeleteModalTitle">Delete Invoice</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <p>Delete invoice <b id="idInvNo"></b> of <b id="idInvCustomer"></b>? This cannot be undone.</p>
          <p class="helper-line mt-2">The animals go back into stock. Its returns, refunds and automatic receipt are removed.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-danger btn-sm" id="btnConfirmInvDelete"><i class="fas fa-trash mr-1" aria-hidden="true"></i> Delete</button>
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </div>
    </div>
  </div>
<?php
}

/**
 * Delete livestock modal.
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
          <h5 class="modal-title" id="deleteModalTitle">Delete Livestock</h5>
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
 * 6b. NEW MODALS: quotations list / view / delete, purchases list / view / delete
 * ============================================================== */

/**
 * Quotations list modal (ids start with "quotations").
 *
 * @return void
 */
function dt_modal_quotations(): void
{
    ?>
  <div class="modal fade" id="quotationsModal" tabindex="-1" role="dialog" aria-labelledby="quotationsModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="quotationsModalTitle">Quotations</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <table id="quotationsTable" class="table table-hover table-bordered">
            <thead>
              <tr>
                <th class="px60">#</th>
                <th class="px120">Date</th>
                <th>Customer</th>
                <th class="px120">Reference</th>
                <th class="px120">Valid until</th>
                <th class="px120">Total</th>
                <th class="px120">Stage</th>
                <th class="px220 text-center">Action</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
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
 * Quotation view modal: printable on A4 like the invoice (ids start with "qv").
 *
 * @return void
 */
function dt_modal_quotationview(): void
{
    ?>
  <div class="modal fade" id="quotationViewModal" tabindex="-1" role="dialog" aria-labelledby="quotationViewTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="quotationViewTitle">Quotation</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="invoice-sheet" id="qvSheet">
            <img class="inv-watermark" id="qvWatermark" alt="" aria-hidden="true" hidden>
            <div class="inv-watermark inv-watermark-text" id="qvWatermarkText" aria-hidden="true" hidden></div>
            <div class="inv-head">
              <div class="inv-brand">
                <img id="qvLogo" alt="Bushra Farming and Livestock logo" hidden>
                <div class="inv-farm" id="qvFarm"></div>
              </div>
              <div class="inv-title">
                <h2>QUOTATION #<span id="qvNo"></span></h2>
                <div>Date: <strong id="qvDate"></strong></div>
              </div>
            </div>

            <div class="inv-meta">
              <div><span class="lbl">Customer</span><br><strong id="qvCustomer"></strong></div>
              <div><span class="lbl">Reference</span><br><span id="qvRef"></span></div>
              <div><span class="lbl">Contact</span><br><span id="qvContact"></span></div>
              <div><span class="lbl">Address</span><br><span id="qvAddress"></span></div>
              <div><span class="lbl">Valid until</span><br><strong id="qvValid"></strong></div>
              <div><span class="lbl">Status</span><br><span class="badge" id="qvStatus"></span></div>
              <div><span class="lbl">Note</span><br><span id="qvNote"></span></div>
            </div>

            <div class="table-scroll">
              <table id="qvLinesTable" class="table table-bordered inv-lines" aria-label="Quotation lines">
                <thead>
                  <tr>
                    <th class="px60">#</th>
                    <th>Livestock</th>
                    <th class="px140">Type</th>
                    <th class="px120 text-right">Quantity</th>
                    <th class="px140 text-right">Selling Price</th>
                    <th class="px140 text-right">Line total</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>

            <div class="inv-totals" aria-live="polite">
              <div class="inv-row grand"><span>Total</span><strong id="qvTotal"></strong></div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <div class="inv-btns" id="qvBtns"></div>
          <button type="button" class="btn btn-secondary btn-sm" id="btnQvEdit"><i class="fas fa-edit mr-1" aria-hidden="true"></i> Edit</button>
          <button type="button" class="btn btn-danger btn-sm" id="btnQvDelete"><i class="fas fa-trash mr-1" aria-hidden="true"></i> Delete</button>
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
<?php
}

/**
 * Delete quotation confirmation modal (ids start with "qd").
 *
 * @return void
 */
function dt_modal_quotationdelete(): void
{
    ?>
  <div class="modal fade" id="quotationDeleteModal" tabindex="-1" role="dialog" aria-labelledby="quotationDeleteTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="quotationDeleteTitle">Delete Quotation</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <p>Delete quotation <b id="qdNo"></b> of <b id="qdCustomer"></b>? This cannot be undone.</p>
          <p class="helper-line mt-2">A quotation never changes stock.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-danger btn-sm" id="btnConfirmQDelete"><i class="fas fa-trash mr-1" aria-hidden="true"></i> Delete</button>
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </div>
    </div>
  </div>
<?php
}

/**
 * Purchases list modal (ids start with "purchases").
 *
 * @return void
 */
function dt_modal_purchases(): void
{
    ?>
  <div class="modal fade" id="purchasesModal" tabindex="-1" role="dialog" aria-labelledby="purchasesModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="purchasesModalTitle">Purchases</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <table id="purchasesTable" class="table table-hover table-bordered">
            <thead>
              <tr>
                <th class="px60">#</th>
                <th class="px120">Date</th>
                <th>Supplier</th>
                <th class="px120">Reference</th>
                <th class="px120">Total</th>
                <th class="px120">Paid</th>
                <th class="px140">Balance due</th>
                <th class="px120">Stage</th>
                <th class="px220 text-center">Action</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
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
 * Purchase view modal: printable on A4 like the invoice (ids start with "pv").
 *
 * @return void
 */
function dt_modal_purchaseview(): void
{
    ?>
  <div class="modal fade" id="purchaseViewModal" tabindex="-1" role="dialog" aria-labelledby="purchaseViewTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="purchaseViewTitle">Purchase</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="invoice-sheet" id="pvSheet">
            <img class="inv-watermark" id="pvWatermark" alt="" aria-hidden="true" hidden>
            <div class="inv-watermark inv-watermark-text" id="pvWatermarkText" aria-hidden="true" hidden></div>
            <div class="inv-head">
              <div class="inv-brand">
                <img id="pvLogo" alt="Bushra Farming and Livestock logo" hidden>
                <div class="inv-farm" id="pvFarm"></div>
              </div>
              <div class="inv-title">
                <h2>PURCHASE #<span id="pvNo"></span></h2>
                <div>Date: <strong id="pvDate"></strong></div>
              </div>
            </div>

            <div class="inv-meta">
              <div><span class="lbl">Supplier</span><br><strong id="pvSupplier"></strong></div>
              <div><span class="lbl">Reference</span><br><span id="pvRef"></span></div>
              <div><span class="lbl">Paid from account</span><br><span id="pvAccount"></span></div>
              <div><span class="lbl">Note</span><br><span id="pvNote"></span></div>
            </div>

            <div class="table-scroll">
              <table id="pvLinesTable" class="table table-bordered inv-lines" aria-label="Purchase lines">
                <thead>
                  <tr>
                    <th class="px60">#</th>
                    <th>Item</th>
                    <th class="px140">Type</th>
                    <th class="px120 text-right">Quantity</th>
                    <th class="px140 text-right">Selling Price</th>
                    <th class="px140 text-right">Line total</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>

            <div class="inv-totals" aria-live="polite">
              <div class="inv-row"><span>Purchase Cost</span><span id="pvTotal"></span></div>
              <div class="inv-row"><span>Paid</span><span id="pvPaid"></span></div>
              <div class="inv-row grand"><span>Balance due</span><span><strong id="pvBalance"></strong> <span class="badge" id="pvStatus"></span></span></div>
            </div>
          </div>
          <p class="helper-line no-print mt-2" id="pvActionHelp" aria-live="polite"></p>
        </div>
        <div class="modal-footer">
          <div class="inv-btns" id="pvBtns"></div>
          <button type="button" class="btn btn-secondary btn-sm" id="btnPvEdit"><i class="fas fa-edit mr-1" aria-hidden="true"></i> Edit</button>
          <button type="button" class="btn btn-danger btn-sm" id="btnPvDelete"><i class="fas fa-trash mr-1" aria-hidden="true"></i> Delete</button>
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
<?php
}

/**
 * Delete purchase confirmation modal (ids start with "pd").
 *
 * @return void
 */
function dt_modal_purchasedelete(): void
{
    ?>
  <div class="modal fade" id="purchaseDeleteModal" tabindex="-1" role="dialog" aria-labelledby="purchaseDeleteTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="purchaseDeleteTitle">Delete Purchase</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <p>Delete purchase <b id="pdNo"></b> from <b id="pdSupplier"></b>? This cannot be undone.</p>
          <p class="helper-line mt-2">The animals it added come out of stock. Its automatic payment is removed.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-danger btn-sm" id="btnConfirmPDelete"><i class="fas fa-trash mr-1" aria-hidden="true"></i> Delete</button>
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
 * Sidebar: key, label, icon, page, submenu links (label, page). Same menu as pages/dashboard.php, without the Sales group
 * (sales and quotations are made from the Livestock page).
 * ALL pages live flat in pages/ (no people/, accounting/ or reports/ sub-folders), so every link is just a file name.
 * Single pages (Purchases, Products, Livestock, Settings, Users, Activities) stay directly in pages/.
 *
 * @var list<array{0:string,1:string,2:string,3:string,4:list<array{0:string,1:string}>}> $navigation
 */
$navigation = [
    ['dashboard',  'Dashboard',  'home',       'dashboard.php',  []],
    ['livestock',  'Livestock',  'livestock',  'livestock.php',  []],
    ['products',   'Products',   'products',   'products.php',   []],
    ['purchases',  'Purchases',  'purchases',  'purchases.php',  []],
    ['people',     'People',     'people',     'customers.php', [
        ['Customers',    'customers.php'],
        ['Suppliers',    'suppliers.php'],
        ['Shareholders', 'shareholders.php'],
        ['Employees',    'employees.php'],
    ]],
    ['accounting', 'Accounting', 'accounting', 'chartofaccounts.php', [
        ['Chart of Accounts', 'chartofaccounts.php'],
        ['Receipts',          'receipts.php'],
        ['Payments',          'payments.php'],
        ['Expenses',          'expenses.php'],
        ['Account Transfer',  'accounttransfer.php'],
        ["Owner's Equity",    'equity.php'],
        ['Payrolls',          'payrolls.php'],
        ['Assets',            'assets.php'],
    ]],
    ['reports',    'Reports',    'reports',    'salesreport.php', [
        ['Sales Report',      'salesreport.php'],
        ['Purchases Report',  'purchasesreport.php'],
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
    'csrfField' => $csrfField, 'docTitle' => 'Livestock', 'heading' => 'Livestock',
    'sub' => 'Manage the livestock of the farm.',
];

/* ---------- Render ---------- */

dt_render_open($page);
dt_view_list();
dt_render_close();
dt_modal_livestock();
dt_modal_purchase();
dt_modal_sale();
dt_modal_quotation();
dt_modal_sales();
dt_modal_invoice();
dt_modal_return();
dt_modal_refund();
dt_modal_invoicedelete();
dt_modal_delete();
dt_modal_quotations();
dt_modal_quotationview();
dt_modal_quotationdelete();
dt_modal_purchases();
dt_modal_purchaseview();
dt_modal_purchasedelete();

$cfg       = ['csrf' => $csrf, 'self' => 'livestock.php', 'maxStock' => DT_MAX_STOCK, 'maxMoney' => 9999999.99, 'maxLines' => DT_MAX_LINES, 'farm' => $agencyName, 'logo' => $logoUrl];
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

    // Money display: 1,234.50
    function money(v) {
      return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Valid money text: up to 7 digits, up to 2 decimals
    function validMoney(v) {
      return /^\d{1,7}(\.\d{1,2})?$/.test(v) && Number(v) <= CFG.maxMoney;
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
    /* ---------- DataTable ---------- */
    var exportCols = { columns: [0, 1, 2, 3, 4, 5, 6] };

    var table = $('#livestockTable').DataTable({
      ajax: {
        url: URL_SELF,
        type: 'POST',
        data: function (d) { d.action = 'list'; d.csrf_token = CSRF; },
        dataSrc: 'data',
        error: function (xhr) { if (window.console) { console.warn('livestock list request failed (HTTP ' + (xhr && xhr.status) + ')'); } }
      },
      columns: [
        { data: 'livestockid' },
        { data: 'name', render: function (d) { return esc(d); } },
        { data: 'type', render: function (d) { return esc(d); } },
        {
          data: 'currentstock', className: 'text-right',
          render: function (d, type) { return type === 'display' ? Number(d).toLocaleString('en-US') : d; }
        },
        {
          data: 'costeach', className: 'text-right',
          render: function (d, type) { return type === 'display' || type === 'filter' ? money(d) : d; }
        },
        {
          data: 'sellingprice', className: 'text-right',
          render: function (d, type) { return type === 'display' || type === 'filter' ? money(d) : d; }
        },
        {
          data: 'stage',
          render: function (d, type) {
            if (type !== 'display') { return d; }
            return '<span class="badge ' + (String(d).toLowerCase() === 'active' ? 'st-active' : 'st-inactive') + '">' + esc(d) + '</span>';
          }
        },
        {
          data: null, orderable: false, searchable: false, className: 'text-center text-nowrap',
          render: function () {
            return '<button type="button" class="btn btn-primary act-btn btn-edit" title="Edit" aria-label="Edit livestock"><i class="fas fa-edit" aria-hidden="true"></i></button> ' +
                   '<button type="button" class="btn btn-danger act-btn btn-del" title="Delete" aria-label="Delete livestock"><i class="fas fa-trash" aria-hidden="true"></i></button>';
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
        { extend: 'excelHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-excel"></i> Excel', title: 'Livestock', exportOptions: exportCols },
        { extend: 'pdfHtml5',   className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-pdf"></i> PDF',     title: 'Livestock', exportOptions: exportCols },
        { extend: 'print',      className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-print"></i> Print',      title: 'Livestock', exportOptions: exportCols }
      ],
      language: { search: 'Search:', searchPlaceholder: 'Search livestock...', emptyTable: 'No livestock found' }
    });

    function rowData(el) { return table.row($(el).closest('tr')).data(); }

    /* ---------- Add / Edit modal ---------- */
    var NAME_REQUIRED = 'Name is required.';
    var TYPE_REQUIRED = 'Type is required.';
    var STOCK_INVALID = $('#lStockFb').text();
    var COST_INVALID = $('#lCostEachFb').text();
    var PRICE_INVALID = $('#lSellingPriceFb').text();
    var STAGE_REQUIRED = 'Stage is required.';

    function clearInvalid() {
      $('#lName, #lType, #lStock, #lCostEach, #lSellingPrice, #lStage').removeClass('is-invalid');
      $('#lNameFb').text(NAME_REQUIRED);
      $('#lTypeFb').text(TYPE_REQUIRED);
      $('#lStockFb').text(STOCK_INVALID);
      $('#lCostEachFb').text(COST_INVALID);
      $('#lSellingPriceFb').text(PRICE_INVALID);
      $('#lStageFb').text(STAGE_REQUIRED);
    }

    function openLivestockModal(d) {
      $('#livestockForm')[0].reset();
      clearInvalid();
      $('#lId').val(d ? d.livestockid : 0);
      $('#lName').val(d ? d.name : '');
      $('#lType').val(d ? d.type : '');
      $('#lStock').val(d ? d.currentstock : 0);
      $('#lCostEach').val(d ? Number(d.costeach).toFixed(2) : '0.00');
      $('#lSellingPrice').val(d ? Number(d.sellingprice).toFixed(2) : '0.00');
      $('#lStage').val(d ? d.stage : 'Active');
      $('#livestockModalTitle').text(d ? 'Edit Livestock' : 'Add Livestock');
      $('#livestockModal').modal('show');
    }

    $('#btnAdd').on('click', function () { openLivestockModal(null); });
    $('#livestockTable tbody').on('click', '.btn-edit', function () { openLivestockModal(rowData(this)); });
    $('#livestockModal').on('shown.bs.modal', function () { $('#lName').trigger('focus'); });
    $('#lName, #lType, #lStock, #lCostEach, #lSellingPrice, #lStage').on('input', function () { $(this).removeClass('is-invalid'); });

    /* ---------- Save (add / edit) ---------- */
    $('#livestockForm').on('submit', function (e) {
      e.preventDefault();
      clearInvalid();

      var name   = $.trim($('#lName').val());
      var type   = $.trim($('#lType').val());
      var stock  = $.trim($('#lStock').val());
      var cost   = $.trim($('#lCostEach').val());
      var price  = $.trim($('#lSellingPrice').val());
      var stage = $.trim($('#lStage').val());
      if (name === '') {
        $('#lName').addClass('is-invalid').trigger('focus');
        return;
      }
      if (type === '') {
        $('#lType').addClass('is-invalid').trigger('focus');
        return;
      }
      if (!/^\d+$/.test(stock) || Number(stock) > CFG.maxStock) {
        $('#lStock').addClass('is-invalid').trigger('focus');
        return;
      }
      if (!validMoney(cost)) {
        $('#lCostEach').addClass('is-invalid').trigger('focus');
        return;
      }
      if (!validMoney(price)) {
        $('#lSellingPrice').addClass('is-invalid').trigger('focus');
        return;
      }
      if (stage === '') {
        $('#lStage').addClass('is-invalid').trigger('focus');
        return;
      }

      var $btn = $('#btnSaveLivestock').prop('disabled', true);
      api({
        action: 'save',
        livestockid: $('#lId').val(),
        name: name,
        type: type,
        currentstock: stock,
        costeach: cost,
        sellingprice: price,
        stage: stage
      }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#livestockModal').modal('hide');
          table.ajax.reload(null, false);
          return;
        }
        if (res.field === 'name') {
          $('#lNameFb').text(res.message);
          $('#lName').addClass('is-invalid').trigger('focus');
        } else if (res.field === 'type') {
          $('#lTypeFb').text(res.message);
          $('#lType').addClass('is-invalid').trigger('focus');
        } else if (res.field === 'currentstock') {
          $('#lStockFb').text(res.message);
          $('#lStock').addClass('is-invalid').trigger('focus');
        } else if (res.field === 'costeach') {
          $('#lCostEachFb').text(res.message);
          $('#lCostEach').addClass('is-invalid').trigger('focus');
        } else if (res.field === 'sellingprice') {
          $('#lSellingPriceFb').text(res.message);
          $('#lSellingPrice').addClass('is-invalid').trigger('focus');
        } else if (res.field === 'stage') {
          $('#lStageFb').text(res.message);
          $('#lStage').addClass('is-invalid').trigger('focus');
        }
        toastr.error(res.message);
      }).always(function () { $btn.prop('disabled', false); });
    });

    /* ---------- Delete ---------- */
    var deleteId = 0;

    $('#livestockTable tbody').on('click', '.btn-del', function () {
      var d = rowData(this);
      deleteId = d.livestockid;
      $('#delName').text(d.name);
      $('#deleteModal').modal('show');
    });

    $('#btnConfirmDelete').on('click', function () {
      var $btn = $(this).prop('disabled', true);
      api({ action: 'delete', livestockid: deleteId }).done(function (res) {
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
     * Purchase Livestock (exact money math with BigInt, no floats)
     * ============================================================== */
    var RE_QTY    = /^\d{1,6}$/;
    var RE_PRICE  = /^\d{1,7}(\.\d{1,2})?$/;
    var RE_PAID   = /^\d{1,9}(\.\d{1,2})?$/;
    var MAX_TOTAL = 99999999999n;

    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(date) { return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate()); }
    function fmtInt(n) { return Number(n).toLocaleString('en-US'); }

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

    var PL = { suppliers: [], livestock: [], accounts: [] };
    var PLIVE = {};
    var PACC  = {};
    var puSaving = false;
    var puOpening = false;
    var puBalanceFlagged = false;
    var puBalanceBlocked = false;

    function indexPurchaseLookups() {
      PLIVE = {};
      PACC  = {};
      $.each(PL.livestock || [], function (i, r) { PLIVE[String(r.livestockid)] = r; });
      $.each(PL.accounts || [], function (i, r) {
        PACC[String(r.accountid)] = { balance: toMinorSigned(r.balance), label: r.code + ' ' + r.accountname };
      });
    }

    /* ---------- Field map (server field keys) ---------- */
    var puSellingTouched = false, puCostFlagged = false, puCostBlocked = false;
    var MAX_PRICE_MINOR = 999999999n; // 9,999,999.99

    // Cost Each = Purchase Cost / Quantity, rounded half up (BigInt minor units; the one JavaScript helper)
    function costEachMinor(costMinor, qty) { return (2n * costMinor + qty) / (2n * qty); }

    // Selling Price of a livestock item, read from the Livestock table (used as the default in the Purchase form)
    function lvSellingOf(id) {
      var v = '0';
      table.rows().every(function () {
        var d = this.data();
        if (Number(d.livestockid) === Number(id)) { v = Number(d.sellingprice).toFixed(2); }
      });
      return v;
    }

    var PU_FIELDS = {
      supplierid:        ['#puSupplier',     '#puSupplierFb'],
      purchasedate:      ['#puDate',         '#puDateFb'],
      reference:         ['#puRef',          '#puRefFb'],
      livestockid:       ['#puLivestock',    '#puLivestockFb'],
      quantity:          ['#puQty',          '#puQtyFb'],
      purchasecost:      ['#puPurchaseCost', '#puPurchaseCostFb'],
      sellingprice:      ['#puSellingPrice', '#puSellingPriceFb'],
      paidamount:        ['#puPaid',         '#puPaidFb'],
      paidfromaccountid: ['#puAccount',      '#puAccountFb'],
      note:              ['#puNote',         '#puNoteFb']
    };
    var PU_FIELDS_OLD = {
      supplierid:        ['#puSupplier',  '#puSupplierFb'],
      purchasedate:      ['#puDate',      '#puDateFb'],
      reference:         ['#puRef',       '#puRefFb'],
      livestockid:       ['#puLivestock', '#puLivestockFb'],
      quantity:          ['#puQty',       '#puQtyFb'],
      unitprice:         ['#puPrice',     '#puPriceFb'],
      paidamount:        ['#puPaid',      '#puPaidFb'],
      paidfromaccountid: ['#puAccount',   '#puAccountFb'],
      note:              ['#puNote',      '#puNoteFb']
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
      var qty   = $.trim($('#puQty').val());
      var cost  = $.trim($('#puPurchaseCost').val());
      var qOk   = RE_QTY.test(qty) && /[1-9]/.test(qty);
      var cOk   = RE_PAID.test(cost);
      var costM = cOk ? toMinor(cost, 2) : 0n;
      var each  = (qOk && cOk) ? costEachMinor(costM, BigInt(qty)) : null;
      puCostBlocked = each !== null && each > MAX_PRICE_MINOR;
      $('#puCostEach').val(each === null || puCostBlocked ? '' : minorMoney(each));

      var paidRaw = $.trim($('#puPaid').val()) || '0';
      var paid    = RE_PAID.test(paidRaw) ? toMinor(paidRaw, 2) : 0n;
      var bal     = costM - paid;

      $('#puFootPurchaseCost').text(minorMoney(costM));
      $('#puFootPaid').text(minorMoney(paid));
      $('#puFootBalance').text(minorMoney(bal < 0n ? 0n : bal));
      $('#puAccountReq').prop('hidden', paid <= 0n);
      $('#puAccount').attr('aria-required', paid > 0n ? 'true' : 'false');

      var a = PLIVE[String($('#puLivestock').val() || '')];
      $('#puLivestockHelp').text(a ? 'On hand: ' + fmtInt(a.currentstock) + ' animals' : '');
      $('#puQtyHelp').text(a && qOk ? 'Stock after purchase: ' + fmtInt(a.currentstock + Number(qty)) + ' animals' : '');

      var avail = puAvailableMinor();
      $('#puAccountHelp').text(avail === null ? '' : 'Available balance: ' + minorMoney(avail));

      if (puCostBlocked) {
        puCostFlagged = true;
        $('#puPurchaseCost').addClass('is-invalid');
        $('#puPurchaseCostFb').text('Cost each is too large (maximum 9,999,999.99). Check the purchase cost and the quantity.');
      } else if (puCostFlagged) {
        puCostFlagged = false;
        $('#puPurchaseCost').removeClass('is-invalid');
        $('#puPurchaseCostFb').text(PU_DEFAULTS.purchasecost);
      }

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
      // Save stays disabled while Cost Each cannot be computed
      $('#btnSavePurchase').prop('disabled', puBalanceBlocked || puCostBlocked || each === null || puSaving);
    }

    function puRecalc_OLD() {
      var qty   = $.trim($('#puQty').val());
      var price = $.trim($('#puPrice').val());
      var qOk   = RE_QTY.test(qty) && /[1-9]/.test(qty);
      var pOk   = RE_PRICE.test(price);
      var total = (qOk && pOk) ? BigInt(qty) * toMinor(price, 2) : 0n;

      var paidRaw = $.trim($('#puPaid').val()) || '0';
      var paid    = RE_PAID.test(paidRaw) ? toMinor(paidRaw, 2) : 0n;
      var bal     = total - paid;

      $('#puTotal').val(minorMoney(total));
      $('#puFootTotal').text(minorMoney(total));
      $('#puFootPaid').text(minorMoney(paid));
      $('#puFootBalance').text(minorMoney(bal < 0n ? 0n : bal));
      $('#puAccountReq').prop('hidden', paid <= 0n);
      $('#puAccount').attr('aria-required', paid > 0n ? 'true' : 'false');

      var a = PLIVE[String($('#puLivestock').val() || '')];
      $('#puLivestockHelp').text(a ? 'On hand: ' + fmtInt(a.currentstock) + ' animals' : '');
      $('#puQtyHelp').text(a && qOk ? 'Stock after purchase: ' + fmtInt(a.currentstock + Number(qty)) + ' animals' : '');

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
    $('#puLivestock').on('change', function () {
      puResetField('livestockid'); puResetField('quantity');
      if (!puSellingTouched && !puEditId) { $('#puSellingPrice').val($(this).val() ? lvSellingOf($(this).val()) : '0'); }
      puRecalc();
    });
    $('#puQty').on('input', function () { puResetField('quantity'); puRecalc(); });
    $('#puPurchaseCost').on('input', function () { puResetField('purchasecost'); puRecalc(); });
    $('#puSellingPrice').on('input', function () { puSellingTouched = true; puResetField('sellingprice'); });
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

      var l = '<option value="">Select livestock</option>';
      $.each(PL.livestock || [], function (i, r) { l += '<option value="' + Number(r.livestockid) + '">' + esc(r.name + ' (' + r.type + ')') + '</option>'; });
      $('#puLivestock').html(l);

      var a = '<option value="">Select account</option>';
      $.each(PL.accounts || [], function (i, r) { a += '<option value="' + Number(r.accountid) + '">' + esc(r.code + ' ' + r.accountname) + '</option>'; });
      $('#puAccount').html(a);

      indexPurchaseLookups();
    }

    function showPurchaseModal(livestockId) {
      $('#purchaseForm')[0].reset();
      puClearInvalid();
      puFillSelects();
      puSaving = false;
      $('#puDate').val(ymd(new Date()));
      $('#puPurchaseCost').val('0'); $('#puSellingPrice').val('0'); puSellingTouched = false;
      $('#puPaid').val('0');
      if (livestockId && PLIVE[String(livestockId)]) { $('#puLivestock').val(String(livestockId)); $('#puSellingPrice').val(lvSellingOf(livestockId)); }
      puRecalc();
      $('#purchaseModal').modal('show');
    }

    // Reload the lookups (fresh account balances and stock) without touching the open form
    function puRefreshLookups() {
      return api({ action: 'purchaselookups' }).done(function (res) {
        if (res.success) { PL = res.data; indexPurchaseLookups(); puRecalc(); }
      });
    }

    function openPurchaseModal(livestockId) {
      if (puOpening) { return; }
      puOpening = true;
      api({ action: 'purchaselookups' }).done(function (res) {
        if (!res.success) { toastr.error(res.message); return; }
        PL = res.data;
        showPurchaseModal(livestockId);
      }).always(function () { puOpening = false; });
    }

    $('#btnPurchase').on('click', function () { openPurchaseModal(0); });
    $('#livestockTable tbody').on('click', '.btn-sale', function () { openSaleModal(rowData(this).livestockid); });

    /* ---------- Client validation (the server repeats every rule) ---------- */
    function puValidate() {
      puClearInvalid();

      var sup = String($('#puSupplier').val() || '');
      if (!sup) { puMark('supplierid', null); return false; }

      var date = $.trim($('#puDate').val());
      if (!realDate(date)) { puMark('purchasedate', null); return false; }

      var lid = String($('#puLivestock').val() || '');
      if (!lid) { puMark('livestockid', null); return false; }

      var qty = $.trim($('#puQty').val());
      if (!(RE_QTY.test(qty) && /[1-9]/.test(qty))) { puMark('quantity', 'Quantity must be a whole number greater than 0.'); return false; }
      var a = PLIVE[lid];
      if (a && a.currentstock + Number(qty) > CFG.maxStock) {
        puMark('quantity', 'Cannot purchase: ' + a.name + ' stock would exceed ' + fmtInt(CFG.maxStock) + '.');
        return false;
      }

      var cost = $.trim($('#puPurchaseCost').val());
      if (!RE_PAID.test(cost)) { puMark('purchasecost', null); return false; }
      var costM = toMinor(cost, 2);
      if (costEachMinor(costM, BigInt(qty)) > MAX_PRICE_MINOR) {
        puCostFlagged = true;
        puMark('purchasecost', 'Cost each is too large (maximum 9,999,999.99). Check the purchase cost and the quantity.');
        return false;
      }

      var selling = $.trim($('#puSellingPrice').val());
      if (!RE_PRICE.test(selling)) { puMark('sellingprice', null); return false; }

      var paidRaw = $.trim($('#puPaid').val()) || '0';
      if (!RE_PAID.test(paidRaw)) { puMark('paidamount', null); return false; }
      var paid = toMinor(paidRaw, 2);
      if (paid > costM) { puMark('paidamount', 'Paid amount cannot be more than the purchase cost.'); return false; }
      if (sup === 'none' && paid !== costM) { puMark('paidamount', 'A purchase without a supplier must be paid in full.'); return false; }
      if (paid > 0n && !$('#puAccount').val()) { puMark('paidfromaccountid', null); return false; }
      var balMsg = puBalanceMessage(paid);
      if (balMsg) { puBalanceFlagged = true; puMark('paidamount', balMsg); return false; }
      return true;
    }

    function puValidate_OLD() {
      puClearInvalid();

      if (!$('#puSupplier').val()) { puMark('supplierid', null); return false; }

      var date = $.trim($('#puDate').val());
      var real = /^\d{4}-\d{2}-\d{2}$/.test(date) && ymd(new Date(date + 'T00:00:00')) === date;
      if (!real) { puMark('purchasedate', null); return false; }

      var lid = String($('#puLivestock').val() || '');
      if (!lid) { puMark('livestockid', null); return false; }

      var qty = $.trim($('#puQty').val());
      if (!(RE_QTY.test(qty) && /[1-9]/.test(qty))) { puMark('quantity', 'Quantity must be a whole number greater than 0.'); return false; }
      var a = PLIVE[lid];
      if (a && a.currentstock + Number(qty) > CFG.maxStock) {
        puMark('quantity', 'Cannot purchase: ' + a.name + ' stock would exceed ' + fmtInt(CFG.maxStock) + '.');
        return false;
      }

      var price = $.trim($('#puPrice').val());
      if (!RE_PRICE.test(price)) { puMark('sellingprice', null); return false; }

      var total = BigInt(qty) * toMinor(price, 2);
      if (total > MAX_TOTAL) { puMark('quantity', 'The purchase total is too large.'); return false; }

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
        livestockid: $('#puLivestock').val(),
        quantity: $.trim($('#puQty').val()),
        purchasecost: $.trim($('#puPurchaseCost').val()),
        sellingprice: $.trim($('#puSellingPrice').val()),
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

<!-- ===================== Quick add helpers + Livestock Add / Edit (Type and Status selects) ===================== -->
<script nonce="<?= $n ?>">
    /* ---------- Quick add helpers: inline panels shared by the Livestock, Purchase, Sale and Quotation modals ---------- */
    function escAttr(s) { return esc(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    function byName(a, b) {
      var x = String(a.name).toLowerCase(), y = String(b.name).toLowerCase();
      return x < y ? -1 : (x > y ? 1 : 0);
    }

    function qpClear(panelSel) {
      var $p = $(panelSel);
      $p.find('.is-invalid').removeClass('is-invalid');
      $p.find('.invalid-feedback').each(function () {
        var d = $(this).attr('data-default');
        if (d) { $(this).text(d); }
      });
    }
    function qpMark(f, message) {
      $(f.fb).text(message);
      $(f.sel).addClass('is-invalid').trigger('focus');
    }
    function qpReset(c) {
      qpClear(c.panel);
      $.each(c.fields, function (i, f) { $(f.sel).val(f.def !== undefined ? f.def : ''); });
    }
    function qpOpen(c) {
      if (c.prepare) { c.prepare(); }
      qpReset(c);
      $(c.ok).text('');
      $(c.panel).prop('hidden', false);
      $(c.btn).attr('aria-expanded', 'true');
      $(c.focus).trigger('focus');
    }
    function qpClose(c) {
      $(c.panel).prop('hidden', true).find('.quick-panel').prop('hidden', true);
      $(c.panel).find('.btn-quick-add').attr('aria-expanded', 'false');
      $(c.btn).attr('aria-expanded', 'false').trigger('focus');
    }
    // Hide every panel of a modal when it opens (called on show.bs.modal)
    function qpResetModal(modalSel) {
      var $m = $(modalSel);
      $m.find('.quick-panel').prop('hidden', true);
      $m.find('.btn-quick-add').attr('aria-expanded', 'false');
      $m.find('.quick-panel input').val('');
      $m.find('.quick-panel .is-invalid').removeClass('is-invalid');
      $m.find('.quick-panel .invalid-feedback').each(function () {
        var d = $(this).attr('data-default');
        if (d) { $(this).text(d); }
      });
      $m.find('.quick-ok').text('');
    }

    function qpSave(c) {
      if (c.busy) { return; }
      qpClear(c.panel);
      var data = { action: c.action }, bad = false;
      $.each(c.fields, function (i, f) {
        var raw = $(f.sel).val();
        var v = $.trim(raw == null ? '' : String(raw));
        var msg = '';
        if (f.req && v === '') { msg = f.req; }
        else if (f.max && v.length > f.max) { msg = f.maxMsg; }
        else if (f.reserved && v.toLowerCase() === String(f.reserved).toLowerCase()) { msg = 'The name "' + f.reserved + '" is reserved. Choose another name.'; }
        else if (f.money && !validMoney(v)) { msg = f.moneyMsg; }
        if (msg) { qpMark(f, msg); bad = true; return false; }
        data[f.key] = v;
      });
      if (bad) { return; }
      c.busy = true;
      $(c.save).prop('disabled', true);
      api(data).done(function (res) {
        if (res.success) { c.done(res.row, res); return; }
        var hit = null;
        $.each(c.fields, function (i, f) { if (f.key === res.field) { hit = f; } });
        if (hit) { qpMark(hit, res.message); }
        toastr.error(res.message);
      }).always(function () {
        c.busy = false;
        $(c.save).prop('disabled', false);
      });
    }

    // c.lookups = true: the saved types and statuses are loaded before the panel opens
    function quickWire(c) {
      $(c.btn).on('click', function () {
        if (!$(c.panel).prop('hidden')) { qpClose(c); return; }
        if (c.lookups) { lslLoad().done(function () { qpOpen(c); }); } else { qpOpen(c); }
      });
      $(c.cancel).on('click', function () { qpClose(c); });
      $(c.save).on('click', function () { qpSave(c); });
    }

    // Enter inside a panel saves the panel (it never submits the form behind it)
    $(document).on('keydown', '.quick-panel input', function (e) {
      if (e.key !== 'Enter') { return; }
      e.preventDefault();
      $(this).closest('.quick-panel').children('.quick-actions').find('.btn-quick-save').trigger('click');
    });
    // Escape closes the innermost open panel first, then (next press) the modal
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') { return; }
      var $open = $('.modal.show .quick-panel').filter(function () { return !this.hidden; });
      if (!$open.length) { return; }
      e.stopPropagation();
      e.preventDefault();
      $('[aria-controls="' + $open.last().attr('id') + '"]').first().trigger('click');
    }, true);

    /* ---------- Saved types and statuses (shared cache) ---------- */
    var LSL = { types: [], stages: [], loaded: false };

    function lslLoad(force) {
      if (LSL.loaded && !force) { return $.Deferred().resolve().promise(); }
      return api({ action: 'livestocklookups' }).done(function (res) {
        if (res.success) { LSL.types = res.data.types; LSL.stages = res.data.stages; LSL.loaded = true; }
        else { toastr.error(res.message); }
      });
    }

    // Rebuild a select from a lookup list and select "wanted" (a legacy value missing from the list is added so nothing is lost)
    function lslFill(sel, list, placeholder, wanted) {
      var h = '<option value="">' + esc(placeholder) + '</option>', found = '';
      $.each(list, function (i, r) {
        h += '<option value="' + escAttr(r.name) + '">' + esc(r.name) + '</option>';
        if (wanted && String(r.name).toLowerCase() === String(wanted).toLowerCase()) { found = r.name; }
      });
      if (wanted && found === '') {
        h += '<option value="' + escAttr(wanted) + '">' + esc(wanted) + '</option>';
        found = wanted;
      }
      $(sel).html(h);
      $(sel).val(found);
    }

    /* ---------- Livestock Add / Edit modal (replaces the old opener and the old submit handler) ---------- */
    var lvOpening = false;
    var LV_NAME_REQ = 'Name is required.', LV_TYPE_REQ = 'Type is required.', LV_STAGE_REQ = 'Stage is required.';
    var LV_STOCK_MSG = $('#lStockFb').text(), LV_COST_MSG = $('#lCostEachFb').text(), LV_PRICE_MSG = $('#lSellingPriceFb').text();

    function clearInvalid() {
      $('#lName, #lType, #lStock, #lCostEach, #lSellingPrice, #lStage').removeClass('is-invalid');
      $('#lNameFb').text(LV_NAME_REQ);
      $('#lTypeFb').text(LV_TYPE_REQ);
      $('#lStockFb').text(LV_STOCK_MSG);
      $('#lCostEachFb').text(LV_COST_MSG);
      $('#lSellingPriceFb').text(LV_PRICE_MSG);
      $('#lStageFb').text(LV_STAGE_REQ);
    }

    function openLivestockModal(d) {
      if (lvOpening) { return; }
      lvOpening = true;
      lslLoad(true).done(function () {
        $('#livestockForm')[0].reset();
        clearInvalid();
        qpResetModal('#livestockModal');
        $('#lId').val(d ? d.livestockid : 0);
        $('#lName').val(d ? d.name : '');
        lslFill('#lType', LSL.types, 'Select type', d ? d.type : '');
        lslFill('#lStage', LSL.stages, 'Select stage', d ? d.stage : 'Active');
        $('#lStock').val(d ? d.currentstock : 0);
        $('#lCostEach').val(d ? Number(d.costeach).toFixed(2) : '0.00');
        $('#lSellingPrice').val(d ? Number(d.sellingprice).toFixed(2) : '0.00');
        $('#livestockModalTitle').text(d ? 'Edit Livestock' : 'Add Livestock');
        $('#livestockModal').modal('show');
      }).always(function () { lvOpening = false; });
    }

    $('#lType, #lStage').on('change', function () { $(this).removeClass('is-invalid'); });

    $('#livestockForm').off('submit').on('submit', function (e) {
      e.preventDefault();
      clearInvalid();

      var name   = $.trim($('#lName').val());
      var type   = $.trim($('#lType').val() || '');
      var stock  = $.trim($('#lStock').val());
      var cost   = $.trim($('#lCostEach').val());
      var price  = $.trim($('#lSellingPrice').val());
      var stage = $.trim($('#lStage').val() || '');
      if (name === '') { $('#lName').addClass('is-invalid').trigger('focus'); return; }
      if (type === '') { $('#lType').addClass('is-invalid').trigger('focus'); return; }
      if (!/^\d+$/.test(stock) || Number(stock) > CFG.maxStock) { $('#lStock').addClass('is-invalid').trigger('focus'); return; }
      if (!validMoney(cost)) { $('#lCostEach').addClass('is-invalid').trigger('focus'); return; }
      if (!validMoney(price)) { $('#lSellingPrice').addClass('is-invalid').trigger('focus'); return; }
      if (stage === '') { $('#lStage').addClass('is-invalid').trigger('focus'); return; }

      var $btn = $('#btnSaveLivestock').prop('disabled', true);
      api({
        action: 'save',
        livestockid: $('#lId').val(),
        name: name,
        type: type,
        currentstock: stock,
        costeach: cost,
        sellingprice: price,
        stage: stage
      }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#livestockModal').modal('hide');
          table.ajax.reload(null, false);
          return;
        }
        var map = { name: ['#lName', '#lNameFb'], type: ['#lType', '#lTypeFb'], currentstock: ['#lStock', '#lStockFb'],
                    costeach: ['#lCostEach', '#lCostEachFb'], sellingprice: ['#lSellingPrice', '#lSellingPriceFb'], stage: ['#lStage', '#lStageFb'] };
        if (map[res.field]) {
          $(map[res.field][1]).text(res.message);
          $(map[res.field][0]).addClass('is-invalid').trigger('focus');
        }
        toastr.error(res.message);
      }).always(function () { $btn.prop('disabled', false); });
    });

    /* ---------- Quick type and quick status inside the Livestock modal ---------- */
    var QTY = {
      btn: '#btnQtyAdd', panel: '#qtyPanel', save: '#btnQtySave', cancel: '#btnQtyCancel', ok: '#qtyOk', focus: '#qtyName', action: 'typesave',
      fields: [{ key: 'name', sel: '#qtyName', fb: '#qtyNameFb', req: 'Type name is required.', max: 100, maxMsg: 'Type name is too long (maximum 100 characters).' }],
      done: function (row, res) {
        LSL.types.push(row);
        LSL.types.sort(byName);
        lslFill('#lType', LSL.types, 'Select type', row.name);
        $('#lType').removeClass('is-invalid');
        $('#lTypeFb').text(LV_TYPE_REQ);
        $('#qtyOk').text('Type "' + row.name + '" added and selected.');
        toastr.success(res.message);
        qpClose(QTY);
        table.ajax.reload(null, false);
      }
    };
    quickWire(QTY);

    var Qsg = {
      btn: '#btnQsgAdd', panel: '#QsgPanel', save: '#btnQsgSave', cancel: '#btnQsgCancel', ok: '#QsgOk', focus: '#QsgName', action: 'stagesave',
      fields: [{ key: 'name', sel: '#QsgName', fb: '#QsgNameFb', req: 'Stage name is required.', max: 50, maxMsg: 'Stage name is too long (maximum 50 characters).' }],
      done: function (row, res) {
        LSL.stages.push(row);
        LSL.stages.sort(byName);
        lslFill('#lStage', LSL.stages, 'Select stage', row.name);
        $('#lStage').removeClass('is-invalid');
        $('#lStageFb').text(LV_STAGE_REQ);
        $('#QsgOk').text('Stage "' + row.name + '" added and selected.');
        toastr.success(res.message);
        qpClose(Qsg);
        table.ajax.reload(null, false);
      }
    };
    quickWire(Qsg);
</script>

<!-- ===================== Manage types and statuses: list, rename, delete (inline panels) ===================== -->
<script nonce="<?= $n ?>">
    var MG = {
      ty: { key: 'types', idKey: 'livestocktypeid', noun: 'type', label: 'Type', max: 100, pre: 'mty',
            sel: '#lType', ph: 'Select type', list: '#mtyList', panel: '#mtyPanel', btn: '#btnMtyOpen', close: '#btnMtyClose', ok: '#mtyOk',
            plus: '#btnQtyAdd', upd: 'typeupdate', del: 'typedelete', edit: 0, confirm: 0, busy: false },
      sg: { key: 'stages', idKey: 'livestockstageid', noun: 'stage', label: 'Stage', max: 50, pre: 'msg',
            sel: '#lStage', ph: 'Select stage', list: '#MsgList', panel: '#MsgPanel', btn: '#btnMsgOpen', close: '#btnMsgClose', ok: '#MsgOk',
            plus: '#btnQsgAdd', upd: 'stageupdate', del: 'stagedelete', edit: 0, confirm: 0, busy: false }
    };

    function mgItems(c) { return LSL[c.key] || []; }
    function mgFind(c, id) {
      var f = null;
      $.each(mgItems(c), function (i, r) { if (Number(r[c.idKey]) === id) { f = r; } });
      return f;
    }
    function mgRowSel(c, id) { return c.list + ' .manage-row[data-id="' + id + '"]'; }

    function mgRender(c) {
      var items = mgItems(c), h = '';
      if (!items.length) { h = '<div class="manage-empty">No saved ' + c.noun + 's.</div>'; }
      $.each(items, function (i, r) {
        var id = Number(r[c.idKey]), used = Number(r.used || 0), nm = String(r.name), a = escAttr(nm), fb = c.pre + 'Fb' + id;
        if (c.edit === id) {
          h += '<div class="manage-row is-edit" data-id="' + id + '">' +
            '<div class="manage-edit">' +
              '<input type="text" class="form-control mg-input" maxlength="' + c.max + '" value="' + a + '" aria-label="New name for ' + c.noun + ' ' + a + '" aria-describedby="' + fb + '">' +
              '<div class="invalid-feedback" id="' + fb + '">' + esc(c.label) + ' name is required.</div>' +
            '</div>' +
            '<span class="manage-btns">' +
              '<button type="button" class="btn btn-success act-btn mg-save" title="Save" aria-label="Save ' + c.noun + ' ' + a + '"><i class="fas fa-check" aria-hidden="true"></i></button>' +
              '<button type="button" class="btn btn-secondary act-btn mg-cancel" title="Cancel" aria-label="Cancel renaming ' + c.noun + ' ' + a + '"><i class="fas fa-times" aria-hidden="true"></i></button>' +
            '</span></div>';
        } else if (c.confirm === id) {
          h += '<div class="manage-row is-confirm" data-id="' + id + '">' +
            '<span class="manage-name manage-q">Delete "' + esc(nm) + '"?</span>' +
            '<span class="manage-btns">' +
              '<button type="button" class="btn btn-danger btn-sm mg-yes">Yes, delete</button>' +
              '<button type="button" class="btn btn-secondary btn-sm mg-no">No</button>' +
            '</span></div>';
        } else {
          h += '<div class="manage-row" data-id="' + id + '">' +
            '<span class="manage-name">' + esc(nm) + '</span>' +
            '<span class="badge st-inactive">' + fmtInt(used) + ' in use</span>' +
            '<span class="manage-btns">' +
              '<button type="button" class="btn btn-primary act-btn mg-edit" title="Rename" aria-label="Rename ' + c.noun + ' ' + a + '"><i class="fas fa-edit" aria-hidden="true"></i></button>' +
              '<button type="button" class="btn btn-danger act-btn mg-del"' + (used > 0 ? ' disabled title="In use by ' + used + ' livestock item(s)"' : ' title="Delete"') + ' aria-label="Delete ' + c.noun + ' ' + a + '"><i class="fas fa-trash" aria-hidden="true"></i></button>' +
            '</span></div>';
        }
      });
      $(c.list).html(h);
    }

    function mgHide(c) {
      c.edit = 0; c.confirm = 0;
      $(c.panel).prop('hidden', true);
      $(c.btn).attr('aria-expanded', 'false');
    }
    function mgClose(c) { mgHide(c); $(c.btn).trigger('focus'); }

    // Reload the saved list from the server (after an error or a conflict) and redraw
    function mgReload(c) {
      return lslLoad(true).done(function () { c.edit = 0; c.confirm = 0; mgRender(c); });
    }

    function mgSelectionAfter(c, oldName, newName) {
      var cur = $(c.sel).val() || '';
      return cur !== '' && String(cur).toLowerCase() === String(oldName).toLowerCase() ? newName : cur;
    }

    function mgSave(c, $row) {
      if (c.busy) { return; }
      var id = Number($row.attr('data-id')), $in = $row.find('.mg-input'), $fb = $row.find('.invalid-feedback');
      var v = $.trim($in.val()), msg = '';
      $in.removeClass('is-invalid');
      if (v === '') { msg = c.label + ' name is required.'; }
      else if (v.length > c.max) { msg = c.label + ' name is too long (maximum ' + c.max + ' characters).'; }
      else {
        $.each(mgItems(c), function (i, r) {
          if (Number(r[c.idKey]) !== id && String(r.name).toLowerCase() === v.toLowerCase()) { msg = 'A ' + c.noun + ' named "' + v + '" already exists.'; }
        });
      }
      if (msg) { $fb.text(msg); $in.addClass('is-invalid').trigger('focus'); return; }

      var data = { action: c.upd, name: v };
      data[c.idKey] = id;
      c.busy = true;
      $row.find('button').prop('disabled', true);
      api(data).done(function (res) {
        if (res.success) {
          var item = mgFind(c, id), oldName = item ? String(item.name) : '';
          if (item) { item.name = res.row.name; item.used = Number(res.row.used || 0); }
          LSL[c.key].sort(byName);
          c.edit = 0;
          lslFill(c.sel, LSL[c.key], c.ph, mgSelectionAfter(c, oldName, res.row.name));
          mgRender(c);
          $(c.ok).text(c.label + ' "' + oldName + '" renamed to "' + res.row.name + '".' + (Number(res.updated) > 0 ? ' ' + res.updated + ' livestock item(s) updated.' : ''));
          toastr.success(res.message);
          table.ajax.reload(null, false);
          $(mgRowSel(c, id) + ' .mg-edit').trigger('focus');
          return;
        }
        if (res.field === 'name') {
          $fb.text(res.message);
          $in.addClass('is-invalid').trigger('focus');
          toastr.error(res.message);
          return;
        }
        toastr.error(res.message);
        mgReload(c);
      }).always(function () {
        c.busy = false;
        $row.find('button').prop('disabled', false);
      });
    }

    function mgDelete(c, $row) {
      if (c.busy) { return; }
      var id = Number($row.attr('data-id')), data = { action: c.del };
      data[c.idKey] = id;
      c.busy = true;
      $row.find('button').prop('disabled', true);
      api(data).done(function (res) {
        if (res.success) {
          var gone = mgFind(c, id), goneName = gone ? String(gone.name) : '';
          LSL[c.key] = $.grep(LSL[c.key], function (r) { return Number(r[c.idKey]) !== id; });
          c.confirm = 0;
          var cur = $(c.sel).val() || '';
          lslFill(c.sel, LSL[c.key], c.ph, cur !== '' && String(cur).toLowerCase() === goneName.toLowerCase() ? '' : cur);
          mgRender(c);
          $(c.ok).text(c.label + ' "' + goneName + '" deleted.');
          toastr.success(res.message);
          table.ajax.reload(null, false);
          $(c.close).trigger('focus');
          return;
        }
        if (res.blocked) { toastr.warning(res.message); } else { toastr.error(res.message); }
        mgReload(c);
      }).always(function () {
        c.busy = false;
        $row.find('button').prop('disabled', false);
      });
    }

    $.each(MG, function (k, c) {
      // Manage button: toggles its panel exactly like quickWire (the Escape handler clicks it by aria-controls)
      $(c.btn).on('click', function () {
        if (!$(c.panel).prop('hidden')) { mgClose(c); return; }
        $(c.plus).attr('aria-expanded', 'false');
        $(c.panel).prevAll('.quick-panel').first().prop('hidden', true);
        lslLoad(true).done(function () {
          c.edit = 0; c.confirm = 0;
          $(c.ok).text('');
          mgRender(c);
          $(c.panel).prop('hidden', false);
          $(c.btn).attr('aria-expanded', 'true');
          var $first = $(c.list).find('.mg-edit').first();
          if ($first.length) { $first.trigger('focus'); } else { $(c.close).trigger('focus'); }
        });
      });
      // Opening the "+" panel of the same field closes this panel (only one panel per field)
      $(c.plus).on('click', function () { if (!$(c.panel).prop('hidden')) { mgHide(c); } });
      $(c.close).on('click', function () { mgClose(c); });

      // Row actions
      $(c.list).on('click', '.mg-edit', function () {
        c.edit = Number($(this).closest('.manage-row').attr('data-id')); c.confirm = 0;
        mgRender(c);
        $(mgRowSel(c, c.edit) + ' .mg-input').trigger('focus').trigger('select');
      });
      $(c.list).on('click', '.mg-cancel', function () {
        var id = c.edit; c.edit = 0; mgRender(c);
        $(mgRowSel(c, id) + ' .mg-edit').trigger('focus');
      });
      $(c.list).on('click', '.mg-save', function () { mgSave(c, $(this).closest('.manage-row')); });
      $(c.list).on('input', '.mg-input', function () { $(this).removeClass('is-invalid'); });
      $(c.list).on('click', '.mg-del', function () {
        c.confirm = Number($(this).closest('.manage-row').attr('data-id')); c.edit = 0;
        mgRender(c);
        $(mgRowSel(c, c.confirm) + ' .mg-no').trigger('focus');
      });
      $(c.list).on('click', '.mg-no', function () {
        var id = c.confirm; c.confirm = 0; mgRender(c);
        $(mgRowSel(c, id) + ' .mg-del').trigger('focus');
      });
      $(c.list).on('click', '.mg-yes', function () { mgDelete(c, $(this).closest('.manage-row')); });

      // Enter in the row input saves the row and stops here: it never reaches the document handler or the livestock form
      $(c.list).on('keydown', '.mg-input', function (e) {
        if (e.key !== 'Enter') { return; }
        e.preventDefault();
        e.stopPropagation();
        mgSave(c, $(this).closest('.manage-row'));
      });

      // Fresh state every time the Livestock modal opens
      $('#livestockModal').on('show.bs.modal', function () { c.edit = 0; c.confirm = 0; c.busy = false; $(c.list).empty(); $(c.ok).text(''); });
    });

    // Escape: cancel the row edit / row confirm first (window capture runs before the quick add Escape handler),
    // then the quick add handler closes the panel, then the modal closes.
    window.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') { return; }
      var handled = false;
      $.each(MG, function (k, c) {
        if (handled || !$(e.target).closest(c.list).length || (!c.edit && !c.confirm)) { return; }
        var id = c.edit || c.confirm, wasEdit = !!c.edit;
        c.edit = 0; c.confirm = 0;
        mgRender(c);
        $(mgRowSel(c, id) + (wasEdit ? ' .mg-edit' : ' .mg-del')).trigger('focus');
        handled = true;
      });
      if (handled) { e.stopPropagation(); e.preventDefault(); }
    }, true);
</script>

<!-- ===================== Add New Sale JavaScript (bulk lines, edit mode; BigInt money, no floats) ===================== -->
<script nonce="<?= $n ?>">
    var SL = { customers: [], livestock: [], accounts: [], lines: [], invoice: null, locked: false, manual: false, receivedinaccountid: 0 };
    var SLIVE = {};
    var SACC  = {};
    var saSaving = false, saOpening = false, saSeq = 0, saEditId = 0, saLocked = false, saBack = '', saStockBlocked = false;

    function indexSaleLookups() {
      SLIVE = {};
      SACC  = {};
      $.each(SL.livestock || [], function (i, r) { SLIVE[String(r.livestockid)] = r; });
      $.each(SL.accounts || [], function (i, r) { SACC[String(r.accountid)] = r.code + ' ' + r.accountname; });
    }
    // Animals a line can use: in edit mode "available" already includes what the invoice holds
    function saAvail(a) { return a.available !== undefined ? Number(a.available) : Number(a.currentstock); }

    /* ---------- Invalid state ---------- */
    var SA_FIELDS = {
      customerid:          ['#saCustomer', '#saCustomerFb'],
      saledate:            ['#saDate',     '#saDateFb'],
      reference:           ['#saRef',      '#saRefFb'],
      paidamount:          ['#saPaid',     '#saPaidFb'],
      receivedinaccountid: ['#saAccount',  '#saAccountFb'],
      note:                ['#saNote',     '#saNoteFb']
    };
    var SA_DEFAULTS = {};
    $.each(SA_FIELDS, function (key, pair) { SA_DEFAULTS[key] = $(pair[1]).text(); });
    var SA_LINE_KEYS = { livestock: '.sl-livestock', quantity: '.sl-qty', sellingprice: '.sl-selling' };

    /* ---------- Walk-in customer (sale) ---------- */
    var WALKIN = <?= json_encode(BF_WALKIN_NAME, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    SA_FIELDS.walkinname   = ['#saWalkinName', '#saWalkinNameFb'];
    SA_DEFAULTS.walkinname = $('#saWalkinNameFb').text();

    function saIsWalkin() { return $('#saCustomer').val() === 'walkin'; }

    function saToggleWalkin() {
      var w = saIsWalkin();
      $('#saWalkinWrap').prop('hidden', !w);
      if (!w) { $('#saWalkinName').val(''); }
      saResetField('walkinname');
    }

    // Invoice of the Walk-in customer: name and note are split from the note "Walk-in: NAME - note"
    function saParseWalkin(inv) {
      var r = { is: String(inv.customername).toLowerCase() === String(WALKIN).toLowerCase(), name: '', note: inv.note };
      if (r.is) {
        var prefix = WALKIN + ': ', n = String(inv.note || '');
        if (n.indexOf(prefix) === 0) {
          var rest = n.substring(prefix.length), k = rest.indexOf(' - ');
          r.name = k < 0 ? rest : rest.substring(0, k);
          r.note = k < 0 ? '' : rest.substring(k + 3);
        }
      }
      return r;
    }

    $('#saCustomer').on('change', saToggleWalkin);
    $('#saWalkinName').on('input', function () { saResetField('walkinname'); });

    function saResetField(key) {
      $(SA_FIELDS[key][0]).removeClass('is-invalid');
      $(SA_FIELDS[key][1]).text(SA_DEFAULTS[key]);
    }
    function saResetLine($c) {
      $c.removeClass('is-invalid').data('stockerr', false);
      $c.siblings('.invalid-feedback').each(function () { $(this).text($(this).attr('data-default') || ''); });
    }
    function saClearInvalid() {
      $.each(SA_FIELDS, function (key) { saResetField(key); });
      $('#saLinesBody .form-control').each(function () { saResetLine($(this)); });
      $('#saLinesFb').prop('hidden', true);
    }
    function saMark(key, message) {
      if (message) { $(SA_FIELDS[key][1]).text(message); }
      $(SA_FIELDS[key][0]).addClass('is-invalid').trigger('focus');
    }
    function saMarkLine(n, key, message) {
      var $c = $('#saLinesBody tr.line-row').eq(n - 1).find(SA_LINE_KEYS[key]);
      if (!$c.length) { return false; }
      if (message) { $c.siblings('.invalid-feedback').text(message); }
      $c.addClass('is-invalid').trigger('focus');
      return true;
    }
    function saMarkLinesError(message) { $('#saLinesFb').text(message).prop('hidden', false); }

    /* Server field key: a header field, "lines", or "lines:N:livestock|quantity|sellingprice". */
    function saShowServerError(field, message) {
      if (field && SA_FIELDS[field]) { saMark(field, message); return; }
      var m = /^lines:(\d+):(livestock|quantity|sellingprice)$/.exec(field || '');
      if (m && saMarkLine(parseInt(m[1], 10), m[2], message)) { return; }
      if (field === 'lines' || m) { saMarkLinesError(message); }
    }

    /* ---------- Lines ---------- */
    function saItemOptions() {
      var h = '<option value="">Select livestock</option>';
      $.each(SL.livestock || [], function (i, r) { h += '<option value="' + Number(r.livestockid) + '">' + esc(r.name + ' (' + r.type + ')') + '</option>'; });
      return h;
    }

    function saLineHtml(seq) {
      return '<tr class="line-row" data-seq="' + seq + '">' +
        '<td><select class="form-control sl-livestock" aria-describedby="slHelp' + seq + ' slFb' + seq + '"></select>' +
          '<div class="helper-line line-help" id="slHelp' + seq + '" aria-live="polite"></div>' +
          '<div class="invalid-feedback" id="slFb' + seq + '" data-default="Select a livestock item.">Select a livestock item.</div></td>' +
        '<td class="w-qty"><input type="number" class="form-control sl-qty" min="1" max="999999" step="1" inputmode="numeric" aria-describedby="slQtyFb' + seq + '">' +
          '<div class="invalid-feedback" id="slQtyFb' + seq + '" data-default="Quantity must be a whole number greater than 0.">Quantity must be a whole number greater than 0.</div></td>' +
        '<td class="w-price"><input type="number" class="form-control sl-selling" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="0" aria-describedby="slSellingPriceFb' + seq + '">' +
          '<div class="invalid-feedback" id="slSellingPriceFb' + seq + '" data-default="Selling price must be 0 or more, with up to 2 decimals.">Selling price must be 0 or more, with up to 2 decimals.</div></td>' +
        '<td class="w-total ln-total-cell"><output class="sl-total">$0.00</output></td>' +
        '<td class="w-rm text-center"><button type="button" class="btn btn-danger act-btn btn-sl-remove" title="Remove line"><i class="fas fa-times" aria-hidden="true"></i></button></td>' +
        '</tr>';
    }

    function saRenumber() {
      var $rows = $('#saLinesBody tr.line-row');
      $rows.each(function (i) {
        var n = i + 1, $tr = $(this);
        $tr.find('.sl-livestock').attr('aria-label', 'Livestock, line ' + n);
        $tr.find('.sl-qty').attr('aria-label', 'Quantity, line ' + n);
        $tr.find('.sl-selling').attr('aria-label', 'Selling price, line ' + n);
        $tr.find('.sl-total').attr('aria-label', 'Line total, line ' + n);
        $tr.find('.btn-sl-remove').attr('aria-label', 'Remove line ' + n).prop('disabled', saLocked || $rows.length === 1);
      });
    }

    function saLineValues($tr) {
      return { item: $tr.find('.sl-livestock').val() || '', qty: $.trim($tr.find('.sl-qty').val()), price: $.trim($tr.find('.sl-selling').val()) };
    }
    function saQtyOk(v) { return RE_QTY.test(v.qty) && /[1-9]/.test(v.qty); }
    function saLineTotal(v) {
      if (!saQtyOk(v) || !RE_PRICE.test(v.price)) { return null; }
      return BigInt(v.qty) * toMinor(v.price, 2);
    }

    // Selling price follows the selling price of the animal until the user types a price on that line
    function saApplyDefaultPrice($tr) {
      if ($tr.data('touched')) { return; }
      var a = SLIVE[String($tr.find('.sl-livestock').val() || '')];
      $tr.find('.sl-selling').val(a ? a.sellingprice : '0');
    }

    function saUpdateLine($tr) {
      var v = saLineValues($tr), a = SLIVE[String(v.item)];
      $tr.find('.line-help').text(a && !saLocked ? 'Available: ' + fmtInt(saAvail(a)) + ' animals' : '');
      var t = saLineTotal(v);
      $tr.find('.sl-total').text(t === null ? '$0.00' : minorMoney(t));
    }

    // Stock rule on the client: the SUM per animal over the lines (same text as the server)
    function saStockErrors() {
      var errs = [], taken = {};
      if (saLocked) { return errs; }
      $('#saLinesBody tr.line-row').each(function (i) {
        var v = saLineValues($(this)), a = SLIVE[String(v.item)];
        errs[i] = '';
        if (!a || !saQtyOk(v)) { return; }
        var left = saAvail(a) - (taken[v.item] || 0);
        if (Number(v.qty) > left) { errs[i] = 'Line ' + (i + 1) + ': only ' + fmtInt(Math.max(0, left)) + ' of ' + a.name + ' available.'; }
        taken[v.item] = (taken[v.item] || 0) + Number(v.qty);
      });
      return errs;
    }

    function saRecalc() {
      var total = 0n;
      $('#saLinesBody tr.line-row').each(function () {
        var t = saLineTotal(saLineValues($(this)));
        if (t !== null) { total += t; }
      });
      var paidRaw = $.trim($('#saPaid').val()) || '0';
      var paid = RE_PAID.test(paidRaw) ? toMinor(paidRaw, 2) : 0n;
      var bal = total - paid;
      $('#saFootTotal').text(minorMoney(total));
      $('#saFootPaid').text(minorMoney(paid));
      $('#saFootBalance').text(minorMoney(bal < 0n ? 0n : bal));
      $('#saAccountReq').prop('hidden', paid <= 0n);
      $('#saAccount').attr('aria-required', paid > 0n ? 'true' : 'false');

      var errs = saStockErrors();
      saStockBlocked = false;
      $('#saLinesBody tr.line-row').each(function (i) {
        var $q = $(this).find('.sl-qty'), $fb = $q.siblings('.invalid-feedback');
        if (errs[i]) {
          saStockBlocked = true;
          $q.addClass('is-invalid').data('stockerr', true);
          $fb.text(errs[i]);
        } else if ($q.data('stockerr')) {
          $q.removeClass('is-invalid').data('stockerr', false);
          $fb.text($fb.attr('data-default') || '');
        }
      });
      $('#btnSaveSale').prop('disabled', saStockBlocked || saSaving);
    }

    function saAddLine(livestockId, qty, price) {
      if ($('#saLinesBody tr.line-row').length >= CFG.maxLines) {
        toastr.warning('A sale can have at most ' + CFG.maxLines + ' lines.');
        return null;
      }
      var $tr = $(saLineHtml(++saSeq)).appendTo('#saLinesBody');
      $tr.find('.sl-livestock').html(saItemOptions());
      if (livestockId && SLIVE[String(livestockId)]) { $tr.find('.sl-livestock').val(String(livestockId)); }
      if (qty !== undefined) { $tr.find('.sl-qty').val(qty); }
      if (price !== undefined) { $tr.find('.sl-selling').val(price); $tr.data('touched', true); } else { saApplyDefaultPrice($tr); }
      if (saLocked) { $tr.find('input, select, button').prop('disabled', true); }
      saRenumber();
      saUpdateLine($tr);
      saRecalc();
      return $tr;
    }

    $('#btnAddSaleLine').on('click', function () {
      $('#saLinesFb').prop('hidden', true);
      var $tr = saAddLine(0);
      if ($tr) { $tr.find('.sl-livestock').trigger('focus'); }
    });
    $('#saLinesBody').on('change', '.sl-livestock', function () {
      var $tr = $(this).closest('tr');
      saResetLine($(this));
      saResetLine($tr.find('.sl-qty'));
      saApplyDefaultPrice($tr);
      saResetLine($tr.find('.sl-selling'));
      saUpdateLine($tr);
      saRecalc();
    });
    $('#saLinesBody').on('input', '.sl-selling', function () { $(this).closest('tr').data('touched', true); });
    $('#saLinesBody').on('input', '.sl-qty, .sl-selling', function () {
      saResetLine($(this));
      saUpdateLine($(this).closest('tr'));
      saRecalc();
    });
    $('#saLinesBody').on('click', '.btn-sl-remove', function () {
      $(this).closest('tr').remove();
      if (!$('#saLinesBody tr.line-row').length) { saAddLine(0); }
      saRenumber();
      saRecalc();
    });

    $('#saCustomer, #saDate, #saRef, #saNote').on('input change', function () {
      var id = '#' + this.id;
      $.each(SA_FIELDS, function (key, pair) { if (pair[0] === id) { saResetField(key); } });
    });
    $('#saPaid').on('input', function () { saResetField('paidamount'); saRecalc(); });
    $('#saAccount').on('change', function () { saResetField('receivedinaccountid'); saRecalc(); });
    $('#saleModal').on('shown.bs.modal', function () { $(saLocked ? '#saDate' : '#saCustomer').trigger('focus'); });

    /* ---------- Open: new sale ---------- */
    function saFillSelects() {
      var c = '<option value="">Select customer</option><option value="walkin">Walk-in</option>';
      $.each(SL.customers || [], function (i, r) { c += '<option value="' + Number(r.customerid) + '">' + esc(r.name) + '</option>'; });
      $('#saCustomer').html(c);
      var a = '<option value="">Select account</option>';
      $.each(SL.accounts || [], function (i, r) { a += '<option value="' + Number(r.accountid) + '">' + esc(r.code + ' ' + r.accountname) + '</option>'; });
      $('#saAccount').html(a);
      indexSaleLookups();
    }

    function saResetModal() {
      $('#saleForm')[0].reset();
      saClearInvalid();
      saFillSelects();
      saSaving = false;
      saLocked = false;
      $('#saCustomer, #saDate, #saRef, #saPaid, #saAccount, #saNote, #btnAddSaleLine').prop('disabled', false);
      $('#saLockMsg').prop('hidden', true).text('');
      saToggleWalkin();
      $('#saLinesBody').empty();
    }

    function showSaleModal(livestockId) {
      saResetModal();
      saEditId = 0;
      saBack = '';
      $('#saleModalTitle').text('Add New Sale');
      $('#saDate').val(ymd(new Date()));
      $('#saPaid').val('0');
      saAddLine(livestockId);
      saRecalc();
      $('#saleModal').modal('show');
    }

    function openSaleModal(livestockId) {
      if (saOpening) { return; }
      saOpening = true;
      api({ action: 'salelookups' }).done(function (res) {
        if (!res.success) { toastr.error(res.message); return; }
        SL = $.extend({ lines: [], invoice: null, locked: false, manual: false, receivedinaccountid: 0 }, res.data);
        showSaleModal(livestockId);
      }).always(function () { saOpening = false; });
    }

    /* ---------- Open: edit sale ---------- */
    function openSaleEdit(invoiceId, back) {
      if (saOpening) { return; }
      saOpening = true;
      api({ action: 'saleeditlookups', invoiceid: invoiceId }).done(function (res) {
        if (!res.success) { if (res.blocked) { toastr.warning(res.message); } else { toastr.error(res.message); } return; }
        SL = res.data;
        saResetModal();
        saEditId = invoiceId;
        saBack = back || '';
        saLocked = !!SL.locked;
        var inv = SL.invoice;
        $('#saleModalTitle').text('Edit Sale #' + inv.invoiceid);
        var wk = saParseWalkin(inv);
        $('#saCustomer').val(wk.is ? 'walkin' : String(inv.customerid));
        $('#saWalkinName').val(wk.name);
        saToggleWalkin();
        $('#saDate').val(inv.invoicedate);
        $('#saRef').val(inv.reference);
        $('#saNote').val(wk.is ? wk.note : inv.note);
        $('#saPaid').val(inv.paid);
        $('#saAccount').val(SL.receivedinaccountid > 0 && SACC[String(SL.receivedinaccountid)] ? String(SL.receivedinaccountid) : '');
        $.each(SL.lines, function (i, l) { saAddLine(l.livestockid, l.quantity, l.sellingprice); });

        var msgs = [];
        if (saLocked) {
          $('#saCustomer, #btnAddSaleLine').prop('disabled', true);
          msgs.push('This invoice already has returns or refunds, so the customer, lines, quantities and prices cannot change. You can edit the date, reference, note and paid amount (not below the refunds already made: ' + moneyS(inv.refunded) + ').');
        }
        if (SL.manual) {
          $('#saPaid, #saAccount').prop('disabled', true);
          msgs.push('The paid amount and account cannot change here: this invoice has receipts entered on the Receipts page.');
        }
        $('#saLockMsg').text(msgs.join(' ')).prop('hidden', msgs.length === 0);
        saRenumber();
        saRecalc();
        gotoModal('#saleModal');
      }).always(function () { saOpening = false; });
    }

    // Reload the lookups (fresh stock) while the modal is open; a closed modal reloads them when it opens
    function saRefreshLookups() {
      if (!$('#saleModal').hasClass('show')) { return $.Deferred().resolve().promise(); }
      var req = saEditId > 0 ? { action: 'saleeditlookups', invoiceid: saEditId } : { action: 'salelookups' };
      return api(req).done(function (res) {
        if (res.success) { SL.livestock = res.data.livestock; SL.accounts = res.data.accounts; indexSaleLookups(); saRecalc(); }
      });
    }

    $('#btnSale').on('click', function () { openSaleModal(0); });
    $('#livestockTable tbody').on('click', '.btn-sale', function () { openSaleModal(rowData(this).livestockid); });

    // Closed or cancelled: go back to the modal it was opened from (navigation to another modal is handled by gotoModal)
    $('#saleModal').on('hidden.bs.modal', function () {
      if (navigating) { return; }
      var b = saBack;
      saBack = '';
      if (b) { $(b).modal('show'); }
    });

    /* ---------- Client validation (the server repeats every rule) ---------- */
    function saValidate() {
      saClearInvalid();
      if (!$('#saCustomer').val()) { saMark('customerid', null); return false; }
      var date = $.trim($('#saDate').val());
      if (!realDate(date)) { saMark('saledate', null); return false; }

      var rows = $('#saLinesBody tr.line-row');
      if (!rows.length) { saMarkLinesError('Add at least one line.'); return false; }
      if (rows.length > CFG.maxLines) { saMarkLinesError('A sale can have at most ' + CFG.maxLines + ' lines.'); return false; }

      var total = 0n, ok = true;
      rows.each(function (i) {
        var n = i + 1, v = saLineValues($(this));
        if (!v.item || !SLIVE[String(v.item)]) { saMarkLine(n, 'livestock', null); ok = false; return false; }
        if (!saQtyOk(v)) { saMarkLine(n, 'quantity', null); ok = false; return false; }
        if (!RE_PRICE.test(v.price)) { saMarkLine(n, 'sellingprice', null); ok = false; return false; }
        var t = saLineTotal(v);
        if (t > MAX_TOTAL) { saMarkLine(n, 'quantity', 'The line total is too large.'); ok = false; return false; }
        total += t;
      });
      if (!ok) { return false; }
      if (total > MAX_TOTAL) { saMarkLinesError('The sale total is too large.'); return false; }

      var errs = saStockErrors();
      for (var k = 0; k < errs.length; k++) {
        if (errs[k]) { saStockBlocked = true; saMarkLine(k + 1, 'quantity', errs[k]); return false; }
      }

      var paidRaw = $.trim($('#saPaid').val()) || '0';
      if (!RE_PAID.test(paidRaw)) { saMark('paidamount', null); return false; }
      var paid = toMinor(paidRaw, 2);
      if (paid > total) { saMark('paidamount', 'Paid amount cannot be more than the total.'); return false; }
      if (saIsWalkin() && paid !== total) { saMark('paidamount', 'A walk-in sale must be paid in full.'); return false; }
      if (saEditId && SL.invoice && paid < toMinorSigned(SL.invoice.refunded)) {
        saMark('paidamount', 'Paid amount cannot be less than the refunds already made (' + moneyS(SL.invoice.refunded) + ').');
        return false;
      }
      if (paid > 0n && !SACC[String($('#saAccount').val() || '')]) { saMark('receivedinaccountid', null); return false; }
      return true;
    }

    /* ---------- Save (new) or update (edit): after success the Invoice modal opens ---------- */
    $('#saleForm').on('submit', function (e) {
      e.preventDefault();
      if (saSaving || !saValidate()) { return; }

      var lines = [];
      $('#saLinesBody tr.line-row').each(function () {
        var v = saLineValues($(this));
        lines.push({ livestockid: v.item, quantity: v.qty, sellingprice: v.price });
      });

      var data = {
        action: saEditId ? 'saleupdate' : 'salesave',
        customerid: $('#saCustomer').val(),
        walkinname: ($('#saCustomer').val() === 'walkin') ? $.trim($('#saWalkinName').val()) : '',
        saledate: $.trim($('#saDate').val()),
        reference: $.trim($('#saRef').val()),
        paidamount: $.trim($('#saPaid').val()) || '0',
        receivedinaccountid: $('#saAccount').val() || 0,
        note: $.trim($('#saNote').val()),
        lines: JSON.stringify(lines)
      };
      if (saEditId) { data.invoiceid = saEditId; }

      var ok = false;
      saSaving = true;
      $('#btnSaveSale').prop('disabled', true);
      api(data).done(function (res) {
        if (res.success) {
          ok = true; // stays locked: the modal is replaced by the invoice
          toastr.success(res.message);
          var back = saBack;
          saBack = '';
          afterChange();
          openInvoice(res.invoiceid, back === '#invoiceModal' ? INVBACK : back);
          return;
        }
        saShowServerError(res.field, res.message);
        toastr.error(res.message);
        if (res.field && /^lines/.test(res.field)) { saRefreshLookups(); }
      }).always(function () {
        if (!ok) { saSaving = false; saRecalc(); }
      });
    });
</script>

<script type="text/plain" nonce="<?= $n ?>">
/* Old one-animal sale script: disabled by type="text/plain". Delete this block (up to its closing script tag) when you want. */
<script nonce="<?= $n ?>">
    var SL = { customers: [], livestock: [], accounts: [] };
    var SLIVE = {};
    var SACC  = {};
    var saSaving = false;
    var saOpening = false;
    var saStockFlagged = false;
    var saStockBlocked = false;
    var saPriceTouched = false;

    function indexSaleLookups() {
      SLIVE = {};
      SACC  = {};
      $.each(SL.livestock || [], function (i, r) { SLIVE[String(r.livestockid)] = r; });
      $.each(SL.accounts || [], function (i, r) { SACC[String(r.accountid)] = r.code + ' ' + r.accountname; });
    }

    /* ---------- Field map (server field keys) ---------- */
    var SA_FIELDS = {
      customerid:          ['#saCustomer',  '#saCustomerFb'],
      saledate:            ['#saDate',      '#saDateFb'],
      reference:           ['#saRef',       '#saRefFb'],
      livestockid:         ['#saLivestock', '#saLivestockFb'],
      quantity:            ['#saQty',       '#saQtyFb'],
      unitprice:           ['#saPrice',     '#saPriceFb'],
      paidamount:          ['#saPaid',      '#saPaidFb'],
      receivedinaccountid: ['#saAccount',   '#saAccountFb'],
      note:                ['#saNote',      '#saNoteFb']
    };
    var SA_DEFAULTS = {};
    $.each(SA_FIELDS, function (key, pair) { SA_DEFAULTS[key] = $(pair[1]).text(); });

    function saResetField(key) {
      $(SA_FIELDS[key][0]).removeClass('is-invalid');
      $(SA_FIELDS[key][1]).text(SA_DEFAULTS[key]);
    }

    function saClearInvalid() {
      $.each(SA_FIELDS, function (key) { saResetField(key); });
      saStockFlagged = false;
    }

    function saMark(key, message) {
      if (message) { $(SA_FIELDS[key][1]).text(message); }
      $(SA_FIELDS[key][0]).addClass('is-invalid').trigger('focus');
    }

    function saShowServerError(field, message) {
      if (field && SA_FIELDS[field]) { saMark(field, message); }
    }

    /* ---------- Stock rule ---------- */
    function saStockMessage() {
      var a = SLIVE[String($('#saLivestock').val() || '')];
      if (!a) { return ''; }
      if (a.currentstock <= 0) { return 'No stock: ' + a.name + ' has 0 animals on hand.'; }
      var qty = $.trim($('#saQty').val());
      if (RE_QTY.test(qty) && /[1-9]/.test(qty) && Number(qty) > a.currentstock) {
        return 'Only ' + fmtInt(a.currentstock) + ' of ' + a.name + ' available.';
      }
      return '';
    }

    /* ---------- Live recalculation ---------- */
    function saRecalc() {
      var qty   = $.trim($('#saQty').val());
      var price = $.trim($('#saPrice').val());
      var qOk   = RE_QTY.test(qty) && /[1-9]/.test(qty);
      var pOk   = RE_PRICE.test(price);
      var total = (qOk && pOk) ? BigInt(qty) * toMinor(price, 2) : 0n;

      var paidRaw = $.trim($('#saPaid').val()) || '0';
      var paid    = RE_PAID.test(paidRaw) ? toMinor(paidRaw, 2) : 0n;
      var bal     = total - paid;

      $('#saTotal').val(minorMoney(total));
      $('#saFootTotal').text(minorMoney(total));
      $('#saFootPaid').text(minorMoney(paid));
      $('#saFootBalance').text(minorMoney(bal < 0n ? 0n : bal));
      $('#saAccountReq').prop('hidden', paid <= 0n);
      $('#saAccount').attr('aria-required', paid > 0n ? 'true' : 'false');

      var a = SLIVE[String($('#saLivestock').val() || '')];
      $('#saLivestockHelp').text(a ? 'On hand: ' + fmtInt(a.currentstock) + ' animals' : '');
      $('#saQtyHelp').text(a && qOk && Number(qty) <= a.currentstock ? 'Stock after sale: ' + fmtInt(a.currentstock - Number(qty)) + ' animals' : '');

      var hint = '';
      if (a && pOk) {
        var diff = toMinor(price, 2) - toMinor(a.unitcost, 2);
        hint = diff < 0n ? 'Loss per animal: ' + minorMoney(-diff) : 'Profit per animal: ' + minorMoney(diff);
      }
      $('#saPriceHelp').text(hint);

      var msg = saStockMessage();
      saStockBlocked = msg !== '';
      if (msg) {
        $('#saQty').addClass('is-invalid');
        $('#saQtyFb').text(msg);
      } else if (saStockFlagged) {
        $('#saQty').removeClass('is-invalid');
        $('#saQtyFb').text(SA_DEFAULTS.quantity);
      }
      saStockFlagged = saStockBlocked;
      $('#btnSaveSale').prop('disabled', saStockBlocked || saSaving);
    }

    // Selling price follows the selling price of the animal until the user types a price
    function saApplyDefaultPrice() {
      if (saPriceTouched) { return; }
      var a = SLIVE[String($('#saLivestock').val() || '')];
      $('#saPrice').val(a ? a.sellingprice : '0');
    }

    $('#saCustomer, #saDate, #saRef, #saNote').on('input change', function () {
      var id = '#' + this.id;
      $.each(SA_FIELDS, function (key, pair) { if (pair[0] === id) { saResetField(key); } });
    });
    $('#saLivestock').on('change', function () {
      saResetField('livestockid');
      saResetField('quantity');
      saApplyDefaultPrice();
      saResetField('unitprice');
      saRecalc();
    });
    $('#saQty').on('input', function () { saResetField('quantity'); saRecalc(); });
    $('#saPrice').on('input', function () { saPriceTouched = true; saResetField('unitprice'); saRecalc(); });
    $('#saPaid').on('input', function () { saResetField('paidamount'); saRecalc(); });
    $('#saAccount').on('change', function () { saResetField('receivedinaccountid'); saRecalc(); });
    $('#saleModal').on('shown.bs.modal', function () { $('#saCustomer').trigger('focus'); });

    /* ---------- Open the modal ---------- */
    function saFillSelects() {
      var c = '<option value="">Select customer</option><option value="walkin">Walk-in</option>';
      $.each(SL.customers || [], function (i, r) { c += '<option value="' + Number(r.customerid) + '">' + esc(r.name) + '</option>'; });
      $('#saCustomer').html(c);

      var l = '<option value="">Select livestock</option>';
      $.each(SL.livestock || [], function (i, r) { l += '<option value="' + Number(r.livestockid) + '">' + esc(r.name + ' (' + r.type + ')') + '</option>'; });
      $('#saLivestock').html(l);

      var a = '<option value="">Select account</option>';
      $.each(SL.accounts || [], function (i, r) { a += '<option value="' + Number(r.accountid) + '">' + esc(r.code + ' ' + r.accountname) + '</option>'; });
      $('#saAccount').html(a);

      indexSaleLookups();
    }

    function showSaleModal(livestockId) {
      $('#saleForm')[0].reset();
      saClearInvalid();
      saFillSelects();
      saSaving = false;
      saPriceTouched = false;
      $('#saDate').val(ymd(new Date()));
      $('#saPrice').val('0');
      $('#saPaid').val('0');
      if (livestockId && SLIVE[String(livestockId)]) {
        $('#saLivestock').val(String(livestockId));
        saApplyDefaultPrice();
      }
      saRecalc();
      $('#saleModal').modal('show');
    }

    // Reload the lookups (fresh stock) without touching the open form
    function saRefreshLookups() {
      return api({ action: 'salelookups' }).done(function (res) {
        if (res.success) { SL = res.data; indexSaleLookups(); saRecalc(); }
      });
    }

    function openSaleModal(livestockId) {
      if (saOpening) { return; }
      saOpening = true;
      api({ action: 'salelookups' }).done(function (res) {
        if (!res.success) { toastr.error(res.message); return; }
        SL = res.data;
        showSaleModal(livestockId);
      }).always(function () { saOpening = false; });
    }

    $('#btnSale').on('click', function () { openSaleModal(0); });
    $('#livestockTable tbody').on('click', '.btn-sale', function () { openSaleModal(rowData(this).livestockid); });

    /* ---------- Client validation (the server repeats every rule) ---------- */
    function saValidate() {
      saClearInvalid();

      if (!$('#saCustomer').val()) { saMark('customerid', null); return false; }

      var date = $.trim($('#saDate').val());
      var real = /^\d{4}-\d{2}-\d{2}$/.test(date) && ymd(new Date(date + 'T00:00:00')) === date;
      if (!real) { saMark('saledate', null); return false; }

      var lid = String($('#saLivestock').val() || '');
      if (!lid) { saMark('livestockid', null); return false; }

      var qty = $.trim($('#saQty').val());
      if (!(RE_QTY.test(qty) && /[1-9]/.test(qty))) { saMark('quantity', 'Quantity must be a whole number greater than 0.'); return false; }
      var stockMsg = saStockMessage();
      if (stockMsg) { saStockFlagged = true; saMark('quantity', stockMsg); return false; }

      var price = $.trim($('#saPrice').val());
      if (!RE_PRICE.test(price)) { saMark('sellingprice', null); return false; }

      var total = BigInt(qty) * toMinor(price, 2);
      if (total > MAX_TOTAL) { saMark('quantity', 'The sale total is too large.'); return false; }

      var paidRaw = $.trim($('#saPaid').val()) || '0';
      if (!RE_PAID.test(paidRaw)) { saMark('paidamount', null); return false; }
      var paid = toMinor(paidRaw, 2);
      if (paid > total) { saMark('paidamount', 'Paid amount cannot be more than the total.'); return false; }
      if (saIsWalkin() && paid !== total) { saMark('paidamount', 'A walk-in sale must be paid in full.'); return false; }
      if (paid > 0n && !SACC[String($('#saAccount').val() || '')]) { saMark('receivedinaccountid', null); return false; }
      return true;
    }

    /* ---------- Save sale: after a successful save the invoice modal opens ---------- */
    $('#saleForm').on('submit', function (e) {
      e.preventDefault();
      if (saSaving || !saValidate()) { return; }

      saSaving = true;
      $('#btnSaveSale').prop('disabled', true);
      api({
        action: 'salesave',
        customerid: $('#saCustomer').val(),
        walkinname: ($('#saCustomer').val() === 'walkin') ? $.trim($('#saWalkinName').val()) : '',
        saledate: $.trim($('#saDate').val()),
        reference: $.trim($('#saRef').val()),
        livestockid: $('#saLivestock').val(),
        quantity: $.trim($('#saQty').val()),
        unitprice: $.trim($('#saPrice').val()),
        paidamount: $.trim($('#saPaid').val()) || '0',
        receivedinaccountid: $('#saAccount').val() || 0,
        note: $.trim($('#saNote').val())
      }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          table.ajax.reload(null, false);
          saRefreshLookups();
          puRefreshLookups();
          openInvoice(res.invoiceid, '');
          return;
        }
        if (res.field === 'quantity' && /^Only /.test(res.message || '')) { saStockFlagged = true; }
        saShowServerError(res.field, res.message);
        toastr.error(res.message);
        if (res.field === 'quantity' || res.field === 'livestockid') { saRefreshLookups(); }
      }).always(function () {
        saSaving = false;
        $('#btnSaveSale').prop('disabled', saStockBlocked);
      });
    });
</script>

<!-- ===================== Make Quotation JavaScript (exact money math with BigInt, no floats, no stock change) ===================== -->
<script nonce="<?= $n ?>">
    var QL = { customers: [], livestock: [] };
    var QLIVE = {};
    var quSaving = false;
    var quOpening = false;
    var quSeq = 0;

    function indexQuotationLookups() {
      QLIVE = {};
      $.each(QL.livestock || [], function (i, r) { QLIVE[String(r.livestockid)] = r; });
    }

    /* ---------- Header fields: invalid state ---------- */
    var QU_FIELDS = {
      customerid:    ['#quCustomer', '#quCustomerFb'],
      quotationdate: ['#quDate',     '#quDateFb'],
      validuntil:    ['#quValid',    '#quValidFb'],
      reference:     ['#quRef',      '#quRefFb'],
      status:        ['#quStatus',   '#quStatusFb'],
      note:          ['#quNote',     '#quNoteFb']
    };
    var QU_DEFAULTS = {};
    $.each(QU_FIELDS, function (key, pair) { QU_DEFAULTS[key] = $(pair[1]).text(); });
    var QU_LINE_KEYS = { livestock: '.ql-livestock', quantity: '.ql-qty', sellingprice: '.ql-selling' };

    /* ---------- Walk-in customer (quotation) ---------- */
    QU_FIELDS.walkinname   = ['#quWalkinName', '#quWalkinNameFb'];
    QU_DEFAULTS.walkinname = $('#quWalkinNameFb').text();

    function quToggleWalkin() {
      var w = $('#quCustomer').val() === 'walkin';
      $('#quWalkinWrap').prop('hidden', !w);
      if (!w) { $('#quWalkinName').val(''); }
      quResetField('walkinname');
    }
    $('#quCustomer').on('change', quToggleWalkin);
    $('#quWalkinName').on('input', function () { quResetField('walkinname'); });

    function quResetField(key) {
      $(QU_FIELDS[key][0]).removeClass('is-invalid');
      $(QU_FIELDS[key][1]).text(QU_DEFAULTS[key]);
    }

    function quResetLineControl($c) {
      $c.removeClass('is-invalid');
      $c.siblings('.invalid-feedback').each(function () { $(this).text($(this).attr('data-default') || ''); });
    }

    function quClearInvalid() {
      $.each(QU_FIELDS, function (key) { quResetField(key); });
      $('#quLinesBody .form-control').each(function () { quResetLineControl($(this)); });
      $('#quLinesFb').prop('hidden', true);
    }

    function quMark(key, message) {
      if (message) { $(QU_FIELDS[key][1]).text(message); }
      $(QU_FIELDS[key][0]).addClass('is-invalid').trigger('focus');
    }

    function quMarkLine(n, key, message) {
      var $c = $('#quLinesBody tr.line-row').eq(n - 1).find(QU_LINE_KEYS[key]);
      if (!$c.length) { return false; }
      if (message) { $c.siblings('.invalid-feedback').text(message); }
      $c.addClass('is-invalid').trigger('focus');
      return true;
    }

    function quMarkLinesError(message) {
      $('#quLinesFb').text(message).prop('hidden', false);
    }

    /* Server field key: a header field, "lines", or "lines:N:livestock|quantity|sellingprice". */
    function quShowServerError(field, message) {
      if (field && QU_FIELDS[field]) { quMark(field, message); return; }
      var m = /^lines:(\d+):(livestock|quantity|sellingprice)$/.exec(field || '');
      if (m && quMarkLine(parseInt(m[1], 10), m[2], message)) { return; }
      if (field === 'lines' || m) { quMarkLinesError(message); }
    }

    /* ---------- Lines ---------- */
    function quItemOptions() {
      var h = '<option value="">Select livestock</option>';
      $.each(QL.livestock || [], function (i, r) { h += '<option value="' + Number(r.livestockid) + '">' + esc(r.name + ' (' + r.type + ')') + '</option>'; });
      return h;
    }

    function quLineHtml(seq) {
      return '<tr class="line-row" data-seq="' + seq + '">' +
        '<td><select class="form-control ql-livestock" aria-describedby="qlHelp' + seq + ' qlWarn' + seq + ' qlFb' + seq + '"></select>' +
          '<div class="helper-line line-help" id="qlHelp' + seq + '" aria-live="polite"></div>' +
          '<div class="line-warn" id="qlWarn' + seq + '" aria-live="polite"></div>' +
          '<div class="invalid-feedback" id="qlFb' + seq + '" data-default="Select a livestock item.">Select a livestock item.</div></td>' +
        '<td class="w-qty"><input type="number" class="form-control ql-qty" min="1" max="999999" step="1" inputmode="numeric" aria-describedby="qlQtyFb' + seq + '">' +
          '<div class="invalid-feedback" id="qlQtyFb' + seq + '" data-default="Quantity must be a whole number greater than 0.">Quantity must be a whole number greater than 0.</div></td>' +
        '<td class="w-price"><input type="number" class="form-control ql-selling" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="0" aria-describedby="qlSellingPriceFb' + seq + '">' +
          '<div class="invalid-feedback" id="qlSellingPriceFb' + seq + '" data-default="Selling price must be 0 or more, with up to 2 decimals.">Selling price must be 0 or more, with up to 2 decimals.</div></td>' +
        '<td class="w-total ln-total-cell"><output class="ql-total">$0.00</output></td>' +
        '<td class="w-rm text-center"><button type="button" class="btn btn-danger act-btn btn-remove" title="Remove line"><i class="fas fa-times" aria-hidden="true"></i></button></td>' +
        '</tr>';
    }

    function quRenumber() {
      var $rows = $('#quLinesBody tr.line-row');
      $rows.each(function (i) {
        var n = i + 1, $tr = $(this);
        $tr.find('.ql-livestock').attr('aria-label', 'Livestock, line ' + n);
        $tr.find('.ql-qty').attr('aria-label', 'Quantity, line ' + n);
        $tr.find('.ql-selling').attr('aria-label', 'Selling price, line ' + n);
        $tr.find('.ql-total').attr('aria-label', 'Line total, line ' + n);
        $tr.find('.btn-remove').attr('aria-label', 'Remove line ' + n).prop('disabled', $rows.length === 1);
      });
    }

    function quLineValues($tr) {
      return {
        item:  $tr.find('.ql-livestock').val() || '',
        qty:   $.trim($tr.find('.ql-qty').val()),
        price: $.trim($tr.find('.ql-selling').val())
      };
    }

    function quQtyOk(v) { return RE_QTY.test(v.qty) && /[1-9]/.test(v.qty); }

    function quLineTotal(v) {
      if (!quQtyOk(v) || !RE_PRICE.test(v.price)) { return null; }
      return BigInt(v.qty) * toMinor(v.price, 2);
    }

    // Selling price follows the selling price of the animal until the user types a price on that line
    function quApplyDefaultPrice($tr) {
      if ($tr.data('touched')) { return; }
      var a = QLIVE[String($tr.find('.ql-livestock').val() || '')];
      $tr.find('.ql-selling').val(a ? a.sellingprice : '0');
    }

    function quUpdateLine($tr) {
      var v = quLineValues($tr);
      var a = QLIVE[String(v.item)];
      $tr.find('.line-help').text(a ? 'Available: ' + fmtInt(a.currentstock) + ' animals' : '');
      // A quantity above the stock is only a warning: a quotation is not a sale.
      var warn = (a && quQtyOk(v) && Number(v.qty) > a.currentstock)
        ? 'More than the stock on hand. This is only a warning: a quotation does not change stock.'
        : '';
      $tr.find('.line-warn').text(warn);
      var t = quLineTotal(v);
      $tr.find('.ql-total').text(t === null ? '$0.00' : minorMoney(t));
    }

    function quRecalc() {
      var total = 0n;
      $('#quLinesBody tr.line-row').each(function () {
        var t = quLineTotal(quLineValues($(this)));
        if (t !== null) { total += t; }
      });
      $('#quGrandTotal').text(minorMoney(total));
    }

    function quAddLine(livestockId) {
      if ($('#quLinesBody tr.line-row').length >= CFG.maxLines) {
        toastr.warning('A quotation can have at most ' + CFG.maxLines + ' lines.');
        return null;
      }
      var seq = ++quSeq;
      var $tr = $(quLineHtml(seq)).appendTo('#quLinesBody');
      $tr.find('.ql-livestock').html(quItemOptions());
      if (livestockId && QLIVE[String(livestockId)]) {
        $tr.find('.ql-livestock').val(String(livestockId));
      }
      quApplyDefaultPrice($tr);
      quRenumber();
      quUpdateLine($tr);
      quRecalc();
      return $tr;
    }

    $('#btnAddQuoteLine').on('click', function () {
      $('#quLinesFb').prop('hidden', true);
      var $tr = quAddLine(0);
      if ($tr) { $tr.find('.ql-livestock').trigger('focus'); }
    });

    $('#quLinesBody').on('change', '.ql-livestock', function () {
      var $tr = $(this).closest('tr');
      quResetLineControl($(this));
      quResetLineControl($tr.find('.ql-qty'));
      quApplyDefaultPrice($tr);
      quResetLineControl($tr.find('.ql-selling'));
      quUpdateLine($tr);
      quRecalc();
    });
    $('#quLinesBody').on('input', '.ql-selling', function () {
      $(this).closest('tr').data('touched', true);
    });
    $('#quLinesBody').on('input', '.ql-qty, .ql-selling', function () {
      quResetLineControl($(this));
      quUpdateLine($(this).closest('tr'));
      quRecalc();
    });
    $('#quLinesBody').on('click', '.btn-remove', function () {
      $(this).closest('tr').remove();
      if (!$('#quLinesBody tr.line-row').length) { quAddLine(0); }
      quRenumber();
      quRecalc();
    });

    $('#quCustomer, #quDate, #quValid, #quRef, #quStatus, #quNote').on('input change', function () {
      var id = '#' + this.id;
      $.each(QU_FIELDS, function (key, pair) { if (pair[0] === id) { quResetField(key); } });
      if (id === '#quDate') { quResetField('validuntil'); }
    });
    $('#quotationModal').on('shown.bs.modal', function () { $('#quCustomer').trigger('focus'); });

    /* ---------- Open the modal ---------- */
    function quFillSelects() {
      var c = '<option value="">Select customer</option><option value="walkin">Walk-in</option>';
      $.each(QL.customers || [], function (i, r) { c += '<option value="' + Number(r.customerid) + '">' + esc(r.name) + '</option>'; });
      $('#quCustomer').html(c);
      indexQuotationLookups();
    }

    function showQuotationModal(livestockId) {
      $('#quotationForm')[0].reset();
      quClearInvalid();
      quFillSelects();
      quSaving = false;
      $('#quLinesBody').empty();
      quToggleWalkin();
      $('#quDate').val(ymd(new Date()));
      $('#quValid').val('');
      $('#quStatus').val('Draft');
      quAddLine(livestockId);
      quRenumber();
      quRecalc();
      $('#btnSaveQuotation').prop('disabled', false);
      $('#quotationModal').modal('show');
    }

    function openQuotationModal(livestockId) {
      if (quOpening) { return; }
      quOpening = true;
      api({ action: 'quotationlookups' }).done(function (res) {
        if (!res.success) { toastr.error(res.message); return; }
        QL = res.data;
        indexQuotationLookups();
        showQuotationModal(livestockId);
      }).always(function () { quOpening = false; });
    }

    $('#btnQuotation').on('click', function () { openQuotationModal(0); });
    $('#livestockTable tbody').on('click', '.btn-quote', function () { openQuotationModal(rowData(this).livestockid); });

    /* ---------- Client validation (the server repeats every rule) ---------- */
    function quValidate() {
      quClearInvalid();

      if (!$('#quCustomer').val()) { quMark('customerid', null); return false; }

      var date = $.trim($('#quDate').val());
      var real = /^\d{4}-\d{2}-\d{2}$/.test(date) && ymd(new Date(date + 'T00:00:00')) === date;
      if (!real) { quMark('quotationdate', null); return false; }

      var valid = $.trim($('#quValid').val());
      if (valid !== '') {
        var vReal = /^\d{4}-\d{2}-\d{2}$/.test(valid) && ymd(new Date(valid + 'T00:00:00')) === valid;
        if (!vReal) { quMark('validuntil', 'Valid until must be a valid date.'); return false; }
        if (valid < date) { quMark('validuntil', 'Valid until cannot be before the quotation date.'); return false; }
      }

      var rows = $('#quLinesBody tr.line-row');
      if (!rows.length) { quMarkLinesError('Add at least one line.'); return false; }
      if (rows.length > CFG.maxLines) { quMarkLinesError('A quotation can have at most ' + CFG.maxLines + ' lines.'); return false; }

      var total = 0n, ok = true;
      rows.each(function (i) {
        var n = i + 1, v = quLineValues($(this));
        if (!v.item || !QLIVE[String(v.item)]) { quMarkLine(n, 'livestock', null); ok = false; return false; }
        if (!quQtyOk(v)) { quMarkLine(n, 'quantity', null); ok = false; return false; }
        if (!RE_PRICE.test(v.price)) { quMarkLine(n, 'sellingprice', null); ok = false; return false; }
        var t = quLineTotal(v);
        if (t > MAX_TOTAL) { quMarkLine(n, 'quantity', 'The line total is too large.'); ok = false; return false; }
        total += t;
      });
      if (!ok) { return false; }
      if (total > MAX_TOTAL) { quMarkLinesError('The quotation total is too large.'); return false; }
      return true;
    }

    /* ---------- Save quotation ---------- */
    $('#quotationForm').on('submit', function (e) {
      e.preventDefault();
      if (quSaving || !quValidate()) { return; }

      var lines = [];
      $('#quLinesBody tr.line-row').each(function () {
        var v = quLineValues($(this));
        lines.push({ livestockid: v.item, quantity: v.qty, sellingprice: v.price });
      });

      quSaving = true;
      $('#btnSaveQuotation').prop('disabled', true);
      api({
        action: 'quotationsave',
        customerid: $('#quCustomer').val(),
        walkinname: ($('#quCustomer').val() === 'walkin') ? $.trim($('#quWalkinName').val()) : '',
        quotationdate: $.trim($('#quDate').val()),
        validuntil: $.trim($('#quValid').val()),
        reference: $.trim($('#quRef').val()),
        status: $('#quStatus').val(),
        note: $.trim($('#quNote').val()),
        lines: JSON.stringify(lines)
      }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#quotationModal').modal('hide');
          return;
        }
        quShowServerError(res.field, res.message);
        toastr.error(res.message);
      }).always(function () {
        quSaving = false;
        $('#btnSaveQuotation').prop('disabled', false);
      });
    });
</script>

<!-- ===================== Modal navigation, Sales list and Invoice JavaScript (BigInt money, no floats) ===================== -->
<script nonce="<?= $n ?>">
    /* ---------- Modal navigation: one modal at a time (Bootstrap 4 cannot stack), scroll lock and focus restored ---------- */
    var navigating = false;
    var lastFocus = null;

    // Hide the modal that is open, then show the target (the "hidden" handlers of the hidden modal skip while navigating).
    function gotoModal(toSel) {
      var $cur = $('.modal').filter(function () { var m = $(this).data('bs.modal'); return $(this).hasClass('show') || !!(m && m._isShown); }).not(toSel).first();
      if (!$cur.length) { $(toSel).modal('show'); return; }
      navigating = true;
      $cur.one('hidden.bs.modal', function () {
        $(toSel).modal('show');
        setTimeout(function () { navigating = false; }, 0);
      });
      $cur.modal('hide');
    }

    $(document).on('show.bs.modal', '.modal', function () {
      if (navigating || $('.modal.show').length) { return; }
      var ae = document.activeElement;
      if (ae && ae !== document.body && !$(ae).closest('.modal').length) { lastFocus = ae; }
    });
    $(document).on('hidden.bs.modal', '.modal', function () {
      if ($('.modal-backdrop').length || $('.modal.show').length) { $('body').addClass('modal-open'); return; }
      if (navigating) { return; }
      $('body').removeClass('modal-open').css('padding-right', '');
      if (lastFocus && document.body.contains(lastFocus)) { try { lastFocus.focus(); } catch (e) {} }
    });

    function moneyS(s) { return minorMoney(toMinorSigned(s)); }
    function minorPlain(m) {
      var neg = m < 0n, a = neg ? -m : m;
      return (neg ? '-' : '') + (a / 100n).toString() + '.' + (a % 100n).toString().padStart(2, '0');
    }
    function realDate(s) { return /^\d{4}-\d{2}-\d{2}$/.test(s) && ymd(new Date(s + 'T00:00:00')) === s; }

    // After a sale, return, refund or delete: fresh livestock table and lookups
    function afterChange() {
      table.ajax.reload(null, false);
      if (salesDT) { salesDT.ajax.reload(null, false); }
      if (typeof saRefreshLookups === 'function') { saRefreshLookups(); }
      if (typeof puRefreshLookups === 'function') { puRefreshLookups(); }
    }

    /* ---------- Sales list modal ---------- */
    var salesDT = null;

    function moneyCol(key) {
      return {
        data: key, className: 'text-right text-nowrap',
        render: function (d, type) { return (type === 'sort' || type === 'type') ? Number(d) : moneyS(d); }
      };
    }

    function salesActions(row) {
      var n = Number(row.invoiceid);
      var noRet = Number(row.returnable) <= 0;
      var noRef = toMinorSigned(row.refundable) <= 0n;
      return '<button type="button" class="btn btn-info act-btn btn-sv-view" data-id="' + n + '" title="View / Print invoice" aria-label="View invoice #' + n + '"><i class="fas fa-file-invoice" aria-hidden="true"></i></button>' +
             '<button type="button" class="btn btn-secondary act-btn btn-sv-edit" data-id="' + n + '" title="Edit sale" aria-label="Edit invoice #' + n + '"><i class="fas fa-edit" aria-hidden="true"></i></button>' +
             '<button type="button" class="btn btn-primary act-btn btn-sv-return" data-id="' + n + '"' + (noRet ? ' disabled' : '') + ' title="' + (noRet ? 'Nothing to return' : 'Make return') + '" aria-label="Make return for invoice #' + n + '"><i class="fas fa-undo" aria-hidden="true"></i></button>' +
             '<button type="button" class="btn btn-success act-btn btn-sv-refund" data-id="' + n + '"' + (noRef ? ' disabled' : '') + ' title="' + (noRef ? 'Nothing to refund' : 'Make refund') + '" aria-label="Make refund for invoice #' + n + '"><i class="fas fa-hand-holding-usd" aria-hidden="true"></i></button>' +
             '<button type="button" class="btn btn-danger act-btn btn-sv-del" data-id="' + n + '" title="Delete invoice" aria-label="Delete invoice #' + n + '"><i class="fas fa-trash" aria-hidden="true"></i></button>';
    }
    function salesActionsOld(row) {
      var n = Number(row.invoiceid);
      var noRet = Number(row.returnable) <= 0;
      var noRef = toMinorSigned(row.refundable) <= 0n;
      return '<button type="button" class="btn btn-info act-btn btn-sv-view" data-id="' + n + '" title="View / Print invoice" aria-label="View invoice #' + n + '"><i class="fas fa-file-invoice" aria-hidden="true"></i></button> ' +
             '<button type="button" class="btn btn-primary act-btn btn-sv-return" data-id="' + n + '"' + (noRet ? ' disabled' : '') + ' title="' + (noRet ? 'Nothing to return' : 'Make return') + '" aria-label="Make return for invoice #' + n + '"><i class="fas fa-undo" aria-hidden="true"></i></button> ' +
             '<button type="button" class="btn btn-success act-btn btn-sv-refund" data-id="' + n + '"' + (noRef ? ' disabled' : '') + ' title="' + (noRef ? 'Nothing to refund' : 'Make refund') + '" aria-label="Make refund for invoice #' + n + '"><i class="fas fa-hand-holding-usd" aria-hidden="true"></i></button> ' +
             '<button type="button" class="btn btn-danger act-btn btn-sv-del" data-id="' + n + '" title="Delete invoice" aria-label="Delete invoice #' + n + '"><i class="fas fa-trash" aria-hidden="true"></i></button>';
    }

    function initSalesTable() {
      var exp = { columns: [0, 1, 2, 3, 4, 5, 6, 7, 8], orthogonal: 'export' };
      salesDT = $('#salesTable').DataTable({
        ajax: {
          url: URL_SELF, type: 'POST',
          data: function (d) { d.action = 'saleslist'; d.csrf_token = CSRF; },
          cache: false,
          dataSrc: 'data',
          error: function (xhr) { if (window.console) { console.warn('sales list request failed (HTTP ' + (xhr && xhr.status) + ')'); toastr.error('The sales list could not be loaded (HTTP ' + (xhr && xhr.status) + ').'); } }
        },
        columns: [
          { data: 'invoiceid' },
          { data: 'invoicedate', className: 'text-nowrap' },
          { data: 'customername', render: function (d, type) { return type === 'display' ? esc(d) : d; } },
          { data: 'reference', render: function (d, type) { return type === 'display' ? esc(d) : d; } },
          moneyCol('total'), moneyCol('returned'), moneyCol('refunded'), moneyCol('paid'),
          {
            data: 'balance', className: 'text-right text-nowrap',
            render: function (d, type, row) {
              var credit = toMinorSigned(row.credit) > 0n;
              var text = credit ? 'Credit ' + moneyS(row.credit) : moneyS(d);
              if (type === 'sort' || type === 'type') { return credit ? -Number(row.credit) : Number(d); }
              if (type !== 'display') { return text + ' (' + row.status + ')'; }
              var cls = row.status === 'Paid' ? 'st-active' : (row.status === 'Partial' ? 'st-soon' : 'st-expired');
              return '<span class="badge ' + cls + '">' + esc(row.status) + '</span> ' + esc(text);
            }
          },
          { data: null, orderable: false, searchable: false, className: 'text-center text-nowrap', render: function (d, type, row) { return salesActions(row); } }
        ],
        order: [[0, 'desc']],
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        dom: "<'dt-top'<'dt-len'l><'dt-btns'B><'dt-find'f>>" +
             "<'table-scroll'tr>" +
             "<'row mt-2'<'col-md-5'i><'col-md-7'p>>",
        buttons: [
          { extend: 'copyHtml5',  className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-copy"></i> Copy',        title: 'Sales', exportOptions: exp },
          { extend: 'csvHtml5',   className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-csv"></i> CSV',     filename: 'Sales', exportOptions: exp },
          { extend: 'excelHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-excel"></i> Excel', title: 'Sales', filename: 'Sales', exportOptions: exp },
          { extend: 'pdfHtml5',   className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-pdf"></i> PDF',     title: 'Sales', filename: 'Sales', orientation: 'landscape', exportOptions: exp },
          { extend: 'print',      className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-print"></i> Print',      title: 'Sales', exportOptions: exp }
        ],
        language: { search: 'Search:', searchPlaceholder: 'Search sales...', emptyTable: 'No sales found' }
      });
    }

    $('#btnSales').on('click', function () { $('#salesModal').modal('show'); });
    $('#salesModal').on('shown.bs.modal', function () {
      if (!salesDT) { initSalesTable(); return; }
      salesDT.ajax.reload(null, false);
      salesDT.columns.adjust();
    });

    function salesRow(el) { return salesDT.row($(el).closest('tr')).data(); }

    $('#salesTable tbody').on('click', '.btn-sv-view', function () { openInvoice(Number($(this).attr('data-id')), '#salesModal'); });
    $('#salesTable tbody').on('click', '.btn-sv-edit', function () { openSaleEdit(Number($(this).attr('data-id')), '#salesModal'); });
    $('#salesTable tbody').on('click', '.btn-sv-return', function () { openReturn(Number($(this).attr('data-id')), '#salesModal'); });
    $('#salesTable tbody').on('click', '.btn-sv-refund', function () { openRefund(Number($(this).attr('data-id')), '#salesModal'); });
    $('#salesTable tbody').on('click', '.btn-sv-del', function () {
      var d = salesRow(this);
      askInvoiceDelete(Number(d.invoiceid), d.customername, '#salesModal');
    });

    /* ---------- Invoice modal ---------- */
    var INV = { id: 0, data: null };
    var INVBACK = '';
    var invLoading = false;
    var invDT = null;

    function invTitleText() {
      var i = INV.data.invoice;
      return 'Invoice #' + i.invoiceid + ' - ' + CFG.farm;
    }
    function invHeaderText() {
      var i = INV.data.invoice;
      return 'Invoice #' + i.invoiceid + ' | Customer: ' + i.customername + ' | Date: ' + i.invoicedate + (i.reference ? ' | Reference: ' + i.reference : '');
    }
    function invTotalsText() {
      var i = INV.data.invoice;
      var bal = toMinorSigned(i.credit) > 0n ? 'Credit ' + moneyS(i.credit) : 'Balance due ' + moneyS(i.balance);
      return 'Total ' + moneyS(i.total) + ' | Returned ' + moneyS(i.returned) + ' | Net total ' + moneyS(i.net) +
             ' | Paid ' + moneyS(i.paid) + ' | Refunded ' + moneyS(i.refunded) + ' | ' + bal + ' (' + i.status + ')';
    }

    function printInvoice() {
      var done = function () {
        $('body').removeClass('invoice-open');
        window.removeEventListener('afterprint', done);
      };
      window.addEventListener('afterprint', done);
      $('body').addClass('invoice-open');
      window.print();
    }

    /* ---------- PDF watermark: logo converted once to a data URL (same origin canvas), text fallback ---------- */
    var WM = { url: '', ratio: 1, started: false };

    function loadWatermark() {
      if (WM.started || !CFG.logo) { return; }
      WM.started = true;
      var img = new Image();
      img.onload = function () {
        try {
          if (!img.naturalWidth || !img.naturalHeight) { return; }
          var c = document.createElement('canvas');
          c.width = img.naturalWidth;
          c.height = img.naturalHeight;
          c.getContext('2d').drawImage(img, 0, 0);
          WM.url = c.toDataURL('image/png');
          WM.ratio = img.naturalHeight / img.naturalWidth;
        } catch (e) { WM.url = ''; }
      };
      img.onerror = function () { WM.url = ''; };
      img.src = CFG.logo;
    }

    function invoicePdfWatermark(doc) {
      doc.background = function (page, size) {
        if (WM.url) {
          var w = size.width * 0.6, h = w * WM.ratio;
          return { image: WM.url, width: w, opacity: 0.08, absolutePosition: { x: (size.width - w) / 2, y: (size.height - h) / 2 } };
        }
        return { text: CFG.farm, alignment: 'center', bold: true, fontSize: 48, color: '#9d1d22', opacity: 0.08, margin: [40, size.height / 2 - 30, 40, 0] };
      };
    }

    function initInvoiceTable() {
      var exp = { columns: ':visible' };
      invDT = $('#invLinesTable').DataTable({
        data: [], paging: false, searching: false, info: false, ordering: false, autoWidth: false,
        dom: 'Bt',
        columns: [
          { data: 'n' },
          { data: 'name', render: function (d, type) { return type === 'display' ? esc(d) : d; } },
          { data: 'type', render: function (d, type) { return type === 'display' ? esc(d) : d; } },
          { data: 'quantity', className: 'text-right', render: function (d, type) { return type === 'display' ? fmtInt(d) : d; } },
          { data: 'sellingprice', className: 'text-right', render: function (d) { return moneyS(d); } },
          { data: 'linetotal', className: 'text-right', render: function (d) { return moneyS(d); } }
        ],
        buttons: [
          { extend: 'copyHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-copy"></i> Copy', exportOptions: exp,
            title: function () { return invTitleText(); },
            messageTop: function () { return invHeaderText(); },
            messageBottom: function () { return invTotalsText(); } },
          { extend: 'csvHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-csv"></i> CSV', exportOptions: exp,
            filename: function () { return 'Invoice-' + INV.id; },
            customize: function (csv) {
              var q = function (t) { return '"' + String(t).replace(/"/g, '""') + '"'; };
              return q(invTitleText()) + '\n' + q(invHeaderText()) + '\n' + csv + '\n' + q(invTotalsText());
            } },
          { extend: 'excelHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-excel"></i> Excel', exportOptions: exp,
            filename: function () { return 'Invoice-' + INV.id; },
            title: function () { return invTitleText(); },
            messageTop: function () { return invHeaderText(); },
            messageBottom: function () { return invTotalsText(); } },
          { extend: 'pdfHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-pdf"></i> PDF', exportOptions: exp,
            customize: function (doc) { invoicePdfWatermark(doc); },
            filename: function () { return 'Invoice-' + INV.id; },
            title: function () { return invTitleText(); },
            messageTop: function () { return invHeaderText(); },
            messageBottom: function () { return invTotalsText(); } },
          { text: '<i class="fas fa-print"></i> Print', className: 'btn btn-secondary btn-sm', action: function () { printInvoice(); } }
        ],
        language: { emptyTable: 'No lines' }
      });
      invDT.buttons().container().appendTo('#invBtns');
    }

    function renderInvoice(data) {
      var inv = data.invoice;
      INV.id = inv.invoiceid;
      INV.data = data;

      $('#invoiceModalTitle').text('Invoice #' + inv.invoiceid);
      $('#invFarm').text(CFG.farm);
      if (CFG.logo) {
        $('#invLogo').attr('src', CFG.logo).prop('hidden', false);
        $('#invWatermarkText').prop('hidden', true);
        $('#invWatermark').off('error').on('error', function () {
          $(this).prop('hidden', true);
          $('#invWatermarkText').text(CFG.farm).prop('hidden', false);
        }).attr('src', CFG.logo).prop('hidden', false);
        loadWatermark();
      } else {
        $('#invWatermark').prop('hidden', true);
        $('#invWatermarkText').text(CFG.farm).prop('hidden', false);
      }
      $('#invNo').text(inv.invoiceid);
      $('#invDate').text(inv.invoicedate);
      $('#invCustomer').text(inv.customername);
      $('#invRef').text(inv.reference || '-');
      $('#invContact').text(inv.primarycontact || '-');
      $('#invAddress').text(inv.address || '-');
      $('#invNote').text(inv.note || '-');

      if (!invDT) { initInvoiceTable(); }
      var rows = $.map(data.lines, function (l, i) {
        return { n: i + 1, name: l.name, type: l.type, quantity: l.quantity, sellingprice: l.sellingprice, linetotal: l.linetotal };
      });
      invDT.clear().rows.add(rows).draw(false);

      var credit = toMinorSigned(inv.credit) > 0n;
      $('#invTotal').text(moneyS(inv.total));
      $('#invReturned').text(moneyS(inv.returned));
      $('#invNet').text(moneyS(inv.net));
      $('#invPaid').text(moneyS(inv.paid));
      $('#invRefunded').text(moneyS(inv.refunded));
      $('#invBalLabel').text(credit ? 'Credit' : 'Balance due');
      $('#invBalance').text(moneyS(credit ? inv.credit : inv.balance));
      $('#invStatus').text(inv.status)
        .removeClass('st-active st-soon st-expired')
        .addClass(inv.status === 'Paid' ? 'st-active' : (inv.status === 'Partial' ? 'st-soon' : 'st-expired'));

      var h = '';
      $.each(data.returns, function (i, r) {
        var items = $.map(r.items, function (x) { return fmtInt(x.quantity) + ' x ' + esc(x.name); }).join(', ');
        h += '<li><strong>Return #' + Number(r.returnid) + '</strong> on ' + esc(r.returndate) + ': ' + items + ' (value ' + esc(moneyS(r.totalamount)) + ')' + (r.note ? ' - ' + esc(r.note) : '') + '</li>';
      });
      $.each(data.refunds, function (i, r) {
        h += '<li><strong>Refund #' + Number(r.refundid) + '</strong> on ' + esc(r.refunddate) + ': ' + esc(moneyS(r.amount)) +
             (r.account ? ' from ' + esc(r.account) : '') + (Number(r.returnid) > 0 ? ' (return #' + Number(r.returnid) + ')' : '') + (r.note ? ' - ' + esc(r.note) : '') + '</li>';
      });
      $('#invHistory').html(h || '<li>No returns or refunds.</li>');

      var noRet = Number(inv.returnable) <= 0;
      var paid = toMinorSigned(inv.paid);
      var noRef = toMinorSigned(inv.refundable) <= 0n;
      $('#btnInvReturn').prop('disabled', noRet);
      $('#btnInvRefund').prop('disabled', noRef);
      var help = [];
      if (noRet) { help.push('Nothing can be returned: every animal of this invoice was already returned.'); }
      if (noRef) { help.push(paid <= 0n ? 'Nothing can be refunded: nothing was paid on this invoice.' : 'Nothing can be refunded: the paid amount was already refunded.'); }
      $('#invActionHelp').text(help.join(' '));
    }

    function openInvoice(id, back) {
      if (invLoading) { return; }
      invLoading = true;
      api({ action: 'saleget', invoiceid: id }).done(function (res) {
        if (!res.success) { toastr.error(res.message); return; }
        INVBACK = back || '';
        renderInvoice(res.data);
        gotoModal('#invoiceModal');
      }).always(function () { invLoading = false; });
    }

    $('#invoiceModal').on('hidden.bs.modal', function () {
      if (navigating) { return; }
      $('body').removeClass('invoice-open');
      var b = INVBACK;
      INVBACK = '';
      if (b) { $(b).modal('show'); }
    });
    $('#btnInvEdit').on('click', function () { openSaleEdit(INV.id, '#invoiceModal'); });
    $('#invoiceModal').on('show.bs.modal', function () { $('#btnInvEdit').attr('aria-label', 'Edit invoice #' + INV.id); });
    $('#btnInvReturn').on('click', function () { openReturn(INV.id, '#invoiceModal'); });
    $('#btnInvRefund').on('click', function () { openRefund(INV.id, '#invoiceModal'); });
    $('#btnInvDelete').on('click', function () { askInvoiceDelete(INV.id, INV.data ? INV.data.invoice.customername : '', '#invoiceModal'); });

    /* ---------- Delete invoice ---------- */
    var delInv = { id: 0, back: '', done: false, busy: false };

    function askInvoiceDelete(id, name, back) {
      delInv = { id: id, back: back || '', done: false, busy: false };
      $('#idInvNo').text('#' + id);
      $('#idInvCustomer').text(name || '');
      gotoModal('#invDeleteModal');
    }

    $('#invDeleteModal').on('hidden.bs.modal', function () {
      if (navigating) { return; }
      var t = delInv.back;
      if (delInv.done && t === '#invoiceModal') { t = INVBACK; INVBACK = ''; }
      delInv.id = 0;
      if (t) { $(t).modal('show'); }
    });

    $('#btnConfirmInvDelete').on('click', function () {
      if (delInv.busy || !delInv.id) { return; }
      delInv.busy = true;
      var $btn = $(this).prop('disabled', true);
      api({ action: 'invoicedelete', invoiceid: delInv.id }).done(function (res) {
        if (res.success) {
          delInv.done = true;
          toastr.success(res.message);
          afterChange();
        } else if (res.blocked) {
          toastr.warning(res.message);
        } else {
          toastr.error(res.message);
        }
        $('#invDeleteModal').modal('hide');
      }).fail(function () {
        $('#invDeleteModal').modal('hide');
      }).always(function () {
        delInv.busy = false;
        $btn.prop('disabled', false);
      });
    });
</script>

<!-- ===================== Make Return JavaScript (RL / RINV, BigInt money, no floats) ===================== -->
<script nonce="<?= $n ?>">
    var RL = { lines: [] };   // livestock lines of the invoice
    var RINV = null;          // invoice payload (see dt_sale_row)
    var reBack = '';
    var reSaving = false;
    var reOpening = false;

    var RE_FIELDS = {
      returndate: ['#reDate', '#reDateFb'],
      note:       ['#reNote', '#reNoteFb']
    };
    var RE_DEFAULTS = {};
    $.each(RE_FIELDS, function (key, pair) { RE_DEFAULTS[key] = $(pair[1]).text(); });

    function reResetField(key) {
      $(RE_FIELDS[key][0]).removeClass('is-invalid');
      $(RE_FIELDS[key][1]).text(RE_DEFAULTS[key]);
    }
    function reResetLine($c) {
      $c.removeClass('is-invalid');
      $c.siblings('.invalid-feedback').each(function () { $(this).text($(this).attr('data-default') || ''); });
    }
    function reClearInvalid() {
      $.each(RE_FIELDS, function (key) { reResetField(key); });
      $('#reLinesBody .re-qty').each(function () { reResetLine($(this)); });
      $('#reLinesFb').prop('hidden', true);
    }
    function reMark(key, message) {
      if (message) { $(RE_FIELDS[key][1]).text(message); }
      $(RE_FIELDS[key][0]).addClass('is-invalid').trigger('focus');
    }
    function reMarkLine(n, message) {
      var $c = $('#reLinesBody tr.line-row').eq(n - 1).find('.re-qty');
      if (!$c.length) { return false; }
      if (message) { $c.siblings('.invalid-feedback').text(message); }
      $c.addClass('is-invalid').trigger('focus');
      return true;
    }
    function reShowServerError(field, message) {
      if (field && RE_FIELDS[field]) { reMark(field, message); return; }
      var m = /^lines:(\d+):quantity$/.exec(field || '');
      if (m && reMarkLine(parseInt(m[1], 10), message)) { return; }
      if (field === 'lines' || m) { $('#reLinesFb').text(message).prop('hidden', false); }
    }

    function reLineHtml(l, n) {
      var max = Number(l.returnable);
      return '<tr class="line-row" data-itemid="' + Number(l.invoiceitemid) + '" data-price="' + esc(l.sellingprice) + '" data-max="' + max + '">' +
        '<td>' + esc(l.name + ' (' + l.type + ')') + '</td>' +
        '<td class="num-cell">' + fmtInt(l.quantity) + '</td>' +
        '<td class="num-cell">' + fmtInt(l.returnedqty) + '</td>' +
        '<td class="num-cell">' + fmtInt(max) + '</td>' +
        '<td class="w-qty"><input type="number" class="form-control re-qty" min="0" max="' + max + '" step="1" inputmode="numeric" value="0"' + (max <= 0 ? ' disabled' : '') + ' aria-describedby="reQtyFb' + n + '">' +
          '<div class="invalid-feedback" id="reQtyFb' + n + '" data-default="Quantity must be a whole number from 0 to ' + max + '.">Quantity must be a whole number from 0 to ' + max + '.</div></td>' +
        '<td class="w-price num-cell">' + esc(moneyS(l.sellingprice)) + '</td>' +
        '<td class="w-total ln-total-cell"><output class="re-total">$0.00</output></td>' +
        '</tr>';
    }

    function reRecalc() {
      var total = 0n;
      $('#reLinesBody tr.line-row').each(function () {
        var $tr = $(this);
        var q = $.trim($tr.find('.re-qty').val());
        var t = 0n;
        if (RE_QTY.test(q)) { t = BigInt(q) * toMinor($tr.attr('data-price'), 2); }
        $tr.find('.re-total').text(minorMoney(t));
        total += t;
      });
      $('#reFootReturn').text(minorMoney(total));
      $('#reFootNet').text(minorMoney(toMinorSigned(RINV.net) - total));
    }

    $('#reLinesBody').on('input', '.re-qty', function () {
      reResetLine($(this));
      $('#reLinesFb').prop('hidden', true);
      reRecalc();
    });
    $('#reDate, #reNote').on('input change', function () {
      var id = '#' + this.id;
      $.each(RE_FIELDS, function (key, pair) { if (pair[0] === id) { reResetField(key); } });
    });

    function openReturn(invoiceId, back) {
      if (reOpening) { return; }
      reOpening = true;
      api({ action: 'saleget', invoiceid: invoiceId }).done(function (res) {
        if (!res.success) { toastr.error(res.message); return; }
        var d = res.data;
        if (Number(d.invoice.returnable) <= 0) { toastr.warning('Nothing can be returned: every animal of this invoice was already returned.'); return; }
        RINV = d.invoice;
        RL.lines = $.grep(d.lines, function (l) { return l.itemtype === 'Livestock'; });
        reBack = back || '';
        reSaving = false;

        $('#returnForm')[0].reset();
        reClearInvalid();
        $('#reInfo').text('Invoice #' + RINV.invoiceid + ' of ' + RINV.customername + ' (' + RINV.invoicedate + '). Net total ' + moneyS(RINV.net) + '.');
        var today = ymd(new Date());
        $('#reDate').attr('min', RINV.invoicedate).val(today < RINV.invoicedate ? RINV.invoicedate : today);
        var h = '';
        $.each(RL.lines, function (i, l) { h += reLineHtml(l, i + 1); });
        $('#reLinesBody').html(h);
        $('#btnSaveReturn').prop('disabled', false);
        reRecalc();
        gotoModal('#returnModal');
      }).always(function () { reOpening = false; });
    }

    $('#returnModal').on('shown.bs.modal', function () { $('#reLinesBody .re-qty:enabled').first().trigger('focus'); });
    $('#returnModal').on('hidden.bs.modal', function () {
      if (navigating) { return; }
      var b = reBack;
      reBack = '';
      if (b) { $(b).modal('show'); }
    });

    /* ---------- Client validation (the server repeats every rule) ---------- */
    function reValidate() {
      reClearInvalid();
      var date = $.trim($('#reDate').val());
      if (!realDate(date) || date < RINV.invoicedate) { reMark('returndate', null); return false; }
      var any = false, ok = true;
      $('#reLinesBody tr.line-row').each(function (i) {
        var $tr = $(this), q = $.trim($tr.find('.re-qty').val()), max = Number($tr.attr('data-max'));
        if (!RE_QTY.test(q) || Number(q) > max) { reMarkLine(i + 1, null); ok = false; return false; }
        if (Number(q) > 0) { any = true; }
      });
      if (!ok) { return false; }
      if (!any) { $('#reLinesFb').text('Enter a quantity to return on at least one line.').prop('hidden', false); return false; }
      return true;
    }

    /* ---------- Save return: afterwards go back to the invoice or the sales list, refreshed ---------- */
    $('#returnForm').on('submit', function (e) {
      e.preventDefault();
      if (reSaving || !reValidate()) { return; }
      var lines = [];
      $('#reLinesBody tr.line-row').each(function () {
        lines.push({ invoiceitemid: $(this).attr('data-itemid'), quantity: $.trim($(this).find('.re-qty').val()) });
      });
      var ok = false;
      reSaving = true;
      $('#btnSaveReturn').prop('disabled', true);
      api({
        action: 'returnsave',
        invoiceid: RINV.invoiceid,
        returndate: $.trim($('#reDate').val()),
        note: $.trim($('#reNote').val()),
        lines: JSON.stringify(lines)
      }).done(function (res) {
        if (res.success) {
          ok = true;
          toastr.success(res.message);
          afterChange();
          var b = reBack;
          reBack = '';
          if (b === '#invoiceModal') { openInvoice(RINV.invoiceid, INVBACK); } else if (b) { gotoModal(b); } else { $('#returnModal').modal('hide'); }
          return;
        }
        reShowServerError(res.field, res.message);
        toastr.error(res.message);
      }).always(function () {
        if (!ok) { reSaving = false; $('#btnSaveReturn').prop('disabled', false); }
      });
    });
</script>

<!-- ===================== Make Refund JavaScript (RFD, BigInt money, no floats) ===================== -->
<script nonce="<?= $n ?>">
    var RFD = { invoice: null, returns: [], accounts: [] };
    var RFACC = {};
    var rfBack = '';
    var rfSaving = false;
    var rfOpening = false;
    var rfBlocked = false;
    var rfFlagged = false;

    var RF_FIELDS = {
      refunddate: ['#rfDate',    '#rfDateFb'],
      returnid:   ['#rfReturn',  '#rfReturnFb'],
      amount:     ['#rfAmount',  '#rfAmountFb'],
      accountid:  ['#rfAccount', '#rfAccountFb'],
      note:       ['#rfNote',    '#rfNoteFb']
    };
    var RF_DEFAULTS = {};
    $.each(RF_FIELDS, function (key, pair) { RF_DEFAULTS[key] = $(pair[1]).text(); });

    function rfResetField(key) {
      $(RF_FIELDS[key][0]).removeClass('is-invalid');
      $(RF_FIELDS[key][1]).text(RF_DEFAULTS[key]);
    }
    function rfClearInvalid() {
      $.each(RF_FIELDS, function (key) { rfResetField(key); });
      rfFlagged = false;
    }
    function rfMark(key, message) {
      if (message) { $(RF_FIELDS[key][1]).text(message); }
      $(RF_FIELDS[key][0]).addClass('is-invalid').trigger('focus');
    }
    function rfShowServerError(field, message) {
      if (field && RF_FIELDS[field]) { rfMark(field, message); }
    }

    // Message for an amount that breaks a rule the browser can check (empty text when the amount is fine)
    function rfAmountMessage(amount) {
      if (amount <= 0n) { return ''; }
      var refundable = toMinorSigned(RFD.invoice.refundable);
      if (amount > refundable) { return 'Refund cannot be more than ' + minorMoney(refundable) + ' (paid minus refunds already made).'; }
      var acc = String($('#rfAccount').val() || '');
      if (acc && RFACC[acc] && amount > RFACC[acc].balance) {
        return 'Insufficient balance in ' + RFACC[acc].label + ': available ' + minorMoney(RFACC[acc].balance) + ', required ' + minorMoney(amount) + '.';
      }
      return '';
    }

    function rfRecalc() {
      var raw = $.trim($('#rfAmount').val());
      var amount = RE_PAID.test(raw) ? toMinor(raw, 2) : 0n;
      var refundable = toMinorSigned(RFD.invoice.refundable);
      var left = refundable - amount;
      $('#rfFootAmount').text(minorMoney(amount));
      $('#rfFootLeft').text(minorMoney(left < 0n ? 0n : left));
      $('#rfAmountHelp').text('Left to refund: ' + minorMoney(refundable) + ' (paid minus refunds already made).');

      var acc = String($('#rfAccount').val() || '');
      $('#rfAccountHelp').text(acc && RFACC[acc] ? 'Available balance: ' + minorMoney(RFACC[acc].balance) : '');

      var msg = rfAmountMessage(amount);
      rfBlocked = msg !== '';
      if (msg) {
        $('#rfAmount').addClass('is-invalid');
        $('#rfAmountFb').text(msg);
      } else if (rfFlagged) {
        $('#rfAmount').removeClass('is-invalid');
        $('#rfAmountFb').text(RF_DEFAULTS.amount);
      }
      rfFlagged = rfBlocked;
      $('#btnSaveRefund').prop('disabled', rfBlocked || rfSaving);
    }

    $('#rfDate, #rfNote').on('input change', function () {
      var id = '#' + this.id;
      $.each(RF_FIELDS, function (key, pair) { if (pair[0] === id) { rfResetField(key); } });
    });
    $('#rfReturn').on('change', function () { rfResetField('returnid'); });
    $('#rfAmount').on('input', function () { rfResetField('amount'); rfRecalc(); });
    $('#rfAccount').on('change', function () { rfResetField('accountid'); rfRecalc(); });
    $('#refundModal').on('shown.bs.modal', function () { $('#rfAmount').trigger('focus'); });
    $('#refundModal').on('hidden.bs.modal', function () {
      if (navigating) { return; }
      var b = rfBack;
      rfBack = '';
      if (b) { $(b).modal('show'); }
    });

    /* ---------- Open the modal ---------- */
    function openRefund(invoiceId, back) {
      if (rfOpening) { return; }
      rfOpening = true;
      api({ action: 'refundlookups', invoiceid: invoiceId }).done(function (res) {
        if (!res.success) { toastr.error(res.message); return; }
        RFD = res.data;
        if (toMinorSigned(RFD.invoice.refundable) <= 0n) { toastr.warning('Nothing can be refunded on this invoice.'); return; }
        rfBack = back || '';
        rfSaving = false;
        RFACC = {};
        $.each(RFD.accounts, function (i, r) { RFACC[String(r.accountid)] = { balance: toMinorSigned(r.balance), label: r.code + ' ' + r.accountname }; });

        $('#refundForm')[0].reset();
        rfClearInvalid();
        $('#rfInfo').text('Invoice #' + RFD.invoice.invoiceid + ' of ' + RFD.invoice.customername + ' (' + RFD.invoice.invoicedate + '). Paid ' + moneyS(RFD.invoice.paid) + ', refunded ' + moneyS(RFD.invoice.refunded) + '.');
        var today = ymd(new Date());
        $('#rfDate').attr('min', RFD.invoice.invoicedate).val(today < RFD.invoice.invoicedate ? RFD.invoice.invoicedate : today);

        var r = '<option value="0">No return</option>';
        $.each(RFD.returns, function (i, x) { r += '<option value="' + Number(x.returnid) + '">' + esc('Return #' + x.returnid + ' (' + x.returndate + ', ' + moneyS(x.totalamount) + ')') + '</option>'; });
        $('#rfReturn').html(r);
        var a = '<option value="">Select account</option>';
        $.each(RFD.accounts, function (i, x) { a += '<option value="' + Number(x.accountid) + '">' + esc(x.code + ' ' + x.accountname) + '</option>'; });
        $('#rfAccount').html(a);

        rfRecalc();
        gotoModal('#refundModal');
      }).always(function () { rfOpening = false; });
    }

    /* ---------- Client validation (the server repeats every rule) ---------- */
    function rfValidate() {
      rfClearInvalid();
      var date = $.trim($('#rfDate').val());
      if (!realDate(date) || date < RFD.invoice.invoicedate) { rfMark('refunddate', null); return false; }
      var raw = $.trim($('#rfAmount').val());
      if (!RE_PAID.test(raw) || toMinor(raw, 2) <= 0n) { rfMark('amount', null); return false; }
      if (!$('#rfAccount').val()) { rfMark('accountid', null); return false; }
      var msg = rfAmountMessage(toMinor(raw, 2));
      if (msg) { rfFlagged = true; rfMark('amount', msg); return false; }
      return true;
    }

    /* ---------- Save refund: afterwards go back to the invoice or the sales list, refreshed ---------- */
    $('#refundForm').on('submit', function (e) {
      e.preventDefault();
      if (rfSaving || !rfValidate()) { return; }
      var ok = false;
      rfSaving = true;
      $('#btnSaveRefund').prop('disabled', true);
      api({
        action: 'refundsave',
        invoiceid: RFD.invoice.invoiceid,
        refunddate: $.trim($('#rfDate').val()),
        returnid: $('#rfReturn').val() || 0,
        amount: $.trim($('#rfAmount').val()),
        accountid: $('#rfAccount').val() || 0,
        note: $.trim($('#rfNote').val())
      }).done(function (res) {
        if (res.success) {
          ok = true;
          toastr.success(res.message);
          afterChange();
          var b = rfBack;
          rfBack = '';
          if (b === '#invoiceModal') { openInvoice(RFD.invoice.invoiceid, INVBACK); } else if (b) { gotoModal(b); } else { $('#refundModal').modal('hide'); }
          return;
        }
        if (res.field === 'amount' && /^Insufficient/.test(res.message || '')) { rfFlagged = true; }
        rfShowServerError(res.field, res.message);
        toastr.error(res.message);
      }).always(function () {
        if (!ok) { rfSaving = false; rfRecalc(); }
      });
    });
</script>

<!-- ===================== Purchases: None supplier, edit, list, view, delete (BigInt money, no floats) ===================== -->
<script nonce="<?= $n ?>">
    var puEditId = 0, puBack = '', puManual = false, PVBACK = '';
    var NONE_NAME = <?= json_encode(BF_NO_SUPPLIER_NAME, $jsonFlags) ?>;

    /* ---------- Shared helpers for the quotation and purchase view modals ---------- */
    function printDoc(cls) {
      var done = function () {
        $('body').removeClass(cls);
        window.removeEventListener('afterprint', done);
      };
      window.addEventListener('afterprint', done);
      $('body').addClass(cls);
      window.print();
    }

    // Logo, farm name and watermark of a view modal (px = "qv" or "pv"), same behavior as the invoice
    function docBrand(px) {
      $('#' + px + 'Farm').text(CFG.farm);
      if (CFG.logo) {
        $('#' + px + 'Logo').attr('src', CFG.logo).prop('hidden', false);
        $('#' + px + 'WatermarkText').prop('hidden', true);
        $('#' + px + 'Watermark').off('error').on('error', function () {
          $(this).prop('hidden', true);
          $('#' + px + 'WatermarkText').text(CFG.farm).prop('hidden', false);
        }).attr('src', CFG.logo).prop('hidden', false);
        loadWatermark();
      } else {
        $('#' + px + 'Watermark').prop('hidden', true);
        $('#' + px + 'WatermarkText').text(CFG.farm).prop('hidden', false);
      }
    }

    // Lines table with the 5 export buttons; ctx = { title, top, bottom, file, printClass } (functions return text)
    function initDocLines(sel, btns, ctx) {
      var exp = { columns: ':visible' };
      var priceKey = ctx.priceKey || 'sellingprice', totalKey = ctx.totalKey || 'linetotal';
      var dt = $(sel).DataTable({
        data: [], paging: false, searching: false, info: false, ordering: false, autoWidth: false,
        dom: 'Bt',
        columns: [
          { data: 'n' },
          { data: 'name', render: function (d, type) { return type === 'display' ? esc(d) : d; } },
          { data: 'type', render: function (d, type) { return type === 'display' ? esc(d) : d; } },
          { data: 'quantity', className: 'text-right', render: function (d, type) { return type === 'display' ? (/^\d+$/.test(String(d)) ? fmtInt(d) : esc(d)) : d; } },
          { data: priceKey, className: 'text-right', render: function (d) { return moneyS(d); } },
          { data: totalKey, className: 'text-right', render: function (d) { return moneyS(d); } }
        ],
        buttons: [
          { extend: 'copyHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-copy"></i> Copy', exportOptions: exp,
            title: ctx.title, messageTop: ctx.top, messageBottom: ctx.bottom },
          { extend: 'csvHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-csv"></i> CSV', exportOptions: exp,
            filename: ctx.file,
            customize: function (csv) {
              var q = function (t) { return '"' + String(t).replace(/"/g, '""') + '"'; };
              return q(ctx.title()) + '\n' + q(ctx.top()) + '\n' + csv + '\n' + q(ctx.bottom());
            } },
          { extend: 'excelHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-excel"></i> Excel', exportOptions: exp,
            filename: ctx.file, title: ctx.title, messageTop: ctx.top, messageBottom: ctx.bottom },
          { extend: 'pdfHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-pdf"></i> PDF', exportOptions: exp,
            customize: function (doc) { invoicePdfWatermark(doc); },
            filename: ctx.file, title: ctx.title, messageTop: ctx.top, messageBottom: ctx.bottom },
          { text: '<i class="fas fa-print"></i> Print', className: 'btn btn-secondary btn-sm', action: function () { printDoc(ctx.printClass); } }
        ],
        language: { emptyTable: 'No lines' }
      });
      dt.buttons().container().appendTo(btns);
      return dt;
    }

    function initDocLines_OLD(sel, btns, ctx) {
      var exp = { columns: ':visible' };
      var dt = $(sel).DataTable({
        data: [], paging: false, searching: false, info: false, ordering: false, autoWidth: false,
        dom: 'Bt',
        columns: [
          { data: 'n' },
          { data: 'name', render: function (d, type) { return type === 'display' ? esc(d) : d; } },
          { data: 'type', render: function (d, type) { return type === 'display' ? esc(d) : d; } },
          { data: 'quantity', className: 'text-right', render: function (d, type) { return type === 'display' ? (/^\d+$/.test(String(d)) ? fmtInt(d) : esc(d)) : d; } },
          { data: 'sellingprice', className: 'text-right', render: function (d) { return moneyS(d); } },
          { data: 'linetotal', className: 'text-right', render: function (d) { return moneyS(d); } }
        ],
        buttons: [
          { extend: 'copyHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-copy"></i> Copy', exportOptions: exp,
            title: ctx.title, messageTop: ctx.top, messageBottom: ctx.bottom },
          { extend: 'csvHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-csv"></i> CSV', exportOptions: exp,
            filename: ctx.file,
            customize: function (csv) {
              var q = function (t) { return '"' + String(t).replace(/"/g, '""') + '"'; };
              return q(ctx.title()) + '\n' + q(ctx.top()) + '\n' + csv + '\n' + q(ctx.bottom());
            } },
          { extend: 'excelHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-excel"></i> Excel', exportOptions: exp,
            filename: ctx.file, title: ctx.title, messageTop: ctx.top, messageBottom: ctx.bottom },
          { extend: 'pdfHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-pdf"></i> PDF', exportOptions: exp,
            customize: function (doc) { invoicePdfWatermark(doc); },
            filename: ctx.file, title: ctx.title, messageTop: ctx.top, messageBottom: ctx.bottom },
          { text: '<i class="fas fa-print"></i> Print', className: 'btn btn-secondary btn-sm', action: function () { printDoc(ctx.printClass); } }
        ],
        language: { emptyTable: 'No lines' }
      });
      dt.buttons().container().appendTo(btns);
      return dt;
    }

    function statusCls(s) { return s === 'Paid' ? 'st-active' : (s === 'Partial' ? 'st-soon' : 'st-expired'); }

    /* ---------- afterChange: also reloads the purchases list and the customer position ---------- */
    function afterChange() {
      table.ajax.reload(null, false);
      if (salesDT) { salesDT.ajax.reload(null, false); }
      if (purchasesDT) { purchasesDT.ajax.reload(null, false); }
      if (typeof saRefreshLookups === 'function') { saRefreshLookups(); }
      if (typeof puRefreshLookups === 'function') { puRefreshLookups(); }
      if (typeof cpRefresh === 'function') { cpRefresh(); }
    }

    /* ---------- Purchase modal: None supplier, edit mode ---------- */
    function puFillSelects() {
      var s = '<option value="">Select supplier</option><option value="none">None (no supplier)</option>';
      $.each(PL.suppliers || [], function (i, r) { s += '<option value="' + Number(r.supplierid) + '">' + esc(r.name) + '</option>'; });
      $('#puSupplier').html(s);

      var l = '<option value="">Select livestock</option>';
      $.each(PL.livestock || [], function (i, r) { l += '<option value="' + Number(r.livestockid) + '">' + esc(r.name + ' (' + r.type + ')') + '</option>'; });
      $('#puLivestock').html(l);

      var a = '<option value="">Select account</option>';
      $.each(PL.accounts || [], function (i, r) { a += '<option value="' + Number(r.accountid) + '">' + esc(r.code + ' ' + r.accountname) + '</option>'; });
      $('#puAccount').html(a);

      indexPurchaseLookups();
    }

    function puSupplierHelp() {
      $('#puSupplierHelp').text($('#puSupplier').val() === 'none' ? 'A purchase without a supplier must be paid in full.' : '');
    }
    $('#puSupplier').on('change', puSupplierHelp);

    // Payments entered on the Payments page keep their amount: no balance check then
    function puBalanceMessage(paid) {
      if (puManual) { return ''; }
      var acc = String($('#puAccount').val() || '');
      var avail = puAvailableMinor();
      if (paid <= 0n || avail === null || paid <= avail) { return ''; }
      return 'Insufficient balance in ' + PACC[acc].label + ': available ' + minorMoney(avail) + ', required ' + minorMoney(paid) + '.';
    }

    // Fresh stock and balances; in edit mode the edit lookups keep the old payment and the old animals out of the numbers
    function puRefreshLookups() {
      var edit = puEditId > 0 && $('#purchaseModal').hasClass('show');
      var req = edit ? { action: 'purchaseeditlookups', purchaseid: puEditId } : { action: 'purchaselookups' };
      return api(req).done(function (res) {
        if (res.success) {
          PL.suppliers = res.data.suppliers; PL.livestock = res.data.livestock; PL.accounts = res.data.accounts;
          indexPurchaseLookups();
          puRecalc();
        }
      });
    }

    function showPurchaseModal(livestockId) {
      puEditId = 0; puBack = ''; puManual = false;
      $('#purchaseForm')[0].reset();
      puClearInvalid();
      puFillSelects();
      puSaving = false;
      $('#purchaseModalTitle').text('Purchase Livestock');
      $('#puPaid, #puAccount').prop('disabled', false);
      $('#puDate').val(ymd(new Date()));
      $('#puPurchaseCost').val('0'); $('#puSellingPrice').val('0'); puSellingTouched = false;
      $('#puPaid').val('0');
      if (livestockId && PLIVE[String(livestockId)]) { $('#puLivestock').val(String(livestockId)); $('#puSellingPrice').val(lvSellingOf(livestockId)); }
      puSupplierHelp();
      puRecalc();
      $('#purchaseModal').modal('show');
    }

    function puValidate() {
      puClearInvalid();

      var sup = String($('#puSupplier').val() || '');
      if (!sup) { puMark('supplierid', null); return false; }

      var date = $.trim($('#puDate').val());
      if (!realDate(date)) { puMark('purchasedate', null); return false; }

      var lid = String($('#puLivestock').val() || '');
      if (!lid) { puMark('livestockid', null); return false; }

      var qty = $.trim($('#puQty').val());
      if (!(RE_QTY.test(qty) && /[1-9]/.test(qty))) { puMark('quantity', 'Quantity must be a whole number greater than 0.'); return false; }
      var a = PLIVE[lid];
      if (a && a.currentstock + Number(qty) > CFG.maxStock) {
        puMark('quantity', 'Cannot purchase: ' + a.name + ' stock would exceed ' + fmtInt(CFG.maxStock) + '.');
        return false;
      }

      var cost = $.trim($('#puPurchaseCost').val());
      if (!RE_PAID.test(cost)) { puMark('purchasecost', null); return false; }
      var costM = toMinor(cost, 2);
      if (costEachMinor(costM, BigInt(qty)) > MAX_PRICE_MINOR) {
        puCostFlagged = true;
        puMark('purchasecost', 'Cost each is too large (maximum 9,999,999.99). Check the purchase cost and the quantity.');
        return false;
      }

      var selling = $.trim($('#puSellingPrice').val());
      if (!RE_PRICE.test(selling)) { puMark('sellingprice', null); return false; }

      var paidRaw = $.trim($('#puPaid').val()) || '0';
      if (!RE_PAID.test(paidRaw)) { puMark('paidamount', null); return false; }
      var paid = toMinor(paidRaw, 2);
      if (paid > costM) { puMark('paidamount', 'Paid amount cannot be more than the purchase cost.'); return false; }
      if (sup === 'none' && paid !== costM) { puMark('paidamount', 'A purchase without a supplier must be paid in full.'); return false; }
      if (paid > 0n && !$('#puAccount').val()) { puMark('paidfromaccountid', null); return false; }
      var balMsg = puBalanceMessage(paid);
      if (balMsg) { puBalanceFlagged = true; puMark('paidamount', balMsg); return false; }
      return true;
    }

    function puValidate_OLD() {
      puClearInvalid();

      var sup = String($('#puSupplier').val() || '');
      if (!sup) { puMark('supplierid', null); return false; }

      var date = $.trim($('#puDate').val());
      if (!realDate(date)) { puMark('purchasedate', null); return false; }

      var lid = String($('#puLivestock').val() || '');
      if (!lid) { puMark('livestockid', null); return false; }

      var qty = $.trim($('#puQty').val());
      if (!(RE_QTY.test(qty) && /[1-9]/.test(qty))) { puMark('quantity', 'Quantity must be a whole number greater than 0.'); return false; }
      var a = PLIVE[lid];
      if (a && a.currentstock + Number(qty) > CFG.maxStock) {
        puMark('quantity', 'Cannot purchase: ' + a.name + ' stock would exceed ' + fmtInt(CFG.maxStock) + '.');
        return false;
      }

      var price = $.trim($('#puPrice').val());
      if (!RE_PRICE.test(price)) { puMark('sellingprice', null); return false; }

      var total = BigInt(qty) * toMinor(price, 2);
      if (total > MAX_TOTAL) { puMark('quantity', 'The purchase total is too large.'); return false; }

      var paidRaw = $.trim($('#puPaid').val()) || '0';
      if (!RE_PAID.test(paidRaw)) { puMark('paidamount', null); return false; }
      var paid = toMinor(paidRaw, 2);
      if (paid > total) { puMark('paidamount', 'Paid amount cannot be more than the total.'); return false; }
      if (sup === 'none' && paid !== total) { puMark('paidamount', 'A purchase without a supplier must be paid in full.'); return false; }
      if (paid > 0n && !$('#puAccount').val()) { puMark('paidfromaccountid', null); return false; }
      var balMsg = puBalanceMessage(paid);
      if (balMsg) { puBalanceFlagged = true; puMark('paidamount', balMsg); return false; }
      return true;
    }

    // Replaces the old submit handler: new purchase or edit, then the purchase view opens
    $('#purchaseForm').off('submit').on('submit', function (e) {
      e.preventDefault();
      if (puSaving || !puValidate()) { return; }

      var data = {
        action: puEditId ? 'purchaseupdate' : 'purchasesave',
        supplierid: $('#puSupplier').val(),
        purchasedate: $.trim($('#puDate').val()),
        reference: $.trim($('#puRef').val()),
        livestockid: $('#puLivestock').val(),
        quantity: $.trim($('#puQty').val()),
        purchasecost: $.trim($('#puPurchaseCost').val()),
        sellingprice: $.trim($('#puSellingPrice').val()),
        paidamount: $.trim($('#puPaid').val()) || '0',
        paidfromaccountid: $('#puAccount').val() || 0,
        note: $.trim($('#puNote').val())
      };
      if (puEditId) { data.purchaseid = puEditId; }

      var ok = false;
      puSaving = true;
      $('#btnSavePurchase').prop('disabled', true);
      api(data).done(function (res) {
        if (res.success) {
          ok = true;
          toastr.success(res.message);
          var back = puBack;
          puBack = '';
          afterChange();
          openPurchaseView(res.purchaseid, back === '#purchaseViewModal' ? PVBACK : back);
          return;
        }
        if (res.field === 'paidamount' && /^Insufficient/.test(res.message || '')) { puBalanceFlagged = true; }
        puShowServerError(res.field, res.message);
        toastr.error(res.message);
        if (res.field === 'paidamount' || res.field === 'quantity') { puRefreshLookups(); }
      }).always(function () {
        puSaving = false;
        if (ok) { $('#btnSavePurchase').prop('disabled', true); } else { puRecalc(); }
      });
    });

    function openPurchaseEdit(id, back) {
      if (puOpening) { return; }
      puOpening = true;
      api({ action: 'purchaseeditlookups', purchaseid: id }).done(function (res) {
        if (!res.success) { if (res.blocked) { toastr.warning(res.message); } else { toastr.error(res.message); } return; }
        var d = res.data, p = d.purchase;
        PL = { suppliers: d.suppliers, livestock: d.livestock, accounts: d.accounts };
        puEditId = id; puBack = back || ''; puManual = !!d.manual; puSaving = false;
        $('#purchaseForm')[0].reset();
        puClearInvalid();
        puFillSelects();
        $('#purchaseModalTitle').text('Edit Purchase #' + p.purchaseid);
        $('#puSupplier').val(p.nosupplier ? 'none' : String(p.supplierid));
        $('#puDate').val(p.purchasedate);
        $('#puRef').val(p.reference);
        $('#puNote').val(p.note);
        $('#puLivestock').val(String(d.line.livestockid));
        $('#puQty').val(d.line.quantity);
        $('#puPurchaseCost').val(d.line.purchasecost); $('#puSellingPrice').val(d.line.sellingprice); puSellingTouched = true;
        $('#puPaid').val(p.paid);
        $('#puAccount').val(p.accountid > 0 && PACC[String(p.accountid)] ? String(p.accountid) : '');
        $('#puPaid, #puAccount').prop('disabled', puManual);
        puSupplierHelp();
        puRecalc();
        if (puManual) { toastr.info('The paid amount and account cannot change here: this purchase has payments entered on the Payments page.'); }
        gotoModal('#purchaseModal');
      }).always(function () { puOpening = false; });
    }

    // Closed or cancelled: go back to the modal it was opened from
    $('#purchaseModal').on('hidden.bs.modal', function () {
      if (navigating) { return; }
      var b = puBack;
      puBack = '';
      if (b) { $(b).modal('show'); }
    });

    /* ---------- Purchases list modal ---------- */
    var purchasesDT = null;
    var PEDIT_MSG = 'Edit this purchase in Purchases page';

    function purchasesActions(row) {
      var n = Number(row.purchaseid);
      return '<button type="button" class="btn btn-info act-btn btn-pu-view" data-id="' + n + '" title="View / Print purchase" aria-label="View purchase #' + n + '"><i class="fas fa-file-invoice" aria-hidden="true"></i></button>' +
             '<button type="button" class="btn btn-secondary act-btn btn-pu-edit" data-id="' + n + '"' + (row.editable ? '' : ' disabled') + ' title="' + (row.editable ? 'Edit purchase' : PEDIT_MSG) + '" aria-label="Edit purchase #' + n + '"><i class="fas fa-edit" aria-hidden="true"></i></button>' +
             '<button type="button" class="btn btn-danger act-btn btn-pu-del" data-id="' + n + '"' + (row.deletable ? '' : ' disabled') + ' title="' + (row.deletable ? 'Delete purchase' : PEDIT_MSG) + '" aria-label="Delete purchase #' + n + '"><i class="fas fa-trash" aria-hidden="true"></i></button>';
    }

    function initPurchasesTable() {
      var exp = { columns: [0, 1, 2, 3, 4, 5, 6, 7], orthogonal: 'export' };
      purchasesDT = $('#purchasesTable').DataTable({
        ajax: {
          url: URL_SELF, type: 'POST',
          data: function (d) { d.action = 'purchaseslist'; d.csrf_token = CSRF; },
          cache: false,
          dataSrc: 'data',
          error: function (xhr) { if (window.console) { console.warn('purchases list request failed (HTTP ' + (xhr && xhr.status) + ')'); toastr.error('The purchases list could not be loaded (HTTP ' + (xhr && xhr.status) + ').'); } }
        },
        columns: [
          { data: 'purchaseid' },
          { data: 'purchasedate', className: 'text-nowrap' },
          { data: 'suppliername', render: function (d, type) { return type === 'display' ? esc(d) : d; } },
          { data: 'reference', render: function (d, type) { return type === 'display' ? esc(d) : d; } },
          moneyCol('total'), moneyCol('paid'), moneyCol('balance'),
          {
            data: 'stage',
            render: function (d, type) { return type === 'display' ? '<span class="badge ' + statusCls(d) + '">' + esc(d) + '</span>' : d; }
          },
          { data: null, orderable: false, searchable: false, className: 'text-center text-nowrap', render: function (d, type, row) { return purchasesActions(row); } }
        ],
        order: [[0, 'desc']],
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        dom: "<'dt-top'<'dt-len'l><'dt-btns'B><'dt-find'f>>" +
             "<'table-scroll'tr>" +
             "<'row mt-2'<'col-md-5'i><'col-md-7'p>>",
        buttons: [
          { extend: 'copyHtml5',  className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-copy"></i> Copy',        title: 'Purchases', exportOptions: exp },
          { extend: 'csvHtml5',   className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-csv"></i> CSV',     filename: 'Purchases', exportOptions: exp },
          { extend: 'excelHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-excel"></i> Excel', title: 'Purchases', filename: 'Purchases', exportOptions: exp },
          { extend: 'pdfHtml5',   className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-pdf"></i> PDF',     title: 'Purchases', filename: 'Purchases', orientation: 'landscape', exportOptions: exp },
          { extend: 'print',      className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-print"></i> Print',      title: 'Purchases', exportOptions: exp }
        ],
        language: { search: 'Search:', searchPlaceholder: 'Search purchases...', emptyTable: 'No purchases found' }
      });
    }

    $('#btnPurchases').on('click', function () { $('#purchasesModal').modal('show'); });
    $('#purchasesModal').on('shown.bs.modal', function () {
      if (!purchasesDT) { initPurchasesTable(); return; }
      purchasesDT.ajax.reload(null, false);
      purchasesDT.columns.adjust();
    });
    $('#purchasesTable tbody').on('click', '.btn-pu-view', function () { openPurchaseView(Number($(this).attr('data-id')), '#purchasesModal'); });
    $('#purchasesTable tbody').on('click', '.btn-pu-edit', function () { openPurchaseEdit(Number($(this).attr('data-id')), '#purchasesModal'); });
    $('#purchasesTable tbody').on('click', '.btn-pu-del', function () {
      var d = purchasesDT.row($(this).closest('tr')).data();
      askPurchaseDelete(Number(d.purchaseid), d.suppliername, '#purchasesModal');
    });

    /* ---------- Purchase view modal ---------- */
    var PV = { id: 0, data: null };
    var pvLoading = false;
    var pvDT = null;

    function pvTitleText() { return 'Purchase #' + PV.id + ' - ' + CFG.farm; }
    function pvHeaderText() {
      var p = PV.data.purchase;
      return 'Purchase #' + p.purchaseid + ' | Supplier: ' + p.suppliername + ' | Date: ' + p.purchasedate + (p.reference ? ' | Reference: ' + p.reference : '');
    }
    function pvTotalsText() {
      var p = PV.data.purchase;
      return 'Purchase Cost ' + moneyS(p.total) + ' | Paid ' + moneyS(p.paid) + ' | Balance due ' + moneyS(p.balance) + ' (' + p.status + ')';
    }

    function renderPurchaseView(data) {
      var p = data.purchase;
      PV.id = p.purchaseid;
      PV.data = data;
      $('#purchaseViewTitle').text('Purchase #' + p.purchaseid);
      docBrand('pv');
      $('#pvNo').text(p.purchaseid);
      $('#pvDate').text(p.purchasedate);
      $('#pvSupplier').text(p.suppliername);
      $('#pvRef').text(p.reference || '-');
      $('#pvAccount').text(p.account || '-');
      $('#pvNote').text(p.note || '-');

      if (!pvDT) {
        pvDT = initDocLines('#pvLinesTable', '#pvBtns', {
          title: pvTitleText, top: pvHeaderText, bottom: pvTotalsText,
          file: function () { return 'Purchase-' + PV.id; }, printClass: 'pview-open',
          priceKey: 'costeach', totalKey: 'purchasecost'
        });
      }
      var rows = $.map(data.lines, function (l, i) {
        return { n: i + 1, name: l.name, type: l.type, quantity: l.quantity, costeach: l.costeach, purchasecost: l.purchasecost };
      });
      pvDT.clear().rows.add(rows).draw(false);

      $('#pvTotal').text(moneyS(p.total));
      $('#pvPaid').text(moneyS(p.paid));
      $('#pvBalance').text(moneyS(p.balance));
      $('#pvStatus').text(p.status).removeClass('st-active st-soon st-expired').addClass(statusCls(p.status));

      $('#btnPvEdit').prop('disabled', !p.editable).attr('title', p.editable ? 'Edit purchase' : PEDIT_MSG);
      $('#btnPvDelete').prop('disabled', !p.deletable).attr('title', p.deletable ? 'Delete purchase' : PEDIT_MSG);
      $('#pvActionHelp').text(p.editable && p.deletable ? '' : PEDIT_MSG + '.');
    }

    function renderPurchaseView_OLD(data) {
      var p = data.purchase;
      PV.id = p.purchaseid;
      PV.data = data;
      $('#purchaseViewTitle').text('Purchase #' + p.purchaseid);
      docBrand('pv');
      $('#pvNo').text(p.purchaseid);
      $('#pvDate').text(p.purchasedate);
      $('#pvSupplier').text(p.suppliername);
      $('#pvRef').text(p.reference || '-');
      $('#pvAccount').text(p.account || '-');
      $('#pvNote').text(p.note || '-');

      if (!pvDT) {
        pvDT = initDocLines('#pvLinesTable', '#pvBtns', {
          title: pvTitleText, top: pvHeaderText, bottom: pvTotalsText,
          file: function () { return 'Purchase-' + PV.id; }, printClass: 'pview-open'
        });
      }
      var rows = $.map(data.lines, function (l, i) {
        return { n: i + 1, name: l.name, type: l.type, quantity: l.quantity, sellingprice: l.sellingprice, linetotal: l.linetotal };
      });
      pvDT.clear().rows.add(rows).draw(false);

      $('#pvTotal').text(moneyS(p.total));
      $('#pvPaid').text(moneyS(p.paid));
      $('#pvBalance').text(moneyS(p.balance));
      $('#pvStatus').text(p.status).removeClass('st-active st-soon st-expired').addClass(statusCls(p.status));

      $('#btnPvEdit').prop('disabled', !p.editable).attr('title', p.editable ? 'Edit purchase' : PEDIT_MSG);
      $('#btnPvDelete').prop('disabled', !p.deletable).attr('title', p.deletable ? 'Delete purchase' : PEDIT_MSG);
      $('#pvActionHelp').text(p.editable && p.deletable ? '' : PEDIT_MSG + '.');
    }

    function openPurchaseView(id, back) {
      if (pvLoading) { return; }
      pvLoading = true;
      api({ action: 'purchaseget', purchaseid: id }).done(function (res) {
        if (!res.success) { toastr.error(res.message); return; }
        PVBACK = back || '';
        renderPurchaseView(res.data);
        gotoModal('#purchaseViewModal');
      }).always(function () { pvLoading = false; });
    }

    $('#purchaseViewModal').on('hidden.bs.modal', function () {
      if (navigating) { return; }
      $('body').removeClass('pview-open');
      var b = PVBACK;
      PVBACK = '';
      if (b) { $(b).modal('show'); }
    });
    $('#btnPvEdit').on('click', function () { openPurchaseEdit(PV.id, '#purchaseViewModal'); });
    $('#btnPvDelete').on('click', function () { askPurchaseDelete(PV.id, PV.data ? PV.data.purchase.suppliername : '', '#purchaseViewModal'); });

    /* ---------- Delete purchase ---------- */
    var delPur = { id: 0, back: '', done: false, busy: false };

    function askPurchaseDelete(id, name, back) {
      delPur = { id: id, back: back || '', done: false, busy: false };
      $('#pdNo').text('#' + id);
      $('#pdSupplier').text(name || '');
      gotoModal('#purchaseDeleteModal');
    }

    $('#purchaseDeleteModal').on('hidden.bs.modal', function () {
      if (navigating) { return; }
      var t = delPur.back;
      if (delPur.done && t === '#purchaseViewModal') { t = PVBACK; PVBACK = ''; }
      delPur.id = 0;
      if (t) { $(t).modal('show'); }
    });

    $('#btnConfirmPDelete').on('click', function () {
      if (delPur.busy || !delPur.id) { return; }
      delPur.busy = true;
      var $btn = $(this).prop('disabled', true);
      api({ action: 'purchasedelete', purchaseid: delPur.id }).done(function (res) {
        if (res.success) {
          delPur.done = true;
          toastr.success(res.message);
          afterChange();
        } else if (res.blocked) {
          toastr.warning(res.message);
        } else {
          toastr.error(res.message);
        }
        $('#purchaseDeleteModal').modal('hide');
      }).fail(function () {
        $('#purchaseDeleteModal').modal('hide');
      }).always(function () {
        delPur.busy = false;
        $btn.prop('disabled', false);
      });
    });
</script>

<!-- ===================== Purchase quick add: supplier, livestock (with its own type and status) ===================== -->
<script nonce="<?= $n ?>">
    // Rebuild the three selects of the purchase modal and keep what is selected (puFillSelects keeps the None option)
    function puRebuildKeep() {
      var keep = { s: $('#puSupplier').val(), l: $('#puLivestock').val(), a: $('#puAccount').val() };
      puFillSelects();
      $('#puSupplier').val(keep.s == null ? '' : keep.s);
      $('#puLivestock').val(keep.l == null ? '' : keep.l);
      $('#puAccount').val(keep.a == null ? '' : keep.a);
    }

    $('#purchaseModal').on('show.bs.modal', function () {
      qpResetModal('#purchaseModal');
      lslLoad();
    });

    /* ---------- Quick supplier ---------- */
    var QSP = {
      btn: '#btnQspAdd', panel: '#qspPanel', save: '#btnQspSave', cancel: '#btnQspCancel', ok: '#qspOk', focus: '#qspName', action: 'suppliersave',
      fields: [
        { key: 'name', sel: '#qspName', fb: '#qspNameFb', req: 'Supplier name is required.', max: 150, maxMsg: 'Supplier name is too long (maximum 150 characters).', reserved: NONE_NAME },
        { key: 'contact', sel: '#qspContact', fb: '#qspContactFb', max: 50, maxMsg: 'Contact is too long (maximum 50 characters).' },
        { key: 'address', sel: '#qspAddress', fb: '#qspAddressFb', max: 255, maxMsg: 'Address is too long (maximum 255 characters).' }
      ],
      done: function (row, res) {
        PL.suppliers = (PL.suppliers || []).concat([{ supplierid: row.supplierid, name: row.name }]).sort(byName);
        puRebuildKeep();
        $('#puSupplier').val(String(row.supplierid)).trigger('change');
        $('#qspOk').text('Supplier "' + row.name + '" added and selected.');
        toastr.success(res.message);
        qpClose(QSP);
      }
    };
    quickWire(QSP);

    /* ---------- Quick type and quick status (inside the quick livestock panel) ---------- */
    var QPT = {
      btn: '#btnQptAdd', panel: '#qptPanel', save: '#btnQptSave', cancel: '#btnQptCancel', ok: '#qptOk', focus: '#qptName', action: 'typesave',
      fields: [{ key: 'name', sel: '#qptName', fb: '#qptNameFb', req: 'Type name is required.', max: 100, maxMsg: 'Type name is too long (maximum 100 characters).' }],
      done: function (row, res) {
        LSL.types.push(row);
        LSL.types.sort(byName);
        lslFill('#qlvType', LSL.types, 'Select type', row.name);
        $('#qlvType').removeClass('is-invalid');
        $('#qlvTypeFb').text('Select a valid type.');
        $('#qptOk').text('Type "' + row.name + '" added and selected.');
        toastr.success(res.message);
        qpClose(QPT);
        table.ajax.reload(null, false);
      }
    };
    quickWire(QPT);

    var Qpg = {
      btn: '#btnQpgAdd', panel: '#QpgPanel', save: '#btnQpgSave', cancel: '#btnQpgCancel', ok: '#QpgOk', focus: '#QpgName', action: 'stagesave',
      fields: [{ key: 'name', sel: '#QpgName', fb: '#QpgNameFb', req: 'Stage name is required.', max: 50, maxMsg: 'Stage name is too long (maximum 50 characters).' }],
      done: function (row, res) {
        LSL.stages.push(row);
        LSL.stages.sort(byName);
        lslFill('#qlvStage', LSL.stages, 'Select stage', row.name);
        $('#qlvStage').removeClass('is-invalid');
        $('#qlvStageFb').text('Select a valid stage.');
        $('#QpgOk').text('Stage "' + row.name + '" added and selected.');
        toastr.success(res.message);
        qpClose(Qpg);
        table.ajax.reload(null, false);
      }
    };
    quickWire(Qpg);

    /* ---------- Quick livestock ---------- */
    var QLV = {
      btn: '#btnQlvAdd', panel: '#qlvPanel', save: '#btnQlvSave', cancel: '#btnQlvCancel', ok: '#qlvOk', focus: '#qlvName', action: 'livestockquicksave',
      lookups: true,
      prepare: function () {
        lslFill('#qlvType', LSL.types, 'Select type', '');
        lslFill('#qlvStage', LSL.stages, 'Select stage', 'Active');
      },
      fields: [
        { key: 'name', sel: '#qlvName', fb: '#qlvNameFb', req: 'Name is required.', max: 150, maxMsg: 'Name is too long (maximum 150 characters).' },
        { key: 'type', sel: '#qlvType', fb: '#qlvTypeFb', req: 'Select a valid type.' },
        { key: 'stage', sel: '#qlvStage', fb: '#qlvStageFb', req: 'Select a valid stage.', def: 'Active' },
        /* cost each and selling price are not typed here: the purchase sets them */
      ],
      done: function (row, res) {
        PL.livestock = (PL.livestock || []).concat([{ livestockid: row.livestockid, name: row.name, type: row.type, currentstock: 0 }]).sort(byName);
        puRebuildKeep();                       // re-indexes PLIVE
        $('#puLivestock').val(String(row.livestockid)).trigger('change');
        puRecalc();                            // helper lines: "On hand: 0 animals"
        $('#qlvOk').text('Livestock "' + row.name + '" added and selected.');
        toastr.success(res.message);
        qpClose(QLV);
        table.ajax.reload(null, false);
      }
    };
    quickWire(QLV);
</script>

<!-- ===================== Supplier position in the Purchase modal (information only) ===================== -->
<script nonce="<?= $n ?>">
    var spSeq = 0;
    var SP_NONE_TEXT = 'No supplier: nothing to track. Paid in full.';

    function spRender($box, d) {
      var cls = d.status === 'Payable' ? 'st-expired' : (d.status === 'Receivable' ? 'st-soon' : 'st-active');
      var label = d.status === 'Settled' ? 'Settled' : d.status + ' ' + moneyS(d.amount);
      $box.html('<span class="badge ' + cls + '">' + esc(label) + '</span>' +
        '<div class="cp-nums">' +
        '<span>Payable (we owe): <strong>' + esc(moneyS(d.payable)) + '</strong></span>' +
        '<span>Receivable (supplier owes us): <strong>' + esc(moneyS(d.receivable)) + '</strong></span>' +
        '<span>Advance balance: <strong>' + esc(moneyS(d.advance)) + '</strong></span>' +
        '</div>');
    }

    // excludeId = the purchase being edited (0 for a new purchase); an answer that arrives after the supplier changed is ignored
    function spLoad(excludeId) {
      var $box = $('#puSupPos'), v = String($('#puSupplier').val() || ''), seq = ++spSeq;
      if (!v) { $box.prop('hidden', true).empty(); return; }
      if (v === 'none') { $box.prop('hidden', false).text(SP_NONE_TEXT); return; }
      $box.prop('hidden', false).text('Loading supplier position\u2026');
      $.ajax({ url: URL_SELF, type: 'POST', dataType: 'json', data: { action: 'supplierposition', csrf_token: CSRF, supplierid: v, purchaseid: excludeId || 0 } })
        .done(function (res) {
          if (seq !== spSeq) { return; }
          if (!res.success) { $box.text(res.message); return; }
          if (res.data.none) { $box.text(SP_NONE_TEXT); return; }
          spRender($box, res.data);
        })
        .fail(function () { if (seq === spSeq) { $box.text('The supplier position could not be loaded.'); } });
    }

    function spRefresh() { spLoad(typeof puEditId !== 'undefined' ? puEditId : 0); }

    $('#puSupplier').on('change', spRefresh);
    $('#purchaseModal').on('show.bs.modal', function () { spSeq++; $('#puSupPos').prop('hidden', true).empty(); });
    $('#purchaseModal').on('shown.bs.modal', spRefresh);
</script>

<!-- ===================== Quotations: edit mode, list, view, delete (BigInt money, no floats) ===================== -->
<script nonce="<?= $n ?>">
    var quEditId = 0, quBack = '', QVBACK = '';

    function quotationStatusCls(s) {
      return s === 'Accepted' ? 'st-active' : (s === 'Sent' ? 'st-sent' : (s === 'Rejected' ? 'st-expired' : (s === 'Expired' ? 'st-soon' : 'st-inactive')));
    }

    /* ---------- Make Quotation modal: new quotation (replaces the old opener) ---------- */
    function showQuotationModal(livestockId) {
      quEditId = 0; quBack = '';
      $('#quotationForm')[0].reset();
      quClearInvalid();
      quFillSelects();
      quSaving = false;
      $('#quLinesBody').empty();
      quToggleWalkin();
      $('#quotationModalTitle').text('Make Quotation');
      $('#quDate').val(ymd(new Date()));
      $('#quValid').val('');
      $('#quStatus').val('Draft');
      quAddLine(livestockId);
      quRenumber();
      quRecalc();
      $('#btnSaveQuotation').prop('disabled', false);
      $('#quotationModal').modal('show');
    }

    /* ---------- Edit mode ---------- */
    function openQuotationEdit(id, back) {
      if (quOpening) { return; }
      quOpening = true;
      api({ action: 'quotationeditlookups', quotationid: id }).done(function (res) {
        if (!res.success) { toastr.error(res.message); return; }
        var d = res.data, q = d.quotation;
        QL = { customers: d.customers, livestock: d.livestock };
        indexQuotationLookups();
        quEditId = id; quBack = back || ''; quSaving = false;
        $('#quotationForm')[0].reset();
        quClearInvalid();
        quFillSelects();
        $('#quotationModalTitle').text('Edit Quotation #' + q.quotationid);
        $('#quCustomer').val(q.walkin ? 'walkin' : String(q.customerid));
        $('#quWalkinName').val(q.walkinname);
        quToggleWalkin();
        $('#quDate').val(q.quotationdate);
        $('#quValid').val(q.validuntil);
        $('#quRef').val(q.reference);
        $('#quStatus').val(q.status);
        $('#quNote').val(q.usernote);
        $('#quLinesBody').empty();
        $.each(d.lines, function (i, l) {
          var $tr = quAddLine(l.livestockid);
          if ($tr) {
            $tr.find('.ql-qty').val(l.quantity);
            $tr.find('.ql-selling').val(l.sellingprice);
            $tr.data('touched', true);
            quUpdateLine($tr);
          }
        });
        if (!d.lines.length) { quAddLine(0); }
        quRenumber();
        quRecalc();
        $('#btnSaveQuotation').prop('disabled', false);
        gotoModal('#quotationModal');
      }).always(function () { quOpening = false; });
    }

    // Replaces the old submit handler: new quotation or update, then the quotation view opens
    $('#quotationForm').off('submit').on('submit', function (e) {
      e.preventDefault();
      if (quSaving || !quValidate()) { return; }

      var lines = [];
      $('#quLinesBody tr.line-row').each(function () {
        var v = quLineValues($(this));
        lines.push({ livestockid: v.item, quantity: v.qty, sellingprice: v.price });
      });

      var data = {
        action: quEditId ? 'quotationupdate' : 'quotationsave',
        customerid: $('#quCustomer').val(),
        walkinname: ($('#quCustomer').val() === 'walkin') ? $.trim($('#quWalkinName').val()) : '',
        quotationdate: $.trim($('#quDate').val()),
        validuntil: $.trim($('#quValid').val()),
        reference: $.trim($('#quRef').val()),
        status: $('#quStatus').val(),
        note: $.trim($('#quNote').val()),
        lines: JSON.stringify(lines)
      };
      if (quEditId) { data.quotationid = quEditId; }

      var ok = false;
      quSaving = true;
      $('#btnSaveQuotation').prop('disabled', true);
      api(data).done(function (res) {
        if (res.success) {
          ok = true;
          toastr.success(res.message);
          var back = quBack;
          quBack = '';
          if (typeof cpRefresh === 'function') { cpRefresh(); }
          openQuotationView(res.quotationid, back === '#quotationViewModal' ? QVBACK : back);
          return;
        }
        quShowServerError(res.field, res.message);
        toastr.error(res.message);
      }).always(function () {
        quSaving = false;
        $('#btnSaveQuotation').prop('disabled', false);
      });
    });

    $('#quotationModal').on('hidden.bs.modal', function () {
      if (navigating) { return; }
      var b = quBack;
      quBack = '';
      if (b) { $(b).modal('show'); }
    });

    /* ---------- Quotations list modal ---------- */
    var quotationsDT = null;

    function quotationsActions(row) {
      var n = Number(row.quotationid);
      return '<button type="button" class="btn btn-info act-btn btn-qt-view" data-id="' + n + '" title="View / Print quotation" aria-label="View quotation #' + n + '"><i class="fas fa-file-invoice" aria-hidden="true"></i></button>' +
             '<button type="button" class="btn btn-secondary act-btn btn-qt-edit" data-id="' + n + '" title="Edit quotation" aria-label="Edit quotation #' + n + '"><i class="fas fa-edit" aria-hidden="true"></i></button>' +
             '<button type="button" class="btn btn-danger act-btn btn-qt-del" data-id="' + n + '" title="Delete quotation" aria-label="Delete quotation #' + n + '"><i class="fas fa-trash" aria-hidden="true"></i></button>';
    }

    function initQuotationsTable() {
      var exp = { columns: [0, 1, 2, 3, 4, 5, 6], orthogonal: 'export' };
      quotationsDT = $('#quotationsTable').DataTable({
        ajax: {
          url: URL_SELF, type: 'POST',
          data: function (d) { d.action = 'quotationslist'; d.csrf_token = CSRF; },
          cache: false,
          dataSrc: 'data',
          error: function (xhr) { if (window.console) { console.warn('quotations list request failed (HTTP ' + (xhr && xhr.status) + ')'); toastr.error('The quotations list could not be loaded (HTTP ' + (xhr && xhr.status) + ').'); } }
        },
        columns: [
          { data: 'quotationid' },
          { data: 'quotationdate', className: 'text-nowrap' },
          { data: 'customername', render: function (d, type) { return type === 'display' ? esc(d) : d; } },
          { data: 'reference', render: function (d, type) { return type === 'display' ? esc(d) : d; } },
          { data: 'validuntil', className: 'text-nowrap', render: function (d, type) { return d ? d : (type === 'sort' ? '' : '-'); } },
          moneyCol('total'),
          {
            data: 'displaystatus',
            render: function (d, type) { return type === 'display' ? '<span class="badge ' + quotationStatusCls(d) + '">' + esc(d) + '</span>' : d; }
          },
          { data: null, orderable: false, searchable: false, className: 'text-center text-nowrap', render: function (d, type, row) { return quotationsActions(row); } }
        ],
        order: [[0, 'desc']],
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        dom: "<'dt-top'<'dt-len'l><'dt-btns'B><'dt-find'f>>" +
             "<'table-scroll'tr>" +
             "<'row mt-2'<'col-md-5'i><'col-md-7'p>>",
        buttons: [
          { extend: 'copyHtml5',  className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-copy"></i> Copy',        title: 'Quotations', exportOptions: exp },
          { extend: 'csvHtml5',   className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-csv"></i> CSV',     filename: 'Quotations', exportOptions: exp },
          { extend: 'excelHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-excel"></i> Excel', title: 'Quotations', filename: 'Quotations', exportOptions: exp },
          { extend: 'pdfHtml5',   className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-pdf"></i> PDF',     title: 'Quotations', filename: 'Quotations', orientation: 'landscape', exportOptions: exp },
          { extend: 'print',      className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-print"></i> Print',      title: 'Quotations', exportOptions: exp }
        ],
        language: { search: 'Search:', searchPlaceholder: 'Search quotations...', emptyTable: 'No quotations found' }
      });
    }

    $('#btnQuotations').on('click', function () { $('#quotationsModal').modal('show'); });
    $('#quotationsModal').on('shown.bs.modal', function () {
      if (!quotationsDT) { initQuotationsTable(); return; }
      quotationsDT.ajax.reload(null, false);
      quotationsDT.columns.adjust();
    });
    $('#quotationsTable tbody').on('click', '.btn-qt-view', function () { openQuotationView(Number($(this).attr('data-id')), '#quotationsModal'); });
    $('#quotationsTable tbody').on('click', '.btn-qt-edit', function () { openQuotationEdit(Number($(this).attr('data-id')), '#quotationsModal'); });
    $('#quotationsTable tbody').on('click', '.btn-qt-del', function () {
      var d = quotationsDT.row($(this).closest('tr')).data();
      askQuotationDelete(Number(d.quotationid), d.customername, '#quotationsModal');
    });

    /* ---------- Quotation view modal ---------- */
    var QV = { id: 0, data: null };
    var qvLoading = false;
    var qvDT = null;

    function qvTitleText() { return 'Quotation #' + QV.id + ' - ' + CFG.farm; }
    function qvHeaderText() {
      var q = QV.data.quotation;
      return 'Quotation #' + q.quotationid + ' | Customer: ' + q.customername + ' | Date: ' + q.quotationdate +
             (q.reference ? ' | Reference: ' + q.reference : '') + ' | Valid until: ' + (q.validuntil || '-') + ' | Status: ' + q.displaystatus;
    }
    function qvTotalsText() { return 'Total ' + moneyS(QV.data.quotation.total); }

    function renderQuotationView(data) {
      var q = data.quotation;
      QV.id = q.quotationid;
      QV.data = data;
      $('#quotationViewTitle').text('Quotation #' + q.quotationid);
      docBrand('qv');
      $('#qvNo').text(q.quotationid);
      $('#qvDate').text(q.quotationdate);
      $('#qvCustomer').text(q.customername);
      $('#qvRef').text(q.reference || '-');
      $('#qvContact').text(q.primarycontact || '-');
      $('#qvAddress').text(q.address || '-');
      $('#qvValid').text(q.validuntil || '-');
      $('#qvStatus').text(q.displaystatus).removeClass('st-active st-sent st-expired st-soon st-inactive').addClass(quotationStatusCls(q.displaystatus));
      $('#qvNote').text(q.usernote || '-');

      if (!qvDT) {
        qvDT = initDocLines('#qvLinesTable', '#qvBtns', {
          title: qvTitleText, top: qvHeaderText, bottom: qvTotalsText,
          file: function () { return 'Quotation-' + QV.id; }, printClass: 'qview-open'
        });
      }
      var rows = $.map(data.lines, function (l, i) {
        return { n: i + 1, name: l.name, type: l.type, quantity: l.quantity, sellingprice: l.sellingprice, linetotal: l.linetotal };
      });
      qvDT.clear().rows.add(rows).draw(false);
      $('#qvTotal').text(moneyS(q.total));
    }

    function openQuotationView(id, back) {
      if (qvLoading) { return; }
      qvLoading = true;
      api({ action: 'quotationget', quotationid: id }).done(function (res) {
        if (!res.success) { toastr.error(res.message); return; }
        QVBACK = back || '';
        renderQuotationView(res.data);
        gotoModal('#quotationViewModal');
      }).always(function () { qvLoading = false; });
    }

    $('#quotationViewModal').on('hidden.bs.modal', function () {
      if (navigating) { return; }
      $('body').removeClass('qview-open');
      var b = QVBACK;
      QVBACK = '';
      if (b) { $(b).modal('show'); }
    });
    $('#btnQvEdit').on('click', function () { openQuotationEdit(QV.id, '#quotationViewModal'); });
    $('#btnQvDelete').on('click', function () { askQuotationDelete(QV.id, QV.data ? QV.data.quotation.customername : '', '#quotationViewModal'); });

    /* ---------- Delete quotation ---------- */
    var delQuo = { id: 0, back: '', done: false, busy: false };

    function askQuotationDelete(id, name, back) {
      delQuo = { id: id, back: back || '', done: false, busy: false };
      $('#qdNo').text('#' + id);
      $('#qdCustomer').text(name || '');
      gotoModal('#quotationDeleteModal');
    }

    $('#quotationDeleteModal').on('hidden.bs.modal', function () {
      if (navigating) { return; }
      var t = delQuo.back;
      if (delQuo.done && t === '#quotationViewModal') { t = QVBACK; QVBACK = ''; }
      delQuo.id = 0;
      if (t) { $(t).modal('show'); }
    });

    $('#btnConfirmQDelete').on('click', function () {
      if (delQuo.busy || !delQuo.id) { return; }
      delQuo.busy = true;
      var $btn = $(this).prop('disabled', true);
      api({ action: 'quotationdelete', quotationid: delQuo.id }).done(function (res) {
        if (res.success) {
          delQuo.done = true;
          toastr.success(res.message);
          if (quotationsDT) { quotationsDT.ajax.reload(null, false); }
        } else if (res.blocked) {
          toastr.warning(res.message);
        } else {
          toastr.error(res.message);
        }
        $('#quotationDeleteModal').modal('hide');
      }).fail(function () {
        $('#quotationDeleteModal').modal('hide');
      }).always(function () {
        delQuo.busy = false;
        $btn.prop('disabled', false);
      });
    });
</script>

<!-- ===================== Quick customer: Sale and Quotation modals ===================== -->
<script nonce="<?= $n ?>">
    // Rebuild a customer select and keep its value (Walk-in stays on top)
    function qcRebuild(selSel, list) {
      var cur = $(selSel).val();
      var h = '<option value="">Select customer</option><option value="walkin">Walk-in</option>';
      $.each(list || [], function (i, r) { h += '<option value="' + Number(r.customerid) + '">' + esc(r.name) + '</option>'; });
      $(selSel).html(h);
      $(selSel).val(cur == null ? '' : cur);
    }

    // The new customer goes into BOTH lookups and BOTH selects, so it is there next time without reopening
    function qcAdd(row) {
      var item = { customerid: row.customerid, name: row.name };
      SL.customers = (SL.customers || []).concat([item]).sort(byName);
      QL.customers = (QL.customers || []).concat([item]).sort(byName);
      qcRebuild('#saCustomer', SL.customers);
      qcRebuild('#quCustomer', QL.customers);
    }

    // p = id prefix of the panel ("qcs" sale, "qcq" quotation), P = same with a capital (button ids), selSel = the customer select
    function qcMake(p, P, selSel) {
      var c = {
        btn: '#btn' + P + 'Add', panel: '#' + p + 'Panel', save: '#btn' + P + 'Save', cancel: '#btn' + P + 'Cancel',
        ok: '#' + p + 'Ok', focus: '#' + p + 'Name', action: 'customersave',
        fields: [
          { key: 'name', sel: '#' + p + 'Name', fb: '#' + p + 'NameFb', req: 'Customer name is required.', max: 150, maxMsg: 'Customer name is too long (maximum 150 characters).', reserved: WALKIN },
          { key: 'contact', sel: '#' + p + 'Contact', fb: '#' + p + 'ContactFb', max: 50, maxMsg: 'Contact is too long (maximum 50 characters).' },
          { key: 'address', sel: '#' + p + 'Address', fb: '#' + p + 'AddressFb', max: 255, maxMsg: 'Address is too long (maximum 255 characters).' }
        ],
        done: function (row, res) {
          qcAdd(row);
          $(selSel).val(String(row.customerid)).trigger('change');   // hides Walk-in name, resets errors, refreshes the customer position box
          $('#' + p + 'Ok').text('Customer "' + row.name + '" added and selected.');
          toastr.success(res.message);
          qpClose(c);
        }
      };
      quickWire(c);
      return c;
    }
    qcMake('qcs', 'Qcs', '#saCustomer');
    qcMake('qcq', 'Qcq', '#quCustomer');

    // Panels closed on open; "+" disabled while the customer select is locked (edit sale with returns or refunds)
    $('#saleModal').on('show.bs.modal', function () {
      qpResetModal('#saleModal');
      $('#btnQcsAdd').prop('disabled', $('#saCustomer').prop('disabled'));
    });
    $('#quotationModal').on('show.bs.modal', function () { qpResetModal('#quotationModal'); });
</script>

<!-- ===================== Customer position in the Sale and Quotation modals (information only) ===================== -->
<script nonce="<?= $n ?>">
    var cpSeq = { sa: 0, qu: 0 };

    function cpRender($box, d) {
      var cls = d.status === 'Receivable' ? 'st-expired' : (d.status === 'Payable' ? 'st-soon' : 'st-active');
      var label = d.status === 'Settled' ? 'Settled' : d.status + ' ' + moneyS(d.amount);
      $box.html('<span class="badge ' + cls + '">' + esc(label) + '</span>' +
        '<div class="cp-nums">' +
        '<span>Receivable (owes us): <strong>' + esc(moneyS(d.receivable)) + '</strong></span>' +
        '<span>Payable (we owe): <strong>' + esc(moneyS(d.payable)) + '</strong></span>' +
        '<span>Deposit balance: <strong>' + esc(moneyS(d.deposit)) + '</strong></span>' +
        '</div>');
    }

    // prefix "sa" or "qu"; an answer that arrives after the customer changed is ignored (sequence counter)
    function cpLoad(prefix, selSel, boxSel, invoiceId) {
      var $box = $(boxSel), v = String($(selSel).val() || '');
      var seq = ++cpSeq[prefix];
      if (!v) { $box.prop('hidden', true).empty(); return; }
      if (v === 'walkin') { $box.prop('hidden', false).text('Walk-in customer: no balance. Paid in full.'); return; }
      $box.prop('hidden', false).text('Loading customer position\u2026');
      $.ajax({ url: URL_SELF, type: 'POST', dataType: 'json', data: { action: 'customerposition', csrf_token: CSRF, customerid: v, invoiceid: invoiceId || 0 } })
        .done(function (res) {
          if (seq !== cpSeq[prefix]) { return; }
          if (!res.success) { $box.text(res.message); return; }
          if (res.data.walkin) { $box.text('Walk-in customer: no balance. Paid in full.'); return; }
          cpRender($box, res.data);
        })
        .fail(function () { if (seq === cpSeq[prefix]) { $box.text('The customer position could not be loaded.'); } });
    }

    function saCustPosRefresh() { cpLoad('sa', '#saCustomer', '#saCustPos', saEditId || 0); }
    function quCustPosRefresh() { cpLoad('qu', '#quCustomer', '#quCustPos', 0); }
    function cpRefresh() {
      if ($('#saleModal').hasClass('show')) { saCustPosRefresh(); }
      if ($('#quotationModal').hasClass('show')) { quCustPosRefresh(); }
    }

    $('#saCustomer').on('change', saCustPosRefresh);
    $('#quCustomer').on('change', quCustPosRefresh);
    $('#saleModal').on('shown.bs.modal', saCustPosRefresh);
    $('#quotationModal').on('shown.bs.modal', quCustPosRefresh);
</script>

</body>
</html>