<?php

/**
 * Notifier Plugin - Install/Uninstall hooks
 */


/**
 * Plugin install process
 *
 * @return boolean
 */
function plugin_notifier_install(): bool
{
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $migration         = new Migration(PLUGIN_NOTIFIER_VERSION);

    // =========================================================================
    // Table: glpi_plugin_notifier_notifications
    //
    // One row per bell notification. `users_id` is the recipient (not the
    // actor). `itemtype` + `items_id` point at the GLPI object the
    // notification is about, so clicking in the bell can redirect to it.
    // `event` is a short slug (assigned / commented / status_changed / ...).
    // `actor_users_id` is who triggered it, so we can render "John updated
    // ticket #42" without another join at render time.
    // =========================================================================
    if (!$DB->tableExists('glpi_plugin_notifier_notifications')) {
        $query = "CREATE TABLE `glpi_plugin_notifier_notifications` (
            `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Recipient',
            `actor_users_id`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Who triggered the event',
            `itemtype`        VARCHAR(100) NOT NULL DEFAULT '',
            `items_id`        INT UNSIGNED NOT NULL DEFAULT 0,
            `event`           VARCHAR(50) NOT NULL DEFAULT '',
            `channel`         VARCHAR(10) NOT NULL DEFAULT '' COMMENT 'direct | group — used by the preferences filter at read time',
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
        // Upgrade path: the channel column was introduced when preferences
        // moved from insert-time gating to read-time filtering. Existing
        // rows get an empty channel and therefore bypass the filter, which
        // is the right default — they were already allowed through by the
        // old insert-time check.
        $DB->doQueryOrDie(
            "ALTER TABLE `glpi_plugin_notifier_notifications`
             ADD COLUMN `channel` VARCHAR(10) NOT NULL DEFAULT '' AFTER `event`",
            $DB->error()
        );
    }

    // =========================================================================
    // Table: glpi_plugin_notifier_preferences
    //
    // Per-user fine-grained opt-out flags. One row per user; each column is
    // a boolean for "should this user receive notifications of category X".
    // All flags default to 1 (opt-out model) so a fresh install matches the
    // pre-preferences behaviour: every signal fans out to every actor.
    //
    // Naming: notify_<itemtype_slug>_<channel>
    //   <itemtype_slug>: ticket | change | problem | projecttask
    //   <channel>:       direct  → assignee / requester / observer / watcher
    //                    group   → member of a group linked to the item
    //
    // Missing row is treated as "all defaults (1)" — we never force-insert.
    // =========================================================================
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

    // Clean up legacy profile table from earlier RBAC versions. The plugin
    // no longer does right-based gating — every logged-in user sees their
    // own bell — so the junction table is dead weight.
    if ($DB->tableExists('glpi_plugin_notifier_profiles')) {
        $DB->doQueryOrDie("DROP TABLE `glpi_plugin_notifier_profiles`", $DB->error());
    }
    // Drop legacy profile-right rows too, in case installRights() seeded them
    // before the RBAC removal.
    $DB->delete('glpi_profilerights', ['name' => 'plugin_notifier_notification']);

    $migration->executeMigration();

    return true;
}

/**
 * Plugin uninstall process
 *
 * @return boolean
 */
function plugin_notifier_uninstall(): bool
{
    global $DB;

    $tables = [
        'glpi_plugin_notifier_notifications',
        'glpi_plugin_notifier_preferences',
        // Legacy — harmless if it doesn't exist.
        'glpi_plugin_notifier_profiles',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQueryOrDie("DROP TABLE `{$table}`", $DB->error());
        }
    }

    // Legacy profile-right rows.
    $DB->delete('glpi_profilerights', ['name' => 'plugin_notifier_notification']);

    return true;
}

/**
 * Check prerequisites before install
 *
 * @return boolean
 */
function plugin_notifier_check_prerequisites(): bool
{
    return true;
}

/**
 * Check configuration
 *
 * @param bool $verbose
 * @return boolean
 */
function plugin_notifier_check_config(bool $verbose = false): bool
{
    return true;
}
