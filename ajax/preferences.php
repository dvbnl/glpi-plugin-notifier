<?php

// GET ?save=1 → upsert (CSRF-token required); plain GET → returns prefs.
// GET-for-mutation matches mark*.php — sidesteps GLPI 11's Symfony
// CheckCsrfListener which auto-runs on POST and rejects minted tokens.
// Saves still require Session::validateCSRF so a remote page can't flip
// a user's preferences silently via <img src=…>.

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkLoginUser();

$users_id = (int)Session::getLoginUserID();

if (!empty($_GET['save'])) {
    if (!Session::validateCSRF($_GET)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'invalid_csrf']);
        return;
    }

    GlpiPlugin\Notifier\Notification::savePreferences($users_id, $_GET);

    echo json_encode([
        'success'     => true,
        'preferences' => GlpiPlugin\Notifier\Notification::getPreferences($users_id),
    ]);
    return;
}

echo json_encode([
    'preferences' => GlpiPlugin\Notifier\Notification::getPreferences($users_id),
]);
