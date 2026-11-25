<?php

declare(strict_types=1);

/**
 * pages/balancesheet.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: Balance Sheet (sidebar > Reports > Balance Sheet).
 *
 * One page, one AJAX action (POST, JSON): report. No filter card, no Find button: the sheet loads by itself
 * "as of today" (server date) through one POST on DOM ready. It is a centered title block (company, "Balance Sheet",
 * "As of: <date>") and one two-column table (Assets, Liabilities, Owners Equity and the grand total line), a
 * DataTables Buttons export bar of the same lines and a Print button.
 * The page is read-only: it performs no INSERT, UPDATE or DELETE and writes no activity row. The GET request runs
 * no database query.
 *
 * The sheet is NOT forced to balance: the numbers are printed as computed (stock is valued at today's values, so a
 * past date cannot be asked). Every movement dated in the future (> today) is ignored. Amounts are added as integer
 * cents (dt_cents).
 *
 * Cash = every account of type 'Assets' (any status, parents and children), movements summed per row, never as
 * parent + child balances. Account transfers are not added (net zero in total cash). Retained Earnings is the
 * all-time net profit (dt_net_profit_cents), so this page agrees with the Income Statement and the Equity Statement.
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call dt_require_permission for Reports > Balance Sheet (menu 8, submenu 19,
 * key "8-19") right after the "NO LOGIN" marker in the request flow below, for the page and for the report action.
 * Single tenant.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';

if (!defined('DT_ALLOW_CDN')) {
    define('DT_ALLOW_CDN', true); // opt-in: allows the vetted CDN hosts in the CSP for this page only
}

/* ================================================================
 * 1. CONSTANTS AND CONFIG
 * ============================================================== */

const DT_LOGO_FILE = 'bushrofarmingandlivestocklogo.png'; // preferred logo file name
const DT_LOGO_DIRS = ['uploads', 'assets', 'images'];     // folders next to pages/ searched for the logo (in this order)

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
 * 3. DATA AND AJAX ACTION
 * ============================================================== */

/**
 * Convert an amount (number or decimal text) to integer cents, so sums are added without float drift.
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
 * Run a query that returns one sum over a date range and give it in cents.
 *
 * @param PDO                  $pdo    Connection.
 * @param string               $sql    SQL returning one value, with the placeholders :f and :t.
 * @param array{f:string,t:string} $range Date range.
 *
 * @return int Cents (0 when there is no row).
 */
function dt_sum_cents(PDO $pdo, string $sql, array $range): int
{
    return dt_cents(dt_q($pdo, $sql, $range)->fetchColumn());
}

/**
 * Net profit of a date range, in integer cents (negative for a loss), with the rules of the Income Statement:
 * net sales (livestock and product sales - sales returns) - cost of goods sold (costeach snapshot of the sold
 * lines - cost of the returned lines) - payroll charged by bill date - asset write-downs by adjustment date
 * - operating expenses by expense date.
 *
 * @param PDO    $pdo  Connection.
 * @param string $from First day (Y-m-d).
 * @param string $to   Last day (Y-m-d).
 *
 * @return int Net profit in cents.
 */
function dt_net_profit_cents(PDO $pdo, string $from, string $to): int
{
    $range = ['f' => $from, 't' => $to];

    $sales    = 0;
    $soldCost = 0;
    $itemRows = dt_q(
        $pdo,
        'SELECT COALESCE(SUM(ii.linetotal), 0) AS sales, COALESCE(SUM(ii.quantity * ii.costeach), 0) AS cost
           FROM invoiceitems ii
           JOIN invoices i ON i.invoiceid = ii.invoiceid
          WHERE i.invoicedate >= :f AND i.invoicedate <= :t',
        $range
    )->fetchAll();
    foreach ($itemRows as $r) {
        $sales    += dt_cents($r['sales']);
        $soldCost += dt_cents($r['cost']);
    }

    $returns = dt_sum_cents(
        $pdo,
        'SELECT COALESCE(SUM(totalamount), 0) FROM salereturns WHERE returndate >= :f AND returndate <= :t',
        $range
    );

    $returnedCost = dt_sum_cents(
        $pdo,
        'SELECT COALESCE(SUM(sri.quantity * ii.costeach), 0)
           FROM salereturnitems sri
           JOIN salereturns r ON r.returnid = sri.returnid
           JOIN invoiceitems ii ON ii.invoiceitemid = sri.invoiceitemid
          WHERE r.returndate >= :f AND r.returndate <= :t',
        $range
    );

    $payroll = dt_sum_cents(
        $pdo,
        'SELECT COALESCE(SUM(pbi.amount), 0)
           FROM payrollbillitems pbi
           JOIN payrollbills pb ON pb.payrollbillid = pbi.payrollbillid
          WHERE pb.billdate >= :f AND pb.billdate <= :t',
        $range
    );

    $expenses = dt_sum_cents(
        $pdo,
        'SELECT COALESCE(SUM(e.amount), 0)
           FROM expenses e
           JOIN accounts a ON a.accountid = e.expenseaccountid
          WHERE e.expensedate >= :f AND e.expensedate <= :t',
        $range
    );

    $writeDowns = dt_sum_cents(
        $pdo,
        'SELECT COALESCE(SUM(amount), 0) FROM assetadjustments WHERE adjustmentdate >= :f AND adjustmentdate <= :t',
        $range
    );

    return ($sales - $returns) - ($soldCost - $returnedCost) - ($payroll + $writeDowns + $expenses);
}

