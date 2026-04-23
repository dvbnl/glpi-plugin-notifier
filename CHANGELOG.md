# Changelog

All notable changes to this project will be documented in this file.

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
