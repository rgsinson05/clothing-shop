(function () {
    'use strict';

    var panel = document.getElementById('customer-notifications');
    var bells = Array.from(document.querySelectorAll('[data-customer-notification-bell]'));
    if (!panel || !bells.length || !window.fetch || !window.AbortController || panel.notificationController) {
        return;
    }

    var endpoint = new URL(panel.dataset.endpoint, window.location.href);
    if (endpoint.origin !== window.location.origin) {
        return;
    }
    panel.notificationController = true;
    var customerRoot = new URL('../', endpoint);
    var list = panel.querySelector('[data-notification-list]');
    var status = panel.querySelector('[data-notification-status]');
    var retry = panel.querySelector('[data-notification-retry]');
    var signin = panel.querySelector('[data-notification-signin]');
    var more = panel.querySelector('[data-notification-more]');
    var toast = document.querySelector('[data-notification-toast]');
    var notifications = [];
    var entries = new Map();
    var pendingReads = new Map();
    var requests = new Set();
    var requestQueue = Promise.resolve();
    var loading = null;
    var pollTimer = 0;
    var pollInterval = 15000;
    var readAfterRefresh = false;
    var activeBell = null;
    var readTimer = 0;
    var navigationTimer = 0;
    var openVersion = 0;
    var lifecycle = 0;
    var stopped = false;
    var navigating = false;
    var authStatus = 0;
    var toastTimer = 0;
    var toastExit = 0;
    var toastFrame = 0;
    var surfacedId = 0;
    var observedIds = new Set();
    var baselined = false;
    var storage = null;
    var storageKey = 'hopia:customer-notifications:' + endpoint.pathname + ':' + panel.dataset.scope;
    var toastTypes = ['ORDER_SHIPPED', 'ORDER_DELIVERED', 'PICKUP_READY', 'PICKUP_HANDED_OVER', 'ORDER_CANCELLED'];

    ['localStorage', 'sessionStorage'].some(function (name) {
        try {
            storage = window[name];
            storage.getItem(storageKey);
            return true;
        } catch (error) {
            storage = null;
            return false;
        }
    });

    function validCount(value) {
        return Number.isSafeInteger(value) && value >= 0;
    }

    function updateBadge(count) {
        bells.forEach(function (bell) {
            var badge = bell.querySelector('[data-notification-badge]');
            badge.hidden = !validCount(count) || count === 0;
            badge.textContent = badge.hidden ? '' : (count > 9 ? '9+' : String(count));
            bell.setAttribute('aria-label', !validCount(count) ? 'Notifications, currently unavailable'
                : 'Notifications, ' + count + ' unread ' + (count === 1 ? 'update' : 'updates'));
        });
    }

    async function request(method, id) {
        var controller = new AbortController();
        var job = { controller: controller, keepalive: method === 'POST' };
        job.timer = window.setTimeout(function () { controller.abort(); }, 6000);
        requests.add(job);
        var url = new URL(endpoint);
        var options = {
            method: method,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
            signal: controller.signal
        };
        if (method === 'POST') {
            options.headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
            options.body = new URLSearchParams({
                action: 'read', notification_id: String(id), csrf_token: panel.dataset.csrf
            }).toString();
            options.keepalive = true;
        } else {
            url.searchParams.set('view', 'latest');
            url.searchParams.set('limit', '10');
        }
        try {
            var response = await fetch(url.href, options);
            if (!response.ok) {
                var error = new Error('Notification request failed.');
                error.status = response.status;
                throw error;
            }
            var data = await response.json();
            if (!data || data.ok !== true || !validCount(data.unread_count)) {
                throw new Error('Invalid notification response.');
            }
            return data;
        } finally {
            window.clearTimeout(job.timer);
            requests.delete(job);
        }
    }

    function cancelReadDelay() {
        window.clearTimeout(readTimer);
        openVersion += 1;
    }

    function showError(error, reading) {
        if (stopped) { return; }
        cancelReadDelay();
        authStatus = error.status === 401 || error.status === 403 ? error.status : authStatus;
        if (authStatus) { window.clearTimeout(pollTimer); }
        signin.hidden = authStatus !== 401;
        retry.hidden = authStatus === 401;
        retry.textContent = authStatus === 403 ? 'Reload page' : 'Try again';
        status.textContent = authStatus === 401 ? 'Your session has expired. Please sign in again.'
            : authStatus === 403 ? 'Your form session has expired. Please reload the page.'
            : reading ? 'The read status could not be saved. Please try again.'
            : 'Notifications are temporarily unavailable. Please try again.';
        if (!reading || authStatus) {
            updateBadge(null);
            notifications = [];
            entries.clear();
            list.textContent = '';
            list.hidden = true;
            more.hidden = true;
            dismissToast(true);
        }
    }

    function safeDestination(item) {
        var match = typeof item.url === 'string' && /^order-detail\.php\?id=([1-9]\d*)$/.exec(item.url);
        if (!match || !Number.isSafeInteger(item.order_id) || Number(match[1]) !== item.order_id) {
            return null;
        }
        return new URL(item.url, customerRoot).href;
    }

    function render(data) {
        var ids = new Set();
        if (!Array.isArray(data.notifications) || data.notifications.length > 10 || typeof data.has_more !== 'boolean'
            || data.notifications.some(function (item) {
                if (!item || !Number.isSafeInteger(item.id) || item.id < 1 || ids.has(item.id)
                    || typeof item.message !== 'string' || !item.message.trim() || typeof item.read !== 'boolean'
                    || typeof item.created_at !== 'string' || !Number.isFinite(Date.parse(item.created_at))) {
                    return true;
                }
                ids.add(item.id);
                return false;
            })) {
            throw new Error('Invalid notification list.');
        }
        var scrollBox = list.parentElement;
        var scrollTop = scrollBox.scrollTop;
        var scrollBoxTop = scrollBox.getBoundingClientRect().top;
        var anchor = null;
        var anchorTop = 0;
        if (scrollTop > 0) {
            anchor = Array.from(list.children).find(function (row) {
                return ids.has(Number(row.firstElementChild.dataset.notificationId))
                    && row.getBoundingClientRect().bottom > scrollBoxTop;
            });
            if (anchor) {
                anchorTop = anchor.getBoundingClientRect().top;
            }
        }
        notifications = data.notifications.slice().sort(function (a, b) {
            return Date.parse(b.created_at) - Date.parse(a.created_at) || b.id - a.id;
        });
        entries.forEach(function (entry, id) {
            if (!ids.has(id)) { entry.row.remove(); entries.delete(id); }
        });
        var cursor = list.firstChild;
        notifications.forEach(function (item) {
            var href = safeDestination(item);
            var existing = entries.get(item.id);
            if (existing && existing.href === href) {
                existing.element.classList.toggle('is-unread', !item.read);
                existing.message.textContent = item.message;
                existing.time.dateTime = item.created_at;
                existing.time.textContent = typeof item.time_label === 'string' && item.time_label
                    ? item.time_label : new Date(item.created_at).toLocaleString();
                existing.state.textContent = item.read ? 'Read' : 'Unread';
                if (existing.row === cursor) { cursor = cursor.nextSibling; }
                else { list.insertBefore(existing.row, cursor); }
                return;
            }
            if (existing) {
                if (existing.row === cursor) { cursor = cursor.nextSibling; }
                existing.row.remove();
            }
            var li = document.createElement('li');
            var entry = document.createElement(href ? 'a' : 'div');
            entry.className = 'customer-notifications__entry' + (item.read ? '' : ' is-unread');
            entry.dataset.notificationId = String(item.id);
            if (href) {
                entry.href = href;
                entry.setAttribute('data-no-transition', '');
            }
            var message = document.createElement('span');
            message.className = 'customer-notifications__message';
            message.textContent = item.message;
            var meta = document.createElement('span');
            meta.className = 'customer-notifications__meta';
            var time = document.createElement('time');
            time.dateTime = item.created_at;
            time.textContent = typeof item.time_label === 'string' && item.time_label
                ? item.time_label : new Date(item.created_at).toLocaleString();
            var readState = document.createElement('span');
            readState.className = 'customer-notifications__read-state';
            readState.textContent = item.read ? 'Read' : 'Unread';
            meta.append(time, readState);
            entry.append(message, meta);
            if (!href) {
                var unavailable = document.createElement('span');
                unavailable.className = 'customer-notifications__unavailable';
                unavailable.textContent = 'Order details are unavailable.';
                entry.appendChild(unavailable);
            }
            entries.set(item.id, { element: entry, row: li, state: readState, message: message, time: time, href: href });
            li.appendChild(entry);
            list.insertBefore(li, cursor);
        });
        updateBadge(data.unread_count);
        list.hidden = notifications.length === 0;
        status.textContent = notifications.length ? '' : "You're all caught up.";
        retry.hidden = true;
        signin.hidden = true;
        more.hidden = !data.has_more;
        more.textContent = data.has_more
            ? 'Showing the 10 latest updates. Older updates remain saved and unread updates stay in the count.' : '';
        // Restore only after the footer/status establish the final scroll range.
        if (anchor && anchor.isConnected) {
            scrollBox.scrollTop += anchor.getBoundingClientRect().top - anchorTop;
        }
    }

    function markRead(item, version) {
        if (item.read) { return Promise.resolve(); }
        var pending = pendingReads.get(item.id);
        if (pending) {
            // An explicit click may promote a queued automatic read.
            if (version === undefined) { pending.version = undefined; }
            return pending.promise;
        }
        var cycle = lifecycle;
        var job = { version: version };
        // Reads and snapshots share one queue, including their UI updates.
        var operation = requestQueue.then(function () {
            if (stopped || cycle !== lifecycle) { throw new Error('Page closed.'); }
            if (authStatus) { throw { status: authStatus }; }
            if (job.version !== undefined && (panel.hidden || document.hidden || navigating || job.version !== openVersion)) {
                return null;
            }
            return request('POST', item.id);
        }).then(function (data) {
            if (!data || stopped || cycle !== lifecycle) { return; }
            item.read = true;
            notifications.forEach(function (current) {
                if (current.id === item.id) { current.read = true; }
            });
            var entry = entries.get(item.id);
            if (entry) {
                entry.element.classList.remove('is-unread');
                entry.state.textContent = 'Read';
            }
            updateBadge(data.unread_count);
        }).finally(function () {
            if (pendingReads.get(item.id) === job) { pendingReads.delete(item.id); }
        });
        job.promise = operation;
        pendingReads.set(item.id, job);
        // Callers display errors; the queue must still accept a later retry.
        requestQueue = operation.catch(function () {});
        return operation;
    }

    function scheduleRead() {
        if (panel.hidden || document.hidden || navigating || stopped) { return; }
        var version = openVersion;
        var snapshot = notifications.filter(function (item) { return !item.read; });
        if (!snapshot.length) { return; }
        readTimer = window.setTimeout(async function () {
            // Only this retrieved snapshot is read, never unseen rows via read_all.
            for (var item of snapshot) {
                if (panel.hidden || document.hidden || navigating || version !== openVersion || stopped) { break; }
                try {
                    await markRead(item, version);
                } catch (error) {
                    showError(error, true);
                    break;
                }
            }
        }, 1500);
    }

    function schedulePoll() {
        window.clearTimeout(pollTimer);
        if (!stopped && !navigating && !authStatus && !document.hidden && !loading) {
            pollTimer = window.setTimeout(function () { refresh(false); }, pollInterval);
        }
    }

    function refresh(opening) {
        window.clearTimeout(pollTimer);
        if (opening) {
            cancelReadDelay();
            readAfterRefresh = true;
        }
        if (authStatus) {
            showError({ status: authStatus }, false);
            return Promise.resolve();
        }
        if (stopped || navigating || document.hidden) { return Promise.resolve(); }
        if (opening || !baselined) {
            status.textContent = 'Loading your notifications...';
            retry.hidden = true;
            signin.hidden = true;
            panel.setAttribute('aria-busy', 'true');
        }
        if (loading) { return loading; }
        var cycle = lifecycle;
        loading = requestQueue.then(function () {
            if (stopped || cycle !== lifecycle) { throw new Error('Page closed.'); }
            if (authStatus) { throw { status: authStatus }; }
            if (document.hidden || navigating) { return null; }
            return request('GET');
        }).then(function (data) {
            if (!data || stopped || cycle !== lifecycle) { return; }
            render(data);
            surfaceToast();
            // Only a deliberate opening/retry captures IDs for delayed reads.
            // A poll never extends that snapshot to newly arriving rows.
            if (readAfterRefresh) {
                readAfterRefresh = false;
                scheduleRead();
            }
        }).catch(function (error) {
            if (cycle !== lifecycle || stopped) { return; }
            if (error.status === 401 || error.status === 403 || !baselined) {
                showError(error, false);
            } else {
                // Keep the last valid count/list and keyboard focus during outages.
                status.textContent = 'Notifications are temporarily unavailable. Please try again.';
                retry.hidden = false;
            }
            readAfterRefresh = false;
        }).finally(function () {
            if (cycle === lifecycle) {
                loading = null;
                panel.removeAttribute('aria-busy');
                schedulePoll();
            }
        });
        requestQueue = loading.catch(function () {});
        return loading;
    }

    function positionOverlays() {
        if (!toast.hidden) {
            var headerBottom = bells[0].closest('.site-header').getBoundingClientRect().bottom;
            var toastTop = Math.max(12, Math.min(headerBottom + 12, window.innerHeight - 120));
            toast.style.top = toastTop + 'px';
            toast.style.maxHeight = Math.max(0, window.innerHeight - toastTop - 12) + 'px';
        }
        if (panel.hidden) { return; }
        if (!activeBell || !activeBell.getClientRects().length) {
            activeBell = bells.find(function (bell) { return bell.getClientRects().length; });
        }
        if (!activeBell) { closePanel(false); return; }
        bells.forEach(function (bell) { bell.setAttribute('aria-expanded', String(bell === activeBell)); });
        var rect = activeBell.getBoundingClientRect();
        var width = document.documentElement.clientWidth;
        var top = Math.max(12, Math.min(rect.bottom + 8, window.innerHeight - 120));
        panel.style.top = top + 'px';
        panel.style.left = Math.max(12, Math.min(rect.right - panel.offsetWidth, width - panel.offsetWidth - 12)) + 'px';
        panel.style.maxHeight = Math.max(0, window.innerHeight - top - 12) + 'px';
    }

    function closePanel(restoreFocus) {
        cancelReadDelay();
        readAfterRefresh = false;
        panel.hidden = true;
        bells.forEach(function (bell) { bell.setAttribute('aria-expanded', 'false'); });
        if (restoreFocus && activeBell && activeBell.getClientRects().length) { activeBell.focus(); }
    }

    function openPanel(bell) {
        if (!panel.hidden && activeBell === bell) { closePanel(true); return; }
        activeBell = bell;
        var header = bell.closest('.site-header');
        header.classList.remove('nav-open');
        header.querySelectorAll('.header-account.is-open, .nav-dropdown.is-open').forEach(function (menu) {
            menu.classList.remove('is-open');
        });
        header.querySelectorAll('[aria-expanded="true"]').forEach(function (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
        });
        dismissToast(true);
        panel.hidden = false;
        positionOverlays();
        panel.focus({ preventScroll: true });
        refresh(true);
    }

    function dismissToast(immediate) {
        window.clearTimeout(toastTimer);
        window.clearTimeout(toastExit);
        window.cancelAnimationFrame(toastFrame);
        toast.classList.remove('is-visible');
        if (immediate) { toast.hidden = true; }
        else { toastExit = window.setTimeout(function () { toast.hidden = true; }, 180); }
    }

    function armToastDismissal() {
        window.clearTimeout(toastTimer);
        if (!toast.hidden && !toast.contains(document.activeElement) && !toast.matches(':hover')) {
            toastTimer = window.setTimeout(function () { dismissToast(false); }, 6000);
        }
    }

    function surfaceToast() {
        var previous = surfacedId;
        if (storage) {
            try {
                var saved = Number(storage.getItem(storageKey));
                if (validCount(saved)) { previous = Math.max(previous, saved); }
                var savedIds = JSON.parse(storage.getItem(storageKey + ':seen') || '[]');
                if (Array.isArray(savedIds) && savedIds.length <= 200) {
                    savedIds.forEach(function (id) {
                        if (Number.isSafeInteger(id) && id > 0) { observedIds.add(id); }
                    });
                }
            } catch (error) { storage = null; }
        }
        var initial = !baselined;
        var candidate = notifications.find(function (item) {
            var age = Date.now() - Date.parse(item.created_at);
            return !observedIds.has(item.id) && !item.read && toastTypes.includes(item.type)
                && (!initial || (item.id > previous && age >= 0 && age <= 15 * 60 * 1000));
        });
        notifications.forEach(function (item) { observedIds.add(item.id); });
        baselined = true;
        surfacedId = notifications.reduce(function (max, item) { return Math.max(max, item.id); }, previous);
        var remembered = false;
        if (storage) {
            try {
                storage.setItem(storageKey, String(surfacedId));
                storage.setItem(storageKey + ':seen', JSON.stringify(Array.from(observedIds).slice(-200)));
                remembered = true;
            } catch (error) { storage = null; }
        }
        // IDs only, never notification text. The legacy high-water ID is used
        // only for the restrained initial toast, not later out-of-order arrivals.
        // With storage blocked, baseline silently on each page but allow live toasts.
        if (!candidate || (initial && !remembered) || !panel.hidden || document.hidden) { return; }
        dismissToast(true);
        toast.hidden = false;
        toast.querySelector('[data-notification-toast-message]').textContent = candidate.message;
        positionOverlays();
        toastFrame = window.requestAnimationFrame(function () { toast.classList.add('is-visible'); });
        armToastDismissal();
    }

    function selectNotification(event) {
        var link = event.target.closest('a[data-notification-id]');
        if (!link || event.button > 1) { return; }
        var item = notifications.find(function (current) { return current.id === Number(link.dataset.notificationId); });
        if (!item) { event.preventDefault(); return; }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button === 1) {
            markRead(item).catch(function (error) { showError(error, true); });
            return;
        }
        // Prevent native navigation before the shared footer's click handler runs.
        event.preventDefault();
        if (navigating) { return; }
        navigating = true;
        cancelReadDelay();
        window.clearTimeout(pollTimer);
        status.textContent = 'Opening your order...';
        var href = link.href;
        var finished = false;
        function navigate() {
            if (finished || stopped) { return; }
            finished = true;
            window.clearTimeout(navigationTimer);
            window.location.assign(href);
        }
        // A failed/slow read must not trap the customer. Started writes use keepalive.
        navigationTimer = window.setTimeout(navigate, 1800);
        markRead(item).then(navigate, function (error) {
            showError(error, true);
            navigate();
        });
    }

    bells.forEach(function (bell) {
        bell.hidden = false;
        bell.addEventListener('click', function () { openPanel(bell); });
        bell.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') { event.preventDefault(); openPanel(bell); }
        });
    });
    panel.querySelector('[data-notification-close]').addEventListener('click', function () { closePanel(true); });
    retry.addEventListener('click', function () {
        if (authStatus === 403) { window.location.reload(); }
        else { refresh(true); }
    });
    panel.addEventListener('click', selectNotification);
    panel.addEventListener('auxclick', selectNotification);
    document.addEventListener('click', function (event) {
        if (!panel.hidden && !panel.contains(event.target) && !bells.some(function (bell) { return bell.contains(event.target); })) {
            closePanel(false);
        }
    });
    document.addEventListener('focusin', function (event) {
        if (!panel.hidden && !panel.contains(event.target) && !bells.includes(event.target)) { closePanel(false); }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !panel.hidden) { event.preventDefault(); closePanel(true); }
    });
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            window.clearTimeout(pollTimer);
            closePanel(false);
            dismissToast(true);
        } else { refresh(false); }
    });
    window.addEventListener('resize', positionOverlays);
    window.addEventListener('scroll', positionOverlays, { passive: true });
    toast.querySelector('[data-notification-toast-close]').addEventListener('click', function () { dismissToast(false); });
    toast.addEventListener('mouseenter', function () { window.clearTimeout(toastTimer); });
    toast.addEventListener('focusin', function () { window.clearTimeout(toastTimer); });
    toast.addEventListener('mouseleave', armToastDismissal);
    toast.addEventListener('focusout', armToastDismissal);
    window.addEventListener('pagehide', function () {
        stopped = true;
        lifecycle += 1;
        closePanel(false);
        dismissToast(true);
        window.clearTimeout(pollTimer);
        window.clearTimeout(navigationTimer);
        requests.forEach(function (job) {
            if (!job.keepalive) { job.controller.abort(); }
        });
    });
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            stopped = false;
            navigating = false;
            loading = null;
            authStatus = 0;
            pendingReads.clear();
            // Keep the queue so an outstanding keepalive read settles first.
            refresh(false);
        }
    });

    refresh(false);
})();
