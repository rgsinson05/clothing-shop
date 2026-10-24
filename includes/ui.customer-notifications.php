<?php

// Both responsive header bells share this customer-only panel and controller.
if (empty($_SESSION['customer_id']) || $ui_section !== 'store') {
    return;
}
?>
<section class="customer-notifications" id="customer-notifications" aria-labelledby="customer-notifications-title" tabindex="-1" hidden
    data-endpoint="<?= hopia_e($ui_path_prefix . 'api/notifications.php') ?>"
    data-csrf="<?= hopia_e($_SESSION['csrf_token']) ?>"
    data-scope="<?= hopia_e(hash('sha256', 'customer-notifications:' . (int) $_SESSION['customer_id'])) ?>">
    <div class="customer-notifications__heading">
        <h2 id="customer-notifications-title">Notifications</h2>
        <button class="customer-notifications__close" type="button" data-notification-close aria-label="Close notifications">&times;</button>
    </div>
    <div class="customer-notifications__content">
        <p class="customer-notifications__status" data-notification-status role="status" aria-live="polite"></p>
        <button class="customer-notifications__retry" type="button" data-notification-retry hidden>Try again</button>
        <a class="customer-notifications__signin" href="<?= hopia_e($ui_path_prefix . 'login.php') ?>" data-notification-signin hidden>Sign in</a>
        <ol class="customer-notifications__list" data-notification-list hidden></ol>
    </div>
    <div class="customer-notifications__footer">
        <p data-notification-more hidden></p>
        <a href="<?= hopia_e($ui_path_prefix . 'orders.php') ?>">My orders <span aria-hidden="true">&rarr;</span></a>
    </div>
</section>
<aside class="customer-notification-toast" data-notification-toast hidden aria-label="Order update">
    <div>
        <p class="customer-notification-toast__label">Order update</p>
        <p class="customer-notification-toast__message" data-notification-toast-message role="status" aria-live="polite" aria-atomic="true"></p>
    </div>
    <button class="customer-notifications__close" type="button" data-notification-toast-close aria-label="Dismiss order notification">&times;</button>
</aside>
<script src="<?= hopia_e(hopia_asset('js/customer-notifications.js')) ?>" defer></script>
