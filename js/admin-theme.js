(function () {
    'use strict';

    var root = document.documentElement;
    if (!root.hasAttribute('data-admin-theme')) return;

    var key = 'hopiafits.admin.theme';
    var theme = 'dark';
    var storageAvailable = true;

    function readPreference() {
        if (!storageAvailable) return theme;
        try {
            var saved = window.localStorage.getItem(key);
            if (saved === 'light' || saved === 'dark') return saved;
        } catch (error) {
            // Storage can be blocked; switching still works for this document.
            storageAvailable = false;
            return theme;
        }
        return 'dark';
    }

    function apply(next) {
        theme = next;
        root.setAttribute('data-admin-theme', theme);
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', theme === 'dark' ? '#0b1120' : '#f5f4f1');
        document.querySelectorAll('[data-admin-theme-toggle]').forEach(function (button) {
            button.setAttribute('aria-pressed', String(theme === 'dark'));
            button.title = theme === 'dark'
                ? 'Dark mode active. Switch to light mode'
                : 'Light mode active. Switch to dark mode';
            button.querySelector('[data-admin-theme-label]').textContent = theme === 'dark' ? 'Dark mode' : 'Light mode';
        });
    }

    // This script is deliberately loaded before styles and body content.
    apply(readPreference());

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-admin-theme-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                apply(theme === 'dark' ? 'light' : 'dark');
                try { window.localStorage.setItem(key, theme); } catch (error) { storageAvailable = false; }
            });
            button.hidden = false;
        });
        apply(theme);
    });

    window.addEventListener('storage', function (event) {
        if (event.key === key || event.key === null) apply(readPreference());
    });
    window.addEventListener('pageshow', function () { apply(readPreference()); });
})();
