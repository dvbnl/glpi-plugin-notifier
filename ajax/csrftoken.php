<?php

// GLPI 11 CSRF tokens are single-use; the bell mints fresh ones for each
// state-mutating call.

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkLoginUser();

echo json_encode([
    'token' => Session::getNewCSRFToken(),
]);
