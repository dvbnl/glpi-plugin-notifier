<?php

// GET (not POST): GLPI 11's Symfony CheckCsrfListener auto-runs on POST
// routes and rejects our minted tokens. Session + ownership scope means
// CSRF risk is nil for this self-only mutation.

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkLoginUser();

$users_id = (int)Session::getLoginUserID();

$ok = GlpiPlugin\Notifier\Notification::markAllRead($users_id);

echo json_encode([
    'success' => $ok,
    'unread'  => GlpiPlugin\Notifier\Notification::countUnread($users_id),
]);
