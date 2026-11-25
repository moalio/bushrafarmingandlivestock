<?php

declare(strict_types=1);

/**
 * pages/employees.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: Employees list (sidebar > People > Employees).
 *
 * One page, three AJAX actions (POST, JSON): list, save, delete.
 * Same shell as pages/shareholders.php and pages/customers.php.
 * Duplicate employee names are allowed (two people can share a name).
 * Each employee has an optional salary and an optional commission percentage (both can be used together),
 * one optional grantor (three columns) and one optional identity document file
 * (PDF, JPG, JPEG or PNG, 5 MB maximum) saved in uploads/employees/.
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call the auth helpers of ../db.php right after the
 * "NO LOGIN" marker in the request flow below (and pass the user id to dt_log_employee()).
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

const DT_MAX_NAME        = 150;
const DT_MAX_CONTACT     = 50;
const DT_MAX_ADDRESS     = 255;
const DT_MAX_GRANTOR     = 150;
const DT_MAX_GR_CONTACT  = 50;
const DT_MAX_GR_REL      = 100;
const DT_MAX_SALARY      = 999999999.99; // decimal(11,2)
const DT_MAX_COMMISSION  = 100.0;        // decimal(5,2), percent

const DT_MAX_FILE_BYTES = 5 * 1024 * 1024;       // identity file: 5 MB maximum
const DT_UPLOAD_REL     = 'uploads/employees';   // relative folder stored in the database (next to pages/)

/** Allowed identity file types: real MIME type (finfo) => extension saved. */
const DT_FILE_TYPES = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
];

const DT_LOGO_FILE = 'bushrofarmingandlivestocklogo.png'; // preferred logo file name
const DT_LOGO_DIRS = ['uploads', 'assets', 'images'];     // folders next to pages/ searched for the logo (in this order)

/** Table and column names of the employees table (fixed values, never user input). Fix a name here if your schema differs. */
const BF_EMPLOYEES = [
    'table'               => 'employees',
    'id'                  => 'employeeid',
    'name'                => 'name',
    'contact'             => 'contact',
    'address'             => 'address',
    'identityfile'        => 'identityfile',
    'salary'              => 'salary',
    'commissionpercent'   => 'commissionpercent',
    'grantorname'         => 'grantorname',
    'grantorcontact'      => 'grantorcontact',
    'grantorrelationship' => 'grantorrelationship',
];

/**
 * Tables that reference the employee id and block a delete: [table, column, noun].
 * Fixed whitelist (never user input). A table or column that does not exist is logged and skipped.
 */
