<?php

declare(strict_types=1);

/**
 * pages/cashflow.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: Cash Flow (sidebar > Reports > Cash Flow).
 *
 * One page, one AJAX action (POST, JSON): report. A filter card holds From, To and a Find button. The statement
 * builds itself on DOM ready with the defaults (first day of the month to today) and again on Find (form submit,
 * Enter works). It is a centered title block and one table (Operating Activities, Financing Activities, Net
 * Increase (Decrease) in Cash, Opening Cash and Closing Cash), a DataTables Buttons export bar of the same lines
 * and a Print button.
 * The page is read-only: it performs no INSERT, UPDATE or DELETE and writes no activity row. The GET request
 * runs no database query.
 *
 * Direct-method CASH statement of a date range: only rows that moved money through the cash accounts are used
 * (invoices, purchase totals, payroll bills, asset adjustments and stock are never cash). Cash accounts = every
 * account of type 'Assets' (any status, parents and children), movements summed per row, so nothing is counted
 * twice. Account transfers net to zero and are not used; assets never move cash, so there is no Investing section.
 * Opening Cash is the running balance of ALL history before From, so Closing Cash of a range equals the Opening
 * Cash of the range that starts the next day, and equals the Cash line of the Balance Sheet for a range ending today.
 * Amounts are added as integer cents (dt_cents).
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call dt_require_permission for Reports > Cash Flow (menu 8, submenu 20,
 * key "8-20") right after the "NO LOGIN" marker in the request flow below, for the page and for the report action.
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

/** Business-rule failure: message is shown to the user. */
final class DtFail extends RuntimeException
{
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
 * Money that moved through the cash accounts in a date range, by kind, in integer cents (always positive).
 *
 * Cash accounts = every account of type 'Assets' (any status). Separate aggregated statements, each with the
 * placeholders :f and :t used once, so no join multiplies a sum. Account transfers, invoices, purchase totals,
 * payroll bills, assets and stock are never read.
 *
 * @param PDO    $pdo  Connection.
 * @param string $from First day (Y-m-d).
 * @param string $to   Last day (Y-m-d).
 *
 * @return array{customers:int,depositsin:int,depositrefunds:int,salerefunds:int,suppliers:int,payroll:int,expenses:int,capital:int,drawings:int} Cents.
 */
function dt_cash_flows(PDO $pdo, string $from, string $to): array
{
    $cash  = 'IN (SELECT accountid FROM accounts WHERE accounttype = \'Assets\')';
    $range = ['f' => $from, 't' => $to];
    $sum   = static fn (string $sql): int => dt_cents(dt_q($pdo, $sql, $range)->fetchColumn());

    return [
        'customers' => $sum("SELECT COALESCE(SUM(amount), 0) FROM receipts
                              WHERE accountid {$cash} AND receiptdate >= :f AND receiptdate <= :t"),
        'depositsin' => $sum("SELECT COALESCE(SUM(amount), 0) FROM customerdeposits
                               WHERE accountid {$cash} AND depositdate >= :f AND depositdate <= :t"),
        'depositrefunds' => $sum("SELECT COALESCE(SUM(amount), 0) FROM customerdepositrefunds
                                   WHERE accountid {$cash} AND refunddate >= :f AND refunddate <= :t"),
        'salerefunds' => $sum("SELECT COALESCE(SUM(amount), 0) FROM salerefunds
                                WHERE accountid {$cash} AND refunddate >= :f AND refunddate <= :t"),
        // Paid to suppliers: the payment made with the purchase + later payments.
        'suppliers' => $sum("SELECT COALESCE(SUM(paidamount), 0) FROM purchases
                              WHERE paidfromaccountid {$cash} AND purchasedate >= :f AND purchasedate <= :t")
            + $sum("SELECT COALESCE(SUM(amount), 0) FROM payments
                     WHERE accountid {$cash} AND paymentdate >= :f AND paymentdate <= :t"),
        'payroll' => $sum("SELECT COALESCE(SUM(amount), 0) FROM payrollpayments
                            WHERE accountid {$cash} AND paymentdate >= :f AND paymentdate <= :t"),
        'expenses' => $sum("SELECT COALESCE(SUM(amount), 0) FROM expenses
                             WHERE paidfromaccountid {$cash} AND expensedate >= :f AND expensedate <= :t"),
        'capital' => $sum("SELECT COALESCE(SUM(amount), 0) FROM equity
                            WHERE equitytype = 'Capital' AND accountid {$cash} AND equitydate >= :f AND equitydate <= :t"),
        'drawings' => $sum("SELECT COALESCE(SUM(amount), 0) FROM equity
                             WHERE equitytype = 'Drawing' AND accountid {$cash} AND equitydate >= :f AND equitydate <= :t"),
    ];
}

/**
 * Cash flow statement of a date range (direct method).
 *
 * Operating = customers + deposits received - deposit refunds - sales refunds - suppliers - payroll - expenses.
 * Financing = capital - drawings. Opening Cash = net of all history before From (may be negative).
 * Closing Cash = Opening Cash + net change. Every value is integer cents until the output.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On an invalid date range.
 */
function dt_act_report(PDO $pdo): array
{
    $fromRaw = $_POST['DateFrom'] ?? '';
    $toRaw   = $_POST['DateTo'] ?? '';
    $fromRaw = is_string($fromRaw) ? trim($fromRaw) : '';
    $toRaw   = is_string($toRaw) ? trim($toRaw) : '';
    if (!dt_valid_date($fromRaw) || !dt_valid_date($toRaw)) {
        throw new DtFail('A valid date range is required.');
    }
    if ($fromRaw > $toRaw) {
        throw new DtFail('The start date cannot be after the end date.');
    }

    $p         = dt_cash_flows($pdo, $fromRaw, $toRaw);
    $operating = $p['customers'] + $p['depositsin'] - $p['depositrefunds'] - $p['salerefunds']
        - $p['suppliers'] - $p['payroll'] - $p['expenses'];
    $financing = $p['capital'] - $p['drawings'];
    $netChange = $operating + $financing;

    // Opening Cash: cash is a running balance, so it is the net of everything before From.
    $dayBefore = (new DateTimeImmutable($fromRaw))->modify('-1 day')->format('Y-m-d');
    $h         = dt_cash_flows($pdo, '1000-01-01', $dayBefore);
    $opening   = ($h['customers'] + $h['depositsin'] + $h['capital'])
        - ($h['depositrefunds'] + $h['salerefunds'] + $h['suppliers'] + $h['payroll'] + $h['expenses'] + $h['drawings']);
    $closing   = $opening + $netChange;

    $out = static fn (int $cents): float => round($cents / 100, 2);

    return [
        'success'        => true,
        'from'           => date('M d, Y', strtotime($fromRaw)),
        'to'             => date('M d, Y', strtotime($toRaw)),
        'fromymd'        => $fromRaw,
        'toymd'          => $toRaw,
        'company'        => 'Bushra Farming and Livestock',
        'customers'      => $out($p['customers']),
        'depositsin'     => $out($p['depositsin']),
        'depositrefunds' => $out($p['depositrefunds']),
        'salerefunds'    => $out($p['salerefunds']),
        'suppliers'      => $out($p['suppliers']),
        'payroll'        => $out($p['payroll']),
        'expenses'       => $out($p['expenses']),
        'operating'      => $out($operating),
        'capital'        => $out($p['capital']),
        'drawings'       => $out($p['drawings']),
        'financing'      => $out($financing),
        'netchange'      => $out($netChange),
        'opening'        => $out($opening),
        'closing'        => $out($closing),
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
    } catch (DtFail $e) {
        dt_json(dt_fail($e->getMessage()));
    } catch (PDOException $e) {
        dt_log_error('[cashflow ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[cashflow ' . $action . '] ' . dt_describe_throwable($e));
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
.card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
.header-title { font-size: 17px; font-weight: 600; color: var(--brand-primary); }

/* ===== Buttons ===== */
.btn { border-radius: 0; font-size: 14px; font-weight: 500; }
.btn-sm { font-size: 13px; }
.btn-primary { background: var(--brand-primary); border-color: var(--brand-primary); color: #ffffff; }
.btn-primary:hover, .btn-primary:focus { background: var(--brand-primary-dark); border-color: var(--brand-primary-dark); color: #ffffff; box-shadow: none; }
.btn-primary:not(:disabled):not(.disabled):active { background: var(--brand-primary-dark); border-color: var(--brand-primary-dark); }
.btn-secondary { background: #545b64; border-color: #545b64; color: #ffffff; }
.btn-secondary:hover, .btn-secondary:focus { background: #3f454c; border-color: #3f454c; color: #ffffff; box-shadow: none; }
.btn:focus-visible { outline: 3px solid var(--brand-accent); outline-offset: 2px; box-shadow: none; }

/* ===== Forms ===== */
label { color: var(--heading); font-weight: 500; font-size: 14px; }
.form-control {
    padding: 6px 10px; font-size: 15px; color: var(--text); background: #ffffff;
    border: 1px solid var(--line); border-radius: 0;
}
.form-control:focus { border-color: var(--brand-accent); box-shadow: 0 0 0 2px rgba(22, 160, 74, 0.35); outline: 0; color: var(--text); }
.btn-primary:disabled { background: var(--brand-primary); border-color: var(--brand-primary); color: #ffffff; opacity: .6; }

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
 * Stylesheet additions for this page (statement block, export bar, print rules).
 *
 * @return string CSS rules.
 */
function dt_report_css(): string
{
    return <<<'CSS'
/* ===== Cash flow statement (scoped to #dprint) ===== */
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
    padding: 3px 6px; vertical-align: middle; border: 1px solid var(--line); text-align: left;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
#dprint table.rp-table td:nth-child(1) { width: 68.3%; }
#dprint table.rp-table td:nth-child(2) { width: 31.7%; text-align: right; }
#dprint table.rp-table tr.sec td { color: var(--heading); font-weight: 400; }
#dprint table.rp-table tr.it td:first-child { padding-left: 3em; }
#dprint table.rp-table tr.tt td { color: var(--heading); font-weight: 700; }

/* ===== Export bar ===== */
#exportBar { display: flex; justify-content: center; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
#exportBar .dt-buttons { display: flex; flex-wrap: wrap; justify-content: center; }

/* ===== Print ===== */
@page { size: A4 portrait; margin: 8mm; }
@media print {
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    html, body { background: #ffffff !important; min-height: 0; overflow: visible; }
    .topbar, .side-menu, .backdrop, .footer, .skip-link, .page-title-box, #filterCard, #prtWrap,
    #exportBar, #exportHold, .scroll-to-top, #toast-container { display: none !important; }
    .content-page { margin: 0 !important; min-height: 0 !important; display: block; }
    .content { padding: 0 !important; }
    #reportCard { box-shadow: none !important; background: #ffffff !important; }
    #reportCard .card-body { padding: 0 !important; }
    #dprint { font-size: 11px; overflow: visible; width: 100%; }
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

<?= dt_report_css() ?>
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
                            <a href="<?= dt_e($subPage) ?>"<?= ($isReports && $subPage === 'cashflow.php') ? ' class="active" aria-current="page"' : '' ?>><span><?= dt_e($subLabel) ?></span></a>
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
 * Filter card and report card.
 *
 * @param string $dateFrom Default From date (Y-m-d).
 * @param string $dateTo   Default To date (Y-m-d).
 *
 * @return void
 */
function dt_view_cash_flow(string $dateFrom, string $dateTo): void
{
    ?>
        <div class="row">
          <div class="col-12 mb-4">
            <div class="card" id="filterCard">
              <div class="card-body">
                <div class="card-head">
                  <h2 class="header-title">Cash Flow</h2>
                </div>

                <form id="filterForm" novalidate autocomplete="off">
                  <div class="form-row align-items-end">
                    <div class="form-group col-6 col-md-3">
                      <label for="DateFrom">From</label>
                      <input type="date" class="form-control" id="DateFrom" name="DateFrom" value="<?= dt_e($dateFrom) ?>" required>
                    </div>
                    <div class="form-group col-6 col-md-3">
                      <label for="DateTo">To</label>
                      <input type="date" class="form-control" id="DateTo" name="DateTo" value="<?= dt_e($dateTo) ?>" required>
                    </div>
                    <div class="form-group col-12 col-md-auto">
                      <button type="submit" class="btn btn-primary btn-block" id="btnFind"><i class="fas fa-search mr-1" aria-hidden="true"></i> Find</button>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>

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
 * When login returns, call dt_require_permission for Reports > Cash Flow
 * (menu 8, submenu 20, key "8-20") right here: it covers the page (GET) and the report action (POST).
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

$dateFrom = date('Y-m-01'); // default range: first day of the month to today
$dateTo   = date('Y-m-d');

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
    'csrfField' => $csrfField, 'docTitle' => 'Cash Flow', 'heading' => 'Cash Flow',
    'sub' => "Review the cash received and paid, the owners' capital and drawings, and the opening and closing cash for a date range.",
];

/* ---------- Render ---------- */

dt_render_open($page);
dt_view_cash_flow($dateFrom, $dateTo);
dt_render_close();

$cfg       = ['csrf' => $csrf, 'self' => 'cashflow.php'];
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
    var reqSeq = 0;

    /* ---------- Helpers ---------- */
    // "$1,200.00", "-$25.00" (never "-$0.00")
    function money(n) {
      n = Number(n) || 0;
      var s = Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      return ((n < 0 && Number(s.replace(/,/g, '')) !== 0) ? '-$' : '$') + s;
    }

    // Ordered lines shared by the screen statement and the export (sec = section, it = item, tt = total)
    function reportLines(res) {
      return [
        { t: 'sec', label: 'Operating Activities',                 amt: '' },
        { t: 'it',  label: 'Cash Received from Customers',         amt: money(res.customers) },
        { t: 'it',  label: 'Customer Deposits Received',           amt: money(res.depositsin) },
        { t: 'it',  label: 'Customer Deposits Refunded',           amt: money(-res.depositrefunds) },
        { t: 'it',  label: 'Sales Refunds Paid',                   amt: money(-res.salerefunds) },
        { t: 'it',  label: 'Paid to Suppliers',                    amt: money(-res.suppliers) },
        { t: 'it',  label: 'Payroll Paid',                         amt: money(-res.payroll) },
        { t: 'it',  label: 'Expenses Paid',                        amt: money(-res.expenses) },
        { t: 'tt',  label: 'Net Cash from Operating Activities',   amt: money(res.operating) },
        { t: 'sec', label: 'Financing Activities',                 amt: '' },
        { t: 'it',  label: 'Capital Contributed',                  amt: money(res.capital) },
        { t: 'it',  label: 'Owner Drawings',                       amt: money(-res.drawings) },
        { t: 'tt',  label: 'Net Cash from Financing Activities',   amt: money(res.financing) },
        { t: 'tt',  label: 'Net Increase (Decrease) in Cash',      amt: money(res.netchange) },
        { t: 'it',  label: 'Opening Cash',                         amt: money(res.opening) },
        { t: 'tt',  label: 'Closing Cash',                         amt: money(res.closing) }
      ];
    }

    /* ---------- Statement (printable block) ---------- */
    function buildReport(res) {
      var h = '<div class="rp-wrap">';

      h += '<div class="rp-titleblock">' +
             '<div class="rp-t1">' + esc(res.company) + '</div>' +
             '<div class="rp-t2">Cash Flow</div>' +
             '<div class="rp-dates">' + esc(res.from) + ' <b>UP</b> ' + esc(res.to) + '</div>' +
           '</div>';

      h += '<table class="rp-table"><tbody>';
      var L = reportLines(res);
      for (var i = 0; i < L.length; i++) {
        h += '<tr class="' + esc(L[i].t) + '"><td>' + esc(L[i].label) + '</td><td>' + esc(L[i].amt) + '</td></tr>';
      }
      h += '</tbody></table></div>';
      $('#dprint').html(h);
    }

    /* ---------- Export bar (hidden #tblExport -> DataTables Buttons, same lines) ---------- */
    function buildExport(res) {
      if ($.fn.DataTable.isDataTable('#tblExport')) { $('#tblExport').DataTable().destroy(); }
      $('#exportBar').empty();

      var t = '<table id="tblExport"><thead><tr><th>Description</th><th>Amount</th></tr></thead><tbody>';
      var L = reportLines(res);
      for (var i = 0; i < L.length; i++) {
        t += '<tr><td>' + esc(L[i].label) + '</td><td>' + esc(L[i].amt) + '</td></tr>';
      }
      t += '</tbody></table>';
      $('#exportHold').html(t);

      var title = 'Cash Flow';
      var fname = (title + ' ' + res.fromymd + ' to ' + res.toymd).replace(/[\\\/:*?"<>|]/g, '-');
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
            messageTop: res.company + ' | ' + res.from + ' UP ' + res.to,
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

    /* ---------- Find (form submit, also on DOM ready with the defaults) ---------- */
    function loadReport() {
      var from = $('#DateFrom').val();
      var to   = $('#DateTo').val();

      if (!from || !to) {
        toastr.error('Both From and To dates are required.');
        $(from ? '#DateTo' : '#DateFrom').trigger('focus');
        return;
      }
      if (from > to) {
        toastr.error('From date cannot be after To date.');
        $('#DateFrom').trigger('focus');
        return;
      }

      var seq  = ++reqSeq;
      var $btn = $('#btnFind').prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm mr-1" role="status" aria-hidden="true"></span> Find');

      api({ action: 'report', DateFrom: from, DateTo: to }).done(function (res) {
        if (seq !== reqSeq) { return; } // a newer request is running
        if (res.success) {
          buildReport(res);
          buildExport(res);
        } else {
          toastr.error(res.message);
        }
      }).always(function () {
        if (seq === reqSeq) {
          $btn.prop('disabled', false).html('<i class="fas fa-search mr-1" aria-hidden="true"></i> Find');
        }
      });
    }

    $('#filterForm').on('submit', function (e) {
      e.preventDefault();
      loadReport();
    });

    $(document).ready(loadReport);

    /* ---------- Print (only #dprint is visible, see @media print) ---------- */
    $('#btnPrint').on('click', function () { window.print(); });
</script>
</body>
</html>