<?php

declare(strict_types=1);

/**
 * pages/assets.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: Assets (sidebar > Accounting > Assets).
 *
 * Fixed assets and supplies of the farm (tractors, tools, feed stock...) and the adjustments that lower their value:
 *   Depreciation       -> Equipment assets
 *   Supply Adjustment  -> Supply assets
 * Remaining value = assets.amount - SUM(assetadjustments.amount), never stored, never below 0, compared in integer cents.
 * This page NEVER touches the accounts table or any cash / bank balance. It writes ONLY to assets, assetadjustments and activitylogs.
 *
 * One page, three tabs, POST (AJAX, JSON) actions: list, asset_options, save_asset, delete_asset,
 * save_adjustment, delete_adjustment. Lock order is always: the asset row first, then the adjustment row.
 * Same shell as pages/shareholders.php.
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call the auth helpers of ../db.php right after the
 * "NO LOGIN" marker in the request flow below (and pass the user id to dt_log_asset()).
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
const DT_MAX_DESC   = 255;
const DT_MAX_AMOUNT = 1000000000;
const DT_MAX_PAGE   = 1000;

/** Adjustment type => asset type it may be applied to. */
const DT_ADJ_TYPES = ['Depreciation' => 'Equipment', 'Supply Adjustment' => 'Supply'];

/** Asset types. */
const DT_ASSET_TYPES = ['Equipment', 'Supply'];