const DT_EMPLOYEE_REFS = [
    ['payrolls', 'employeeid', 'payroll'],
    ['payrollbills', 'employeeid', 'payroll bill'],
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
 * 3. SHARED BUSINESS RULES (read and validate, files, load and lock, count references, log)
 * ============================================================== */

/**
 * Read and validate an optional decimal field (empty means 0.00).
 *
 * @param string $raw      Raw posted text.
 * @param float  $max      Maximum allowed value.
 * @param string $message  Failure message.
 * @param string $field    Field name for the failure.
 *
 * @return string Value formatted with 2 decimals.
 *
 * @throws DtFail When the value is not a number between 0 and the maximum.
 */
function dt_read_decimal(string $raw, float $max, string $message, string $field): string
{
    if ($raw === '') {
        return '0.00';
    }
    if (!is_numeric($raw)) {
        throw new DtFail($message, false, $field);
    }
    $value = (float)$raw;
    if (!is_finite($value) || $value < 0 || $value > $max) {
        throw new DtFail($message, false, $field);
    }

    return number_format(round($value, 2), 2, '.', '');
}

/**
 * Read and validate the posted employee fields (used by save). The uploaded file is handled by dt_save_identity_file().
 *
 * @return array{id:int,name:string,contact:?string,address:?string,salary:string,commission:string,grname:?string,grcontact:?string,grrel:?string,removefile:bool} Clean fields (empty optional text becomes null, empty salary and commission become 0.00).
 *
 * @throws DtFail On invalid input.
 */
function dt_read_employee_input(): array
{
    $id         = max(0, (int)($_POST['employeeid'] ?? 0));
    $name       = trim((string)($_POST['name'] ?? ''));
    $contact    = trim((string)($_POST['contact'] ?? ''));
    $address    = trim((string)($_POST['address'] ?? ''));
    $rawSalary  = trim((string)($_POST['salary'] ?? ''));
    $rawComm    = trim((string)($_POST['commissionpercent'] ?? ''));
    $grName     = trim((string)($_POST['grantorname'] ?? ''));
    $grContact  = trim((string)($_POST['grantorcontact'] ?? ''));
    $grRel      = trim((string)($_POST['grantorrelationship'] ?? ''));
    $removeFile = ((string)($_POST['removefile'] ?? '0')) === '1';

    if ($name === '') {
        throw new DtFail('Employee name is required.', false, 'name');
    }
    if (mb_strlen($name) > DT_MAX_NAME) {
        throw new DtFail('One of the fields is too long.', false, 'name');
    }
    if (mb_strlen($contact) > DT_MAX_CONTACT) {
        throw new DtFail('One of the fields is too long.', false, 'contact');
    }
    if (mb_strlen($address) > DT_MAX_ADDRESS) {
        throw new DtFail('One of the fields is too long.', false, 'address');
    }

    $salary     = dt_read_decimal($rawSalary, DT_MAX_SALARY, 'Invalid salary.', 'salary');
    $commission = dt_read_decimal($rawComm, DT_MAX_COMMISSION, 'Commission must be between 0 and 100.', 'commissionpercent');

    if (mb_strlen($grName) > DT_MAX_GRANTOR) {
        throw new DtFail('One of the fields is too long.', false, 'grantorname');
    }
    if (mb_strlen($grContact) > DT_MAX_GR_CONTACT) {
        throw new DtFail('One of the fields is too long.', false, 'grantorcontact');
    }
    if (mb_strlen($grRel) > DT_MAX_GR_REL) {
        throw new DtFail('One of the fields is too long.', false, 'grantorrelationship');
    }
    if ($grName === '' && ($grContact !== '' || $grRel !== '')) {
        throw new DtFail('Grantor name is required when grantor details are filled.', false, 'grantorname');
    }

    return [
        'id'         => $id,
        'name'       => $name,
        'contact'    => $contact === '' ? null : $contact,
        'address'    => $address === '' ? null : $address,
        'salary'     => $salary,
        'commission' => $commission,
        'grname'     => $grName === '' ? null : $grName,
        'grcontact'  => $grContact === '' ? null : $grContact,
        'grrel'      => $grRel === '' ? null : $grRel,
        'removefile' => $removeFile,
    ];
}

/**
 * Make sure uploads/employees/ exists (mode 0755) and holds its .htaccess (no listing, no script execution).
 *
 * @return string Absolute folder path (no trailing slash).
 *
 * @throws DtFail When the folder or the .htaccess cannot be created.
 */
function dt_ensure_upload_dir(): string
{
    $dir = __DIR__ . '/../' . DT_UPLOAD_REL;

    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        dt_log_error('[employees upload] cannot create folder ' . $dir);
        throw new DtFail('The upload folder could not be created.', false, 'identityfile');
    }

    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        $rules = "Options -Indexes\n"
            . "<FilesMatch \"\\.(php|php[0-9]|phtml|phar|pht|phps|cgi|pl|py|sh|asp|aspx|jsp)$\">\n"
            . "    Require all denied\n"
            . "</FilesMatch>\n";
        if (file_put_contents($htaccess, $rules, LOCK_EX) === false) {
            dt_log_error('[employees upload] cannot write ' . $htaccess);
            throw new DtFail('The upload folder could not be secured.', false, 'identityfile');
        }
    }

    return $dir;
}

/**
 * Validate and save the uploaded identity file (field "identityfile").
 * Checks the upload error code, is_uploaded_file, the size and the real MIME type (finfo); the extension comes from the MIME type.
 *
 * @return string|null Relative path (uploads/employees/NAME.ext), or null when no file was uploaded.
 *
 * @throws DtFail On an invalid or unsaved file.
 */