/**
 * Named parameters :d1 .. :dN that all hold the same date (each name is used once per statement).
 *
 * @param int    $count Number of placeholders in the statement.
 * @param string $date  Date (Y-m-d).
 *
 * @return array<string,string> ['d1' => date, 'd2' => date, ...].
 */
function dt_date_params(int $count, string $date): array
{
    $params = [];
    for ($k = 1; $k <= $count; $k++) {
        $params['d' . $k] = $date;
    }

    return $params;
}

/**
 * Balance sheet as of today: assets, liabilities and owner's equity.
 *
 * Separate aggregated statements (no join multiplies a sum; assets and their adjustments are summed per asset in a
 * derived table). Every value is turned into integer cents before it is added or subtracted. Nothing is clamped
 * except the remaining value of an asset (never below 0). The sheet is not forced to balance.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_report(PDO $pdo): array
{
    $today = date('Y-m-d');
    $cash  = 'IN (SELECT accountid FROM accounts WHERE accounttype = \'Assets\')'; // every Assets account, any status

    // A. Cash = money in - money out over every Assets account (account transfers are net zero, not added).
    $c = dt_q(
        $pdo,
        "SELECT
           (SELECT COALESCE(SUM(amount), 0) FROM receipts WHERE accountid {$cash} AND receiptdate <= :d1) AS rec,
           (SELECT COALESCE(SUM(amount), 0) FROM customerdeposits WHERE accountid {$cash} AND depositdate <= :d2) AS dep,
           (SELECT COALESCE(SUM(amount), 0) FROM equity WHERE equitytype = 'Capital' AND accountid {$cash} AND equitydate <= :d3) AS cap,
           (SELECT COALESCE(SUM(paidamount), 0) FROM purchases WHERE paidfromaccountid {$cash} AND purchasedate <= :d4) AS purpaid,
           (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE accountid {$cash} AND paymentdate <= :d5) AS pay,
           (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE paidfromaccountid {$cash} AND expensedate <= :d6) AS exp,
           (SELECT COALESCE(SUM(amount), 0) FROM salerefunds WHERE accountid {$cash} AND refunddate <= :d7) AS sref,
           (SELECT COALESCE(SUM(amount), 0) FROM customerdepositrefunds WHERE accountid {$cash} AND refunddate <= :d8) AS dref,
           (SELECT COALESCE(SUM(amount), 0) FROM payrollpayments WHERE accountid {$cash} AND paymentdate <= :d9) AS payroll,
           (SELECT COALESCE(SUM(amount), 0) FROM equity WHERE equitytype = 'Drawing' AND accountid {$cash} AND equitydate <= :d10) AS draw",
        dt_date_params(10, $today)
    )->fetch();
    $cashCents = dt_cents($c['rec']) + dt_cents($c['dep']) + dt_cents($c['cap'])
        - dt_cents($c['purpaid']) - dt_cents($c['pay']) - dt_cents($c['exp']) - dt_cents($c['sref'])
        - dt_cents($c['dref']) - dt_cents($c['payroll']) - dt_cents($c['draw']);

    // B. Account Receivable = invoices - returns - receipts - deposits used + sale refunds (may be negative).
    $r = dt_q(
        $pdo,
        'SELECT
           (SELECT COALESCE(SUM(totalamount), 0) FROM invoices WHERE invoicedate <= :d1) AS inv,
           (SELECT COALESCE(SUM(totalamount), 0) FROM salereturns WHERE returndate <= :d2) AS ret,
           (SELECT COALESCE(SUM(amount), 0) FROM receipts WHERE receiptdate <= :d3) AS rec,
           (SELECT COALESCE(SUM(amount), 0) FROM customerdepositusages WHERE usagedate <= :d4) AS used,
           (SELECT COALESCE(SUM(amount), 0) FROM salerefunds WHERE refunddate <= :d5) AS ref',
        dt_date_params(5, $today)
    )->fetch();
    $receivable = dt_cents($r['inv']) - dt_cents($r['ret']) - dt_cents($r['rec'])
        - dt_cents($r['used']) + dt_cents($r['ref']);

    // C. Stock on hand today (a snapshot, no date).
    $s = dt_q(
        $pdo,
        'SELECT
           (SELECT COALESCE(SUM(currentstock * costeach), 0) FROM livestock) AS livestock,
           (SELECT COALESCE(SUM(currentstock * unitcost), 0) FROM products) AS products'
    )->fetch();
    $livestockInv = dt_cents($s['livestock']);
    $productInv   = dt_cents($s['products']);

    // D. Equipments and Supplies: remaining value per asset (amount - adjustments dated <= today), never below 0.
    $assetRows = dt_q(
        $pdo,
        'SELECT a.assettype, a.amount, COALESCE(j.s, 0) AS adjusted
           FROM assets a
      LEFT JOIN (SELECT assetid, SUM(amount) AS s FROM assetadjustments WHERE adjustmentdate <= :d GROUP BY assetid) j
             ON j.assetid = a.assetid
          WHERE a.assetdate <= :d2',
        ['d' => $today, 'd2' => $today]
    )->fetchAll();
    $equipments = 0;
    $supplies   = 0;
    foreach ($assetRows as $a) {
        $remaining = max(0, dt_cents($a['amount']) - dt_cents($a['adjusted']));
        if ((string)$a['assettype'] === 'Equipment') {
            $equipments += $remaining;
        } elseif ((string)$a['assettype'] === 'Supply') {
            $supplies += $remaining;
        }
    }

    // E, F, G. Liabilities: payable to suppliers, unpaid payroll, customer deposits not used or refunded.
    $l = dt_q(
        $pdo,
        'SELECT
           (SELECT COALESCE(SUM(totalamount), 0) FROM purchases WHERE purchasedate <= :d1) AS purtotal,
           (SELECT COALESCE(SUM(paidamount), 0) FROM purchases WHERE purchasedate <= :d2) AS purpaid,
           (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE paymentdate <= :d3) AS pay,
           (SELECT COALESCE(SUM(pbi.amount), 0) FROM payrollbillitems pbi
              JOIN payrollbills pb ON pb.payrollbillid = pbi.payrollbillid WHERE pb.billdate <= :d4) AS billed,
           (SELECT COALESCE(SUM(amount), 0) FROM payrollpayments WHERE paymentdate <= :d5) AS salpaid,
           (SELECT COALESCE(SUM(amount), 0) FROM customerdeposits WHERE depositdate <= :d6) AS dep,
           (SELECT COALESCE(SUM(amount), 0) FROM customerdepositusages WHERE usagedate <= :d7) AS used,
           (SELECT COALESCE(SUM(amount), 0) FROM customerdepositrefunds WHERE refunddate <= :d8) AS dref',
        dt_date_params(8, $today)
    )->fetch();
    $payable     = dt_cents($l['purtotal']) - dt_cents($l['purpaid']) - dt_cents($l['pay']);
    $payrollLiab = dt_cents($l['billed']) - dt_cents($l['salpaid']);
    $deposits    = dt_cents($l['dep']) - dt_cents($l['used']) - dt_cents($l['dref']);

    // H. Equity: capital and drawings by date, retained earnings = all-time net profit up to today.
    $q = dt_q(
        $pdo,
        "SELECT
           (SELECT COALESCE(SUM(amount), 0) FROM equity WHERE equitytype = 'Capital' AND equitydate <= :d1) AS capital,
           (SELECT COALESCE(SUM(amount), 0) FROM equity WHERE equitytype = 'Drawing' AND equitydate <= :d2) AS drawing",
        dt_date_params(2, $today)
    )->fetch();
    $invested = dt_cents($q['capital']);
    $drawings = dt_cents($q['drawing']);
    $retained = dt_net_profit_cents($pdo, '1000-01-01', $today);

    $totalCurrent     = $cashCents + $receivable + $livestockInv + $productInv + $supplies;
    $totalAssets      = $totalCurrent + $equipments;
    $totalLiabilities = $payable + $payrollLiab + $deposits;
    $netEquity        = $invested + $retained - $drawings;
    $totalLE          = $totalLiabilities + $netEquity;

    $out = static fn (int $cents): float => round($cents / 100, 2);

    return [
        'success' => true,
        'date'    => date('M d, Y', strtotime($today)),
        'dateymd' => $today,
        'company' => 'Bushra Farming and Livestock',
        'values'  => [
            'cash'               => $out($cashCents),
            'receivable'         => $out($receivable),
            'livestockinventory' => $out($livestockInv),
            'productinventory'   => $out($productInv),
            'supplies'           => $out($supplies),
            'totalcurrent'       => $out($totalCurrent),
            'equipments'         => $out($equipments),
            'totalassets'        => $out($totalAssets),
            'payable'            => $out($payable),
            'payrollliability'   => $out($payrollLiab),
            'customerdeposits'   => $out($deposits),
            'totalliabilities'   => $out($totalLiabilities),
            'invested'           => $out($invested),
            'retained'           => $out($retained),
            'drawings'           => $out($drawings),
            'netequity'          => $out($netEquity),
            'totalle'            => $out($totalLE),
        ],
    ];
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

    $handlers = ['report' => 'dt_act_report'];
    $action   = (string)($_POST['action'] ?? '');
    if (!isset($handlers[$action])) {
        dt_json(dt_fail('Unknown action.'), 400);
    }

    try {
        dt_json($handlers[$action](dt_pdo()));
    } catch (PDOException $e) {
        dt_log_error('[balancesheet ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[balancesheet ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    }
}

/* ================================================================
 * 4. HTML SHELL (same look as incomestatement.php): CSS, head, topbar, sidebar, footer
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

/* ===== Cards ===== */
.card {
    background: #ffffff; min-width: 0; border: 0; border-radius: 0;
    box-shadow: 0 -3px 31px 0 rgba(0, 0, 0, 0.04), 0 6px 20px 0 rgba(0, 0, 0, 0.03);
}
.card-body { padding: 20px; }

