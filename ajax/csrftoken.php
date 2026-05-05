<?php

/**
 * Mint a fresh one-shot CSRF token for AJAX calls.
 *
 * GLPI 11 CSRF tokens are single-use: the token rendered into a page form
 * is consumed by the first successful Session::checkCSRF() call. The bell
 * dropdown posts multiple times per page load so it needs a fresh token.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkLoginUser();

echo json_encode([
    'token' => Session::getNewCSRFToken(),
]);
