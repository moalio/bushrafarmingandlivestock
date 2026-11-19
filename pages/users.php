<?php

declare(strict_types=1);

/**
 * pages/users.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: Users (user dropdown > Users).
 *
 * One page, two tabs: Users (list, add, edit, delete) and Roles (permission matrix).
 * Six AJAX actions (POST, JSON): list, user_options, save_user, delete_user, perm_get, perm_save.
 * Same shell as pages/livestock.php, functional model taken from the Dalsan users page.
 *
 * Tables and columns expected (single tenant, no agency id anywhere):
 *   users        (userid, name, username, password, status)   status = 'ON' | 'OFF'
 *   permissions  (userid, menuid, submenuid)
 *   activitylogs (userid, action, description, activitydate)   userid must accept NULL
 *   Optional reference tables checked before a delete: sales.userid, purchases.userid, activitylogs.userid
 *   (a missing table or column is logged and skipped).
 *
 * PERMISSIONS ARE ONLY STORED FOR NOW: nothing enforces them until login returns.
 * The permission catalog is DT_PERMISSION_CATALOG below (edit it when the sidebar changes).
 *
 * First administrator = the user with the lowest userid. He cannot be deleted, cannot be set to OFF,
 * and always holds every permission (his permissions are fixed and never written).
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call the auth helpers of ../db.php right after the
 * "NO LOGIN" marker in the request flow below (and pass the acting user id to dt_log_user()).
 * Rules that need an acting user ("cannot delete/deactivate your own account",
 * "only the first administrator can edit his own account") are left out until then.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';

if (!defined('DT_ALLOW_CDN')) {
    define('DT_ALLOW_CDN', true); // opt-in: allows the vetted CDN hosts in the CSP for this page only
}

/* ================================================================
 * 1. CONSTANTS AND CONFIG
 * ============================================================== */

const DT_MAX_NAME     = 150;
const DT_MIN_PASSWORD = 6;
const DT_MAX_PASSWORD = 72; // bcrypt only uses the first 72 bytes

const DT_LOGO_FILE = 'bushrofarmingandlivestocklogo.png'; // preferred logo file name
const DT_LOGO_DIRS = ['uploads', 'assets', 'images'];     // folders next to pages/ searched for the logo (in this order)

/** Table and column names (fixed values, never user input). Fix a name here if your schema differs. */
const BF_USERS       = ['table' => 'users', 'id' => 'userid', 'name' => 'name', 'username' => 'username', 'password' => 'password', 'status' => 'status'];
const BF_PERMISSIONS = ['table' => 'permissions', 'user' => 'userid', 'menu' => 'menuid', 'submenu' => 'submenuid'];

/**
 * Tables that reference the user id and block a delete: [table, column, noun].
 * Fixed whitelist (never user input). A table or column that does not exist is logged and skipped.
 */
const DT_USER_REFS = [
    ['activitylogs', 'userid', 'activity log'],
    ['sales', 'userid', 'sale'],
    ['purchases', 'userid', 'purchase'],
];

/**
 * Permission catalog, built from the sidebar and the user menu of livestock.php.
 * One row per menu without submenus, or per submenu: [menuId, submenuId, menu label, submenu label].
 * The permission key is "menuId-submenuId". A menu without submenus uses submenuId 1.
 * Keep ids stable once permissions are stored: add new rows at the end of their menu with a new submenu id.
 */
