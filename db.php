<?php

declare(strict_types=1);

/**
 * db.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: shared core (single tenant).
 *
 * Configuration, lazy PDO connection, central error handling, hardened sessions,
 * security headers, CSRF, permission checks, home route and activity logging.
 * All helpers are prefixed dt_.
 *
 * Tables used here (lowercase, see the users SQL):
 *   users        (userid, name, username, password, status ON|OFF)
 *   permissions  (userid, menuid, submenuid)
 *   activitylogs (userid, action, description, activitydate)
 *
 * The first administrator (lowest userid) always holds every permission.
 * The permission catalog itself lives in pages/users.php; this file only needs the
 * "menuid-submenuid" keys of DT_ROUTES to find the home page of a user.
 *
 * Pages that already declare helpers (dt_q, dt_json, dt_fail, dt_icon, dt_find_logo,
 * dt_permission_items, dt_first_admin_id, ...) keep working: this file never declares those names.
 *
 * Include with require_once only. Direct requests are refused.
 * ---------------------------------------------------------------
 */

// Block direct access (db.php requested by URL).
if (
    PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(403);
    exit('Forbidden');
}

// Block double inclusion when the file is loaded by a different path.
if (defined('DT_CORE_LOADED')) {
    return;
}
define('DT_CORE_LOADED', true);

/* ================================================================
 * 1. CONSTANTS
 * ============================================================== */

const DT_SESSION_NAME        = 'BFSESSID';
const DT_IDLE_TIMEOUT        = 1800;   // 30 minutes without activity
const DT_ABSOLUTE_TIMEOUT    = 28800;  // 8 hours since sign-in
const DT_ACCESS_RECHECK      = 300;    // re-check status and permissions every 5 minutes
const DT_ACTIVITY_TOUCH      = 60;     // refresh last_activity at most once a minute
const DT_MAX_USERNAME_LENGTH = 150;    // users.username VARCHAR(150)
const DT_MAX_PASSWORD_LENGTH = 1024;
const DT_MAX_ATTEMPTS        = 5;      // failed logins per session before lockout
const DT_LOCKOUT_SECONDS     = 60;
const DT_IP_MAX_FAILURES     = 20;     // failed logins per IP inside the window
const DT_IP_WINDOW_SECONDS   = 600;
const DT_IP_LOCKOUT_SECONDS  = 300;
const DT_STATUS_ACTIVE       = 'ON';
const DT_GENERIC_ERROR       = 'Service temporarily unavailable. Please try again.';

/**
 * Redirect whitelist (paths relative to the application root).
 * "login" and "index" are fixed keys. Every other key is a permission key "menuid-submenuid"
 * (same ids as DT_PERMISSION_CATALOG in pages/users.php) and points to that page.
 * The ORDER matters: dt_home_route() returns the first page the user may open, Dashboard first.
 */
const DT_ROUTES = [
    'login' => 'login.php',
    'index' => 'index.php',

    '1-1'  => 'pages/dashboard.php',
    '2-1'  => 'pages/invoices.php',
    '2-2'  => 'pages/quotations.php',
    '3-1'  => 'pages/livestock.php',
    '4-1'  => 'pages/products.php',
    '5-1'  => 'pages/purchases.php',
    '6-1'  => 'pages/customers.php',
    '6-2'  => 'pages/suppliers.php',
    '6-3'  => 'pages/shareholders.php',
    '6-4'  => 'pages/employees.php',
    '7-1'  => 'pages/chartofaccounts.php',
    '7-2'  => 'pages/receipts.php',
    '7-3'  => 'pages/payments.php',
    '7-4'  => 'pages/expenses.php',
    '7-5'  => 'pages/accounttransfer.php',
    '7-6'  => 'pages/equity.php',
    '7-7'  => 'pages/payrolls.php',
    '7-8'  => 'pages/assets.php',
    '8-1'  => 'pages/salesreport.php',
    '8-2'  => 'pages/purchasesreport.php',
    '8-3'  => 'pages/livestockreport.php',
    '8-4'  => 'pages/customersreport.php',
    '8-5'  => 'pages/suppliersreport.php',
    '8-6'  => 'pages/employeesreport.php',
    '8-7'  => 'pages/shareholdersreport.php',
    '8-8'  => 'pages/usersreport.php',
    '8-9'  => 'pages/chartofaccountsreport.php',
    '8-10' => 'pages/receiptsreport.php',
    '8-11' => 'pages/paymentsreport.php',
    '8-12' => 'pages/cashreport.php',
    '8-13' => 'pages/expensesreport.php',
    '8-14' => 'pages/accounttransferreport.php',
    '8-15' => 'pages/payrollsreport.php',
    '8-16' => 'pages/assetsreport.php',
    '8-17' => 'pages/incomestatement.php',
    '8-18' => 'pages/equitystatement.php',
    '8-19' => 'pages/balancesheet.php',
    '8-20' => 'pages/cashflow.php',
    '9-1'  => 'pages/settings.php',
    '10-1' => 'pages/users.php',
    '10-2' => 'pages/activities.php',
];

