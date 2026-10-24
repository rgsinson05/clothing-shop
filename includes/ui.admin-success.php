<?php

require_once __DIR__ . '/admin-feedback.php';
$ui_admin_success = hopia_admin_success_take();

?>
<div class="admin-success no-print" id="admin-success"<?= $ui_admin_success === '' ? ' hidden' : '' ?>>
    <span class="admin-success__icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9"></circle>
            <path d="m8 12 2.5 2.5L16 9"></path>
        </svg>
    </span>
    <div class="admin-success__message" role="status" aria-live="polite" aria-atomic="true" data-admin-success-message><?= hopia_e($ui_admin_success) ?></div>
    <button class="admin-success__close" type="button" aria-label="Dismiss success message" data-admin-success-close>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="m6 6 12 12M18 6 6 18"></path>
        </svg>
    </button>
</div>
<script src="<?= hopia_e(hopia_asset('js/admin-success.js')) ?>"></script>
