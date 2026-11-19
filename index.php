<?php

declare(strict_types=1);

/**
 * index.php
 * ---------------------------------------------------------------
 * Bushra Farming and Livestock: entry point / router.
 *
 *   GET/HEAD  -> the signed-in user's home page (dt_home_route(): the Dashboard when he
 *                holds that permission, otherwise the first page he may open), else login
 *   POST      -> action=logout (CSRF protected), nothing else
 *
 * Whitelist routing only: no request value is ever used as an include path or a redirect target.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/db.php';

/**
 * Sign the user out: log it, destroy the session and cookie, go to login.
 *
 * @return never
 */
function dt_logout(): never
{
    $userId = dt_has_user_session() ? (int)$_SESSION['user_id'] : null;

    dt_destroy_session(); // empties $_SESSION, destroys it and expires the cookie

    if ($userId !== null) {
        dt_log_activity_later($userId, 'Logout', 'User signed out (IP ' . dt_client_ip() . ')');
    }
    dt_redirect('login');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    dt_security_headers();

    // Logout is the only POST action; the action name is compared, never used as a path.
    if (($_POST['action'] ?? '') !== 'logout') {
        dt_render_message(400, 'Bad request', 'This request is not supported.', 'index');
    }
    if (!dt_has_session_cookie() || !dt_session_start()) {
        dt_redirect('login'); // nothing to sign out of
    }
    if (!dt_csrf_valid($_POST['csrf_token'] ?? null)) {
        dt_render_message(403, 'Request rejected', 'Your session token is invalid or has expired.', 'index');
    }
    dt_logout();
}

if ($method !== 'GET' && $method !== 'HEAD') {
    dt_render_message(405, 'Method not allowed', 'This request method is not supported.');
}

// No session cookie means anonymous: no session file, no database.
if (!dt_has_session_cookie()) {
    dt_redirect('login', 302);
}

// dt_is_authenticated() starts the session, enforces the timeouts and refreshes the access data.
// dt_home_route() needs the ACTIVE session, so it is evaluated before dt_redirect() closes it.
if (!dt_is_authenticated()) {
    dt_redirect('login', 302);
}
dt_redirect(dt_home_route(), 302);