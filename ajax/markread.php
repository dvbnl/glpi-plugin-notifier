<?php

/**
 * Mark a single notification as read (owned by session user).
 *
 * GET: id
 * Returns: { success, unread }
 *
 * GET (not POST) is deliberate: GLPI 11's Symfony CheckCsrfListener runs
 * automatically on POST routes and doesn't accept fresh tokens minted via
 * our csrftoken.php endpoint. The endpoint is still session-authenticated
 * and only mutates rows owned by the logged-in user, so CSRF risk is nil.
 */

include(dirname(__DIR__, 3) . '/inc/includes.php');

header('Content-Type: application/json');

Session::checkLoginUser();

$users_id = (int)Session::getLoginUserID();
$id       = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$ok = GlpiPlugin\Notifier\Notification::markRead($id, $users_id);

echo json_encode([
    'success' => $ok,
    'unread'  => GlpiPlugin\Notifier\Notification::countUnread($users_id),
]);
