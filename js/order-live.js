(function () {
    'use strict';

    var root = document.querySelector('[data-order-live]');
    if (!root || !window.fetch || !window.AbortController) return;
    var status = document.createElement('p');
    status.className = 'text-small muted';
    status.setAttribute('role', 'status');
    root.prepend(status);
    var busy = false;
    var stopped = false;
    var expired = false;
    var controller = null;
    var lifecycle = 0;
    var url = new URL(window.location.pathname, window.location.href);
    var currentUrl = new URL(window.location.href);
    ['id', 'queue', 'status', 'q', 'page', 'fulfillment'].forEach(function (key) {
        if (currentUrl.searchParams.has(key)) url.searchParams.set(key, currentUrl.searchParams.get(key));
    });
    url.searchParams.set('order_live', '1');

    // This has no timer: the existing notification poll owns the refresh cadence.
    async function refresh() {
        if (busy || stopped || expired || document.hidden) return;
        busy = true;
        var cycle = lifecycle;
        var requestController = new AbortController();
        controller = requestController;
        var timeout = window.setTimeout(function () { requestController.abort(); }, 15000);
        try {
            var response = await fetch(url.href, {
                credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'text/html' }, signal: requestController.signal
            });
            if (response.status === 401 || response.status === 403) expired = true;
            if (!response.ok || response.redirected) throw new Error(response.status === 404
                ? 'This order is no longer available.' : expired
                    ? 'Your session expired. Sign in again to see current order updates.'
                    : 'Live order updates are temporarily unavailable. Showing the last loaded state.');
            var html = await response.text();
            if (stopped || cycle !== lifecycle) return;
            var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-order-live]');
            if (!fresh || !/^[a-f0-9]{64}$/.test(fresh.dataset.version || '')) throw new Error('Invalid order update.');
            if (fresh.dataset.version === root.dataset.version) { status.textContent = ''; return; }
            var replacements = [];
            root.querySelectorAll('[data-order-live-region]').forEach(function (region) {
                var next = Array.from(fresh.querySelectorAll('[data-order-live-region]')).find(function (candidate) {
                    return candidate.dataset.orderLiveRegion === region.dataset.orderLiveRegion;
                });
                if (!next) throw new Error('Incomplete order update.');
                replacements.push([region, next]);
            });
            var before = new CustomEvent('hopia:order-before-update', { cancelable: true });
            if (!document.dispatchEvent(before)) {
                status.textContent = 'An order update is available. The current submission will finish before the display updates.';
                return;
            }
            var focusedId = document.activeElement && document.activeElement.id;
            replacements.forEach(function (pair) {
                var old = pair[0];
                var next = document.importNode(pair[1], true);
                // Keep unsent text, not hidden security tokens, files or approvals.
                old.querySelectorAll('input[id], textarea[id]').forEach(function (field) {
                    if (field.tagName === 'INPUT' && !['text', 'search', 'tel', 'email', 'number'].includes(field.type)) return;
                    if (field.value === field.defaultValue) return;
                    var replacement = Array.from(next.querySelectorAll('input[id], textarea[id]')).find(function (candidate) {
                        return candidate.id === field.id && candidate.type === field.type;
                    });
                    if (replacement) replacement.value = field.value;
                });
                old.replaceWith(next);
            });
            root.dataset.version = fresh.dataset.version;
            document.dispatchEvent(new CustomEvent('hopia:order-updated'));
            if (focusedId) {
                var focused = document.getElementById(focusedId);
                if (focused && root.contains(focused)) focused.focus({ preventScroll: true });
            }
            status.textContent = 'Order information updated from the server.';
        } catch (error) {
            if (!stopped && cycle === lifecycle) status.textContent = error.name === 'AbortError' || error instanceof TypeError
                ? 'Live order updates are temporarily unavailable. Showing the last loaded state.'
                : error.message || 'Live order updates are temporarily unavailable.';
        } finally {
            window.clearTimeout(timeout);
            if (cycle === lifecycle) busy = false;
        }
    }

    window.hopiaOrderLive = { refresh: refresh };
    window.addEventListener('pagehide', function () {
        stopped = true;
        lifecycle += 1;
        if (controller) controller.abort();
    });
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) { stopped = false; expired = false; busy = false; }
    });
})();
