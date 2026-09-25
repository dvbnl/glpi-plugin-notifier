<?php

namespace GlpiPlugin\Notifier;

use CommonDBTM;
use CommonITILObject;
use ITILFollowup;
use Session;

/**
 * Actions the bell can perform on the source item without navigating.
 * Every entry point re-loads the item and asks GLPI's rights layer: a
 * notification outlives the permissions that produced it, so the row
 * itself proves nothing.
 */
class QuickAction
{
    /** CommonITILActor::ASSIGN, inlined to avoid the hook-context import. */
    private const ACTOR_ASSIGN = 2;

    private const MAX_FOLLOWUP_LENGTH = 4000;

    private const LINK_CLASSES = [
        'Ticket'  => ['class' => 'Ticket_User',  'fk' => 'tickets_id'],
        'Change'  => ['class' => 'Change_User',  'fk' => 'changes_id'],
        'Problem' => ['class' => 'Problem_User', 'fk' => 'problems_id'],
    ];

    public static function supports(string $itemtype): bool
    {
        return isset(self::LINK_CLASSES[$itemtype]);
    }

    /**
     * Everything the itemtype defines minus solved and closed, which
     * need a solution and must go through the real form.
     *
     * @return array<int, string>
     */
    public static function allowedStatuses(string $itemtype): array
    {
        if (!self::supports($itemtype) || !is_a($itemtype, CommonITILObject::class, true)) {
            return [];
        }

        $excluded = array_merge(
            $itemtype::getSolvedStatusArray(),
            $itemtype::getClosedStatusArray()
        );

        $statuses = [];
        foreach ($itemtype::getAllStatusArray(false) as $id => $label) {
            if (in_array((int)$id, $excluded, true)) {
                continue;
            }
            $statuses[(int)$id] = (string)$label;
        }

        return $statuses;
    }

    /** @return CommonDBTM|string the item, or an error slug */
    public static function loadWritable(string $itemtype, int $items_id)
    {
        if (!self::supports($itemtype)) {
            return 'unsupported_itemtype';
        }
        if ($items_id <= 0) {
            return 'bad_request';
        }

        $item = new $itemtype();
        if (!$item->getFromDB($items_id)) {
            return 'not_found';
        }
        if (!empty($item->fields['is_deleted'])) {
            return 'not_found';
        }
        if (!$item->can($items_id, UPDATE)) {
            return 'forbidden';
        }

        return $item;
    }

    public static function take(CommonDBTM $item): array
    {
        $itemtype = $item::getType();
        $users_id = (int)Session::getLoginUserID();
        $items_id = (int)$item->fields['id'];

        if ($users_id <= 0 || !isset(self::LINK_CLASSES[$itemtype])) {
            return ['success' => false, 'error' => 'bad_request'];
        }

        if (method_exists($item, 'canAssign') && !$item->canAssign()) {
            return ['success' => false, 'error' => 'forbidden'];
        }

        $linkClass = self::LINK_CLASSES[$itemtype]['class'];
        $fk        = self::LINK_CLASSES[$itemtype]['fk'];

        $link  = new $linkClass();
        $found = $link->getFromDBByCrit([
            $fk        => $items_id,
            'users_id' => $users_id,
            'type'     => self::ACTOR_ASSIGN,
        ]);
        if ($found) {
            return ['success' => true, 'message' => 'already_assigned'];
        }

        $ok = $link->add([
            $fk        => $items_id,
            'users_id' => $users_id,
            'type'     => self::ACTOR_ASSIGN,
        ]);

        return $ok
            ? ['success' => true, 'message' => 'assigned']
            : ['success' => false, 'error' => 'update_failed'];
    }

    public static function setStatus(CommonDBTM $item, int $status): array
    {
        $allowed = self::allowedStatuses($item::getType());

        if (!isset($allowed[$status])) {
            return ['success' => false, 'error' => 'bad_status'];
        }

        $current = (int)($item->fields['status'] ?? 0);
        if ($current === $status) {
            return ['success' => true, 'message' => 'unchanged'];
        }

        // update() never consults the profile's status matrix; only the form dropdown does.
        if (!$item::isAllowedStatus($current, $status)) {
            return ['success' => false, 'error' => 'forbidden'];
        }

        $ok = $item->update([
            'id'     => (int)$item->fields['id'],
            'status' => $status,
        ]);

        return $ok
            ? ['success' => true, 'message' => 'status_changed']
            : ['success' => false, 'error' => 'update_failed'];
    }

    public static function addFollowup(CommonDBTM $item, string $rawContent): array
    {
        $content = Text::toStoredHtml(mb_substr($rawContent, 0, self::MAX_FOLLOWUP_LENGTH));
        if ($content === '') {
            return ['success' => false, 'error' => 'empty_content'];
        }

        $input = [
            'itemtype' => $item::getType(),
            'items_id' => (int)$item->fields['id'],
            'content'  => $content,
        ];

        $followup = new ITILFollowup();
        if (!$followup->can(-1, CREATE, $input)) {
            return ['success' => false, 'error' => 'forbidden'];
        }

        return $followup->add($input)
            ? ['success' => true, 'message' => 'followup_added']
            : ['success' => false, 'error' => 'update_failed'];
    }
}
