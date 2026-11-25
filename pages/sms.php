<?php

declare(strict_types=1);

/**
 * pages/sms.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: SMS (sidebar > Settings > SMS), Hormuud only.
 *
 * One page, two tabs. "Messages": send an SMS to customers and review the sent batches (audit trail: no edit,
 * no delete). "Gateway": the one Hormuud gateway of the farm (table smsgateway, row smsgatewayid = 1).
 *
 * Seven AJAX actions (POST, JSON): sms_list, sms_customers, sms_view, sms_send, gateway_get, gateway_save,
 * gateway_delete. The GET request runs NO database query: everything loads through POST on DOM ready.
 *
 * The Hormuud password is encrypted at rest with AES-256-GCM (key = sha256(GATEWAY_KEY), stored as
 * base64(iv12 . tag16 . ciphertext)). It is only DECRYPTED inside the Hormuud send function, in memory. It never
 * leaves PHP: no answer, log line, note or form value holds it. The browser never calls a provider
 * (CSP connect-src stays 'self').
 *
 * The Hormuud API call itself is NOT implemented until its specification is supplied: dt_send_hormuud() only checks
 * the credentials and answers "Hormuud API is not configured" (counted as Failed).
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call dt_require_permission for Settings > SMS (key to be confirmed) right after the
 * "NO LOGIN" marker in the request flow below, for the page and for the seven actions, before the CSRF check, and
 * pass the logged-in user id to sentsms.userid and activitylogs.userid (NULL for now).
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

const DT_DEFAULT_COUNTRY_CODE = '252';     // Somalia: added to local 9-digit numbers
const DT_SMS_PROVIDER         = 'Hormuud'; // the only provider
const DT_SMS_MAX_LEN          = 600;       // maximum characters in one message
const DT_SMS_MAX_RECIPIENTS   = 100;       // maximum customers per send
const DT_MAX_USERNAME         = 150;
const DT_MAX_SECRET           = 255;

/** Business-rule failure: message is shown to the user (the transaction is rolled back by the caller). */
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
if (!function_exists('dt_transaction')) {
    /**
     * Run a callback inside one transaction: commit on success, roll back and rethrow on any failure.
     *
     * @param callable(PDO):mixed $fn Work to do; receives the connection.
     *
     * @return mixed Whatever the callback returns.
     *
     * @throws Throwable Whatever the callback or the commit throws (after the rollback).
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
if (!function_exists('dt_sms_log')) {
    /**
     * Write an activitylogs row (call inside the transaction, before the commit). The user is NULL until login returns.
     * Never pass a secret in $text.
     *
     * @param PDO    $pdo    Connection.
     * @param string $action Add | Update | Delete.
     * @param string $text   Description (cut to 255 characters).
     * @param string $ref    Reference, for example SMSGW-1 or SMS-12.
     *
     * @return void
     */
    function dt_sms_log(PDO $pdo, string $action, string $text, string $ref): void
    {
        dt_q(
            $pdo,
            'INSERT INTO activitylogs (userid, invoicenumber, action, description, activitydate)
             VALUES (NULL, :ref, :action, :text, NOW())',
            ['ref' => $ref, 'action' => $action, 'text' => mb_substr($text, 0, 255)]
        );
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

/**
 * A text field of the request, as posted (not trimmed). Arrays and invalid UTF-8 are rejected.
 *
 * @param string $key Field name.
 *
 * @return string Value ('' when absent).
 *
 * @throws DtFail When the value is not valid text.
 */
function dt_post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        throw new DtFail('The form contains invalid data.');
    }

    return $value;
}

/* ================================================================
 * 3. SECRETS (AES-256-GCM, base64(iv12 . tag16 . ciphertext))
 * ============================================================== */

/**
 * Is GATEWAY_KEY defined, at least 32 characters long and not a placeholder?
 *
 * @return bool True when secrets can be encrypted and decrypted.
 */
function dt_key_configured(): bool
{
    if (!defined('GATEWAY_KEY')) {
        return false;
    }
    $key = (string)GATEWAY_KEY;

    return strlen($key) >= 32 && !str_starts_with($key, 'REPLACE-WITH');
}

/**
 * 32-byte binary key derived from GATEWAY_KEY.
 *
 * @return string Raw key (sha256 of GATEWAY_KEY).
 *
 * @throws DtFail When the key is missing, too short or still a placeholder.
 */
function dt_gateway_key(): string
{
    if (!dt_key_configured()) {
        throw new DtFail('The gateway key is not configured.');
    }

    return hash('sha256', (string)GATEWAY_KEY, true);
}

/**
 * Encrypt a secret.
 *
 * @param string $plain Plain text.
 *
 * @return string base64( iv(12) . tag(16) . ciphertext ).
 *
 * @throws DtFail When the key is missing.
 * @throws RuntimeException When encryption fails.
 */
function dt_enc_secret(string $plain): string
{
    $key = dt_gateway_key();
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false || strlen($tag) !== 16) {
        throw new RuntimeException('Encryption failed');
    }

    return base64_encode($iv . $tag . $ct);
}

/**
 * Decrypt a stored secret.
 *
 * @param string $stored Stored value (base64).
 *
 * @return string Plain text, or '' on any failure (bad base64, short data, tag mismatch).
 *
 * @throws DtFail When the key is missing.
 */
function dt_dec_secret(string $stored): string
{
    $key = dt_gateway_key();
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }

    $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

    return $out === false ? '' : $out;
}

/* ================================================================
 * 4. SMS HELPERS (phone normalising, Hormuud send)
 * ============================================================== */

/**
 * Normalise a phone for sending. Keeps digits and one leading '+'. Without '+': a leading '00' is removed, else a
 * leading '0' is removed; a 9-digit number starting with 6 or 7 gets the default country code; the result gets '+'.
 *
 * @param string $raw Raw phone text.
 *
 * @return string Normalised phone ('' when it holds no digit).
 */
function dt_normalise_phone(string $raw): string
{
    $raw    = trim($raw);
    $plus   = str_starts_with($raw, '+');
    $digits = (string)preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return '';
    }

    if (!$plus) {
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 9 && ($digits[0] === '6' || $digits[0] === '7')) {
            $digits = DT_DEFAULT_COUNTRY_CODE . $digits;
        }
    }

    return '+' . $digits;
}

/**
 * A normalised phone is valid when it holds 6 to 15 digits.
 *
 * @param string $phone Normalised phone.
 *
 * @return bool True when valid.
 */
function dt_phone_valid(string $phone): bool
{
    $digits = strlen(ltrim($phone, '+'));

    return $digits >= 6 && $digits <= 15;
}

/**
 * One short single-line note (max 255 characters).
 *
 * @param string $text Raw text.
 *
 * @return string Clean note.
 */
