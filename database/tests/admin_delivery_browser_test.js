// Offline browser driver. Executes the actual delivery script against real DOM
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
                assign: function (url) { navigations.push({ type: 'assign', url: url }); },
                replace: function (url) { navigations.push({ type: 'replace', url: url }); }
            },
            hopiaAdminSuccess: { show: function () { successShows++; }, dismiss: function () {} },
            setTimeout: window.setTimeout.bind(window), clearTimeout: window.clearTimeout.bind(window),
            addEventListener: function (name, listener) { windowListeners[name] = listener; }
        };
        function fakeFetch(url, options) {
            calls.push({ url: url, options: options, body: new URLSearchParams(options.body) });
            return new Promise(function (resolve, reject) { respond = { resolve: resolve, reject: reject }; });
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
        if (mode.startsWith('render') || mode === 'legacy' || mode === 'blocked') {
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
        windowListeners.pageshow({ persisted: true });
        var restoredUrl = new URL(navigations[navigations.length - 1].url, fakeWindow.location.href);
        check('back-forward cache recovery forces a fresh order GET', navigations[navigations.length - 1].type === 'replace'
            && restoredUrl.searchParams.has('delivery_review') && restoredUrl.searchParams.get('id') === '801');
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
