<?php

/**
 * Mark a single notification as unread (owned by session user).
 *
 * GET: id
 * Returns: { success, unread }
 *
 * GET for the same reason as markread.php — bypasses GLPI 11's Symfony
 * CheckCsrfListener which only runs on POST routes. Endpoint still
 * session-authenticated and only mutates rows owned by the logged-in user.
 */

include(dirname(__DIR__, 3) . '/inc/includes.php');

header('Content-Type: application/json');

Session::checkLoginUser();

$users_id = (int)Session::getLoginUserID();
$id       = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$ok = GlpiPlugin\Notifier\Notification::markUnread($id, $users_id);

echo json_encode([
    'success' => $ok,
    'unread'  => GlpiPlugin\Notifier\Notification::countUnread($users_id),
]);
