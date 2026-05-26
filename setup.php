<?php

/**
 * -------------------------------------------------------------------------
 * Notifier - In-app notification bell plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Notifier.
 *
 * Notifier is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * Notifier is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Notifier. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2024-2026 DVBNL
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/dvbnl/glpi-plugin-notifier
 * -------------------------------------------------------------------------
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_NOTIFIER_VERSION', '1.0.3');
define('PLUGIN_NOTIFIER_MIN_GLPI', '10.0.0');
define('PLUGIN_NOTIFIER_MAX_GLPI', '11.99.99');

// htmlescape() arrived in GLPI 10.0.x as the GLPI 11 bridge.
if (!function_exists('htmlescape')) {
    function htmlescape(?string $string): string
    {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}

function plugin_version_notifier(): array
{
    return [
        'name'           => __('Notifier - In-app notifications', 'notifier'),
        'version'        => PLUGIN_NOTIFIER_VERSION,
        'author'         => 'DVBNL',
        'license'        => 'GPLv3',
        'homepage'       => 'https://github.com/dvbnl/glpi-plugin-notifier',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_NOTIFIER_MIN_GLPI,
                'max' => PLUGIN_NOTIFIER_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.1',
            ],
        ],
    ];
}

function plugin_init_notifier(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['notifier'] = true;

    // GLPI 11 prefers public/; GLPI 10 uses css/js/. Both copies must
    // stay in sync — see CHANGELOG for the v1.0.2 drift incident.
    if (is_dir(__DIR__ . '/public/')) {
        $PLUGIN_HOOKS['add_css']['notifier'] = 'public/notifier.css';
        $PLUGIN_HOOKS['add_javascript']['notifier'] = 'public/notifier.js';
    } else {
        $PLUGIN_HOOKS['add_css']['notifier'] = 'css/notifier.css';
        $PLUGIN_HOOKS['add_javascript']['notifier'] = 'js/notifier.js';
    }

    // Self-service has its own flow; central interface only.
    if (
        isset($_SESSION['glpiactiveprofile']['interface'])
        && $_SESSION['glpiactiveprofile']['interface'] === 'helpdesk'
    ) {
        unset($PLUGIN_HOOKS['add_css']['notifier']);
        unset($PLUGIN_HOOKS['add_javascript']['notifier']);
    }

    Plugin::registerClass(
        'GlpiPlugin\Notifier\Notification',
        ['addtabon' => []]
    );

    $watched_types = [
        'Ticket',
        'Change',
        'Problem',
        'ProjectTask',
        'ITILFollowup',
        'TicketTask',
        'ChangeTask',
        'ProblemTask',
        'ITILSolution',
        'Ticket_User',
        'Change_User',
        'Problem_User',
        'Group_Ticket',
        'Change_Group',
        'Group_Problem',
        'ProjectTaskTeam',
        'TicketValidation',
        'ChangeValidation',
    ];

    $dispatch = ['GlpiPlugin\Notifier\Notification', 'handleItemEvent'];

    foreach ($watched_types as $type) {
        $PLUGIN_HOOKS[Hooks::ITEM_ADD]['notifier'][$type]    = $dispatch;
        $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['notifier'][$type] = $dispatch;
    }

    // item_purge → drop dangling bell rows.
    $cleanup = ['GlpiPlugin\Notifier\Notification', 'cleanForItem'];
    foreach ($watched_types as $type) {
        $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['notifier'][$type] = $cleanup;
    }

    // Opening the item's form (any route — bell click, search hit, direct
    // URL) clears its outstanding bells for the viewer.
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_FORM]['notifier'] =
        ['GlpiPlugin\Notifier\Notification', 'markItemAsSeen'];
}
