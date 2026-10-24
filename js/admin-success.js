(function () {
    'use strict';

    var popup = document.getElementById('admin-success');
    if (!popup) { return; }
    var message = popup.querySelector('[data-admin-success-message]');
    var close = popup.querySelector('[data-admin-success-close]');
    var timer;

    function dismiss() {
        window.clearTimeout(timer);
        popup.hidden = true;
        message.textContent = '';
    }

    function scheduleDismiss() {
        window.clearTimeout(timer);
        if (popup.hidden || popup.matches(':hover') || popup.contains(document.activeElement)) { return; }
        // Longer confirmations get extra reading time; hover/focus pauses dismissal.
        timer = window.setTimeout(dismiss, Math.max(8000, message.textContent.length * 65));
    }

    // Used only by server-confirmed AJAX success branches, never submit/click alone.
    window.hopiaAdminSuccess = {
        show: function (text) {
            dismiss();
            if (typeof text !== 'string' || text.trim() === '') { return; }
            popup.hidden = false;
            message.textContent = text;
            scheduleDismiss();
        },
        dismiss: dismiss
    };

    close.addEventListener('click', dismiss);
    popup.addEventListener('mouseenter', function () { window.clearTimeout(timer); });
    popup.addEventListener('mouseleave', scheduleDismiss);
    popup.addEventListener('focusin', function () { window.clearTimeout(timer); });
    popup.addEventListener('focusout', function () { window.setTimeout(scheduleDismiss, 0); });
    // A new action and browser history restoration must not retain old success.
    document.addEventListener('submit', dismiss, true);
    window.addEventListener('pagehide', dismiss);
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) { dismiss(); }
    });

    try {
        var url = new URL(window.location.href);
        if (url.searchParams.has('admin_success')) {
            url.searchParams.delete('admin_success');
            window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
        }
    } catch (error) { /* Session consumption still prevents replay without History API. */ }

    scheduleDismiss();
})();
