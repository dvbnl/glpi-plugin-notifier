<?php

// GET (not POST): see markread.php for the rationale.

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkLoginUser();

$users_id = (int)Session::getLoginUserID();
$id       = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$ok = GlpiPlugin\Notifier\Notification::markUnread($id, $users_id);

echo json_encode([
    'success' => $ok,
    'unread'  => GlpiPlugin\Notifier\Notification::countUnread($users_id),
]);
