<?php

declare(strict_types=1);

/**
 * login.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: sign-in (view + handler).
 *
 * Prepared statements, password_verify + transparent rehash, disabled accounts
 * indistinguishable from bad credentials, timing equalisation, session and per-IP
 * throttling, CSRF, CSP nonce and a deferred activity log.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/db.php';

/** Valid bcrypt hash of a throw-away string (equalises timing for unknown users). */
const DT_DUMMY_HASH = '$2y$10$pptvcTRPaJIkiWMDYSpCguesOppGLeo8lAxtN4AsK.XVu3oHaSbjy';
const DT_LOGIN_LOGO = 'uploads/bushrofarmingandlivestocklogo.png';

/**
 * Read a POST field as a string (arrays and missing fields become "").
 *
 * @param string $key Field name.
 *
 * @return string Field value.
 */
function dt_post_string(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? $value : '';
}

/**
 * Failed-attempt result (the visitor never sees the reason, only the log does).
 *
 * @param string   $reason unknown | password | disabled | input.
 * @param int|null $userId User the username matched, if any.
 *
 * @return array{status:string,reason:string,userid:int|null}
 */
function dt_login_invalid(string $reason, ?int $userId = null): array
{
    return ['status' => 'invalid', 'reason' => $reason, 'userid' => $userId];
}

/**
 * Verify credentials. Unknown user, wrong password and disabled account each cost exactly
 * one bcrypt check and look identical to the visitor; the reason is only logged.
 *
 * @param PDO    $pdo      Connection.
 * @param string $username Trimmed username.
 * @param string $password Raw password.
 *
 * @return array<string,mixed> status "ok" (user, firstadmin, permissions) or "invalid" (reason, userid).
 *
 * @throws Throwable On database failure.
 */
function dt_attempt_login(PDO $pdo, string $username, string $password): array
{
    if (mb_strlen($username) > DT_MAX_USERNAME_LENGTH || strlen($password) > DT_MAX_PASSWORD_LENGTH) {
        password_verify('x', DT_DUMMY_HASH);

        return dt_login_invalid('input');
    }

    $stmt = $pdo->prepare('SELECT userid, name, username, password, status FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if (!is_array($user)) {
        password_verify($password, DT_DUMMY_HASH);

        return dt_login_invalid('unknown');
    }

    $userId = (int)$user['userid'];
    $hash   = (string)$user['password'];

    if (!password_verify($password, $hash)) {
        return dt_login_invalid('password', $userId);
    }
    if (!dt_is_active_status($user['status'])) {
        return dt_login_invalid('disabled', $userId);
    }

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        try {
            $update = $pdo->prepare('UPDATE users SET password = :hash WHERE userid = :id');
            $update->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $userId]);
        } catch (Throwable $e) {
            dt_log_error('Password rehash failed: ' . dt_describe_throwable($e)); // login still succeeds
        }
    }

    $firstAdmin = dt_auth_is_first_admin($pdo, $userId);

    return [
        'status'      => 'ok',
        'user'        => $user,
        'firstadmin'  => $firstAdmin,
        'permissions' => $firstAdmin ? [] : dt_auth_load_permissions($pdo, $userId),
    ];
}

/**
 * Build the view state of a request.
 *
 * @param string $error    Message for the visitor.
 * @param string $username Username to re-fill.
 * @param int    $lock     Lockout seconds remaining (0 = none).
 * @param int    $status   HTTP status.
 *
 * @return array{error:string,username:string,lock:int,status:int}
 */
function dt_login_state(string $error, string $username = '', int $lock = 0, int $status = 200): array
{
    return ['error' => $error, 'username' => $username, 'lock' => $lock, 'status' => $status];
}

/**
 * Message shown while locked out.
 *
 * @param int $seconds Seconds remaining.
 *
 * @return string Visitor message.
 */
function dt_lockout_message(int $seconds): string
{
    return 'Too many failed attempts. Please try again in ' . $seconds . 's.';
}

/**
 * Finish a successful sign-in: new session id, session data, deferred log, redirect.
 * Returns only when the session cannot be secured (fail-closed).
 *
 * @param array<string,mixed> $auth     Result of dt_attempt_login() with status "ok".
 * @param string              $username Typed username (re-filled on failure).
 *
 * @return array{error:string,username:string,lock:int,status:int}
 */
function dt_complete_login(array $auth, string $username): array
{
    if (!session_regenerate_id(true)) {
        dt_log_error('session_regenerate_id failed during login.');

        return dt_login_state(DT_GENERIC_ERROR, $username, 0, 503);
    }

    $user   = $auth['user'];
    $userId = (int)$user['userid'];
    $now    = time();

    $_SESSION = [
        'user_id'           => $userId,
        'name'              => (string)$user['name'],
        'username'          => (string)$user['username'],
        'first_admin'       => $auth['firstadmin'],
        'permissions'       => $auth['permissions'],
        'login_at'          => $now,
        'last_activity'     => $now,
        'access_checked_at' => $now,
    ];
    dt_csrf_rotate();

    dt_log_activity_later($userId, 'Login', 'User signed in (IP ' . dt_client_ip() . ')');
    dt_redirect(dt_home_route());
}

