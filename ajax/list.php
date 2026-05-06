<?php

// GET — returns { unread, unread_groups, items } for the session user.

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkLoginUser();

$users_id = (int)Session::getLoginUserID();

$limit = 25;
if (isset($_GET['limit'])) {
    $limit = max(1, min(100, (int)$_GET['limit']));
}

$items         = GlpiPlugin\Notifier\Notification::getForUser($users_id, $limit);
$unread        = GlpiPlugin\Notifier\Notification::countUnread($users_id);
$unread_groups = GlpiPlugin\Notifier\Notification::countUnreadGroups($users_id);

echo json_encode([
    'unread'        => $unread,
    'unread_groups' => $unread_groups,
    'items'         => $items,
]);