function dt_save_identity_file(): ?string
{
    $file = $_FILES['identityfile'] ?? null;
    if (!is_array($file) || !isset($file['error']) || !is_int($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $tooBig = 'The file is too large (maximum 5 MB).';
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        throw new DtFail($tooBig, false, 'identityfile');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new DtFail('The file could not be uploaded. Please try again.', false, 'identityfile');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new DtFail('The file could not be uploaded. Please try again.', false, 'identityfile');
    }

    $size = filesize($tmp);
    if ($size === false || $size <= 0) {
        throw new DtFail('The file is empty.', false, 'identityfile');
    }
    if ($size > DT_MAX_FILE_BYTES) {
        throw new DtFail($tooBig, false, 'identityfile');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmp);
    if (!is_string($mime) || !isset(DT_FILE_TYPES[$mime])) {
        throw new DtFail('Only PDF, JPG, JPEG or PNG files are allowed.', false, 'identityfile');
    }

    $dir    = dt_ensure_upload_dir();
    $name   = bin2hex(random_bytes(16)) . '.' . DT_FILE_TYPES[$mime];
    $target = $dir . '/' . $name;

    if (!move_uploaded_file($tmp, $target)) {
        dt_log_error('[employees upload] move_uploaded_file failed for ' . $target);
        throw new DtFail('The file could not be saved. Please try again.', false, 'identityfile');
    }
    @chmod($target, 0644);

    return DT_UPLOAD_REL . '/' . $name;
}

/**
 * Delete an identity file from disk. Only paths made by dt_save_identity_file() are accepted. Failures are logged, never thrown.
 *
 * @param string|null $relative Relative path stored in the database (null or empty is ignored).
 *
 * @return void
 */
function dt_delete_identity_file(?string $relative): void
{
    if ($relative === null || $relative === '') {
        return;
    }
    if (preg_match('#^' . preg_quote(DT_UPLOAD_REL, '#') . '/[a-f0-9]{32}\.(pdf|jpg|png)$#', $relative) !== 1) {
        dt_log_error('[employees upload] refused to delete unexpected path: ' . $relative);

        return;
    }

    $path = __DIR__ . '/../' . $relative;
    if (is_file($path) && !@unlink($path)) {
        dt_log_error('[employees upload] cannot delete ' . $path);
    }
}

/**
 * Load and lock (FOR UPDATE) an employee row. Call inside a transaction.
 *
 * @param PDO $pdo Connection.
 * @param int $id  Employee id.
 *
 * @return array<string,mixed> Row (id, name, identityfile).
 *
 * @throws DtFail When not found.
 */
function dt_lock_employee(PDO $pdo, int $id): array
{
    $s   = BF_EMPLOYEES;
    $row = dt_q(
        $pdo,
        "SELECT `{$s['id']}` AS id, `{$s['name']}` AS name, `{$s['identityfile']}` AS identityfile FROM `{$s['table']}` WHERE `{$s['id']}` = :id FOR UPDATE",
        ['id' => $id]
    )->fetch();
    if (!is_array($row)) {
        throw new DtFail('Employee not found.');
    }

    return $row;
}

/**
 * Count the records that use an employee (see DT_EMPLOYEE_REFS).
 * A missing table or column (SQLSTATE 42S02 / 42S22) is logged and skipped; any other database error is rethrown.
 *
 * @param PDO $pdo Connection.
 * @param int $id  Employee id.
 *
 * @return array<int,string> Texts such as "2 payrolls", empty when the employee is unused.
 *
 * @throws PDOException On a database error other than a missing table or column.
 */
function dt_count_references(PDO $pdo, int $id): array
{
    $used = [];
    foreach (DT_EMPLOYEE_REFS as [$table, $column, $noun]) {
        try {
            $count = (int)dt_q($pdo, "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :id", ['id' => $id])->fetchColumn();
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
                dt_log_error('[employees refs] skipped ' . $table . '.' . $column . ': ' . dt_describe_throwable($e));
                continue;
            }
            throw $e;
        }
        if ($count > 0) {
            $plural = $count > 1 ? (str_ends_with($noun, 'y') ? substr($noun, 0, -1) . 'ies' : $noun . 's') : $noun;
            $used[] = $count . ' ' . $plural;
        }
    }

    return $used;
}

/**
 * Write an employee activity row (call before commit). Without login the user is unknown: userid is NULL ("System" on the dashboard).
 *
 * @param PDO    $pdo    Connection.
 * @param string $action Add | Update | Delete.
 * @param string $text   Description.
 *
 * @return void
 */
function dt_log_employee(PDO $pdo, string $action, string $text): void
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
 * List all employees ordered by id.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_list(PDO $pdo): array
{
    $s    = BF_EMPLOYEES;
    $rows = dt_q(
        $pdo,
        "SELECT `{$s['id']}` AS employeeid, `{$s['name']}` AS name, `{$s['contact']}` AS contact, `{$s['address']}` AS address,
                `{$s['identityfile']}` AS identityfile, `{$s['salary']}` AS salary, `{$s['commissionpercent']}` AS commissionpercent,
                `{$s['grantorname']}` AS grantorname, `{$s['grantorcontact']}` AS grantorcontact, `{$s['grantorrelationship']}` AS grantorrelationship
           FROM `{$s['table']}` ORDER BY `{$s['id']}` ASC"
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'employeeid'          => (int)$r['employeeid'],
            'name'                => (string)($r['name'] ?? ''),
            'contact'             => (string)($r['contact'] ?? ''),
            'address'             => (string)($r['address'] ?? ''),
            'identityfile'        => (string)($r['identityfile'] ?? ''),
            'salary'              => number_format((float)($r['salary'] ?? 0), 2, '.', ''),
            'commissionpercent'   => number_format((float)($r['commissionpercent'] ?? 0), 2, '.', ''),
            'grantorname'         => (string)($r['grantorname'] ?? ''),
            'grantorcontact'      => (string)($r['grantorcontact'] ?? ''),
            'grantorrelationship' => (string)($r['grantorrelationship'] ?? ''),
        ];
    }

    return ['success' => true, 'data' => $out];
}