const DT_PERMISSION_CATALOG = [
    [1, 1, 'Dashboard', 'Dashboard'],

    [2, 1, 'Sales', 'Invoices'],
    [2, 2, 'Sales', 'Quotations'],

    [3, 1, 'Livestock', 'Livestock'],
    [4, 1, 'Products', 'Products'],
    [5, 1, 'Purchases', 'Purchases'],

    [6, 1, 'People', 'Customers'],
    [6, 2, 'People', 'Suppliers'],
    [6, 3, 'People', 'Shareholders'],
    [6, 4, 'People', 'Employees'],

    [7, 1, 'Accounting', 'Chart of Accounts'],
    [7, 2, 'Accounting', 'Receipts'],
    [7, 3, 'Accounting', 'Payments'],
    [7, 4, 'Accounting', 'Expenses'],
    [7, 5, 'Accounting', 'Account Transfer'],
    [7, 6, 'Accounting', "Owner's Equity"],
    [7, 7, 'Accounting', 'Payrolls'],
    [7, 8, 'Accounting', 'Assets'],

    [8, 1, 'Reports', 'Sales Report'],
    [8, 2, 'Reports', 'Purchases Report'],
    [8, 3, 'Reports', 'Livestock'],
    [8, 4, 'Reports', 'Customers'],
    [8, 5, 'Reports', 'Suppliers'],
    [8, 6, 'Reports', 'Employees'],
    [8, 7, 'Reports', 'Shareholders'],
    [8, 8, 'Reports', 'Users'],
    [8, 9, 'Reports', 'Chart of Accounts'],
    [8, 10, 'Reports', 'Receipts'],
    [8, 11, 'Reports', 'Payments'],
    [8, 12, 'Reports', 'Cash Report'],
    [8, 13, 'Reports', 'Expenses'],
    [8, 14, 'Reports', 'Account Transfer'],
    [8, 15, 'Reports', 'Payrolls'],
    [8, 16, 'Reports', 'Assets'],
    [8, 17, 'Reports', 'Income Statement'],
    [8, 18, 'Reports', 'Equity Statement'],
    [8, 19, 'Reports', 'Balance Sheet'],
    [8, 20, 'Reports', 'Cash Flow'],

    [9, 1, 'Settings', 'Settings'],

    [10, 1, 'User menu', 'Users'],
    [10, 2, 'User menu', 'Activities'],
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
 * The permission catalog (see DT_PERMISSION_CATALOG).
 *
 * @return list<array{0:int,1:int,2:string,3:string}> Rows: menuId, submenuId, menu label, submenu label.
 */
function dt_permission_items(): array
{
    return DT_PERMISSION_CATALOG;
}

/**
 * Valid permission keys ("menuId-submenuId") of the catalog.
 *
 * @return array<string,true> Keys as array keys.
 */
function dt_permission_keys(): array
{
    $keys = [];
    foreach (DT_PERMISSION_CATALOG as [$menuId, $subId]) {
        $keys[$menuId . '-' . $subId] = true;
    }

    return $keys;
}

/* ================================================================
 * 3. SHARED BUSINESS RULES (read and validate, load and lock, unique username, first admin, references, log)
 * ============================================================== */

/**
 * Read and validate the posted user fields (used by save_user, before the transaction).
 *
 * @return array{id:int,name:string,username:string,password:string,status:string} Clean fields.
 *
 * @throws DtFail On invalid input.
 */
function dt_read_user_input(): array
{
    $id       = max(0, (int)($_POST['userid'] ?? 0));
    $name     = trim((string)($_POST['name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');
    $status   = strtoupper(trim((string)($_POST['status'] ?? '')));

    if ($name === '') {
        throw new DtFail('Full name is required.', false, 'name');
    }
    if (mb_strlen($name) > DT_MAX_NAME) {
        throw new DtFail('Full name is too long (maximum ' . DT_MAX_NAME . ' characters).', false, 'name');
    }
    if ($username === '') {
        throw new DtFail('Username is required.', false, 'username');
    }
    if (mb_strlen($username) > DT_MAX_NAME) {
        throw new DtFail('Username is too long (maximum ' . DT_MAX_NAME . ' characters).', false, 'username');
    }
    if ($id === 0 && $password === '') {
        throw new DtFail('Password is required.', false, 'password');
    }
    if ($password !== '' && strlen($password) < DT_MIN_PASSWORD) {
        throw new DtFail('Password must be at least ' . DT_MIN_PASSWORD . ' characters.', false, 'password');
    }
    if (strlen($password) > DT_MAX_PASSWORD) {
        throw new DtFail('Password is too long (maximum ' . DT_MAX_PASSWORD . ' characters).', false, 'password');
    }
    if ($password !== '' && $password !== $confirm) {
        throw new DtFail('Passwords do not match.', false, 'confirm');
    }
    if ($status !== 'ON' && $status !== 'OFF') {
        throw new DtFail('Invalid status.', false, 'status');
    }

    return ['id' => $id, 'name' => $name, 'username' => $username, 'password' => $password, 'status' => $status];
}

/**
 * Load and lock (FOR UPDATE) a user row. Call inside a transaction (autocommit also works for plain reads).
 *
 * @param PDO $pdo Connection.
 * @param int $id  User id.
 *
 * @return array<string,mixed> Row (id, name).
 *
 * @throws DtFail When not found.
 */
function dt_lock_user(PDO $pdo, int $id): array
{
    $s   = BF_USERS;
    $row = dt_q(
        $pdo,
        "SELECT `{$s['id']}` AS id, `{$s['name']}` AS name FROM `{$s['table']}` WHERE `{$s['id']}` = :id FOR UPDATE",
        ['id' => $id]
    )->fetch();
    if (!is_array($row)) {
        throw new DtFail('User not found.');
    }

    return $row;
}

/**
 * Make sure no other user has the same username (case-insensitive).
 *
 * @param PDO    $pdo      Connection.
 * @param string $username Username to check.
 * @param int    $exceptId Row to ignore (0 when adding).
 *
 * @return void
 *
 * @throws DtFail When the username is already used.
 */
function dt_assert_username_free(PDO $pdo, string $username, int $exceptId): void
{
    $s     = BF_USERS;
    $count = (int)dt_q(
        $pdo,
        "SELECT COUNT(*) FROM `{$s['table']}` WHERE LOWER(`{$s['username']}`) = LOWER(:u) AND `{$s['id']}` <> :id",
        ['u' => $username, 'id' => $exceptId]
    )->fetchColumn();
    if ($count > 0) {
        throw new DtFail('This username already exists.', false, 'username');
    }
}

/**
 * Id of the first administrator (lowest userid), 0 when there is no user.
 *
 * @param PDO $pdo Connection.
 *
 * @return int User id or 0.
 */
function dt_first_admin_id(PDO $pdo): int
{
    $s  = BF_USERS;
    $id = dt_q($pdo, "SELECT MIN(`{$s['id']}`) FROM `{$s['table']}`")->fetchColumn();

    return $id === false || $id === null ? 0 : (int)$id;
}

/**
 * Whether a user is the first administrator.
 *
 * @param PDO $pdo Connection.
 * @param int $id  User id.
 *
 * @return bool True for the first administrator.
 */
function dt_is_first_admin(PDO $pdo, int $id): bool
{
    $first = dt_first_admin_id($pdo);

    return $first > 0 && $first === $id;
}

/**
 * Plural of a reference noun for a count.
 *
 * @param int    $count Count.
 * @param string $noun  Singular noun.
 *
 * @return string "1 sale", "2 sales", "2 batches".
 */
function dt_count_text(int $count, string $noun): string
{
    if ($count === 1) {
        return $count . ' ' . $noun;
    }

    return $count . ' ' . $noun . (str_ends_with($noun, 'ch') ? 'es' : 's');
}

/**
 * Count the records that use a user (see DT_USER_REFS).
 * A missing table or column (SQLSTATE 42S02 / 42S22) is logged and skipped; any other database error is rethrown.
 *
 * @param PDO $pdo Connection.
 * @param int $id  User id.
 *
 * @return array<int,string> Texts such as "2 sales", empty when the user is unused.
 *
 * @throws PDOException On a database error other than a missing table or column.
 */
function dt_count_references(PDO $pdo, int $id): array
{
    $used = [];
    foreach (DT_USER_REFS as [$table, $column, $noun]) {
        try {
            $count = (int)dt_q($pdo, "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :id", ['id' => $id])->fetchColumn();
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
                dt_log_error('[users refs] skipped ' . $table . '.' . $column . ': ' . dt_describe_throwable($e));
                continue;
            }
            throw $e;
        }
        if ($count > 0) {
            $used[] = dt_count_text($count, $noun);
        }
    }

    return $used;
}

/**
 * Write a user activity row (call before commit). Without login the acting user is unknown: userid is NULL ("System" on the dashboard).
 *
 * @param PDO    $pdo    Connection.
 * @param string $action Add | Update | Delete.
 * @param string $text   Description.
 *
 * @return void
 */
function dt_log_user(PDO $pdo, string $action, string $text): void
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
 * List all users ordered by id (the password hash is never selected).
 * Each row carries 'first' (true only for the first administrator), computed once for the whole list.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_list(PDO $pdo): array
{
    $s    = BF_USERS;
    $rows = dt_q(
        $pdo,
        "SELECT `{$s['id']}` AS userid, `{$s['name']}` AS name, `{$s['username']}` AS username, `{$s['status']}` AS status
           FROM `{$s['table']}` ORDER BY `{$s['id']}` ASC"
    )->fetchAll();

    $firstId = dt_first_admin_id($pdo);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'userid'   => (int)$r['userid'],
            'name'     => (string)($r['name'] ?? ''),
            'username' => (string)($r['username'] ?? ''),
            'status'   => (string)($r['status'] ?? ''),
            'first'    => $firstId > 0 && (int)$r['userid'] === $firstId,
        ];
    }

    return ['success' => true, 'data' => $out];
}

