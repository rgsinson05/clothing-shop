// Offline browser driver. Executes the actual production scripts against real DOM
// with in-memory storage, fake fetch and captured navigation. Never calls an API.
(async function () {
    'use strict';
    var output = document.getElementById('browser-test-results');
    var checks = 0;
    var failures = 0;
    var messages = [];
    function check(name, pass) {
        checks++;
        if (!pass) failures++;
        messages.push((pass ? 'ok ' : 'FAIL ') + name);
    }
    function tick() { return new Promise(function (resolve) { setTimeout(resolve, 0); }); }
    try {
        // Let the real shared theme bootstrap/pageshow synchronization finish.
        if (document.readyState !== 'complete') await new Promise(function (resolve) {
            window.addEventListener('load', function () { setTimeout(resolve, 0); }, { once: true });
        });
        var mode = window.deliveryFixtureMode;
        var desiredTheme = mode === 'render-light' ? 'light' : 'dark';
        if (document.documentElement.dataset.adminTheme !== desiredTheme) document.querySelector('[data-admin-theme-toggle]').click();
        check('requested shared theme is active: ' + desiredTheme, document.documentElement.dataset.adminTheme === desiredTheme);
        var root = document.querySelector('[data-admin-delivery]');
        var originalKey = root.querySelector('[name="action_key"]');
        var keyValue = originalKey ? originalKey.value : '';
        var calls = [];
        var navigations = [];
        var memory = {};
        var respond;
        var successShows = 0;
        var windowListeners = {};
        var intervals = [];
        var storage = {
            getItem: function (key) { return memory[key] || null; },
            setItem: function (key, value) {
                if (mode === 'storage-denied') throw new Error('Storage unavailable');
                memory[key] = value;
            },
            removeItem: function (key) { delete memory[key]; }
        };
        var fakeWindow = {
            location: {
                href: 'https://offline.invalid/admin/order-view.php?id=801',
                pathname: '/admin/order-view.php',
                assign: function (url) { navigations.push({ type: 'assign', url: url }); },
                replace: function (url) { navigations.push({ type: 'replace', url: url }); },
                reload: function () { navigations.push({ type: 'reload' }); }
            },
            hopiaAdminSuccess: { show: function () { successShows++; }, dismiss: function () {} },
            fetch: fakeFetch, AbortController: window.AbortController,
            setInterval: function (callback, cadence) {
                intervals.push({ callback: callback, cadence: cadence });
                return intervals.length;
            },
            setTimeout: window.setTimeout.bind(window), clearTimeout: window.clearTimeout.bind(window),
            addEventListener: function (name, listener) { windowListeners[name] = listener; }
        };
        function fakeFetch(url, options) {
            var call = { url: url, options: options, body: new URLSearchParams(options.body) };
            calls.push(call);
            return new Promise(function (resolve, reject) {
                if (mode === 'live') { call.resolve = resolve; call.reject = reject; }
                else respond = { resolve: resolve, reject: reject };
            });
        }
        function result(status, data) {
            respond.resolve({ status: status, ok: status >= 200 && status < 300, json: async function () { return data; } });
        }
        var forms = Array.from(root.querySelectorAll('[data-delivery-form]'));
        if (mode === 'return') {
            memory[root.dataset.storageKey] = JSON.stringify({ savedAt: Date.now(), drafts: {
                '0:inspect_return:901': { reason: 'Earlier-cycle draft', outcome: 'RETRY_APPROVED' }
            } });
        }
        new Function('window', 'fetch', 'sessionStorage', document.getElementById('delivery-production-source').textContent)(fakeWindow, fakeFetch, storage);
        check('production script initializes without exceptions', true);
        check('no initial API request', calls.length === 0);
        await tick();
        check('page has no horizontal overflow at width ' + window.innerWidth, document.documentElement.scrollWidth <= window.innerWidth + 1);
        check('shared card has actual theme surface', getComputedStyle(root).backgroundColor !== 'rgba(0, 0, 0, 0)');
        if (mode === 'legacy' || mode === 'blocked') {
            check('unready schema has no action forms', forms.length === 0);
        } else {
            check('only eligible form buttons enabled', forms.every(function (form) { return !form.querySelector('[data-delivery-submit]').disabled; }));
        }
        if (mode === 'live') {
            var liveRoot = document.querySelector('.admin-order-detail[data-order-live]');
            var initialRegions = Array.from(liveRoot.querySelectorAll('[data-order-live-region]'));
            var failureForm = forms.find(function (entry) { return entry.elements.action.value === 'fail_attempt'; });
            var failureReason = failureForm.elements.reason;
            var initialModal = root.querySelector('[data-delivery-modal]');
            var draftKey = root.dataset.storageKey;
            var draftId = failureForm.dataset.draftId;
            var unsentNotes = 'Unsubmitted courier failure notes awaiting confirmation';
            var freshHtml = document.getElementById('order-live-fresh-source').textContent;
            var freshRoot = new DOMParser().parseFromString(freshHtml, 'text/html').querySelector('[data-order-live]');
            var freshTimeline = freshRoot.querySelector('.admin-order-progress');
            var freshReceipt = freshRoot.querySelector('[data-customer-receipt-event]');
            var beforeUpdates = 0;
            var updates = 0;
            function liveRegion(name) { return liveRoot.querySelector('[data-order-live-region="' + name + '"]'); }
            function liveRequests(order) {
                return calls.filter(function (call) {
                    var isOrder = new URL(call.url, fakeWindow.location.href).searchParams.get('order_live') === '1';
                    return isOrder === order;
                });
            }
            function sameRegions(regions) {
                var current = Array.from(liveRoot.querySelectorAll('[data-order-live-region]'));
                return current.length === regions.length && current.every(function (region, index) { return region === regions[index]; });
            }
            async function settleLivePoll(status, html) {
                calls.filter(function (call) { return !call.settled; }).forEach(function (call) {
                    call.settled = true;
                    if (new URL(call.url, fakeWindow.location.href).searchParams.get('order_live') === '1') {
                        if (status === 0) call.reject(new Error('Simulated order network failure; the response could not be confirmed.'));
                        else call.resolve({ status: status, ok: status >= 200 && status < 300, redirected: false,
                            text: async function () { return html; } });
                    } else call.reject(new Error('Simulated notification fetch failure'));
                });
                await tick();
            }
            async function pollLive(name, status, html) {
                var orderCount = liveRequests(true).length;
                var notificationCount = liveRequests(false).length;
                notificationPoll.callback();
                check(name + ' uses the production notification tick even after notification failure',
                    liveRequests(true).length === orderCount + 1 && liveRequests(false).length === notificationCount + 1);
                await settleLivePoll(status, html);
            }
            check('live detail starts with authoritative In Transit state and named regions', liveRoot.dataset.version === 'a'.repeat(64)
                && initialRegions.map(function (region) { return region.dataset.orderLiveRegion; }).join('|') === 'header|status|courier'
                && failureForm.elements.stage.value === 'OUT_FOR_DELIVERY'
                && liveRegion('status').querySelector('.badge').textContent.trim() === 'In Transit'
                && liveRoot.querySelector('.admin-order-progress [aria-current="step"] .admin-order-progress__label').textContent.trim() === 'In Transit');
            check('fresh payload supplies real Delivered timeline and customer received_at history', freshRoot.dataset.version === 'b'.repeat(64)
                && freshTimeline.querySelector('[aria-current="step"] .admin-order-progress__label').textContent.trim() === 'Delivered'
                && freshReceipt && /\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(freshReceipt.textContent));

            // A synthetic field-id change prevents the generic updater from copying
            // this textarea: the actual courier controller must restore its draft.
            var draftFresh = liveRoot.cloneNode(true);
            draftFresh.dataset.version = 'c'.repeat(64);
            var serverReason = draftFresh.querySelector('[name="reason"]');
            serverReason.id += '-fresh';
            draftFresh.querySelector('label[for="' + failureReason.id + '"]').htmlFor = serverReason.id;
            draftFresh.querySelector('[name="action_key"]').setAttribute('value', 'd'.repeat(32));
            failureReason.value = unsentNotes;
            failureReason.dispatchEvent(new Event('input', { bubbles: true }));
            failureForm.querySelector('[data-delivery-submit]').click();
            check('unsent failure notes open confirmation without submitting an action', !initialModal.hidden
                && document.activeElement === initialModal.querySelector('[data-delivery-acknowledge]')
                && document.body.classList.contains('is-modal-open') && calls.length === 0);

            document.addEventListener('hopia:order-before-update', function () { beforeUpdates++; });
            document.addEventListener('hopia:order-updated', function () { updates++; });
            new Function('window', 'fetch', 'sessionStorage', document.getElementById('order-live-production-source').textContent)(fakeWindow, fakeFetch, storage);
            var liveStatus = liveRoot.firstElementChild;
            check('production order updater retains the root and owns a live status paragraph, not a timer',
                typeof fakeWindow.hopiaOrderLive.refresh === 'function' && liveStatus.matches('p[role="status"]')
                && intervals.length === 0 && calls.length === 0);
            new Function('window', 'fetch', 'sessionStorage', document.getElementById('admin-notifications-production-source').textContent)(fakeWindow, fakeFetch, storage);
            check('production admin notification initialization captures one 30000 ms interval without a startup request',
                intervals.length === 1 && intervals[0].cadence === 30000 && calls.length === 0);
            var notificationPoll = intervals[0];
            notificationPoll.callback();
            check('first captured production notification tick invokes the order fragment refresh', liveRequests(true).length === 1 && liveRequests(false).length === 1);
            var notificationCall = liveRequests(false)[0];
            notificationCall.settled = true;
            notificationCall.reject(new Error('Simulated notification fetch failure'));
            await tick();
            var notificationRoot = document.querySelector('[data-admin-notifications]');
            check('notification fetch failure is honest and cannot cancel the pending order refresh',
                notificationRoot.querySelector('.admin-notifications__badge').textContent === '!'
                && notificationRoot.querySelector('.admin-notifications__status').textContent === 'Simulated notification fetch failure'
                && !liveRequests(true)[0].settled && sameRegions(initialRegions) && liveRoot.dataset.version === 'a'.repeat(64));
            await settleLivePoll(200, draftFresh.outerHTML);
            var refreshedCourier = liveRegion('courier');
            var restoredForm = refreshedCourier.querySelector('[data-delivery-form]');
            var restoredModal = refreshedCourier.querySelector('[data-delivery-modal]');
            var savedDraft = JSON.parse(memory[draftKey] || 'null');
            check('changed-version response replaces regions without replacing the live root or status paragraph',
                document.querySelector('[data-order-live]') === liveRoot && liveStatus.parentNode === liveRoot
                && liveRoot.dataset.version === 'c'.repeat(64) && !sameRegions(initialRegions) && beforeUpdates === 1 && updates === 1);
            check('courier reinitialization restores unsent notes through existing draft storage, not field-id copying',
                refreshedCourier !== root && refreshedCourier.deliveryController && restoredForm.elements.reason.id !== failureReason.id
                && restoredForm.elements.reason.value === unsentNotes && savedDraft && savedDraft.drafts[draftId]
                && savedDraft.drafts[draftId].reason === unsentNotes && restoredForm.elements.action_key.value === 'd'.repeat(32));
            check('replacement safely closes the open confirmation and releases its focus trap',
                initialModal.hidden && !initialModal.isConnected && restoredModal.hidden && !document.body.classList.contains('is-modal-open')
                && !initialModal.contains(document.activeElement));

            var transitRegions = Array.from(liveRoot.querySelectorAll('[data-order-live-region]'));
            await pollLive('order network failure', 0, '');
            check('order network failure retains In Transit, pending notes and the failure form without guessing Delivered',
                sameRegions(transitRegions) && liveRoot.dataset.version === 'c'.repeat(64)
                && liveRegion('status').querySelector('.badge').textContent.trim() === 'In Transit'
                && restoredForm.elements.reason.value === unsentNotes && !liveRoot.querySelector('[data-customer-receipt-event]')
                && /network failure.*could not be confirmed/.test(liveStatus.textContent) && successShows === 0 && navigations.length === 0);
            restoredForm.querySelector('[data-delivery-submit]').click();
            check('reinitialized courier confirmation still works without an action POST', !restoredModal.hidden
                && document.activeElement === restoredModal.querySelector('[data-delivery-acknowledge]'));
            await pollLive('authoritative Delivered response', 200, freshHtml);
            var deliveredCourier = liveRegion('courier');
            var deliveredTimeline = liveRoot.querySelector('.admin-order-progress');
            var deliveredReceipt = deliveredCourier.querySelector('[data-customer-receipt-event]');
            check('server-supplied Delivered updates detail badges and the real primary timeline', liveRoot.dataset.version === 'b'.repeat(64)
                && liveRegion('header').querySelector('.badge').textContent.trim() === 'Delivered'
                && liveRegion('status').querySelector('.badge').textContent.trim() === 'Delivered'
                && deliveredCourier.querySelector('.badge').textContent.includes('Delivered')
                && deliveredTimeline.outerHTML === freshTimeline.outerHTML
                && deliveredTimeline.querySelector('[aria-current="step"] .admin-order-progress__label').textContent.trim() === 'Delivered');
            check('courier history renders the authoritative customer received_at exactly once',
                deliveredCourier.querySelectorAll('[data-customer-receipt-event]').length === 1 && deliveredReceipt
                && deliveredReceipt.outerHTML === freshReceipt.outerHTML && deliveredReceipt.closest('details')
                && Array.from(deliveredCourier.querySelectorAll('.admin-delivery__history')).map(function (history) { return history.outerHTML; }).join('')
                    === Array.from(freshRoot.querySelectorAll('.admin-delivery__history')).map(function (history) { return history.outerHTML; }).join(''));
            check('Delivered removes manual delivery actions and the failure form without navigation or client success',
                deliveredCourier.querySelector('[data-delivery-form]') === null && deliveredCourier.querySelector('[data-delivery-submit]') === null
                && liveRoot.querySelector('[name="action"][value="delivered"], [name="action"][value="fail_attempt"]') === null
                && navigations.length === 0 && successShows === 0);
            check('Delivered closes an open reinitialized confirmation and retains unsent failure notes in draft storage',
                restoredModal.hidden && !restoredModal.isConnected && deliveredCourier.querySelector('[data-delivery-modal]').hidden
                && !document.body.classList.contains('is-modal-open') && !restoredModal.contains(document.activeElement)
                && JSON.parse(memory[draftKey]).drafts[draftId].reason === unsentNotes);
            var callsBeforeStaleConfirmation = calls.length;
            restoredModal.querySelector('[data-delivery-acknowledge]').checked = true;
            restoredModal.querySelector('[data-delivery-confirm-form]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            check('a detached stale confirmation cannot submit after server replacement', calls.length === callsBeforeStaleConfirmation);

            var deliveredRegions = Array.from(liveRoot.querySelectorAll('[data-order-live-region]'));
            var deliveredUpdates = updates;
            var deliveredBeforeUpdates = beforeUpdates;
            await pollLive('same-version response', 200, freshHtml);
            check('same-version response does not replace regions or rerun courier lifecycle events', sameRegions(deliveredRegions)
                && updates === deliveredUpdates && beforeUpdates === deliveredBeforeUpdates && liveStatus.parentNode === liveRoot);
            await pollLive('order 404 response', 404, '');
            check('404 retains last server-confirmed Delivered status, timeline and receipt with an honest error', sameRegions(deliveredRegions)
                && liveRoot.dataset.version === 'b'.repeat(64) && liveRegion('status').querySelector('.badge').textContent.trim() === 'Delivered'
                && liveRoot.querySelector('.admin-order-progress') === deliveredTimeline
                && liveRoot.querySelector('[data-customer-receipt-event]') === deliveredReceipt
                && liveStatus.textContent.includes('no longer available') && navigations.length === 0 && successShows === 0);

            // Seed a list-style fixture on the SAME root; never restart the updater
            // or remove its live status paragraph to hide stale-root regressions.
            document.dispatchEvent(new CustomEvent('hopia:order-before-update', { cancelable: true }));
            liveRoot.replaceChildren(liveStatus);
            liveRoot.dataset.version = 'd'.repeat(64);
            liveRoot.insertAdjacentHTML('beforeend', '<section data-order-live-region="filters"><p data-live-fixture-count>Delivered: 0</p></section>'
                + '<section data-order-live-region="orders"><table><tbody><tr data-live-fixture-order="801"><td>ORDER #801</td><td data-live-fixture-state>In Transit</td></tr></tbody></table></section>');
            var listFilters = liveRegion('filters');
            var listOrders = liveRegion('orders');
            var listRow = listOrders.querySelector('[data-live-fixture-order="801"]');
            var listFreshHtml = '<div data-order-live data-version="' + 'e'.repeat(64) + '">'
                + '<section data-order-live-region="filters"><p data-live-fixture-count>Delivered: 1</p></section>'
                + '<section data-order-live-region="orders"><table><tbody><tr data-live-fixture-order="801"><td>ORDER #801</td><td data-live-fixture-state>Delivered</td></tr></tbody></table></section></div>';
            await pollLive('list-style Delivered response', 200, listFreshHtml);
            check('generic updater replaces the supplied list row and filters count on the retained root',
                document.querySelector('[data-order-live]') === liveRoot && liveStatus.parentNode === liveRoot
                && liveRoot.dataset.version === 'e'.repeat(64) && liveRegion('filters') !== listFilters && liveRegion('orders') !== listOrders
                && !listRow.isConnected && liveRoot.querySelector('[data-live-fixture-order="801"] [data-live-fixture-state]').textContent === 'Delivered'
                && liveRoot.querySelector('[data-live-fixture-count]').textContent === 'Delivered: 1'
                && navigations.length === 0 && successShows === 0);
            var confirmedListRegions = Array.from(liveRoot.querySelectorAll('[data-order-live-region]'));
            await pollLive('order session expiry response', 401, '');
            check('401 shows session expiry without changing the last confirmed list state or navigating', sameRegions(confirmedListRegions)
                && liveRoot.dataset.version === 'e'.repeat(64) && liveStatus.textContent.includes('session expired') && navigations.length === 0);
            var fetchesBeforeExpiryTick = liveRequests(true).length;
            var notificationsBeforeExpiryTick = liveRequests(false).length;
            notificationPoll.callback();
            await settleLivePoll(401, '');
            check('session expiry stops further order fetches even while notification ticks continue',
                liveRequests(true).length === fetchesBeforeExpiryTick && liveRequests(false).length === notificationsBeforeExpiryTick + 1
                && sameRegions(confirmedListRegions) && liveStatus.textContent.includes('session expired'));
            check('all production network calls stay intercepted, read-only and same-origin', calls.length > 0 && calls.every(function (call) {
                var target = new URL(call.url, fakeWindow.location.href);
                var order = target.searchParams.get('order_live') === '1';
                return target.origin === 'https://offline.invalid' && (!call.options.method || call.options.method === 'GET')
                    && !call.options.body && call.options.credentials === 'same-origin' && call.options.cache === 'no-store'
                    && call.options.signal instanceof window.AbortSignal
                    && (order ? target.pathname === '/admin/order-view.php' && target.searchParams.get('id') === '801'
                        && call.options.headers.Accept === 'text/html' : target.pathname === '/admin/api/notifications.php');
            }));
        } else if (mode.startsWith('render') || mode === 'legacy' || mode === 'blocked') {
            check('modal stays hidden until administrator acts', root.querySelector('[data-delivery-modal]').hidden);
        } else {
            var form = mode === 'return' ? forms[0] : forms.find(function (entry) { return entry.elements.action.value === 'fail_attempt'; });
            var button = form.querySelector('[data-delivery-submit]');
            var reason = form.elements.reason;
            var modal = root.querySelector('[data-delivery-modal]');
            var confirmation = modal.querySelector('[data-delivery-confirm-form]');
            var confirm = modal.querySelector('[data-delivery-confirm]');
            var ack = modal.querySelector('[data-delivery-acknowledge]');
            function submitForm() { form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); }
            function submitConfirm() { confirmation.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); }
            if (mode === 'return') {
                check('prior-cycle draft cannot fill a new stock inspection', reason.value === '' && form.elements.outcome.value === '');
                form.elements.outcome.value = 'PASSED';
                forms[1].elements.reason.value = 'Unsubmitted second-item evidence';
                forms[1].elements.outcome.value = 'MANUAL_REVIEW';
            }
            reason.value = '   ';
            submitForm();
            check('blank evidence cannot open confirmation', modal.hidden && calls.length === 0);
            reason.value = '\u00e9'.repeat(128);
            reason.dispatchEvent(new Event('input', { bubbles: true }));
            submitForm();
            check('UTF-8 byte limit matches backend', modal.hidden && calls.length === 0);
            reason.value = mode === 'return' ? 'Inspected fabric, seams and item identity' : 'Customer unavailable at confirmed address';
            reason.dispatchEvent(new Event('input', { bubbles: true }));
            button.focus();
            button.click();
            check('confirmation opens and owns focus', !modal.hidden && document.activeElement === ack && document.body.classList.contains('is-modal-open'));
            var rect = modal.querySelector('[role="dialog"]').getBoundingClientRect();
            check('modal is viewport bounded', rect.left >= 0 && rect.right <= window.innerWidth + 1 && rect.top >= 0 && rect.bottom <= window.innerHeight + 1);
            submitConfirm();
            check('explicit acknowledgment required', calls.length === 0);
            ack.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true }));
            check('reverse tab stays in modal', document.activeElement === confirm);
            confirm.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
            check('forward tab stays in modal', document.activeElement === ack);
            ack.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
            check('Escape closes and restores trigger focus', modal.hidden && document.activeElement === button && !document.body.classList.contains('is-modal-open'));
            button.click();
            ack.checked = true;
            submitConfirm();
            submitConfirm();
            forms[forms.length - 1].dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            check('duplicate and conflicting submits make one request', calls.length === 1);
            check('all delivery submit buttons disabled while pending', forms.every(function (entry) { return entry.querySelector('[data-delivery-submit]').disabled; }));
            check('request uses actual contract tokens and stable key', calls[0].body.get('cycle') === '1'
                && calls[0].body.get('revision') === '8' && calls[0].body.get('action_key') === form.elements.action_key.value
                && calls[0].body.get('csrf_token') === 'synthetic-csrf-"<&' && !calls[0].body.has('validateOnly')
                && !calls[0].body.has('confirm_cancellation'));
            check('request is POST and same-origin credentials', calls[0].options.method === 'POST' && calls[0].options.credentials === 'same-origin');
            check('named action input does not shadow the real API endpoint', calls[0].url === (mode === 'return' ? 'api/return-stock-action.php' : 'api/delivery-action.php'));
            if (mode === 'success') {
                result(422, { ok: false, reason: 'reason_required', message: '<b>Recorded validation error</b>' });
                await tick();
                check('validation never fabricates success or navigates', successShows === 0 && navigations.length === 0);
                check('validation error is text, not HTML', root.querySelector('[data-delivery-feedback]').textContent === '<b>Recorded validation error</b>' && root.querySelector('[data-delivery-feedback] b') === null);
                check('validation keeps notes and permits deliberate correction', reason.value === 'Customer unavailable at confirmed address' && !button.disabled);
                button.click();
                ack.checked = true;
                submitConfirm();
                check('corrected resubmission preserves action key', calls.length === 2 && calls[0].body.get('action_key') === calls[1].body.get('action_key') && form.elements.action_key.value === keyValue);
                result(200, { ok: true, idempotent: true, redirect: 'order-view.php?id=801&admin_success=synthetic' });
                await tick();
                check('idempotent success reloads authoritative page and server flash', navigations.length === 1 && navigations[0].type === 'assign' && navigations[0].url.includes('admin_success=synthetic') && navigations[0].url.endsWith('#courier-workflow'));
                check('successful form evidence is not restored as pending', !JSON.parse(memory[root.dataset.storageKey]).drafts[form.dataset.draftId]);
            } else if (mode === 'return') {
                result(200, { ok: true, idempotent: false, redirect: 'order-view.php?id=801&admin_success=synthetic' });
                await tick();
                var drafts = JSON.parse(memory[root.dataset.storageKey]).drafts;
                check('one stock inspection only is submitted', calls.length === 1 && calls[0].body.get('action') === 'inspect_stock' && calls[0].body.get('order_item_id') === '901' && calls[0].body.get('outcome') === 'PASSED');
                check('other unsent item notes survive reload', !drafts[form.dataset.draftId] && drafts[forms[1].dataset.draftId].reason === 'Unsubmitted second-item evidence');
            } else {
                if (mode === 'conflict') result(409, { ok: false, reason: 'conflict', message: 'The delivery state changed.' });
                else respond.reject(new Error('Simulated lost response'));
                await tick();
                check('conflict/uncertain response never reports success or repeats POST', successShows === 0 && calls.length === 1);
                check('old page cannot submit after conflict/unknown result', forms.every(function (entry) { return entry.querySelector('[data-delivery-submit]').disabled; }));
                if (mode === 'storage-denied') {
                    check('storage failure preserves notes and explicit recovery', navigations.length === 0 && !root.querySelector('[data-delivery-recovery]').hidden && reason.value === 'Customer unavailable at confirmed address');
                } else {
                    check('conflict reload is GET navigation, not resubmission', navigations.length === 1 && navigations[0].type === 'replace');
                    var reviewUrl = new URL(navigations[0].url, fakeWindow.location.href);
                    check('error recovery changes query to force a document GET, not a hash-only navigation', reviewUrl.searchParams.has('delivery_review')
                        && reviewUrl.searchParams.get('id') === '801' && reviewUrl.hash === '#courier-workflow');
                    check('recovery retains unsent notes and honest result message', JSON.parse(memory[root.dataset.storageKey]).drafts[form.dataset.draftId].reason === 'Customer unavailable at confirmed address'
                        && memory[root.dataset.storageKey + '.notice'].includes(mode === 'conflict' ? 'state changed' : 'may have reached the server'));
                }
            }
        }
        if (mode !== 'live') {
            windowListeners.pageshow({ persisted: true });
            var restoredUrl = new URL(navigations[navigations.length - 1].url, fakeWindow.location.href);
            check('back-forward cache recovery forces a fresh order GET', navigations[navigations.length - 1].type === 'replace'
                && restoredUrl.searchParams.has('delivery_review') && restoredUrl.searchParams.get('id') === '801');
        }
    } catch (error) {
        check('uncaught browser error: ' + error.stack, false);
    }
    output.textContent = messages.join('\n') + '\nBROWSER_RESULT: ' + checks + ' checks, ' + failures + ' failures';
    output.dataset.checks = String(checks);
    output.dataset.failures = String(failures);
    output.dataset.width = String(window.innerWidth);
    if (window.parent !== window) window.parent.postMessage({ deliveryBrowserResult: true,
        checks: checks, failures: failures, width: window.innerWidth, text: output.textContent }, '*');
})();