/* ================================================================
 * 2. CONFIGURATION
 * ============================================================== */

/**
 * Application configuration. Environment variables override the defaults,
 * so credentials never need to live in code on a real server.
 *
 * @param string $key Configuration key.
 *
 * @return mixed Value (null when the key is unknown).
 */
function dt_config(string $key): mixed
{
    static $config = null;

    if ($config === null) {
        $env = static fn (string $name, string $default): string =>
            (($value = getenv($name)) !== false && $value !== '') ? (string)$value : $default;

        $config = [
            'timezone'    => $env('DT_TIMEZONE', 'Africa/Mogadishu'),
            'db_host'     => $env('DT_DB_HOST', '127.0.0.1'),
            'db_port'     => $env('DT_DB_PORT', '3306'),
            'db_name'     => $env('DT_DB_NAME', 'bushrafarmingandlivestock'),
            'db_user'     => $env('DT_DB_USER', 'root'),   // XAMPP default: change in production
            'db_pass'     => $env('DT_DB_PASS', ''),       // XAMPP default: change in production
            'db_timeout'  => 5,
            // Outside the web root when possible: two folders above this file (C:\xampp\bushra_storage).
            'storage_dir' => $env('DT_STORAGE_DIR', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bushra_storage'),
            'trust_proxy' => $env('DT_TRUST_PROXY', '0') === '1', // true only behind your own reverse proxy
        ];
    }

    return $config[$key] ?? null;
}

/* ================================================================
 * 3. LOGGING AND ERROR POLICY
 * ============================================================== */

/**
 * Resolve (and create once) a writable storage sub-directory.
 *
 * @param string $sub Sub-directory name, e.g. "logs".
 *
 * @return string|null Absolute path, or null when unavailable.
 */
function dt_storage_path(string $sub): ?string
{
    static $cache = [];

    if (array_key_exists($sub, $cache)) {
        return $cache[$sub];
    }

    $path = rtrim((string)dt_config('storage_dir'), '/\\') . DIRECTORY_SEPARATOR . $sub;
    $ok   = is_dir($path) || @mkdir($path, 0750, true);

    return $cache[$sub] = ($ok && is_writable($path)) ? $path : null;
}

/**
 * Write a line to the application log (falls back to PHP's error_log). Never throws, never prints.
 *
 * @param string $message Message to log (no secrets).
 *
 * @return void
 */
function dt_log_error(string $message): void
{
    static $busy = false;

    if ($busy) {
        return; // a failure while logging must never recurse
    }
    $busy = true;

    try {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL;
        $dir  = dt_storage_path('logs');

        if ($dir === null || !error_log($line, 3, $dir . DIRECTORY_SEPARATOR . 'bushra-errors.log')) {
            error_log('[Bushra] ' . trim($line));
        }
    } finally {
        $busy = false;
    }
}

/**
 * One-line description of a Throwable for the log (no stack trace: traces can hold passwords).
 *
 * @param Throwable $e Throwable.
 *
 * @return string Class, message, file and line.
 */
function dt_describe_throwable(Throwable $e): string
{
    return get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
date_default_timezone_set((string)dt_config('timezone'));

set_error_handler(
    static function (int $severity, string $message, string $file, int $line): bool {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }
        dt_log_error("PHP[{$severity}] {$message} @ " . basename($file) . ":{$line}");

        return true; // handled: nothing is displayed to the user
    }
);

set_exception_handler(
    static function (Throwable $e): void {
        dt_log_error('Uncaught ' . dt_describe_throwable($e));
        dt_render_message(500, 'Something went wrong', DT_GENERIC_ERROR);
    }
);

register_shutdown_function(
    static function (): void {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        dt_log_error("Fatal[{$error['type']}] {$error['message']} @ " . basename($error['file']) . ":{$error['line']}");
        if (!headers_sent()) {
            dt_render_message(500, 'Something went wrong', DT_GENERIC_ERROR);
        }
    }
);

/* ================================================================
 * 4. DATABASE
 * ============================================================== */

/**
 * Lazy, shared PDO connection (one per request). A failed attempt is remembered,
 * so the request never waits on the database twice.
 *
 * @return PDO Connected PDO instance.
 *
 * @throws RuntimeException When the connection cannot be established.
 */
function dt_pdo(): PDO
{
    static $pdo = null;
    static $failure = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($failure instanceof Throwable) {
        throw $failure;
    }

    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            dt_config('db_host'),
            dt_config('db_port'),
            dt_config('db_name')
        );
        $pdo = new PDO($dsn, (string)dt_config('db_user'), (string)dt_config('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::ATTR_TIMEOUT            => (int)dt_config('db_timeout'),
        ]);
    } catch (Throwable $e) {
        $failure = new RuntimeException('Database connection failed: ' . $e->getMessage());
        throw $failure;
    }

    return $pdo;
}

