<?php

declare(strict_types=1);

/**
 * pages/assetsreport.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: Assets Report (sidebar > Reports > Assets).
 *
 * One page, one AJAX action (POST, JSON): report. A filter card holds From, To, Type (empty = all),
 * Asset (empty = all) and a Find button. The report builds itself on DOM ready with the defaults (first day
 * of the month to today) and again on Find (form submit, Enter works); changing a select alone does not reload.
 * The report is a company header and one row per asset (#, Date, Asset, Name, Type, Amount, Adjusted,
 * Remaining) with a Total row at the end of the table, a DataTables Buttons export bar (all rows plus a final
 * Total row) and a Print button. The page is read-only: it performs no INSERT, UPDATE or DELETE and writes
 * no activity row.
 *
 * The date range applies to the asset date (assets.assetdate). Adjusted = the sum of ALL adjustments of the
 * asset, whatever their date, and Remaining = amount - Adjusted, never below 0, as in pages/assets.php.
 * Tables read: assets and assetadjustments only; assets never move cash. The adjustments are grouped per asset
 * before the join, so no asset is multiplied; amounts are computed and added as integer cents (dt_cents),
 * so there is no float drift.
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call dt_require_permission for Reports > Assets (menu 8, submenu 16, key "8-16")
 * right after the "NO LOGIN" marker in the request flow below, for the page (including the asset list
 * rendered on GET) and for the report action.
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

/** Maximum number of asset rows one report may return. */
const DT_MAX_ROWS = 10000;

const DT_LOGO_FILE = 'bushrofarmingandlivestocklogo.png'; // preferred logo file name
const DT_LOGO_DIRS = ['uploads', 'assets', 'images'];     // folders next to pages/ searched for the logo (in this order)

/** Company block of the report header. No source table exists: fill these in (empty hides the line). */
const DT_COMPANY_ADDRESS = '';
const DT_COMPANY_PHONE   = '';

/** Asset types: the filter options and the validation. */
const DT_ASSET_TYPES = ['Equipment', 'Supply'];

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

/**
 * Company block of the printed report (logo, name, address, phone), every value escaped.
 * The logo is printed only when a URL is given; the address and phone lines only when not empty.
 *
 * @param string $name    Company name.
 * @param string $address Company address ('' hides the line).
 * @param string $phone   Company phone ('' hides the line).
 * @param string $logoUrl Logo URL ('' hides the image).
 *
 * @return string HTML.
 */
