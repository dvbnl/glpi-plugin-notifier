<?php

namespace GlpiPlugin\Notifier;

use CommonDBTM;
use CommonITILObject;
use CommonITILActor;
use Session;
use Ticket;
use Change;
use Problem;
use ProjectTask;
use ProjectTaskTeam;
use ITILFollowup;
use TicketTask;
use ChangeTask;
use ProblemTask;
use ITILSolution;
use Ticket_User;
use Change_User;
use Problem_User;
use Group_User;
use User;
use Toolbox;
use QueryExpression;

/**
 * Persistent store for in-app bell notifications. setup.php wires this
 * into GLPI's item_add / item_update hooks and the dispatcher fans the
 * event out to every affected user.
 */
class Notification extends CommonDBTM
{
    public static $rightname = '';
    public $dohistory        = false;

    // Slugs double as CSS modifier and i18n key — keep short.
    const EVENT_ASSIGNED       = 'assigned';
    const EVENT_CREATED        = 'created';
    const EVENT_COMMENTED      = 'commented';
    const EVENT_TASK_ADDED     = 'task_added';
    const EVENT_SOLUTION       = 'solution';
    const EVENT_STATUS_CHANGED = 'status_changed';
    const EVENT_UPDATED        = 'updated';

    public static function getTypeName($nb = 0): string
    {
        return _n('Notification', 'Notifications', $nb, 'notifier');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_notifier_notifications';
    }

    // ------------------------------------------------------------------ preferences
    //
    // Per-user opt-out flags in glpi_plugin_notifier_preferences, missing
    // row = all defaults. Preferences are a *view filter*, not a
    // subscription — every event is still stored, the filter applies at
    // read time so flipping a flag back on resurfaces history.

    public static function getDefaultPreferences(): array
    {
        return [
            'notify_ticket_direct'      => 1,
            'notify_ticket_group'       => 1,
            'notify_change_direct'      => 1,
            'notify_change_group'       => 1,
            'notify_problem_direct'     => 1,
            'notify_problem_group'      => 1,
            'notify_projecttask_direct' => 1,
            'notify_projecttask_group'  => 1,
        ];
    }

    /** @var array<int, array<string, int>> per-request memo for getPreferences() */
    private static array $prefsCache = [];

    private static bool $schemaEnsured = false;

