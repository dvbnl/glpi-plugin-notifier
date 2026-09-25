<?php

namespace GlpiPlugin\Notifier;

use CommonDBTM;
use CommonITILObject;
use CronTask;
use Session;
use Ticket;
use Change;
use Problem;
use ProjectTask;
use ITILFollowup;
use User;
use Toolbox;
use QueryExpression;

/**
 * Persistent store for in-app bell notifications. setup.php wires this
 * into GLPI's item_add / item_update hooks.
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
    const EVENT_VALIDATION     = 'validation';
    const EVENT_MENTION        = 'mention';
    const EVENT_DEADLINE       = 'deadline';

    /** A blanket itemtype opt-out must not silence these: both name you directly. */
    private const CHANNEL_FILTER_EXEMPT = [self::EVENT_MENTION, self::EVENT_DEADLINE];

    const CHANNEL_DIRECT = 'direct';
    const CHANNEL_GROUP  = 'group';
    const CHANNEL_ENTITY = 'entity';

    private const CHANNELS = [self::CHANNEL_DIRECT, self::CHANNEL_GROUP, self::CHANNEL_ENTITY];

    /** Right whose UPDATE bit marks a technician for the entity channel. */
    private const ENTITY_WATCH_RIGHTS = [
        'Ticket'  => 'ticket',
        'Change'  => 'change',
        'Problem' => 'problem',
    ];

    /** Right whose READALL bit lets a user open any item of the type in an entity. */
    private const READALL_RIGHTS = [
        'Ticket'      => 'ticket',
        'Change'      => 'change',
        'Problem'     => 'problem',
        'ProjectTask' => 'project',
    ];

    /** Core's READALL / SEEPRIVATE bits, inlined for the hook context. */
    private const READALL    = 1024;
    private const SEEPRIVATE = 1024;

    private const DEDUP_WINDOW_SECONDS = 60;

    // Leftovers are picked up on the next run.
    private const DEADLINE_SCAN_LIMIT = 500;

    public static function getTypeName($nb = 0): string
    {
        return _n('Notification', 'Notifications', $nb, 'notifier');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_notifier_notifications';
    }

    /** @return string[] */
    public static function getEventSlugs(): array
    {
        return [
            self::EVENT_ASSIGNED,
            self::EVENT_CREATED,
            self::EVENT_COMMENTED,
            self::EVENT_TASK_ADDED,
            self::EVENT_SOLUTION,
            self::EVENT_STATUS_CHANGED,
            self::EVENT_UPDATED,
            self::EVENT_VALIDATION,
            self::EVENT_MENTION,
            self::EVENT_DEADLINE,
        ];
    }

    public static function getEventLabel(string $slug): string
    {
        switch ($slug) {
            case self::EVENT_ASSIGNED:       return __('Assignment', 'notifier');
            case self::EVENT_CREATED:        return __('Creation', 'notifier');
            case self::EVENT_COMMENTED:      return __('New comment', 'notifier');
            case self::EVENT_TASK_ADDED:     return __('New task', 'notifier');
            case self::EVENT_SOLUTION:       return __('Solution proposed', 'notifier');
            case self::EVENT_STATUS_CHANGED: return __('Status changed', 'notifier');
            case self::EVENT_UPDATED:        return __('Item updated', 'notifier');
            case self::EVENT_VALIDATION:     return __('Approval', 'notifier');
            case self::EVENT_MENTION:        return __('Mention', 'notifier');
            case self::EVENT_DEADLINE:       return __('Deadline', 'notifier');
        }
        return $slug;
    }

    // ------------------------------------------------------------------ preferences
    //
    // A view filter, not a subscription: every event is stored regardless,
    // so flipping a flag back on resurfaces history. Missing row = all on.

    public static function getDefaultPreferences(): array
    {
        $defaults = [
            'notify_ticket_direct'      => 1,
            'notify_ticket_group'       => 1,
            'notify_change_direct'      => 1,
            'notify_change_group'       => 1,
            'notify_problem_direct'     => 1,
            'notify_problem_group'      => 1,
            'notify_projecttask_direct' => 1,
            'notify_projecttask_group'  => 1,
            // Opt-in: every new item in an entity can be a lot of bells.
            'notify_ticket_entity'      => 0,
            'notify_change_entity'      => 0,
            'notify_problem_entity'     => 0,
        ];

        foreach (self::getEventSlugs() as $slug) {
            $defaults['notify_event_' . $slug] = 1;
        }

        // Opt-in: the browser prompts for permission, and an unrequested
        // sound in a shared office is rude.
        $defaults['desktop_enabled'] = 0;
        $defaults['sound_enabled']   = 0;

        return $defaults;
    }

    private static array $prefsCache = [];

    private static bool $schemaEnsured = false;

    // Safety net for installs that never re-ran the plugin installer.
    private static function ensurePreferencesTable(): bool
    {
        global $DB;

        if ($DB->tableExists('glpi_plugin_notifier_preferences')) {
            return true;
        }

        $charset   = \DBConnection::getDefaultCharset();
        $collation = \DBConnection::getDefaultCollation();

        $columns = '';
        foreach (self::getDefaultPreferences() as $col => $default) {
            $columns .= "`{$col}` TINYINT NOT NULL DEFAULT " . (int)$default . ",\n            ";
        }

        // Raw DDL: interpolate only hardcoded identifiers, never input.
        $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_notifier_preferences` (
            `users_id` INT UNSIGNED NOT NULL,
            {$columns}`date_mod` TIMESTAMP NULL DEFAULT NULL,
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

        $row = ['users_id' => $users_id];
        foreach (array_keys(self::getDefaultPreferences()) as $col) {
            if (!$DB->fieldExists('glpi_plugin_notifier_preferences', $col)) {
                continue;
            }
            $row[$col] = isset($input[$col]) && (int)$input[$col] ? 1 : 0;
        }
        $row['date_mod'] = new QueryExpression('NOW()');

        // Upsert via delete+insert — single-row PK keeps it cheap.
        $DB->delete('glpi_plugin_notifier_preferences', ['users_id' => $users_id]);
        $DB->insert('glpi_plugin_notifier_preferences', $row);

        unset(self::$prefsCache[$users_id]);
        return true;
    }

    /** Null when the user has opted out of nothing. */
    private static function prefFilterExpression(int $users_id): ?QueryExpression
    {
        $prefs = self::getPreferences($users_id);

        $typeMap = [
            'ticket'      => 'Ticket',
            'change'      => 'Change',
            'problem'     => 'Problem',
            'projecttask' => 'ProjectTask',
        ];

        $exempt = "'" . implode("', '", self::CHANNEL_FILTER_EXEMPT) . "'";

        // Table-qualified: this lands in a query that joins glpi_users.
        // Every interpolated value is a constant or a key of $typeMap.
        $t        = '`' . self::getTable() . '`';
        $itemCol  = $t . '.`itemtype`';
        $chanCol  = $t . '.`channel`';
        $eventCol = $t . '.`event`';

        // Pre-channel rows carry channel='' and cannot be backfilled, so a
        // partial opt-out leaves them visible.
        $disabled = [];
        foreach ($typeMap as $slug => $itemtype) {
            $channels = [];
            $off      = [];
            foreach (self::CHANNELS as $channel) {
                $key = 'notify_' . $slug . '_' . $channel;
                if (!array_key_exists($key, $prefs)) {
                    continue;
                }
                $channels[] = $channel;
                if (empty($prefs[$key])) {
                    $off[] = "'{$channel}'";
                }
            }

            if (empty($off)) {
                continue;
            }
            if (count($off) === count($channels)) {
                $clause = "{$itemCol} = '{$itemtype}'";
            } else {
                $clause = "{$itemCol} = '{$itemtype}' AND {$chanCol} IN (" . implode(', ', $off) . ')';
            }
            $disabled[] = "({$clause} AND {$eventCol} NOT IN ({$exempt}))";
        }

        $eventsOff = [];
        foreach (self::getEventSlugs() as $eventSlug) {
            if (empty($prefs['notify_event_' . $eventSlug])) {
                $eventsOff[] = "'{$eventSlug}'";
            }
        }
        if (!empty($eventsOff)) {
            $disabled[] = '(' . $eventCol . ' IN (' . implode(', ', $eventsOff) . '))';
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

        if (in_array($type, ['TicketValidation', 'ChangeValidation'], true)) {
            self::handleValidation($item);
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

        $base = self::baseFor($item, $type, $id);

        $mentioned = [];
        if ($isCreate || in_array('content', $relevant, true)) {
            $mentioned = self::dispatchMentions($item, $base, $targets);
            $targets   = array_diff_key($targets, $mentioned);
        }

        if ($isCreate) {
            $watchers = self::collectEntityWatchers($type, (int)$base['entities_id']);
            unset($watchers[(int)Session::getLoginUserID()]);
            $watchers = array_diff_key($watchers, $mentioned);

            // Someone in both sets gets the channel they have switched on,
            // or the read-time filter would hide the only row they got.
            $slug = strtolower($type);
            foreach (array_keys(array_intersect_key($watchers, $targets)) as $uid) {
                $prefs = self::getPreferences($uid);
                if (!empty($prefs['notify_' . $slug . '_' . $targets[$uid]])) {
                    unset($watchers[$uid]);
                } else {
                    unset($targets[$uid]);
                }
            }

            if (!empty($targets)) {
                self::dispatch($targets, $base + [
                    'event'   => self::EVENT_CREATED,
                    'message' => __('New item concerning you', 'notifier'),
                ]);
            }
            if (!empty($watchers)) {
                self::dispatch($watchers, $base + [
                    'event'   => self::EVENT_CREATED,
                    'message' => __('New item in an entity you watch', 'notifier'),
                ]);
            }
            return;
        }

        if (empty($targets)) {
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

        self::dispatch($targets, $base + ['event' => $event, 'message' => $message]);
    }

    private static function handleProjectTask(CommonDBTM $item): void
    {
        $id       = (int)$item->fields['id'];
        $isCreate = empty($item->updates);

        // ProjectTask uses its own team junction, not the ITIL actor pattern.
        $targets = self::collectProjectTaskMembers($id);
        unset($targets[(int)Session::getLoginUserID()]);

        $base = self::baseFor($item, 'ProjectTask', $id);

        $mentioned = [];
        if ($isCreate || in_array('content', $item->updates ?? [], true)) {
            $mentioned = self::dispatchMentions($item, $base, $targets);
            $targets   = array_diff_key($targets, $mentioned);
        }

        if (empty($targets)) {
            return;
        }

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

        self::dispatch($targets, $base + ['event' => $event, 'message' => $message]);
    }

    private static function handleFollowup(CommonDBTM $item): void
    {
        $parent = self::resolvePolymorphicParent($item);
        if ($parent === null) {
            return;
        }

        $base = self::baseFor($parent, $parent::getType(), (int)$parent->fields['id']);

        $targets = self::collectActorsForItil($parent);
        unset($targets[(int)Session::getLoginUserID()]);

        $private = self::privateAudience($item, (int)$base['entities_id']);
        if ($private !== null) {
            $targets = array_intersect_key($targets, $private);
        }

        $mentioned = self::dispatchMentions($item, $base, $targets, $private);
        $targets   = array_diff_key($targets, $mentioned);

        if (empty($targets)) {
            return;
        }

        self::dispatch($targets, $base + [
            'event'   => self::EVENT_COMMENTED,
            'message' => __('New comment', 'notifier'),
        ]);
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

        $base = self::baseFor($parent, $parentType, $parentId);

        $targets = self::collectActorsForItil($parent);

        // 'direct' so a group-only opt-out cannot silence the named tech.
        if (!empty($item->fields['users_id_tech'])) {
            $targets[(int)$item->fields['users_id_tech']] = 'direct';
        }

        unset($targets[(int)Session::getLoginUserID()]);

        $private = self::privateAudience($item, (int)$base['entities_id']);
        if ($private !== null) {
            $targets = array_intersect_key($targets, $private);
        }

        $mentioned = self::dispatchMentions($item, $base, $targets, $private);
        $targets   = array_diff_key($targets, $mentioned);

        if (empty($targets)) {
            return;
        }

        self::dispatch($targets, $base + [
            'event'   => self::EVENT_TASK_ADDED,
            'message' => __('New task', 'notifier'),
        ]);
    }

    private static function handleSolution(CommonDBTM $item): void
    {
        $parent = self::resolvePolymorphicParent($item);
        if ($parent === null) {
            return;
        }

        $base = self::baseFor($parent, $parent::getType(), (int)$parent->fields['id']);

        $targets = self::collectActorsForItil($parent);
        unset($targets[(int)Session::getLoginUserID()]);

        $mentioned = self::dispatchMentions($item, $base, $targets);
        $targets   = array_diff_key($targets, $mentioned);

        if (empty($targets)) {
            return;
        }

        self::dispatch($targets, $base + [
            'event'   => self::EVENT_SOLUTION,
            'message' => __('Solution proposed', 'notifier'),
        ]);
    }

    /** ITILFollowup / ITILSolution both point at their parent the same way. */
    private static function resolvePolymorphicParent(CommonDBTM $item): ?CommonDBTM
    {
        $parentType = (string)($item->fields['itemtype'] ?? '');
        $parentId   = (int)($item->fields['items_id'] ?? 0);

        if ($parentType === '' || $parentId === 0 || !class_exists($parentType)) {
            return null;
        }
        if (!in_array($parentType, ['Ticket', 'Change', 'Problem'], true)) {
            return null;
        }

        $parent = new $parentType();
        if (!$parent->getFromDB($parentId)) {
            return null;
        }
        return $parent;
    }

    /**
     * On add: ping the validator(s). On status change: ping the requester
     * back. Filed against the parent so per-type preferences still apply.
     */
    private static function handleValidation(CommonDBTM $item): void
    {
        $type = $item::getType();
        $map  = [
            'TicketValidation' => ['parent' => 'Ticket', 'fk' => 'tickets_id'],
            'ChangeValidation' => ['parent' => 'Change', 'fk' => 'changes_id'],
        ];
        if (!isset($map[$type])) {
            return;
        }

        $parentType = $map[$type]['parent'];
        $parentId   = (int)($item->fields[$map[$type]['fk']] ?? 0);
        if ($parentId <= 0) {
            return;
        }

        $parent = new $parentType();
        if (!$parent->getFromDB($parentId)) {
            return;
        }

        $isCreate = empty($item->updates ?? []);
        $actor    = (int)Session::getLoginUserID();
        $base     = self::baseFor($parent, $parentType, $parentId);

        if ($isCreate) {
            $targets = self::collectValidationTargets($item);
            unset($targets[$actor]);
            if (empty($targets)) {
                return;
            }

            self::dispatch($targets, $base + [
                'event'   => self::EVENT_VALIDATION,
                'message' => __('Approval requested', 'notifier'),
            ]);
            return;
        }

        $updates = $item->updates ?? [];
        if (!in_array('status', $updates, true)) {
            return;
        }

        $requester = (int)($item->fields['users_id'] ?? 0);
        if ($requester <= 0 || $requester === $actor) {
            return;
        }

        self::dispatch([$requester => 'direct'], $base + [
            'event'   => self::EVENT_VALIDATION,
            'message' => __('Approval status changed', 'notifier'),
        ]);
    }

    // GLPI 10.0.7+ uses itemtype_target/items_id_target; older installs
    // only have users_id_validate.
    private static function collectValidationTargets(CommonDBTM $item): array
    {
        $targets    = [];
        $targetType = (string)($item->fields['itemtype_target'] ?? '');
        $targetId   = (int)($item->fields['items_id_target'] ?? 0);

        if ($targetType !== '' && $targetId > 0) {
            if ($targetType === 'User') {
                $targets[$targetId] = 'direct';
            } elseif ($targetType === 'Group') {
                foreach (self::membersOfGroups([$targetId]) as $uid) {
                    $targets[$uid] = 'group';
                }
            }
        }

        if (empty($targets)) {
            $legacy = (int)($item->fields['users_id_validate'] ?? 0);
            if ($legacy > 0) {
                $targets[$legacy] = 'direct';
            }
        }

        return $targets;
    }

    // Only ASSIGN (CommonITILActor::ASSIGN = 2): requesters and observers
    // are noise here. Inlined to avoid an import in hook context.
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

        self::dispatch([$targetUser => 'direct'], self::baseFor($parent, $parentType, $parentId) + [
            'event'   => self::EVENT_ASSIGNED,
            'message' => __('You have been assigned', 'notifier'),
        ]);
    }

    private static function handleItilGroupLink(CommonDBTM $item, string $parentType, string $fk): void
    {
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

        $actor   = (int)Session::getLoginUserID();
        $targets = [];
        foreach (self::membersOfGroups([$groupId]) as $uid) {
            if ($uid !== $actor) {
                $targets[$uid] = 'group';
            }
        }

        if (empty($targets)) {
            return;
        }

        self::dispatch($targets, self::baseFor($parent, $parentType, $parentId) + [
            'event'   => self::EVENT_ASSIGNED,
            'message' => __('Your group has been assigned', 'notifier'),
        ]);
    }

    private static function handleProjectTaskTeamLink(CommonDBTM $item): void
    {
        $taskId     = (int)($item->fields['projecttasks_id'] ?? 0);
        $memberType = (string)($item->fields['itemtype'] ?? '');
        $memberId   = (int)($item->fields['items_id'] ?? 0);
        if ($taskId <= 0 || $memberId <= 0) {
            return;
        }

        $task = new ProjectTask();
        if (!$task->getFromDB($taskId)) {
            return;
        }

        $actor   = (int)Session::getLoginUserID();
        $targets = [];

        if ($memberType === 'User') {
            if ($memberId !== $actor) {
                $targets[$memberId] = 'direct';
            }
        } elseif ($memberType === 'Group') {
            foreach (self::membersOfGroups([$memberId]) as $uid) {
                if ($uid !== $actor) {
                    $targets[$uid] = 'group';
                }
            }
        }

        if (empty($targets)) {
            return;
        }

        self::dispatch($targets, self::baseFor($task, 'ProjectTask', $taskId) + [
            'event'   => self::EVENT_ASSIGNED,
            'message' => __('You have been added to a project task', 'notifier'),
        ]);
    }

    // ------------------------------------------------------------------ mentions

    /**
     * Returns the mentioned users so the caller can subtract them from the
     * generic recipients — being named beats being an actor.
     *
     * A mention only reaches someone who could already open the item: an
     * actor, or a READALL holder in its entity. Otherwise any author could
     * leak a title to any user by typing their login.
     *
     * @param  array<int, string>    $actors
     * @param  array<int, int>|null  $private audience of a private source, null when public
     * @return array<int, string>
     */
    private static function dispatchMentions(CommonDBTM $source, array $base, array $actors, ?array $private = null): array
    {
        if (!Config::get('mentions_enabled')) {
            return [];
        }

        $content = Mention::contentOf($source);
        if ($content === '') {
            return [];
        }

        $actor   = (int)Session::getLoginUserID();
        $targets = [];
        foreach (Mention::extract($content) as $uid) {
            if ($uid > 0 && $uid !== $actor) {
                $targets[$uid] = 'direct';
            }
        }

        if (empty($targets)) {
            return [];
        }

        $readAll = isset(self::READALL_RIGHTS[$base['itemtype']])
            ? self::usersWithRightInEntity(self::READALL_RIGHTS[$base['itemtype']], self::READALL, (int)$base['entities_id'])
            : [];
        foreach (array_keys($targets) as $uid) {
            $visible = isset($actors[$uid]) || isset($readAll[$uid]);
            if (!$visible || ($private !== null && !isset($private[$uid]))) {
                unset($targets[$uid]);
            }
        }

        if (empty($targets)) {
            return [];
        }

        self::dispatch($targets, $base + [
            'event'   => self::EVENT_MENTION,
            'message' => __('You were mentioned', 'notifier'),
        ]);

        return $targets;
    }

    // ------------------------------------------------------------------ actor collection

    /**
     * [user_id => channel]. Direct beats group when both apply, so a
     * group-only opt-out cannot silence a personal actor.
     */
    private static function collectActorsForItil(CommonDBTM $item): array
    {
        global $DB;

        $type = $item::getType();
        $id   = (int)$item->fields['id'];

        $linkMap = self::itilLinkMap();
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

        foreach (self::membersOfGroups(array_values($groups)) as $uid) {
            if (!isset($users[$uid])) {
                $users[$uid] = 'group';
            }
        }

        return $users;
    }

    /** @return array<int, string> [user_id => 'entity'] */
    private static function collectEntityWatchers(string $type, int $entities_id): array
    {
        global $DB;

        if (!Config::get('entity_watch_enabled') || !isset(self::ENTITY_WATCH_RIGHTS[$type])) {
            return [];
        }

        $prefTable = 'glpi_plugin_notifier_preferences';
        $prefCol   = 'notify_' . strtolower($type) . '_entity';
        if (!$DB->tableExists($prefTable) || !$DB->fieldExists($prefTable, $prefCol)) {
            return [];
        }

        $scope = self::profileEntityScope($entities_id);

        $rs = $DB->request([
            'SELECT'     => ['glpi_profiles_users.users_id'],
            'DISTINCT'   => true,
            'FROM'       => $prefTable,
            'INNER JOIN' => [
                'glpi_profiles_users' => ['ON' => [
                    'glpi_profiles_users' => 'users_id',
                    $prefTable            => 'users_id',
                ]],
                'glpi_profilerights' => ['ON' => [
                    'glpi_profilerights'  => 'profiles_id',
                    'glpi_profiles_users' => 'profiles_id',
                ]],
            ],
            'WHERE'      => [
                $prefTable . '.' . $prefCol => 1,
                'glpi_profilerights.name'   => self::ENTITY_WATCH_RIGHTS[$type],
                new QueryExpression('(`glpi_profilerights`.`rights` & ' . UPDATE . ') = ' . UPDATE),
            ] + $scope,
        ]);

        $users = [];
        foreach ($rs as $row) {
            $uid = (int)$row['users_id'];
            if ($uid > 0) {
                $users[$uid] = self::CHANNEL_ENTITY;
            }
        }
        return $users;
    }

    /** Profile assignments that reach the entity, directly or through a recursive ancestor. */
    private static function profileEntityScope(int $entities_id): array
    {
        $ancestors = array_values(array_map('intval', getAncestorsOf('glpi_entities', $entities_id)));

        $scope = ['glpi_profiles_users.entities_id' => $entities_id];
        if (!empty($ancestors)) {
            $scope = ['OR' => [
                $scope,
                [
                    'glpi_profiles_users.entities_id'  => $ancestors,
                    'glpi_profiles_users.is_recursive' => 1,
                ],
            ]];
        }
        return $scope;
    }

    /** @return array<int, int> active users holding $bit on $right in the entity */
    private static function usersWithRightInEntity(string $right, int $bit, int $entities_id): array
    {
        global $DB;

        $rs = $DB->request([
            'SELECT'     => ['glpi_profiles_users.users_id'],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_profiles_users',
            'INNER JOIN' => [
                'glpi_profilerights' => ['ON' => [
                    'glpi_profilerights'  => 'profiles_id',
                    'glpi_profiles_users' => 'profiles_id',
                ]],
            ],
            'WHERE'      => [
                'glpi_profilerights.name' => $right,
                new QueryExpression('(`glpi_profilerights`.`rights` & ' . $bit . ') = ' . $bit),
            ] + self::profileEntityScope($entities_id),
        ]);

        $users = [];
        foreach ($rs as $row) {
            $uid = (int)$row['users_id'];
            if ($uid > 0) {
                $users[$uid] = $uid;
            }
        }
        return $users;
    }

    /**
     * Who may see a private followup/task, mirroring core: SEEPRIVATE holders
     * plus, for tasks, the assigned tech and tech group.
     *
     * @return array<int, int>|null null when the source is not private
     */
    private static function privateAudience(CommonDBTM $source, int $entities_id): ?array
    {
        if (empty($source->fields['is_private'])) {
            return null;
        }

        $class = get_class($source);
        $right = property_exists($class, 'rightname') ? (string)$class::$rightname : '';
        $bit   = defined($class . '::SEEPRIVATE') ? (int)constant($class . '::SEEPRIVATE') : self::SEEPRIVATE;

        $users = $right !== '' ? self::usersWithRightInEntity($right, $bit, $entities_id) : [];

        if (!empty($source->fields['users_id_tech'])) {
            $uid = (int)$source->fields['users_id_tech'];
            $users[$uid] = $uid;
        }
        if (!empty($source->fields['groups_id_tech'])) {
            foreach (self::membersOfGroups([(int)$source->fields['groups_id_tech']]) as $uid) {
                $users[$uid] = $uid;
            }
        }

        return $users;
    }

    private static function itilLinkMap(): array
    {
        return [
            'Ticket'  => ['users' => 'glpi_tickets_users',  'groups' => 'glpi_groups_tickets',  'fk' => 'tickets_id'],
            'Change'  => ['users' => 'glpi_changes_users',  'groups' => 'glpi_changes_groups',  'fk' => 'changes_id'],
            'Problem' => ['users' => 'glpi_problems_users', 'groups' => 'glpi_groups_problems', 'fk' => 'problems_id'],
        ];
    }

    /**
     * @param  int[] $groupIds
     * @return int[] distinct member ids, empty for an empty input
     */
    private static function membersOfGroups(array $groupIds): array
    {
        global $DB;

        $groupIds = array_values(array_filter(array_map('intval', $groupIds)));
        if (empty($groupIds)) {
            return [];
        }

        $rs = $DB->request([
            'SELECT'          => ['users_id'],
            'DISTINCT'        => true,
            'FROM'            => 'glpi_groups_users',
            'WHERE'           => ['groups_id' => $groupIds],
        ]);

        $users = [];
        foreach ($rs as $row) {
            $uid = (int)$row['users_id'];
            if ($uid > 0) {
                $users[$uid] = $uid;
            }
        }
        return array_values($users);
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

        foreach (self::membersOfGroups(array_values($groups)) as $uid) {
            if (!isset($users[$uid])) {
                $users[$uid] = 'group';
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

    /** Shared title / url / entity payload for every event on an item. */
    private static function baseFor(CommonDBTM $item, string $itemtype, int $items_id): array
    {
        return [
            'itemtype'    => $itemtype,
            'items_id'    => $items_id,
            'entities_id' => (int)($item->fields['entities_id'] ?? 0),
            'title'       => self::formatItemTitle($item),
            'url'         => $itemtype::getFormURLWithID($items_id, false),
        ];
    }

    // ------------------------------------------------------------------ insert / read / cleanup

    // Lazy migration for installs that never re-ran the plugin installer.
    // Once-per-request, no-op after the first call.
    private static function ensureNotificationsSchema(): void
    {
        global $DB;

        if (self::$schemaEnsured) {
            return;
        }
        self::$schemaEnsured = true;

        if (!$DB->tableExists(self::getTable())) {
            return;
        }

        $columns = [
            'channel'       => "VARCHAR(10) NOT NULL DEFAULT '' AFTER `event`",
            'entities_id'   => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER `actor_users_id`",
            'snoozed_until' => "TIMESTAMP NULL DEFAULT NULL AFTER `is_read`",
        ];

        foreach ($columns as $column => $definition) {
            if ($DB->fieldExists(self::getTable(), $column)) {
                continue;
            }
            // Raw DDL: interpolate only hardcoded identifiers, never input.
            $DB->doQuery(
                'ALTER TABLE `' . self::getTable() . '` ADD COLUMN `' . $column . '` ' . $definition
            );
        }
    }

    /** Single-recipient wrapper over dispatch(). */
    public static function insert(array $data): void
    {
        $users_id = (int)($data['users_id'] ?? 0);
        if ($users_id <= 0) {
            return;
        }
        $channel = (string)($data['channel'] ?? '');
        unset($data['users_id'], $data['channel']);

        self::dispatch([$users_id => $channel], $data);
    }

    /**
     * Three queries regardless of group size: recipient check, dedup scan,
     * one multi-row insert.
     *
     * @param array<int, string> $targets [user_id => channel]
     */
    public static function dispatch(array $targets, array $payload): void
    {
        global $DB;

        $itemtype = (string)($payload['itemtype'] ?? '');
        $items_id = (int)($payload['items_id'] ?? 0);
        $event    = (string)($payload['event'] ?? '');

        if (empty($targets) || $itemtype === '' || $items_id === 0 || $event === '') {
            return;
        }

        if (!Config::isEventEnabled($event)) {
            return;
        }

        self::ensureNotificationsSchema();

        $userIds = [];
        foreach (array_keys($targets) as $uid) {
            $uid = (int)$uid;
            if ($uid > 0) {
                $userIds[$uid] = $uid;
            }
        }
        if (empty($userIds)) {
            return;
        }

        // Groups routinely still contain disabled accounts.
        $userIds = self::filterActiveUsers($userIds);
        if (empty($userIds)) {
            return;
        }

        // A single form save fires several hooks.
        $recent = $DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'users_id' => array_values($userIds),
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'event'    => $event,
                'is_read'  => 0,
                new QueryExpression(
                    '`date_creation` > NOW() - INTERVAL ' . self::DEDUP_WINDOW_SECONDS . ' SECOND'
                ),
            ],
        ]);
        foreach ($recent as $row) {
            unset($userIds[(int)$row['users_id']]);
        }
        if (empty($userIds)) {
            return;
        }

        $actor   = (int)Session::getLoginUserID();
        $entity  = (int)($payload['entities_id'] ?? 0);
        $title   = (string)($payload['title'] ?? '');
        $message = (string)($payload['message'] ?? '');
        $url     = (string)($payload['url'] ?? '');

        $rows = [];
        foreach ($userIds as $uid) {
            $rows[] = [
                'users_id'       => $uid,
                'actor_users_id' => $actor,
                'entities_id'    => $entity,
                'itemtype'       => $itemtype,
                'items_id'       => $items_id,
                'event'          => $event,
                'channel'        => (string)($targets[$uid] ?? ''),
                'title'          => $title,
                'message'        => $message,
                'url'            => $url,
                'is_read'        => 0,
            ];
        }

        self::bulkInsert($rows);
    }

    /** @param array<int,int> $userIds @return array<int,int> */
    private static function filterActiveUsers(array $userIds): array
    {
        global $DB;

        $rs = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_users',
            'WHERE'  => [
                'id'         => array_values($userIds),
                'is_active'  => 1,
                'is_deleted' => 0,
            ],
        ]);

        $active = [];
        foreach ($rs as $row) {
            $id = (int)$row['id'];
            $active[$id] = $id;
        }
        return $active;
    }

    /**
     * MySQL stamps the dates so they stay comparable with the NOW()-based
     * dedup and retention windows even if PHP and the DB clocks differ.
     */
    private static function bulkInsert(array $rows): void
    {
        global $DB;

        if (empty($rows)) {
            return;
        }

        $columns = array_keys($rows[0]);
        $quoted  = array_map(static fn($c) => $DB->quoteName($c), $columns);
        $quoted[] = $DB->quoteName('date_creation');
        $quoted[] = $DB->quoteName('date_mod');

        $tuples = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = self::quoteValue($row[$column]);
            }
            $values[] = 'NOW()';
            $values[] = 'NOW()';
            $tuples[] = '(' . implode(', ', $values) . ')';
        }

        // Raw multi-row insert the builder cannot express; every value goes through quoteValue().
        $DB->doQuery(
            'INSERT INTO ' . $DB->quoteName(self::getTable())
            . ' (' . implode(', ', $quoted) . ') VALUES ' . implode(', ', $tuples)
        );
    }

    /** GLPI renamed this helper across the supported range. */
    private static function quoteValue($value): string
    {
        global $DB;

        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_bool($value)) {
            return (string)(int)$value;
        }

        $value = (string)$value;

        foreach (['quoteValue', 'quote'] as $method) {
            if (method_exists($DB, $method)) {
                return (string)$DB->{$method}($value);
            }
        }
        if (method_exists($DB, 'escape')) {
            return "'" . $DB->escape($value) . "'";
        }

        return "'" . addslashes($value) . "'";
    }

    /** Ownership, preferences, entity visibility and the snooze window. */
    private static function feedCriteria(int $users_id, bool $unreadOnly = false): array
    {
        $table = self::getTable();

        // Qualified: getForUser() joins glpi_users, which has its own
        // entities_id and date columns.
        $where = [$table . '.users_id' => $users_id];

        if ($unreadOnly) {
            $where[$table . '.is_read'] = 0;
        }

        $filter = self::prefFilterExpression($users_id);
        if ($filter !== null) {
            $where[] = $filter;
        }

        // Hidden from the badge too, which is the point of snoozing.
        $where[] = new QueryExpression(
            '(`' . $table . '`.`snoozed_until` IS NULL OR `' . $table . '`.`snoozed_until` <= NOW())'
        );

        $entityRestrict = getEntitiesRestrictCriteria($table, 'entities_id');
        if (!empty($entityRestrict)) {
            $where[] = $entityRestrict;
        }

        return $where;
    }

    public static function getForUser(int $users_id, int $limit = 25, int $offset = 0, string $search = ''): array
    {
        global $DB;

        self::ensureNotificationsSchema();

        $table = self::getTable();
        $where = self::feedCriteria($users_id);

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = ['OR' => [
                $table . '.title'   => ['LIKE', $like],
                $table . '.message' => ['LIKE', $like],
            ]];
        }

        // JOIN, not a getFromDB() per row: that cost one query per
        // notification on every poll.
        $rs = $DB->request([
            'SELECT'    => [
                $table . '.*',
                'glpi_users.firstname AS actor_firstname',
                'glpi_users.realname AS actor_realname',
                'glpi_users.name AS actor_login',
                'glpi_users.picture AS actor_picture',
                new QueryExpression('UNIX_TIMESTAMP(`' . $table . '`.`date_creation`) AS `created_ts`'),
            ],
            'FROM'      => $table,
            'LEFT JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        'glpi_users' => 'id',
                        $table       => 'actor_users_id',
                    ],
                ],
            ],
            'WHERE'     => $where,
            // id DESC breaks ties so paging stays stable across requests.
            'ORDER'     => [$table . '.is_read ASC', $table . '.date_creation DESC', $table . '.id DESC'],
            'START'     => max(0, $offset),
            'LIMIT'     => $limit,
        ]);

        $rows = [];
        foreach ($rs as $row) {
            $rows[] = [
                'id'         => (int)$row['id'],
                'itemtype'   => $row['itemtype'],
                'items_id'   => (int)$row['items_id'],
                'event'      => $row['event'],
                'title'      => $row['title'],
                'message'    => $row['message'],
                'url'        => $row['url'],
                'is_read'    => (bool)$row['is_read'],
                'actor_name'   => self::formatActorName($row),
                'actor_avatar' => self::actorAvatarUrl($row),
                // Epoch: browser and database need not share a timezone.
                'created_ts' => (int)($row['created_ts'] ?? 0),
            ];
        }
        return $rows;
    }

    private static function formatActorName(array $row): string
    {
        $full = trim(($row['actor_firstname'] ?? '') . ' ' . ($row['actor_realname'] ?? ''));
        if ($full !== '') {
            return $full;
        }
        return (string)($row['actor_login'] ?? '');
    }

    /** Thumbnail URL for the actor's GLPI profile picture, '' when they have none. */
    private static function actorAvatarUrl(array $row): string
    {
        $picture = (string)($row['actor_picture'] ?? '');
        if ($picture === '') {
            return '';
        }
        return (string)User::getThumbnailURLForPicture($picture);
    }

    public static function countUnread(int $users_id): int
    {
        global $DB;

        self::ensureNotificationsSchema();

        $rs = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::getTable(),
            'WHERE' => self::feedCriteria($users_id, true),
        ]);
        $row = $rs->current();
        return (int)($row['cpt'] ?? 0);
    }

    /** What the badge shows. Mirrors the JS-side groupKey(). */
    public static function countUnreadGroups(int $users_id): int
    {
        global $DB;

        self::ensureNotificationsSchema();

        $rs = $DB->request([
            'SELECT' => [new QueryExpression('COUNT(DISTINCT `itemtype`, `items_id`) AS cpt')],
            'FROM'   => self::getTable(),
            'WHERE'  => self::feedCriteria($users_id, true),
        ]);
        $row = $rs->current();
        return (int)($row['cpt'] ?? 0);
    }

    public static function countVisible(int $users_id): int
    {
        global $DB;

        self::ensureNotificationsSchema();

        $rs = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::getTable(),
            'WHERE' => self::feedCriteria($users_id),
        ]);
        $row = $rs->current();
        return (int)($row['cpt'] ?? 0);
    }

    /**
     * ETag source. Unfiltered on purpose — it only has to move whenever
     * the feed could have, and the snooze count makes it move as timers
     * expire without any row being touched.
     */
    public static function stateSignature(int $users_id): string
    {
        global $DB;

        self::ensureNotificationsSchema();

        $rs = $DB->request([
            'SELECT' => [
                new QueryExpression('COUNT(*) AS `total`'),
                new QueryExpression('COALESCE(MAX(`date_mod`), 0) AS `latest`'),
                new QueryExpression('SUM(`is_read` = 0) AS `unread`'),
                new QueryExpression('SUM(`snoozed_until` IS NOT NULL AND `snoozed_until` > NOW()) AS `snoozed`'),
            ],
            'FROM'   => self::getTable(),
            'WHERE'  => ['users_id' => $users_id],
        ]);
        $row = $rs->current() ?: [];

        $parts = [
            $row['total']   ?? 0,
            $row['latest']  ?? 0,
            $row['unread']  ?? 0,
            $row['snoozed'] ?? 0,
            Note::signature($users_id),
            // Switching entity changes what is visible without touching a row.
            implode(',', (array)($_SESSION['glpiactiveentities'] ?? [])),
        ];

        return substr(md5(implode('|', $parts)), 0, 16);
    }

    public static function markRead(int $id, int $users_id): bool
    {
        return self::setReadState($id, $users_id, 1);
    }

    public static function markUnread(int $id, int $users_id): bool
    {
        return self::setReadState($id, $users_id, 0);
    }

    private static function setReadState(int $id, int $users_id, int $isRead): bool
    {
        global $DB;

        if ($id <= 0 || $users_id <= 0) {
            return false;
        }
        return (bool)$DB->update(
            self::getTable(),
            ['is_read' => $isRead, 'date_mod' => new QueryExpression('NOW()')],
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
            self::getTable(),
            ['is_read' => 1, 'date_mod' => new QueryExpression('NOW()')],
            ['users_id' => $users_id, 'is_read' => 0]
        );
    }

    /** Ownership is in the WHERE: nobody can snooze another user's row. */
    public static function snooze(int $id, int $users_id, int $minutes): bool
    {
        global $DB;

        if ($id <= 0 || $users_id <= 0 || $minutes <= 0) {
            return false;
        }

        self::ensureNotificationsSchema();

        return (bool)$DB->update(
            self::getTable(),
            [
                'snoozed_until' => new QueryExpression('NOW() + INTERVAL ' . (int)$minutes . ' MINUTE'),
                'is_read'       => 0,
                'date_mod'      => new QueryExpression('NOW()'),
            ],
            ['id' => $id, 'users_id' => $users_id]
        );
    }

    /** Every unread row for one item, used by the group-level actions. */
    public static function snoozeItem(int $users_id, string $itemtype, int $items_id, int $minutes): bool
    {
        global $DB;

        if ($users_id <= 0 || $itemtype === '' || $items_id <= 0 || $minutes <= 0) {
            return false;
        }

        self::ensureNotificationsSchema();

        return (bool)$DB->update(
            self::getTable(),
            [
                'snoozed_until' => new QueryExpression('NOW() + INTERVAL ' . (int)$minutes . ' MINUTE'),
                'date_mod'      => new QueryExpression('NOW()'),
            ],
            [
                'users_id' => $users_id,
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'is_read'  => 0,
            ]
        );
    }

    /**
     * pre_item_form: fires on any route that renders the form, so reaching
     * a ticket from search or a direct URL clears its bells too.
     */
    public static function markItemAsSeen($params): void
    {
        global $DB;

        if (!is_array($params) || !isset($params['item']) || !is_object($params['item'])) {
            return;
        }
        $item = $params['item'];
        $type = $item::getType();

        if (!in_array($type, ['Ticket', 'Change', 'Problem', 'ProjectTask'], true)) {
            return;
        }

        $id = (int)($item->fields['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $users_id = (int)Session::getLoginUserID();
        if ($users_id <= 0) {
            return;
        }

        self::ensureNotificationsSchema();

        $DB->update(
            self::getTable(),
            ['is_read' => 1, 'date_mod' => new QueryExpression('NOW()')],
            [
                'users_id' => $users_id,
                'itemtype' => $type,
                'items_id' => $id,
                'is_read'  => 0,
            ]
        );
    }

    // item_purge: the item is gone for good, so are its bells.
    public static function cleanForItem($item): void
    {
        global $DB;
        if (!is_object($item) || !isset($item->fields['id'])) {
            return;
        }
        $DB->delete(self::getTable(), [
            'itemtype' => $item::getType(),
            'items_id' => (int)$item->fields['id'],
        ]);
    }

    /**
     * item_delete: a binned item can be restored, so mark read rather than
     * delete. The badge stops nagging, the history survives.
     */
    public static function silenceForItem($item): void
    {
        global $DB;
        if (!is_object($item) || !isset($item->fields['id'])) {
            return;
        }

        self::ensureNotificationsSchema();

        $DB->update(
            self::getTable(),
            ['is_read' => 1, 'date_mod' => new QueryExpression('NOW()')],
            [
                'itemtype' => $item::getType(),
                'items_id' => (int)$item->fields['id'],
                'is_read'  => 0,
            ]
        );
    }

    // ------------------------------------------------------------------ cron

    public static function cronInfo($name): array
    {
        switch ($name) {
            case 'NotifierCleanup':
                return ['description' => __('Purge old in-app notifications', 'notifier')];
            case 'NotifierDeadline':
                return ['description' => __('Notify assignees about approaching resolution deadlines', 'notifier')];
        }
        return [];
    }

    /** Unread rows get three times the configured age before they go too. */
    public static function cronNotifierCleanup(CronTask $task): int
    {
        global $DB;

        $days = Config::get('retention_days');
        if ($days <= 0) {
            return 0;
        }

        self::ensureNotificationsSchema();

        $deleted = 0;

        $DB->delete(self::getTable(), [
            'is_read' => 1,
            new QueryExpression('`date_creation` < NOW() - INTERVAL ' . (int)$days . ' DAY'),
        ]);
        $deleted += $DB->affectedRows();

        $DB->delete(self::getTable(), [
            new QueryExpression('`date_creation` < NOW() - INTERVAL ' . (int)($days * 3) . ' DAY'),
        ]);
        $deleted += $DB->affectedRows();

        $deleted += Note::purgeDone();

        if ($deleted > 0) {
            $task->addVolume($deleted);
            return 1;
        }
        return 0;
    }

    /** Once approaching, once breached, per assignee per item. */
    public static function cronNotifierDeadline(CronTask $task): int
    {
        if (!Config::get('deadline_enabled') || !Config::isEventEnabled(self::EVENT_DEADLINE)) {
            return 0;
        }

        self::ensureNotificationsSchema();

        $lead     = max(1, Config::get('deadline_lead_minutes'));
        $produced = 0;

        foreach (['Ticket', 'Change', 'Problem'] as $itemtype) {
            $produced += self::scanDeadlines($itemtype, $lead);
        }

        if ($produced > 0) {
            $task->addVolume($produced);
            return 1;
        }
        return 0;
    }

    private static function scanDeadlines(string $itemtype, int $leadMinutes): int
    {
        global $DB;

        $table   = $itemtype::getTable();
        $linkMap = self::itilLinkMap();
        if (!isset($linkMap[$itemtype])) {
            return 0;
        }

        $closed = array_merge(
            $itemtype::getSolvedStatusArray(),
            $itemtype::getClosedStatusArray()
        );

        // Each negation is its own nested group: two 'NOT' keys in one
        // flat array would silently collapse into the last one.
        $rs = $DB->request([
            'SELECT' => ['id', 'name', 'entities_id', 'time_to_resolve'],
            'FROM'   => $table,
            'WHERE'  => [
                'is_deleted' => 0,
                ['NOT' => ['status' => $closed]],
                ['NOT' => ['time_to_resolve' => null]],
                new QueryExpression(
                    '`time_to_resolve` <= NOW() + INTERVAL ' . (int)$leadMinutes . ' MINUTE'
                ),
            ],
            'ORDER'  => ['time_to_resolve ASC'],
            'LIMIT'  => self::DEADLINE_SCAN_LIMIT,
        ]);

        $items = [];
        foreach ($rs as $row) {
            $items[(int)$row['id']] = $row;
        }
        if (empty($items)) {
            return 0;
        }

        if (count($items) === self::DEADLINE_SCAN_LIMIT) {
            Toolbox::logInFile(
                'notifier',
                sprintf(
                    "Deadline scan for %s hit the %d item cap; remaining items are handled next run.\n",
                    $itemtype,
                    self::DEADLINE_SCAN_LIMIT
                )
            );
        }

        $itemIds   = array_keys($items);
        $assignees = self::collectAssigneesBulk($itemtype, $itemIds);
        $existing  = self::existingDeadlineRows($itemtype, $itemIds);

        $now      = time();
        $produced = 0;

        foreach ($items as $id => $row) {
            if (empty($assignees[$id])) {
                continue;
            }

            $due = strtotime((string)$row['time_to_resolve']);
            if ($due === false) {
                continue;
            }

            $breached = $due <= $now;
            // A row only counts inside the current window, so moving
            // time_to_resolve re-arms both phases.
            $since   = $breached ? $due : $due - ($leadMinutes * 60);
            $message = $breached
                ? __('Resolution deadline passed', 'notifier')
                : __('Resolution deadline approaching', 'notifier');

            $targets = [];
            foreach ($assignees[$id] as $uid => $channel) {
                $seenAt = $existing[$id][$uid] ?? null;
                if ($seenAt !== null && $seenAt >= $since) {
                    continue;
                }
                $targets[$uid] = $channel;
            }

            if (empty($targets)) {
                continue;
            }

            $title = sprintf(
                '[%s #%d] %s',
                $itemtype,
                $id,
                Toolbox::substr((string)($row['name'] ?? ('#' . $id)), 0, 180)
            );

            self::dispatch($targets, [
                'itemtype'    => $itemtype,
                'items_id'    => $id,
                'entities_id' => (int)$row['entities_id'],
                'event'       => self::EVENT_DEADLINE,
                'title'       => $title,
                'message'     => $message,
                'url'         => $itemtype::getFormURLWithID($id, false),
            ]);

            $produced += count($targets);
        }

        return $produced;
    }

    /**
     * @param  int[] $itemIds
     * @return array<int, array<int, string>> [items_id => [users_id => channel]]
     */
    private static function collectAssigneesBulk(string $itemtype, array $itemIds): array
    {
        global $DB;

        $map = self::itilLinkMap()[$itemtype] ?? null;
        if ($map === null || empty($itemIds)) {
            return [];
        }
        $fk = $map['fk'];

        $result = [];

        $rs = $DB->request([
            'SELECT' => [$fk, 'users_id'],
            'FROM'   => $map['users'],
            'WHERE'  => [$fk => $itemIds, 'type' => 2],
        ]);
        foreach ($rs as $row) {
            $uid = (int)$row['users_id'];
            if ($uid > 0) {
                $result[(int)$row[$fk]][$uid] = 'direct';
            }
        }

        $groupsByItem = [];
        $allGroups    = [];
        $rs = $DB->request([
            'SELECT' => [$fk, 'groups_id'],
            'FROM'   => $map['groups'],
            'WHERE'  => [$fk => $itemIds, 'type' => 2],
        ]);
        foreach ($rs as $row) {
            $gid = (int)$row['groups_id'];
            if ($gid > 0) {
                $groupsByItem[(int)$row[$fk]][$gid] = $gid;
                $allGroups[$gid] = $gid;
            }
        }

        if (!empty($allGroups)) {
            $membersByGroup = [];
            $rs = $DB->request([
                'SELECT' => ['groups_id', 'users_id'],
                'FROM'   => 'glpi_groups_users',
                'WHERE'  => ['groups_id' => array_values($allGroups)],
            ]);
            foreach ($rs as $row) {
                $uid = (int)$row['users_id'];
                if ($uid > 0) {
                    $membersByGroup[(int)$row['groups_id']][] = $uid;
                }
            }

            foreach ($groupsByItem as $itemId => $groups) {
                foreach ($groups as $gid) {
                    foreach ($membersByGroup[$gid] ?? [] as $uid) {
                        if (!isset($result[$itemId][$uid])) {
                            $result[$itemId][$uid] = 'group';
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Newest deadline row per (item, user) as an epoch, so the caller can
     * tell which phase was already announced.
     *
     * @param  int[] $itemIds
     * @return array<int, array<int, int>>
     */
    private static function existingDeadlineRows(string $itemtype, array $itemIds): array
    {
        global $DB;

        if (empty($itemIds)) {
            return [];
        }

        $rs = $DB->request([
            'SELECT' => [
                'items_id',
                'users_id',
                new QueryExpression('MAX(UNIX_TIMESTAMP(`date_creation`)) AS `seen_at`'),
            ],
            'FROM'    => self::getTable(),
            'WHERE'   => [
                'itemtype' => $itemtype,
                'items_id' => $itemIds,
                'event'    => self::EVENT_DEADLINE,
            ],
            'GROUPBY' => ['items_id', 'users_id'],
        ]);

        $seen = [];
        foreach ($rs as $row) {
            $seen[(int)$row['items_id']][(int)$row['users_id']] = (int)$row['seen_at'];
        }
        return $seen;
    }
}
