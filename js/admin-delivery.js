(function () {
    'use strict';

    var root = document.querySelector('[data-admin-delivery]');
    if (!root) return;
    var forms = Array.from(root.querySelectorAll('[data-delivery-form]'));
    var modal = root.querySelector('[data-delivery-modal]');
    var panel = modal.querySelector('[role="dialog"]');
    var confirmForm = modal.querySelector('[data-delivery-confirm-form]');
    var confirmButton = modal.querySelector('[data-delivery-confirm]');
    var acknowledge = modal.querySelector('[data-delivery-acknowledge]');
    var feedback = root.querySelector('[data-delivery-feedback]');
    var recovery = root.querySelector('[data-delivery-recovery]');
    var storageKey = root.dataset.storageKey;
    var activeForm = null;
    var trigger = null;
    var inFlight = false;
    var needsReview = false;
    var succeededForm = null;

    function notice(message, isError) {
        feedback.className = 'alert ' + (isError ? 'alert-error' : 'alert-info');
        feedback.textContent = message;
        feedback.hidden = false;
        feedback.focus();
    }

    function saveDrafts() {
        var drafts = {};
        forms.forEach(function (form) {
            if (form === succeededForm) return;
            var values = {};
            ['reason', 'outcome'].forEach(function (name) {
                var field = form.elements.namedItem(name);
                if (field && field.value) values[name] = field.value;
            });
            if (Object.keys(values).length) drafts[form.dataset.draftId] = values;
        });
        try {
            sessionStorage.setItem(storageKey, JSON.stringify({ savedAt: Date.now(), drafts: drafts }));
            return true;
        } catch (error) {
            return false;
        }
    }

    // Drafts contain only unsent notes/decisions, never CSRF, action keys or approval.
    // Keys include the cycle and item, so an old cycle cannot approve a new one.
    try {
        var stored = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
        if (stored && stored.savedAt > Date.now() - 30 * 60 * 1000 && stored.drafts) {
            var restored = false;
            forms.forEach(function (form) {
                var values = stored.drafts[form.dataset.draftId];
                if (!values) return;
                ['reason', 'outcome'].forEach(function (name) {
                    var field = form.elements.namedItem(name);
                    if (field && typeof values[name] === 'string') {
                        field.value = values[name];
                        restored = true;
                    }
                });
            });
            if (restored) notice('Unsubmitted notes were restored for this cycle. Review them before confirming; they are not recorded inspection evidence.', false);
        }
        var previousNotice = sessionStorage.getItem(storageKey + '.notice');
        sessionStorage.removeItem(storageKey + '.notice');
        if (previousNotice) notice(previousNotice, true);
    } catch (error) {
        // Storage is optional. Keep visible inputs on any error instead of losing them.
    }

    function setBusy(busy) {
        inFlight = busy;
        root.setAttribute('aria-busy', busy ? 'true' : 'false');
        root.querySelectorAll('[data-delivery-submit]').forEach(function (button) {
            button.disabled = busy || needsReview;
        });
        confirmButton.disabled = busy;
        confirmButton.textContent = busy ? 'Saving...' : 'Confirm Action';
        modal.querySelectorAll('button[data-delivery-cancel]').forEach(function (button) {
            button.disabled = busy;
        });
    }

    function closeModal() {
        if (inFlight) return;
        modal.hidden = true;
        document.body.classList.remove('is-modal-open');
        activeForm = null;
        if (trigger && trigger.isConnected) trigger.focus();
    }

    function freshOrderGet() {
        var destination = new URL(root.dataset.reloadUrl, window.location.href);
        // A fragment-only navigation does not reload persisted state or notices.
        destination.searchParams.set('delivery_review', String(Date.now()));
        window.location.replace(destination.href);
    }

    function reloadWithError(message) {
        needsReview = true;
        setBusy(false);
        closeModal();
        saveDrafts();
        // A fresh GET only. No rejected or uncertain action is automatically replayed.
        try {
            sessionStorage.setItem(storageKey + '.notice', message + ' The latest order state is shown; review it before taking another action.');
            freshOrderGet();
        } catch (error) {
            notice(message + ' Reload and review the latest state before trying again.', true);
            recovery.hidden = false;
        }
    }

    forms.forEach(function (form) {
        var reason = form.elements.namedItem('reason');
        if (reason) {
            reason.addEventListener('input', function () { reason.setCustomValidity(''); });
        }
        form.addEventListener('input', saveDrafts);
        form.addEventListener('change', saveDrafts);
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (inFlight || needsReview || activeForm) return;
            if (reason) {
                var trimmed = reason.value.trim();
                reason.setCustomValidity(!trimmed ? 'Enter the actual reason or inspection evidence.'
                    : new TextEncoder().encode(trimmed).length > 255 ? 'Use no more than 255 UTF-8 bytes.' : '');
            }
            if (!form.reportValidity()) return;
            if (window.hopiaAdminSuccess) window.hopiaAdminSuccess.dismiss();
            activeForm = form;
            trigger = event.submitter || form.querySelector('[data-delivery-submit]');
            modal.querySelector('#delivery-confirm-title').textContent = form.dataset.label;
            modal.querySelector('#delivery-confirm-copy').textContent = form.dataset.confirmation;
            modal.querySelector('[data-delivery-confirm-item]').textContent = form.dataset.itemLabel;
            acknowledge.checked = false;
            modal.hidden = false;
            document.body.classList.add('is-modal-open');
            acknowledge.focus();
        });
    });

    modal.querySelectorAll('[data-delivery-cancel]').forEach(function (button) {
        button.addEventListener('click', closeModal);
    });
    modal.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
        }
        if (event.key !== 'Tab') return;
        var focusable = Array.from(panel.querySelectorAll('input:not(:disabled), button:not(:disabled), a[href], [tabindex="0"]'));
        var first = focusable[0] || panel;
        var last = focusable[focusable.length - 1] || panel;
        if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
    document.addEventListener('focusin', function (event) {
        if (!modal.hidden && !panel.contains(event.target)) panel.focus();
    });

    confirmForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (inFlight || needsReview || !activeForm || !confirmForm.reportValidity()) return;
        var form = activeForm;
        // Capture exact tokens/key/payload once, before disabling controls. The key
        // comes from the server and is never regenerated on a click or retry.
        var body = new URLSearchParams(new FormData(form));
        saveDrafts();
        setBusy(true);
        var controller = new AbortController();
        var timeout = window.setTimeout(function () { controller.abort(); }, 20000);
        try {
            // name="action" is an API field and shadows HTMLFormElement.action.
            var response = await fetch(form.getAttribute('action'), {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { 'Accept': 'application/json' }, body: body, signal: controller.signal
            });
            var result = await response.json();
            if (!result || typeof result.ok !== 'boolean') throw new Error('Invalid response');
            if (response.ok && result.ok === true) {
                succeededForm = form;
                saveDrafts();
                var expected = new URL(root.dataset.reloadUrl, window.location.href);
                var destination = new URL(result.redirect || root.dataset.reloadUrl, window.location.href);
                if (destination.origin !== expected.origin || destination.pathname !== expected.pathname
                    || destination.searchParams.get('id') !== expected.searchParams.get('id')) destination = expected;
                destination.hash = 'courier-workflow';
                // Server-bound success feedback is consumed once after this fresh GET.
                window.location.assign(destination.href);
                return;
            }
            var message = typeof result.message === 'string' ? result.message : ({
                auth: 'Your admin session expired. Reload and sign in before trying again.',
                csrf: 'The security token expired. Reload the order before trying again.',
                admin_required: 'An authorized administrator is required.',
                method: 'The delivery endpoint requires a POST request.'
            }[result.reason] || 'The delivery action was not accepted. Review the latest order state.');
            if (response.status === 422) {
                setBusy(false);
                closeModal();
                notice(message, true);
                recovery.hidden = false;
            } else {
                reloadWithError(message);
            }
        } catch (error) {
            // A timeout or lost response is not proof of failure or success.
            reloadWithError('The result could not be confirmed. Check the current shipment and history; the request may have reached the server.');
        } finally {
            window.clearTimeout(timeout);
        }
    });

    window.addEventListener('pagehide', saveDrafts);
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) freshOrderGet();
    });
    setBusy(false);
})();
