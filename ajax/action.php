<?php

// The only endpoint that writes to core GLPI objects, so also the
// strictest: header token, fresh load of the item, then GLPI's own
// rights check. The notification row grants nothing.

use GlpiPlugin\Notifier\Config as NotifierConfig;
use GlpiPlugin\Notifier\Endpoint;
use GlpiPlugin\Notifier\QuickAction;

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Endpoint::begin();
Endpoint::requireToken();

if (!NotifierConfig::get('quick_actions_enabled')) {
    Endpoint::fail('disabled', 403);
}

$itemtype = Endpoint::itemtypeParam();
$items_id = Endpoint::intParam('items_id');
$action   = Endpoint::stringParam('action', 32);

if ($itemtype === '' || $items_id <= 0) {
    Endpoint::fail('bad_request', 400);
}

$item = QuickAction::loadWritable($itemtype, $items_id);
if (is_string($item)) {
    Endpoint::fail($item, $item === 'forbidden' ? 403 : 404);
}

switch ($action) {
    case 'take':
        $result = QuickAction::take($item);
        break;

    case 'status':
        $result = QuickAction::setStatus($item, Endpoint::intParam('status'));
        break;

    case 'followup':
        $result = QuickAction::addFollowup($item, Endpoint::stringParam('content', 4000));
        break;

    default:
        Endpoint::fail('unknown_action', 400);
}

if (empty($result['success'])) {
    $error = (string)($result['error'] ?? 'update_failed');
    Endpoint::fail($error, $error === 'forbidden' ? 403 : 400);
}

Endpoint::json($result);
