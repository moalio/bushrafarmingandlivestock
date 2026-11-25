<?php

declare(strict_types=1);

/**
 * pages/companyprofile.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: Company Profile (sidebar > Settings > Company Profile).
 *
 * One form that edits the one company profile row (companyprofile, companyprofileid = 1): company name, phone,
 * email, address and logo. Two AJAX actions (POST, JSON): save (multipart FormData, optional logo upload) and
 * removelogo. The GET request runs ONE query (the profile row) and renders the form with the saved values; when the
 * row is missing the defaults are shown and the first save creates the row (upsert).
 *
 * Every write runs in one transaction together with its activitylogs row. Logo files are stored in uploads/company/
 * under a random server-made name; the type is read with finfo, never from the client. A replaced or removed logo is
 * deleted only after the commit, a new file is deleted when the transaction fails, and only paths that match
 * uploads/company/<16 hex>.(png|jpg|webp) and resolve inside that folder are ever deleted.
 *
 * Other pages are NOT changed: they keep their own constants and logo finder. The topbar logo stays the platform
 * file found by dt_find_logo(), it is not switched to the uploaded logo.
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call dt_require_permission for Settings > Company Profile (key to be confirmed) right after
 * the "NO LOGIN" marker in the request flow below, for the page and for both actions, and pass the logged-in user id
 * to activitylogs.userid.
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

const DT_COMPANY_NAME     = 'Bushra Farming and Livestock'; // default name when the profile row is missing
const DT_COMPANY_LOGO_DIR = 'uploads/company';              // folder of the uploaded company logo, relative to the site root
const DT_COMPANY_LOGO_RE  = '#^uploads/company/[0-9a-f]{16}\.(png|jpg|webp)$#'; // the only logofile values ever served or deleted
const DT_LOGO_MAX_BYTES   = 2097152;                        // 2 MB
const DT_LOGO_MAX_PIXELS  = 2000;                           // width and height limit

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
        'arrow-left' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        'arrow-up'   => '<line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/>',
    ];

    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
}

/* ================================================================
 * 3. DATA AND AJAX ACTION
 * ============================================================== */

/**
 * Read the one profile row.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed>|null The row, or null when it does not exist yet.
 */
function dt_read_profile(PDO $pdo): ?array
{
    $row = dt_q(
        $pdo,
        'SELECT companyname, phone, email, address, logofile FROM companyprofile WHERE companyprofileid = 1'
    )->fetch();

    return $row === false ? null : $row;
}

/**
 * URL of a stored logo for the pages folder, or an empty string when there is none or the stored value is not
 * a path this page could have written.
 *
 * @param mixed $logofile Value of companyprofile.logofile.
 *
 * @return string URL such as ../uploads/company/<file>, or ''.
 */
function dt_logo_url(mixed $logofile): string
{
    $rel = (string)$logofile;
    if ($rel === '' || preg_match(DT_COMPANY_LOGO_RE, $rel) !== 1) {
        return '';
    }

    return '../' . DT_COMPANY_LOGO_DIR . '/' . rawurlencode(basename($rel));
}

/**
 * Profile values as the page and the JSON answers show them (defaults when the row is missing).
 *
 * @param array<string,mixed>|null $row Row from dt_read_profile().
 *
 * @return array{name:string,phone:string,email:string,address:string,logo:string} Payload.
 */
function dt_profile_payload(?array $row): array
{
    return [
        'name'    => $row === null ? DT_COMPANY_NAME : (string)$row['companyname'],
        'phone'   => $row === null ? '' : (string)($row['phone'] ?? ''),
        'email'   => $row === null ? '' : (string)($row['email'] ?? ''),
        'address' => $row === null ? '' : (string)($row['address'] ?? ''),
        'logo'    => $row === null ? '' : dt_logo_url($row['logofile'] ?? ''),
    ];
}

/**
 * Delete a stored logo file, only when the path has the exact shape this page writes and resolves inside
 * uploads/company/. Anything else is never touched.
 *
 * @param string $rel Value of companyprofile.logofile (relative to the site root).
 *
 * @return void
 */