const DT_LOGO_FILE = 'bushrofarmingandlivestocklogo.png'; // preferred logo file name
const DT_LOGO_DIRS = ['uploads', 'assets', 'images'];     // folders next to pages/ searched for the logo (in this order)

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
if (!function_exists('dt_valid_date')) {
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
}
if (!function_exists('dt_money')) {
    /**
     * Format an amount: 2 decimals, thousands separator, never "-0.00", no currency symbol.
     *
     * @param mixed $value Amount.
     *
     * @return string e.g. "1,234.50".
     */
    function dt_money(mixed $value): string
    {
        $value = round((float)$value, 2);
        if ($value == 0) {
            $value = 0.0;
        }

        return number_format($value, 2, '.', ',');
    }
}
if (!function_exists('dt_f2')) {
    /**
     * Plain 2-decimal string for database writes.
     *
     * @param float $value Amount.
     *
     * @return string e.g. "120.75".
     */
    function dt_f2(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
if (!function_exists('dt_cents')) {
    /**
     * Amount as integer cents (all comparisons of money on this page are made in cents).
     *
     * @param mixed $value Amount.
     *
     * @return int Cents.
     */
    function dt_cents(mixed $value): int
    {
        return (int)round(((float)$value) * 100);
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
 * 3. SHARED BUSINESS RULES (read and validate, load and lock, adjustment total, log)
 * ============================================================== */

/**
 * Adjustment name used in messages.
 *
 * @param string $type Adjustment type.
 *
 * @return string "Depreciation" or "Supply adjustment".
 */
function dt_adjustment_label(string $type): string
{
    return $type === 'Depreciation' ? 'Depreciation' : 'Supply adjustment';
}

/**
 * Validate an amount posted as text (shared by assets and adjustments).
 *
 * @param mixed $raw Raw posted value.
 *
 * @return float Amount rounded to 2 decimals.
 *
 * @throws DtFail On invalid input (field: amount).
 */
function dt_read_amount(mixed $raw): float
{
    $raw = trim((string)$raw);
    if ($raw === '' || !is_numeric($raw) || !is_finite((float)$raw)) {
        throw new DtFail('Amount is required.', false, 'amount');
    }
    $amount = round((float)$raw, 2);
    if ($amount <= 0) {
        throw new DtFail('Amount must be greater than 0.', false, 'amount');
    }
    if ($amount >= DT_MAX_AMOUNT) {
        throw new DtFail('Amount is too large.', false, 'amount');
    }

    return $amount;
}

/**
 * Read and validate the posted asset (used by save_asset, before the transaction).
 *
 * @return array{id:int,name:string,type:string,date:string,amount:float} Clean fields.
 *
 * @throws DtFail On invalid input.
 */
function dt_read_asset_input(): array
{
    $id   = max(0, (int)($_POST['assetid'] ?? 0));
    $name = trim((string)($_POST['name'] ?? ''));
    $type = trim((string)($_POST['assettype'] ?? ''));
    $date = trim((string)($_POST['assetdate'] ?? ''));

    if ($name === '') {
        throw new DtFail('Asset name is required.', false, 'name');
    }
    if (mb_strlen($name) > DT_MAX_NAME) {
        throw new DtFail('The asset name is too long (maximum ' . DT_MAX_NAME . ' characters).', false, 'name');
    }
    if (!in_array($type, DT_ASSET_TYPES, true)) {
        throw new DtFail('Select a valid type.', false, 'assettype');
    }
    if (!dt_valid_date($date)) {
        throw new DtFail('A valid date is required.', false, 'assetdate');
    }
    $amount = dt_read_amount($_POST['amount'] ?? '');

    return ['id' => $id, 'name' => $name, 'type' => $type, 'date' => $date, 'amount' => $amount];
}

/**
 * Read and validate the posted adjustment (used by save_adjustment, before the transaction).
 *
 * @return array{id:int,type:string,asset:int,amount:float,desc:string,date:string} Clean fields.
 *
 * @throws DtFail On invalid input.
 */
function dt_read_adjustment_input(): array
{
    $id    = max(0, (int)($_POST['adjustmentid'] ?? 0));
    $type  = trim((string)($_POST['adjustmenttype'] ?? ''));
    $asset = (int)($_POST['assetid'] ?? 0);
    $desc  = trim((string)($_POST['description'] ?? ''));
    $date  = trim((string)($_POST['adjustmentdate'] ?? ''));

    if (!isset(DT_ADJ_TYPES[$type])) {
        throw new DtFail('Invalid adjustment type.');
    }
    if ($asset <= 0) {
        throw new DtFail('Select a valid asset.', false, 'assetid');
    }
    $amount = dt_read_amount($_POST['amount'] ?? '');
    if ($desc === '') {
        throw new DtFail('Description is required.', false, 'description');
    }
    if (mb_strlen($desc) > DT_MAX_DESC) {
        throw new DtFail('The description is too long (maximum ' . DT_MAX_DESC . ' characters).', false, 'description');
    }
    if (!dt_valid_date($date)) {
        throw new DtFail('A valid date is required.', false, 'adjustmentdate');
    }

    return ['id' => $id, 'type' => $type, 'asset' => $asset, 'amount' => $amount, 'desc' => $desc, 'date' => $date];
}

/**
 * Load and lock (FOR UPDATE) an asset. Call inside a transaction, always BEFORE locking an adjustment and before reading an adjustment total.
 *
 * @param PDO $pdo     Connection.
 * @param int $assetId Asset id.
 *
 * @return array<string,mixed>|null Row (assetid, name, assettype, amount, assetdate) or null when not found.
 */
function dt_lock_asset(PDO $pdo, int $assetId): ?array
{
    $row = dt_q(
        $pdo,
        'SELECT assetid, name, assettype, amount, assetdate FROM assets WHERE assetid = :id FOR UPDATE',
        ['id' => $assetId]
    )->fetch();

    return is_array($row) ? $row : null;
}

/**
 * Load and lock (FOR UPDATE) an adjustment. Call inside a transaction, AFTER its asset is locked.
 *
 * @param PDO $pdo          Connection.
 * @param int $adjustmentId Adjustment id.
 *
 * @return array<string,mixed>|null Row (adjustmentid, assetid, adjustmenttype, amount) or null when not found.
 */
function dt_lock_adjustment(PDO $pdo, int $adjustmentId): ?array
{
    $row = dt_q(
        $pdo,
        'SELECT adjustmentid, assetid, adjustmenttype, amount FROM assetadjustments WHERE adjustmentid = :id FOR UPDATE',
        ['id' => $adjustmentId]
    )->fetch();

    return is_array($row) ? $row : null;
}

/**
 * Total of the adjustments already made on an asset, in integer cents (optionally leaving one adjustment out).
 *
 * @param PDO $pdo       Connection.
 * @param int $assetId   Asset id.
 * @param int $excludeId Adjustment left out of the sum (0 = none).
 *
 * @return int Cents.
 */
function dt_adjustment_total_cents(PDO $pdo, int $assetId, int $excludeId): int
{
    $value = dt_q(
        $pdo,
        'SELECT COALESCE(SUM(ROUND(amount * 100)), 0) FROM assetadjustments WHERE assetid = :a AND adjustmentid <> :ex',
        ['a' => $assetId, 'ex' => $excludeId]
    )->fetchColumn();

    return (int)round((float)$value);
}

/**
 * Write an activity row (call before commit). Without login the user is unknown: userid is NULL.
 *
 * @param PDO    $pdo    Connection.
 * @param string $action Add | Update | Delete.
 * @param string $text   Description.
 *
 * @return void
 */
function dt_log_asset(PDO $pdo, string $action, string $text): void
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
 * Read the DataTables paging, search and order input (never trusted).
 *
 * @return array{draw:int,search:string,col:int,dir:string,limit:string} Clean input (limit is a ready LIMIT clause or '').
 */
function dt_read_table_input(): array
{
    $start  = max(0, (int)($_POST['start'] ?? 0));
    $length = (int)($_POST['length'] ?? 10);
    if ($length !== -1) {
        $length = max(1, min(DT_MAX_PAGE, $length));
    }
    $search = '';
    if (isset($_POST['search']) && is_array($_POST['search'])) {
        $search = trim((string)($_POST['search']['value'] ?? ''));
    }
    $col = -1;
    $dir = 'DESC';
    if (isset($_POST['order']) && is_array($_POST['order']) && isset($_POST['order'][0]) && is_array($_POST['order'][0])) {
        $col = (int)($_POST['order'][0]['column'] ?? -1);
        $dir = ((string)($_POST['order'][0]['dir'] ?? '') === 'asc') ? 'ASC' : 'DESC';
    }

    return [
        'draw'   => (int)($_POST['draw'] ?? 0),
        'search' => $search,
        'col'    => $col,
        'dir'    => $dir,
        'limit'  => $length > 0 ? ' LIMIT ' . $length . ' OFFSET ' . $start : '',
    ];
}

/**
 * List the assets with their remaining value.
 *
 * @param PDO                 $pdo Connection.
 * @param array<string,mixed> $in  Output of dt_read_table_input().
 *
 * @return array<string,mixed> Payload.
 */
function dt_list_assets(PDO $pdo, array $in): array
{
    // Remaining value as one SQL expression (no placeholders inside, so it can be repeated in the search and the order).
    $rem = 'GREATEST(a.amount - COALESCE((SELECT SUM(j.amount) FROM assetadjustments j WHERE j.assetid = a.assetid), 0), 0)';

    $orderMap = [
        1 => 'a.assetid', 2 => 'a.name', 3 => 'a.assettype', 4 => 'a.assetdate', 5 => 'a.amount', 6 => 'remaining',
    ];
    $orderSql = isset($orderMap[$in['col']]) ? $orderMap[$in['col']] . ' ' . $in['dir'] . ', a.assetid DESC' : 'a.assetid DESC';

    $recordsTotal = (int)dt_q($pdo, 'SELECT COUNT(*) FROM assets')->fetchColumn();

    $where  = '';
    $params = [];
    if ($in['search'] !== '') {
        $like    = '%' . addcslashes($in['search'], '\\%_') . '%';
        $likeNum = '%' . addcslashes(str_replace(',', '', $in['search']), '\\%_') . '%';
        $where   = " WHERE (CAST(a.assetid AS CHAR) LIKE :s1
                        OR a.name LIKE :s2
                        OR a.assettype LIKE :s3
                        OR CAST(a.assetdate AS CHAR) LIKE :s4
                        OR CAST(ROUND(a.amount, 2) AS CHAR) LIKE :s5
                        OR CAST(ROUND({$rem}, 2) AS CHAR) LIKE :s6)";
        $params  = ['s1' => $like, 's2' => $like, 's3' => $like, 's4' => $like, 's5' => $likeNum, 's6' => $likeNum];
    }

    $recordsFiltered = (int)dt_q($pdo, "SELECT COUNT(*) FROM assets a{$where}", $params)->fetchColumn();

    $rows = dt_q(
        $pdo,
        "SELECT a.assetid, a.name, a.assettype, a.assetdate, a.amount, {$rem} AS remaining
           FROM assets a{$where}
       ORDER BY {$orderSql}" . $in['limit'],
        $params
    )->fetchAll();

    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'assetid'   => (int)$r['assetid'],
            'name'      => (string)($r['name'] ?? ''),
            'assettype' => (string)($r['assettype'] ?? ''),
            'assetdate' => (string)$r['assetdate'],
            'amount'    => round((float)$r['amount'], 2),
            'remaining' => round((float)$r['remaining'], 2),
        ];
    }

    return [
        'success' => true, 'draw' => $in['draw'], 'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered, 'data' => $data,
    ];
}

/**
 * List the adjustments of one type.
 *
 * @param PDO                 $pdo  Connection.
 * @param array<string,mixed> $in   Output of dt_read_table_input().
 * @param string              $kind Adjustment type (whitelisted by the caller).
 *
 * @return array<string,mixed> Payload.
 */
function dt_list_adjustments(PDO $pdo, array $in, string $kind): array
{
    $orderMap = [1 => 'j.adjustmentid', 2 => 'a.name', 3 => 'j.description', 4 => 'j.amount', 5 => 'j.adjustmentdate'];
    $orderSql = isset($orderMap[$in['col']]) ? $orderMap[$in['col']] . ' ' . $in['dir'] . ', j.adjustmentid DESC' : 'j.adjustmentid DESC';

    $from = 'FROM assetadjustments j JOIN assets a ON a.assetid = j.assetid';

    $recordsTotal = (int)dt_q(
        $pdo,
        'SELECT COUNT(*) FROM assetadjustments WHERE adjustmenttype = :k',
        ['k' => $kind]
    )->fetchColumn();

    $where  = 'WHERE j.adjustmenttype = :kind';
    $params = ['kind' => $kind];
    if ($in['search'] !== '') {
        $like    = '%' . addcslashes($in['search'], '\\%_') . '%';
        $likeNum = '%' . addcslashes(str_replace(',', '', $in['search']), '\\%_') . '%';
        $where  .= ' AND (CAST(j.adjustmentid AS CHAR) LIKE :s1
                      OR a.name LIKE :s2
                      OR j.description LIKE :s3
                      OR CAST(ROUND(j.amount, 2) AS CHAR) LIKE :s4
                      OR CAST(j.adjustmentdate AS CHAR) LIKE :s5)';
        $params += ['s1' => $like, 's2' => $like, 's3' => $like, 's4' => $likeNum, 's5' => $like];
    }

    $recordsFiltered = (int)dt_q($pdo, "SELECT COUNT(*) {$from} {$where}", $params)->fetchColumn();

    $rows = dt_q(
        $pdo,
        "SELECT j.adjustmentid, j.assetid, j.description, j.amount, j.adjustmentdate, a.name AS assetname
           {$from} {$where}
       ORDER BY {$orderSql}" . $in['limit'],
        $params
    )->fetchAll();

    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'adjustmentid'   => (int)$r['adjustmentid'],
            'assetid'        => (int)$r['assetid'],
            'assetname'      => (string)($r['assetname'] ?? ''),
            'description'    => (string)($r['description'] ?? ''),
            'amount'         => round((float)$r['amount'], 2),
            'adjustmentdate' => (string)$r['adjustmentdate'],
        ];
    }

    return [
        'success' => true, 'draw' => $in['draw'], 'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered, 'data' => $data,
    ];
}

