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
 * Notification - Persistent store for in-app bell notifications.
 *
 * Each row is "X happened, Y should see a bell badge for it". The class
 * is wired from setup.php into GLPI's item_add / item_update hooks for
 * every ITIL-ish type we care about and fans the event out to every
 * affected user.
 */
class Notification extends CommonDBTM
{
    // Every authenticated user sees their own bell — no right gating.
    public static $rightname = '';
    public $dohistory        = false;

    // Event slugs. Keep short — used as CSS modifier and i18n key.
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

    // =========================================================================
    // Preferences — per-user opt-out flags, one row per user in
    // glpi_plugin_notifier_preferences. A missing row means "all defaults" so
    // a fresh user (or an install before the preferences table was added)
    // gets every notification.
    //
    // Preferences are a *view filter*, not a subscription. Every event still
    // produces a notification row; the filter is applied at read time in
    // getForUser() / countUnread(), so flipping a flag back on reveals the
    // history instead of silently dropping it. The bell never force-inserts
    // a preferences row.
    // =========================================================================

    /** Defaults for every preference column — opt-out model (all 1). */
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

    /**
     * Per-request memoisation cache for getPreferences(). Reads happen
     * per-request from list.php / markread.php etc.; a single call may
     * touch prefs several times (count + list) and we want to avoid a DB
     * round-trip each time. savePreferences() clears the slot it just
     * wrote so subsequent reads see fresh values.
     *
     * @var array<int, array<string, int>>
     */
    private static array $prefsCache = [];

    /**
     * One-time-per-request guard for the notifications schema migration
     * (adding the `channel` column on installs that predate read-time
     * filtering). `false` means "not yet checked this request".
     */
    private static bool $schemaEnsured = false;

    /**
     * Create the preferences table on demand. Covers installs that predate
     * the table (plugin was installed before preferences were introduced):
     * re-running the plugin install via the UI is the canonical upgrade
     * path, but an idempotent runtime guard spares users the round-trip.
     */
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

    /**
     * Load a single user's preferences merged over the defaults.
     */
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

    /**
     * Persist preferences for a user. Only known columns are written.
     */
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

        // Upsert: delete then insert — cheap with a single-row PK.
        $DB->delete('glpi_plugin_notifier_preferences', ['users_id' => $users_id]);
        $DB->insert('glpi_plugin_notifier_preferences', $row);

