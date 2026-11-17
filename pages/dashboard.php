<?php

declare(strict_types=1);

/**
 * pages/dashboard.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: dashboard (same layout as the Dalsan dashboard).
 *
 * NO LOGIN for now: this page does not check a session or a permission.
 * When login returns, call the auth helpers of ../db.php right after the
 * "NO LOGIN" marker below.
 * Every query is prepared; each widget fails on its own (a broken query
 * shows "Unavailable" and never takes the page down). One file: markup,
 * CSS and JS are inline with the CSP nonce (no inline style attributes
 * or event-handler attributes).
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';

/* Fallbacks: only defined when ../db.php does not already provide them. */
if (!function_exists('dt_e')) {
    function dt_e(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('dt_nonce')) {
    function dt_nonce(): string
    {
        static $n = null;

        return $n ??= bin2hex(random_bytes(16));
    }
}
if (!function_exists('dt_security_headers')) {
    function dt_security_headers(): void
    {
        $n = dt_nonce();
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'nonce-{$n}' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self' 'nonce-{$n}'; img-src 'self' data:; base-uri 'self'; frame-ancestors 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
    }
}
if (!function_exists('dt_log_error')) {
    function dt_log_error(string $m): void
    {
        error_log($m);
    }
}
if (!function_exists('dt_describe_throwable')) {
    function dt_describe_throwable(Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage();
    }
}
if (!function_exists('dt_pdo')) {
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

const DT_ACTIVITY_LIMIT = 5;
const DT_TREND_MONTHS   = 6;
const DT_LOGO_FILE      = 'bushrofarmingandlivestocklogo.png'; // preferred logo file name
const DT_LOGO_DIRS      = ['uploads', 'assets', 'images'];     // folders next to pages/ searched for the logo (in this order)

/* Table and column names (fixed values, never user input). Fix a name here if your schema differs. */
const BF_SCHEMA = [
    'sales'     => ['table' => 'Sales',     'date' => 'SaleDate',     'total' => 'TotalAmount', 'paid' => 'PaidAmount', 'due' => 'DueDate'],
    'purchases' => ['table' => 'Purchases', 'date' => 'PurchaseDate', 'total' => 'TotalAmount', 'paid' => 'PaidAmount', 'due' => 'DueDate'],
    'expenses'  => ['table' => 'Expenses',  'date' => 'ExpenseDate',  'total' => 'Amount'],
];

/* ================================================================
 * Helpers
 * ============================================================== */

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
 * Run a prepared query.
 *
 * @param PDO                  $pdo    Connection.
 * @param string               $sql    SQL with named placeholders (each name used once).
 * @param array<string,mixed>  $params Bound values.
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
 * Run a widget query; on failure log it and return the default.
 *
 * @param callable $fn      Query callback.
 * @param mixed    $default Value returned on failure.
 *
 * @return mixed Callback result or default.
 */
function dt_safe(callable $fn, mixed $default = null): mixed
{
    try {
        return $fn();
    } catch (Throwable $e) {
        dt_log_error('[dashboard] ' . dt_describe_throwable($e));

        return $default;
    }
}

/**
 * Format an amount as dollars.
 *
 * @param float $amount Amount.
 *
 * @return string e.g. "$1,200.00".
 */
function dt_money(float $amount): string
{
    return '$' . number_format($amount, 2);
}

/**
 * Human "time ago" text.
 *
 * @param string $datetime Database datetime.
 *
 * @return string e.g. "3 hours ago".
 */
function dt_time_ago(string $datetime): string
{
    $time = strtotime($datetime);
    if ($time === false) {
        return '';
    }
    $diff  = max(0, time() - $time);
    $units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];

    foreach ($units as [$name, $seconds]) {
        if ($diff >= $seconds) {
            $n = intdiv($diff, $seconds);

            return $n . ' ' . $name . ($n > 1 ? 's' : '') . ' ago';
        }
    }

    return 'just now';
}

/**
 * Total of sales, purchases or expenses from a date (included) to a date (excluded).
 *
 * @param PDO    $pdo  Connection.
 * @param string $kind sales | purchases | expenses (key of BF_SCHEMA).
 * @param string $from First day (Y-m-d).
 * @param string $to   Day after the last day (Y-m-d).
 *
 * @return float Total amount.
 */
function dt_total_between(PDO $pdo, string $kind, string $from, string $to): float
{
    $s = BF_SCHEMA[$kind];

    return (float)dt_q(
        $pdo,
        "SELECT COALESCE(SUM({$s['total']}), 0) FROM {$s['table']} WHERE {$s['date']} >= :f AND {$s['date']} < :t",
        ['f' => $from, 't' => $to]
    )->fetchColumn();
}

/**
 * Open balance of sales (receivables) or purchases (payables).
 *
 * @param PDO    $pdo  Connection.
 * @param string $kind sales | purchases.
 *
 * @return array{amount:float,count:int,overdue:float} Open amount, open documents, overdue part.
 */
function dt_open_balance(PDO $pdo, string $kind): array
{
    $s   = BF_SCHEMA[$kind];
    $row = dt_q(
        $pdo,
        "SELECT COALESCE(SUM({$s['total']} - {$s['paid']}), 0) AS amount,
                COUNT(*) AS cnt,
                COALESCE(SUM(CASE WHEN {$s['due']} < CURDATE() THEN {$s['total']} - {$s['paid']} ELSE 0 END), 0) AS overdue
           FROM {$s['table']}
          WHERE {$s['total']} - {$s['paid']} > 0.004"
    )->fetch();

    return ['amount' => (float)$row['amount'], 'count' => (int)$row['cnt'], 'overdue' => (float)$row['overdue']];
}

/**
 * Badge colour class for an activity action.
 *
 * @param string $action Action text from the activity log.
 *
 * @return string success | warning | danger | info.
 */
function dt_badge_class(string $action): string
{
    return match (strtolower($action)) {
        'delete', 'login failed', 'login locked' => 'danger',
        'update'                                 => 'warning',
        'add'                                    => 'success',
        default                                  => 'info',
    };
}

/**
 * Arrow + amount (up = accent colour, down or zero = primary colour). Output is already escaped.
 *
 * @param float  $diff Difference to the previous period.
 * @param string $text Text shown beside the arrow.
 *
 * @return string HTML.
 */
function dt_delta(float $diff, string $text): string
{
    $up = $diff > 0.004;

    return '<span class="delta ' . ($up ? 'up' : 'down') . '">' . dt_icon($up ? 'arrow-up' : 'arrow-down') . ' '
        . dt_e($text) . '</span>';
}

/**
 * Inline SVG icon (no icon fonts, CSP safe).
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
        'expenses'   => '<path d="M21 12V7a2 2 0 0 0-2-2H5a2 2 0 0 1 0-4h14"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
        'sales'      => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
        'receivable' => '<line x1="17" y1="7" x2="7" y2="17"/><polyline points="17 17 7 17 7 7"/>',
        'payable'    => '<line x1="7" y1="17" x2="17" y2="7"/><polyline points="7 7 17 7 17 17"/>',
        'menu'       => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'logout'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'arrow'      => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        'arrow-up'   => '<line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/>',
        'arrow-down' => '<line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/>',
        'caret'      => '<polyline points="6 9 12 15 18 9"/>',
    ];

    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
}

/**
 * Build one stat card with a delta against the previous period.
 *
 * @param string         $label Card label.
 * @param float|int|null $now   Current value (null = unavailable).
 * @param float|int|null $prev  Previous value (null = unavailable).
 * @param string         $note  Text after the delta.
 * @param string         $icon  Icon key.
 * @param string         $tone  accent | primary (icon accent).
 *
 * @return array<string,mixed> Card definition.
 */
function dt_stat(string $label, float|int|null $now, float|int|null $prev, string $note, string $icon, string $tone): array
{
    $available = $now !== null && $prev !== null;
    $diff      = $available ? (float)$now - (float)$prev : 0.0;

    return [
        'label' => $label,
        'value' => $now === null ? null : dt_money((float)$now),
        'delta' => $available ? dt_delta($diff, dt_money(abs($diff))) : '',
        'note'  => $available ? $note : 'Unavailable',
        'icon'  => $icon,
        'tone'  => $tone,
    ];
}

/**
 * Build one stat card for an open balance (receivables or payables).
 *
 * @param string                                          $label Card label.
 * @param array{amount:float,count:int,overdue:float}|null $open  Open balance (null = unavailable).
 * @param string                                          $icon  Icon key.
 * @param string                                          $tone  accent | primary.
 *
 * @return array<string,mixed> Card definition.
 */
function dt_open_stat(string $label, ?array $open, string $icon, string $tone): array
{
    if ($open === null) {
        return ['label' => $label, 'value' => null, 'delta' => '', 'note' => 'Unavailable', 'icon' => $icon, 'tone' => $tone];
    }
    $overdue = $open['overdue'] > 0.004;

    return [
        'label' => $label,
        'value' => dt_money($open['amount']),
        'delta' => $overdue ? dt_delta(-1.0, dt_money($open['overdue']) . ' overdue') : '',
        'note'  => $open['count'] . ($open['count'] === 1 ? ' open document' : ' open documents'),
        'icon'  => $icon,
        'tone'  => $tone,
    ];
}

/* ================================================================
 * NO LOGIN: nothing is checked here for now (no session, no permission).
 * ============================================================== */

dt_security_headers();

/* ================================================================
 * Page context
 * ============================================================== */

$userName   = 'Administrator';
$initial    = mb_strtoupper(mb_substr($userName, 0, 1)) ?: 'A';
$agencyName = 'Bushra Farming and Livestock';
$csrfField  = function_exists('dt_csrf_field') ? dt_csrf_field() : '';
$logoutUrl  = '../index.php';
$logoUrl    = dt_find_logo(DT_LOGO_FILE);

/**
 * Sidebar: key, label, icon, page, submenu links (label, page).
 * A group with submenus renders as an expandable button; its own page is reached through its submenu links.
 * Links are relative to pages/ (this file). Grouped sections live in their own folder:
 *   Sales      -> pages/sales/       (e.g. /pages/sales/invoices.php)
 *   People     -> pages/people/      (e.g. /pages/people/customers.php)
 *   Accounting -> pages/accounting/  (e.g. /pages/accounting/chartofaccounts.php)
 *   Reports    -> pages/reports/     (e.g. /pages/reports/salesreport.php)
 * Single pages (Purchases, Products, Livestock, Settings, Users, Activities) stay directly in pages/.
 *
 * @var list<array{0:string,1:string,2:string,3:string,4:list<array{0:string,1:string}>}> $navigation
 */
$navigation = [
    ['dashboard',  'Dashboard',  'home',       'dashboard.php',  []],
    ['sales',      'Sales',      'sales',      'sales/invoices.php', [
        ['Invoices',   'sales/invoices.php'],
        ['Quotations', 'sales/quotations.php'],
    ]],
    ['livestock',  'Livestock',  'livestock',  'livestock.php',  []],
    ['products',   'Products',   'products',   'products.php',   []],
    ['purchases',  'Purchases',  'purchases',  'purchases.php',  []],
    ['people',     'People',     'people',     'people/customers.php', [
        ['Customers',    'people/customers.php'],
        ['Suppliers',    'people/suppliers.php'],
        ['Shareholders', 'people/shareholders.php'],
        ['Employees',    'people/employees.php'],
    ]],
    ['accounting', 'Accounting', 'accounting', 'accounting/chartofaccounts.php', [
        ['Chart of Accounts', 'accounting/chartofaccounts.php'],
        ['Receipts',          'accounting/receipts.php'],
        ['Payments',          'accounting/payments.php'],
        ['Expenses',          'accounting/expenses.php'],
        ['Account Transfer',  'accounting/accounttransfer.php'],
        ["Owner's Equity",    'accounting/equity.php'],
        ['Payrolls',          'accounting/payrolls.php'],
        ['Assets',            'accounting/assets.php'],
    ]],
    ['reports',    'Reports',    'reports',    'reports/salesreport.php', [
        ['Sales Report',      'reports/salesreport.php'],
        ['Purchases Report',  'reports/purchasesreport.php'],
        ['Livestock',         'reports/livestockreport.php'],
        ['Customers',         'reports/customersreport.php'],
        ['Suppliers',         'reports/suppliersreport.php'],
        ['Employees',         'reports/employeesreport.php'],
        ['Shareholders',      'reports/shareholdersreport.php'],
        ['Users',             'reports/usersreport.php'],
        ['Chart of Accounts', 'reports/chartofaccountsreport.php'],
        ['Receipts',          'reports/receiptsreport.php'],
        ['Payments',          'reports/paymentsreport.php'],
        ['Cash Report',       'reports/cashreport.php'],
        ['Expenses',          'reports/expensesreport.php'],
        ['Account Transfer',  'reports/accounttransferreport.php'],
        ['Payrolls',          'reports/payrollsreport.php'],
        ['Assets',            'reports/assetsreport.php'],
        ['Income Statement',  'reports/incomestatement.php'],
        ['Equity Statement',  'reports/equitystatement.php'],
        ['Balance Sheet',     'reports/balancesheet.php'],
        ['Cash Flow',         'reports/cashflow.php'],
    ]],
    ['settings',   'Settings',   'settings',   'settings.php',   []],
];

/** @var list<array{0:string,1:string,2:string}> $userMenu label, page, icon (top-right menu, as in Dalsan) */
$userMenu = [
    ['Users',      'users.php',      'users'],
    ['Activities', 'activities.php', 'activities'],
];
$canActivities = true;

/* ================================================================
 * Dashboard data
 * ============================================================== */

$monthStart = date('Y-m-01');
$nextMonth  = date('Y-m-01', strtotime('first day of next month'));
$prevMonth  = date('Y-m-01', strtotime('first day of last month'));

// Sales and expenses: this month vs last month.
$salesNow  = dt_safe(static fn () => dt_total_between(dt_pdo(), 'sales', $monthStart, $nextMonth));
$salesPrev = dt_safe(static fn () => dt_total_between(dt_pdo(), 'sales', $prevMonth, $monthStart));
$expNow    = dt_safe(static fn () => dt_total_between(dt_pdo(), 'expenses', $monthStart, $nextMonth));
$expPrev   = dt_safe(static fn () => dt_total_between(dt_pdo(), 'expenses', $prevMonth, $monthStart));

// Open receivables (sales) and open payables (purchases).
$receivables = dt_safe(static fn () => dt_open_balance(dt_pdo(), 'sales'));
$payables    = dt_safe(static fn () => dt_open_balance(dt_pdo(), 'purchases'));

// Activity log (latest rows; ordered by the primary key so it stays fast as the log grows).
$activities = dt_safe(static fn () => dt_q(
    dt_pdo(),
    'SELECT a.Action AS action, a.Description AS description, a.ActivityDate AS activitydate,
            COALESCE(u.Name, \'System\') AS username
       FROM ActivityLogs a
       LEFT JOIN Users u ON u.UserID = a.UserID
      ORDER BY a.ActivityID DESC
      LIMIT ' . DT_ACTIVITY_LIMIT
)->fetchAll());

$stats = [
    dt_stat('Sales This Month', $salesNow, $salesPrev, 'Compared last month', 'sales', 'accent'),
    dt_open_stat('Open Receivables', $receivables, 'receivable', 'primary'),
    dt_open_stat('Open Payables', $payables, 'payable', 'accent'),
    dt_stat('Expenses This Month', $expNow, $expPrev, 'Compared last month', 'expenses', 'primary'),
];

// Sales vs expenses, last months (oldest first).
$trend       = [];
$trendMax    = 1.0;
$trendFailed = false;
for ($i = DT_TREND_MONTHS - 1; $i >= 0; $i--) {
    $from  = date('Y-m-01', strtotime("first day of -{$i} months"));
    $to    = date('Y-m-01', strtotime($from . ' +1 month'));
    $sold  = dt_safe(static fn () => dt_total_between(dt_pdo(), 'sales', $from, $to));
    $spent = dt_safe(static fn () => dt_total_between(dt_pdo(), 'expenses', $from, $to));
    if ($sold === null || $spent === null) {
        $trendFailed = true;
    }
    $sold     = (float)($sold ?? 0);
    $spent    = (float)($spent ?? 0);
    $trendMax = max($trendMax, $sold, $spent);
    $trend[]  = ['label' => date('M Y', strtotime($from)), 'sales' => $sold, 'exp' => $spent];
}

// Receivables vs payables: open amount and overdue part.
$balances = [];
foreach (['Receivables' => $receivables, 'Payables' => $payables] as $name => $open) {
    $balances[] = ['name' => $name, 'open' => $open];
}

$nonce = dt_e(dt_nonce());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Dashboard | Bushra Farming and Livestock</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&amp;display=swap" rel="stylesheet">
    <style nonce="<?= $nonce ?>">
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
            --topbar-h: 70px;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { overflow-x: hidden; min-height: 100%; }
        body {
            --side-w: 240px; --left-w: 240px;
            margin: 0; background: var(--surface); color: var(--text);
            font-family: 'Poppins', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            font-size: 15px; line-height: 1.5;
        }
        body.enlarged { --side-w: 70px; --left-w: 70px; }
        h1, h2, h3 { margin: 0; font-weight: 500; color: var(--heading); }
        p { margin: 0; }
        a { color: inherit; text-decoration: none; }
        button, select { font-family: inherit; font-size: inherit; }
        [hidden] { display: none !important; }
        .ico { width: 18px; height: 18px; flex: none; display: inline-block; vertical-align: middle; }
        :focus-visible { outline: 3px solid var(--brand-accent); outline-offset: 2px; }
        .sr-only {
            position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
        }
        .skip-link {
            position: fixed; top: 8px; left: 8px; z-index: 2000; padding: 10px 16px;
            background: var(--brand-accent-dark); color: #ffffff; font-weight: 500; transform: translateY(-200%);
        }
        .skip-link:focus { transform: translateY(0); }

        /* Topbar */
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
        .dropdown-menu {
            display: none; position: absolute; right: 0; top: calc(100% + 10px); min-width: 230px; background: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.08); border-top: 3px solid var(--brand-accent);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.14); padding: 6px 0; z-index: 1000;
        }
        .dropdown-menu.show { display: block; }
        .dropdown-head { padding: 8px 20px 10px; font-weight: 500; color: var(--heading); }
        .dropdown-agency { font-size: 13.5px; font-weight: 400; color: var(--muted); }
        .dropdown-divider { height: 1px; background: var(--line); margin: 6px 0; }
        .dropdown-item {
            display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 20px; color: var(--text);
            background: none; border: 0; text-align: left; cursor: pointer;
        }
        .dropdown-item:hover { background: var(--surface); color: var(--brand-primary); }
        .dropdown-item.danger { color: var(--brand-primary); }
        .dropdown-item:disabled { opacity: .7; cursor: wait; }
        .dropdown-form { margin: 0; }

        /* Sidebar (white; groups with submenus: accordion) */
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

        /* Content */
        .content-page { margin-left: var(--side-w); min-height: 100vh; display: flex; flex-direction: column; transition: margin-left .2s ease; }
        .content { flex: 1; padding: var(--topbar-h) 20px 10px; }
        .page-title-box { padding: 22px 0 18px; }
        .page-title { font-size: 24px; line-height: 34px; }
        .page-sub { color: var(--muted); }

        .grid-stats { display: grid; gap: 20px; margin-bottom: 20px; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); }
        .grid-3 { display: grid; gap: 20px; margin-bottom: 20px; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }

        .card { background: #ffffff; min-width: 0; box-shadow: 0 -3px 31px 0 rgba(0, 0, 0, 0.04), 0 6px 20px 0 rgba(0, 0, 0, 0.03); }
        .card-body { padding: 20px; }
        .card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
        .header-title { font-size: 17px; font-weight: 600; color: var(--brand-primary); }

        .stat { position: relative; padding: 18px 20px; }
        .stat-icon {
            position: absolute; top: 16px; right: 16px; width: 44px; height: 44px; display: grid; place-items: center;
            background: rgba(22, 160, 74, 0.12); color: var(--brand-accent-dark);
        }
        .stat.primary .stat-icon { background: rgba(157, 29, 34, 0.1); color: var(--brand-primary); }
        .stat-icon .ico { width: 22px; height: 22px; }
        .stat-label { margin: 0 56px 8px 0; font-size: 13px; font-weight: 500; letter-spacing: .5px; text-transform: uppercase; color: var(--muted); }
        .stat-value { display: block; font-size: 26px; font-weight: 600; color: var(--heading); line-height: 1.35; }
        .stat-value.na { color: var(--muted); }
        .stat-sub { margin-top: 6px; font-size: 13.5px; color: var(--muted); }
        .delta { display: inline-flex; align-items: center; gap: 3px; margin-right: 6px; font-weight: 600; }
        .delta .ico { width: 13px; height: 13px; }
        .delta.up { color: var(--brand-accent-dark); }
        .delta.down { color: var(--brand-primary); }

        /* Activity feed */
        .feed { margin: 0; padding: 0; list-style: none; }
        .feed li { position: relative; padding: 0 0 10px 20px; margin-bottom: 10px; border-left: 3px solid var(--line); border-bottom: 1px solid var(--line); }
        .feed li:last-child { border-left-color: transparent; margin-bottom: 0; }
        .feed li::before { content: ""; position: absolute; top: 3px; left: -7px; width: 11px; height: 11px; background: var(--brand-accent); }
        .feed-name { font-weight: 500; color: var(--heading); line-height: 1.3; }
        .feed-meta { margin-top: 3px; display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
        .feed-desc { font-size: 13.5px; color: var(--muted); }
        .ago { font-size: 13.5px; color: var(--muted); font-style: italic; margin-left: 4px; }
        .badge { display: inline-block; padding: 2px 8px; font-size: 12.5px; font-weight: 600; line-height: 1.5; color: #ffffff; background: var(--muted); }
        .badge.success { background: var(--brand-accent-dark); } .badge.warning { background: var(--warning); }
        .badge.danger { background: var(--brand-primary); }      .badge.info { background: var(--info); }
        .view-all { display: inline-flex; align-items: center; gap: 6px; margin-top: 14px; font-weight: 600; color: var(--brand-primary); }
        .view-all .ico { width: 14px; height: 14px; transition: transform .15s ease; }
        .view-all:hover { color: var(--brand-accent-dark); }
        .view-all:hover .ico { transform: translateX(3px); }

        /* Chart cards (same look as the Dalsan city cards) */
        .city-item { margin-bottom: 16px; }
        .city-row { display: flex; justify-content: space-between; align-items: flex-end; gap: 8px; }
        .city-code { font-size: 16.5px; font-weight: 500; color: var(--heading); }
        .city-times { font-size: 13.5px; font-weight: 600; color: var(--muted); text-align: right; }
        progress.bar {
            display: block; width: 100%; height: 10px; margin-top: 4px; border: 0; border-radius: 0;
            -webkit-appearance: none; appearance: none; background: #e9ecef;
        }
        progress.bar::-webkit-progress-bar { background: #e9ecef; }
        progress.bar.sales { color: var(--brand-accent); }
        progress.bar.sales::-webkit-progress-value { background: var(--brand-accent); }
        progress.bar.sales::-moz-progress-bar { background: var(--brand-accent); }
        progress.bar.exp { color: var(--brand-primary); }
        progress.bar.exp::-webkit-progress-value { background: var(--brand-primary); }
        progress.bar.exp::-moz-progress-bar { background: var(--brand-primary); }
        .legend { display: flex; gap: 16px; margin-bottom: 14px; font-size: 13.5px; color: var(--muted); }
        .legend i { display: inline-block; width: 10px; height: 10px; margin-right: 6px; vertical-align: middle; }
        .legend .l-sales { background: var(--brand-accent); }
        .legend .l-exp { background: var(--brand-primary); }
        .empty { padding: 22px 0; text-align: center; color: var(--muted); }
        .empty.error { color: var(--brand-primary); }

        .footer { border-top: 1px solid rgba(112, 112, 112, 0.2); text-align: center; padding: 19px 30px 20px; color: var(--muted); }

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
    </style>
</head>
<body>
<a class="skip-link" href="#main">Skip to main content</a>

<script nonce="<?= $nonce ?>">
    try { if (window.innerWidth > 768 && localStorage.getItem('dt_sidebar') === 'collapsed') document.body.classList.add('enlarged'); } catch (e) {}
</script>

<header class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" aria-label="<?= dt_e($agencyName) ?>: Dashboard">
            <?php if ($logoUrl !== ''): ?>
                <img src="<?= dt_e($logoUrl) ?>" alt="<?= dt_e($agencyName) ?>" id="logo">
            <?php endif; ?>
            <span class="brand-text" id="brandText"<?= $logoUrl !== '' ? ' hidden' : '' ?>>Bushra Farming<br>&amp; Livestock</span>
        </a>
    </div>

    <div class="navbar-custom">
        <button type="button" class="menu-btn" id="menuToggle" aria-label="Toggle navigation menu" aria-controls="sidebar-menu" aria-expanded="true" title="Show or hide the menu">
            <?= dt_icon('menu') ?>
        </button>

        <div class="nav-user">
            <button type="button" class="nav-user-btn" id="userToggle" aria-haspopup="true" aria-expanded="false" aria-controls="userMenu">
                <span class="avatar" aria-hidden="true"><?= dt_e($initial) ?></span>
                <span class="user-name"><?= dt_e($userName) ?></span>
                <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="dropdown-menu" id="userMenu" role="menu" aria-labelledby="userToggle">
                <div class="dropdown-head"><?= dt_e($userName) ?><div class="dropdown-agency"><?= dt_e($agencyName) ?></div></div>
                <div class="dropdown-divider"></div>
                <?php foreach ($userMenu as [$label, $page, $icon]): ?>
                    <a class="dropdown-item" role="menuitem" href="<?= dt_e($page) ?>"><?= dt_icon($icon) ?> <?= dt_e($label) ?></a>
                <?php endforeach; ?>
                <?php if ($userMenu): ?><div class="dropdown-divider"></div><?php endif; ?>
                <form class="dropdown-form" id="logoutForm" method="post" action="<?= dt_e($logoutUrl) ?>">
                    <?= $csrfField ?>
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="dropdown-item danger" role="menuitem" id="logoutBtn">
                        <?= dt_icon('logout') ?> <span id="logoutLabel">Logout</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>

<nav class="side-menu" id="sidebar-menu" aria-label="Main navigation">
    <ul>
        <?php foreach ($navigation as [$key, $label, $icon, $page, $subs]): ?>
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
                    <a href="<?= dt_e($page) ?>" title="<?= dt_e($label) ?>"<?= $key === 'dashboard' ? ' class="active" aria-current="page"' : '' ?>>
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
            <h1 class="page-title">Dashboard</h1>
            <p class="page-sub">Welcome back, <?= dt_e($userName) ?>. Today is <?= dt_e(date('l, d F Y')) ?>.</p>
        </div>

        <section class="grid-stats" aria-label="Key figures">
            <?php foreach ($stats as $s): ?>
                <article class="card stat <?= dt_e($s['tone']) ?>">
                    <div class="stat-icon"><?= dt_icon($s['icon']) ?></div>
                    <p class="stat-label"><?= dt_e($s['label']) ?></p>
                    <?php if ($s['value'] === null): ?>
                        <span class="stat-value na">&mdash;</span>
                    <?php else: ?>
                        <span class="stat-value"><?= dt_e($s['value']) ?></span>
                    <?php endif; ?>
                    <p class="stat-sub"><?= $s['delta'] ?><span><?= dt_e($s['note']) ?></span></p>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="grid-3">

            <section class="card" aria-labelledby="activityTitle">
                <div class="card-body">
                    <div class="card-head"><h2 class="header-title" id="activityTitle">Activity Log</h2></div>
                    <?php if ($activities === null): ?>
                        <p class="empty error" role="alert">Activity could not be loaded.</p>
                    <?php elseif (!$activities): ?>
                        <p class="empty">No activity yet.</p>
                    <?php else: ?>
                        <ul class="feed">
                            <?php foreach ($activities as $a):
                                $action = (string)$a['action'];
                                $label  = strcasecmp($action, 'update') === 0 ? 'Updated' : $action;
                                $class  = dt_badge_class($action); ?>
                                <li>
                                    <div class="feed-name"><?= dt_e($a['username']) ?></div>
                                    <div class="feed-meta">
                                        <span class="badge <?= $class ?>"><?= dt_e($label) ?></span>
                                        <span class="feed-desc"><?= dt_e($a['description'] ?? '') ?></span>
                                        <span class="ago"><?= dt_e(dt_time_ago((string)$a['activitydate'])) ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($canActivities): ?>
                        <a class="view-all" href="activities.php">View all <?= dt_icon('arrow') ?></a>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card" aria-labelledby="trendTitle">
                <div class="card-body">
                    <div class="card-head"><h2 class="header-title" id="trendTitle">Sales vs Expenses</h2></div>
                    <?php if ($trendFailed): ?>
                        <p class="empty error" role="alert">Could not load data</p>
                    <?php else: ?>
                        <div class="legend">
                            <span><i class="l-sales"></i>Sales</span>
                            <span><i class="l-exp"></i>Expenses</span>
                        </div>
                        <?php foreach ($trend as $t): ?>
                            <div class="city-item">
                                <div class="city-row">
                                    <span class="city-code"><?= dt_e($t['label']) ?></span>
                                    <span class="city-times"><?= dt_e(dt_money($t['sales'])) ?> / <?= dt_e(dt_money($t['exp'])) ?></span>
                                </div>
                                <progress class="bar sales" max="<?= dt_e((string)$trendMax) ?>" value="<?= dt_e((string)$t['sales']) ?>" aria-label="Sales <?= dt_e($t['label']) ?>"></progress>
                                <progress class="bar exp" max="<?= dt_e((string)$trendMax) ?>" value="<?= dt_e((string)$t['exp']) ?>" aria-label="Expenses <?= dt_e($t['label']) ?>"></progress>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card" aria-labelledby="balanceTitle">
                <div class="card-body">
                    <div class="card-head"><h2 class="header-title" id="balanceTitle">Receivables and Payables</h2></div>
                    <div class="legend">
                        <span><i class="l-exp"></i>Overdue part of the open amount</span>
                    </div>
                    <?php foreach ($balances as $b): ?>
                        <?php if ($b['open'] === null): ?>
                            <div class="city-item">
                                <div class="city-row">
                                    <span class="city-code"><?= dt_e($b['name']) ?></span>
                                    <span class="city-times">Unavailable</span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="city-item">
                                <div class="city-row">
                                    <span class="city-code"><?= dt_e($b['name']) ?></span>
                                    <span class="city-times"><?= dt_e(dt_money($b['open']['overdue'])) ?> overdue of <?= dt_e(dt_money($b['open']['amount'])) ?></span>
                                </div>
                                <progress class="bar exp" max="<?= dt_e((string)max($b['open']['amount'], 1.0)) ?>" value="<?= dt_e((string)$b['open']['overdue']) ?>" aria-label="<?= dt_e($b['name']) ?> overdue share"></progress>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>

        </div>
    </main>

    <footer class="footer">
        &copy; <?= dt_e(date('Y')) ?> Bushra Farming and Livestock. All rights reserved.
    </footer>
</div>

<script nonce="<?= $nonce ?>">
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

    // User dropdown
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
</body>
</html>