/* ===== Buttons ===== */
.btn { border-radius: 0; font-size: 14px; font-weight: 500; }
.btn-sm { font-size: 13px; }
.btn-primary { background: var(--brand-primary); border-color: var(--brand-primary); color: #ffffff; }
.btn-primary:hover, .btn-primary:focus { background: var(--brand-primary-dark); border-color: var(--brand-primary-dark); color: #ffffff; box-shadow: none; }
.btn-primary:not(:disabled):not(.disabled):active { background: var(--brand-primary-dark); border-color: var(--brand-primary-dark); }
.btn-secondary { background: #545b64; border-color: #545b64; color: #ffffff; }
.btn-secondary:hover, .btn-secondary:focus { background: #3f454c; border-color: #3f454c; color: #ffffff; box-shadow: none; }
.btn:focus-visible { outline: 3px solid var(--brand-accent); outline-offset: 2px; box-shadow: none; }

/* ===== Export buttons (DataTables Buttons) ===== */
.dt-buttons .btn { font-size: 13px; padding: 3px 10px; white-space: nowrap; }

/* ===== Toastr ===== */
#toast-container > div { border-radius: 0; opacity: .97; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18); font-family: inherit; font-size: 14px; }
#toast-container > div:hover { box-shadow: 0 8px 24px rgba(0, 0, 0, 0.28); opacity: 1; }
.toast-error { background-color: var(--danger); }
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
 * Stylesheet additions for this page (balance sheet block, export bar, print rules).
 *
 * @return string CSS rules.
 */
function dt_balance_css(): string
{
    return <<<'CSS'
/* ===== Balance sheet (scoped to #dprint) ===== */
#dprint { overflow-x: auto; text-align: left; font-size: 13px; line-height: 1.35; color: var(--muted); }
#dprint .rp-wrap { min-width: 560px; }
#dprint .rp-titleblock { text-align: center; padding: 8px 0 10px; }
#dprint .rp-t1 { font-size: 16px; font-weight: 600; line-height: 1.3; color: var(--heading); }
#dprint .rp-t2 { font-size: 20px; font-weight: 600; line-height: 1.3; color: var(--heading); }
#dprint .rp-dates { font-size: 13px; white-space: nowrap; }
#dprint .rp-dates b { font-weight: 600; color: var(--heading); }

#dprint table.rp-table {
    width: 100%; table-layout: fixed; margin: 0; border-collapse: collapse;
    background: transparent; color: inherit; border: 1px solid var(--line);
}
#dprint table.rp-table td {
    height: 2em; line-height: 2; padding: 0 6px; vertical-align: middle; border: 1px solid var(--line); text-align: left;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
#dprint table.rp-table td:nth-child(1) { width: 67%; }
#dprint table.rp-table td:nth-child(2) { width: 33%; text-align: right; }
#dprint table.rp-table tr.l0 td:first-child { padding-left: 1.5em; }
#dprint table.rp-table tr.l1 td:first-child { padding-left: 3.4em; }
#dprint table.rp-table tr.l2 td:first-child { padding-left: 4.4em; }
#dprint table.rp-table tr.gt td:first-child { padding-left: 2.2em; }
#dprint table.rp-table tr.sec td { color: var(--heading); font-weight: 400; }
#dprint table.rp-table tr.it td { color: var(--muted); }
#dprint table.rp-table tr.tt td, #dprint table.rp-table tr.gt td { color: var(--heading); font-weight: 700; }
#dprint table.rp-table tr.sp td { height: .6em; line-height: .6; padding: 0; }

/* ===== Export bar ===== */
#exportBar { display: flex; justify-content: center; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
#exportBar .dt-buttons { display: flex; flex-wrap: wrap; justify-content: center; }

/* ===== Print ===== */
@page { size: A4 portrait; margin: 8mm; }
@media print {
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    html, body { background: #ffffff !important; min-height: 0; overflow: visible; }
    .topbar, .side-menu, .backdrop, .footer, .skip-link, .page-title-box, #prtWrap,
    #exportBar, #exportHold, .scroll-to-top, #toast-container { display: none !important; }
    .content-page { margin: 0 !important; min-height: 0 !important; display: block; }
    .content { padding: 0 !important; }
    #reportCard { box-shadow: none !important; background: #ffffff !important; }
    #reportCard .card-body { padding: 0 !important; }
    #dprint { font-size: 12px; overflow: visible; width: 100%; }
    #dprint .rp-wrap { min-width: 0; }
    #dprint table.rp-table, #dprint table.rp-table td { border: 0; }
    #dprint tr { break-inside: avoid; page-break-inside: avoid; }
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

  <!-- CDN: Poppins, Bootstrap 4.6, Font Awesome 5 (content icons), DataTables Buttons (export), Toastr -->
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

<?= dt_balance_css() ?>
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
            $isReports = ($key === 'reports'); ?>
            <li>
                <?php if ($subs): ?>
                    <button type="button" class="nav-btn" data-sub title="<?= dt_e($label) ?>" aria-expanded="<?= $isReports ? 'true' : 'false' ?>" aria-controls="sub-<?= dt_e($key) ?>">
                        <?= dt_icon($icon) ?> <span><?= dt_e($label) ?></span><?= dt_icon('caret') ?>
                    </button>
                    <div class="sub<?= $isReports ? ' show' : '' ?>" id="sub-<?= dt_e($key) ?>">
                        <?php foreach ($subs as [$subLabel, $subPage]): ?>
                            <a href="<?= dt_e($subPage) ?>"<?= ($isReports && $subPage === 'balancesheet.php') ? ' class="active" aria-current="page"' : '' ?>><span><?= dt_e($subLabel) ?></span></a>
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
 * 5. PAGE MARKUP
 * ============================================================== */

/**
 * Report card (no filter, no form).
 *
 * @return void
 */
function dt_view_balance_sheet(): void
{
    ?>
        <div class="row">
          <div class="col-12 mb-4">
            <div class="card" id="reportCard">
              <div class="card-body">
                <div id="exportBar" class="d-none"></div>
                <div id="dprint"></div>
                <div id="prtWrap" class="text-right mt-2 d-none">
                  <button type="button" class="btn btn-primary btn-sm" id="btnPrint"><i class="fas fa-print mr-1" aria-hidden="true"></i> Print</button>
                </div>
                <div id="exportHold" class="d-none"></div>
              </div>
            </div>
          </div>
        </div>
<?php
}

/* ================================================================
 * 6. REQUEST FLOW
 * ============================================================== */

/* ================================================================
 * NO LOGIN: nothing is checked here for now (no session, no permission).
 * When login returns, call dt_require_permission for Reports > Balance Sheet
 * (menu 8, submenu 19, key "8-19") right here: it covers the page (GET) and the report action (POST).
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

/* ---------- GET: no database query; collect the page values, then release the session ---------- */

$csrf        = dt_csrf_token();
$csrfField   = dt_csrf_field();
$userName    = 'Administrator';
$initial     = mb_strtoupper(mb_substr($userName, 0, 1)) ?: 'A';
$agencyName  = 'Bushra Farming and Livestock';
$logoutUrl   = '../index.php';
$logoUrl     = dt_find_logo(DT_LOGO_FILE);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/**
 * Sidebar: key, label, icon, page, submenu links (label, page). Same menu as pages/incomestatement.php.
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
    'csrfField' => $csrfField, 'docTitle' => 'Balance Sheet', 'heading' => 'Balance Sheet',
    'sub' => "Review the financial position of the farm as of today: assets, liabilities and owner's equity.",
];

/* ---------- Render ---------- */

dt_render_open($page);
dt_view_balance_sheet();
dt_render_close();

$cfg       = ['csrf' => $csrf, 'self' => 'balancesheet.php'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$n         = dt_n();

/* ================================================================
 * 7. JAVASCRIPT
 * ============================================================== */
?>

<a class="scroll-to-top" href="#main" aria-label="Back to top"><i class="fas fa-angle-up" aria-hidden="true"></i></a>

<!-- CDN: jQuery 3.5.1 + Bootstrap 4.6 bundle (includes Popper) -->
<script nonce="<?= $n ?>" src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- CDN: DataTables + Buttons (export bar; Print uses window.print()) -->
<script nonce="<?= $n ?>" src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap4.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script nonce="<?= $n ?>" src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
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
    /* ---------- Helpers ---------- */
    // "$1,200.00", negatives in parentheses "($25.00)" (never "($0.00)")
    function money(n) {
      n = Number(n) || 0;
      var s = Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      var isZero = Number(s.replace(/,/g, '')) === 0;
      return (n < 0 && !isZero) ? '($' + s + ')' : '$' + s;
    }

    /* ---------- Rows (screen and exports) ---------- */
    // t: sec = section, it = item, tt = total, gt = grand total, sp = spacer (screen and print only); l = indent level
    var ROWS = [
      { t: 'sec', l: 0, label: 'Assets' },
      { t: 'sec', l: 1, label: 'Current Assets' },
      { t: 'it',  l: 2, label: 'Cash',                 key: 'cash' },
      { t: 'it',  l: 2, label: 'Account Receivable',   key: 'receivable' },
      { t: 'it',  l: 2, label: 'Livestock Inventory',  key: 'livestockinventory' },
      { t: 'it',  l: 2, label: 'Product Inventory',    key: 'productinventory' },
      { t: 'it',  l: 2, label: 'Supplies',             key: 'supplies' },
      { t: 'tt',  l: 1, label: 'Total Current Assets', key: 'totalcurrent' },
      { t: 'it',  l: 2, label: 'Equipments',           key: 'equipments' },
      { t: 'tt',  l: 1, label: 'Total Assets',         key: 'totalassets' },
      { t: 'sp' },
      { t: 'sec', l: 0, label: 'Liabilities' },
      { t: 'it',  l: 2, label: 'Account Payable',      key: 'payable' },
      { t: 'it',  l: 2, label: 'Payroll Liability',    key: 'payrollliability' },
      { t: 'it',  l: 2, label: 'Customer Deposits',    key: 'customerdeposits' },
      { t: 'tt',  l: 1, label: 'Total Liabilities',    key: 'totalliabilities' },
      { t: 'sec', l: 0, label: 'Owners Equity' },
      { t: 'it',  l: 2, label: 'Invested Capital',     key: 'invested' },
      { t: 'it',  l: 2, label: 'Add: Retained Earnings', key: 'retained' },
      { t: 'it',  l: 2, label: 'Less: Drawings',       key: 'drawings', neg: true },
      { t: 'tt',  l: 1, label: 'Net Owner\u2019s Equity', key: 'netequity' },
      { t: 'gt',  l: 0, label: 'Total Liabilities and Owners Equity', key: 'totalle' }
    ];

    // Amount text of a row ('' for rows without a key); drawings arrive positive and are shown negative
    function rowAmount(rw, v) {
      if (!rw.key) { return ''; }
      var n = Number(v[rw.key]) || 0;
      return money(rw.neg ? -n : n);
    }

    /* ---------- Report (printable block) ---------- */
    function buildReport(res) {
      var h = '<div class="rp-wrap">';

      h += '<div class="rp-titleblock">' +
             '<div class="rp-t1">' + esc(res.company) + '</div>' +
             '<div class="rp-t2">Balance Sheet</div>' +
             '<div class="rp-dates"><b>As of:</b> ' + esc(res.date) + '</div>' +
           '</div>';

      h += '<table class="rp-table"><tbody>';
      for (var i = 0; i < ROWS.length; i++) {
        var rw = ROWS[i];
        if (rw.t === 'sp') {
          h += '<tr class="sp"><td></td><td></td></tr>';
          continue;
        }
        h += '<tr class="' + esc(rw.t) + ' l' + esc(rw.l) + '"><td>' + esc(rw.label) + '</td><td>' +
             esc(rowAmount(rw, res.values)) + '</td></tr>';
      }
      h += '</tbody></table></div>';
      $('#dprint').html(h);
    }

    /* ---------- Export bar (hidden #tblExport -> DataTables Buttons, rows without the spacer) ---------- */
    function buildExport(res) {
      if ($.fn.DataTable.isDataTable('#tblExport')) { $('#tblExport').DataTable().destroy(); }
      $('#exportBar').empty();

      var t = '<table id="tblExport"><thead><tr><th>Description</th><th>Amount</th></tr></thead><tbody>';
      for (var i = 0; i < ROWS.length; i++) {
        var rw = ROWS[i];
        if (rw.t === 'sp') { continue; }
        t += '<tr><td>' + esc(rw.label) + '</td><td>' + esc(rowAmount(rw, res.values)) + '</td></tr>';
      }
      t += '</tbody></table>';
      $('#exportHold').html(t);

      var title = 'Balance Sheet';
      var fname = ('Balance Sheet ' + res.dateymd).replace(/[\\\/:*?"<>|]/g, '-');
      var base  = { className: 'btn btn-secondary btn-sm', title: title, filename: fname, footer: false };

      var dt = $('#tblExport').DataTable({
        paging: false, searching: false, ordering: false, info: false, autoWidth: false,
        dom: 'B',
        buttons: [
          $.extend({}, base, { extend: 'copyHtml5',  text: '<i class="fas fa-copy" aria-hidden="true"></i> Copy', title: '' }),
          $.extend({}, base, { extend: 'csvHtml5',   text: '<i class="fas fa-file-csv" aria-hidden="true"></i> CSV' }),
          $.extend({}, base, { extend: 'excelHtml5', text: '<i class="fas fa-file-excel" aria-hidden="true"></i> Excel' }),
          $.extend({}, base, {
            extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf" aria-hidden="true"></i> PDF',
            orientation: 'portrait', pageSize: 'A4',
            messageTop: res.company + ' | As of: ' + res.date,
            customize: function (doc) {
              doc.defaultStyle.fontSize = 9;
              if (doc.styles && doc.styles.tableHeader) { doc.styles.tableHeader.fontSize = 9; }
              var tbl = null;
              $.each(doc.content, function (_, c) { if (c.table) { tbl = c.table; } });
              if (!tbl) { return; }
              tbl.widths = ['*', 120];
              $.each(tbl.body, function (_, row) {
                if (row[1]) { row[1].alignment = 'right'; } // Amount column
              });
            }
          }),
          {
            className: 'btn btn-secondary btn-sm',
            text: '<i class="fas fa-print" aria-hidden="true"></i> Print',
            action: function () { window.print(); }
          }
        ]
      });
      dt.buttons().container().appendTo('#exportBar');
      $('#exportBar').removeClass('d-none');
      $('#prtWrap').removeClass('d-none');
    }

    /* ---------- Load on DOM ready (no filter, no Find button) ---------- */
    $(document).ready(function () {
      api({ action: 'report' }).done(function (res) {
        if (res.success) {
          buildReport(res);
          buildExport(res);
        } else {
          toastr.error(res.message);
        }
      });
    });

    /* ---------- Print (only #dprint is visible, see @media print) ---------- */
    $('#btnPrint').on('click', function () { window.print(); });
</script>
</body>
</html>