/**
 * User options for the Roles selector.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_user_options(PDO $pdo): array
{
    $s    = BF_USERS;
    $rows = dt_q(
        $pdo,
        "SELECT `{$s['id']}` AS userid, `{$s['name']}` AS name FROM `{$s['table']}` ORDER BY `{$s['name']}` ASC, `{$s['id']}` ASC"
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = ['userid' => (int)$r['userid'], 'name' => (string)($r['name'] ?? '')];
    }

    return ['success' => true, 'data' => $out];
}

/**
 * Add (id = 0) or edit a user (one transaction). The first administrator cannot be set to OFF.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input, a duplicate username, a broken rule or a missing row.
 */
function dt_act_save_user(PDO $pdo): array
{
    $d = dt_read_user_input();
    $s = BF_USERS;

    return dt_transaction(static function (PDO $pdo) use ($d, $s): array {
        if ($d['id'] > 0) {
            dt_lock_user($pdo, $d['id']);

            if ($d['status'] === 'OFF' && dt_is_first_admin($pdo, $d['id'])) {
                throw new DtFail('The first administrator cannot be deactivated.', false, 'status');
            }
        }

        dt_assert_username_free($pdo, $d['username'], $d['id']);

        if ($d['id'] > 0) {
            if ($d['password'] !== '') {
                dt_q(
                    $pdo,
                    "UPDATE `{$s['table']}` SET `{$s['name']}` = :n, `{$s['username']}` = :u, `{$s['status']}` = :st, `{$s['password']}` = :p WHERE `{$s['id']}` = :id",
                    ['n' => $d['name'], 'u' => $d['username'], 'st' => $d['status'], 'p' => password_hash($d['password'], PASSWORD_DEFAULT), 'id' => $d['id']]
                );
            } else {
                dt_q(
                    $pdo,
                    "UPDATE `{$s['table']}` SET `{$s['name']}` = :n, `{$s['username']}` = :u, `{$s['status']}` = :st WHERE `{$s['id']}` = :id",
                    ['n' => $d['name'], 'u' => $d['username'], 'st' => $d['status'], 'id' => $d['id']]
                );
            }
            dt_log_user($pdo, 'Update', 'User updated: ' . $d['name']);

            return ['success' => true, 'message' => 'User updated successfully.'];
        }

        dt_q(
            $pdo,
            "INSERT INTO `{$s['table']}` (`{$s['name']}`, `{$s['username']}`, `{$s['password']}`, `{$s['status']}`) VALUES (:n, :u, :p, :st)",
            ['n' => $d['name'], 'u' => $d['username'], 'p' => password_hash($d['password'], PASSWORD_DEFAULT), 'st' => $d['status']]
        );
        dt_log_user($pdo, 'Add', 'User added: ' . $d['name']);

        return ['success' => true, 'message' => 'User added successfully.'];
    });
}

/**
 * Delete a user unless he is the first administrator or other records use him (one transaction).
 * His permission rows are deleted together with him.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found, first administrator or still used (blocked).
 */