        // Invalidate the per-request cache so a subsequent getPreferences()
        // in the same AJAX call (preferences.php echoes the row right back)
        // reads what we just persisted.
        unset(self::$prefsCache[$users_id]);
        return true;
    }

    /**
     * Build a WHERE fragment that excludes notifications whose
     * (itemtype, channel) combo the user has opted out of.
     *
     * Returns `null` when no filter applies (all flags on, or no prefs
     * row). The returned QueryExpression can be appended to the WHERE of
     * a DB->request call.
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

        // Rows written before the `channel` column existed carry channel=''
        // — we can't backfill them so we apply a special rule: if a user
        // opts out of BOTH channels for a type, they've effectively
        // silenced that type entirely, so legacy rows of that type get
        // hidden too. Partial opt-out leaves legacy rows visible because
        // we can't tell which channel they belonged to.
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

    // =========================================================================
    // Event dispatch
    // =========================================================================

    /**
     * Main entry point called by PLUGIN_HOOKS item_add / item_update.
     *
     * GLPI passes the freshly added/updated object. We inspect its type
     * and the set of modified fields ($item->updates) to decide:
     *   - what kind of event this is
     *   - who is affected (should get a bell row)
     *
     * The actor (who triggered it) is filtered out so nobody gets a bell
     * for their own action.
     */
    public static function handleItemEvent($item): void
    {
        if (!is_object($item) || !isset($item->fields['id'])) {
            return;
        }

        $type = $item::getType();

        // ITIL parent objects: Ticket / Change / Problem / ProjectTask
        if (in_array($type, ['Ticket', 'Change', 'Problem'], true)) {
            self::handleItilParent($item);
            return;
        }

        if ($type === 'ProjectTask') {
            self::handleProjectTask($item);
            return;
        }

        // Followups and tasks are children of a parent ITIL object.
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

        // Actor junctions — fired on item_add when someone is assigned to
        // an existing ITIL object. Only user-actors of type ASSIGN (=2)
        // are considered a notifiable event.
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

    /**
     * Handle Ticket / Change / Problem add or update.
     */
    private static function handleItilParent(CommonDBTM $item): void
    {
        $type     = $item::getType();
        $id       = (int)$item->fields['id'];
        // On item_add, CommonDBTM does not populate $item->updates, so an
        // empty updates list means "this is a create".
        $isCreate = empty($item->updates ?? []);

        $updates = $item->updates ?? [];

        // Figure out which field changes we care about.
        $watchedFields = ['status', 'content', 'name', 'priority', 'urgency', 'users_id_lastupdater'];
        $relevant = array_intersect($updates, $watchedFields);

        // "Assignment changed" is tracked via the *_users junction rather
        // than a field on the parent, so we rely on the Assign_User hook
        // (item_add for Ticket_User etc.) — handled separately below.
        // Here we only emit a generic update event when a watched field
        // changed AND it's an update (not create).

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
            // Nothing we care about — bail without touching the table.
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

    /**
     * Handle ProjectTask add/update.
     *
     * ProjectTask has its own team junction (glpi_projecttaskteams) rather
     * than the ITIL actor pattern — we resolve members from there.
     */
    private static function handleProjectTask(CommonDBTM $item): void
    {
        $id       = (int)$item->fields['id'];
        $isCreate = empty($item->updates);

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

    /**
     * Handle a new ITILFollowup (comment on a ticket/change/problem).
     */
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

    /**
     * Handle a new TicketTask / ChangeTask / ProblemTask.
     */
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

        // If the task has an explicit assigned user, make sure they're
        // always notified — even if they're not an actor on the parent.
        // Mark them as 'direct' so the group-only opt-out never silences
        // a technician who was handed the task by name.
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

    /**
     * Handle a new ITILSolution (proposed or applied solution).
     */
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

    /**
     * Handle Ticket_User / Change_User / Problem_User row add.
     *
     * Fires whenever a user is attached to an existing ITIL object. We only
     * emit a bell when the link type is ASSIGN (type = 2) — requesters and
     * observers set themselves during normal ticket-creation flow and
     * don't need to hear about it.
     */
    private static function handleItilUserLink(CommonDBTM $item, string $parentType, string $fk): void
    {
        // CommonITILActor::ASSIGN = 2 (we hard-code to avoid a use-statement
        // dependency in hook context).
        $linkType = (int)($item->fields['type'] ?? 0);
        if ($linkType !== 2) {
            return;
        }

        $targetUser = (int)($item->fields['users_id'] ?? 0);
        $parentId   = (int)($item->fields[$fk] ?? 0);
        if ($targetUser <= 0 || $parentId <= 0) {
            return;
        }

        // Don't notify the actor about their own assignment (self-assign).
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

    /**
     * Handle Group_Ticket / Change_Group / Group_Problem row add.
     *
     * Fires when a group is attached to an existing ITIL object. Same as
     * user-link, only for type=ASSIGN; fans out to every member of the
     * group.
     */
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

    /**
     * Handle ProjectTaskTeam row add — a user or group added to a task's team.
     */
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

    // =========================================================================
    // Actor collection helpers
    // =========================================================================

    /**
     * Resolve every user that should be notified about a Ticket/Change/Problem.
     *
     * Returns `[user_id => channel]` where `channel` is either `'direct'`
     * (requester / observer / assign user link) or `'group'` (member of a
     * group linked to the item). When a user qualifies via both, the
     * stronger `'direct'` wins so per-type group-only opt-outs never drop
     * someone who was personally added.
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

        // Directly-linked users (requester / observer / assign)
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

        // Directly-linked groups — expand to members
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

    /**
     * Resolve every user linked to a ProjectTask via its team junction.
     *
     * Same return shape as {@see collectActorsForItil()}:
     * `[uid => 'direct'|'group']`.
     */
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

    /**
     * Pretty title for a given item: "[Ticket #42] Broken printer".
     */
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

    // =========================================================================
    // Insert / read / mark-read / cleanup
    // =========================================================================

    /**
     * Lazy schema migration for the notifications table. Adds the
     * `channel` column on installs that predate read-time filtering so
     * users don't need to re-run the plugin install. One check per
     * request (static flag); no-op after the first call.
     */
    private static function ensureNotificationsSchema(): void
    {
        global $DB;

        if (self::$schemaEnsured) {
            return;
        }
        self::$schemaEnsured = true;

        if (!$DB->tableExists('glpi_plugin_notifier_notifications')) {
            // The bigger install will recreate it; nothing to migrate.
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
     * Insert a notification row. Deduplicates against the most recent
     * unread notification for the same user/item/event to avoid bell spam
     * when a form saves multiple times in one request.
     *
     * `channel` is 'direct' (personal actor on the item) or 'group'
     * (reached through a group link); it's persisted so the preferences
     * filter at read time can match the right opt-out flag.
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

        // Dedup window: last 60 seconds, same user/item/event, still unread.
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

    /**
     * Get latest notifications for the session user. Most recent first.
     *
     * Applies the per-user preference filter so items of types the user
     * has opted out of are hidden — but still live in the table, so
     * flipping the flag back on makes them reappear.
     */
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

    /**
     * Count unread notifications for a user.
     *
     * Applies the same preference filter as getForUser() so the badge
     * matches what the panel actually shows.
     */
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
     * Mark a single notification as read. Only allowed on rows owned by
     * the session user.
     */
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

    /**
     * Mark a single notification as unread (undoes markRead). Only
     * allowed on rows owned by the session user.
     */
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

    /**
     * Mark every notification for this user as read.
     */
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

    /**
     * Called from PLUGIN_HOOKS item_purge — wipe any notification pointing
     * at a now-deleted item so the bell never dangles.
     */
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

    /**
     * Friendly name for an actor id. Returns empty string for system/unknown.
     */
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