    // Idempotent runtime safety net for installs that predate the table;
    // re-running plugin install via the UI is the canonical upgrade path.
    private static function ensurePreferencesTable(): bool
    {
        global $DB;

        if ($DB->tableExists('glpi_plugin_notifier_preferences')) {
            return true;
        }

        $charset   = \DBConnection::getDefaultCharset();
        $collation = \DBConnection::getDefaultCollation();

        $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_notifier_preferences` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";

        return (bool)$DB->doQuery($query);
    }

    public static function getPreferences(int $users_id): array
    {
        global $DB;

        if (isset(self::$prefsCache[$users_id])) {
            return self::$prefsCache[$users_id];
        }

        $prefs = self::getDefaultPreferences();
        if ($users_id <= 0 || !$DB->tableExists('glpi_plugin_notifier_preferences')) {
            return self::$prefsCache[$users_id] = $prefs;
        }

        $rs = $DB->request([
            'FROM'  => 'glpi_plugin_notifier_preferences',
            'WHERE' => ['users_id' => $users_id],
            'LIMIT' => 1,
        ]);
        $row = $rs->current();
        if (!$row) {
            return self::$prefsCache[$users_id] = $prefs;
        }
        foreach ($prefs as $k => $_default) {
            if (array_key_exists($k, $row)) {
                $prefs[$k] = (int)$row[$k] ? 1 : 0;
            }
        }
        return self::$prefsCache[$users_id] = $prefs;
    }

    public static function savePreferences(int $users_id, array $input): bool
    {
        global $DB;

        if ($users_id <= 0) {
            return false;
        }

        if (!self::ensurePreferencesTable()) {
            return false;
        }

        $allowed = array_keys(self::getDefaultPreferences());
        $row = ['users_id' => $users_id];
        foreach ($allowed as $col) {
            $row[$col] = isset($input[$col]) && (int)$input[$col] ? 1 : 0;
        }
        $row['date_mod'] = date('Y-m-d H:i:s');

        // Upsert via delete+insert — single-row PK keeps it cheap.
        $DB->delete('glpi_plugin_notifier_preferences', ['users_id' => $users_id]);
        $DB->insert('glpi_plugin_notifier_preferences', $row);

        unset(self::$prefsCache[$users_id]);
        return true;
    }

    /**
     * WHERE fragment that excludes (itemtype, channel) combos the user
     * has opted out of. Returns null when no filter applies.
     */
    private static function prefFilterExpression(int $users_id): ?QueryExpression
    {
        $prefs = self::getPreferences($users_id);

        $typeMap = [
            'ticket'      => 'Ticket',
            'change'      => 'Change',
            'problem'     => 'Problem',
            'projecttask' => 'ProjectTask',
        ];

        // Pre-channel rows carry channel='' and can't be backfilled.
        // Full opt-out hides them too; partial opt-out leaves them
        // visible since we can't tell which channel they belonged to.
        $disabled = [];
        foreach ($typeMap as $slug => $itemtype) {
            $directOff = empty($prefs['notify_' . $slug . '_direct']);
            $groupOff  = empty($prefs['notify_' . $slug . '_group']);

            if ($directOff && $groupOff) {
                $disabled[] = "(`itemtype` = '{$itemtype}')";
            } elseif ($directOff) {
                $disabled[] = "(`itemtype` = '{$itemtype}' AND `channel` = 'direct')";
            } elseif ($groupOff) {
                $disabled[] = "(`itemtype` = '{$itemtype}' AND `channel` = 'group')";
            }
        }

        if (empty($disabled)) {
            return null;
        }

        return new QueryExpression('NOT (' . implode(' OR ', $disabled) . ')');
    }

    // ------------------------------------------------------------------ event dispatch

    public static function handleItemEvent($item): void
    {
        if (!is_object($item) || !isset($item->fields['id'])) {
            return;
        }

        $type = $item::getType();

        if (in_array($type, ['Ticket', 'Change', 'Problem'], true)) {
            self::handleItilParent($item);
            return;
        }

        if ($type === 'ProjectTask') {
            self::handleProjectTask($item);
            return;
        }

        if ($type === 'ITILFollowup') {
            self::handleFollowup($item);
            return;
        }

        if (in_array($type, ['TicketTask', 'ChangeTask', 'ProblemTask'], true)) {
            self::handleItilTask($item);
            return;
        }

        if ($type === 'ITILSolution') {
            self::handleSolution($item);
            return;
        }

        $itilUserMap = [
            'Ticket_User'  => ['parent' => 'Ticket',  'fk' => 'tickets_id'],
            'Change_User'  => ['parent' => 'Change',  'fk' => 'changes_id'],
            'Problem_User' => ['parent' => 'Problem', 'fk' => 'problems_id'],
        ];
        if (isset($itilUserMap[$type])) {
            self::handleItilUserLink($item, $itilUserMap[$type]['parent'], $itilUserMap[$type]['fk']);
            return;
        }

        $itilGroupMap = [
            'Group_Ticket'  => ['parent' => 'Ticket',  'fk' => 'tickets_id'],
            'Change_Group'  => ['parent' => 'Change',  'fk' => 'changes_id'],
            'Group_Problem' => ['parent' => 'Problem', 'fk' => 'problems_id'],
        ];
        if (isset($itilGroupMap[$type])) {
            self::handleItilGroupLink($item, $itilGroupMap[$type]['parent'], $itilGroupMap[$type]['fk']);
            return;
        }

        if ($type === 'ProjectTaskTeam') {
            self::handleProjectTaskTeamLink($item);
            return;
        }
    }

    private static function handleItilParent(CommonDBTM $item): void
    {
        $type = $item::getType();
        $id   = (int)$item->fields['id'];
        // CommonDBTM leaves $item->updates empty on item_add.
        $isCreate = empty($item->updates ?? []);

        $updates = $item->updates ?? [];
        $watchedFields = ['status', 'content', 'name', 'priority', 'urgency', 'users_id_lastupdater'];
        $relevant = array_intersect($updates, $watchedFields);

        $targets = self::collectActorsForItil($item);
        unset($targets[(int)Session::getLoginUserID()]);

        if (empty($targets)) {
            return;
        }

        $title   = self::formatItemTitle($item);
        $baseUrl = $item::getFormURLWithID($id, false);

        if ($isCreate) {
            foreach ($targets as $uid => $channel) {
                self::insert([
                    'users_id' => $uid,
                    'itemtype' => $type,
                    'items_id' => $id,
                    'event'    => self::EVENT_CREATED,
                    'channel'  => $channel,
                    'title'    => $title,
                    'message'  => __('New item concerning you', 'notifier'),
                    'url'      => $baseUrl,
                ]);
            }
            return;
        }

        if (in_array('status', $relevant, true)) {
            $event   = self::EVENT_STATUS_CHANGED;
            $message = __('Status changed', 'notifier');
        } elseif (!empty($relevant)) {
            $event   = self::EVENT_UPDATED;
            $message = __('Item updated', 'notifier');
        } else {
            return;
        }

        foreach ($targets as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => $type,
                'items_id' => $id,
                'event'    => $event,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => $message,
                'url'      => $baseUrl,
            ]);
        }
    }

    private static function handleProjectTask(CommonDBTM $item): void
    {
        $id       = (int)$item->fields['id'];
        $isCreate = empty($item->updates);

        // ProjectTask uses its own team junction, not the ITIL actor pattern.
        $targets = self::collectProjectTaskMembers($id);
        unset($targets[(int)Session::getLoginUserID()]);

        if (empty($targets)) {
            return;
        }

        $title   = self::formatItemTitle($item);
        $baseUrl = ProjectTask::getFormURLWithID($id, false);

        if ($isCreate) {
            $event   = self::EVENT_CREATED;
            $message = __('New project task concerning you', 'notifier');
        } else {
            $updates = $item->updates ?? [];
            if (empty($updates)) {
                return;
            }
            if (in_array('projectstates_id', $updates, true) || in_array('percent_done', $updates, true)) {
                $event   = self::EVENT_STATUS_CHANGED;
                $message = __('Status changed', 'notifier');
            } else {
                $event   = self::EVENT_UPDATED;
                $message = __('Project task updated', 'notifier');
            }
        }

        foreach ($targets as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => 'ProjectTask',
                'items_id' => $id,
                'event'    => $event,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => $message,
                'url'      => $baseUrl,
            ]);
        }
    }

    private static function handleFollowup(CommonDBTM $item): void
    {
        $parentType = $item->fields['itemtype'] ?? '';
        $parentId   = (int)($item->fields['items_id'] ?? 0);
        if ($parentType === '' || $parentId === 0) {
            return;
        }
        if (!class_exists($parentType)) {
            return;
        }
        $parent = new $parentType();
        if (!$parent->getFromDB($parentId)) {
            return;
        }

        $targets = self::collectActorsForItil($parent);
        unset($targets[(int)Session::getLoginUserID()]);
        if (empty($targets)) {
            return;
        }

        $title   = self::formatItemTitle($parent);
        $baseUrl = $parentType::getFormURLWithID($parentId, false);

        foreach ($targets as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => $parentType,
                'items_id' => $parentId,
                'event'    => self::EVENT_COMMENTED,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => __('New comment', 'notifier'),
                'url'      => $baseUrl,
            ]);
        }
    }

    private static function handleItilTask(CommonDBTM $item): void
    {
        $type = $item::getType();
        $map  = [
            'TicketTask'  => ['parent' => 'Ticket',  'fk' => 'tickets_id'],
            'ChangeTask'  => ['parent' => 'Change',  'fk' => 'changes_id'],
            'ProblemTask' => ['parent' => 'Problem', 'fk' => 'problems_id'],
        ];
        if (!isset($map[$type])) {
            return;
        }

        $parentType = $map[$type]['parent'];
        $parentId   = (int)($item->fields[$map[$type]['fk']] ?? 0);
        if ($parentId === 0) {
            return;
        }

        $parent = new $parentType();
        if (!$parent->getFromDB($parentId)) {
            return;
        }

        $targets = self::collectActorsForItil($parent);

        // A named tech is always notified, marked 'direct' so a group-only
        // opt-out can't silence them.
        if (!empty($item->fields['users_id_tech'])) {
            $targets[(int)$item->fields['users_id_tech']] = 'direct';
        }

        unset($targets[(int)Session::getLoginUserID()]);
        if (empty($targets)) {
            return;
        }

        $title   = self::formatItemTitle($parent);
        $baseUrl = $parentType::getFormURLWithID($parentId, false);

        foreach ($targets as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => $parentType,
                'items_id' => $parentId,
                'event'    => self::EVENT_TASK_ADDED,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => __('New task', 'notifier'),
                'url'      => $baseUrl,
            ]);
        }
    }

    private static function handleSolution(CommonDBTM $item): void
    {
        $parentType = $item->fields['itemtype'] ?? '';
        $parentId   = (int)($item->fields['items_id'] ?? 0);
        if ($parentType === '' || $parentId === 0 || !class_exists($parentType)) {
            return;
        }

        $parent = new $parentType();
        if (!$parent->getFromDB($parentId)) {
            return;
        }

        $targets = self::collectActorsForItil($parent);
        unset($targets[(int)Session::getLoginUserID()]);
        if (empty($targets)) {
            return;
        }

        $title   = self::formatItemTitle($parent);
        $baseUrl = $parentType::getFormURLWithID($parentId, false);

        foreach ($targets as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => $parentType,
                'items_id' => $parentId,
                'event'    => self::EVENT_SOLUTION,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => __('Solution proposed', 'notifier'),
                'url'      => $baseUrl,
            ]);
        }
    }

    // Only ASSIGN (CommonITILActor::ASSIGN = 2) gets a bell — requesters
    // and observers are noise during ticket creation. Hard-coded to avoid
    // a use-statement dependency in hook context.
    private static function handleItilUserLink(CommonDBTM $item, string $parentType, string $fk): void
    {
        $linkType = (int)($item->fields['type'] ?? 0);
        if ($linkType !== 2) {
            return;
        }

        $targetUser = (int)($item->fields['users_id'] ?? 0);
        $parentId   = (int)($item->fields[$fk] ?? 0);
        if ($targetUser <= 0 || $parentId <= 0) {
            return;
        }

        if ($targetUser === (int)Session::getLoginUserID()) {
            return;
        }

        $parent = new $parentType();
        if (!$parent->getFromDB($parentId)) {
            return;
        }

        self::insert([
            'users_id' => $targetUser,
            'itemtype' => $parentType,
            'items_id' => $parentId,
            'event'    => self::EVENT_ASSIGNED,
            'channel'  => 'direct',
            'title'    => self::formatItemTitle($parent),
            'message'  => __('You have been assigned', 'notifier'),
            'url'      => $parentType::getFormURLWithID($parentId, false),
        ]);
    }

    private static function handleItilGroupLink(CommonDBTM $item, string $parentType, string $fk): void
    {
        global $DB;

        $linkType = (int)($item->fields['type'] ?? 0);
        if ($linkType !== 2) {
            return;
        }

        $groupId  = (int)($item->fields['groups_id'] ?? 0);
        $parentId = (int)($item->fields[$fk] ?? 0);
        if ($groupId <= 0 || $parentId <= 0) {
            return;
        }

        $parent = new $parentType();
        if (!$parent->getFromDB($parentId)) {
            return;
        }

        $rs = $DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => 'glpi_groups_users',
            'WHERE'  => ['groups_id' => $groupId],
        ]);

        $title   = self::formatItemTitle($parent);
        $baseUrl = $parentType::getFormURLWithID($parentId, false);
        $actor   = (int)Session::getLoginUserID();

        foreach ($rs as $row) {
            $uid = (int)$row['users_id'];
            if ($uid <= 0 || $uid === $actor) {
                continue;
            }
            self::insert([
                'users_id' => $uid,
                'itemtype' => $parentType,
                'items_id' => $parentId,
                'event'    => self::EVENT_ASSIGNED,
                'channel'  => 'group',
                'title'    => $title,
                'message'  => __('Your group has been assigned', 'notifier'),
                'url'      => $baseUrl,
            ]);
        }
    }

    private static function handleProjectTaskTeamLink(CommonDBTM $item): void
    {
        global $DB;

        $taskId = (int)($item->fields['projecttasks_id'] ?? 0);
        $memberType = (string)($item->fields['itemtype'] ?? '');
        $memberId   = (int)($item->fields['items_id'] ?? 0);
        if ($taskId <= 0 || $memberId <= 0) {
            return;
        }

        $task = new ProjectTask();
        if (!$task->getFromDB($taskId)) {
            return;
        }

        $actor = (int)Session::getLoginUserID();
        $targets = [];

        if ($memberType === 'User') {
            if ($memberId !== $actor) {
                $targets[$memberId] = 'direct';
            }
        } elseif ($memberType === 'Group') {
            $rs = $DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => 'glpi_groups_users',
                'WHERE'  => ['groups_id' => $memberId],
            ]);
            foreach ($rs as $row) {
                $uid = (int)$row['users_id'];
                if ($uid > 0 && $uid !== $actor) {
                    $targets[$uid] = 'group';
                }
            }
        }

        if (empty($targets)) {
            return;
        }

        $title   = self::formatItemTitle($task);
        $baseUrl = ProjectTask::getFormURLWithID($taskId, false);

        foreach ($targets as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => 'ProjectTask',
                'items_id' => $taskId,
                'event'    => self::EVENT_ASSIGNED,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => __('You have been added to a project task', 'notifier'),
                'url'      => $baseUrl,
            ]);
        }
    }

    // ------------------------------------------------------------------ actor collection

    /**
     * Returns [user_id => channel] where channel is 'direct' (personal
     * actor) or 'group' (via group link). Direct beats group when both
     * apply, so a group-only opt-out can't silence a personal actor.
     */
    private static function collectActorsForItil(CommonDBTM $item): array
    {
        global $DB;

        $type = $item::getType();
        $id   = (int)$item->fields['id'];

        $linkMap = [
            'Ticket'  => ['users' => 'glpi_tickets_users',  'groups' => 'glpi_groups_tickets',  'fk' => 'tickets_id'],
            'Change'  => ['users' => 'glpi_changes_users',  'groups' => 'glpi_changes_groups',  'fk' => 'changes_id'],
            'Problem' => ['users' => 'glpi_problems_users', 'groups' => 'glpi_groups_problems', 'fk' => 'problems_id'],
        ];
        if (!isset($linkMap[$type])) {
            return [];
        }

        $users  = [];
        $groups = [];

        $rs = $DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => $linkMap[$type]['users'],
            'WHERE'  => [$linkMap[$type]['fk'] => $id],
        ]);
        foreach ($rs as $row) {
            $uid = (int)$row['users_id'];
            if ($uid > 0) {
                $users[$uid] = 'direct';
            }
        }

        $rs = $DB->request([
            'SELECT' => ['groups_id'],
            'FROM'   => $linkMap[$type]['groups'],
            'WHERE'  => [$linkMap[$type]['fk'] => $id],
        ]);
        foreach ($rs as $row) {
            $gid = (int)$row['groups_id'];
            if ($gid > 0) {
                $groups[$gid] = $gid;
            }
        }

        if (!empty($groups)) {
            $rs = $DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => 'glpi_groups_users',
                'WHERE'  => ['groups_id' => array_values($groups)],
            ]);
            foreach ($rs as $row) {
                $uid = (int)$row['users_id'];
                if ($uid > 0 && !isset($users[$uid])) {
                    $users[$uid] = 'group';
                }
            }
        }

        return $users;
    }

    private static function collectProjectTaskMembers(int $taskId): array
    {
        global $DB;

        $users  = [];
        $groups = [];

        $rs = $DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM'   => 'glpi_projecttaskteams',
            'WHERE'  => ['projecttasks_id' => $taskId],
        ]);
        foreach ($rs as $row) {
            if ($row['itemtype'] === 'User') {
                $uid = (int)$row['items_id'];
                if ($uid > 0) {
                    $users[$uid] = 'direct';
                }
            } elseif ($row['itemtype'] === 'Group') {
                $gid = (int)$row['items_id'];
                if ($gid > 0) {
                    $groups[$gid] = $gid;
                }
            }
        }

        if (!empty($groups)) {
            $rs = $DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => 'glpi_groups_users',
                'WHERE'  => ['groups_id' => array_values($groups)],
            ]);
            foreach ($rs as $row) {
                $uid = (int)$row['users_id'];
                if ($uid > 0 && !isset($users[$uid])) {
                    $users[$uid] = 'group';
                }
            }
        }

        return $users;
    }

    private static function formatItemTitle(CommonDBTM $item): string
    {
        $type = $item::getType();
        $id   = (int)$item->fields['id'];
        $name = (string)($item->fields['name'] ?? '');
        if ($name === '') {
            $name = '#' . $id;
        }
        $name = Toolbox::substr($name, 0, 180);
        return sprintf('[%s #%d] %s', $type, $id, $name);
    }

    // ------------------------------------------------------------------ insert / read / cleanup

    // Lazy migration: adds the `channel` column on installs that predate
    // read-time filtering. Once-per-request, no-op after the first call.
    private static function ensureNotificationsSchema(): void
    {
        global $DB;

        if (self::$schemaEnsured) {
            return;
        }
        self::$schemaEnsured = true;

        if (!$DB->tableExists('glpi_plugin_notifier_notifications')) {
            return;
        }
        if ($DB->fieldExists('glpi_plugin_notifier_notifications', 'channel')) {
            return;
        }
        $DB->doQuery(
            "ALTER TABLE `glpi_plugin_notifier_notifications`
             ADD COLUMN `channel` VARCHAR(10) NOT NULL DEFAULT '' AFTER `event`"
        );
    }

    /**
     * Insert a notification row, deduplicated against the most recent
     * unread row for the same user/item/event in the last 60 seconds —
     * a single form save can fire several hooks and we don't want spam.
     */
    public static function insert(array $data): void
    {
        global $DB;

        $users_id = (int)($data['users_id'] ?? 0);
        $itemtype = (string)($data['itemtype'] ?? '');
        $items_id = (int)($data['items_id'] ?? 0);
        $event    = (string)($data['event'] ?? '');
        $channel  = (string)($data['channel'] ?? '');

        if ($users_id <= 0 || $itemtype === '' || $items_id === 0 || $event === '') {
            return;
        }

        self::ensureNotificationsSchema();

        $recent = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_notifier_notifications',
            'WHERE'  => [
                'users_id'      => $users_id,
                'itemtype'      => $itemtype,
                'items_id'      => $items_id,
                'event'         => $event,
                'is_read'       => 0,
                'date_creation' => ['>', date('Y-m-d H:i:s', time() - 60)],
            ],
            'LIMIT'  => 1,
        ]);
        if (count($recent) > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $DB->insert('glpi_plugin_notifier_notifications', [
            'users_id'       => $users_id,
            'actor_users_id' => (int)Session::getLoginUserID(),
            'itemtype'       => $itemtype,
            'items_id'       => $items_id,
            'event'          => $event,
            'channel'        => $channel,
            'title'          => (string)($data['title'] ?? ''),
            'message'        => (string)($data['message'] ?? ''),
            'url'            => (string)($data['url'] ?? ''),
            'is_read'        => 0,
            'date_creation'  => $now,
            'date_mod'       => $now,
        ]);
    }

    public static function getForUser(int $users_id, int $limit = 25): array
    {
        global $DB;

        self::ensureNotificationsSchema();

        $where = ['users_id' => $users_id];
        $filter = self::prefFilterExpression($users_id);
        if ($filter !== null) {
            $where[] = $filter;
        }

        $rs = $DB->request([
            'FROM'     => 'glpi_plugin_notifier_notifications',
            'WHERE'    => $where,
            'ORDER'    => ['is_read ASC', 'date_creation DESC'],
            'LIMIT'    => $limit,
        ]);

        $rows = [];
        foreach ($rs as $row) {
            $rows[] = [
                'id'            => (int)$row['id'],
                'itemtype'      => $row['itemtype'],
                'items_id'      => (int)$row['items_id'],
                'event'         => $row['event'],
                'title'         => $row['title'],
                'message'       => $row['message'],
                'url'           => $row['url'],
                'is_read'       => (bool)$row['is_read'],
                'actor_name'    => self::actorName((int)$row['actor_users_id']),
                'date_creation' => $row['date_creation'],
            ];
        }
        return $rows;
    }

    public static function countUnread(int $users_id): int
    {
        global $DB;

        self::ensureNotificationsSchema();

        $where = [
            'users_id' => $users_id,
            'is_read'  => 0,
        ];
        $filter = self::prefFilterExpression($users_id);
        if ($filter !== null) {
            $where[] = $filter;
        }

        $rs = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_plugin_notifier_notifications',
            'WHERE' => $where,
        ]);
        $row = $rs->current();
        return (int)($row['cpt'] ?? 0);
    }

    /**
     * Number of unique source items with at least one unread row — what
     * the bell badge shows. Mirrors the JS-side groupKey().
     */
    public static function countUnreadGroups(int $users_id): int
    {
        global $DB;

        self::ensureNotificationsSchema();

        $where = [
            'users_id' => $users_id,
            'is_read'  => 0,
        ];
        $filter = self::prefFilterExpression($users_id);
        if ($filter !== null) {
            $where[] = $filter;
        }

        $rs = $DB->request([
            'SELECT' => [new QueryExpression('COUNT(DISTINCT `itemtype`, `items_id`) AS cpt')],
            'FROM'   => 'glpi_plugin_notifier_notifications',
            'WHERE'  => $where,
        ]);
        $row = $rs->current();
        return (int)($row['cpt'] ?? 0);
    }

    public static function markRead(int $id, int $users_id): bool
    {
        global $DB;
        if ($id <= 0 || $users_id <= 0) {
            return false;
        }
        return (bool)$DB->update(
            'glpi_plugin_notifier_notifications',
            ['is_read' => 1, 'date_mod' => date('Y-m-d H:i:s')],
            ['id' => $id, 'users_id' => $users_id]
        );
    }

    public static function markUnread(int $id, int $users_id): bool
    {
        global $DB;
        if ($id <= 0 || $users_id <= 0) {
            return false;
        }
        return (bool)$DB->update(
            'glpi_plugin_notifier_notifications',
            ['is_read' => 0, 'date_mod' => date('Y-m-d H:i:s')],
            ['id' => $id, 'users_id' => $users_id]
        );
    }

    public static function markAllRead(int $users_id): bool
    {
        global $DB;
        if ($users_id <= 0) {
            return false;
        }
        return (bool)$DB->update(
            'glpi_plugin_notifier_notifications',
            ['is_read' => 1, 'date_mod' => date('Y-m-d H:i:s')],
            ['users_id' => $users_id, 'is_read' => 0]
        );
    }

    // PLUGIN_HOOKS item_purge — keeps the bell from dangling.
    public static function cleanForItem($item): void
    {
        global $DB;
        if (!is_object($item) || !isset($item->fields['id'])) {
            return;
        }
        $DB->delete('glpi_plugin_notifier_notifications', [
            'itemtype' => $item::getType(),
            'items_id' => (int)$item->fields['id'],
        ]);
    }

    private static function actorName(int $users_id): string
    {
        if ($users_id <= 0) {
            return '';
        }
        $user = new User();
        if (!$user->getFromDB($users_id)) {
            return '';
        }
        $full = trim(($user->fields['firstname'] ?? '') . ' ' . ($user->fields['realname'] ?? ''));
        return $full !== '' ? $full : (string)($user->fields['name'] ?? '');
    }
}