/**
 * List assets or adjustments (DataTables server-side). The kind is whitelisted: anything else means assets.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_list(PDO $pdo): array
{
    $kind = (string)($_POST['kind'] ?? 'assets');
    $in   = dt_read_table_input();

    return isset(DT_ADJ_TYPES[$kind]) ? dt_list_adjustments($pdo, $in, $kind) : dt_list_assets($pdo, $in);
}

/**
 * Assets that can receive an adjustment of a type, with their remaining value, leaving out the adjustment being edited (read-only, no locks).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 */
function dt_act_asset_options(PDO $pdo): array
{
    $type    = (string)($_POST['adjustmenttype'] ?? '');
    $exclude = max(0, (int)($_POST['adjustmentid'] ?? 0));
    if (!isset(DT_ADJ_TYPES[$type])) {
        return dt_fail('Invalid adjustment type.');
    }

    $rows = dt_q(
        $pdo,
        'SELECT a.assetid, a.name,
                GREATEST(a.amount - COALESCE((SELECT SUM(j.amount) FROM assetadjustments j
                                               WHERE j.assetid = a.assetid AND j.adjustmentid <> :ex), 0), 0) AS remaining
           FROM assets a
          WHERE a.assettype = :t
       ORDER BY a.name, a.assetid',
        ['ex' => $exclude, 't' => DT_ADJ_TYPES[$type]]
    )->fetchAll();

    $options = [];
    foreach ($rows as $r) {
        $options[] = [
            'id'        => (int)$r['assetid'],
            'name'      => (string)($r['name'] ?? ''),
            'remaining' => round((float)$r['remaining'], 2),
        ];
    }

    return ['success' => true, 'options' => $options];
}

/**
 * Add (id = 0) or edit an asset (one transaction, asset locked first).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_save_asset(PDO $pdo): array
{
    $d = dt_read_asset_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        $text = $d['name'] . ' (' . $d['type'] . ') ' . dt_money($d['amount']);

        if ($d['id'] > 0) {
            $old = dt_lock_asset($pdo, $d['id']);
            if ($old === null) {
                throw new DtFail('Asset not found.');
            }

            $info  = dt_q(
                $pdo,
                'SELECT COUNT(*) AS total, MIN(adjustmentdate) AS firstdate FROM assetadjustments WHERE assetid = :a',
                ['a' => $d['id']]
            )->fetch();
            $count = is_array($info) ? (int)$info['total'] : 0;
            $first = (is_array($info) && $info['firstdate'] !== null) ? (string)$info['firstdate'] : '';

            if ($count > 0 && (string)$old['assettype'] !== $d['type']) {
                throw new DtFail('The type cannot be changed because this asset has adjustments.', false, 'assettype');
            }
            $usedCents = dt_adjustment_total_cents($pdo, $d['id'], 0);
            if (dt_cents($d['amount']) < $usedCents) {
                throw new DtFail('Amount cannot be lower than the adjustments already made (' . dt_money($usedCents / 100) . ').', false, 'amount');
            }
            if ($count > 0 && $first !== '' && $d['date'] > $first) {
                throw new DtFail('The asset date cannot be after its first adjustment (' . $first . ').', false, 'assetdate');
            }

            dt_q(
                $pdo,
                'UPDATE assets SET name = :n, assettype = :t, amount = :m, assetdate = :dt WHERE assetid = :id',
                ['n' => $d['name'], 't' => $d['type'], 'm' => dt_f2($d['amount']), 'dt' => $d['date'], 'id' => $d['id']]
            );
            dt_log_asset($pdo, 'Update', 'Asset updated: ' . $text);

            return ['success' => true, 'message' => 'Asset updated successfully.'];
        }

        dt_q(
            $pdo,
            'INSERT INTO assets (name, assettype, amount, assetdate) VALUES (:n, :t, :m, :dt)',
            ['n' => $d['name'], 't' => $d['type'], 'm' => dt_f2($d['amount']), 'dt' => $d['date']]
        );
        dt_log_asset($pdo, 'Add', 'Asset added: ' . $text);

        return ['success' => true, 'message' => 'Asset added successfully.'];
    });
}

/**
 * Delete an asset unless it has adjustments (one transaction, asset locked first).
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found or blocked.
 */
function dt_act_delete_asset(PDO $pdo): array
{
    $assetId = max(0, (int)($_POST['assetid'] ?? 0));

    return dt_transaction(static function (PDO $pdo) use ($assetId): array {
        $asset = dt_lock_asset($pdo, $assetId);
        if ($asset === null) {
            throw new DtFail('Asset not found.');
        }
        $count = (int)dt_q(
            $pdo,
            'SELECT COUNT(*) FROM assetadjustments WHERE assetid = :a',
            ['a' => $assetId]
        )->fetchColumn();
        if ($count > 0) {
            throw new DtFail('You cannot delete this asset because it has adjustments.', true);
        }

        dt_q($pdo, 'DELETE FROM assets WHERE assetid = :id', ['id' => $assetId]);
        dt_log_asset(
            $pdo,
            'Delete',
            'Asset deleted: ' . $asset['name'] . ' (' . $asset['assettype'] . ') ' . dt_money($asset['amount'])
        );

        return ['success' => true, 'message' => 'Asset deleted successfully.'];
    });
}