function dt_short_note(string $text): string
{
    return mb_substr(trim((string)preg_replace('/\s+/', ' ', $text)), 0, 255);
}

/**
 * Send one SMS through Hormuud: intentionally not implemented until its API specification is supplied.
 * It only checks that the credentials can be read and always reports "not configured". The password is decrypted
 * in memory only and is never returned or logged.
 *
 * @param array<string,mixed> $gw   Gateway row (password still encrypted).
 * @param string              $to   Normalised phone.
 * @param string              $body Message text.
 *
 * @return array{0:bool,1:string} [ok, note].
 *
 * @throws DtFail When GATEWAY_KEY is not configured.
 */
function dt_send_hormuud(array $gw, string $to, string $body): array
{
    $user = (string)($gw['username'] ?? '');
    $pass = dt_dec_secret((string)($gw['password'] ?? ''));
    if ($user === '' || $pass === '' || $to === '' || $body === '') {
        return [false, 'Gateway credentials could not be read'];
    }

    return [false, 'Hormuud API is not configured'];
}

/* ================================================================
 * 5. DATA AND AJAX ACTIONS (one small function per action)
 * ============================================================== */

/**
 * Read the one gateway row.
 *
 * @param PDO  $pdo  Connection.
 * @param bool $lock True to lock the row (FOR UPDATE); only inside a transaction.
 *
 * @return array<string,mixed>|null Row (smsgatewayid, username, password still encrypted), or null when none.
 */
function dt_read_gateway(PDO $pdo, bool $lock = false): ?array
{
    $row = dt_q(
        $pdo,
        'SELECT smsgatewayid, username, password FROM smsgateway WHERE smsgatewayid = 1' . ($lock ? ' FOR UPDATE' : '')
    )->fetch();

    return is_array($row) ? $row : null;
}

/**
 * Gateway values as the page shows them. NEVER the secret, only whether one is stored.
 *
 * @param array<string,mixed>|null $row Row from dt_read_gateway().
 *
 * @return array<string,mixed> Payload fields.
 */
function dt_gateway_payload(?array $row): array
{
    return [
        'exists'         => $row !== null,
        'username'       => $row === null ? '' : (string)($row['username'] ?? ''),
        'passwordstored' => $row !== null && (string)($row['password'] ?? '') !== '',
        'keyconfigured'  => dt_key_configured(),
    ];
}

/**
 * List the sent batches, newest first.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_sms_list(PDO $pdo): array
{
    $rows = dt_q(
        $pdo,
        'SELECT s.smsid, s.message, s.recipientcount, s.successcount, s.failedcount, s.provider, s.sentat, u.name AS username
           FROM sentsms s
      LEFT JOIN users u ON u.userid = s.userid
       ORDER BY s.smsid DESC'
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'smsid'          => (int)$r['smsid'],
            'message'        => (string)($r['message'] ?? ''),
            'recipientcount' => (int)$r['recipientcount'],
            'successcount'   => (int)$r['successcount'],
            'failedcount'    => (int)$r['failedcount'],
            'provider'       => (string)($r['provider'] ?? ''),
            'username'       => $r['username'] !== null ? (string)$r['username'] : '',
            'sentat'         => (string)($r['sentat'] ?? ''),
        ];
    }

    return ['success' => true, 'data' => $out];
}

/**
 * Customers that have a phone number.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_sms_customers(PDO $pdo): array
{
    $rows = dt_q(
        $pdo,
        "SELECT customerid, name, primarycontact
           FROM customers
          WHERE primarycontact IS NOT NULL AND TRIM(primarycontact) <> ''
       ORDER BY name ASC, customerid ASC"
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'customerid'     => (int)$r['customerid'],
            'name'           => (string)($r['name'] ?? ''),
            'primarycontact' => (string)($r['primarycontact'] ?? ''),
        ];
    }

    return ['success' => true, 'data' => $out];
}

/**
 * One batch and its recipients (read only).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When the batch does not exist.
 */
function dt_act_sms_view(PDO $pdo): array
{
    $smsId = max(0, (int)($_POST['smsid'] ?? 0));

    $sms = dt_q(
        $pdo,
        'SELECT smsid, message, provider, recipientcount, successcount, failedcount, sentat
           FROM sentsms WHERE smsid = :id',
        ['id' => $smsId]
    )->fetch();
    if (!is_array($sms)) {
        throw new DtFail('SMS not found.');
    }

    $rows = dt_q(
        $pdo,
        'SELECT r.recipientid, c.name AS customername, r.phone, r.status, r.errornote
           FROM sentsmsrecipients r
      LEFT JOIN customers c ON c.customerid = r.customerid
          WHERE r.smsid = :id
       ORDER BY r.recipientid ASC',
        ['id' => $smsId]
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'recipientid'  => (int)$r['recipientid'],
            'customername' => $r['customername'] !== null ? (string)$r['customername'] : '',
            'phone'        => (string)($r['phone'] ?? ''),
            'status'       => (string)($r['status'] ?? ''),
            'errornote'    => $r['errornote'] !== null ? (string)$r['errornote'] : '',
        ];
    }

    return [
        'success' => true,
        'sms'     => [
            'smsid'          => (int)$sms['smsid'],
            'message'        => (string)($sms['message'] ?? ''),
            'provider'       => (string)($sms['provider'] ?? ''),
            'recipientcount' => (int)$sms['recipientcount'],
            'successcount'   => (int)$sms['successcount'],
            'failedcount'    => (int)$sms['failedcount'],
            'sentat'         => (string)($sms['sentat'] ?? ''),
        ],
        'rows'    => $out,
    ];
}

/**
 * Read and validate the posted send fields and load the gateway row (the password stays encrypted).
 *
 * @param PDO $pdo Connection.
 *
 * @return array{gateway:array<string,mixed>,message:string,ids:array<int,int>} Clean input.
 *
 * @throws DtFail On invalid input or when there is no gateway.
 */
function dt_read_send_input(PDO $pdo): array
{
    $gateway = dt_read_gateway($pdo);
    if ($gateway === null) {
        throw new DtFail('SMS gateway not found.');
    }

    $message = trim(dt_post_string('message'));
    if ($message === '') {
        throw new DtFail('Description is required.');
    }
    if (mb_strlen($message) > DT_SMS_MAX_LEN) {
        throw new DtFail('Description is too long.');
    }

    $raw = $_POST['ids'] ?? [];
    if (!is_array($raw)) {
        $raw = [];
    }
    $set = [];
    foreach ($raw as $value) {
        $id = is_scalar($value) ? (int)$value : 0;
        if ($id > 0) {
            $set[$id] = true;
        }
    }
    $ids = array_keys($set);
    if (!$ids) {
        throw new DtFail('Select at least one customer.');
    }
    if (count($ids) > DT_SMS_MAX_RECIPIENTS) {
        throw new DtFail('Too many recipients. Maximum is ' . DT_SMS_MAX_RECIPIENTS . ' per send.');
    }

    return ['gateway' => $gateway, 'message' => $message, 'ids' => $ids];
}

