<?php

namespace GlpiPlugin\Notifier;

use QueryExpression;

/**
 * Personal to-do list living in the bell. Deliberately not routed through
 * the notifications table: a note has no entity, no actor and no source
 * item, so the entity filter and the event preferences do not apply to it.
 */
class Note
{
    public const MAX_LENGTH = 255;

    /** Keeps one user's list from growing without bound. */
    private const MAX_OPEN = 200;

    /** Ticked items stay visible for a while, then the cleanup cron drops them. */
    public const DONE_RETENTION_DAYS = 7;

    private static bool $schemaEnsured = false;

    public static function getTable(): string
    {
        return 'glpi_plugin_notifier_notes';
    }

    public static function ensureSchema(): bool
    {
        global $DB;

        if (self::$schemaEnsured) {
            return true;
        }
        if ($DB->tableExists(self::getTable())) {
            self::$schemaEnsured = true;
            return true;
        }

        $charset   = \DBConnection::getDefaultCharset();
        $collation = \DBConnection::getDefaultCollation();

        // Raw DDL: interpolate only hardcoded identifiers, never input.
        $ok = (bool)$DB->doQuery(
            'CREATE TABLE IF NOT EXISTS `' . self::getTable() . '` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `users_id`      INT UNSIGNED NOT NULL DEFAULT 0,
                `content`       VARCHAR(255) NOT NULL DEFAULT \'\',
                `remind_at`     TIMESTAMP NULL DEFAULT NULL,
                `notified_at`   TIMESTAMP NULL DEFAULT NULL,
                `is_done`       TINYINT NOT NULL DEFAULT 0,
                `itemtype`      VARCHAR(100) NOT NULL DEFAULT \'\',
                `items_id`      INT UNSIGNED NOT NULL DEFAULT 0,
                `url`           VARCHAR(500) NOT NULL DEFAULT \'\',
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod`      TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `owner` (`users_id`, `is_done`),
                KEY `due` (`remind_at`, `notified_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=' . $charset . ' COLLATE=' . $collation . ' ROW_FORMAT=DYNAMIC'
        );

        self::$schemaEnsured = $ok;
        return $ok;
    }

    /**
     * Open notes first, oldest deadline first, then the recently ticked
     * ones so undoing a mistake stays possible.
     */
    public static function getForUser(int $users_id): array
    {
        global $DB;

        if ($users_id <= 0 || !self::ensureSchema()) {
            return [];
        }

        $rs = $DB->request([
            'SELECT' => [
                'id', 'content', 'is_done', 'itemtype', 'items_id', 'url',
                new QueryExpression('UNIX_TIMESTAMP(`remind_at`) AS `remind_ts`'),
                new QueryExpression('UNIX_TIMESTAMP(`notified_at`) AS `notified_ts`'),
            ],
            'FROM'   => self::getTable(),
            'WHERE'  => ['users_id' => $users_id],
            'ORDER'  => ['is_done ASC', 'remind_at IS NULL ASC', 'remind_at ASC', 'id DESC'],
            'LIMIT'  => self::MAX_OPEN + 50,
        ]);

        $notes = [];
        foreach ($rs as $row) {
            $notes[] = [
                'id'          => (int)$row['id'],
                // Stored in whatever encoding the core uses; the client
                // wants plain text and escapes it again itself.
                'content'     => Text::toPlain((string)$row['content']),
                'is_done'     => (bool)$row['is_done'],
                'remind_ts'   => $row['remind_ts'] === null ? null : (int)$row['remind_ts'],
                'notified'    => $row['notified_ts'] !== null,
                'itemtype'    => (string)$row['itemtype'],
                'items_id'    => (int)$row['items_id'],
                'url'         => (string)$row['url'],
            ];
        }
        return $notes;
    }