/**
 * Record a failed attempt and decide the message and the lockout.
 *
 * @param array<string,mixed> $auth     Result of dt_attempt_login() with status "invalid".
 * @param string              $username Typed username.
 *
 * @return array{error:string,username:string,lock:int,status:int}
 */
function dt_register_failure(array $auth, string $username): array
{
    $ip     = dt_client_ip();
    $userId = $auth['userid'] ?? null;
    $label  = match ($auth['reason'] ?? 'unknown') {
        'disabled' => 'Sign-in blocked: account disabled',
        'password' => 'Sign-in failed: wrong password',
        'input'    => 'Sign-in failed: invalid input',
        default    => 'Sign-in failed: unknown account',
    };
    // The typed username is never logged for unknown accounts (it may be a mistyped password).
    dt_log_activity_later($userId, 'Login Failed', $label . ' (IP ' . $ip . ')');

    $_SESSION['login_attempts'] = (int)($_SESSION['login_attempts'] ?? 0) + 1;

    if (dt_ip_record_failure()) {
        dt_log_activity_later(null, 'Login Locked', 'IP locked after repeated failures (IP ' . $ip . ')');

        return dt_login_state(dt_lockout_message(DT_IP_LOCKOUT_SECONDS), $username, DT_IP_LOCKOUT_SECONDS, 429);
    }

    if ($_SESSION['login_attempts'] >= DT_MAX_ATTEMPTS) {
        $_SESSION['login_lockout_until'] = time() + DT_LOCKOUT_SECONDS;
        $_SESSION['login_attempts']      = 0;
        dt_log_activity_later($userId, 'Login Locked', 'Session locked after repeated failures (IP ' . $ip . ')');

        return dt_login_state(dt_lockout_message(DT_LOCKOUT_SECONDS), $username, DT_LOCKOUT_SECONDS, 429);
    }

    return dt_login_state('Invalid username or password.', $username);
}

/**
 * Handle a POST to the login page.
 *
 * @return array{error:string,username:string,lock:int,status:int} View state (redirects on success).
 */
function dt_process_login(): array
{
    $lock = max(max(0, (int)($_SESSION['login_lockout_until'] ?? 0) - time()), dt_ip_locked_seconds());
    if ($lock > 0) {
        return dt_login_state(dt_lockout_message($lock), '', $lock, 429);
    }

    if (!dt_csrf_valid($_POST['csrf_token'] ?? null)) {
        return dt_login_state('Your session has expired. Please refresh and try again.', '', 0, 403);
    }

    $username = trim(dt_post_string('username'));
    $password = dt_post_string('password');
    $oldUser  = mb_substr($username, 0, DT_MAX_USERNAME_LENGTH);

    if ($username === '' || $password === '') {
        return dt_login_state('Please enter both username and password.', $oldUser);
    }
    if (!mb_check_encoding($username, 'UTF-8')) {
        password_verify('x', DT_DUMMY_HASH); // keep the timing comparable

        return dt_register_failure(dt_login_invalid('input'), '');
    }

    try {
        $auth = dt_attempt_login(dt_pdo(), $username, $password);
    } catch (Throwable $e) {
        dt_log_error('Login error: ' . dt_describe_throwable($e));

        return dt_login_state(DT_GENERIC_ERROR, $oldUser, 0, 503);
    }

    if ($auth['status'] === 'ok') {
        return dt_complete_login($auth, $oldUser); // redirects unless the session cannot be secured
    }

    return dt_register_failure($auth, $oldUser);
}

/* ================================================================
 * Request handling
 * ============================================================== */

dt_security_headers();

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'POST'], true)) {
    dt_render_message(405, 'Method not allowed', 'This request method is not supported.');
}
if (!dt_session_start()) {
    dt_render_message(503, 'Service unavailable', DT_GENERIC_ERROR);
}
if (dt_is_authenticated()) {
    dt_redirect(dt_home_route());
}
// dt_is_authenticated() may have replaced an expired session: make sure one is active.
if (!dt_session_start()) {
    dt_render_message(503, 'Service unavailable', DT_GENERIC_ERROR);
}

$notice = is_string($_SESSION['flash'] ?? null) ? $_SESSION['flash'] : '';
unset($_SESSION['flash']);