function dt_act_delete_user(PDO $pdo): array
{
    $id = max(0, (int)($_POST['userid'] ?? 0));
    $s  = BF_USERS;
    $p  = BF_PERMISSIONS;

    return dt_transaction(static function (PDO $pdo) use ($id, $s, $p): array {
        $row = dt_lock_user($pdo, $id);

        if (dt_is_first_admin($pdo, $id)) {
            throw new DtFail('The first administrator cannot be deleted.', true);
        }

        $used = dt_count_references($pdo, $id);
        if ($used) {
            throw new DtFail('Cannot delete "' . $row['name'] . '": the user is used in ' . implode(', ', $used) . '. Set the status to OFF instead.', true);
        }

        dt_q($pdo, "DELETE FROM `{$p['table']}` WHERE `{$p['user']}` = :id", ['id' => $id]);
        dt_q($pdo, "DELETE FROM `{$s['table']}` WHERE `{$s['id']}` = :id", ['id' => $id]);
        dt_log_user($pdo, 'Delete', 'User deleted: ' . $row['name']);

        return ['success' => true, 'message' => 'User deleted successfully.'];
    });
}

/**
 * Permissions of one user as "menuId-submenuId" ids, plus 'locked'.
 * The first administrator always holds every permission: his ids are built from the catalog
 * without reading the permissions table, and 'locked' is true.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When the user does not exist.
 */
function dt_act_perm_get(PDO $pdo): array
{
    $targetId = max(0, (int)($_POST['userid'] ?? 0));
    dt_lock_user($pdo, $targetId);

    if (dt_is_first_admin($pdo, $targetId)) {
        return ['success' => true, 'ids' => array_keys(dt_permission_keys()), 'locked' => true];
    }

    $p    = BF_PERMISSIONS;
    $rows = dt_q(
        $pdo,
        "SELECT `{$p['menu']}` AS menuid, `{$p['submenu']}` AS submenuid FROM `{$p['table']}` WHERE `{$p['user']}` = :id ORDER BY `{$p['menu']}` ASC, `{$p['submenu']}` ASC",
        ['id' => $targetId]
    )->fetchAll();

    $ids = [];
    foreach ($rows as $r) {
        $ids[] = (int)$r['menuid'] . '-' . (int)$r['submenuid'];
    }

    return ['success' => true, 'ids' => $ids, 'locked' => false];
}

/**
 * Replace the permissions of one user (an empty list removes all). One transaction.
 * The first administrator's permissions cannot be changed: nothing is written for him.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On an invalid permission, the first administrator, or a missing user.
 */
