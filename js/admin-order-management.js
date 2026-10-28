(function () {
    'use strict';

    var expanded = new Map();
    var scrollPositions = new Map();

    function setExpanded(button, open) {
        var panel = document.getElementById(button.getAttribute('aria-controls'));
        if (!panel) return;
        button.setAttribute('aria-expanded', String(open));
        button.setAttribute('aria-label', (open ? 'Collapse' : 'Expand') + ' details for order #' + button.dataset.orderId);
        button.querySelector('[data-order-toggle-label]').textContent = open ? 'Hide Details' : 'Show Details';
        panel.hidden = !open;
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-order-expand]');
        if (!button) return;
        var section = button.closest('[data-order-card-section]');
        if (!section) return;
        var open = button.getAttribute('aria-expanded') !== 'true';
        section.querySelectorAll('[data-order-expand][aria-expanded="true"]').forEach(function (other) {
            if (other !== button) setExpanded(other, false);
        });
        setExpanded(button, open);
        if (open) expanded.set(section.dataset.orderCardSection, button.dataset.orderId);
        else expanded.delete(section.dataset.orderCardSection);
    });

    document.addEventListener('change', function (event) {
        if (!event.target.matches('[data-order-filter-select]')) return;
        var form = event.target.form;
        var direct = form.querySelector('[data-order-direct-filter]');
        if (direct) direct.disabled = event.target.value !== '';
        if (form.requestSubmit) form.requestSubmit();
        else form.submit();
    });

    // Live region replacement must not close a card or jump its desktop scrollbar.
    document.addEventListener('hopia:order-before-update', function () {
        document.querySelectorAll('[data-order-table-scroll]').forEach(function (table, index) {
            scrollPositions.set(index, [table.scrollTop, table.scrollLeft]);
        });
    });
    document.addEventListener('hopia:order-updated', function () {
        document.querySelectorAll('[data-order-card-section]').forEach(function (section) {
            var id = expanded.get(section.dataset.orderCardSection);
            var button = Array.from(section.querySelectorAll('[data-order-expand]')).find(function (candidate) {
                return candidate.dataset.orderId === id;
            });
            if (button) setExpanded(button, true);
            else expanded.delete(section.dataset.orderCardSection);
        });
        document.querySelectorAll('[data-order-table-scroll]').forEach(function (table, index) {
            var position = scrollPositions.get(index);
            if (position) { table.scrollTop = position[0]; table.scrollLeft = position[1]; }
        });
    });
})();
