<?php

// Notifier — install / uninstall hooks.

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
            `itemtype`        VARCHAR(100) NOT NULL DEFAULT '',
            `items_id`        INT UNSIGNED NOT NULL DEFAULT 0,
            `event`           VARCHAR(50) NOT NULL DEFAULT '',
            `channel`         VARCHAR(10) NOT NULL DEFAULT '' COMMENT 'direct | group',
            `title`           VARCHAR(255) NOT NULL DEFAULT '',
            `message`         TEXT,
            `url`             VARCHAR(500) NOT NULL DEFAULT '',
            `is_read`         TINYINT NOT NULL DEFAULT 0,
            `date_creation`   TIMESTAMP NULL DEFAULT NULL,
            `date_mod`        TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `actor_users_id` (`actor_users_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `is_read` (`is_read`),
            KEY `user_unread` (`users_id`, `is_read`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    } elseif (!$DB->fieldExists('glpi_plugin_notifier_notifications', 'channel')) {
        // Upgrade path: pre-channel rows get '' and bypass the read-time
        // filter — that mirrors the old insert-time gating behaviour.
        $DB->doQueryOrDie(
            "ALTER TABLE `glpi_plugin_notifier_notifications`
             ADD COLUMN `channel` VARCHAR(10) NOT NULL DEFAULT '' AFTER `event`",
            $DB->error()
        );
    }

    // Opt-out preferences: missing row = all-on. notify_<type>_<channel>.
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
            `date_mod`                    TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    }

    // Drop legacy RBAC artefacts from earlier versions.
    if ($DB->tableExists('glpi_plugin_notifier_profiles')) {
        $DB->doQueryOrDie("DROP TABLE `glpi_plugin_notifier_profiles`", $DB->error());
    }
    $DB->delete('glpi_profilerights', ['name' => 'plugin_notifier_notification']);

    $migration->executeMigration();

    return true;
}

function plugin_notifier_uninstall(): bool
{
    global $DB;

    $tables = [
        'glpi_plugin_notifier_notifications',
        'glpi_plugin_notifier_preferences',
        'glpi_plugin_notifier_profiles', // legacy
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQueryOrDie("DROP TABLE `{$table}`", $DB->error());
        }
    }

    $DB->delete('glpi_profilerights', ['name' => 'plugin_notifier_notification']);

    return true;
}

function plugin_notifier_check_prerequisites(): bool
{
    return true;
}

function plugin_notifier_check_config(bool $verbose = false): bool
{
    return true;
}
