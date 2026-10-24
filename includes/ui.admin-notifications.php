<?php

if (empty($_SESSION['admin_id'])) {
    return;
}
require_once __DIR__ . '/admin_notifications.php';
if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}
$notificationCount = null;
try {
    if (!isset($pdo)) {
        throw new RuntimeException('The notification database is unavailable.');
    }
    $notificationCount = admin_notifications_count($pdo);
} catch (Throwable $e) {
    error_log('Admin notification badge: ' . $e->getMessage());
}
?>
<div class="admin-notifications" data-admin-notifications data-endpoint="api/notifications.php" data-csrf="<?= hopia_e($_SESSION['admin_csrf_token']) ?>">
    <button class="admin-notifications__bell" type="button" aria-expanded="false" aria-controls="admin-notification-panel" aria-label="<?= $notificationCount === null ? 'Notifications unavailable' : 'Notifications, ' . $notificationCount . ' unread' ?>">
        <?= hopia_admin_icon('bell') ?>
        <span class="admin-notifications__badge" aria-hidden="true"><?= $notificationCount === null ? '!' : hopia_e(admin_notifications_badge($notificationCount)) ?></span>
    </button>
    <section class="admin-notifications__panel" id="admin-notification-panel" aria-labelledby="admin-notification-title" hidden>
        <div class="admin-notifications__heading">
            <h2 id="admin-notification-title">New orders</h2>
            <p>Placement details, not current order status. Opening marks unread notifications as read for all admins.</p>
        </div>
        <p class="admin-notifications__status" role="status" aria-live="polite"></p>
        <ul class="admin-notifications__list"></ul>
        <a class="admin-notifications__more" href="notifications.php">See More</a>
    </section>
    <noscript><a href="notifications.php">Notification history</a></noscript>
</div>
