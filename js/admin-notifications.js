(function () {
    'use strict';

    const root = document.querySelector('[data-admin-notifications]');
    if (!root) return;

    const bell = root.querySelector('.admin-notifications__bell');
    const badge = root.querySelector('.admin-notifications__badge');
    const panel = root.querySelector('.admin-notifications__panel');
    const list = root.querySelector('.admin-notifications__list');
    const status = root.querySelector('.admin-notifications__status');
    let busy = false;
    let pendingOpen = false;
    let expired = false;

    function close() {
        panel.hidden = true;
        bell.setAttribute('aria-expanded', 'false');
    }

    function render(items) {
        list.replaceChildren();
        items.forEach(function (item) {
            const li = document.createElement('li');
            const link = document.createElement('a');
            link.className = 'admin-notifications__item' + (item.unread ? ' is-unread' : '');
            link.href = item.url;
            const message = document.createElement('strong');
            message.textContent = item.message;
            const meta = document.createElement('span');
            meta.className = 'admin-notifications__meta';
            const time = document.createElement('time');
            time.dateTime = item.created_at;
            time.textContent = item.time_label;
            const state = document.createElement('span');
            state.textContent = item.unread ? 'Unread' : 'Read';
            meta.append(time, state);
            link.append(message, meta);
            li.append(link);
            list.append(li);
        });
    }

    // Serialize polling and opening: an older count response cannot overwrite
    // the result of a successful mark-read request in this page.
    async function refresh(opening) {
        if (expired) return;
        if (busy) {
            if (opening) pendingOpen = true;
            return;
        }
        busy = true;
        const controller = new AbortController();
        const timeout = window.setTimeout(function () { controller.abort(); }, 15000);
        try {
            const view = panel.hidden ? 'count' : 'latest';
            const options = { credentials: 'same-origin', cache: 'no-store', signal: controller.signal };
            if (opening) {
                options.method = 'POST';
                options.body = new URLSearchParams({ action: 'open', csrf_token: root.dataset.csrf });
            }
            const response = await fetch(root.dataset.endpoint + '?view=' + view, options);
            const data = await response.json();
            if (response.status === 401) expired = true;
            if (!response.ok || !data.ok) {
                throw new Error(data.message || 'Notifications are temporarily unavailable.');
            }
            badge.textContent = data.badge;
            bell.setAttribute('aria-label', 'Notifications, ' + data.unread_count + ' unread');
            if (!panel.hidden && data.notifications) {
                render(data.notifications);
                status.textContent = data.notifications.length === 0
                    ? 'No new-order notifications yet.'
                    : (data.unread_count > 0
                        ? data.unread_count + ' unread. Close and reopen the bell to mark new arrivals as read.'
                        : 'All notifications are read.');
            }
            if (opening || data.unread_count === 0) {
                // These rows predate the open snapshot, or the database confirms
                // nothing remains unread. No second state mutation is needed.
                document.querySelectorAll('.admin-notifications-history .is-unread').forEach(function (item) {
                    item.classList.remove('is-unread');
                    item.querySelector('[data-read-label]').textContent = 'Read';
                });
            }
        } catch (error) {
            badge.textContent = '!';
            bell.setAttribute('aria-label', 'Notifications unavailable');
            status.textContent = error.name === 'AbortError'
                ? 'Notifications timed out. Close and reopen the bell to retry.'
                : error.message;
        } finally {
            window.clearTimeout(timeout);
            busy = false;
            if (pendingOpen) {
                pendingOpen = false;
                if (!panel.hidden) refresh(true);
            }
        }
    }

    bell.addEventListener('click', function () {
        if (!panel.hidden) {
            close();
            return;
        }
        panel.hidden = false;
        bell.setAttribute('aria-expanded', 'true');
        if (!expired) {
            status.textContent = 'Loading notifications...';
            refresh(true);
        }
    });
    document.addEventListener('click', function (event) {
        if (!root.contains(event.target)) close();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !panel.hidden) {
            close();
            bell.focus();
        }
    });
    root.addEventListener('focusout', function (event) {
        if (event.relatedTarget && !root.contains(event.relatedTarget)) close();
    });
    // Admin-only refresh. GET never marks arrivals read while the bell is open.
    window.setInterval(function () {
        if (!document.hidden) refresh(false);
    }, 30000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) refresh(false);
    });
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            if (document.querySelector('.admin-notifications-history')) {
                window.location.reload();
            } else {
                refresh(false);
            }
        }
    });
})();
