<?php

session_start();
if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/admin_notifications.php';

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
if ($page === false || (isset($_GET['page']) && $page === null)) {
    http_response_code(400);
    exit('Invalid notification page.');
}
$page = $page ?? 1;
$notifications = [];
$notificationError = '';
$hasOlder = false;
try {
    require_once __DIR__ . '/../includes/database.php';
    $notifications = admin_notifications_latest($pdo, 21, ($page - 1) * 20);
    $hasOlder = count($notifications) > 20;
    $notifications = array_slice($notifications, 0, 20);
} catch (Throwable $e) {
    error_log('Admin notification history: ' . $e->getMessage());
    http_response_code(503);
    $notificationError = 'Notifications are temporarily unavailable. Please try again.';
}

$page_title = 'Notifications - Admin - Hopia Fits';
$ui_section = 'admin';
$ui_active = 'notifications.php';
require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>
<header class="admin-page-header">
    <div>
        <p class="admin-page-header__eyebrow">Order management</p>
        <h1>NOTIFICATIONS</h1>
        <p class="admin-page-header__copy">New customer orders, newest first. Methods reflect placement, not later changes. Read state is shared by all admins; opening the bell marks unread notifications as read.</p>
    </div>
</header>
<section class="admin-notifications-history" aria-label="New-order notification history">
    <?php if ($notificationError !== ''): ?>
        <p class="admin-notifications__status" role="alert"><?= hopia_e($notificationError) ?></p>
    <?php elseif ($notifications === []): ?>
        <p class="admin-notifications__status">No notifications on this page.</p>
    <?php else: ?>
        <ul class="admin-notifications__list">
            <?php foreach ($notifications as $notification): ?>
                <li>
                    <a class="admin-notifications__item<?= $notification['unread'] ? ' is-unread' : '' ?>" data-notification-id="<?= $notification['id'] ?>" href="<?= hopia_e($notification['url']) ?>">
                        <strong><?= hopia_e($notification['message']) ?></strong>
                        <span class="admin-notifications__meta"><time datetime="<?= hopia_e($notification['created_at']) ?>"><?= hopia_e($notification['time_label']) ?></time><span data-read-label><?= $notification['unread'] ? 'Unread' : 'Read' ?></span></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<nav class="admin-notifications__pagination" aria-label="Notification pages">
    <?php if ($page > 1): ?><a class="btn btn-secondary" href="notifications.php?page=<?= $page - 1 ?>">Newer</a><?php endif; ?>
    <span>Page <?= $page ?></span>
    <?php if ($hasOlder): ?><a class="btn btn-secondary" href="notifications.php?page=<?= $page + 1 ?>">Older</a><?php endif; ?>
</nav>
<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