function dt_company_html(string $name, string $address, string $phone, string $logoUrl): string
{
    $html = '';

    if ($logoUrl !== '') {
        $html .= '<img class="rp-logo" src="' . dt_e($logoUrl) . '" alt="">';
    }

    $html .= '<div class="rp-name">' . dt_e($name) . '</div>';

    if ($address !== '') {
        $html .= '<div><b>Address :</b> ' . dt_e($address) . '</div>';
    }
    if ($phone !== '') {
        $html .= '<div><b>Tel :</b> ' . dt_e($phone) . '</div>';
    }

    return $html;
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
 * Text of an asset, so two assets with the same name can be told apart: "#id name (type)".
 *
 * @param array<string,mixed> $asset Row with assetid, name and assettype.
 *
 * @return string Label.
 */
function dt_asset_label(array $asset): string
{
    return '#' . (int)$asset['assetid'] . ' ' . (string)$asset['name'] . ' (' . (string)$asset['assettype'] . ')';
}

/**
 * Assets for the filter (preloaded by PHP, no AJAX): every asset, ordered by name.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<int,array<string,mixed>> Rows with assetid, name and assettype.
 */
function dt_assets(PDO $pdo): array
{
    return dt_q($pdo, 'SELECT assetid, name, assettype FROM assets ORDER BY name ASC, assetid ASC')->fetchAll();
}

/**
 * Assets report: the assets acquired in a date range, optionally of one type and one asset, with their
 * adjustments to date, their remaining value and the totals.
 *
 * One query: assets LEFT JOINed to the adjustments grouped per asset, so no row is multiplied and there is no
 * N+1. The optional filters are appended with bound parameters (each name used once per statement). Each total
 * is the sum of the integer cent values of the returned rows.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input, an unknown type or asset, or too many rows.
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

    // Optional filter: one type ('' = all types).
    $type = $_POST['AssetType'] ?? '';
    if (!is_string($type) || ($type !== '' && !in_array($type, DT_ASSET_TYPES, true))) {
        throw new DtFail('The selected type is not valid.');
    }

    // Optional filter: one asset ('' or '0' = all assets).
    $idRaw      = $_POST['AssetId'] ?? '';
    $assetId    = 0;
    $assetLabel = '';
    if (!is_string($idRaw)) {
        throw new DtFail('The selected asset does not exist.');
    }
    $idRaw = trim($idRaw);
    if ($idRaw !== '' && $idRaw !== '0') {
        if (!ctype_digit($idRaw)) {
            throw new DtFail('The selected asset does not exist.');
        }
        $assetId = (int)$idRaw;
        $found   = dt_q($pdo, 'SELECT assetid, name, assettype FROM assets WHERE assetid = :id', ['id' => $assetId])->fetch();
        if (!is_array($found)) {
            throw new DtFail('The selected asset does not exist.');
        }
        $assetLabel = dt_asset_label($found);
    }

    $sql = 'SELECT a.assetid, a.name, a.assettype, a.assetdate, a.amount, COALESCE(j.s, 0) AS adjusted
              FROM assets a
         LEFT JOIN (SELECT assetid, SUM(amount) AS s FROM assetadjustments GROUP BY assetid) j ON j.assetid = a.assetid
             WHERE a.assetdate >= :df AND a.assetdate <= :dt';
    $params = ['df' => $fromRaw, 'dt' => $toRaw];
    if ($type !== '') {
        $sql .= ' AND a.assettype = :t';
        $params['t'] = $type;
    }
    if ($assetId > 0) {
        $sql .= ' AND a.assetid = :aid';
        $params['aid'] = $assetId;
    }
    $sql .= ' ORDER BY a.assetdate ASC, a.assetid ASC LIMIT ' . (DT_MAX_ROWS + 1);

    $list = dt_q($pdo, $sql, $params)->fetchAll();

    if (count($list) > DT_MAX_ROWS) {
        throw new DtFail('Too many rows. Please narrow the dates or select a single asset.');
    }

    // Cents per row; each total is the sum of the row cent values.
    $rows           = [];
    $totalAmount    = 0;
    $totalAdjusted  = 0;
    $totalRemaining = 0;
    foreach ($list as $r) {
        $amount    = dt_cents($r['amount']);
        $adjusted  = dt_cents($r['adjusted']);
        $remaining = max(0, $amount - $adjusted);

        $totalAmount    += $amount;
        $totalAdjusted  += $adjusted;
        $totalRemaining += $remaining;

        $rows[] = [
            'date'      => substr((string)($r['assetdate'] ?? ''), 0, 10),
            'asset'     => (int)$r['assetid'],
            'name'      => (string)($r['name'] ?? ''),
            'type'      => (string)($r['assettype'] ?? ''),
            'amount'    => round($amount / 100, 2),
            'adjusted'  => round($adjusted / 100, 2),
            'remaining' => round($remaining / 100, 2),
        ];
    }

    $today = date('Y-m-d');

    return [
        'success'        => true,
        'date'           => date('M d, Y', strtotime($today)),
        'dateymd'        => $today,
        'from'           => date('M d, Y', strtotime($fromRaw)),
        'to'             => date('M d, Y', strtotime($toRaw)),
        'fromymd'        => $fromRaw,
        'toymd'          => $toRaw,
        'company'        => 'Bushra Farming and Livestock',
        'type'           => $type,
        'asset'          => $assetLabel,
        'rows'           => $rows,
        'totalamount'    => round($totalAmount / 100, 2),
        'totaladjusted'  => round($totalAdjusted / 100, 2),
        'totalremaining' => round($totalRemaining / 100, 2),
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
        dt_log_error('[assetsreport ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[assetsreport ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    }
}

/* ================================================================
 * 4. HTML SHELL (same look as employeesreport.php): CSS, head, topbar, sidebar, footer
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

/* ===== Export buttons (DataTables Buttons) ===== */
.dt-buttons .btn { font-size: 13px; padding: 3px 10px; white-space: nowrap; }

/* ===== Select2 ===== */
.select2-container { width: 100% !important; }
.select2-container--bootstrap4 .select2-selection { border-radius: 0; border: 1px solid var(--line); min-height: 34px; background: #ffffff; }
.select2-container--bootstrap4 .select2-selection--single { height: 34px !important; }
.select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered { line-height: 32px; color: var(--text); font-size: 15px; }
.select2-container--bootstrap4 .select2-selection--single .select2-selection__placeholder { color: var(--muted); }
.select2-container--bootstrap4 .select2-selection--single .select2-selection__arrow { height: 32px; }
.select2-container--bootstrap4 .select2-selection--single .select2-selection__clear { color: var(--muted); }
.select2-container--bootstrap4.select2-container--focus .select2-selection,
.select2-container--bootstrap4.select2-container--open .select2-selection { border-color: var(--brand-accent); box-shadow: 0 0 0 2px rgba(22, 160, 74, 0.35); }
.select2-container--bootstrap4 .select2-dropdown { border-radius: 0; border-color: var(--brand-accent); font-size: 14px; }
.select2-container--bootstrap4 .select2-results__option--highlighted[aria-selected] { background: var(--brand-primary); color: #ffffff; }
.select2-container--bootstrap4 .select2-results__option[aria-selected=true] { background: var(--surface); color: var(--heading); }
.select2-container--bootstrap4 .select2-search--dropdown .select2-search__field { border-radius: 0; border-color: var(--line); font-size: 14px; }

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
 * Stylesheet additions for this page (report block, export bar, print rules).
 *
 * @return string CSS rules.
 */
function dt_report_css(): string
{
    return <<<'CSS'
/* ===== Assets report (scoped to #dprint) ===== */
#dprint { overflow-x: auto; text-align: left; font-size: 14px; line-height: 1.35; color: var(--muted); }
#dprint .rp-wrap { min-width: 700px; }

#dprint .rp-head {
    display: flex; justify-content: space-between; align-items: flex-end;
    border: 1px solid var(--line); padding: 6px 10px;
}
#dprint .rp-left { text-align: left; }
#dprint .rp-logo { display: block; width: 7.5em; height: auto; margin: 0 0 4px; border: 0; }
#dprint .rp-name { font-weight: 600; color: var(--heading); }
#dprint .rp-left b { font-weight: 600; color: var(--heading); }
#dprint .rp-right { text-align: right; align-self: flex-end; padding-bottom: 2.2em; }
#dprint .rp-title { font-size: 22px; font-weight: 600; line-height: 1.3; color: var(--heading); margin: 0 0 2px; }
#dprint .rp-dates { font-size: 14px; white-space: nowrap; }
#dprint .rp-dates b { font-weight: 600; color: var(--heading); }

#dprint table.rp-table {
    width: 100%; table-layout: fixed; margin: 6px 0 0; border-collapse: collapse;
    background: transparent; color: inherit; border: 1px solid var(--line);
}
#dprint table.rp-table th,
#dprint table.rp-table td {
    padding: 4px 6px; vertical-align: middle; border: 1px solid var(--line); text-align: left;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
#dprint table.rp-table thead th { color: var(--heading); font-weight: 600; background: var(--surface); }
#dprint table.rp-table th:nth-child(1) { width: 4%; }
#dprint table.rp-table th:nth-child(2) { width: 10%; }
#dprint table.rp-table th:nth-child(3) { width: 7%; }
#dprint table.rp-table th:nth-child(4) { width: 27%; }
#dprint table.rp-table th:nth-child(5) { width: 11%; }
#dprint table.rp-table th:nth-child(6) { width: 14%; }
#dprint table.rp-table th:nth-child(7) { width: 14%; }
#dprint table.rp-table th:nth-child(8) { width: 13%; }
#dprint table.rp-table th:nth-child(6),
#dprint table.rp-table td:nth-child(6),
#dprint table.rp-table th:nth-child(7),
#dprint table.rp-table td:nth-child(7),
#dprint table.rp-table th:nth-child(8),
#dprint table.rp-table td:nth-child(8) { text-align: right; }
#dprint table.rp-table td:nth-child(4) { white-space: normal; overflow-wrap: anywhere; }
#dprint table.rp-table td.rp-empty { text-align: center; }
#dprint table.rp-table tr.tot td { color: var(--heading); font-weight: 600; }

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
    #dprint { font-size: 10px; overflow: visible; width: 100%; }
    #dprint .rp-wrap { min-width: 0; }
    #dprint .rp-head { border: 0; }
    #dprint table.rp-table, #dprint table.rp-table th, #dprint table.rp-table td { border: 0; }
    #dprint table.rp-table thead th { font-weight: 700; background: transparent; }
    #dprint thead { display: table-header-group; }
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

  <!-- CDN: Poppins, Bootstrap 4.6, Font Awesome 5 (content icons), Select2, DataTables Buttons (export), Toastr -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">
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
                            <a href="<?= dt_e($subPage) ?>"<?= ($isReports && $subPage === 'assetsreport.php') ? ' class="active" aria-current="page"' : '' ?>><span><?= dt_e($subLabel) ?></span></a>
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
 * @param array<int,array<string,mixed>> $assets   Rows with assetid, name and assettype.
 * @param string                         $dateFrom Default From date (Y-m-d).
 * @param string                         $dateTo   Default To date (Y-m-d).
 *
 * @return void
 */
function dt_view_assets_report(array $assets, string $dateFrom, string $dateTo): void
{
    ?>
        <div class="row">
          <div class="col-12 mb-4">
            <div class="card" id="filterCard">
              <div class="card-body">
                <div class="card-head">
                  <h2 class="header-title">Assets Report</h2>
                </div>

                <form id="filterForm" novalidate autocomplete="off">
                  <div class="form-row align-items-end">
                    <div class="form-group col-6 col-md-2">
                      <label for="DateFrom">From</label>
                      <input type="date" class="form-control" id="DateFrom" name="DateFrom" value="<?= dt_e($dateFrom) ?>" required>
                    </div>
                    <div class="form-group col-6 col-md-2">
                      <label for="DateTo">To</label>
                      <input type="date" class="form-control" id="DateTo" name="DateTo" value="<?= dt_e($dateTo) ?>" required>
                    </div>
                    <div class="form-group col-12 col-md-2">
                      <label for="AssetType">Type</label>
                      <select class="form-control" id="AssetType" name="AssetType">
                        <option></option>
<?php foreach (DT_ASSET_TYPES as $typeName): ?>
                        <option value="<?= dt_e($typeName) ?>"><?= dt_e($typeName) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>
                    <div class="form-group col-12 col-md-4">
                      <label for="AssetId">Asset</label>
                      <select class="form-control" id="AssetId" name="AssetId">
                        <option></option>
<?php foreach ($assets as $asset): ?>
                        <option value="<?= (int)$asset['assetid'] ?>"><?= dt_e(dt_asset_label($asset)) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>
                    <div class="form-group col-12 col-md-2">
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
 * When login returns, call dt_require_permission for Reports > Assets
 * (menu 8, submenu 16, key "8-16") right here: it covers the page (GET, including
 * the asset list below) and the report action (POST).
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

try {
    $assets = dt_assets(dt_pdo()); // the only GET query: the assets of the filter
} catch (Throwable $e) {
    dt_log_error('[assetsreport view] ' . dt_describe_throwable($e));
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Server error. Please try again.';
    exit;
}

$dateFrom = date('Y-m-01'); // default range: first day of the month to today
$dateTo   = date('Y-m-d');

$csrf        = dt_csrf_token();
$csrfField   = dt_csrf_field();
$userName    = 'Administrator';
$initial     = mb_strtoupper(mb_substr($userName, 0, 1)) ?: 'A';
$agencyName  = 'Bushra Farming and Livestock';
$logoutUrl   = '../index.php';
$logoUrl     = dt_find_logo(DT_LOGO_FILE);
$companyHtml = dt_company_html($agencyName, DT_COMPANY_ADDRESS, DT_COMPANY_PHONE, $logoUrl);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/**
 * Sidebar: key, label, icon, page, submenu links (label, page). Same menu as pages/usersreport.php.
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
    'csrfField' => $csrfField, 'docTitle' => 'Assets Report', 'heading' => 'Assets Report',
    'sub' => 'Review the equipment and supplies acquired in a period, their adjustments and their remaining value.',
];

/* ---------- Render ---------- */

dt_render_open($page);
dt_view_assets_report($assets, $dateFrom, $dateTo);
dt_render_close();

$cfg       = ['csrf' => $csrf, 'self' => 'assetsreport.php', 'company' => $companyHtml];
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
<script nonce="<?= $n ?>" src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>
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

    // One data row of the report and of the export table (same 8 columns)
    function rowCells(r, i) {
      return '<td>' + (i + 1) + '</td>' +
             '<td>' + esc(r.date) + '</td>' +
             '<td>' + esc('#' + r.asset) + '</td>' +
             '<td>' + esc(r.name) + '</td>' +
             '<td>' + esc(r.type) + '</td>' +
             '<td>' + esc(money(r.amount)) + '</td>' +
             '<td>' + esc(money(r.adjusted)) + '</td>' +
             '<td>' + esc(money(r.remaining)) + '</td>';
    }

    // Cells of the Total row: "Total" in Name, the totals in Amount, Adjusted and Remaining
    function totalCells(res) {
      return '<td></td><td></td><td></td>' +
             '<td>Total</td>' +
             '<td></td>' +
             '<td>' + esc(money(res.totalamount)) + '</td>' +
             '<td>' + esc(money(res.totaladjusted)) + '</td>' +
             '<td>' + esc(money(res.totalremaining)) + '</td>';
    }

    /* ---------- Selects (Select2, options preloaded by PHP, search enabled) ---------- */
    $('#AssetType').select2({
      theme: 'bootstrap4',
      width: '100%',
      placeholder: 'Select Type',
      allowClear: true,
      minimumResultsForSearch: 0
    });

    $('#AssetId').select2({
      theme: 'bootstrap4',
      width: '100%',
      placeholder: 'Select Asset',
      allowClear: true,
      minimumResultsForSearch: 0
    });

    /* ---------- Report (printable block) ---------- */
    function buildReport(res) {
      var h = '<div class="rp-wrap">';

      // CFG.company is escaped server side (dt_company_html)
      h += '<div class="rp-head">' +
             '<div class="rp-left">' + CFG.company + '</div>' +
             '<div class="rp-right">' +
               '<div class="rp-title">Assets Report</div>' +
               '<div class="rp-dates"><b>Print Date:</b> ' + esc(res.date) + '</div>' +
               '<div class="rp-dates"><b>From:</b> ' + esc(res.from) + ' <b>To:</b> ' + esc(res.to) + '</div>' +
               (res.type ? '<div class="rp-dates"><b>Type:</b> ' + esc(res.type) + '</div>' : '') +
               (res.asset ? '<div class="rp-dates"><b>Asset:</b> ' + esc(res.asset) + '</div>' : '') +
             '</div>' +
           '</div>';

      h += '<table class="rp-table"><thead><tr>' +
             '<th>#</th><th>Date</th><th>Asset</th><th>Name</th><th>Type</th><th>Amount</th><th>Adjusted</th><th>Remaining</th>' +
           '</tr></thead><tbody>';

      if (res.rows.length === 0) {
        h += '<tr><td colspan="8" class="rp-empty">No assets found.</td></tr>';
      }
      for (var i = 0; i < res.rows.length; i++) {
        h += '<tr>' + rowCells(res.rows[i], i) + '</tr>';
      }
      h += '<tr class="tot">' + totalCells(res) + '</tr>';
      h += '</tbody></table></div>';
      $('#dprint').html(h);
    }

    /* ---------- Export bar (hidden #tblExport -> DataTables Buttons, ALL rows + Total row) ---------- */
    function buildExport(res) {
      if ($.fn.DataTable.isDataTable('#tblExport')) { $('#tblExport').DataTable().destroy(); }
      $('#exportBar').empty();

      var t = '<table id="tblExport"><thead><tr>' +
                '<th>#</th><th>Date</th><th>Asset</th><th>Name</th><th>Type</th><th>Amount</th><th>Adjusted</th><th>Remaining</th>' +
              '</tr></thead><tbody>';
      for (var i = 0; i < res.rows.length; i++) {
        t += '<tr>' + rowCells(res.rows[i], i) + '</tr>';
      }
      t += '<tr>' + totalCells(res) + '</tr>';
      t += '</tbody></table>';
      $('#exportHold').html(t);

      var title = 'Assets Report';
      var fname = (title + ' ' + res.fromymd + ' to ' + res.toymd +
                   (res.type ? ' ' + res.type : '') + (res.asset ? ' ' + res.asset : '')).replace(/[\\\/:*?"<>|]/g, '-');
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
            messageTop: res.company + ' | Print Date: ' + res.date + ' | From: ' + res.from + ' To: ' + res.to +
                        (res.type ? ' | Type: ' + res.type : '') + (res.asset ? ' | Asset: ' + res.asset : ''),
            customize: function (doc) {
              doc.defaultStyle.fontSize = 9;
              if (doc.styles && doc.styles.tableHeader) { doc.styles.tableHeader.fontSize = 9; }
              var tbl = null;
              $.each(doc.content, function (_, c) { if (c.table) { tbl = c.table; } });
              if (!tbl) { return; }
              tbl.widths = [18, 50, 34, '*', 50, 56, 56, 56];
              $.each(tbl.body, function (_, row) {
                $.each([5, 6, 7], function (_, col) { // Amount, Adjusted, Remaining: header, rows and Total row
                  if (row[col]) { row[col].alignment = 'right'; }
                });
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

      api({
        action: 'report',
        DateFrom: from,
        DateTo: to,
        AssetType: $('#AssetType').val() || '',
        AssetId: $('#AssetId').val() || ''
      }).done(function (res) {
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