function dt_delete_logo_file(string $rel): void
{
    if (preg_match(DT_COMPANY_LOGO_RE, $rel) !== 1) {
        return;
    }
    $dir = realpath(__DIR__ . '/../' . DT_COMPANY_LOGO_DIR);
    $abs = realpath(__DIR__ . '/../' . $rel);
    if ($dir === false || $abs === false || !str_starts_with($abs, $dir . DIRECTORY_SEPARATOR) || !is_file($abs)) {
        return;
    }
    @unlink($abs);
}

/**
 * A trimmed text field of the request; control characters and invalid UTF-8 are rejected.
 *
 * @param string $key Field name.
 *
 * @return string Trimmed value ('' when absent).
 *
 * @throws DtFail When the value is not text or holds control characters.
 */
function dt_post_text(string $key): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        throw new DtFail('The form contains invalid data.');
    }
    $value = trim($value);
    if (preg_match('/[\x00-\x1F\x7F]/u', $value) !== 0) {
        throw new DtFail('The text contains invalid characters.');
    }

    return $value;
}

/**
 * Validate the text fields of the profile form.
 *
 * @return array{name:string,phone:string,email:string,address:string} Clean values ('' for an empty optional field).
 *
 * @throws DtFail On the first invalid field.
 */
function dt_validate_profile(): array
{
    $name    = dt_post_text('CompanyName');
    $phone   = dt_post_text('Phone');
    $email   = dt_post_text('Email');
    $address = dt_post_text('Address');

    if ($name === '') {
        throw new DtFail('Company name is required.');
    }
    if (mb_strlen($name) > 150) {
        throw new DtFail('Company name is too long (150 characters at most).');
    }
    if ($phone !== '' && (mb_strlen($phone) > 50 || preg_match('/^[0-9 +()\-]+$/', $phone) !== 1)) {
        throw new DtFail('Phone number is not valid.');
    }
    if ($email !== '' && (mb_strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
        throw new DtFail('Email address is not valid.');
    }
    if (mb_strlen($address) > 255) {
        throw new DtFail('Address is too long (255 characters at most).');
    }

    return ['name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address];
}

/**
 * Validate the uploaded logo, if one was sent. The type comes from finfo, never from the client.
 *
 * @return array{tmp:string,ext:string}|null Temporary file and extension, or null when no file was sent.
 *
 * @throws DtFail When the upload is not an acceptable image.
 */
function dt_validate_logo(): ?array
{
    $file = $_FILES['LogoFile'] ?? null;
    if (!is_array($file) || !isset($file['error'])) {
        return null;
    }
    if (is_array($file['error'])) {
        throw new DtFail('The logo could not be uploaded.');
    }
    $error = (int)$file['error'];
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new DtFail('The logo is too large (2 MB at most).');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new DtFail('The logo could not be uploaded.');
    }
    $size = filesize($tmp);
    if ($size === false || $size < 1 || $size > DT_LOGO_MAX_BYTES) {
        throw new DtFail('The logo is too large (2 MB at most).');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $ext  = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][(string)$mime] ?? '';
    if ($ext === '') {
        throw new DtFail('The logo must be a PNG, JPG or WebP image.');
    }

    $info = @getimagesize($tmp);
    if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] > DT_LOGO_MAX_PIXELS || $info[1] > DT_LOGO_MAX_PIXELS) {
        throw new DtFail('The logo is not a valid image (2000 x 2000 pixels at most).');
    }

    return ['tmp' => $tmp, 'ext' => $ext];
}

/**
 * Move a validated upload into uploads/company/ under a random name made by the server.
 *
 * @param array{tmp:string,ext:string} $logo Result of dt_validate_logo().
 *
 * @return string Path relative to the site root (uploads/company/<16 hex>.<ext>).
 *
 * @throws DtFail When the folder or the move fails.
 */