    /** @return int|false the new id */
    public static function add(int $users_id, string $content, ?int $remindTs, array $link = [])
    {
        global $DB;

        if ($users_id <= 0 || !self::ensureSchema()) {
            return false;
        }

        $content = mb_substr(trim($content), 0, self::MAX_LENGTH);
        if (Text::toPlain($content) === '') {
            return false;
        }

        if (self::countOpen($users_id) >= self::MAX_OPEN) {
            return false;
        }

        $row = [
            'users_id'      => $users_id,
            'content'       => $content,
            'is_done'       => 0,
            'itemtype'      => (string)($link['itemtype'] ?? ''),
            'items_id'      => (int)($link['items_id'] ?? 0),
            'url'           => (string)($link['url'] ?? ''),
            'date_creation' => new QueryExpression('NOW()'),
            'date_mod'      => new QueryExpression('NOW()'),
        ];

        // Epoch in, epoch out: the browser and the database do not
        // necessarily agree on a timezone.
        $row['remind_at'] = $remindTs === null
            ? null
            : new QueryExpression('FROM_UNIXTIME(' . (int)$remindTs . ')');

        if (!$DB->insert(self::getTable(), $row)) {
            return false;
        }
        return (int)$DB->insertId();
    }

    public static function setDone(int $id, int $users_id, bool $done): bool
    {
        global $DB;

        if ($id <= 0 || $users_id <= 0 || !self::ensureSchema()) {
            return false;
        }

        return (bool)$DB->update(
            self::getTable(),
            ['is_done' => $done ? 1 : 0, 'date_mod' => new QueryExpression('NOW()')],
            ['id' => $id, 'users_id' => $users_id]
        );
    }

    /** Stops the browser re-announcing a reminder that has already fired. */
    public static function markNotified(int $id, int $users_id): bool
    {
        global $DB;

        if ($id <= 0 || $users_id <= 0 || !self::ensureSchema()) {
            return false;
        }

        return (bool)$DB->update(
            self::getTable(),
            ['notified_at' => new QueryExpression('NOW()')],
            ['id' => $id, 'users_id' => $users_id]
        );
    }

    public static function remove(int $id, int $users_id): bool
    {
        global $DB;

        if ($id <= 0 || $users_id <= 0 || !self::ensureSchema()) {
            return false;
        }

        return (bool)$DB->delete(self::getTable(), ['id' => $id, 'users_id' => $users_id]);
    }

    public static function countOpen(int $users_id): int
    {
        global $DB;

        if (!self::ensureSchema()) {
            return 0;
        }

        $rs  = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id, 'is_done' => 0],
        ]);
        $row = $rs->current();
        return (int)($row['cpt'] ?? 0);
    }

    /** Open notes whose reminder has passed — these drive the bell badge. */
    public static function countDue(int $users_id): int
    {
        global $DB;

        if (!self::ensureSchema()) {
            return 0;
        }

        $rs = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::getTable(),
            'WHERE' => [
                'users_id' => $users_id,
                'is_done'  => 0,
                new QueryExpression('`remind_at` IS NOT NULL AND `remind_at` <= NOW()'),
            ],
        ]);
        $row = $rs->current();
        return (int)($row['cpt'] ?? 0);
    }

    /**
     * Change detector for the feed ETag. The due count moves as reminders
     * mature, so a note coming due invalidates the cached response even
     * though no row was written.
     */
    public static function signature(int $users_id): string
    {
        global $DB;

        if (!self::ensureSchema()) {
            return '0';
        }

        $rs = $DB->request([
            'SELECT' => [
                new QueryExpression('COUNT(*) AS `total`'),
                new QueryExpression('COALESCE(MAX(`date_mod`), 0) AS `latest`'),
                new QueryExpression('SUM(`is_done` = 0 AND `remind_at` IS NOT NULL AND `remind_at` <= NOW()) AS `due`'),
            ],
            'FROM'   => self::getTable(),
            'WHERE'  => ['users_id' => $users_id],
        ]);
        $row = $rs->current() ?: [];

        return ($row['total'] ?? 0) . '.' . ($row['latest'] ?? 0) . '.' . ($row['due'] ?? 0);
    }

    public static function purgeDone(int $days = self::DONE_RETENTION_DAYS): int
    {
        global $DB;

        if (!self::ensureSchema()) {
            return 0;
        }

        $DB->delete(self::getTable(), [
            'is_done' => 1,
            new QueryExpression('`date_mod` < NOW() - INTERVAL ' . (int)$days . ' DAY'),
        ]);

        return $DB->affectedRows();
    }
}