$state = dt_login_state('');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $state  = dt_process_login();
    $notice = '';
    dt_csrf_rotate(); // new token after every failed POST
} else {
    $existingLock = max(0, (int)($_SESSION['login_lockout_until'] ?? 0) - time());
    if ($existingLock > 0) {
        $state = dt_login_state(dt_lockout_message($existingLock), '', $existingLock);
    }
}

$csrf = dt_csrf_token();
session_write_close(); // the rest of the request only renders HTML

http_response_code($state['status']);
if ($state['lock'] > 0) {
    header('Retry-After: ' . $state['lock']);
}

$message  = $state['error'] !== '' ? $state['error'] : $notice;
$msgKind  = $state['error'] !== '' ? 'error' : 'info';
$hasError = $state['error'] !== '';
$hasLogo  = is_file(__DIR__ . '/' . DT_LOGIN_LOGO);
$logoUrl  = dt_base_path() . '/' . DT_LOGIN_LOGO;
$nonce    = dt_e(dt_nonce());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign In | Bushra Farming and Livestock</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&amp;display=swap" rel="stylesheet">
    <style nonce="<?= $nonce ?>">
        :root {
            --brand-primary:     #9d1d22;
            --brand-accent:      #16a04a;
            --brand-accent-dark: #0f7a37; /* text-bearing hover/focus accents: AA contrast on white */
            --ink:               #2a1213;
            --ink-soft:          #6b727b;
            --line:              rgba(157, 29, 34, 0.35);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        [hidden] { display: none !important; }
        body {
            font-family: 'Poppins', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            background: #ffffff; color: var(--ink);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 16px;
        }
        input, button { font: inherit; }

        .card {
            width: 100%; max-width: 420px; background: #ffffff;
            border: 2px solid var(--brand-primary); border-top: 6px solid var(--brand-accent);
            padding: 28px 20px;
        }
        .brand { text-align: center; margin-bottom: 16px; }
        .brand img { display: block; margin: 0 auto; width: 280px; height: auto; max-width: 100%; }
        .sr-only {
            position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
        }

        .msg { font-size: 13px; font-weight: 600; padding: 10px 14px; margin-bottom: 20px; border: 2px solid; border-left-width: 8px; }
        .msg:empty { display: block; padding: 0; margin: 0; border: 0; height: 0; overflow: hidden; }
        .msg--error { color: var(--brand-primary); border-color: var(--brand-primary); }
        .msg--info  { color: var(--brand-accent-dark); border-color: var(--brand-accent); }
        .msg:focus  { outline: none; }

        .field { margin-bottom: 20px; }
        .field label { display: block; font-size: 13px; font-weight: 600; color: var(--brand-primary); margin-bottom: 6px; }
        .control { position: relative; }
        .control > svg.icon {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            width: 16px; height: 16px; fill: var(--ink-soft); pointer-events: none;
            transition: fill 0.15s;
        }
        .control:focus-within > svg.icon { fill: var(--brand-accent); }
        .control input {
            width: 100%; padding: 11px 12px 11px 38px; font-size: 14px; color: var(--ink);
            background: #ffffff; border: 2px solid var(--line); border-radius: 0; outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .control input::placeholder { color: var(--ink-soft); }
        .control input:hover { border-color: var(--brand-primary); }
        .control input:focus { border-color: var(--brand-accent); box-shadow: 0 0 0 3px rgba(22, 160, 74, 0.22); }
        .control input[aria-invalid="true"] { border-color: var(--brand-primary); }
        #password { padding-right: 46px; }

        .toggle {
            position: absolute; right: 4px; top: 50%; transform: translateY(-50%);
            display: flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; background: none; color: var(--ink-soft);
            border: 2px solid transparent; border-radius: 0; cursor: pointer;
        }
        .toggle:hover { color: var(--brand-primary); }
        .toggle:focus-visible { outline: none; border-color: var(--brand-accent); }
        .toggle svg { width: 18px; height: 18px; }

        .caps { font-size: 12px; font-weight: 600; color: var(--brand-primary); margin-top: 6px; }
        .caps:empty { display: none; }

        .submit {
            width: 100%; padding: 12px; font-size: 14px; font-weight: 600; color: #ffffff;
            background: var(--brand-primary); border: 2px solid var(--brand-primary); border-radius: 0; cursor: pointer;
            transition: background 0.12s, border-color 0.12s;
        }
        .submit:hover { background: var(--brand-accent-dark); border-color: var(--brand-accent-dark); }
        .submit:focus-visible { outline: 3px solid rgba(22, 160, 74, 0.45); outline-offset: 2px; }
        .submit:disabled { opacity: 0.75; cursor: not-allowed; }

        @media (min-width: 481px) { .card { padding: 40px; } }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body>
<main class="card">
    <header class="brand">
        <?php if ($hasLogo): ?>
        <img id="logo" src="<?= dt_e($logoUrl) ?>" width="280" height="280" decoding="async" fetchpriority="high"
             alt="Bushra Farming and Livestock logo">
        <?php endif; ?>
        <h1 class="sr-only">Bushra Farming and Livestock: sign in</h1>
    </header>

    <div id="formMsg" class="msg msg--<?= $msgKind ?>" role="alert" aria-live="assertive" tabindex="-1"><?= dt_e($message) ?></div>

    <form id="loginForm" method="post" action="" novalidate data-lock="<?= (int)$state['lock'] ?>">
        <input type="hidden" name="csrf_token" value="<?= dt_e($csrf) ?>">

        <div class="field">
            <label for="username">Username</label>
            <div class="control">
                <svg class="icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                    <path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"/>
                </svg>
                <input type="text" id="username" name="username" placeholder="Enter your username"
                       value="<?= dt_e($state['username']) ?>" maxlength="<?= DT_MAX_USERNAME_LENGTH ?>"
                       autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus
                       aria-describedby="formMsg"<?= $hasError ? ' aria-invalid="true"' : '' ?>>
            </div>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <div class="control">
                <svg class="icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                    <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/>
                </svg>
                <input type="password" id="password" name="password" placeholder="Enter your password"
                       maxlength="<?= DT_MAX_PASSWORD_LENGTH ?>" autocomplete="current-password" required
                       aria-describedby="formMsg capsHint"<?= $hasError ? ' aria-invalid="true"' : '' ?>>
                <button type="button" class="toggle" id="togglePw" aria-label="Show password" aria-pressed="false" title="Show password">
                    <svg id="eyeOpen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg id="eyeShut" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" hidden>
                        <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
                        <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
                        <line x1="1" y1="1" x2="23" y2="23"/>
                    </svg>
                </button>
            </div>
            <p class="caps" id="capsHint" role="status" aria-live="polite"></p>
        </div>

        <button type="submit" class="submit" id="btnLogin">Sign In</button>
    </form>
</main>

<script nonce="<?= $nonce ?>">
(function () {
    'use strict';
    var form = document.getElementById('loginForm');
    var user = document.getElementById('username');
    var pass = document.getElementById('password');
    var btn  = document.getElementById('btnLogin');
    var msg  = document.getElementById('formMsg');
    var caps = document.getElementById('capsHint');
    var tog  = document.getElementById('togglePw');
    var eyeO = document.getElementById('eyeOpen');
    var eyeS = document.getElementById('eyeShut');
    var logo = document.getElementById('logo');
    var submitting = false;

    function say(text, kind) {
        msg.className = 'msg msg--' + kind;
        msg.textContent = text;
    }

    // Missing logo file: hide the broken image.
    if (logo) { logo.addEventListener('error', function () { logo.hidden = true; }); }

    // Show / hide password.
    tog.addEventListener('click', function () {
        var show = pass.type === 'password';
        pass.type = show ? 'text' : 'password';
        eyeO.hidden = show;
        eyeS.hidden = !show;
        tog.setAttribute('aria-pressed', show ? 'true' : 'false');
        tog.title = show ? 'Hide password' : 'Show password';
    });

    // Caps Lock hint.
    function checkCaps(e) {
        if (typeof e.getModifierState === 'function') {
            caps.textContent = e.getModifierState('CapsLock') ? 'Caps Lock is on' : '';
        }
    }
    pass.addEventListener('keydown', checkCaps);
    pass.addEventListener('keyup', checkCaps);
    pass.addEventListener('blur', function () { caps.textContent = ''; });

    // Lockout countdown (the server enforces it regardless).
    function startCountdown(seconds) {
        var left = seconds;
        btn.disabled = true;
        (function tick() {
            if (left <= 0) {
                btn.disabled = false;
                btn.textContent = 'Sign In';
                say('', 'error');
                return;
            }
            btn.textContent = 'Try again in ' + left + 's';
            left -= 1;
            setTimeout(tick, 1000);
        })();
    }
    var lock = parseInt(form.getAttribute('data-lock'), 10) || 0;
    if (lock > 0) { startCountdown(lock); }

    // Convenience checks only: the server is the source of truth.
    form.addEventListener('submit', function (e) {
        if (submitting || btn.disabled) { e.preventDefault(); return; }
        var u = user.value.trim();
        if (u === '' || pass.value === '') {
            e.preventDefault();
            say('Please enter both username and password.', 'error');
            (u === '' ? user : pass).focus();
            return;
        }
        submitting = true;
        btn.disabled = true;
        btn.textContent = 'Signing in\u2026';
    });

    // Back/forward cache: restore the form state.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted && lock === 0) {
            submitting = false;
            btn.disabled = false;
            btn.textContent = 'Sign In';
        }
    });
})();
</script>
</body>
</html>
<?php
dt_finish_response();