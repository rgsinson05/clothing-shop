<?php

require_once __DIR__ . '/ui.php';

$page_title = isset($page_title) && $page_title !== ''
    ? (string) $page_title
    : hopia_site_name();

$page_description = isset($page_description)
    ? (string) $page_description
    : "Preloved shirts, pants, and shorts at " . hopia_site_name() . ".";

?>
<!DOCTYPE html>
<html lang="en"<?= ($ui_section ?? '') === 'admin' ? ' data-admin-theme="dark"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= ($ui_section ?? '') === 'admin' ? '#0b1120' : '#f5f4f1' ?>">
    <meta name="description" content="<?= hopia_e($page_description) ?>">
    <title><?= hopia_e($page_title) ?></title>
    <?php if (($ui_section ?? '') === 'admin'): ?>
        <script src="<?= hopia_e(hopia_asset('js/admin-theme.js')) ?>"></script>
    <?php endif; ?>
    <?php if (!empty($ui_editorial_fonts)): ?>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&amp;family=Playfair+Display:wght@600;700&amp;display=swap">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= hopia_e(hopia_asset('css/styles.css')) ?>">
    <?php if (($ui_section ?? '') === 'admin'): ?>
        <link rel="stylesheet" href="<?= hopia_e(hopia_asset('css/admin-notifications.css')) ?>">
        <link rel="stylesheet" href="<?= hopia_e(hopia_asset('css/admin-theme.css')) ?>">
        <link rel="stylesheet" href="<?= hopia_e(hopia_asset('css/admin-order-management.css')) ?>">
        <?php if (!empty($ui_admin_order_list)): ?>
            <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
        <?php endif; ?>
    <?php elseif (!empty($_SESSION['customer_id'])): ?>
        <link rel="stylesheet" href="<?= hopia_e(hopia_asset('css/customer-notifications.css')) ?>">
    <?php endif; ?>
</head>