/**
 * Run a callback inside a transaction: commit on success, roll back and rethrow on any failure.
 *
 * @param callable(PDO):mixed $callback Work to perform.
 *
 * @return mixed Callback result.
 *
 * @throws Throwable Anything the callback throws (after the rollback).
 */
function dt_transaction(callable $callback): mixed
{
    $pdo = dt_pdo();
    $pdo->beginTransaction();

    try {
        $result = $callback($pdo);
        $pdo->commit();

        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* ================================================================
 * 5. REQUEST HELPERS, HEADERS AND OUTPUT
 * ============================================================== */

/**
 * Escape a value for HTML output.
 *
 * @param mixed $value Value.
 *
 * @return string Escaped text.
 */
function dt_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/**
 * Whether the current request arrived over HTTPS.
 *
 * @return bool True for HTTPS.
 */
function dt_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    return dt_config('trust_proxy') === true
        && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * Client IP address (REMOTE_ADDR only; proxy headers are never trusted).
 *
 * @return string Valid IP, or "0.0.0.0".
 */
function dt_client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
}

/**
 * Per-request CSP nonce.
 *
 * @return string Base64 nonce.
 */
function dt_nonce(): string
{
    static $nonce = null;

    return $nonce ??= base64_encode(random_bytes(16));
}

/**
 * Send the security and no-store cache headers (once per request).
 * A page that loads the vetted CDN libraries defines DT_ALLOW_CDN = true before calling this.
 *
 * @return void
 */
function dt_security_headers(): void
{
    static $sent = false;

    if ($sent || headers_sent()) {
        return;
    }
    $sent  = true;
    $nonce = dt_nonce();

    $cdn     = defined('DT_ALLOW_CDN') && DT_ALLOW_CDN === true;
    $scripts = $cdn ? ' https://code.jquery.com https://cdn.jsdelivr.net https://cdn.datatables.net https://cdnjs.cloudflare.com' : '';
    $styles  = $cdn ? ' https://cdn.jsdelivr.net https://cdn.datatables.net https://cdnjs.cloudflare.com' : '';
    $fonts   = $cdn ? ' https://cdnjs.cloudflare.com' : '';

    header_remove('X-Powered-By');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'nonce-{$nonce}'{$scripts}; "
        . "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com{$styles}; "
        . "font-src https://fonts.gstatic.com{$fonts}; "
        . "img-src 'self' data:; connect-src 'self'; "
        . "form-action 'self'; base-uri 'self'; frame-ancestors 'none'"
    );
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (dt_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Application base path (e.g. "/moalio/bushrafarmingandlivestock") derived from the document root,
 * so redirects work in any folder without hard-coding.
 *
 * @return string Base path without trailing slash ("" at the root).
 */
function dt_base_path(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $base    = '';
    $docRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $appRoot = realpath(__DIR__);

    if ($docRoot !== false && $appRoot !== false) {
        $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
        $appRoot = str_replace('\\', '/', $appRoot);
        if ($docRoot !== '' && stripos($appRoot, $docRoot) === 0) {
            $base = rtrim(substr($appRoot, strlen($docRoot)), '/');
        }
    }

    return $base;
}

/**
 * Flush the response to the client so deferred work (activity logging) does not delay it.
 *
 * @return void
 */
function dt_finish_response(): void
{
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

/**
 * Redirect to a whitelisted route and stop. An unknown key falls back to login,
 * so no request value ever reaches the Location header.
 *
 * @param string $routeKey A key of DT_ROUTES.
 * @param int    $status   HTTP status (303 by default).
 *
 * @return never
 */
function dt_redirect(string $routeKey, int $status = 303): never
{
    $target = DT_ROUTES[$routeKey] ?? DT_ROUTES['login'];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (!headers_sent()) {
        dt_security_headers();
        header('Location: ' . dt_base_path() . '/' . $target, true, $status);
    }
    dt_finish_response();
    exit;
}

/**
 * Send a JSON answer and stop.
 *
 * @param array<string,mixed> $data Payload.
 * @param int                 $code HTTP status.
 *
 * @return never
 */
function dt_send_json(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Render a small branded message page and stop (errors, 403, 405...).
 *
 * @param int         $status    HTTP status code.
 * @param string      $title     Heading.
 * @param string      $message   Plain-text message.
 * @param string|null $linkRoute Optional DT_ROUTES key for a "Back" link.
 *
 * @return never
 */
function dt_render_message(int $status, string $title, string $message, ?string $linkRoute = null): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        if ($status === 405) {
            header('Allow: GET, HEAD, POST');
        }
        dt_security_headers();
    }

    $nonce = dt_e(dt_nonce());
    $link  = '';
    if ($linkRoute !== null && isset(DT_ROUTES[$linkRoute])) {
        $link = '<p><a href="' . dt_e(dt_base_path() . '/' . DT_ROUTES[$linkRoute]) . '">Back</a></p>';
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . dt_e($title) . ' | Bushra Farming and Livestock</title>'
        . '<style nonce="' . $nonce . '">'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;'
        . 'font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#fff;color:#2a1213}'
        . 'main{max-width:420px;width:100%;border:2px solid #9d1d22;border-top:6px solid #16a04a;padding:32px}'
        . 'h1{margin:0 0 8px;font-size:18px;color:#9d1d22}p{margin:8px 0;font-size:14px;line-height:1.5}'
        . 'a{color:#9d1d22;font-weight:600}'
        . '</style></head><body><main role="alert"><h1>' . dt_e($title) . '</h1><p>' . dt_e($message) . '</p>'
        . $link . '</main></body></html>';
    exit;
}

/* ================================================================
 * 6. SESSIONS
 * ============================================================== */

/**
 * Start the hardened session (idempotent).
 *
 * @return bool True when a session is active; false = treat the visitor as anonymous.
 */
function dt_session_start(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }
    if (headers_sent()) {
        dt_log_error('Session could not start: headers already sent.');

        return false;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_cookies', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string)(DT_IDLE_TIMEOUT + 1800));

    session_cache_limiter(''); // dt_security_headers() owns the cache headers
    session_name(DT_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => dt_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    try {
        return session_start();
    } catch (Throwable $e) {
        dt_log_error('Session start failed: ' . dt_describe_throwable($e));

        return false;
    }
}

/**
 * Whether the browser sent a session cookie (lets entry pages skip creating a session for new visitors).
 *
 * @return bool True when the cookie is present.
 */
function dt_has_session_cookie(): bool
{
    return isset($_COOKIE[DT_SESSION_NAME]) && $_COOKIE[DT_SESSION_NAME] !== '';
}

/**
 * Fully destroy the session and its cookie.
 *
 * @param bool $restart Start a fresh anonymous session afterwards (expired session landing on login).
 *
 * @return void
 */
function dt_destroy_session(bool $restart = false): void
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    if ($restart) {
        session_id(session_create_id());
        dt_session_start();

        return;
    }

    if (!headers_sent()) {
        setcookie(DT_SESSION_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => dt_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/**
 * End an invalid session and leave a notice for the login page.
 *
 * @param string $notice Message shown after the redirect to login.
 *
 * @return void
 */
function dt_expire_session(string $notice): void
{
    dt_destroy_session(true);
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['flash'] = $notice;
    }
}

/**
 * Whether a users.status value means the account is enabled.
 *
 * @param mixed $status Raw status value.
 *
 * @return bool True for 'ON'.
 */
function dt_is_active_status(mixed $status): bool
{
    return strtoupper(trim((string)$status)) === DT_STATUS_ACTIVE;
}

/**
 * Whether the session holds a signed-in user (no timeout or database check).
 *
 * @return bool True when a user id is stored.
 */
function dt_has_user_session(): bool
{
    return session_status() === PHP_SESSION_ACTIVE && is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0;
}

/**
 * Whether the request belongs to a valid, current signed-in user.
 * Enforces the idle and absolute timeouts and periodically re-checks the account. Fail-closed.
 *
 * @return bool True only for a fully valid session.
 */
function dt_is_authenticated(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE && (!dt_has_session_cookie() || !dt_session_start())) {
        return false;
    }
    if (!dt_has_user_session()) {
        return false;
    }

    $now     = time();
    $loginAt = (int)($_SESSION['login_at'] ?? 0);
    $last    = (int)($_SESSION['last_activity'] ?? 0);

    if ($loginAt <= 0 || $last <= 0 || ($now - $last) > DT_IDLE_TIMEOUT || ($now - $loginAt) > DT_ABSOLUTE_TIMEOUT) {
        dt_expire_session('Your session has expired. Please sign in again.');

        return false;
    }

    if (($now - (int)($_SESSION['access_checked_at'] ?? 0)) >= DT_ACCESS_RECHECK && !dt_refresh_access((int)$_SESSION['user_id'])) {
        dt_expire_session('Your session has ended. Please sign in again.');

        return false;
    }

    if (($now - $last) >= DT_ACTIVITY_TOUCH) {
        $_SESSION['last_activity'] = $now;
    }

    return true;
}

/**
 * Re-validate the account and reload its access data into the session.
 * Runs at most once per DT_ACCESS_RECHECK seconds, never per page load.
 *
 * @param int $userId Signed-in user id.
 *
 * @return bool True when the user still exists and is ON.
 */
function dt_refresh_access(int $userId): bool
{
    try {
        $pdo  = dt_pdo();
        $stmt = $pdo->prepare('SELECT name, username, status FROM users WHERE userid = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        if (!is_array($row) || !dt_is_active_status($row['status'])) {
            return false;
        }

        $firstAdmin = dt_auth_is_first_admin($pdo, $userId);

        $_SESSION['name']              = (string)$row['name'];
        $_SESSION['username']          = (string)$row['username'];
        $_SESSION['first_admin']       = $firstAdmin;
        $_SESSION['permissions']       = $firstAdmin ? [] : dt_auth_load_permissions($pdo, $userId);
        $_SESSION['access_checked_at'] = time();

        return true;
    } catch (Throwable $e) {
        dt_log_error('Access re-check failed: ' . dt_describe_throwable($e));

        return false; // fail-closed
    }
}

/**
 * Require a signed-in user. Anonymous: POST gets JSON 401, anything else is redirected to login.
 *
 * @return void
 */
function dt_require_login(): void
{
    dt_security_headers();
    if (dt_is_authenticated()) {
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        dt_send_json(['success' => false, 'message' => 'Session expired'], 401);
    }
    dt_redirect('login');
}

/* ================================================================
 * 7. CSRF
 * ============================================================== */

/**
 * Current CSRF token (created on first use; starts the session when needed).
 *
 * @return string 64-char hex token.
 */
function dt_csrf_token(): string
{
    dt_session_start();

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) !== 64) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validate a submitted CSRF token in constant time.
 *
 * @param mixed $submitted Token from the request.
 *
 * @return bool True when it matches the session token.
 */
function dt_csrf_valid(mixed $submitted): bool
{
    dt_session_start();
    $expected = $_SESSION['csrf_token'] ?? null;

    return is_string($submitted) && is_string($expected) && $expected !== '' && hash_equals($expected, $submitted);
}

/**
 * Replace the CSRF token.
 *
 * @return void
 */
function dt_csrf_rotate(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Hidden input carrying the CSRF token, for forms.
 *
 * @return string HTML input element.
 */
function dt_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . dt_e(dt_csrf_token()) . '">';
}

/* ================================================================
 * 8. PERMISSIONS
 * Session keys: first_admin (bool), permissions (map "menuid:submenuid" and "menuid:*" => true).
 * ============================================================== */

/**
 * User id of the first administrator: the lowest userid. Cached per request.
 *
 * @param PDO $pdo Connection.
 *
 * @return int User id, or 0 when there is no user.
 */
function dt_auth_first_admin_id(PDO $pdo): int
{
    static $id = null;

    return $id ??= (int)$pdo->query('SELECT MIN(userid) FROM users')->fetchColumn();
}

/**
 * Whether a user is the first administrator.
 *
 * @param PDO $pdo    Connection.
 * @param int $userId User id.
 *
 * @return bool True for the first administrator.
 */
function dt_auth_is_first_admin(PDO $pdo, int $userId): bool
{
    return $userId > 0 && dt_auth_first_admin_id($pdo) === $userId;
}

/**
 * Load the permissions of a user as a lookup map.
 * Keys: "menuid:submenuid" and "menuid:*" (any submenu of that menu).
 *
 * @param PDO $pdo    Connection.
 * @param int $userId User id.
 *
 * @return array<string,true> Permission map.
 *
 * @throws PDOException On database failure (callers fail closed).
 */
function dt_auth_load_permissions(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT menuid, submenuid FROM permissions WHERE userid = :id');
    $stmt->execute(['id' => $userId]);

    $map = [];
    foreach ($stmt as $row) {
        $menu                                      = (int)$row['menuid'];
        $map[$menu . ':' . (int)$row['submenuid']] = true;
        $map[$menu . ':*']                         = true;
    }

    return $map;
}

/**
 * Whether the signed-in user holds a permission. The first administrator holds all of them.
 *
 * @param int      $menuId    Menu id.
 * @param int|null $submenuId Submenu id, or null for "any submenu of the menu".
 *
 * @return bool True when granted (false for anonymous visitors).
 */
function dt_has_permission(int $menuId, ?int $submenuId = null): bool
{
    if (!dt_has_user_session()) {
        return false;
    }
    if (($_SESSION['first_admin'] ?? false) === true) {
        return true;
    }

    $map = $_SESSION['permissions'] ?? null;

    return is_array($map) && isset($map[$menuId . ':' . ($submenuId ?? '*')]);
}

/**
 * Guard for a permission-protected page. Returns when the permission is held; otherwise a POST gets
 * JSON 403 and everything else the 403 page with a Back link to the user's home page.
 * Call after dt_require_login() and before session_write_close().
 *
 * @param int      $menuId    Menu id.
 * @param int|null $submenuId Submenu id, or null for "any submenu of the menu".
 *
 * @return void
 */
function dt_require_permission(int $menuId, ?int $submenuId = null): void
{
    if (dt_has_permission($menuId, $submenuId)) {
        return;
    }

    dt_security_headers();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        dt_send_json(['success' => false, 'message' => 'You do not have permission to use this page.'], 403);
    }
    dt_render_message(403, 'Access denied', 'You do not have permission to open this page.', dt_home_route());
}

/**
 * Route key of the signed-in user's home page: the first page of DT_ROUTES he may open (Dashboard first).
 * A user without any permission gets the Dashboard key: its guard answers with the 403 page, which is a
 * page and not a redirect, so no loop is possible (login would loop: it sends signed-in users home).
 * An anonymous request gets 'login'. Needs an ACTIVE session: call before session_write_close().
 *
 * @return string DT_ROUTES key.
 */
function dt_home_route(): string
{
    if (!dt_has_user_session()) {
        return 'login';
    }

    foreach (array_keys(DT_ROUTES) as $key) {
        if (!str_contains($key, '-')) {
            continue; // fixed keys (login, index)
        }
        [$menuId, $submenuId] = array_map('intval', explode('-', $key));
        if (dt_has_permission($menuId, $submenuId)) {
            return $key;
        }
    }

    return '1-1';
}

/* ================================================================
 * 9. ACTIVITY LOG (never breaks the caller)
 * ============================================================== */

/**
 * Insert a row into activitylogs. Never throws, never receives secrets.
 *
 * @param int|null $userId      Acting user (null when unknown).
 * @param string   $action      Action label (max 30 characters).
 * @param string   $description Description (max 255 characters).
 *
 * @return bool True when the row was written.
 */
function dt_log_activity(?int $userId, string $action, string $description): bool
{
    try {
        $stmt = dt_pdo()->prepare(
            'INSERT INTO activitylogs (userid, action, description, activitydate) VALUES (:userid, :action, :description, :at)'
        );
        $stmt->execute([
            'userid'      => $userId,
            'action'      => mb_substr($action, 0, 30),
            'description' => mb_substr($description, 0, 255),
            'at'          => date('Y-m-d H:i:s'),
        ]);

        return true;
    } catch (Throwable $e) {
        dt_log_error('Activity log failed: ' . dt_describe_throwable($e));

        return false;
    }
}

/**
 * Queue an activity log write for after the response has been sent,
 * so logging can never slow down or break the request.
 *
 * @param int|null $userId      Acting user.
 * @param string   $action      Action label.
 * @param string   $description Description.
 *
 * @return void
 */
function dt_log_activity_later(?int $userId, string $action, string $description): void
{
    register_shutdown_function(
        static function () use ($userId, $action, $description): void {
            dt_log_activity($userId, $action, $description);
        }
    );
}

/* ================================================================
 * 10. PER-IP LOGIN THROTTLE (file based, no schema change)
 * ============================================================== */

/**
 * Path of the throttle file of the current client IP.
 *
 * @return string|null Path, or null when storage is unavailable.
 */
function dt_ip_throttle_file(): ?string
{
    $dir = dt_storage_path('throttle');

    return $dir === null ? null : $dir . DIRECTORY_SEPARATOR . hash('sha256', dt_client_ip()) . '.json';
}

/**
 * Seconds the current IP remains locked out (0 = allowed).
 *
 * @return int Remaining lockout seconds.
 */
function dt_ip_locked_seconds(): int
{
    $file = dt_ip_throttle_file();
    if ($file === null || !is_file($file)) {
        return 0;
    }
    $data = json_decode((string)file_get_contents($file), true);

    return is_array($data) ? max(0, (int)($data['locked_until'] ?? 0) - time()) : 0;
}

/**
 * Record a failed login for the current IP and apply the lockout at the threshold.
 * A successful login deliberately does not reset the counter.
 *
 * @return bool True when this failure triggered a new lockout.
 */
function dt_ip_record_failure(): bool
{
    $file   = dt_ip_throttle_file();
    $handle = $file === null ? false : fopen($file, 'c+');
    if ($handle === false) {
        return false; // degrade: the session throttle still applies
    }

    $locked = false;
    try {
        if (flock($handle, LOCK_EX)) {
            $data = json_decode((string)stream_get_contents($handle), true);
            $data = is_array($data) ? $data : [];
            $now  = time();

            if ((int)($data['first'] ?? 0) === 0 || ($now - (int)$data['first']) > DT_IP_WINDOW_SECONDS) {
                $data = ['count' => 0, 'first' => $now, 'locked_until' => 0];
            }
            $data['count'] = (int)($data['count'] ?? 0) + 1;

            if ($data['count'] >= DT_IP_MAX_FAILURES) {
                $data   = ['count' => 0, 'first' => $now, 'locked_until' => $now + DT_IP_LOCKOUT_SECONDS];
                $locked = true;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string)json_encode($data));
            fflush($handle);
            flock($handle, LOCK_UN);
        }
    } finally {
        fclose($handle);
    }

    dt_ip_cleanup();

    return $locked;
}

/**
 * Occasionally delete stale throttle files (about 1% of failures).
 *
 * @return void
 */
function dt_ip_cleanup(): void
{
    $dir = dt_storage_path('throttle');
    if ($dir === null || random_int(1, 100) !== 1) {
        return;
    }

    $cutoff = time() - 86400;
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < $cutoff) {
            unlink($file);
        }
    }
}