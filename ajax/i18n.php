<?php

// Hydrates the JS-side T dictionary so the bell respects the session
// language. JS keeps English fallbacks if this fails.

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkLoginUser();

echo json_encode([
    'notifications'        => __('Notifications', 'notifier'),
    'markAllRead'          => __('Mark all as read', 'notifier'),
    'markAsRead'           => __('Mark as read', 'notifier'),
    'markAsUnread'         => __('Mark as unread', 'notifier'),
    'noNotifications'      => __('No notifications', 'notifier'),
    'noNotificationsHint'  => __("You're all caught up.", 'notifier'),
    'minimize'             => __('Minimize', 'notifier'),
    'expand'               => __('Expand notifications', 'notifier'),
    'tabAll'               => __('All', 'notifier'),
    'tabUnread'            => __('Unread', 'notifier'),
    'settings'             => __('Settings', 'notifier'),
    'preferencesTitle'     => __('Notification preferences', 'notifier'),
    'preferencesIntro'     => __('Choose which updates you want to receive. Direct updates are about items assigned to you; group updates are about items assigned to one of your groups.', 'notifier'),
    'colDirect'            => __('Assigned to me', 'notifier'),
    'colGroup'             => __('Assigned to my group', 'notifier'),
    'typeTicket'           => __('Tickets', 'notifier'),
    'typeChange'           => __('Changes', 'notifier'),
    'typeProblem'          => __('Problems', 'notifier'),
    'typeProjectTask'      => __('Project tasks', 'notifier'),
    'save'                 => __('Save', 'notifier'),
    'cancel'               => __('Cancel', 'notifier'),
    'saved'                => __('Preferences saved', 'notifier'),
    'close'                => __('Close', 'notifier'),
    'groupedUpdates'       => __('{n} updates', 'notifier'),
    'expandGroup'          => __('Show all updates', 'notifier'),
    'collapseGroup'        => __('Hide updates', 'notifier'),
]);
