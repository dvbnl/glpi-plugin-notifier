# Changelog

All notable changes to this project will be documented in this file.

## [1.0.6] - 2026-09-25

### Security
- **@-mentions respect item visibility**: a mention now only notifies someone who could already open the item — an actor (directly or through a group) or a user holding the type's "see all" right in the item's entity. Previously anyone who could write a comment could send any active user the title and link of that item by typing their login
- **Quick status change honours the profile's status matrix**: the bell refuses a transition the administrator has forbidden for the profile, just like the status dropdown on the form. Previously only the generic update right was checked
- **Private followups and tasks stay private**: a private followup or task no longer notifies requesters or observers. Only users who may see private items (the "see private" right, or for tasks the assigned technician and technician group) are notified, and mentions in private content follow the same rule

### Changed
- Remaining raw SQL (schema self-healing, the multi-row insert and the entity backfill) is now explicitly marked as such, so a future edit does not mistake it for a query-builder call

## [1.0.5] - 2026-09-15

### Added
- **Reminder from a notification**: every row in the bell now carries a bookmark button that turns the notification into a personal reminder — in an hour, tomorrow morning, or with no time at all. The reminder links back to the item, so "I still need to do something with this" no longer depends on leaving the notification unread. A row whose item already has an open reminder shows the bookmark filled in
- **Movable bell** ([#2](https://github.com/dvbnl/glpi-plugin-notifier/issues/2)): drag the bell when it sits in the way of a form button. It snaps to a grid of roughly 80px steps, shown as dots while dragging, so it always lines up and the edges stay reachable. The panel opens upwards or downwards and left or right depending on where the bell is, the minimize tab tucks into the nearest edge, and the position is remembered per browser. A plain click still opens the panel; only a real drag moves it
- **Entity watch** ([#3](https://github.com/dvbnl/glpi-plugin-notifier/issues/3)): a third channel next to "assigned to me" and "assigned to my group". Technicians who opt in get a bell for every new ticket, change or problem created in an entity they hold the type's update right in, directly or through a recursive profile, even before anyone is assigned. Opt-in per user and per type since an entity can be busy. an administrator can disable the feature instance-wide. Direct and group notifications take precedence, so nobody gets the same event twice

### Changed
- The per-user channel filter is now generic over channels instead of special-casing direct and group, in preparation for the entity channel
- Translation template regenerated; Dutch, French, Spanish, Japanese and British English are complete for the new strings

## [1.0.4] - 2026-07-31

### Added
- **Personal task list**: a third tab in the panel holding a plain checklist — "call Henk at 15:00", "chase that licence tomorrow". Things that are not tickets and do not belong in one, but that you want in the place you already watch. A task can carry a reminder time or none at all; an overdue one turns red, counts into the bell badge, and fires a desktop notification the minute it comes due. Reminders that matured while you were away announce themselves as soon as you load a page. Ticked items stay visible for a week in case you undo, then a cron purges them. Kept deliberately outside the notifications table: a personal note has no entity, no actor and no source item, so neither the entity filter nor the event preferences apply to it
- **@-mentions**: naming someone in a followup, task, solution or the item description now fires a dedicated "You were mentioned" bell (icon: at-sign), even when that person is not an actor on the item. Both GLPI's native rich-text mention markup (`data-user-mention` / `data-user-id`, 10.0.7+) and plain `@login` are recognised. A mention replaces the generic "New comment" bell for that user rather than stacking on top of it, and a broad per-type opt-out cannot silence it — being named is explicit
- **Deadline notifications**: a new `NotifierDeadline` cron task warns every assignee before `time_to_resolve` is reached, and again once it is breached. Exactly one bell per phase per deadline; moving the deadline re-arms both. Lead time is configurable, default 60 minutes
- **Quick actions**: assign the item to yourself, change its status, or post a short followup straight from the bell without leaving the page. Every action re-loads the item and goes through GLPI's own rights check — the notification row grants nothing. Solved and closed are deliberately not offered, since those need a real solution
- **Snooze**: hide a notification for an hour, three hours, or until tomorrow morning instead of marking it read
- **Desktop notifications and sound**: both opt-in per user from the preferences dialog. One system popup per arriving batch rather than one per row; the chime is synthesised in the browser, so no audio file ships and nothing for a strict CSP to block
- **Unread count in the browser tab title**, so the count is visible without switching tabs
- **Search and pagination in the panel**: a search box filters on title and message server-side, and a "Load more" button pages through the history instead of stopping at a hard 25
- **Per-event preferences**: users can now opt out of individual event types ("no more `updated` bells, keep `assigned`") on top of the existing per-type and per-channel matrix
- **Admin configuration page** under Setup > Plugins: polling interval, page size, retention, deadline lead time, feature toggles, global per-event kill switches, and whether the self-service interface gets a bell at all
- **Retention cleanup**: a `NotifierCleanup` cron task purges read notifications past the configured age (default 90 days) and unread ones at three times that, so the table can no longer grow without bound
- **Self-service support**: the bell now appears for every logged-in user, including the self-service (helpdesk) interface, which previously never loaded it. An administrator can restrict it back to the central interface from the configuration page
- **Entity awareness**: notifications record the entity of their source item and are filtered against the viewer's active entities on read. Existing rows are backfilled from their source item during the upgrade
- **Unit tests and CI**: a dependency-free suite (`php tests/run.php`, 81 assertions) covers mention parsing, followup sanitisation, config clamping, and the itemtype/status whitelists. GitHub Actions lints PHP on 8.1–8.3, lints the JavaScript, validates the plugin XML, checks that the version agrees across `setup.php`, `notifier.xml` and this file, and fails the build if `public/` has drifted from `css/` and `js/`

### Changed
- **CSRF protection on every mutating endpoint**: `markread`, `markunread`, `markallread`, `preferences`, `snooze` and `action` now require a per-session secret in an `X-Notifier-Token` header. Cross-origin pages cannot set a custom header without a CORS preflight we never answer, and no form, image or prefetch can set one at all. This replaces the previous unauthenticated GETs, and avoids both GLPI 11's `CheckCsrfListener` rejecting minted tokens on POST and GLPI 10's single-use tokens forcing a second round trip before every click. Requests are additionally rejected when `Sec-Fetch-Site` says they are cross-origin
- **Polling got much cheaper**: a hidden tab stops polling entirely, unchanged responses come back as a bodyless `304` via `ETag`, and an idle session backs off progressively up to 8x the configured interval. The `ETag` fingerprint deliberately tracks expiring snooze timers so a snoozed row reappears on its own
- **Notification rows now carry an avatar**: the actor's initials in a stable per-person colour, with the event type as a corner badge. Grouped rows summarise the actors as "Jane and 2 others" instead of showing only the most recent one
- **Preferences dialog restructured** into three sections — which items, which events, how to be alerted — instead of a single matrix
- **`ajax/i18n.php` and `ajax/csrftoken.php` are gone**, replaced by a single `ajax/boot.php` that returns the session token, translations, instance settings, the user's preferences and the quick-action status list in one request on page load
- **Soft-deleted items no longer nag**: moving a Ticket / Change / Problem / Project task to the bin marks its outstanding bells read instead of deleting them, so a restore is not lossy. Purging still deletes them outright

### Fixed
- **SQL injection in the notification search** (found by security review before release): the new `?q=` parameter was un-escaped by the shared parameter reader before reaching a `LIKE` clause. On GLPI 10 that removed the core's own escaping of superglobals, and GLPI 10's query builder does not escape again — so any authenticated user, self-service included, could break out of the string literal and read arbitrary tables. Parameters bound for SQL are now passed through exactly as the core handed them over, with a regression test covering it
- **Wrong asset base URL on marketplace installs**: the client guessed its endpoint prefix from the page path, which produced `/marketplace/notifier/plugins/notifier/…` and a 404 on every request for plugins installed through the GLPI marketplace rather than dropped into `plugins/`. It now derives the prefix from its own `<script src>`
- **CSRF failure when saving the settings page on GLPI 11**: Symfony's `CheckCsrfListener` already validates and consumes the token before a legacy plugin file runs, so the explicit check afterwards failed on a token that no longer existed
- **Unreadable text in dark themes**: muted text took `--tblr-secondary-color`, which several dark themes set to a near-black that vanishes against their own panel background. It is now derived from the resolved body colour, and the avatar uses a translucent hue with the theme's own text colour, so both work in light and dark without detecting which is active
- **Double-bordered search box**: the theme's own input styling drew a second box inside the search field. The search and quick-action fields now out-specify it
- **Panel could run off the top of the screen** on short viewports, since it is anchored to a bell at the bottom
- **Reconnecting indicator was cryptic and flapped**: it showed a bare icon after a single failed poll. It now waits for two consecutive failures and carries a label
- **`public/` had drifted from `css/` again**: `public/notifier.css` was missing the two validation-icon rules added in 1.0.3, so approval bells rendered without their stamp icon on GLPI 11 — the same class of bug as the 1.0.2 incident. `public/` is now generated by `tools/sync-assets.sh` and CI fails on any drift
- **N+1 query on every poll**: `getForUser()` ran a separate `User::getFromDB()` per row to resolve the actor's name, costing 25 extra queries per user per poll. It is now a single `LEFT JOIN`
- **Group fan-out no longer scales with group size**: assigning a 200-member group previously ran 400 queries inline in the request that saved the ticket (a dedup `SELECT` plus an `INSERT` per member). It is now one recipient check, one dedup scan and one multi-row insert regardless of group size
- **Deduplication compared PHP's clock to the database's**: the 60-second window built its cutoff with PHP's `date()` and compared it against a MySQL `TIMESTAMP`, so any timezone or clock skew between the two either broke deduplication or suppressed real notifications. Timestamps are now stamped and compared entirely by the database
- **Relative times were wrong for anyone not in the server's timezone**: the client parsed the raw database timestamp as browser-local, producing "3h" for a fresh notification, or negative ages. The API now sends an absolute epoch
- **Notifications outlived the permissions that created them**: with no entity filter, a row kept showing its ticket's title after the item moved to another entity or the viewer lost access to it
- **Disabled and deleted user accounts were still receiving notifications**, which mattered most on group fan-out, where stale members are common
- **Unescaped translation strings in `title` attributes** on the row toggle buttons — the only place in the client that skipped `escapeHtml()`. A quote in a translation would have broken out of the attribute
- **`markAllRead` from a background tab** no longer competes with an in-flight poll: concurrent refreshes are collapsed and re-run instead of being dropped
- **Nested interactive elements**: the group row was a `role="button"` containing more buttons. The activatable region is now its own element, and the panel supports arrow-key navigation, Enter/Space activation, Escape to close, and focus trapping in the preferences dialog
- **`prefers-reduced-motion`** is now respected; the bell no longer shakes and panels no longer animate for users who asked not to have that

## [1.0.3] - 2026-05-21

### Added
- **Approval / validation notifications**: adding an approver to a Ticket or Change now fires a bell at the validator with a dedicated "Approval requested" event (icon: stamp). When the validator accepts or refuses, the requester gets an "Approval status changed" bell back. Group validators (GLPI 10.0.7+ `itemtype_target` = `Group`) fan out to every member; older installs that still rely on `users_id_validate` keep working through a fallback. Notifications are filed against the parent Ticket / Change so the existing per-type preferences still apply
- **Auto mark-as-read on item view**: opening a Ticket / Change / Problem / Project task form via any route — search hit, dashboard, direct URL, not just the bell — now clears every outstanding bell for that item for the viewer. Implemented as a `pre_item_form` hook so it runs server-side regardless of how the page was reached

### Fixed
- **Missing bell for validation requests**: `TicketValidation` / `ChangeValidation` were not in the watched-types list, so adding an approver silently produced no notification. They are now wired into `item_add` / `item_update` like every other ITIL child object

## [1.0.2] - 2026-05-06

### Changed
- **Unread tab is now the default**: opening the bell panel lands on the Unread tab so the first thing the user sees is "what still needs my attention". The All tab is right next to it; the user's choice still persists per-browser via `localStorage`, so anyone who explicitly switches to All will keep landing on All
- **Stronger unread visual treatment**: unread rows now carry a soft primary-color background tint plus a bolder title alongside the existing left border, so they read as "needs attention" at a glance rather than as a thin colored stripe
- **Notifications are batched per source object**: every Ticket / Change / Problem / ProjectTask now renders as a single row showing its most recent event, with a chevron and a "{n} updates" count when there are more. Expanding the chevron reveals the full sub-event list (status changes, comments, tasks, ...) for that object. Clicking the row body navigates to the item and marks every unread sub-event as read in one go; a per-group toggle marks the whole batch read or flips it back to unread
- **Bell badge counts source items, not raw events**: the unread badge on the bell and the `(N)` counter in the panel header now show the number of unique source items (tickets/changes/problems/projecttasks) with at least one unread event, matching what the user sees in the batched list. Backed by a new `Notification::countUnreadGroups()` helper that does a `COUNT(DISTINCT itemtype, items_id)` over the unread rows

### Fixed
- **GLPI 11 asset path drift**: `setup.php` serves `public/notifier.{js,css}` on GLPI 11 layouts but those files had drifted from the `js/`/`css/` source since v1.0.0. The build now ships matching copies in both locations, so all the bell UX changes actually reach the browser on GLPI 11 installs

## [1.0.1] - 2026-04-29

### Fixed
- **GLPI 11 compatibility**: every `ajax/*.php` endpoint now guards its bootstrap include with `defined('GLPI_ROOT')`. GLPI 11 routes legacy plugin endpoints through `LegacyFileLoadController`, which has already booted the kernel and defined `GLPI_ROOT`; re-running `/inc/includes.php` emitted a "constant already defined" warning that ended up in the response body and broke the bell's JSON parsing. GLPI 10 still hits these files directly and is unaffected — the include runs as before

## [1.0.0] - 2026-04-14

Initial release.

### Added
- **Central header bell**: a new bell button is injected next to the user avatar in GLPI's top header, with an unread badge, a gentle pulse animation on first load, and a dropdown panel listing the latest notifications. Only loaded for the central (technician) interface; self-service users are not affected
- **Complete ITIL event coverage**: Notifier listens to `item_add` / `item_update` on every ITIL object and turns them into bell rows:
  - **Ticket / Change / Problem** — created concerning you, status changed, title / content / priority / urgency updated, new ITILFollowup (comment), new task, solution proposed (ITILSolution)
  - **Assignment** — Ticket_User / Change_User / Problem_User with `type = ASSIGN` (2) fire a dedicated "You have been assigned" notification for the newly added user; Group_Ticket / Change_Group / Group_Problem assignments fan out to every member of the group
  - **Project task** — created, updated, status / percent-done changed; ProjectTaskTeam additions fan out to the added user, or to every member of an added group
- **Smart target resolution**: for each ITIL event, Notifier resolves every user that should hear about it — direct actors on the item (requester / observer / assign) plus every member of any group attached to the item — and always filters out the acting user so nobody gets a bell for their own action
- **Tabs in the bell panel**: All / Unread toggle at the top of the panel. Selection persists across page loads via `localStorage`
- **Notification preferences modal**: a settings cog in the panel footer opens a per-type, per-channel preferences dialog. Users can opt out of "direct" updates (assignments / mentions on items linked to them personally) and "group" updates (items assigned to a group they're a member of) independently, for each ITIL type: Ticket, Change, Problem, Project task. Defaults to "all on" so no user is silenced out of the box
- **Rich panel layout**: animated pop-in, event-specific icons (assign / comment / status / solution / task / create / update), a colored left border for unread rows, a dedicated footer for the Settings button, and an illustrated empty state with a title and a hint
- **Per-row mark read / mark unread**: a round toggle on each row flips its read state without navigating away
- **One-click redirect + auto mark-read**: clicking a row in the bell dropdown posts a mark-read request and immediately navigates to the source item's form URL
- **Mark all as read**: a button in the panel header marks every unread notification for the session user at once
- **Floating bell with minimize**: the bell lives fixed in the bottom-right of the viewport chat-widget style and can be minimized to a slim edge tab that a user can click to bring it back. State persists via `localStorage`
- **Automatic cleanup**: an `item_purge` hook on all watched types deletes any notification row pointing at the removed item, so the bell never dangles
- **Dedup window**: `Notification::insert()` skips duplicates for the same user / item / event within a 60-second window, preventing bell spam when a form save triggers multiple hooks
- **30 second polling**: the bell polls `ajax/list.php` every 30 seconds while a page is open and re-fetches immediately when the dropdown is opened
- **Per-user preference cache**: `Notification::getPreferences()` memoises results per request so a fan-out to a large group only reads the preferences row once per recipient
- **Multi-language support**: English, Dutch, French, Spanish. Translations cover every user-facing string (bell labels, event messages, preferences modal)
- **CSRF-safe AJAX**: a `ajax/csrftoken.php` endpoint mints fresh one-shot tokens so the mark-read / mark-all-read / preferences POSTs work even after other parts of the page have consumed the form token

### Database
- New table `glpi_plugin_notifier_notifications` (bell rows: recipient, actor, itemtype, items_id, event slug, title, message, url, is_read, date_creation, date_mod)
- New table `glpi_plugin_notifier_preferences` (one row per user, boolean flags per ITIL type × channel). All flags default to `1` so the bell is noisy by default until a user opts out
