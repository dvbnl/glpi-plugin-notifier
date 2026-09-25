<?php

// Notifier — install / uninstall hooks.

use GlpiPlugin\Notifier\Config as NotifierConfig;
use GlpiPlugin\Notifier\Notification;

function plugin_notifier_install(): bool
{
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $migration         = new Migration(PLUGIN_NOTIFIER_VERSION);

    if (!$DB->tableExists('glpi_plugin_notifier_notifications')) {
        $query = "CREATE TABLE `glpi_plugin_notifier_notifications` (
            `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Recipient',
            `actor_users_id`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Who triggered the event',
            `entities_id`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Entity of the source item',
            `itemtype`        VARCHAR(100) NOT NULL DEFAULT '',
            `items_id`        INT UNSIGNED NOT NULL DEFAULT 0,
            `event`           VARCHAR(50) NOT NULL DEFAULT '',
            `channel`         VARCHAR(10) NOT NULL DEFAULT '' COMMENT 'direct | group',
            `title`           VARCHAR(255) NOT NULL DEFAULT '',
            `message`         TEXT,
            `url`             VARCHAR(500) NOT NULL DEFAULT '',
            `is_read`         TINYINT NOT NULL DEFAULT 0,
            `snoozed_until`   TIMESTAMP NULL DEFAULT NULL,
            `date_creation`   TIMESTAMP NULL DEFAULT NULL,
            `date_mod`        TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `actor_users_id` (`actor_users_id`),
            KEY `entities_id` (`entities_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `is_read` (`is_read`),
            KEY `user_unread` (`users_id`, `is_read`),
            KEY `user_feed` (`users_id`, `is_read`, `snoozed_until`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    } else {
        // Upgrade path: pre-channel rows get '' and bypass the read-time
        // filter — that mirrors the old insert-time gating behaviour.
        $migration->addField('glpi_plugin_notifier_notifications', 'channel', 'string', [
            'value' => '',
            'after' => 'event',
        ]);
        $needs_entity_backfill = !$DB->fieldExists('glpi_plugin_notifier_notifications', 'entities_id');
        $migration->addField('glpi_plugin_notifier_notifications', 'entities_id', 'integer', [
            'value' => 0,
            'after' => 'actor_users_id',
        ]);
        $migration->addField('glpi_plugin_notifier_notifications', 'snoozed_until', 'timestamp', [
            'after' => 'is_read',
        ]);
        $migration->addKey('glpi_plugin_notifier_notifications', ['entities_id'], 'entities_id');
        $migration->addKey(
            'glpi_plugin_notifier_notifications',
            ['users_id', 'is_read', 'snoozed_until'],
            'user_feed'
        );

        if ($needs_entity_backfill) {
            $migration->executeMigration();
            plugin_notifier_backfill_entities();
        }
    }

    // Missing row = all on.
    if (!$DB->tableExists('glpi_plugin_notifier_preferences')) {
        $query = "CREATE TABLE `glpi_plugin_notifier_preferences` (
            `users_id`                    INT UNSIGNED NOT NULL,
            `notify_ticket_direct`        TINYINT NOT NULL DEFAULT 1,
            `notify_ticket_group`         TINYINT NOT NULL DEFAULT 1,
            `notify_change_direct`        TINYINT NOT NULL DEFAULT 1,
            `notify_change_group`         TINYINT NOT NULL DEFAULT 1,
            `notify_problem_direct`       TINYINT NOT NULL DEFAULT 1,
            `notify_problem_group`        TINYINT NOT NULL DEFAULT 1,
            `notify_projecttask_direct`   TINYINT NOT NULL DEFAULT 1,
            `notify_projecttask_group`    TINYINT NOT NULL DEFAULT 1,
            `notify_ticket_entity`        TINYINT NOT NULL DEFAULT 0,
            `notify_change_entity`        TINYINT NOT NULL DEFAULT 0,
            `notify_problem_entity`       TINYINT NOT NULL DEFAULT 0,
            `notify_event_assigned`       TINYINT NOT NULL DEFAULT 1,
            `notify_event_created`        TINYINT NOT NULL DEFAULT 1,
            `notify_event_commented`      TINYINT NOT NULL DEFAULT 1,
            `notify_event_task_added`     TINYINT NOT NULL DEFAULT 1,
            `notify_event_solution`       TINYINT NOT NULL DEFAULT 1,
            `notify_event_status_changed` TINYINT NOT NULL DEFAULT 1,
            `notify_event_updated`        TINYINT NOT NULL DEFAULT 1,
            `notify_event_validation`     TINYINT NOT NULL DEFAULT 1,
            `notify_event_mention`        TINYINT NOT NULL DEFAULT 1,
            `notify_event_deadline`       TINYINT NOT NULL DEFAULT 1,
            `desktop_enabled`             TINYINT NOT NULL DEFAULT 0,
            `sound_enabled`               TINYINT NOT NULL DEFAULT 0,
            `date_mod`                    TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    } else {
        foreach (Notification::getEventSlugs() as $slug) {
            $migration->addField('glpi_plugin_notifier_preferences', 'notify_event_' . $slug, 'bool', [
                'value' => 1,
            ]);
        }
        // Opt-in, unlike everything else here.
        foreach (['ticket', 'change', 'problem'] as $slug) {
            $migration->addField('glpi_plugin_notifier_preferences', 'notify_' . $slug . '_entity', 'bool', [
                'value' => 0,
            ]);
        }
        $migration->addField('glpi_plugin_notifier_preferences', 'desktop_enabled', 'bool', ['value' => 0]);
        $migration->addField('glpi_plugin_notifier_preferences', 'sound_enabled', 'bool', ['value' => 0]);
    }

    GlpiPlugin\Notifier\Note::ensureSchema();

    // Drop legacy RBAC artefacts from earlier versions.
    if ($DB->tableExists('glpi_plugin_notifier_profiles')) {
        $DB->doQueryOrDie("DROP TABLE `glpi_plugin_notifier_profiles`", $DB->error());
    }
    $DB->delete('glpi_profilerights', ['name' => 'plugin_notifier_notification']);

    $migration->executeMigration();

    NotifierConfig::install();

    CronTask::register(
        Notification::class,
        'NotifierCleanup',
        DAY_TIMESTAMP,
        [
            'comment' => 'Purge notifications older than the configured retention window',
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
        ]
    );

    CronTask::register(
        Notification::class,
        'NotifierDeadline',
        15 * MINUTE_TIMESTAMP,
        [
            'comment' => 'Warn assignees about approaching and breached resolution deadlines',
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
        ]
    );

    return true;
}

function plugin_notifier_uninstall(): bool
{
    global $DB;

    $tables = [
        'glpi_plugin_notifier_notifications',
        'glpi_plugin_notifier_preferences',
        'glpi_plugin_notifier_notes',
        'glpi_plugin_notifier_profiles', // legacy
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQueryOrDie("DROP TABLE `{$table}`", $DB->error());
        }
    }

    $DB->delete('glpi_profilerights', ['name' => 'plugin_notifier_notification']);

    // unregister() matches a "Plugin<Name>" prefix, which never hits a
    // namespaced class.
    CronTask::unregister('notifier');
    $DB->delete('glpi_crontasks', ['itemtype' => Notification::class]);

    NotifierConfig::uninstall();

    return true;
}

/**
 * Without this, every pre-1.0.4 row reads as entity 0 and vanishes for
 * anyone whose profile does not include the root entity.
 */
function plugin_notifier_backfill_entities(): void
{
    global $DB;

    $sources = [
        'Ticket'      => 'glpi_tickets',
        'Change'      => 'glpi_changes',
        'Problem'     => 'glpi_problems',
        'ProjectTask' => 'glpi_projecttasks',
    ];

    foreach ($sources as $itemtype => $table) {
        if (!$DB->tableExists($table)) {
            continue;
        }
        // Raw UPDATE ... JOIN the builder cannot express; $itemtype/$table come from the fixed map above.
        // Driven by the (itemtype, items_id) index.
        $DB->doQuery(
            'UPDATE `glpi_plugin_notifier_notifications` AS `n`'
            . ' INNER JOIN `' . $table . '` AS `s` ON `s`.`id` = `n`.`items_id`'
            . ' SET `n`.`entities_id` = `s`.`entities_id`'
            . " WHERE `n`.`itemtype` = '" . $itemtype . "'"
        );
    }
}

function plugin_notifier_check_prerequisites(): bool
{
    return true;
}

function plugin_notifier_check_config(bool $verbose = false): bool
{
    return true;
}