/**
 * Build the recipient list: customers that do not exist or have no phone are dropped, invalid phones stay as
 * Failed rows, duplicate valid phones are dropped.
 *
 * @param PDO            $pdo Connection.
 * @param array<int,int> $ids Unique positive customer ids.
 *
 * @return array<int,array{cid:int,phone:string,valid:bool}> Recipients.
 *
 * @throws DtFail When no valid phone number is found.
 */
function dt_build_recipients(PDO $pdo, array $ids): array
{
    $placeholders = [];
    $params       = [];
    foreach ($ids as $i => $customerId) {
        $placeholders[]   = ':c' . $i;
        $params['c' . $i] = $customerId;
    }

    $rows = dt_q(
        $pdo,
        "SELECT customerid, primarycontact
           FROM customers
          WHERE customerid IN (" . implode(',', $placeholders) . ")
            AND primarycontact IS NOT NULL AND TRIM(primarycontact) <> ''
       ORDER BY customerid ASC",
        $params
    )->fetchAll();

    $recipients = [];
    $seen       = [];
    foreach ($rows as $row) {
        $original = (string)$row['primarycontact'];
        $phone    = dt_normalise_phone($original);
        if (!dt_phone_valid($phone)) {
            $recipients[] = [
                'cid'   => (int)$row['customerid'],
                'phone' => mb_substr($phone !== '' ? $phone : trim($original), 0, 30),
                'valid' => false,
            ];
            continue;
        }
        if (isset($seen[$phone])) {
            continue;
        }
        $seen[$phone] = true;
        $recipients[] = ['cid' => (int)$row['customerid'], 'phone' => $phone, 'valid' => true];
    }

    if (!$seen) {
        throw new DtFail('No valid phone numbers found for the selected customers.');
    }

    return $recipients;
}

/**
 * Send a message to the selected customers (two short transactions around the sending loop).
 * The batch row is written BEFORE the loop so a crash, timeout or closed browser during a long send still leaves a
 * visible batch; the recipient rows are written AFTER the loop, in one transaction with the final counts and the
 * activity row, because only then are the results known and the batch is never half written.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a missing GATEWAY_KEY (before anything is saved).
 * @throws Throwable When phase B fails (logged with the batch number).
 */
function dt_act_sms_send(PDO $pdo): array
{
    set_time_limit(300);        // a batch must not be cut half-way
    ignore_user_abort(true);

    $in         = dt_read_send_input($pdo);
    $gateway    = $in['gateway'];
    $message    = $in['message'];
    $recipients = dt_build_recipients($pdo, $in['ids']);

    dt_gateway_key(); // fails early (before anything is saved) when the key is not configured

    $total = count($recipients);

    // Phase A: the batch row (final recipient count, counts filled in phase B)
    $smsId = (int)dt_transaction(static function (PDO $pdo) use ($message, $total): int {
        dt_q(
            $pdo,
            'INSERT INTO sentsms (message, provider, recipientcount, successcount, failedcount, userid, sentat)
             VALUES (:m, :p, :rc, 0, 0, NULL, NOW())',
            ['m' => $message, 'p' => DT_SMS_PROVIDER, 'rc' => $total]
        );

        return (int)$pdo->lastInsertId();
    });

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // a long batch must not lock the session
    }

    $sent   = 0;
    $failed = 0;
    $rows   = [];
    foreach ($recipients as $r) {
        if (!$r['valid']) {
            $failed++;
            $rows[] = [$r['cid'], $r['phone'], 'Failed', 'Invalid phone number'];
            continue;
        }

        [$ok, $note] = dt_send_hormuud($gateway, $r['phone'], $message);
        if ($ok) {
            $sent++;
            $rows[] = [$r['cid'], $r['phone'], 'Sent', null];
        } else {
            $failed++;
            $rows[] = [$r['cid'], $r['phone'], 'Failed', dt_short_note($note)];
        }
    }

    // Phase B: recipients, final counts and the activity row in ONE transaction
    try {
        dt_transaction(static function (PDO $pdo) use ($smsId, $rows, $sent, $failed, $total): void {
            $insert = $pdo->prepare(
                'INSERT INTO sentsmsrecipients (smsid, customerid, phone, status, errornote)
                 VALUES (:s, :c, :p, :st, :n)'
            );
            foreach ($rows as $row) {
                $insert->execute(['s' => $smsId, 'c' => $row[0], 'p' => $row[1], 'st' => $row[2], 'n' => $row[3]]);
            }

            dt_q(
                $pdo,
                'UPDATE sentsms SET successcount = :sc, failedcount = :fc WHERE smsid = :id',
                ['sc' => $sent, 'fc' => $failed, 'id' => $smsId]
            );

            dt_sms_log(
                $pdo,
                'Add',
                'SMS sent: ' . $total . ' recipient(s), ' . $sent . ' sent, ' . $failed . ' failed',
                'SMS-' . $smsId
            );
        });
    } catch (Throwable $e) {
        dt_log_error('[sms phase B failed, batch ' . $smsId . ': ' . $sent . ' sent, ' . $failed . ' failed] ' . dt_describe_throwable($e));
        throw $e; // the handler answers "Server error. Please try again." (500)
    }

    $text = 'SMS sent to ' . $sent . ' of ' . $total . ' recipient(s).';
    if ($failed > 0) {
        $text .= ' ' . $failed . ' failed. Open the SMS details to see why.';
    }

    return ['success' => true, 'message' => $text, 'failed' => $failed];
}

/**
 * Gateway state for the Gateway tab and the Send modal (never the secret).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_gateway_get(PDO $pdo): array
{
    return ['success' => true] + dt_gateway_payload(dt_read_gateway($pdo));
}

/**
 * Save the gateway (upsert, one transaction). A blank password keeps the stored (already encrypted) one.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a missing GATEWAY_KEY (nothing is saved).
 */