function dt_act_perm_save(PDO $pdo): array
{
    $targetId = max(0, (int)($_POST['userid'] ?? 0));
    $raw      = $_POST['ids'] ?? [];
    if (!is_array($raw)) {
        $raw = [];
    }

    $valid = dt_permission_keys();
    $pairs = [];
    foreach ($raw as $value) {
        $key = trim((string)$value);
        if (!isset($valid[$key])) {
            throw new DtFail('Invalid permission.');
        }
        $pairs[$key] = true;
    }

    $p = BF_PERMISSIONS;

    return dt_transaction(static function (PDO $pdo) use ($targetId, $pairs, $p): array {
        $user = dt_lock_user($pdo, $targetId);
        if (dt_is_first_admin($pdo, $targetId)) {
            throw new DtFail('The first administrator always has every permission.');
        }

        dt_q($pdo, "DELETE FROM `{$p['table']}` WHERE `{$p['user']}` = :id", ['id' => $targetId]);

        $insert = $pdo->prepare("INSERT INTO `{$p['table']}` (`{$p['user']}`, `{$p['menu']}`, `{$p['submenu']}`) VALUES (:u, :m, :s)");
        foreach (array_keys($pairs) as $key) {
            [$menuId, $subId] = array_map('intval', explode('-', (string)$key));
            $insert->execute(['u' => $targetId, 'm' => $menuId, 's' => $subId]);
        }
        dt_log_user($pdo, 'Update', 'Permissions updated: ' . $user['name']);

        return ['success' => true, 'message' => 'Permissions saved successfully.'];
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
        'list'         => 'dt_act_list',
        'user_options' => 'dt_act_user_options',
        'save_user'    => 'dt_act_save_user',
        'delete_user'  => 'dt_act_delete_user',
        'perm_get'     => 'dt_act_perm_get',
        'perm_save'    => 'dt_act_perm_save',
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
        dt_log_error('[users ' . $action . '] ' . dt_describe_throwable($e));
        if ($e->getCode() === '23000' && $action === 'delete_user') {
            dt_json(['success' => false, 'blocked' => true, 'message' => 'Cannot delete: the user is used in other records. Set the status to OFF instead.']);
        }
        if ($e->getCode() === '23000' && $action === 'save_user') {
            dt_json(['success' => false, 'field' => 'username', 'message' => 'This username already exists.']);
        }
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[users ' . $action . '] ' . dt_describe_throwable($e));
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
.is-hidden { display: none !important; }
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
.user-item[aria-current="page"] { color: var(--brand-primary); font-weight: 600; background: rgba(157, 29, 34, 0.08); }
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
.custom-control-input:checked ~ .custom-control-label::before { background-color: var(--brand-accent-dark); border-color: var(--brand-accent-dark); }
.custom-control-input:focus ~ .custom-control-label::before { box-shadow: 0 0 0 2px rgba(22, 160, 74, 0.35); }
.custom-control-input:focus:not(:checked) ~ .custom-control-label::before { border-color: var(--brand-accent); }
.custom-control-input:disabled ~ .custom-control-label { color: var(--muted); }

/* ===== Badges ===== */
.badge { display: inline-block; padding: 2px 8px; font-size: 12.5px; font-weight: 600; line-height: 1.5; color: #ffffff; background: var(--muted); border-radius: 0; }
.badge.st-active { background: var(--brand-accent-dark); }
.badge.st-inactive { background: #6c757d; }

/* ===== Modals ===== */
.modal-content { border: 0; border-radius: 0; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25); }
.modal-header { background: var(--brand-primary); color: #ffffff; border-radius: 0; border-bottom: 0; padding: 10px 20px; }
.modal-header .modal-title { color: #ffffff; font-size: 16px; font-weight: 500; margin: 0; }
.modal-header .close { color: #ffffff; opacity: .9; text-shadow: none; }
.modal-header .close:hover { opacity: 1; color: #ffffff; }
.modal-footer { border-top: 1px solid var(--line); padding: 10px 20px; }

/* ===== Tabs ===== */
.nav-tabs { border-bottom: 1px solid var(--line); }
.nav-tabs.compact-tabs { margin-top: 8px; }
.nav-tabs.compact-tabs .nav-link {
    padding: 6px 14px; font-size: 13px; font-weight: 600; border-radius: 0; border: 1px solid transparent;
    background: var(--surface); color: var(--brand-primary); margin-right: 2px;
}
.nav-tabs.compact-tabs .nav-link:hover { color: var(--brand-accent-dark); }
.nav-tabs.compact-tabs .nav-link.active { background: var(--brand-primary); color: #ffffff; border-color: var(--brand-primary); }
.tab-content { padding-top: 16px; }

/* ===== Permission matrix ===== */
.perm-table { font-size: 14px; }
.perm-table thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); vertical-align: middle; }
.perm-table td { vertical-align: middle; }
.pc25 { width: 25%; }

/* ===== DataTable ===== */
#usersTable { width: 100% !important; font-size: 14px; }
#usersTable thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); white-space: nowrap; font-weight: 600; }
#usersTable td { vertical-align: middle; }
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
.px60 { width: 60px; } .px120 { width: 120px; } .px140 { width: 140px; }

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
                    <a class="user-item" role="menuitem" href="<?= dt_e($page) ?>"<?= $page === 'users.php' ? ' aria-current="page"' : '' ?>><?= dt_icon($icon) ?> <?= dt_e($label) ?></a>
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
 * User list card: header, compact tabs, Users pane and Roles pane.
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
                  <h2 class="header-title">User List</h2>
                  <button type="button" class="btn btn-primary btn-sm" id="btnAdd"><i class="fas fa-plus mr-1" aria-hidden="true"></i> Add User</button>
                </div>

                <ul class="nav nav-tabs compact-tabs" id="usTabs" role="tablist">
                  <li class="nav-item">
                    <a class="nav-link active" id="tab-users" data-toggle="tab" href="#paneUsers" role="tab" aria-controls="paneUsers" aria-selected="true">Users</a>
                  </li>
                  <li class="nav-item">
                    <a class="nav-link" id="tab-roles" data-toggle="tab" href="#paneRoles" role="tab" aria-controls="paneRoles" aria-selected="false">Roles</a>
                  </li>
                </ul>

                <div class="tab-content">

                  <div class="tab-pane fade show active" id="paneUsers" role="tabpanel" aria-labelledby="tab-users">
                    <table id="usersTable" class="table table-hover table-bordered">
                      <thead>
                        <tr>
                          <th class="px60">#</th>
                          <th>Name</th>
                          <th>Username</th>
                          <th class="px140">Status</th>
                          <th class="px120 text-center">Action</th>
                        </tr>
                      </thead>
                      <tbody></tbody>
                    </table>
                  </div>

                  <div class="tab-pane fade" id="paneRoles" role="tabpanel" aria-labelledby="tab-roles">
                    <div class="form-group">
                      <label for="rUser">Select Users <span class="req">*</span></label>
                      <select class="form-control" id="rUser">
                        <option value="">Select user</option>
                      </select>
                    </div>

                    <div id="permWrap" class="is-hidden">
                      <table class="table table-striped perm-table mb-3">
                        <thead>
                          <tr>
                            <th class="pc25">
                              <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="permAll">
                                <label class="custom-control-label" for="permAll">Permission</label>
                              </div>
                            </th>
                            <th>Menu</th>
                            <th>Submenu</th>
                          </tr>
                        </thead>
                        <tbody id="permBody"></tbody>
                      </table>
                    </div>

                    <div class="text-right">
                      <button type="button" class="btn btn-danger btn-sm" id="btnPermCancel">Cancel</button>
                      <button type="button" class="btn btn-success btn-sm" id="btnPermSave" disabled>Submit</button>
                    </div>
                  </div>

                </div>
              </div>
            </div>
          </div>
        </div>
<?php
}

/**
 * Add / edit user modal.
 *
 * @return void
 */
function dt_modal_user(): void
{
    ?>
  <div class="modal fade" id="userModal" tabindex="-1" role="dialog" aria-labelledby="userModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form class="modal-content" id="userForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="userModalTitle">Add User</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="uId" value="0">
          <div class="form-group">
            <label for="uName">Full Name <span class="req">*</span></label>
            <input type="text" class="form-control" id="uName" maxlength="<?= DT_MAX_NAME ?>" required aria-describedby="uNameFb">
            <div class="invalid-feedback" id="uNameFb">Full name is required.</div>
          </div>
          <div class="form-group">
            <label for="uUsername">Username <span class="req">*</span></label>
            <input type="text" class="form-control" id="uUsername" maxlength="<?= DT_MAX_NAME ?>" autocomplete="off" required aria-describedby="uUsernameFb">
            <div class="invalid-feedback" id="uUsernameFb">Username is required.</div>
          </div>
          <div class="form-group">
            <label for="uPassword">Password <span class="req" id="pwReq">*</span></label>
            <input type="password" class="form-control" id="uPassword" maxlength="<?= DT_MAX_PASSWORD ?>" autocomplete="new-password" aria-describedby="uPasswordFb">
            <div class="invalid-feedback" id="uPasswordFb">Password is required.</div>
          </div>
          <div class="form-group">
            <label for="uConfirm">Confirm Password <span class="req" id="pwReq2">*</span></label>
            <input type="password" class="form-control" id="uConfirm" maxlength="<?= DT_MAX_PASSWORD ?>" autocomplete="new-password" aria-describedby="uConfirmFb">
            <div class="invalid-feedback" id="uConfirmFb">Passwords do not match.</div>
          </div>
          <div class="form-group mb-0">
            <label class="d-block" id="uStatusLabel">Status <span class="req">*</span></label>
            <div role="radiogroup" aria-labelledby="uStatusLabel">
              <div class="custom-control custom-radio custom-control-inline">
                <input type="radio" id="uStatusOn" name="status" class="custom-control-input" value="ON" checked>
                <label class="custom-control-label" for="uStatusOn">ON</label>
              </div>
              <div class="custom-control custom-radio custom-control-inline">
                <input type="radio" id="uStatusOff" name="status" class="custom-control-input" value="OFF">
                <label class="custom-control-label" for="uStatusOff">OFF</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveUser"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Delete user modal.
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
          <h5 class="modal-title" id="deleteModalTitle">Delete User</h5>
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
 * Sidebar: key, label, icon, page, submenu links (label, page). Same menu as pages/livestock.php.
 * ALL pages live flat in pages/ (no sales/, people/, accounting/ or reports/ sub-folders), so every link is just a file name.
 * No item is active on this page (Users lives in the user dropdown).
 *
 * @var list<array{0:string,1:string,2:string,3:string,4:list<array{0:string,1:string}>}> $navigation
 */
$navigation = [
    ['dashboard',  'Dashboard',  'home',       'dashboard.php',  []],
    ['sales',      'Sales',      'sales',      'invoices.php', [
        ['Invoices',   'invoices.php'],
        ['Quotations', 'quotations.php'],
    ]],
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
    'csrfField' => $csrfField, 'docTitle' => 'Users', 'heading' => 'Users',
    'sub' => 'Manage the users of the system and their permissions.',
];

/* ---------- Render ---------- */

dt_render_open($page);
dt_view_list();
dt_render_close();
dt_modal_user();
dt_modal_delete();

$cfg       = ['csrf' => $csrf, 'self' => 'users.php', 'minPw' => DT_MIN_PASSWORD, 'perm' => dt_permission_items()];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
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
    var PERM_ITEMS = CFG.perm; // [menuId, submenuId, menuLabel, submenuLabel]

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

    /* ---------- Safe sessionStorage (storage can be blocked) ---------- */
    function ssGet(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
    function ssSet(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
</script>

<!-- ===================== Page JavaScript ===================== -->
<script nonce="<?= $n ?>">
    var usersTable = null;
    var rolesReady = false;

    /* =============================================================
     * Tabs: lazy init, remembered tab, header button follows the tab
     * ============================================================= */
    var TABS = ['paneUsers', 'paneRoles'];

    function onTabShown(pane) {
      ssSet('us_tab', pane);
      $('#btnAdd').toggleClass('is-hidden', pane === 'paneRoles');
      if (pane === 'paneUsers') {
        initUsers();
        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
      } else {
        initRoles();
      }
    }

    $('#usTabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
      onTabShown(String($(e.target).attr('href')).substring(1));
    });

    /* =============================================================
     * USERS TABLE
     * ============================================================= */
    function exportButtons() {
      var cols = { columns: [0, 1, 2, 3] };
      var base = { className: 'btn btn-secondary btn-sm', title: 'Users', exportOptions: cols };
      return [
        $.extend({}, base, { extend: 'copyHtml5',  text: '<i class="fas fa-copy"></i> Copy',        title: '' }),
        $.extend({}, base, { extend: 'csvHtml5',   text: '<i class="fas fa-file-csv"></i> CSV' }),
        $.extend({}, base, { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel' }),
        $.extend({}, base, { extend: 'pdfHtml5',   text: '<i class="fas fa-file-pdf"></i> PDF' }),
        $.extend({}, base, { extend: 'print',      text: '<i class="fas fa-print"></i> Print' })
      ];
    }

    function initUsers() {
      if (usersTable) { return; }
      usersTable = $('#usersTable').DataTable({
        ajax: {
          url: URL_SELF,
          type: 'POST',
          data: function (d) { d.action = 'list'; d.csrf_token = CSRF; },
          dataSrc: 'data',
          error: function (xhr) { if (window.console) { console.warn('users list request failed (HTTP ' + (xhr && xhr.status) + ')'); } }
        },
        columns: [
          { data: 'userid' },
          { data: 'name',     render: function (d) { return esc(d); } },
          { data: 'username', render: function (d) { return esc(d); } },
          {
            data: 'status',
            render: function (d, type) {
              if (type !== 'display') { return d; }
              return '<span class="badge ' + (String(d).toUpperCase() === 'ON' ? 'st-active' : 'st-inactive') + '">' + esc(d) + '</span>';
            }
          },
          {
            data: null, orderable: false, searchable: false, className: 'text-center',
            render: function (d, type, row) {
              var del = row.first === true
                ? '<button type="button" class="btn btn-danger act-btn btn-del" title="The first administrator cannot be deleted" aria-label="Delete user" disabled><i class="fas fa-trash" aria-hidden="true"></i></button>'
                : '<button type="button" class="btn btn-danger act-btn btn-del" title="Delete" aria-label="Delete user"><i class="fas fa-trash" aria-hidden="true"></i></button>';
              return '<button type="button" class="btn btn-primary act-btn btn-edit" title="Edit" aria-label="Edit user"><i class="fas fa-edit" aria-hidden="true"></i></button> ' + del;
            }
          }
        ],
        order: [[0, 'asc']],
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        dom: "<'dt-top'<'dt-len'l><'dt-btns'B><'dt-find'f>>" +
             "<'table-scroll'tr>" +
             "<'row mt-2'<'col-md-5'i><'col-md-7'p>>",
        buttons: exportButtons(),
        language: { search: 'Search:', searchPlaceholder: 'Search users...', emptyTable: 'No users found' }
      });
    }

    function rowData(el) { return usersTable.row($(el).closest('tr')).data(); }

    // After a user save or delete: reload the table (keep the page) and refresh the Roles selector
    function afterUserChange() {
      usersTable.ajax.reload(null, false);
      if (rolesReady) { loadRoleUsers(); }
    }

    /* =============================================================
     * USER ADD / EDIT
     * ============================================================= */
    var FIELDS = { name: '#uName', username: '#uUsername', password: '#uPassword', confirm: '#uConfirm' };
    var FB_DEFAULT = {
      name: 'Full name is required.',
      username: 'Username is required.',
      password: 'Password is required.',
      confirm: 'Passwords do not match.'
    };
    var $userFields = $('#uName, #uUsername, #uPassword, #uConfirm');

    function clearInvalid() {
      $userFields.removeClass('is-invalid');
      $.each(FIELDS, function (key, sel) { $(sel + 'Fb').text(FB_DEFAULT[key]); });
    }

    function markInvalid(key, message) {
      var sel = FIELDS[key];
      if (message) { $(sel + 'Fb').text(message); }
      $(sel).addClass('is-invalid');
    }

    function setPasswordMode(isEdit) {
      $('#pwReq, #pwReq2').toggleClass('is-hidden', isEdit);
      $('#uPassword, #uConfirm').attr('placeholder', isEdit ? 'Leave empty to keep the current password' : '');
    }

    function openUserModal(d) {
      $('#userForm')[0].reset();
      clearInvalid();
      $('#uId').val(d ? d.userid : 0);
      $('#uName').val(d ? d.name : '');
      $('#uUsername').val(d ? d.username : '');
      $('#uPassword').val('');
      $('#uConfirm').val('');
      $(d && String(d.status).toUpperCase() === 'OFF' ? '#uStatusOff' : '#uStatusOn').prop('checked', true);
      $('#uStatusOff').prop('disabled', !!(d && d.first === true)); // the first administrator cannot be set to OFF
      setPasswordMode(!!d);
      $('#userModalTitle').text(d ? 'Edit User' : 'Add User');
      $('#userModal').modal('show');
    }

    $('#btnAdd').on('click', function () { openUserModal(null); });
    $('#usersTable tbody').on('click', '.btn-edit', function () { openUserModal(rowData(this)); });
    $('#userModal').on('shown.bs.modal', function () { $('#uName').trigger('focus'); });
    $userFields.on('input', function () { $(this).removeClass('is-invalid'); });

    $('#userForm').on('submit', function (e) {
      e.preventDefault();
      clearInvalid();

      var isEdit = parseInt($('#uId').val(), 10) > 0;
      var name   = $.trim($('#uName').val());
      var user   = $.trim($('#uUsername').val());
      var pw     = $('#uPassword').val();
      var cf     = $('#uConfirm').val();

      var invalid = [];
      if (name === '') { markInvalid('name'); invalid.push('name'); }
      if (user === '') { markInvalid('username'); invalid.push('username'); }
      if (!isEdit && pw === '') {
        markInvalid('password', 'Password is required.'); invalid.push('password');
      } else if (pw !== '' && pw.length < CFG.minPw) {
        markInvalid('password', 'Password must be at least ' + CFG.minPw + ' characters.'); invalid.push('password');
      }
      if (pw !== '' && pw !== cf) { markInvalid('confirm'); invalid.push('confirm'); }
      if (invalid.length) {
        $(FIELDS[invalid[0]]).trigger('focus');
        return;
      }

      var $btn = $('#btnSaveUser').prop('disabled', true);
      api({
        action: 'save_user',
        userid: $('#uId').val(),
        name: name,
        username: user,
        password: pw,
        confirm: cf,
        status: $('input[name="status"]:checked').val()
      }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#userModal').modal('hide');
          afterUserChange();
          return;
        }
        if (res.field && FIELDS[res.field]) {
          markInvalid(res.field, res.message);
          $(FIELDS[res.field]).trigger('focus');
        }
        toastr.error(res.message);
      }).always(function () { $btn.prop('disabled', false); });
    });

    /* =============================================================
     * DELETE
     * ============================================================= */
    var deleteId = 0;

    $('#usersTable tbody').on('click', '.btn-del', function () {
      var d = rowData(this);
      deleteId = d.userid;
      $('#delName').text(d.name);
      $('#deleteModal').modal('show');
    });

    $('#btnConfirmDelete').on('click', function () {
      var $btn = $(this).prop('disabled', true);
      api({ action: 'delete_user', userid: deleteId }).done(function (res) {
        $('#deleteModal').modal('hide');
        if (res.success) {
          toastr.success(res.message);
          afterUserChange();
        } else if (res.blocked) {
          toastr.warning(res.message);
        } else {
          toastr.error(res.message);
        }
      }).fail(function () {
        $('#deleteModal').modal('hide');
      }).always(function () { $btn.prop('disabled', false); });
    });

    /* =============================================================
     * ROLES (permission matrix)
     * ============================================================= */
    var optReq = 0;
    var permReq = 0;

    function buildMatrix() {
      var $body = $('#permBody').empty();
      $.each(PERM_ITEMS, function (i, it) {
        var val = it[0] + '-' + it[1];
        var cid = 'pc_' + it[0] + '_' + it[1];
        var $box = $('<div>').addClass('custom-control custom-checkbox').append(
          $('<input>').attr({ type: 'checkbox', id: cid, value: val, 'aria-label': it[2] + ' - ' + it[3] }).addClass('custom-control-input perm-cb'),
          $('<label>').addClass('custom-control-label').attr('for', cid)
        );
        $body.append(
          $('<tr>').append(
            $('<td>').append($box),
            $('<td>').text(it[2]),
            $('<td>').text(it[3])
          )
        );
      });
    }

    function syncPermAll() {
      var total = $('.perm-cb').length;
      $('#permAll').prop('checked', total > 0 && $('.perm-cb:checked').length === total);
    }

    // Lock or unlock the matrix (locked = the first administrator, who always has every permission)
    function setPermLocked(locked) {
      $('.perm-cb, #permAll').prop('disabled', locked);
    }

    function clearMatrix() {
      setPermLocked(false);
      $('.perm-cb').prop('checked', false);
      $('#permAll').prop('checked', false);
    }

    function resetRoles() {
      permReq++;
      $('#rUser').val('').trigger('change.select2');
      $('#permWrap').addClass('is-hidden');
      clearMatrix();
      $('#btnPermSave').prop('disabled', true);
    }

    // Rebuild the selector options; keep the selection when it still exists, otherwise reset the panel
    function loadRoleUsers() {
      var keep = $('#rUser').val() || '';
      var req = ++optReq;
      api({ action: 'user_options' }).done(function (res) {
        if (req !== optReq || !res.success) { return; }
        var $s = $('#rUser').empty().append($('<option>').val('').text('Select user'));
        $.each(res.data, function (_, r) {
          $s.append($('<option>').val(r.userid).text(r.name));
        });
        var exists = keep !== '' && $s.find('option').filter(function () { return this.value === String(keep); }).length > 0;
        $s.val(exists ? keep : '').trigger('change.select2');
        if (keep !== '' && !exists) { resetRoles(); }
      });
    }

    function initRoles() {
      if (rolesReady) { return; }
      rolesReady = true;
      buildMatrix();
      $('#rUser').select2({ theme: 'bootstrap4', width: '100%' });
      loadRoleUsers();
    }

    $('#permAll').on('change', function () {
      $('.perm-cb').prop('checked', $(this).prop('checked'));
    });
    $('#permBody').on('change', '.perm-cb', syncPermAll);

    $('#rUser').on('change', function () {
      var uid = $(this).val();
      var req = ++permReq;
      clearMatrix();
      $('#permWrap').addClass('is-hidden');
      $('#btnPermSave').prop('disabled', true);
      if (!uid) { return; }

      api({ action: 'perm_get', userid: uid }).done(function (res) {
        if (req !== permReq) { return; }
        if (!res.success) { toastr.error(res.message); return; }
        $.each(res.ids, function (_, id) {
          $('.perm-cb').filter(function () { return this.value === id; }).prop('checked', true);
        });
        syncPermAll();
        var locked = res.locked === true;
        setPermLocked(locked);
        $('#permWrap').removeClass('is-hidden');
        $('#btnPermSave').prop('disabled', locked);
      });
    });

    $('#btnPermCancel').on('click', resetRoles);

    $('#btnPermSave').on('click', function () {
      var uid = $('#rUser').val();
      if (!uid) { return; }
      var ids = $('.perm-cb:checked').map(function () { return this.value; }).get();
      var $btn = $(this).prop('disabled', true);
      api({ action: 'perm_save', userid: uid, ids: ids }).done(function (res) {
        if (res.success) { toastr.success(res.message); }
        else { toastr.error(res.message); }
      }).always(function () { $btn.prop('disabled', !$('#rUser').val()); });
    });

    /* =============================================================
     * Start: restore the remembered tab (only if valid), otherwise the first
     * ============================================================= */
    var startTab = ssGet('us_tab');
    if (TABS.indexOf(startTab) === -1) { startTab = TABS[0]; }
    if (startTab === TABS[0]) {
      onTabShown(startTab);
    } else {
      $('#usTabs a[href="#' + startTab + '"]').tab('show');
    }
</script>
</body>
</html>