/**
 * Add (id = 0) or edit an employee (one transaction). A new identity file is saved before the transaction,
 * removed again when the transaction fails; a replaced or removed old file is deleted only after the commit.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input, an invalid file or a missing row.
 */
function dt_act_save(PDO $pdo): array
{
    $d       = dt_read_employee_input();
    $s       = BF_EMPLOYEES;
    $newFile = dt_save_identity_file();

    try {
        $result = dt_transaction(static function (PDO $pdo) use ($d, $s, $newFile): array {
            if ($d['id'] > 0) {
                $row = dt_lock_employee($pdo, $d['id']);
                $old = (is_string($row['identityfile'] ?? null) && $row['identityfile'] !== '') ? $row['identityfile'] : null;

                $fileValue = $old;
                $cleanup   = null;
                if ($newFile !== null) {
                    $fileValue = $newFile;
                    $cleanup   = $old;
                } elseif ($d['removefile']) {
                    $fileValue = null;
                    $cleanup   = $old;
                }

                dt_q(
                    $pdo,
                    "UPDATE `{$s['table']}`
                        SET `{$s['name']}` = :n, `{$s['contact']}` = :c, `{$s['address']}` = :a, `{$s['identityfile']}` = :f,
                            `{$s['salary']}` = :s, `{$s['commissionpercent']}` = :cm,
                            `{$s['grantorname']}` = :gn, `{$s['grantorcontact']}` = :gc, `{$s['grantorrelationship']}` = :gr
                      WHERE `{$s['id']}` = :id",
                    [
                        'n'  => $d['name'],
                        'c'  => $d['contact'],
                        'a'  => $d['address'],
                        'f'  => $fileValue,
                        's'  => $d['salary'],
                        'cm' => $d['commission'],
                        'gn' => $d['grname'],
                        'gc' => $d['grcontact'],
                        'gr' => $d['grrel'],
                        'id' => $d['id'],
                    ]
                );
                dt_log_employee($pdo, 'Update', 'Employee updated: ' . $d['name']);

                return ['payload' => ['success' => true, 'message' => 'Employee updated successfully.'], 'cleanup' => $cleanup];
            }

            dt_q(
                $pdo,
                "INSERT INTO `{$s['table']}`
                    (`{$s['name']}`, `{$s['contact']}`, `{$s['address']}`, `{$s['identityfile']}`,
                     `{$s['salary']}`, `{$s['commissionpercent']}`,
                     `{$s['grantorname']}`, `{$s['grantorcontact']}`, `{$s['grantorrelationship']}`)
                 VALUES (:n, :c, :a, :f, :s, :cm, :gn, :gc, :gr)",
                [
                    'n'  => $d['name'],
                    'c'  => $d['contact'],
                    'a'  => $d['address'],
                    'f'  => $newFile,
                    's'  => $d['salary'],
                    'cm' => $d['commission'],
                    'gn' => $d['grname'],
                    'gc' => $d['grcontact'],
                    'gr' => $d['grrel'],
                ]
            );
            dt_log_employee($pdo, 'Add', 'Employee added: ' . $d['name']);

            return ['payload' => ['success' => true, 'message' => 'Employee added successfully.'], 'cleanup' => null];
        });
    } catch (Throwable $e) {
        dt_delete_identity_file($newFile); // no orphan file after a failed save
        throw $e;
    }

    dt_delete_identity_file($result['cleanup']); // old file goes only after the commit

    return $result['payload'];
}

/**
 * Delete an employee unless other records use it (one transaction), then delete its identity file from disk.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found or still used (blocked).
 */
function dt_act_delete(PDO $pdo): array
{
    $id = max(0, (int)($_POST['employeeid'] ?? 0));
    $s  = BF_EMPLOYEES;

    $result = dt_transaction(static function (PDO $pdo) use ($id, $s): array {
        $row = dt_lock_employee($pdo, $id);

        $used = dt_count_references($pdo, $id);
        if ($used) {
            throw new DtFail('Cannot delete "' . $row['name'] . '": the employee has ' . implode(', ', $used) . '.', true);
        }

        dt_q($pdo, "DELETE FROM `{$s['table']}` WHERE `{$s['id']}` = :id", ['id' => $id]);
        dt_log_employee($pdo, 'Delete', 'Employee deleted: ' . $row['name']);

        $file = (is_string($row['identityfile'] ?? null) && $row['identityfile'] !== '') ? $row['identityfile'] : null;

        return ['payload' => ['success' => true, 'message' => 'Employee deleted successfully.'], 'cleanup' => $file];
    });

    dt_delete_identity_file($result['cleanup']); // file goes only after the commit

    return $result['payload'];
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

    $handlers = ['list' => 'dt_act_list', 'save' => 'dt_act_save', 'delete' => 'dt_act_delete'];
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
        dt_log_error('[employees ' . $action . '] ' . dt_describe_throwable($e));
        if ($e->getCode() === '23000' && $action === 'delete') {
            dt_json(['success' => false, 'blocked' => true, 'message' => 'Cannot delete: the employee is used in other records.']);
        }
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[employees ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    }
}