function dt_act_gateway_save(PDO $pdo): array
{
    $user     = trim(dt_post_string('username'));
    $password = dt_post_string('password'); // NOT trimmed

    if (mb_strlen($user) > DT_MAX_USERNAME || mb_strlen($password) > DT_MAX_SECRET) {
        throw new DtFail('One of the fields is too long.');
    }
    if ($user === '') {
        throw new DtFail('Username is required.');
    }
    dt_gateway_key(); // nothing is saved when the key is not configured

    $existed = (bool)dt_transaction(static function (PDO $pdo) use ($user, $password): bool {
        $row    = dt_read_gateway($pdo, true);
        $stored = $row === null ? '' : (string)($row['password'] ?? '');
        if ($password === '' && $stored === '') {
            throw new DtFail('Password is required.');
        }

        dt_q(
            $pdo,
            'INSERT INTO smsgateway (smsgatewayid, username, password)
             VALUES (1, :u, :p)
             ON DUPLICATE KEY UPDATE username = VALUES(username), password = VALUES(password)',
            ['u' => $user, 'p' => $password !== '' ? dt_enc_secret($password) : $stored]
        );
        dt_sms_log($pdo, $row === null ? 'Add' : 'Update', 'SMS gateway saved: ' . DT_SMS_PROVIDER, 'SMSGW-1');

        return $row !== null;
    });

    return ['success' => true, 'message' => $existed ? 'SMS gateway updated successfully.' : 'SMS gateway saved successfully.']
        + dt_gateway_payload(dt_read_gateway($pdo));
}

/**
 * Remove the gateway (one transaction).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When there is no gateway.
 */
function dt_act_gateway_delete(PDO $pdo): array
{
    dt_transaction(static function (PDO $pdo): void {
        if (dt_read_gateway($pdo, true) === null) {
            throw new DtFail('There is no SMS gateway to remove.');
        }
        dt_q($pdo, 'DELETE FROM smsgateway WHERE smsgatewayid = 1');
        dt_sms_log($pdo, 'Delete', 'SMS gateway removed', 'SMSGW-1');
    });

    return ['success' => true, 'message' => 'SMS gateway removed successfully.'] + dt_gateway_payload(null);
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
        'sms_list'       => 'dt_act_sms_list',
        'sms_customers'  => 'dt_act_sms_customers',
        'sms_view'       => 'dt_act_sms_view',
        'sms_send'       => 'dt_act_sms_send',
        'gateway_get'    => 'dt_act_gateway_get',
        'gateway_save'   => 'dt_act_gateway_save',
        'gateway_delete' => 'dt_act_gateway_delete',
    ];
    $action = (string)($_POST['action'] ?? '');
    if (!isset($handlers[$action])) {
        dt_json(dt_fail('Unknown action.'), 400);
    }

    try {
        dt_json($handlers[$action](dt_pdo()));
    } catch (DtFail $e) {
        dt_json(dt_fail($e->getMessage()));
    } catch (Throwable $e) {
        dt_log_error('[sms ' . $action . '] ' . dt_describe_throwable($e));
        dt_json(dt_fail('Server error. Please try again.'), 500);
    }
}

/* ================================================================
 * 6. HTML SHELL (same look as companyprofile.php): CSS, head, topbar, sidebar, footer
 * ============================================================== */