/**
 * Add (id = 0) or edit an adjustment (one transaction). Lock order: the chosen asset first, then the adjustment.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail On invalid input or a broken rule.
 */
function dt_act_save_adjustment(PDO $pdo): array
{
    $d = dt_read_adjustment_input();

    return dt_transaction(static function (PDO $pdo) use ($d): array {
        // 1) the asset (locked BEFORE its adjustment total is read)
        $asset = dt_lock_asset($pdo, $d['asset']);
        if ($asset === null || (string)$asset['assettype'] !== DT_ADJ_TYPES[$d['type']]) {
            throw new DtFail('Select a valid asset.', false, 'assetid');
        }

        // 2) the adjustment (edit only), it must keep its type
        if ($d['id'] > 0) {
            $old = dt_lock_adjustment($pdo, $d['id']);
            if ($old === null || (string)$old['adjustmenttype'] !== $d['type']) {
                throw new DtFail('Adjustment not found.');
            }
        }

        // 3) remaining value in cents, leaving out the adjustment being edited
        $remainCents = dt_cents($asset['amount']) - dt_adjustment_total_cents($pdo, $d['asset'], $d['id']);
        if (dt_cents($d['amount']) > $remainCents) {
            throw new DtFail('Amount exceeds the remaining value (' . dt_money(max(0, $remainCents) / 100) . ').', false, 'amount');
        }

        // 4) the adjustment cannot be older than the asset
        if ($d['date'] < (string)$asset['assetdate']) {
            throw new DtFail('The adjustment date cannot be before the asset date.', false, 'adjustmentdate');
        }

        $label = dt_adjustment_label($d['type']);
        $text  = dt_money($d['amount']) . ' on ' . $asset['name'];

        if ($d['id'] > 0) {
            dt_q(
                $pdo,
                'UPDATE assetadjustments
                    SET assetid = :a, amount = :m, adjustmentdate = :dt, description = :d
                  WHERE adjustmentid = :id',
                ['a' => $d['asset'], 'm' => dt_f2($d['amount']), 'dt' => $d['date'], 'd' => $d['desc'], 'id' => $d['id']]
            );
            dt_log_asset($pdo, 'Update', $d['type'] . ' updated: ' . $text);

            return ['success' => true, 'message' => $label . ' updated successfully.'];
        }

        dt_q(
            $pdo,
            'INSERT INTO assetadjustments (assetid, adjustmenttype, amount, adjustmentdate, description)
             VALUES (:a, :t, :m, :dt, :d)',
            ['a' => $d['asset'], 't' => $d['type'], 'm' => dt_f2($d['amount']), 'dt' => $d['date'], 'd' => $d['desc']]
        );
        dt_log_asset($pdo, 'Add', $d['type'] . ' added: ' . $text);

        return ['success' => true, 'message' => $label . ' added successfully.'];
    });
}

/**
 * Delete an adjustment (one transaction). Lock order: its asset first, then the adjustment.
 *
 * @param PDO $pdo Connection.
 *
 * @return array<string,mixed> Payload.
 *
 * @throws DtFail When not found.
 */