/* ================================================================
 * 5. HTML SHELL (same look as shareholders.php): CSS, head, topbar, sidebar, footer
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
.form-control-file { font-size: 14px; max-width: 100%; }
.form-control-file.is-invalid { outline: 2px solid var(--danger); outline-offset: 2px; }
.invalid-feedback { color: var(--danger); font-size: 13px; font-weight: 500; }
label { color: var(--heading); font-weight: 500; font-size: 14px; }
label .req { color: var(--danger); }
.form-section-title {
    margin: 0 0 12px; padding-bottom: 4px; border-bottom: 1px solid var(--line);
    font-size: 13px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: var(--brand-primary);
}
.form-section-title.spaced { margin-top: 18px; }
.file-note { font-size: 13px; color: var(--muted); }
.current-file { font-size: 14px; color: var(--text); word-break: break-all; }
.text-muted { color: var(--muted) !important; }

/* ===== Modals ===== */
.modal-content { border: 0; border-radius: 0; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25); }
.modal-header { background: var(--brand-primary); color: #ffffff; border-radius: 0; border-bottom: 0; padding: 10px 20px; }
.modal-header .modal-title { color: #ffffff; font-size: 16px; font-weight: 500; margin: 0; }
.modal-header .close { color: #ffffff; opacity: .9; text-shadow: none; }
.modal-header .close:hover { opacity: 1; color: #ffffff; }
.modal-footer { border-top: 1px solid var(--line); padding: 10px 20px; }

/* ===== DataTable ===== */
#employeesTable { width: 100% !important; font-size: 14px; }
#employeesTable thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); white-space: nowrap; font-weight: 600; }
#employeesTable td { vertical-align: middle; }
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
        <?php foreach ($p['nav'] as [$key, $label, $icon, $page, $subs]):
            $isPeople = ($key === 'people'); ?>
            <li>
                <?php if ($subs): ?>
                    <button type="button" class="nav-btn" data-sub title="<?= dt_e($label) ?>" aria-expanded="<?= $isPeople ? 'true' : 'false' ?>" aria-controls="sub-<?= dt_e($key) ?>">
                        <?= dt_icon($icon) ?> <span><?= dt_e($label) ?></span><?= dt_icon('caret') ?>
                    </button>
                    <div class="sub<?= $isPeople ? ' show' : '' ?>" id="sub-<?= dt_e($key) ?>">
                        <?php foreach ($subs as [$subLabel, $subPage]): ?>
                            <a href="<?= dt_e($subPage) ?>"<?= ($isPeople && $subPage === 'employees.php') ? ' class="active" aria-current="page"' : '' ?>><span><?= dt_e($subLabel) ?></span></a>
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
 * Employee list card.
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
                  <h2 class="header-title">Employee List</h2>
                  <button type="button" class="btn btn-primary btn-sm" id="btnAdd"><i class="fas fa-plus mr-1" aria-hidden="true"></i> Add Employee</button>
                </div>

                <table id="employeesTable" class="table table-hover table-bordered">
                  <thead>
                    <tr>
                      <th class="px60">#</th>
                      <th>Name</th>
                      <th>Contact</th>
                      <th>Address</th>
                      <th>Salary</th>
                      <th>Commission %</th>
                      <th>Grantor</th>
                      <th>Relationship</th>
                      <th>Grantor Contact</th>
                      <th>Identity</th>
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
 * Add / edit employee modal.
 *
 * @return void
 */
function dt_modal_employee(): void
{
    ?>
  <div class="modal fade" id="employeeModal" tabindex="-1" role="dialog" aria-labelledby="employeeModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
      <form class="modal-content" id="employeeForm" novalidate autocomplete="off" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title" id="employeeModalTitle">Add Employee</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="eId" value="0">

          <h6 class="form-section-title">Employee</h6>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="eName">Employee name <span class="req">*</span></label>
              <input type="text" class="form-control" id="eName" maxlength="<?= DT_MAX_NAME ?>" required aria-describedby="eNameFb">
              <div class="invalid-feedback" id="eNameFb">Employee name is required.</div>
            </div>
            <div class="form-group col-md-6">
              <label for="eContact">Contact</label>
              <input type="text" class="form-control" id="eContact" maxlength="<?= DT_MAX_CONTACT ?>" aria-describedby="eContactFb">
              <div class="invalid-feedback" id="eContactFb">One of the fields is too long.</div>
            </div>
          </div>
          <div class="form-group">
            <label for="eAddress">Address</label>
            <input type="text" class="form-control" id="eAddress" maxlength="<?= DT_MAX_ADDRESS ?>" aria-describedby="eAddressFb">
            <div class="invalid-feedback" id="eAddressFb">One of the fields is too long.</div>
          </div>
          <div class="form-group mb-0">
            <label for="eFile">Identity document file</label>
            <div id="eCurrent" class="current-file mb-1" hidden>
              Current file: <span id="eCurrentName"></span>
              <div class="custom-control custom-checkbox mt-1">
                <input type="checkbox" class="custom-control-input" id="eRemoveFile">
                <label class="custom-control-label" for="eRemoveFile">Remove file</label>
              </div>
            </div>
            <input type="file" class="form-control-file" id="eFile" accept=".pdf,.jpg,.jpeg,.png" aria-describedby="eFileNote eFileFb">
            <div class="file-note" id="eFileNote">PDF, JPG, JPEG or PNG, maximum 5 MB.</div>
            <div class="invalid-feedback" id="eFileFb">Only PDF, JPG, JPEG or PNG files are allowed.</div>
          </div>

          <h6 class="form-section-title spaced">Pay</h6>
          <div class="form-row">
            <div class="form-group col-md-6 mb-1">
              <label for="eSalary">Salary</label>
              <input type="number" class="form-control" id="eSalary" step="0.01" min="0" placeholder="0.00" aria-describedby="eSalaryFb ePayNote">
              <div class="invalid-feedback" id="eSalaryFb">Invalid salary.</div>
            </div>
            <div class="form-group col-md-6 mb-1">
              <label for="eCommission">Commission %</label>
              <input type="number" class="form-control" id="eCommission" step="0.01" min="0" max="100" placeholder="0.00" aria-describedby="eCommissionFb ePayNote">
              <div class="invalid-feedback" id="eCommissionFb">Commission must be between 0 and 100.</div>
            </div>
          </div>
          <div class="file-note" id="ePayNote">Fill one or both. 0.00 means none.</div>

          <h6 class="form-section-title spaced">Grantor</h6>
          <div class="form-row">
            <div class="form-group col-md-4 mb-0">
              <label for="eGrantorName">Grantor name</label>
              <input type="text" class="form-control" id="eGrantorName" maxlength="<?= DT_MAX_GRANTOR ?>" aria-describedby="eGrantorNameFb">
              <div class="invalid-feedback" id="eGrantorNameFb">Grantor name is required when grantor details are filled.</div>
            </div>
            <div class="form-group col-md-4 mb-0">
              <label for="eGrantorContact">Grantor contact</label>
              <input type="text" class="form-control" id="eGrantorContact" maxlength="<?= DT_MAX_GR_CONTACT ?>" aria-describedby="eGrantorContactFb">
              <div class="invalid-feedback" id="eGrantorContactFb">One of the fields is too long.</div>
            </div>
            <div class="form-group col-md-4 mb-0">
              <label for="eGrantorRelationship">Relationship</label>
              <input type="text" class="form-control" id="eGrantorRelationship" maxlength="<?= DT_MAX_GR_REL ?>" placeholder="father, brother, friend..." aria-describedby="eGrantorRelationshipFb">
              <div class="invalid-feedback" id="eGrantorRelationshipFb">One of the fields is too long.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveEmployee"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Delete employee modal.
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
          <h5 class="modal-title" id="deleteModalTitle">Delete Employee</h5>
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
    'csrfField' => $csrfField, 'docTitle' => 'Employees', 'heading' => 'Employees',
    'sub' => 'Manage the employees of the farm.',
];

/* ---------- Render ---------- */

dt_render_open($page);
dt_view_list();
dt_render_close();
dt_modal_employee();
dt_modal_delete();

$cfg       = ['csrf' => $csrf, 'self' => 'employees.php', 'maxFile' => DT_MAX_FILE_BYTES, 'maxSalary' => DT_MAX_SALARY, 'maxCommission' => DT_MAX_COMMISSION];
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
    var MAX_FILE = CFG.maxFile;
    var MAX_SALARY = CFG.maxSalary;
    var MAX_COMMISSION = CFG.maxCommission;

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

    function apiFail(xhr) {
      var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Request failed. Please try again.';
      toastr.error(msg);
    }

    // POST helper: always sends CSRF, expects JSON
    function api(data) {
      data.csrf_token = CSRF;
      return $.ajax({ url: URL_SELF, type: 'POST', data: data, dataType: 'json' }).fail(apiFail);
    }

    // POST helper for multipart/form-data (file upload): the caller passes a FormData; CSRF is added here
    function apiForm(fd) {
      fd.append('csrf_token', CSRF);
      return $.ajax({ url: URL_SELF, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' }).fail(apiFail);
    }

    // Number column: raw number for sorting and filtering, 2 decimals (and an optional suffix) for display and export
    function numberRender(suffix) {
      return function (d, type) {
        var v = parseFloat(d);
        if (isNaN(v)) { v = 0; }
        return type === 'display' || type === 'export' ? v.toFixed(2) + (suffix || '') : v;
      };
    }
</script>

<!-- ===================== Page JavaScript ===================== -->
<script nonce="<?= $n ?>">
    /* ---------- DataTable ---------- */
    var exportCols = { columns: [0, 1, 2, 3, 4, 5, 6, 7, 8] };

    var table = $('#employeesTable').DataTable({
      ajax: {
        url: URL_SELF,
        type: 'POST',
        data: function (d) { d.action = 'list'; d.csrf_token = CSRF; },
        dataSrc: 'data',
        error: function (xhr) { if (window.console) { console.warn('employees list request failed (HTTP ' + (xhr && xhr.status) + ')'); } }
      },
      columns: [
        { data: 'employeeid' },
        { data: 'name',                render: function (d) { return esc(d); } },
        { data: 'contact',             render: function (d) { return esc(d); } },
        { data: 'address',             render: function (d) { return esc(d); } },
        { data: 'salary',              type: 'num', className: 'text-right', render: numberRender('') },
        { data: 'commissionpercent',   type: 'num', className: 'text-right', render: numberRender('%') },
        { data: 'grantorname',         render: function (d) { return esc(d); } },
        { data: 'grantorrelationship', render: function (d) { return esc(d); } },
        { data: 'grantorcontact',      render: function (d) { return esc(d); } },
        {
          data: 'identityfile', orderable: false, searchable: false,
          render: function (d) {
            if (!d) { return '<span class="text-muted">No file</span>'; }
            return '<a class="btn btn-secondary act-btn" href="' + encodeURI('../' + d) + '" target="_blank" rel="noopener"><i class="fas fa-id-card" aria-hidden="true"></i> View</a>';
          }
        },
        {
          data: null, orderable: false, searchable: false, className: 'text-center',
          render: function () {
            return '<button type="button" class="btn btn-primary act-btn btn-edit" title="Edit" aria-label="Edit employee"><i class="fas fa-edit" aria-hidden="true"></i></button> ' +
                   '<button type="button" class="btn btn-danger act-btn btn-del" title="Delete" aria-label="Delete employee"><i class="fas fa-trash" aria-hidden="true"></i></button>';
          }
        }
      ],
      order: [[0, 'asc']],
      pageLength: 10,
      lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
      dom: "<'dt-top'<'dt-len'l><'dt-btns'B><'dt-find'f>>" +
           "<'table-scroll'tr>" +
           "<'row mt-2'<'col-md-5'i><'col-md-7'p>>",
      buttons: [
        { extend: 'copyHtml5',  className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-copy"></i> Copy',        exportOptions: exportCols },
        { extend: 'csvHtml5',   className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-csv"></i> CSV',     exportOptions: exportCols },
        { extend: 'excelHtml5', className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-excel"></i> Excel', title: 'Employees', exportOptions: exportCols },
        { extend: 'pdfHtml5',   className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-file-pdf"></i> PDF',     title: 'Employees', exportOptions: exportCols },
        { extend: 'print',      className: 'btn btn-secondary btn-sm', text: '<i class="fas fa-print"></i> Print',      title: 'Employees', exportOptions: exportCols }
      ],
      language: { search: 'Search:', searchPlaceholder: 'Search employee...', emptyTable: 'No employees found' }
    });

    function rowData(el) { return table.row($(el).closest('tr')).data(); }

    /* ---------- Fields: server field name => input selector (feedback element is selector + "Fb") ---------- */
    var FIELDS = {
      name: '#eName',
      contact: '#eContact',
      address: '#eAddress',
      identityfile: '#eFile',
      salary: '#eSalary',
      commissionpercent: '#eCommission',
      grantorname: '#eGrantorName',
      grantorcontact: '#eGrantorContact',
      grantorrelationship: '#eGrantorRelationship'
    };
    var DEFAULT_FB = {};
    $.each(FIELDS, function (key, sel) { DEFAULT_FB[key] = $(sel + 'Fb').text(); });

    var FILE_TYPE_MSG = 'Only PDF, JPG, JPEG or PNG files are allowed.';
    var FILE_SIZE_MSG = 'The file is too large (maximum 5 MB).';

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

    /* Client-side file check (type by extension, size). The server checks the real MIME type again. */
    function fileProblem(file) {
      if (!file) { return ''; }
      if (!/\.(pdf|jpe?g|png)$/i.test(file.name)) { return FILE_TYPE_MSG; }
      if (file.size > MAX_FILE) { return FILE_SIZE_MSG; }
      if (file.size <= 0) { return 'The file is empty.'; }
      return '';
    }

    /* Optional number check: empty is fine (saved as 0.00), otherwise 0..max */
    function numberProblem(text, max) {
      if (text === '') { return false; }
      var v = Number(text);
      return isNaN(v) || !isFinite(v) || v < 0 || v > max;
    }

    /* ---------- Add / Edit modal ---------- */
    function openEmployeeModal(d) {
      $('#employeeForm')[0].reset();
      clearInvalid();
      $('#eId').val(d ? d.employeeid : 0);
      $('#eName').val(d ? d.name : '');
      $('#eContact').val(d ? d.contact : '');
      $('#eAddress').val(d ? d.address : '');
      $('#eSalary').val(d ? parseFloat(d.salary || 0).toFixed(2) : '');
      $('#eCommission').val(d ? parseFloat(d.commissionpercent || 0).toFixed(2) : '');
      $('#eGrantorName').val(d ? d.grantorname : '');
      $('#eGrantorContact').val(d ? d.grantorcontact : '');
      $('#eGrantorRelationship').val(d ? d.grantorrelationship : '');
      $('#eRemoveFile').prop('checked', false);
      if (d && d.identityfile) {
        $('#eCurrentName').text(d.identityfile.split('/').pop());
        $('#eCurrent').prop('hidden', false);
      } else {
        $('#eCurrentName').text('');
        $('#eCurrent').prop('hidden', true);
      }
      $('#employeeModalTitle').text(d ? 'Edit Employee' : 'Add Employee');
      $('#employeeModal').modal('show');
    }

    $('#btnAdd').on('click', function () { openEmployeeModal(null); });
    $('#employeesTable tbody').on('click', '.btn-edit', function () { openEmployeeModal(rowData(this)); });
    $('#employeeModal').on('shown.bs.modal', function () { $('#eName').trigger('focus'); });
    $('#employeeForm input').on('input change', function () { $(this).removeClass('is-invalid'); });

    $('#eFile').on('change', function () {
      var problem = fileProblem(this.files[0]);
      if (problem) {
        markInvalid('identityfile', problem);
        this.value = '';
      }
    });

    /* ---------- Save (add / edit) ---------- */
    $('#employeeForm').on('submit', function (e) {
      e.preventDefault();
      clearInvalid();

      var name       = $.trim($('#eName').val());
      var contact    = $.trim($('#eContact').val());
      var address    = $.trim($('#eAddress').val());
      var salary     = $.trim($('#eSalary').val());
      var commission = $.trim($('#eCommission').val());
      var grName     = $.trim($('#eGrantorName').val());
      var grContact  = $.trim($('#eGrantorContact').val());
      var grRel      = $.trim($('#eGrantorRelationship').val());
      var file       = $('#eFile')[0].files[0] || null;

      if (name === '') {
        $(markInvalid('name')).trigger('focus');
        return;
      }
      var problem = fileProblem(file);
      if (problem) {
        $(markInvalid('identityfile', problem)).trigger('focus');
        return;
      }
      if (numberProblem(salary, MAX_SALARY)) {
        $(markInvalid('salary')).trigger('focus');
        return;
      }
      if (numberProblem(commission, MAX_COMMISSION)) {
        $(markInvalid('commissionpercent')).trigger('focus');
        return;
      }
      if (grName === '' && (grContact !== '' || grRel !== '')) {
        $(markInvalid('grantorname')).trigger('focus');
        return;
      }

      var fd = new FormData();
      fd.append('action', 'save');
      fd.append('employeeid', $('#eId').val());
      fd.append('name', name);
      fd.append('contact', contact);
      fd.append('address', address);
      fd.append('salary', salary);
      fd.append('commissionpercent', commission);
      fd.append('grantorname', grName);
      fd.append('grantorcontact', grContact);
      fd.append('grantorrelationship', grRel);
      fd.append('removefile', $('#eRemoveFile').is(':checked') ? '1' : '0');
      if (file) { fd.append('identityfile', file); }

      var $btn = $('#btnSaveEmployee').prop('disabled', true);
      apiForm(fd).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#employeeModal').modal('hide');
          table.ajax.reload(null, false);
          return;
        }
        if (res.field && FIELDS[res.field]) {
          $(markInvalid(res.field, res.message)).trigger('focus');
        }
        toastr.error(res.message);
      }).always(function () { $btn.prop('disabled', false); });
    });

    /* ---------- Delete ---------- */
    var deleteId = 0;

    $('#employeesTable tbody').on('click', '.btn-del', function () {
      var d = rowData(this);
      deleteId = d.employeeid;
      $('#delName').text(d.name);
      $('#deleteModal').modal('show');
    });

    $('#btnConfirmDelete').on('click', function () {
      var $btn = $(this).prop('disabled', true);
      api({ action: 'delete', employeeid: deleteId }).done(function (res) {
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
</script>
</body>
</html>