function dt_store_logo(array $logo): string
{
    $root = realpath(__DIR__ . '/..');
    if ($root === false) {
        throw new DtFail('The logo could not be uploaded.');
    }
    $dir = $root . '/' . DT_COMPANY_LOGO_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $real = realpath($dir);
    if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR)) {
        throw new DtFail('The logo could not be uploaded.');
    }

    $name = bin2hex(random_bytes(8)) . '.' . $logo['ext'];
    if (!@move_uploaded_file($logo['tmp'], $real . DIRECTORY_SEPARATOR . $name)) {
        throw new DtFail('The logo could not be uploaded.');
    }

    return DT_COMPANY_LOGO_DIR . '/' . $name;
}

/**
 * Save the profile (upsert), with an optional new logo.
 *
 * Validate everything, move the file, then one transaction: lock the old logo, upsert, activity row, commit. The old
 * file is deleted only after the commit; the new file is deleted when anything fails.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input.
 * @throws Throwable On a database or file-system failure (the new file is removed first).
 */
function dt_act_save(PDO $pdo): array
{
    $v    = dt_validate_profile();
    $logo = dt_validate_logo();
    $new  = $logo === null ? null : dt_store_logo($logo);

    try {
        $pdo->beginTransaction();

        $old = dt_q($pdo, 'SELECT logofile FROM companyprofile WHERE companyprofileid = 1 FOR UPDATE')->fetchColumn();
        $old = ($old === false || $old === null || $old === '') ? null : (string)$old;
        $kept = $new ?? $old;

        dt_q(
            $pdo,
            'INSERT INTO companyprofile (companyprofileid, companyname, phone, email, address, logofile)
             VALUES (1, :n, :p, :e, :a, :l)
             ON DUPLICATE KEY UPDATE companyname = VALUES(companyname), phone = VALUES(phone), email = VALUES(email),
                                     address = VALUES(address), logofile = VALUES(logofile)',
            [
                'n' => $v['name'],
                'p' => $v['phone'] === '' ? null : $v['phone'],
                'e' => $v['email'] === '' ? null : $v['email'],
                'a' => $v['address'] === '' ? null : $v['address'],
                'l' => $kept,
            ]
        );
        dt_q(
            $pdo,
            "INSERT INTO activitylogs (userid, invoicenumber, action, description, activitydate)
             VALUES (NULL, NULL, 'Update', :d, NOW())",
            ['d' => mb_substr('Company profile updated: ' . $v['name'], 0, 255)]
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($new !== null) {
            dt_delete_logo_file($new);
        }
        throw $e;
    }

    if ($new !== null && $old !== null && $old !== $new) {
        dt_delete_logo_file($old);
    }

    return [
        'success' => true,
        'message' => 'Company profile saved.',
        'profile' => dt_profile_payload([
            'companyname' => $v['name'], 'phone' => $v['phone'], 'email' => $v['email'],
            'address' => $v['address'], 'logofile' => $kept,
        ]),
    ];
}

/**
 * Remove the saved logo: clear the column, write the activity row, and delete the file after the commit.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When there is no logo to remove.
 * @throws Throwable On a database failure.
 */
function dt_act_remove_logo(PDO $pdo): array
{
    try {
        $pdo->beginTransaction();

        $old = dt_q($pdo, 'SELECT logofile FROM companyprofile WHERE companyprofileid = 1 FOR UPDATE')->fetchColumn();
        if ($old === false || $old === null || $old === '') {
            throw new DtFail('There is no logo to remove.');
        }

        dt_q($pdo, 'UPDATE companyprofile SET logofile = NULL WHERE companyprofileid = 1');
        dt_q(
            $pdo,
            "INSERT INTO activitylogs (userid, invoicenumber, action, description, activitydate)
             VALUES (NULL, NULL, 'Update', 'Company logo removed', NOW())"
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    dt_delete_logo_file((string)$old);

    return [
        'success' => true,
        'message' => 'Logo removed.',
        'profile' => dt_profile_payload(dt_read_profile($pdo)),
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

    $handlers = ['save' => 'dt_act_save', 'removelogo' => 'dt_act_remove_logo'];
    $action   = (string)($_POST['action'] ?? '');
    if (!isset($handlers[$action])) {
        dt_json(dt_fail('Unknown action.'), 400);
    }

    try {
        dt_json($handlers[$action](dt_pdo()));
    } catch (DtFail $e) {
        dt_json(dt_fail($e->getMessage()));
    } catch (PDOException $e) {
        dt_log_error('[companyprofile ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[companyprofile ' . $action . '] ' . dt_describe_throwable($e));
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

/* ===== Back link and form feedback ===== */
.back-link { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 10px; color: var(--brand-primary); font-size: 14px; font-weight: 500; }
.back-link:hover { color: var(--brand-primary-dark); text-decoration: underline; }
.req { color: var(--danger); }
.form-control.is-invalid { border-color: var(--danger); background-image: none; padding-right: 10px; }
.form-control.is-invalid:focus { box-shadow: 0 0 0 2px rgba(157, 29, 34, 0.3); }
.invalid-feedback { color: var(--danger); font-size: 13.5px; font-weight: 500; }
.form-text { color: var(--muted); font-size: 13.5px; }

/* ===== Logo preview ===== */
.logo-box {
    display: flex; align-items: center; justify-content: center; min-height: 90px; min-width: 200px; max-width: 320px;
    padding: 8px; border: 1px solid var(--line); background: #ffffff; color: var(--muted); font-size: 14px;
}
.logo-box img { display: block; max-height: 90px; max-width: 100%; }

/* ===== Toastr ===== */
#toast-container > div { border-radius: 0; opacity: .97; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18); font-family: inherit; font-size: 14px; }
#toast-container > div:hover { box-shadow: 0 8px 24px rgba(0, 0, 0, 0.28); opacity: 1; }
.toast-success { background-color: var(--brand-accent-dark); }
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

  <!-- CDN: Poppins, Bootstrap 4.6, Toastr -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
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
                    <a href="<?= dt_e($page) ?>" title="<?= dt_e($label) ?>"<?= $page === 'settings.php' ? ' class="active" aria-current="page"' : '' ?>>
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
 * Company profile card with the form.
 *
 * @param array{name:string,phone:string,email:string,address:string,logo:string} $profile Saved values.
 *
 * @return void
 */
function dt_view_company_profile(array $profile): void
{
    ?>
        <div class="row">
          <div class="col-12 mb-4">
            <div class="card" id="profileCard">
              <div class="card-body">
                <a class="back-link" href="settings.php" aria-label="Back to Settings"><?= dt_icon('arrow-left') ?> <span>Settings</span></a>
                <div class="card-head">
                  <h2 class="header-title">Company Profile</h2>
                </div>

                <form id="profileForm" novalidate autocomplete="off" enctype="multipart/form-data">
                  <div class="form-row">
                    <div class="form-group col-12 col-md-6">
                      <label for="CompanyName">Company Name <span class="req" aria-hidden="true">*</span></label>
                      <input type="text" class="form-control" id="CompanyName" name="CompanyName" maxlength="150" required
                             value="<?= dt_e($profile['name']) ?>" aria-describedby="CompanyNameFeedback">
                      <div class="invalid-feedback" id="CompanyNameFeedback"></div>
                    </div>
                    <div class="form-group col-12 col-md-3">
                      <label for="Phone">Phone</label>
                      <input type="text" class="form-control" id="Phone" name="Phone" maxlength="50"
                             value="<?= dt_e($profile['phone']) ?>" aria-describedby="PhoneFeedback">
                      <div class="invalid-feedback" id="PhoneFeedback"></div>
                    </div>
                    <div class="form-group col-12 col-md-3">
                      <label for="Email">Email</label>
                      <input type="email" class="form-control" id="Email" name="Email" maxlength="150"
                             value="<?= dt_e($profile['email']) ?>" aria-describedby="EmailFeedback">
                      <div class="invalid-feedback" id="EmailFeedback"></div>
                    </div>
                    <div class="form-group col-12">
                      <label for="Address">Address</label>
                      <input type="text" class="form-control" id="Address" name="Address" maxlength="255"
                             value="<?= dt_e($profile['address']) ?>" aria-describedby="AddressFeedback">
                      <div class="invalid-feedback" id="AddressFeedback"></div>
                    </div>
                    <div class="form-group col-12">
                      <label for="LogoFile">Logo (PNG, JPG or WebP, up to 2 MB)</label>
                      <div class="logo-box mb-2" id="logoBox"><?php if ($profile['logo'] !== ''): ?><img src="<?= dt_e($profile['logo']) ?>" alt="Company logo"><?php else: ?>No logo uploaded<?php endif; ?></div>
                      <input type="file" class="form-control-file" id="LogoFile" name="LogoFile" accept=".png,.jpg,.jpeg,.webp"
                             aria-describedby="LogoFeedback">
                      <div class="invalid-feedback" id="LogoFeedback"></div>
                      <button type="button" class="btn btn-secondary btn-sm mt-2" id="btnRemoveLogo"<?= $profile['logo'] === '' ? ' hidden' : '' ?>>Remove logo</button>
                    </div>
                  </div>

                  <button type="submit" class="btn btn-primary" id="btnSave">Save</button>
                </form>
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
 * When login returns, call dt_require_permission for Settings > Company Profile
 * (key to be confirmed) right here: it covers the page (GET) and both actions (POST), and pass the logged-in
 * user id to activitylogs.userid.
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

try {
    $profile = dt_profile_payload(dt_read_profile(dt_pdo())); // the ONE query of the GET request
} catch (Throwable $e) {
    dt_log_error('[companyprofile load] ' . dt_describe_throwable($e));
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Server error. Please try again.';
    exit;
}

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
    'csrfField' => $csrfField, 'docTitle' => 'Company Profile', 'heading' => 'Company Profile',
    'sub' => 'Manage the company name, logo and contact details.',
];

/* ---------- Render ---------- */

dt_render_open($page);
dt_view_company_profile($profile);
dt_render_close();

$cfg       = ['csrf' => $csrf, 'self' => 'companyprofile.php'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$n         = dt_n();

/* ================================================================
 * 7. JAVASCRIPT
 * ============================================================== */
?>

<a class="scroll-to-top" href="#main" aria-label="Back to top"><?= dt_icon('arrow-up') ?></a>

<!-- CDN: jQuery 3.5.1 + Bootstrap 4.6 bundle (includes Popper) + Toastr -->
<script nonce="<?= $n ?>" src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script nonce="<?= $n ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
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

    // POST helper: always sends CSRF, expects JSON; accepts a plain object or a FormData
    function api(data) {
      var opts = { url: URL_SELF, type: 'POST', dataType: 'json' };
      if (data instanceof FormData) {
        data.append('csrf_token', CSRF);
        opts.processData = false;
        opts.contentType = false;
      } else {
        data.csrf_token = CSRF;
      }
      opts.data = data;
      return $.ajax(opts)
        .fail(function (xhr) {
          var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Request failed. Please try again.';
          toastr.error(msg);
        });
    }
</script>

<!-- ===================== Page JavaScript ===================== -->
<script nonce="<?= $n ?>">
    var MAX_LOGO = 2 * 1024 * 1024;
    var LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
    var saving = false;
    var savedLogo = <?= json_encode($profile['logo'], $jsonFlags) ?>; // URL of the saved logo ('' when none)
    var BTN_SAVE = 'Save';

    /* ---------- Logo preview and Remove button ---------- */
    function showLogo(url) {
      var $box = $('#logoBox').empty();
      if (url) {
        $box.append($('<img>').attr({ src: url, alt: 'Company logo' }));
      } else {
        $box.text('No logo uploaded');
      }
    }
    function syncRemove() { $('#btnRemoveLogo').prop('hidden', !savedLogo); }

    /* ---------- Field errors (is-invalid + aria-invalid + feedback text) ---------- */
    function markInvalid(id, msg) {
      $('#' + id).addClass('is-invalid').attr('aria-invalid', 'true');
      $('#' + id + 'Feedback').text(msg);
    }
    function clearInvalid(id) {
      $('#' + id).removeClass('is-invalid').removeAttr('aria-invalid');
      $('#' + id + 'Feedback').text('');
    }
    function clearAll() {
      $.each(['CompanyName', 'Phone', 'Email', 'Address', 'LogoFile'], function (_, id) { clearInvalid(id); });
    }

    // Client checks of the chosen file: type and size ('' when fine)
    function fileProblem(file) {
      if (LOGO_TYPES.indexOf(file.type) === -1) { return 'The logo must be a PNG, JPG or WebP image.'; }
      if (file.size > MAX_LOGO) { return 'The logo is too large (2 MB at most).'; }
      return '';
    }

    /* ---------- Choosing a file: immediate preview, nothing uploaded yet ---------- */
    $('#LogoFile').on('change', function () {
      clearInvalid('LogoFile');
      var file = this.files && this.files[0];
      if (!file) { showLogo(savedLogo); return; }
      var problem = fileProblem(file);
      if (problem) {
        markInvalid('LogoFile', problem);
        toastr.error(problem);
        $(this).val('');
        showLogo(savedLogo);
        return;
      }
      var reader = new FileReader();
      reader.onload = function (ev) { showLogo(ev.target.result); };
      reader.readAsDataURL(file);
    });

    /* ---------- Client validation (first invalid field gets the focus) ---------- */
    function validForm() {
      clearAll();
      var first = '';
      var msg   = '';
      function fail(id, text) {
        markInvalid(id, text);
        if (!first) { first = id; msg = text; }
      }

      if ($.trim($('#CompanyName').val()) === '') { fail('CompanyName', 'Company name is required.'); }
      var email = $.trim($('#Email').val());
      if (email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { fail('Email', 'Email address is not valid.'); }
      var file = $('#LogoFile')[0].files && $('#LogoFile')[0].files[0];
      if (file) {
        var problem = fileProblem(file);
        if (problem) { fail('LogoFile', problem); }
      }

      if (first) {
        $('#' + first).trigger('focus');
        toastr.error(msg);
        return false;
      }
      return true;
    }

    /* ---------- Save ---------- */
    $('#profileForm').on('submit', function (e) {
      e.preventDefault();
      if (saving || !validForm()) { return; }

      var fd = new FormData();
      fd.append('action', 'save');
      fd.append('CompanyName', $.trim($('#CompanyName').val()));
      fd.append('Phone', $.trim($('#Phone').val()));
      fd.append('Email', $.trim($('#Email').val()));
      fd.append('Address', $.trim($('#Address').val()));
      var file = $('#LogoFile')[0].files && $('#LogoFile')[0].files[0];
      if (file) { fd.append('LogoFile', file); }

      saving = true;
      var $btn = $('#btnSave').prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm mr-1" role="status" aria-hidden="true"></span> ' + BTN_SAVE);

      api(fd).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#CompanyName').val(res.profile.name);
          $('#Phone').val(res.profile.phone);
          $('#Email').val(res.profile.email);
          $('#Address').val(res.profile.address);
          $('#LogoFile').val('');
          savedLogo = res.profile.logo;
          showLogo(savedLogo);
          syncRemove();
        } else {
          toastr.error(res.message);
        }
      }).always(function () {
        saving = false;
        $btn.prop('disabled', false).text(BTN_SAVE);
      });
    });

    /* ---------- Remove logo ---------- */
    $('#btnRemoveLogo').on('click', function () {
      if (saving || !window.confirm('Remove the company logo?')) { return; }
      saving = true;
      var $btn = $(this).prop('disabled', true);

      api({ action: 'removelogo' }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          savedLogo = '';
          $('#LogoFile').val('');
          showLogo('');
          syncRemove();
        } else {
          toastr.error(res.message);
        }
      }).always(function () {
        saving = false;
        $btn.prop('disabled', false);
      });
    });
</script>
</body>
</html>