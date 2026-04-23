<?php

/**
 * Return the current user's notifications + unread count.
 *
 * GET: no parameters — everything is resolved from session.
 */

include(dirname(__DIR__, 3) . '/inc/includes.php');

header('Content-Type: application/json');

// Login-only: every authenticated user sees their own bell. There is no
// RBAC gating any more — the plugin used to expose a profile-level right
// but that was removed in favour of an "always available" UX.
Session::checkLoginUser();

$users_id = (int)Session::getLoginUserID();

$limit = 25;
if (isset($_GET['limit'])) {
    $limit = max(1, min(100, (int)$_GET['limit']));
}

$items  = GlpiPlugin\Notifier\Notification::getForUser($users_id, $limit);
$unread = GlpiPlugin\Notifier\Notification::countUnread($users_id);

echo json_encode([
    'unread' => $unread,
    'items'  => $items,
]);
