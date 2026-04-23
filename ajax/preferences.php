<?php

/**
 * Get / save the current user's notification preferences.
 *
 * Both read and write go over GET:
 *
 *   GET                         → returns { preferences }
 *   GET ?save=1&notify_xxx=0...&_glpi_csrf_token=… → upsert, returns
 *                                   { success, preferences }
 *
 * GET for mutation is deliberate and matches the mark*.php endpoints:
 * GLPI 11's Symfony CheckCsrfListener auto-runs on POST and rejects
 * single-use tokens minted by csrftoken.php, so staying on GET sidesteps
 * the whole dance.
 *
 * Saves additionally require a fresh CSRF token (minted via
 * csrftoken.php). Without that, a malicious external page could flip a
 * logged-in user's preferences silently via an <img src=…> tag and
 * suppress their future notifications — session-authenticated does not
 * mean CSRF-safe for state mutation.
 */

include(dirname(__DIR__, 3) . '/inc/includes.php');

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