function dt_act_delete_adjustment(PDO $pdo): array
{
    $adjustmentId = max(0, (int)($_POST['adjustmentid'] ?? 0));

    return dt_transaction(static function (PDO $pdo) use ($adjustmentId): array {
        // Plain read only to learn the asset id; nothing is trusted until both rows are locked.
        $peek = dt_q(
            $pdo,
            'SELECT assetid FROM assetadjustments WHERE adjustmentid = :id',
            ['id' => $adjustmentId]
        )->fetch();
        if (!is_array($peek)) {
            throw new DtFail('Adjustment not found.');
        }
        $assetId = (int)$peek['assetid'];

        $asset = dt_lock_asset($pdo, $assetId);
        if ($asset === null) {
            throw new DtFail('Adjustment not found.');
        }
        $adj = dt_lock_adjustment($pdo, $adjustmentId);
        if ($adj === null) {
            throw new DtFail('Adjustment not found.');
        }
        if ((int)$adj['assetid'] !== $assetId) {
            throw new DtFail('The adjustment was changed by someone else. Refresh the page and try again.');
        }
        $type = (string)$adj['adjustmenttype'];

        dt_q($pdo, 'DELETE FROM assetadjustments WHERE adjustmentid = :id', ['id' => $adjustmentId]);
        dt_log_asset($pdo, 'Delete', $type . ' deleted: ' . dt_money($adj['amount']) . ' on ' . $asset['name']);

        return ['success' => true, 'message' => dt_adjustment_label($type) . ' deleted successfully.'];
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
        'list' => 'dt_act_list', 'asset_options' => 'dt_act_asset_options',
        'save_asset' => 'dt_act_save_asset', 'delete_asset' => 'dt_act_delete_asset',
        'save_adjustment' => 'dt_act_save_adjustment', 'delete_adjustment' => 'dt_act_delete_adjustment',
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
        dt_log_error('[assets ' . $action . '] ' . dt_describe_throwable($e));
        if ($e->getCode() === '23000') {
            if ($action === 'delete_asset') {
                dt_json(['success' => false, 'blocked' => true, 'message' => 'You cannot delete this asset because it has adjustments.']);
            }
            if ($action === 'save_asset' || $action === 'save_adjustment') {
                dt_json(dt_fail('The selected asset no longer exists.'));
            }
        }
        dt_json(dt_fail('Server error. Please try again.'), 500);
    } catch (Throwable $e) {
        dt_log_error('[assets ' . $action . '] ' . dt_describe_throwable($e));
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
button, select, input, textarea { font-family: inherit; }
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
.form-control[readonly] { background: var(--surface); }
.form-control.is-invalid { border-color: var(--danger); background-image: none; padding-right: 10px; }
.form-control.is-invalid:focus { box-shadow: 0 0 0 2px rgba(157, 29, 34, 0.3); }
textarea.form-control { height: auto; }
.invalid-feedback { color: var(--danger); font-size: 13px; font-weight: 500; }
label { color: var(--heading); font-weight: 500; font-size: 14px; }
label .req { color: var(--danger); }

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
    padding: 5px 12px; font-size: 13px; font-weight: 600; border-radius: 0; border: 1px solid transparent;
    background: var(--surface); color: var(--brand-primary); margin-right: 2px;
}
.nav-tabs.compact-tabs .nav-link:hover { color: var(--brand-accent-dark); }
.nav-tabs.compact-tabs .nav-link.active { background: var(--brand-primary); color: #ffffff; border-color: var(--brand-primary); }
.tab-content { padding-top: 16px; }

/* ===== DataTable ===== */
.as-table { width: 100% !important; font-size: 14px; }
.as-table thead th { background: var(--surface); color: var(--heading); border-bottom: 1px solid var(--line); white-space: nowrap; font-weight: 600; }
.as-table td { vertical-align: middle; }
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
select.is-invalid + .select2-container--bootstrap4 .select2-selection { border-color: var(--danger); }
.modal .select2-container { z-index: 2000; }

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
            $isAccounting = ($key === 'accounting'); ?>
            <li>
                <?php if ($subs): ?>
                    <button type="button" class="nav-btn" data-sub title="<?= dt_e($label) ?>" aria-expanded="<?= $isAccounting ? 'true' : 'false' ?>" aria-controls="sub-<?= dt_e($key) ?>">
                        <?= dt_icon($icon) ?> <span><?= dt_e($label) ?></span><?= dt_icon('caret') ?>
                    </button>
                    <div class="sub<?= $isAccounting ? ' show' : '' ?>" id="sub-<?= dt_e($key) ?>">
                        <?php foreach ($subs as [$subLabel, $subPage]): ?>
                            <a href="<?= dt_e($subPage) ?>"<?= ($isAccounting && $subPage === 'assets.php') ? ' class="active" aria-current="page"' : '' ?>><span><?= dt_e($subLabel) ?></span></a>
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
 * Asset list card: header, tabs and the three tables.
 *
 * @return void
 */
function dt_view_list(): void
{
    $adjTable = static function (string $id, string $key): void {
        ?>
                    <table id="<?= dt_e($id) ?>" data-key="<?= dt_e($key) ?>" class="table table-hover table-bordered as-table">
                      <thead>
                        <tr>
                          <th class="px60">#</th>
                          <th>Adjustment</th>
                          <th>Asset</th>
                          <th>Description</th>
                          <th class="text-right">Amount</th>
                          <th>Date</th>
                          <th class="px120 text-center">Action</th>
                        </tr>
                      </thead>
                      <tbody></tbody>
                    </table>
<?php
    };
    ?>
        <div class="row">
          <div class="col-12 mb-4">
            <div class="card">
              <div class="card-body">
                <div class="card-head">
                  <h2 class="header-title">Asset List</h2>
                  <button type="button" class="btn btn-primary btn-sm" id="btnAdd"><i class="fas fa-plus mr-1" aria-hidden="true"></i> <span id="btnAddText">New Asset</span></button>
                </div>

                <ul class="nav nav-tabs compact-tabs" id="asTabs" role="tablist">
                  <li class="nav-item">
                    <a class="nav-link active" id="tab-assets" data-toggle="tab" href="#assets" role="tab" aria-controls="assets" aria-selected="true">New Assets</a>
                  </li>
                  <li class="nav-item">
                    <a class="nav-link" id="tab-supply" data-toggle="tab" href="#supply" role="tab" aria-controls="supply" aria-selected="false">Supply Adjustment</a>
                  </li>
                  <li class="nav-item">
                    <a class="nav-link" id="tab-depreciation" data-toggle="tab" href="#depreciation" role="tab" aria-controls="depreciation" aria-selected="false">Depreciation</a>
                  </li>
                </ul>

                <div class="tab-content" id="asTabContent">
                  <div class="tab-pane fade show active" id="assets" role="tabpanel" aria-labelledby="tab-assets">
                    <table id="tblAssets" data-key="assets" class="table table-hover table-bordered as-table">
                      <thead>
                        <tr>
                          <th class="px60">#</th>
                          <th>Asset</th>
                          <th>Name</th>
                          <th>Type</th>
                          <th>Date</th>
                          <th class="text-right">Amount</th>
                          <th class="text-right">Remaining</th>
                          <th class="px120 text-center">Action</th>
                        </tr>
                      </thead>
                      <tbody></tbody>
                    </table>
                  </div>
                  <div class="tab-pane fade" id="supply" role="tabpanel" aria-labelledby="tab-supply">
<?php $adjTable('tblSupply', 'supply'); ?>
                  </div>
                  <div class="tab-pane fade" id="depreciation" role="tabpanel" aria-labelledby="tab-depreciation">
<?php $adjTable('tblDepreciation', 'depreciation'); ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php
}

/**
 * Add / edit asset modal.
 *
 * @param string $today Today (Y-m-d).
 *
 * @return void
 */
function dt_modal_asset(string $today): void
{
    ?>
  <div class="modal fade" id="assetModal" tabindex="-1" role="dialog" aria-labelledby="assetModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form class="modal-content" id="assetForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="assetModalTitle">Add Asset</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="aId" value="0">
          <div class="form-group">
            <label for="aName">Asset Name <span class="req">*</span></label>
            <input type="text" class="form-control" id="aName" maxlength="<?= DT_MAX_NAME ?>" required aria-describedby="aNameFb">
            <div class="invalid-feedback" id="aNameFb">Asset name is required.</div>
          </div>
          <div class="form-group">
            <label for="aType">Type <span class="req">*</span></label>
            <select class="form-control" id="aType" required aria-describedby="aTypeFb">
              <option value="">Select type</option>
              <option value="Equipment">Equipment</option>
              <option value="Supply">Supply</option>
            </select>
            <div class="invalid-feedback" id="aTypeFb">Select a valid type.</div>
          </div>
          <div class="form-group">
            <label for="aDate">Date <span class="req">*</span></label>
            <input type="date" class="form-control" id="aDate" value="<?= dt_e($today) ?>" required aria-describedby="aDateFb">
            <div class="invalid-feedback" id="aDateFb">A valid date is required.</div>
          </div>
          <div class="form-group mb-0">
            <label for="aAmount">Amount <span class="req">*</span></label>
            <input type="number" class="form-control" id="aAmount" step="0.01" min="0.01" required aria-describedby="aAmountFb">
            <div class="invalid-feedback" id="aAmountFb">Enter an amount greater than 0.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveAsset"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Add / edit adjustment modal.
 *
 * @param string $today Today (Y-m-d).
 *
 * @return void
 */
function dt_modal_adjustment(string $today): void
{
    ?>
  <div class="modal fade" id="adjModal" tabindex="-1" role="dialog" aria-labelledby="adjModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form class="modal-content" id="adjForm" novalidate autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="adjModalTitle">Add Depreciation</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="jId" value="0">
          <input type="hidden" id="jType" value="">
          <div class="form-group">
            <label for="jAsset">Asset <span class="req">*</span></label>
            <select class="form-control" id="jAsset" required aria-describedby="jAssetFb">
              <option value="">Select asset</option>
            </select>
            <div class="invalid-feedback" id="jAssetFb">Select a valid asset.</div>
          </div>
          <div class="form-group">
            <label for="jRemaining">Remaining Value</label>
            <input type="number" class="form-control" id="jRemaining" step="0.01" readonly tabindex="-1">
          </div>
          <div class="form-group">
            <label for="jAmount">Amount <span class="req">*</span></label>
            <input type="number" class="form-control" id="jAmount" step="0.01" min="0.01" required aria-describedby="jAmountFb">
            <div class="invalid-feedback" id="jAmountFb">Enter an amount greater than 0.</div>
          </div>
          <div class="form-group">
            <label for="jDesc">Description <span class="req">*</span></label>
            <textarea class="form-control" id="jDesc" rows="3" maxlength="<?= DT_MAX_DESC ?>" required aria-describedby="jDescFb"></textarea>
            <div class="invalid-feedback" id="jDescFb">Description is required.</div>
          </div>
          <div class="form-group mb-0">
            <label for="jDate">Date <span class="req">*</span></label>
            <input type="date" class="form-control" id="jDate" value="<?= dt_e($today) ?>" required aria-describedby="jDateFb">
            <div class="invalid-feedback" id="jDateFb">A valid date is required.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success btn-sm" id="btnSaveAdj"><i class="fas fa-check mr-1" aria-hidden="true"></i> Submit</button>
          <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
<?php
}

/**
 * Delete asset modal.
 *
 * @return void
 */
function dt_modal_delete_asset(): void
{
    ?>
  <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="deleteModalTitle">Delete Asset</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body" id="delText"></div>
        <div class="modal-footer">
          <button type="button" class="btn btn-danger btn-sm" id="btnConfirmDelete"><i class="fas fa-trash mr-1" aria-hidden="true"></i> Delete</button>
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
        </div>
      </div>
    </div>
  </div>
<?php
}

/**
 * Delete adjustment modal.
 *
 * @return void
 */
function dt_modal_delete_adjustment(): void
{
    ?>
  <div class="modal fade" id="deleteAdjModal" tabindex="-1" role="dialog" aria-labelledby="delAdjTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="delAdjTitle">Delete Depreciation</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body" id="delAdjText"></div>
        <div class="modal-footer">
          <button type="button" class="btn btn-danger btn-sm" id="btnConfirmDeleteAdj"><i class="fas fa-trash mr-1" aria-hidden="true"></i> Delete</button>
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
$today      = date('Y-m-d');
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/**
 * Sidebar: key, label, icon, page, submenu links (label, page). Same menu as pages/shareholders.php.
 * ALL pages live flat in pages/ (no sub-folders), so every link is just a file name.
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
    'csrfField' => $csrfField, 'docTitle' => 'Assets', 'heading' => 'Assets',
    'sub' => 'Track equipment and supplies, and record depreciation and supply adjustments.',
];

/* ---------- Render ---------- */

dt_render_open($page);
dt_view_list();
dt_render_close();
dt_modal_asset($today);
dt_modal_adjustment($today);
dt_modal_delete_asset();
dt_modal_delete_adjustment();

$cfg       = ['csrf' => $csrf, 'self' => 'assets.php', 'today' => $today];
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
    var TODAY = CFG.today;

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

    function fmt(v) {
      return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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

    /* ---------- Safe sessionStorage (storage can be blocked) ---------- */
    function ssGet(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
    function ssSet(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }

    /* ---------- Field helpers: one FIELDS map per modal (server field name => input selector, feedback = selector + "Fb") ---------- */
    function defaultFeedback(fields) {
      var out = {};
      $.each(fields, function (key, sel) { out[key] = $(sel + 'Fb').text(); });
      return out;
    }

    function clearInvalid(fields, defaults) {
      $.each(fields, function (key, sel) {
        $(sel).removeClass('is-invalid');
        $(sel + 'Fb').text(defaults[key]);
      });
    }

    function markInvalid(fields, defaults, key, message) {
      var sel = fields[key];
      $(sel + 'Fb').text(message ? message : defaults[key]);
      $(sel).addClass('is-invalid');
      return sel;
    }

    function focusField(sel) {
      if (sel === '#jAsset') { $('#jAsset').select2('open'); } else { $(sel).trigger('focus'); }
    }

    // Shows the server answer of a failed save: marks the field (when the server named one) and toasts the message
    function showSaveFailure(res, fields, defaults) {
      if (res.field && fields[res.field]) {
        focusField(markInvalid(fields, defaults, res.field, res.message));
      }
      toastr.error(res.message);
    }
</script>

<!-- ===================== Page JavaScript ===================== -->
<script nonce="<?= $n ?>">
    /* ---------- Tabs and tables ---------- */
    var TABS = {
      assets: {
        sel: '#tblAssets', kind: 'assets', addText: 'New Asset', label: 'Assets', adj: false,
        exportCols: [1, 2, 3, 4, 5, 6], widths: [40, '*', 60, 60, 70, 70], pdfRight: [4, 5],
        search: 'Search asset...', empty: 'No assets found'
      },
      supply: {
        sel: '#tblSupply', kind: 'Supply Adjustment', addText: 'Adjust', label: 'Supply Adjustments', adj: true,
        word: 'Supply Adjustment', needText: 'Add a Supply asset first.',
        exportCols: [1, 2, 3, 4, 5], widths: [50, '*', '*', 70, 60], pdfRight: [3],
        search: 'Search adjustment...', empty: 'No adjustments found'
      },
      depreciation: {
        sel: '#tblDepreciation', kind: 'Depreciation', addText: 'Depreciate', label: 'Depreciation', adj: true,
        word: 'Depreciation', needText: 'Add an Equipment asset first.',
        exportCols: [1, 2, 3, 4, 5], widths: [50, '*', '*', 70, 60], pdfRight: [3],
        search: 'Search adjustment...', empty: 'No adjustments found'
      }
    };
    var tables = {};
    var activeKey = 'assets';

    // Export ALL matching rows: page length -> -1, redraw, run the standard export, restore the page
    function exportAll(name) {
      return function (e, dt, button, config) {
        var self   = this;
        var oldLen = dt.page.len();
        var run    = $.fn.dataTable.ext.buttons[name].action;

        if (oldLen === -1) { run.call(self, e, dt, button, config); return; }

        var oldStart = dt.settings()[0]._iDisplayStart;
        dt.one('draw', function () {
          run.call(self, e, dt, button, config);
          dt.page.len(oldLen);
          dt.settings()[0]._iDisplayStart = oldStart;
          dt.draw(false);
        });
        dt.page.len(-1).draw(false);
      };
    }

    function buttonsFor(cfg) {
      var base = {
        className: 'btn btn-secondary btn-sm',
        title: cfg.label,
        filename: cfg.label,
        exportOptions: { columns: cfg.exportCols }
      };
      return [
        $.extend({}, base, { extend: 'copyHtml5',  text: '<i class="fas fa-copy"></i> Copy',        title: '', action: exportAll('copyHtml5') }),
        $.extend({}, base, { extend: 'csvHtml5',   text: '<i class="fas fa-file-csv"></i> CSV',     action: exportAll('csvHtml5') }),
        $.extend({}, base, { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel', action: exportAll('excelHtml5') }),
        $.extend({}, base, {
          extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf"></i> PDF',
          orientation: 'portrait', pageSize: 'A4',
          action: exportAll('pdfHtml5'),
          customize: function (doc) {
            var tbl = null;
            $.each(doc.content, function (i, c) { if (c.table) { tbl = c.table; } });
            if (!tbl) { return; }
            tbl.widths = cfg.widths;
            $.each(tbl.body, function (i, row) {
              $.each(cfg.pdfRight, function (j, idx) { if (row[idx]) { row[idx].alignment = 'right'; } });
            });
          }
        }),
        $.extend({}, base, { extend: 'print',      text: '<i class="fas fa-print"></i> Print',      action: exportAll('print') })
      ];
    }

    function rowNo(d, type, row, meta) { return meta.row + meta.settings._iDisplayStart + 1; }

    function actionHtml(isAdj) {
      var what = isAdj ? 'adjustment' : 'asset';
      return '<button type="button" class="btn btn-primary act-btn btn-edit" title="Edit" aria-label="Edit ' + what + '"><i class="fas fa-edit" aria-hidden="true"></i></button> ' +
             '<button type="button" class="btn btn-danger act-btn btn-del" title="Delete" aria-label="Delete ' + what + '"><i class="fas fa-trash" aria-hidden="true"></i></button>';
    }

    function makeTable(key) {
      var cfg = TABS[key];
      var act = { data: null, orderable: false, searchable: false, className: 'text-center', render: function () { return actionHtml(cfg.adj); } };
      var num = { data: null, orderable: false, searchable: false, render: rowNo };
      var cols;

      if (!cfg.adj) {
        cols = [
          num,
          { data: 'assetid',   render: function (d) { return esc(d); } },
          { data: 'name',      render: function (d) { return esc(d); } },
          { data: 'assettype', render: function (d) { return esc(d); } },
          { data: 'assetdate', render: function (d) { return esc(d); } },
          { data: 'amount',    className: 'text-right', render: function (d) { return esc(fmt(d)); } },
          { data: 'remaining', className: 'text-right', render: function (d) { return esc(fmt(d)); } },
          act
        ];
      } else {
        cols = [
          num,
          { data: 'adjustmentid',   render: function (d) { return esc(d); } },
          { data: 'assetname',      render: function (d) { return esc(d); } },
          { data: 'description',    render: function (d) { return esc(d); } },
          { data: 'amount',         className: 'text-right', render: function (d) { return esc(fmt(d)); } },
          { data: 'adjustmentdate', render: function (d) { return esc(d); } },
          act
        ];
      }

      tables[key] = $(cfg.sel).DataTable({
        serverSide: true,
        autoWidth: false,
        searchDelay: 400,
        ajax: {
          url: URL_SELF,
          type: 'POST',
          data: function (d) { d.action = 'list'; d.kind = cfg.kind; d.csrf_token = CSRF; },
          dataSrc: 'data',
          error: function (xhr) { toastr.error('Could not load the list.'); }
        },
        columns: cols,
        order: [[1, 'desc']],
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        dom: "<'dt-top'<'dt-len'l><'dt-btns'B><'dt-find'f>>" +
             "<'table-scroll'tr>" +
             "<'row mt-2'<'col-md-5'i><'col-md-7'p>>",
        buttons: buttonsFor(cfg),
        language: { search: 'Search:', searchPlaceholder: cfg.search, emptyTable: cfg.empty, zeroRecords: cfg.empty }
      });
    }

    // Saving or deleting changes remaining values everywhere: reload every table that exists, keeping its page
    function reloadAll() {
      $.each(tables, function (key, dt) { dt.ajax.reload(null, false); });
    }

    function applyTab(key) {
      if (!Object.prototype.hasOwnProperty.call(TABS, key)) { key = 'assets'; }
      activeKey = key;
      ssSet('as_tab', key);
      $('#btnAddText').text(TABS[key].addText);
      if (!tables[key]) { makeTable(key); }
      else { tables[key].columns.adjust(); }
    }

    $('#asTabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
      applyTab(String($(e.target).attr('href')).substring(1));
    });

    (function () {
      var saved = ssGet('as_tab');
      if (!saved || !Object.prototype.hasOwnProperty.call(TABS, saved)) { saved = 'assets'; }
      if (saved === 'assets') { applyTab('assets'); }
      else { $('#tab-' + saved).tab('show'); }
    })();

    // One header button: asset modal on the first tab, adjustment modal on the others
    $('#btnAdd').on('click', function () {
      if (activeKey === 'assets') { openAsset(null); } else { openAdjustment(activeKey, null); }
    });

    function keyOf(el) { return $(el).closest('table').attr('data-key'); }

    function setSelect($sel, val) {
      $sel.val(val ? String(val) : '');
      if ($sel.val() === null) { $sel.val(''); }
      $sel.trigger('change.select2');
    }

    /* ---------- Asset add / edit ---------- */
    var ASSET_FIELDS = { name: '#aName', assettype: '#aType', assetdate: '#aDate', amount: '#aAmount' };
    var ASSET_FB = defaultFeedback(ASSET_FIELDS);

    $('#assetForm input, #assetForm select').on('input change', function () { $(this).removeClass('is-invalid'); });

    function openAsset(d) {
      $('#assetForm')[0].reset();
      clearInvalid(ASSET_FIELDS, ASSET_FB);
      if (d) {
        $('#aId').val(d.assetid);
        $('#aName').val(d.name);
        $('#aType').val(d.assettype);
        if ($('#aType').val() === null) { $('#aType').val(''); }
        $('#aDate').val(d.assetdate);
        $('#aAmount').val(Number(d.amount).toFixed(2));
        $('#assetModalTitle').text('Edit Asset');
      } else {
        $('#aId').val(0);
        $('#aDate').val(TODAY);
        $('#assetModalTitle').text('Add Asset');
      }
      $('#assetModal').modal('show');
    }

    $('#assetModal').on('shown.bs.modal', function () { $('#aName').trigger('focus'); });

    $('#assetForm').on('submit', function (e) {
      e.preventDefault();
      clearInvalid(ASSET_FIELDS, ASSET_FB);

      var name   = $.trim($('#aName').val());
      var type   = $('#aType').val();
      var date   = $('#aDate').val();
      var amount = parseFloat($('#aAmount').val());

      if (name === '')                  { focusField(markInvalid(ASSET_FIELDS, ASSET_FB, 'name')); return; }
      if (!type)                        { focusField(markInvalid(ASSET_FIELDS, ASSET_FB, 'assettype')); return; }
      if (!date)                        { focusField(markInvalid(ASSET_FIELDS, ASSET_FB, 'assetdate')); return; }
      if (isNaN(amount) || amount <= 0) { focusField(markInvalid(ASSET_FIELDS, ASSET_FB, 'amount')); return; }

      var $btn = $('#btnSaveAsset').prop('disabled', true);
      api({
        action: 'save_asset',
        assetid: $('#aId').val(),
        name: name,
        assettype: type,
        assetdate: date,
        amount: $('#aAmount').val()
      }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#assetModal').modal('hide');
          reloadAll();
          return;
        }
        showSaveFailure(res, ASSET_FIELDS, ASSET_FB);
      }).always(function () { $btn.prop('disabled', false); });
    });

    /* ---------- Adjustment add / edit ---------- */
    var ADJ_FIELDS = { assetid: '#jAsset', amount: '#jAmount', description: '#jDesc', adjustmentdate: '#jDate' };
    var ADJ_FB = defaultFeedback(ADJ_FIELDS);

    $('#jAsset').select2({ theme: 'bootstrap4', width: '100%', dropdownParent: $('#adjModal') });

    var REMAINING = {};   // asset id -> remaining value of the list currently loaded
    var optReq    = 0;    // request counter: late answers are ignored

    $('#jAmount, #jDesc, #jDate').on('input change', function () { $(this).removeClass('is-invalid'); });

    $('#jAsset').on('change', function () {
      $(this).removeClass('is-invalid');
      var v = $(this).val();
      $('#jRemaining').val(v && REMAINING[v] !== undefined ? Number(REMAINING[v]).toFixed(2) : '');
    });

    // Load the assets of the matching type, then run onDone (the modal opens only when loaded and not empty)
    function loadAssetOptions(cfg, tid, selected, onDone) {
      var req = ++optReq;
      api({ action: 'asset_options', adjustmenttype: cfg.kind, adjustmentid: tid }).done(function (res) {
        if (req !== optReq) { return; }
        if (!res.success) { toastr.error(res.message); return; }
        if (!res.options.length) { toastr.warning(cfg.needText); return; }
        var $s = $('#jAsset').empty().append($('<option>').val('').text('Select asset'));
        REMAINING = {};
        $.each(res.options, function (i, o) {
          REMAINING[String(o.id)] = o.remaining;
          $s.append($('<option>').val(String(o.id)).text(o.name + ' (remaining ' + fmt(o.remaining) + ')'));
        });
        setSelect($s, selected);
        $s.trigger('change');
        onDone();
      });
    }

    function openAdjustment(key, d) {
      var cfg = TABS[key];
      var tid = d ? d.adjustmentid : 0;

      $('#adjForm')[0].reset();
      clearInvalid(ADJ_FIELDS, ADJ_FB);
      $('#jId').val(tid);
      $('#jType').val(cfg.kind);
      $('#jRemaining').val('');
      $('#jDate').val(d ? d.adjustmentdate : TODAY);
      if (d) {
        $('#jAmount').val(Number(d.amount).toFixed(2));
        $('#jDesc').val(d.description || '');
      }
      $('#adjModalTitle').text((d ? 'Edit ' : 'Add ') + cfg.word);

      loadAssetOptions(cfg, tid, d ? d.assetid : '', function () { $('#adjModal').modal('show'); });
    }

    $('#adjForm').on('submit', function (e) {
      e.preventDefault();
      clearInvalid(ADJ_FIELDS, ADJ_FB);

      var asset  = $('#jAsset').val();
      var amount = parseFloat($('#jAmount').val());
      var desc   = $.trim($('#jDesc').val());
      var date   = $('#jDate').val();

      if (!asset)                       { focusField(markInvalid(ADJ_FIELDS, ADJ_FB, 'assetid')); return; }
      if (isNaN(amount) || amount <= 0) { focusField(markInvalid(ADJ_FIELDS, ADJ_FB, 'amount')); return; }
      if (desc === '')                  { focusField(markInvalid(ADJ_FIELDS, ADJ_FB, 'description')); return; }
      if (!date)                        { focusField(markInvalid(ADJ_FIELDS, ADJ_FB, 'adjustmentdate')); return; }

      var $btn = $('#btnSaveAdj').prop('disabled', true);
      api({
        action: 'save_adjustment',
        adjustmentid: $('#jId').val(),
        adjustmenttype: $('#jType').val(),
        assetid: asset,
        amount: $('#jAmount').val(),
        description: desc,
        adjustmentdate: date
      }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          $('#adjModal').modal('hide');
          reloadAll();
          return;
        }
        showSaveFailure(res, ADJ_FIELDS, ADJ_FB);
      }).always(function () { $btn.prop('disabled', false); });
    });

    /* ---------- Edit and delete buttons of the three tables ---------- */
    $('#asTabContent').on('click', '.btn-edit', function () {
      var key = keyOf(this);
      var d   = tables[key].row($(this).closest('tr')).data();
      if (key === 'assets') { openAsset(d); } else { openAdjustment(key, d); }
    });

    var deleteAssetId = 0;
    var deleteAdjId   = 0;

    $('#asTabContent').on('click', '.btn-del', function () {
      var key = keyOf(this);
      var d   = tables[key].row($(this).closest('tr')).data();
      if (key === 'assets') {
        deleteAssetId = d.assetid;
        $('#delText').empty().append(
          document.createTextNode('Delete asset '),
          $('<b>').text('#' + d.assetid),
          document.createTextNode(' (' + (d.name || '') + ', ' + fmt(d.amount) + ')? This cannot be undone.')
        );
        $('#deleteModal').modal('show');
      } else {
        deleteAdjId = d.adjustmentid;
        $('#delAdjTitle').text('Delete ' + TABS[key].word);
        $('#delAdjText').empty().append(
          document.createTextNode('Delete adjustment '),
          $('<b>').text('#' + d.adjustmentid),
          document.createTextNode(' (' + fmt(d.amount) + ', ' + (d.assetname || '') + ')? This cannot be undone.')
        );
        $('#deleteAdjModal').modal('show');
      }
    });

    $('#btnConfirmDelete').on('click', function () {
      var $btn = $(this).prop('disabled', true);
      api({ action: 'delete_asset', assetid: deleteAssetId }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          reloadAll();
        } else if (res.blocked) {
          toastr.warning(res.message);
        } else {
          toastr.error(res.message);
        }
      }).always(function () {
        $('#deleteModal').modal('hide');
        $btn.prop('disabled', false);
      });
    });

    $('#btnConfirmDeleteAdj').on('click', function () {
      var $btn = $(this).prop('disabled', true);
      api({ action: 'delete_adjustment', adjustmentid: deleteAdjId }).done(function (res) {
        if (res.success) {
          toastr.success(res.message);
          reloadAll();
        } else {
          toastr.error(res.message);
        }
      }).always(function () {
        $('#deleteAdjModal').modal('hide');
        $btn.prop('disabled', false);
      });
    });
</script>
</body>
</html>