/**
 * Application stylesheet (shell tokens and components, then the rules of this page), loaded after the CDN styles.
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
    --warning: #9a5b00;
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
button, select, input, textarea { font-family: inherit; }
[hidden] { display: none !important; }
.ico { width: 18px; height: 18px; flex: none; display: inline-block; vertical-align: middle; }
:focus-visible { outline: 3px solid var(--brand-accent); outline-offset: 2px; }
.text-danger { color: var(--danger) !important; }
.text-muted { color: var(--muted) !important; }
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
.back-link { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 10px; color: var(--brand-primary); font-size: 14px; font-weight: 500; }
.back-link:hover { color: var(--brand-primary-dark); text-decoration: underline; }

/* ===== Buttons ===== */
.btn { border-radius: 0; font-size: 14px; font-weight: 500; }
.btn-sm { font-size: 13px; }
.btn-primary { background: var(--brand-primary); border-color: var(--brand-primary); color: #ffffff; }
.btn-primary:hover, .btn-primary:focus { background: var(--brand-primary-dark); border-color: var(--brand-primary-dark); color: #ffffff; box-shadow: none; }
.btn-primary:not(:disabled):not(.disabled):active { background: var(--brand-primary-dark); border-color: var(--brand-primary-dark); }
.btn-primary:disabled { background: var(--brand-primary); border-color: var(--brand-primary); color: #ffffff; opacity: .6; }
.btn-success { background: var(--brand-accent-dark); border-color: var(--brand-accent-dark); color: #ffffff; }
.btn-success:hover, .btn-success:focus { background: #0a5f2a; border-color: #0a5f2a; color: #ffffff; box-shadow: none; }
.btn-danger { background: var(--danger); border-color: var(--danger); color: #ffffff; }
.btn-danger:hover, .btn-danger:focus { background: var(--brand-primary-dark); border-color: var(--brand-primary-dark); color: #ffffff; box-shadow: none; }
.btn-secondary { background: #545b64; border-color: #545b64; color: #ffffff; }
.btn-secondary:hover, .btn-secondary:focus { background: #3f454c; border-color: #3f454c; color: #ffffff; box-shadow: none; }
.btn-light { background: #ffffff; border-color: var(--line); color: var(--heading); }
.btn-light:hover { background: var(--surface); color: var(--brand-primary); }
.btn:focus-visible { outline: 3px solid var(--brand-accent); outline-offset: 2px; box-shadow: none; }
.act-btn { padding: 2px 10px; font-size: 13px; }

/* ===== Forms ===== */
label { color: var(--heading); font-weight: 500; font-size: 14px; }
label .req { color: var(--danger); }
.form-control {
    padding: 6px 10px; font-size: 15px; color: var(--text); background: #ffffff;
    border: 1px solid var(--line); border-radius: 0;
}
.form-control:focus { border-color: var(--brand-accent); box-shadow: 0 0 0 2px rgba(22, 160, 74, 0.35); outline: 0; color: var(--text); }
.form-control.is-invalid { border-color: var(--danger); background-image: none; padding-right: 10px; }
.form-control.is-invalid:focus { box-shadow: 0 0 0 2px rgba(157, 29, 34, 0.3); }
.invalid-feedback { color: var(--danger); font-size: 13.5px; font-weight: 500; }
.status-line { color: var(--muted); font-size: 14px; }

/* ===== Tabs ===== */
.nav-tabs { border-bottom: 1px solid var(--line); }
.nav-tabs.compact-tabs { margin-top: 8px; }
.nav-tabs.compact-tabs .nav-link {
    padding: 6px 14px; font-size: 14px; font-weight: 600; border-radius: 0; border: 1px solid transparent;
    background: var(--surface); color: var(--brand-primary); margin-right: 2px;
}
.nav-tabs.compact-tabs .nav-link:hover { color: var(--brand-accent-dark); }
.nav-tabs.compact-tabs .nav-link.active { background: var(--brand-primary); color: #ffffff; border-color: var(--brand-primary); }

/* ===== Tables and DataTables ===== */
#smsTable, #recipTable { width: 100% !important; font-size: 14px; }
#smsTable thead th, #recipTable thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); white-space: nowrap; font-weight: 600; }
#smsTable td, #recipTable td { vertical-align: middle; }
.col-num { width: 60px; }
.col-ctl { width: 100px; }
.table-bordered, .table-bordered td, .table-bordered th { border-color: var(--line); }
.table-hover tbody tr:hover { background: rgba(22, 160, 74, 0.07); }
.dataTables_wrapper { font-size: 14px; }
.dataTables_wrapper .row { margin-left: 0; margin-right: 0; }
.dataTables_wrapper .row > [class*="col-"] { padding-left: 0; padding-right: 0; }
.dataTables_wrapper .dataTables_filter input, .dataTables_wrapper .dataTables_length select { height: 34px; border: 1px solid var(--line); border-radius: 0; padding: 0 8px; }
.dataTables_wrapper .dataTables_length select { width: auto; display: inline-block; }
.dataTables_wrapper .dataTables_filter input:focus, .dataTables_wrapper .dataTables_length select:focus { border-color: var(--brand-accent); outline: 0; box-shadow: 0 0 0 2px rgba(22, 160, 74, 0.35); }
.dt-buttons .btn { font-size: 13px; padding: 3px 10px; }
.page-item .page-link { color: var(--brand-primary); border-radius: 0; border-color: var(--line); font-size: 14px; }
.page-item.active .page-link { background: var(--brand-primary); border-color: var(--brand-primary); color: #ffffff; }
.page-item.disabled .page-link { color: var(--muted); }
.page-link:hover { color: var(--brand-accent-dark); background: var(--surface); }
table.dataTable thead .sorting:before, table.dataTable thead .sorting:after { opacity: .3; }

/* ===== Modals ===== */
.modal-content { border: 0; border-radius: 0; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25); }
.modal-header { background: var(--brand-primary); color: #ffffff; border-radius: 0; border-bottom: 0; padding: 10px 20px; }
.modal-header .modal-title { color: #ffffff; font-size: 16px; font-weight: 500; margin: 0; }
.modal-header .close { color: #ffffff; opacity: .9; text-shadow: none; }
.modal-header .close:hover { opacity: 1; color: #ffffff; }
.modal-footer { border-top: 1px solid var(--line); padding: 10px 20px; }
.msg-block { white-space: pre-wrap; word-break: break-word; background: #ffffff; border: 1px solid var(--line); padding: 10px 12px; }

/* ===== Select2 (multiple) ===== */
.select2-container { width: 100% !important; }
.select2-container--bootstrap4 .select2-selection--multiple { min-height: 38px; border-radius: 0; border: 1px solid var(--line); background: #ffffff; }
.select2-container--bootstrap4 .select2-selection--multiple .select2-selection__choice { background: var(--brand-primary); color: #ffffff; border: 0; border-radius: 0; font-size: 13px; padding: 0 8px; }
.select2-container--bootstrap4 .select2-selection--multiple .select2-selection__choice__remove { color: #ffffff; margin-right: 4px; }
.select2-container--bootstrap4 .select2-selection--multiple .select2-search__field { font-size: 14px; }
.select2-container--bootstrap4.select2-container--focus .select2-selection--multiple,
.select2-container--bootstrap4.select2-container--open .select2-selection--multiple { border-color: var(--brand-accent); box-shadow: 0 0 0 2px rgba(22, 160, 74, 0.35); }
.select2-container--bootstrap4 .select2-dropdown { border-radius: 0; border-color: var(--brand-accent); font-size: 14px; }
.select2-container--bootstrap4 .select2-results__option--highlighted[aria-selected] { background: var(--brand-primary); color: #ffffff; }
.select2-container--bootstrap4 .select2-results__option[aria-selected=true] { background: var(--surface); color: var(--heading); }
select.is-invalid + .select2-container--bootstrap4 .select2-selection { border-color: var(--danger); }

/* ===== Toastr ===== */
#toast-container > div { border-radius: 0; opacity: .97; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18); font-family: inherit; font-size: 14px; }
#toast-container > div:hover { box-shadow: 0 8px 24px rgba(0, 0, 0, 0.28); opacity: 1; }
.toast-success { background-color: var(--brand-accent-dark); }
.toast-error { background-color: var(--danger); }
.toast-warning { background-color: var(--warning); }
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

  <!-- CDN: Poppins, Bootstrap 4.6, DataTables + Buttons, Select2 4.0.13 (bootstrap4 theme), Toastr -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap4.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">
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
 * 7. PAGE MARKUP
 * ============================================================== */

/**
 * SMS card: back link, tabs, the Messages tab (sent table) and the Gateway tab (form).
 *
 * @return void
 */
function dt_view_sms(): void
{
    ?>
        <div class="row">
          <div class="col-12 mb-4">
            <div class="card">
              <div class="card-body">
                <a class="back-link" href="settings.php" aria-label="Back to Settings"><?= dt_icon('arrow-left') ?> <span>Settings</span></a>
                <div class="card-head">
                  <h2 class="header-title">SMS</h2>
                </div>

                <ul class="nav nav-tabs compact-tabs" id="mainTabs" role="tablist">
                  <li class="nav-item">
                    <a class="nav-link active" id="tab-messages" href="#messages" data-toggle="tab" role="tab" aria-controls="messages" aria-selected="true">Messages</a>
                  </li>
                  <li class="nav-item">
                    <a class="nav-link" id="tab-gateway" href="#gateway" data-toggle="tab" role="tab" aria-controls="gateway" aria-selected="false">Gateway</a>
                  </li>
                </ul>

                <div class="tab-content pt-3">
                  <div class="tab-pane fade show active" id="messages" role="tabpanel" aria-labelledby="tab-messages">
                    <div class="d-flex justify-content-end mb-2">
                      <button type="button" class="btn btn-primary btn-sm" id="btnSend">Send SMS</button>
                    </div>
                    <table id="smsTable" class="table table-hover table-bordered">
                      <thead>
                        <tr>
                          <th class="col-num">#</th>
                          <th>Description</th>
                          <th>Recipients</th>
                          <th>Sent</th>
                          <th>Failed</th>
                          <th>Provider</th>
                          <th>Sent By</th>
                          <th>Date</th>
                          <th class="col-ctl text-center">Control</th>
                        </tr>
                      </thead>
                      <tbody></tbody>
                    </table>
                  </div>

                  <div class="tab-pane fade" id="gateway" role="tabpanel" aria-labelledby="tab-gateway">
                    <form id="gatewayForm" novalidate autocomplete="off">
                      <div class="form-row">
                        <div class="form-group col-12 col-md-4">
                          <label for="gProvider">Provider</label>
                          <input type="text" class="form-control" id="gProvider" value="<?= dt_e(DT_SMS_PROVIDER) ?>" readonly>
                        </div>
                        <div class="form-group col-12 col-md-4">
                          <label for="gUser">Username <span class="req" aria-hidden="true">*</span></label>
                          <input type="text" class="form-control" id="gUser" maxlength="<?= DT_MAX_USERNAME ?>" autocomplete="off" aria-required="true" aria-describedby="gUserFeedback">
                          <div class="invalid-feedback" id="gUserFeedback">Username is required.</div>
                        </div>
                        <div class="form-group col-12 col-md-4">
                          <label for="gPassword">Password <span class="req" id="reqPassword" aria-hidden="true">*</span></label>
                          <input type="password" class="form-control" id="gPassword" maxlength="<?= DT_MAX_SECRET ?>" autocomplete="new-password" aria-required="true" aria-describedby="gPasswordFeedback">
                          <div class="invalid-feedback" id="gPasswordFeedback">Password is required.</div>
                        </div>
                      </div>

                      <p class="status-line mb-1" id="gwStatus" role="status"></p>
                      <p class="text-danger mb-2" id="gwKeyWarn" role="alert" hidden>The gateway key is not configured on the server.</p>

                      <button type="submit" class="btn btn-success btn-sm" id="btnSaveGw">Save</button>
                      <button type="button" class="btn btn-danger btn-sm" id="btnRemoveGw" hidden>Remove gateway</button>
                    </form>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php
}

/**
 * Send SMS modal.
 *
 * @return void
 */
function dt_modal_send(): void
{
    ?>
  <div class="modal fade" id="sendModal" tabindex="-1" role="dialog" aria-labelledby="sendModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form class="modal-content" id="sendForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="sendModalTitle">Send SMS</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <p class="mb-3" id="sendVia" role="status">
            <span id="sendViaText"></span>
            <span id="sendNoGw" class="text-danger" hidden>No gateway configured. Add one in the <a href="#gateway" id="gwLink" class="font-weight-bold">Gateway tab</a>.</span>
          </p>

          <div class="form-group">
            <label for="message">Description <span class="req" aria-hidden="true">*</span></label>
            <textarea class="form-control" id="message" rows="5" maxlength="<?= DT_SMS_MAX_LEN ?>" required aria-required="true" aria-describedby="messageFeedback"></textarea>
            <div class="invalid-feedback" id="messageFeedback">Description is required.</div>
            <small class="text-muted float-right" id="msgCount">0 / <?= DT_SMS_MAX_LEN ?></small>
          </div>

          <div class="form-group mb-0">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <label for="customers" class="mb-0">Customers <span class="req" aria-hidden="true">*</span></label>
              <div>
                <button type="button" class="btn btn-light btn-sm" id="btnSelectAll">Select all</button>
                <button type="button" class="btn btn-light btn-sm" id="btnClearAll">Clear</button>
              </div>
            </div>
            <select class="form-control" id="customers" multiple aria-required="true" aria-describedby="custErr"></select>
            <div class="text-danger small d-none" id="custErr" role="alert">Select at least one customer.</div>
            <small class="text-muted">Only customers with a phone number are listed.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success btn-sm" id="btnDoSend">Send</button>
          <button type="button" class="btn btn-danger btn-sm" id="btnSendCancel" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * View batch modal (read only).
 *
 * @return void
 */
function dt_modal_view(): void
{
    ?>
  <div class="modal fade" id="viewModal" tabindex="-1" role="dialog" aria-labelledby="viewModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="viewModalTitle">SMS</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="msg-block mb-2" id="viewMessage"></div>
          <div class="mb-2" id="viewSummary"></div>
          <table id="recipTable" class="table table-sm table-bordered table-hover mb-0">
            <thead>
              <tr>
                <th class="col-num">#</th>
                <th>Customer</th>
                <th>Phone</th>
                <th>Status</th>
                <th>Note</th>
              </tr>
            </thead>
            <tbody id="recipBody"></tbody>
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

/* ================================================================
 * 8. REQUEST FLOW
 * ============================================================== */

/* ================================================================
 * NO LOGIN: nothing is checked here for now (no session, no permission).
 * When login returns, call dt_require_permission for Settings > SMS (key to be confirmed) right here: it covers
 * the page (GET) and the seven actions (POST) and must run before the CSRF check; pass the logged-in user id to
 * sentsms.userid and activitylogs.userid.
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

/* ---------- GET: NO database query; collect the page values, then release the session ---------- */

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
 * Sidebar: key, label, icon, page, submenu links (label, page). Same menu as pages/companyprofile.php.
 * Every group stays collapsed; only the Settings link is active.
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
    'csrfField' => $csrfField, 'docTitle' => 'SMS', 'heading' => 'SMS',
    'sub' => 'Manage the SMS gateway and send or review SMS messages.',
];

/* ---------- Render ---------- */

dt_render_open($page);
dt_view_sms();
dt_render_close();
dt_modal_send();
dt_modal_view();

$cfg = [
    'csrf' => $csrf, 'self' => 'sms.php', 'provider' => DT_SMS_PROVIDER,
    'maxLen' => DT_SMS_MAX_LEN, 'maxRecipients' => DT_SMS_MAX_RECIPIENTS,
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$n         = dt_n();

/* ================================================================
 * 9. JAVASCRIPT
 * ============================================================== */
?>

<a class="scroll-to-top" href="#main" aria-label="Back to top"><?= dt_icon('arrow-up') ?></a>

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
<!-- CDN: Select2 + Toastr -->
<script nonce="<?= $n ?>" src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>
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
    // Escape for use inside a double-quoted HTML attribute.
    function escAttr(s) {
      return esc(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // 'YYYY-MM-DD HH:MM:SS' -> 'YYYY-MM-DD hh:mm:ssam/pm' (display only)
    function fmtDate(s) {
      var m = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2}):(\d{2})/.exec(s || '');
      if (!m) { return s || ''; }
      var hh  = parseInt(m[2], 10);
      var ap  = hh >= 12 ? 'pm' : 'am';
      var h12 = hh % 12;
      if (h12 === 0) { h12 = 12; }
      return m[1] + ' ' + ('0' + h12).slice(-2) + ':' + m[3] + ':' + m[4] + ap;
    }

    function textCol(name) {
      return { data: name, render: function (d, type) { return type === 'display' ? esc(d) : d; } };
    }

    function markInvalid($el) { $el.addClass('is-invalid').attr('aria-invalid', 'true'); }
    function clearInvalid($el) { $el.removeClass('is-invalid').removeAttr('aria-invalid'); }

    /* ---------- Sent SMS table ---------- */
    var EXPORT_OPTS = { columns: [0, 1, 2, 3, 4, 5, 6, 7], orthogonal: 'export' };

    var table = $('#smsTable').DataTable({
      ajax: {
        url: URL_SELF,
        type: 'POST',
        data: function (d) { d.action = 'sms_list'; d.csrf_token = CSRF; },
        dataSrc: 'data',
        error: function () { toastr.error('Could not load SMS.'); }
      },
      autoWidth: false,
      columns: [
        { data: 'smsid' },
        {
          data: 'message',
          render: function (d, type) {
            var s = (d == null) ? '' : String(d);
            if (type !== 'display') { return s; }   // sort, search and export use the full text
            var short = s.length > 80 ? s.substring(0, 80) + '...' : s;
            return '<span title="' + escAttr(s) + '">' + esc(short) + '</span>';
          }
        },
        { data: 'recipientcount' },
        { data: 'successcount' },
        { data: 'failedcount' },
        textCol('provider'),
        {
          data: 'username',
          render: function (d, type) {
            var v = d || '-';
            return type === 'display' ? esc(v) : v;
          }
        },
        { data: 'sentat', render: function (d, type) { return type === 'display' ? esc(fmtDate(d)) : d; } },
        {
          data: null, orderable: false, searchable: false, className: 'text-center',
          render: function () {
            return '<button type="button" class="btn btn-primary act-btn btn-view" title="View" aria-label="View SMS details">View</button>';
          }
        }
      ],
      order: [[0, 'desc']],
      pageLength: 10,
      lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
      dom: "<'row mb-2'<'col-md-4'l><'col-md-4 text-center'B><'col-md-4'f>>" +
           "<'table-scroll'tr>" +
           "<'row mt-2'<'col-md-5'i><'col-md-7'p>>",
      buttons: [
        { extend: 'copyHtml5',  className: 'btn btn-secondary btn-sm', text: 'Copy',  exportOptions: EXPORT_OPTS },
        { extend: 'csvHtml5',   className: 'btn btn-secondary btn-sm', text: 'CSV',   exportOptions: EXPORT_OPTS },
        { extend: 'excelHtml5', className: 'btn btn-secondary btn-sm', text: 'Excel', title: 'Sent SMS', exportOptions: EXPORT_OPTS },
        { extend: 'pdfHtml5',   className: 'btn btn-secondary btn-sm', text: 'PDF',   title: 'Sent SMS', orientation: 'landscape', exportOptions: EXPORT_OPTS },
        { extend: 'print',      className: 'btn btn-secondary btn-sm', text: 'Print', title: 'Sent SMS', exportOptions: EXPORT_OPTS }
      ],
      language: { search: 'Search:', searchPlaceholder: 'Search SMS...', emptyTable: 'No SMS found', zeroRecords: 'No SMS found' }
    });

    /* ---------- Tabs: kept in location.hash, widths fixed when shown, secrets cleared when Gateway is hidden ---------- */
    $('#mainTabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
      var hash = $(e.target).attr('href');
      if (window.history && history.replaceState) { history.replaceState(null, '', hash); }
      $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    });
    $('#mainTabs a[data-toggle="tab"]').on('hidden.bs.tab', function (e) {
      if ($(e.target).attr('href') === '#gateway') { clearSecrets(); }
    });
    if (window.location.hash === '#gateway') { $('#tab-gateway').tab('show'); }

    /* =============================================================
     * Gateway tab
     * ============================================================= */
    var KEEP_PH = 'Leave empty to keep the current value';
    var gw      = { exists: false, username: '', passwordstored: false, keyconfigured: true };
    var gwBusy  = false;

    function clearSecrets() { $('#gPassword').val(''); }

    // Fetch the gateway state (never holds a secret) into gw
    function fetchGateway() {
      return api({ action: 'gateway_get' }).done(function (res) {
        if (res.success) { gw = res; }
      });
    }

    // Show gw in the form: secrets are never put in the form
    function fillGatewayForm() {
      clearInvalid($('#gUser, #gPassword'));
      $('#gUser').val(gw.username);
      clearSecrets();
      $('#gPassword').attr('placeholder', gw.passwordstored ? KEEP_PH : '');
      $('#reqPassword').prop('hidden', gw.passwordstored);
      $('#gPassword').attr('aria-required', gw.passwordstored ? 'false' : 'true');
      $('#gwStatus').text(gw.exists ? 'Gateway saved: ' + CFG.provider : 'No gateway saved yet');
      $('#gwKeyWarn').prop('hidden', gw.keyconfigured);
      $('#btnRemoveGw').prop('hidden', !gw.exists);
    }

    $('#gUser, #gPassword').on('input', function () { clearInvalid($(this)); });

    $('#gatewayForm').on('submit', function (e) {
      e.preventDefault();
      if (gwBusy) { return; }

      var user = $.trim($('#gUser').val());
      var pw   = $('#gPassword').val();

      clearInvalid($('#gUser, #gPassword'));
      var bad = [];
      if (user === '') { bad.push('#gUser'); }
      if (pw === '' && !gw.passwordstored) { bad.push('#gPassword'); }
      if (bad.length) {
        markInvalid($(bad.join(',')));
        $(bad[0]).trigger('focus');
        return;
      }

      gwBusy = true;
      var $btn = $('#btnSaveGw').prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm mr-1" role="status" aria-hidden="true"></span> Save');

      api({ action: 'gateway_save', username: user, password: pw }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          gw = res;
          fillGatewayForm();
        } else {
          toastr.error(res.message);
        }
      }).always(function () {
        clearSecrets();
        gwBusy = false;
        $btn.prop('disabled', false).text('Save');
      });
    });

    $('#btnRemoveGw').on('click', function () {
      if (gwBusy || !window.confirm('Remove the SMS gateway? Messages cannot be sent until a new one is saved.')) { return; }
      gwBusy = true;
      var $btn = $(this).prop('disabled', true);

      api({ action: 'gateway_delete' }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          gw = res;
          fillGatewayForm();
        } else {
          toastr.error(res.message);
        }
      }).always(function () {
        gwBusy = false;
        $btn.prop('disabled', false);
      });
    });

    /* =============================================================
     * View modal (read-only audit of one batch)
     * ============================================================= */
    var viewReq = 0;
    var NO_RECIPIENTS = '<tr><td colspan="5" class="text-center text-muted">No recipients found.</td></tr>';

    $('#smsTable tbody').on('click', '.btn-view', function () {
      var d   = table.row($(this).closest('tr')).data();
      var req = ++viewReq;
      $('#viewModalTitle').text('SMS #' + d.smsid);
      $('#viewMessage').text(d.message || '');
      $('#viewSummary').text('');
      $('#recipBody').html('<tr><td colspan="5" class="text-center text-muted">Loading...</td></tr>');
      $('#viewModal').modal('show');

      api({ action: 'sms_view', smsid: d.smsid }).done(function (res) {
        if (req !== viewReq) { return; }   // a late answer of an earlier click is ignored
        if (!res.success) {
          $('#recipBody').html(NO_RECIPIENTS);
          toastr.error(res.message);
          return;
        }
        $('#viewMessage').text(res.sms.message || '');
        $('#viewSummary').text('Provider: ' + res.sms.provider + ' | Sent: ' + res.sms.successcount + ' | Failed: ' + res.sms.failedcount);

        if (!res.rows.length) {
          $('#recipBody').html(NO_RECIPIENTS);
          return;
        }
        var html = '';
        $.each(res.rows, function (i, r) {
          var cls = (r.status === 'Failed') ? ' class="text-danger"' : '';
          html += '<tr>' +
            '<td>' + (i + 1) + '</td>' +
            '<td>' + esc(r.customername || '-') + '</td>' +
            '<td>' + esc(r.phone) + '</td>' +
            '<td' + cls + '>' + esc(r.status) + '</td>' +
            '<td' + cls + '>' + esc(r.errornote || '') + '</td>' +
            '</tr>';
        });
        $('#recipBody').html(html);
      }).fail(function () {
        if (req === viewReq) { $('#recipBody').html(NO_RECIPIENTS); }
      });
    });

    /* =============================================================
     * Send modal
     * ============================================================= */
    var sending   = false;
    var gatewayOk = false;
    var CUST_ERR  = 'Select at least one customer.';

    $('#customers').select2({
      theme: 'bootstrap4',
      width: '100%',
      dropdownParent: $('#sendModal'),
      closeOnSelect: false,
      placeholder: 'Select customers'
    });

    function clearSendInvalid() {
      clearInvalid($('#message, #customers'));
      $('#custErr').text(CUST_ERR).addClass('d-none');
    }

    function setSendBtn(busy) {
      var $b = $('#btnDoSend');
      if (busy) {
        $b.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-1" role="status" aria-hidden="true"></span> Sending...');
      } else {
        $b.prop('disabled', !gatewayOk).text('Send');
      }
      $('#btnSendCancel').prop('disabled', busy);
    }

    function updateCount() {
      $('#msgCount').text($('#message').val().length + ' / ' + CFG.maxLen);
    }

    // "Sending through: Hormuud" or the "No gateway configured" line with the link to the Gateway tab
    function showSendVia() {
      gatewayOk = !!gw.exists;
      $('#sendViaText').text(gatewayOk ? 'Sending through: ' + CFG.provider : '');
      $('#sendNoGw').prop('hidden', gatewayOk);
      setSendBtn(false);
    }

    $('#message').on('input', function () { clearInvalid($(this)); updateCount(); });
    $('#customers').on('change', function () {
      clearInvalid($(this));
      $('#custErr').addClass('d-none');
    });

    $('#btnSelectAll').on('click', function () {
      var all = $('#customers option').map(function () { return this.value; }).get();
      $('#customers').val(all).trigger('change');
    });
    $('#btnClearAll').on('click', function () {
      $('#customers').val(null).trigger('change');
    });

    // Customers multi-select: option text built with .text() so it is escaped
    function loadCustomers() {
      return api({ action: 'sms_customers' }).done(function (res) {
        var $c = $('#customers').empty();
        if (res.success) {
          $.each(res.data, function (_, c) {
            $c.append($('<option>').val(c.customerid).text(c.name + ' | ' + c.primarycontact));
          });
        }
        $c.val(null).trigger('change');
      });
    }

    $('#btnSend').on('click', function () {
      $('#sendForm')[0].reset();
      clearSendInvalid();
      updateCount();
      gatewayOk = false;
      $('#sendViaText').text('Checking the gateway...');
      $('#sendNoGw').prop('hidden', true);
      setSendBtn(false);
      $('#customers').val(null).trigger('change');
      fetchGateway().done(showSendVia).fail(function () {
        $('#sendViaText').text('Could not check the gateway.');
      });
      loadCustomers();
      $('#sendModal').modal('show');
    });

    // The link in the "No gateway configured" line switches to the Gateway tab
    $('#gwLink').on('click', function (e) {
      e.preventDefault();
      if (sending) { return; }
      $('#sendModal').modal('hide');
      $('#tab-gateway').tab('show');
    });

    // The modal cannot be closed (backdrop, Esc, X, Cancel) while a request is running
    $('#sendModal').on('hide.bs.modal', function (e) {
      if (sending) { e.preventDefault(); }
    });
    $('#sendModal').on('shown.bs.modal', function () { $('#message').trigger('focus'); });

    $('#sendForm').on('submit', function (e) {
      e.preventDefault();
      if (sending) { return; }

      var msg   = $.trim($('#message').val());
      var custs = $('#customers').val() || [];

      clearSendInvalid();
      var firstBad = null;
      function flag(fn) { if (!firstBad) { firstBad = fn; } }

      if (!gatewayOk) {
        toastr.error('SMS gateway not found.');
        flag(function () { $('#gwLink').trigger('focus'); });
      }
      if (msg === '' || msg.length > CFG.maxLen) {
        markInvalid($('#message'));
        flag(function () { $('#message').trigger('focus'); });
      }
      if (!custs.length) {
        markInvalid($('#customers'));
        $('#custErr').text(CUST_ERR).removeClass('d-none');
        flag(function () { $('#customers').select2('open'); });
      } else if (custs.length > CFG.maxRecipients) {
        markInvalid($('#customers'));
        $('#custErr').text('Too many recipients. Maximum is ' + CFG.maxRecipients + ' per send.').removeClass('d-none');
        flag(function () { $('#customers').select2('open'); });
      }
      if (firstBad) { firstBad(); return; }

      sending = true;
      setSendBtn(true);
      api({ action: 'sms_send', message: msg, ids: custs }).done(function (res) {
        if (res.success) {
          if (res.failed > 0) { toastr.warning(res.message); }
          else { toastr.success(res.message); }
          sending = false;
          $('#sendModal').modal('hide');
          $('#sendForm')[0].reset();
          $('#customers').val(null).trigger('change');
          updateCount();
          table.ajax.reload(null, false);
        } else {
          toastr.error(res.message);
        }
      }).always(function () {
        sending = false;
        setSendBtn(false);
      });
    });

    /* ---------- On DOM ready: the gateway state (the sent table loads itself) ---------- */
    $(function () {
      fetchGateway().done(fillGatewayForm);
    });
</script>
</body>
</html>