/**
 * Notifier plugin — bell widget.
 *
 * Responsibilities:
 *   1. Inject a bell button into GLPI's top header next to the user menu.
 *   2. Poll /plugins/notifier/ajax/list.php for unread count + latest items.
 *   3. Render a dropdown panel showing the items, with All/Unread tabs.
 *   4. Wire click-to-open (redirect to the item URL, mark row as read).
 *   5. Wire "mark all as read" action.
 *   6. Expose a settings modal for per-type, per-channel notification
 *      preferences (direct vs. group updates per ITIL type).
 *
 * Runs only in the central interface — setup.php skips the JS hook in
 * helpdesk mode, so no need to guard here.
 */
(function() {
    'use strict';

    var POLL_INTERVAL_MS = 30000;
    var LS_COLLAPSED_KEY = 'notifier:collapsed';
    var LS_TAB_KEY       = 'notifier:tab';       // 'all' | 'unread'
    var BASE_URL = null;      // resolved once we find the GLPI root
    var pollTimer = null;
    var pollInFlight = false;

    // Pref flag columns kept in one place so the modal and the save-payload
    // agree on ordering/typing. Each entry is [slug, itemTypeLabel, directKey, groupKey].
    // itemTypeLabel is a T-key name (not the translated string) — the modal
    // renderer looks it up in T so the row titles stay i18n-aware.
    var PREF_TYPES = [
        { slug: 'ticket',      typeLabelKey: 'typeTicket',      direct: 'notify_ticket_direct',      group: 'notify_ticket_group' },
        { slug: 'change',      typeLabelKey: 'typeChange',      direct: 'notify_change_direct',      group: 'notify_change_group' },
        { slug: 'problem',     typeLabelKey: 'typeProblem',     direct: 'notify_problem_direct',     group: 'notify_problem_group' },
        { slug: 'projecttask', typeLabelKey: 'typeProjectTask', direct: 'notify_projecttask_direct', group: 'notify_projecttask_group' }
    ];

    // Translation dictionary, hydrated from ajax/i18n.php at boot. The
    // English values act as fallbacks until the request resolves (and if
    // the endpoint ever fails). Every user-facing string flows through
    // here so the bell respects the GLPI session language.
    var T = {
        notifications:       'Notifications',
        markAllRead:         'Mark all as read',
        markAsRead:          'Mark as read',
        markAsUnread:        'Mark as unread',
        noNotifications:     'No notifications',
        noNotificationsHint: "You're all caught up.",
        minimize:            'Minimize',
        expand:              'Expand notifications',
        tabAll:              'All',
        tabUnread:           'Unread',
        settings:            'Settings',
        preferencesTitle:    'Notification preferences',
        preferencesIntro:    'Choose which updates you want to receive. Direct updates are about items assigned to you; group updates are about items assigned to one of your groups.',
        colDirect:           'Assigned to me',
        colGroup:            'Assigned to my group',
        typeTicket:          'Tickets',
        typeChange:          'Changes',
        typeProblem:         'Problems',
        typeProjectTask:     'Project tasks',
        save:                'Save',
        cancel:              'Cancel',
        saved:               'Preferences saved',
        close:               'Close'
    };

    // Active client-side state. Mutated by render() and the tab handler.
    var state = {
        items: [],
        unread: 0,
        tab: loadTab()   // 'all' | 'unread'
    };

    // ------------------------------------------------------------------ utils

    function resolveBaseUrl() {
        // GLPI exposes its root path via CFG_GLPI.root_doc in a global.
        // Fall back to location-based detection if the global is missing.
        if (typeof window.CFG_GLPI === 'object' && window.CFG_GLPI && window.CFG_GLPI.root_doc) {
            return window.CFG_GLPI.root_doc + '/plugins/notifier';
        }
        var match = window.location.pathname.match(/^(.*?)\/(front|plugins|index\.php)/);
        var root  = match ? match[1] : '';
        return root + '/plugins/notifier';
    }

    function fetchJson(url, opts) {
        opts = opts || {};
        opts.credentials = 'same-origin';
        opts.headers = opts.headers || {};
        opts.headers['X-Requested-With'] = 'XMLHttpRequest';
        return fetch(url, opts).then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function isSameOrigin(url) {
        try {
            // Resolve against the current page so relative URLs
            // ("/front/ticket.form.php?id=1") stay valid.
            var resolved = new URL(url, window.location.href);
            return resolved.origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function timeAgo(dateStr) {
        // GLPI stores TIMESTAMP like "2026-04-14 13:37:00" — treat as local.
        if (!dateStr) return '';
        var d = new Date(dateStr.replace(' ', 'T'));
        var diff = (Date.now() - d.getTime()) / 1000;
        if (diff < 60)    return Math.floor(diff) + 's';
        if (diff < 3600)  return Math.floor(diff / 60) + 'm';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h';
        return Math.floor(diff / 86400) + 'd';
    }

    function loadTab() {
        try {
            var v = localStorage.getItem(LS_TAB_KEY);
            return v === 'unread' ? 'unread' : 'all';
        } catch (e) { return 'all'; }
    }
    function saveTab(tab) {
        try { localStorage.setItem(LS_TAB_KEY, tab); } catch (e) { /* ignore */ }
    }

    // ---------------------------------------------------------------- mount

    function buildBell() {
        var wrap = document.createElement('div');
        wrap.className = 'notifier-bell-wrap';
        wrap.innerHTML = ''
            + '<button type="button" class="notifier-bell-btn" aria-label="' + escapeHtml(T.notifications) + '" aria-haspopup="true" aria-expanded="false">'
            +   '<i class="fas fa-bell"></i>'
            +   '<span class="notifier-bell-badge" hidden>0</span>'
            + '</button>'
            // Minimize tab: visible only when the wrap has .is-collapsed.
            // Clicking it uncollapses + opens the panel in one go.
            + '<button type="button" class="notifier-bell-restore" aria-label="' + escapeHtml(T.expand) + '" title="' + escapeHtml(T.expand) + '">'
            +   '<i class="fas fa-chevron-left"></i>'
            + '</button>'
            + '<div class="notifier-bell-panel" role="dialog" aria-label="' + escapeHtml(T.notifications) + '" hidden>'
            +   '<div class="notifier-bell-panel-header">'
            +     '<div class="notifier-bell-panel-titlebar">'
            +       '<span class="notifier-bell-panel-title">'
            +         escapeHtml(T.notifications)
            +         ' <span class="notifier-bell-panel-count">(0)</span>'
            +       '</span>'
            +       '<button type="button" class="notifier-bell-minimize" title="' + escapeHtml(T.minimize) + '" aria-label="' + escapeHtml(T.minimize) + '">'
            +         '<i class="fas fa-minus"></i>'
            +       '</button>'
            +     '</div>'
            +     '<div class="notifier-bell-tabs" role="tablist">'
            +       '<button type="button" class="notifier-bell-tab" data-tab="all" role="tab" aria-selected="true">' + escapeHtml(T.tabAll) + '</button>'
            +       '<button type="button" class="notifier-bell-tab" data-tab="unread" role="tab" aria-selected="false">' + escapeHtml(T.tabUnread) + '</button>'
            +       '<button type="button" class="notifier-bell-markall">' + escapeHtml(T.markAllRead) + '</button>'
            +     '</div>'
            +   '</div>'
            +   '<div class="notifier-bell-panel-body">'
            +     '<ul class="notifier-bell-list" role="list"></ul>'
            +     '<div class="notifier-bell-empty" hidden>'
            +       '<div class="notifier-bell-empty-art" aria-hidden="true">'
            +         '<i class="fas fa-bell-slash"></i>'
            +       '</div>'
            +       '<div class="notifier-bell-empty-title">' + escapeHtml(T.noNotifications) + '</div>'
            +       '<div class="notifier-bell-empty-hint">' + escapeHtml(T.noNotificationsHint) + '</div>'
            +     '</div>'
            +   '</div>'
            +   '<div class="notifier-bell-panel-footer">'
            +     '<button type="button" class="notifier-bell-settings" title="' + escapeHtml(T.settings) + '">'
            +       '<i class="fas fa-cog"></i> ' + escapeHtml(T.settings)
            +     '</button>'
            +   '</div>'
            + '</div>';
        return wrap;
    }

    /**
     * Apply a collapsed/uncollapsed state to the wrap, syncing localStorage
     * and ARIA state. `wrap` may be null for a no-op (used before mount).
     */
    function setCollapsed(wrap, collapsed) {
        try { localStorage.setItem(LS_COLLAPSED_KEY, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
        if (!wrap) return;
        wrap.classList.toggle('is-collapsed', !!collapsed);
        var btn = wrap.querySelector('.notifier-bell-btn');
        if (btn) btn.setAttribute('aria-hidden', collapsed ? 'true' : 'false');
        if (collapsed) {
            // Always close the panel when we minimize.
            var panel = wrap.querySelector('.notifier-bell-panel');
            if (panel) panel.hidden = true;
            if (btn) btn.setAttribute('aria-expanded', 'false');
        }
    }

    function isStoredCollapsed() {
        try { return localStorage.getItem(LS_COLLAPSED_KEY) === '1'; } catch (e) { return false; }
    }

    function installBell() {
        if (document.querySelector('.notifier-bell-wrap')) return true;  // already mounted
        // GLPI's header DOM varies wildly across versions and themes, and
        // every selector-based mount we tried had edge cases (bell inside
        // a collapsed dropdown, inside a zero-width btn-group, etc).
        // Just float the bell fixed top-right — it's independent of any
        // GLPI markup and always visible.
        var bell = buildBell();
        bell.classList.add('notifier-bell-floating');
        document.body.appendChild(bell);
        wireEvents(bell);
        return true;
    }

    // ---------------------------------------------------------------- render

    function visibleItems() {
        if (state.tab === 'unread') {
            return state.items.filter(function(i) { return !i.is_read; });
        }
        return state.items;
    }

    function render() {
        var wrap = document.querySelector('.notifier-bell-wrap');
        if (!wrap) return;

        var badge = wrap.querySelector('.notifier-bell-badge');
        if (state.unread > 0) {
            badge.textContent = state.unread > 99 ? '99+' : String(state.unread);
            badge.hidden = false;
            wrap.classList.add('has-unread');
        } else {
            badge.hidden = true;
            wrap.classList.remove('has-unread');
        }

        // Title counter in the panel header mirrors the unread count so the
        // dropdown looks "alive" even when the bell badge is eclipsed by an
        // open panel (e.g. the user just clicked it open).
        var countEl = wrap.querySelector('.notifier-bell-panel-count');
        if (countEl) countEl.textContent = '(' + state.unread + ')';

        // Sync tab button active state.
        var tabBtns = wrap.querySelectorAll('.notifier-bell-tab');
        tabBtns.forEach(function(btn) {
            var isActive = btn.dataset.tab === state.tab;
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-selected', String(isActive));
        });

        var list  = wrap.querySelector('.notifier-bell-list');
        var empty = wrap.querySelector('.notifier-bell-empty');
        list.innerHTML = '';

        var items = visibleItems();
        if (!items.length) {
            empty.hidden = false;
            return;
        }
        empty.hidden = true;

        items.forEach(function(item) {
            var li = document.createElement('li');
            li.className = 'notifier-bell-item notifier-event-' + escapeHtml(item.event)
                + (item.is_read ? ' is-read' : ' is-unread');
            li.dataset.id = item.id;
            li.dataset.url = item.url;
            // Toggle button: a check on unread rows (click = mark read), or
            // an undo arrow on read rows (click = mark unread again). Data
            // attribute drives which endpoint the click handler calls.
            var toggleAction = item.is_read ? 'unread' : 'read';
            var toggleIcon   = item.is_read ? 'fa-rotate-left' : 'fa-check';
            var toggleLabel  = item.is_read ? T.markAsUnread : T.markAsRead;
            var toggleHtml = '<button type="button" class="notifier-bell-item-toggle"'
                + ' data-action="' + toggleAction + '"'
                + ' title="' + toggleLabel + '" aria-label="' + toggleLabel + '">'
                + '<i class="fas ' + toggleIcon + '"></i>'
                + '</button>';
            li.innerHTML = ''
                + '<div class="notifier-bell-item-icon"><i class="fas ' + eventIcon(item.event) + '"></i></div>'
                + '<div class="notifier-bell-item-body">'
                +   '<div class="notifier-bell-item-title">' + escapeHtml(item.title) + '</div>'
                +   '<div class="notifier-bell-item-msg">'
                +     (item.actor_name ? '<strong>' + escapeHtml(item.actor_name) + '</strong> ' : '')
                +     escapeHtml(item.message)
                +   '</div>'
                +   '<div class="notifier-bell-item-meta">' + escapeHtml(timeAgo(item.date_creation)) + '</div>'
                + '</div>'
                + toggleHtml;
            list.appendChild(li);
        });
    }

    /**
     * Map an event slug to a distinctive Font Awesome icon. Falls back to
     * fa-bell so an unknown event still renders something legible.
     */
    function eventIcon(event) {
        switch (event) {
            case 'assigned':       return 'fa-user-check';
            case 'commented':      return 'fa-comment-dots';
            case 'status_changed': return 'fa-arrows-rotate';
            case 'solution':       return 'fa-lightbulb';
            case 'task_added':     return 'fa-list-check';
            case 'created':        return 'fa-plus';
            case 'updated':        return 'fa-pen';
            default:               return 'fa-bell';
        }
    }

    /**
     * Fire a mark-read request. GET avoids GLPI 11's Symfony CheckCsrfListener
     * which only runs on POST routes; the endpoint is still login + rights
     * protected and only mutates rows owned by the session user.
     */
    function fireMarkRead(id) {
        return fetchJson(BASE_URL + '/ajax/markread.php?id=' + encodeURIComponent(id))
            .catch(function(err) {
                if (window.console) console.warn('[notifier] markread failed:', err);
            });
    }

    /**
     * Fire a mark-unread request (undoes markRead).
     */
    function fireMarkUnread(id) {
        return fetchJson(BASE_URL + '/ajax/markunread.php?id=' + encodeURIComponent(id))
            .catch(function(err) {
                if (window.console) console.warn('[notifier] markunread failed:', err);
            });
    }

    // ---------------------------------------------------------------- preferences modal

    /**
     * Render the preferences modal. The modal is injected lazily the first
     * time the settings button is clicked and reused afterwards — cheaper
     * than rebuilding on every open, and lets us reuse the same DOM for
     * state restoration on cancel.
     */
    function buildPreferencesModal() {
        var overlay = document.createElement('div');
        overlay.className = 'notifier-modal-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', T.preferencesTitle);
        overlay.hidden = true;

        var rows = PREF_TYPES.map(function(p) {
            return ''
                + '<tr>'
                +   '<th scope="row">' + escapeHtml(T[p.typeLabelKey] || p.slug) + '</th>'
                +   '<td>'
                +     '<label class="notifier-switch" title="' + escapeHtml(T.colDirect) + '">'
                +       '<input type="checkbox" data-pref="' + p.direct + '" checked>'
                +       '<span class="notifier-switch-slider"></span>'
                +     '</label>'
                +   '</td>'
                +   '<td>'
                +     '<label class="notifier-switch" title="' + escapeHtml(T.colGroup) + '">'
                +       '<input type="checkbox" data-pref="' + p.group + '" checked>'
                +       '<span class="notifier-switch-slider"></span>'
                +     '</label>'
                +   '</td>'
                + '</tr>';
        }).join('');

        overlay.innerHTML = ''
            + '<div class="notifier-modal">'
            +   '<div class="notifier-modal-header">'
            +     '<h3 class="notifier-modal-title"><i class="fas fa-cog"></i> ' + escapeHtml(T.preferencesTitle) + '</h3>'
            +     '<button type="button" class="notifier-modal-close" aria-label="' + escapeHtml(T.close) + '" title="' + escapeHtml(T.close) + '">'
            +       '<i class="fas fa-times"></i>'
            +     '</button>'
            +   '</div>'
            +   '<div class="notifier-modal-body">'
            +     '<p class="notifier-modal-intro">' + escapeHtml(T.preferencesIntro) + '</p>'
            +     '<table class="notifier-pref-table">'
            +       '<thead>'
            +         '<tr>'
            +           '<th></th>'
            +           '<th>' + escapeHtml(T.colDirect) + '</th>'
            +           '<th>' + escapeHtml(T.colGroup) + '</th>'
            +         '</tr>'
            +       '</thead>'
            +       '<tbody>' + rows + '</tbody>'
            +     '</table>'
            +     '<div class="notifier-modal-toast" hidden><i class="fas fa-check-circle"></i> ' + escapeHtml(T.saved) + '</div>'
            +   '</div>'
            +   '<div class="notifier-modal-footer">'
            +     '<button type="button" class="notifier-btn notifier-btn-secondary" data-action="cancel">' + escapeHtml(T.cancel) + '</button>'
            +     '<button type="button" class="notifier-btn notifier-btn-primary" data-action="save">' + escapeHtml(T.save) + '</button>'
            +   '</div>'
            + '</div>';

        return overlay;
    }

    function ensurePreferencesModal() {
        var existing = document.querySelector('.notifier-modal-overlay');
        if (existing) return existing;
        var overlay = buildPreferencesModal();
        document.body.appendChild(overlay);
        wirePreferencesModal(overlay);
        return overlay;
    }

    function wirePreferencesModal(overlay) {
        var modal = overlay.querySelector('.notifier-modal');

        // Clicking the backdrop closes without saving; clicks inside the
        // modal body should not propagate up and trigger that close.
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                closePreferences(overlay);
            }
        });
        modal.addEventListener('click', function(e) { e.stopPropagation(); });

        overlay.querySelector('.notifier-modal-close').addEventListener('click', function() {
            closePreferences(overlay);
        });
        overlay.querySelector('[data-action="cancel"]').addEventListener('click', function() {
            closePreferences(overlay);
        });
        overlay.querySelector('[data-action="save"]').addEventListener('click', function() {
            savePreferences(overlay);
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !overlay.hidden) {
                closePreferences(overlay);
            }
        });
    }

    function openPreferences() {
        var overlay = ensurePreferencesModal();
        overlay.hidden = false;
        // Pull the fresh preferences on every open so the modal reflects
        // any concurrent change (e.g. the user saved in another tab).
        fetchJson(BASE_URL + '/ajax/preferences.php')
            .then(function(resp) {
                var prefs = (resp && resp.preferences) || {};
                overlay.querySelectorAll('input[data-pref]').forEach(function(input) {
                    var key = input.dataset.pref;
                    input.checked = !!(prefs[key] === undefined ? 1 : +prefs[key]);
                });
            })
            .catch(function() { /* keep defaults checked */ });
    }

    function closePreferences(overlay) {
        overlay.hidden = true;
        var toast = overlay.querySelector('.notifier-modal-toast');
        if (toast) toast.hidden = true;
    }

    function savePreferences(overlay) {
        var params = new URLSearchParams();
        params.append('save', '1');
        overlay.querySelectorAll('input[data-pref]').forEach(function(input) {
            params.append(input.dataset.pref, input.checked ? '1' : '0');
        });
        var saveBtn = overlay.querySelector('[data-action="save"]');
        saveBtn.disabled = true;

        // Mint a fresh CSRF token right before the save — the preferences
        // endpoint rejects the call without it. Staying on GET (for the
        // same Symfony-listener reason as mark*.php) is still safe because
        // an attacker on another origin cannot read this token response.
        fetchJson(BASE_URL + '/ajax/csrftoken.php')
            .then(function(r) {
                params.append('_glpi_csrf_token', r && r.token ? r.token : '');
                return fetchJson(BASE_URL + '/ajax/preferences.php?' + params.toString());
            })
            .then(function() {
                var toast = overlay.querySelector('.notifier-modal-toast');
                if (toast) {
                    toast.hidden = false;
                    setTimeout(function() { toast.hidden = true; }, 1800);
                }
                setTimeout(function() { closePreferences(overlay); }, 900);
            })
            .catch(function(err) {
                if (window.console) console.error('[notifier] save preferences failed:', err);
            })
            .then(function() { saveBtn.disabled = false; });
    }

    // ---------------------------------------------------------------- events

    function wireEvents(wrap) {
        var btn      = wrap.querySelector('.notifier-bell-btn');
        var panel    = wrap.querySelector('.notifier-bell-panel');
        var list     = wrap.querySelector('.notifier-bell-list');
        var markAll  = wrap.querySelector('.notifier-bell-markall');
        var minimize = wrap.querySelector('.notifier-bell-minimize');
        var restore  = wrap.querySelector('.notifier-bell-restore');
        var settings = wrap.querySelector('.notifier-bell-settings');
        var tabs     = wrap.querySelectorAll('.notifier-bell-tab');

        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            // If the user clicks the bell while collapsed, treat it as
            // "restore + open" — single click to go from minimized to seeing
            // their notifications.
            if (wrap.classList.contains('is-collapsed')) {
                setCollapsed(wrap, false);
                panel.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                refresh();
                return;
            }
            var open = !panel.hidden;
            panel.hidden = open;
            btn.setAttribute('aria-expanded', String(!open));
            if (!open) {
                refresh();  // fetch fresh data every time the panel opens
            }
        });

        // Restore (uncollapse) tab: slides the bell back on-screen and
        // opens the panel in the same click.
        if (restore) {
            restore.addEventListener('click', function(e) {
                e.stopPropagation();
                setCollapsed(wrap, false);
                panel.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                refresh();
            });
        }

        // Minimize button inside the panel header.
        if (minimize) {
            minimize.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                setCollapsed(wrap, true);
            });
        }

        // Tab switching — no server round-trip; we just filter the cached list.
        tabs.forEach(function(tab) {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                state.tab = tab.dataset.tab === 'unread' ? 'unread' : 'all';
                saveTab(state.tab);
                render();
            });
        });

        if (settings) {
            settings.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                openPreferences();
            });
        }

        // Outside click closes the panel — but not while a modal dialog
        // (preferences) is visible on top of it.
        document.addEventListener('click', function(e) {
            if (panel.hidden) return;
            if (wrap.contains(e.target)) return;
            var overlay = document.querySelector('.notifier-modal-overlay');
            if (overlay && !overlay.hidden && overlay.contains(e.target)) return;
            panel.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        });

        // Item click — three modes:
        //   1. Toggle button on an unread row → mark as read, stay here
        //   2. Toggle button on a read row   → mark as unread, stay here
        //   3. Click anywhere else on the row → mark as read AND redirect
        list.addEventListener('click', function(e) {
            var li = e.target.closest('.notifier-bell-item');
            if (!li) return;
            var id = parseInt(li.dataset.id, 10);
            if (!id) return;

            var toggleBtn = e.target.closest('.notifier-bell-item-toggle');
            if (toggleBtn) {
                e.preventDefault();
                e.stopPropagation();
                var action = toggleBtn.dataset.action;  // 'read' | 'unread'
                var op = action === 'unread' ? fireMarkUnread : fireMarkRead;
                op(id).then(refresh);
                return;
            }

            // Click-to-navigate: fire the markread request and wait for its
            // response before navigating, so the row is guaranteed to be
            // marked as read by the time the target page renders.
            // Reject any non-same-origin target: the `url` column is
            // populated from the hook-side object's getFormURLWithID() and
            // should always be same-origin, but the DB column is a plain
            // VARCHAR(500) with no constraint — a future code path that
            // lets user input flow into it must not turn the bell into an
            // open-redirect phishing vector.
            var url = li.dataset.url;
            if (url && isSameOrigin(url)) {
                e.preventDefault();
                fireMarkRead(id).then(function() {
                    window.location.href = url;
                });
            } else {
                fireMarkRead(id).then(refresh);
            }
        });

        markAll.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (window.console) console.info('[notifier] mark all as read: start');
            fetchJson(BASE_URL + '/ajax/markallread.php')
                .then(function(r) {
                    if (window.console) console.info('[notifier] mark all as read: ok', r);
                    refresh();
                })
                .catch(function(err) {
                    if (window.console) console.error('[notifier] mark all as read failed:', err);
                });
        });
    }

    // ---------------------------------------------------------------- polling

    function refresh() {
        if (pollInFlight) return;
        pollInFlight = true;
        fetchJson(BASE_URL + '/ajax/list.php').then(function(data) {
            state.items  = (data && data.items) || [];
            state.unread = (data && data.unread) || 0;
            render();
        }).catch(function() {
            // Fail silently — bell stays at previous state.
        }).then(function() {
            pollInFlight = false;
        });
    }

    function startPolling() {
        if (pollTimer) return;
        refresh();
        pollTimer = setInterval(refresh, POLL_INTERVAL_MS);
    }

    // ---------------------------------------------------------------- boot

    function mountAndStart() {
        installBell();
        var wrap = document.querySelector('.notifier-bell-wrap');
        if (wrap && isStoredCollapsed()) {
            setCollapsed(wrap, true);
        }
        startPolling();
    }

    function boot() {
        BASE_URL = resolveBaseUrl();
        if (window.console && console.info) {
            console.info('[notifier] booting, BASE_URL =', BASE_URL);
        }
        // Hydrate the translation dict before mounting so every label
        // renders in the session language on first paint.
        fetchJson(BASE_URL + '/ajax/i18n.php')
            .then(function(dict) {
                Object.keys(dict || {}).forEach(function(k) { T[k] = dict[k]; });
            })
            .catch(function() { /* stay on English defaults */ })
            .then(mountAndStart);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
