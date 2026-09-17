<?php

// Hopia's V0.1 shared site header. Reads optional in-scope config vars set by a page:
//   $ui_section    : 'store' (default) | 'admin'
//   $ui_active     : exact nav URL to mark current (optional; auto-detected when omitted)
//   $body_class    : extra class(es) for the <body>
//   $ui_path_prefix: prefix for store links when rendered outside /customer
//   $ui_brand_href : optional brand URL override

require_once __DIR__ . '/ui.php';

$ui_section = isset($ui_section) && $ui_section === 'admin' ? 'admin' : 'store';
$ui_active = isset($ui_active) ? (string) $ui_active : '';
$body_class = isset($body_class) ? (string) $body_class : '';
$ui_path_prefix = isset($ui_path_prefix) ? (string) $ui_path_prefix : '';
$ui_brand_href_override = isset($ui_brand_href) ? (string) $ui_brand_href : '';

$ui_script = isset($_SERVER['SCRIPT_NAME']) ? basename((string) $_SERVER['SCRIPT_NAME']) : '';

if ($ui_section === 'admin') {
    $ui_logged_in = !empty($_SESSION['admin_id']);
    $ui_user_name = isset($_SESSION['admin_name']) ? (string) $_SESSION['admin_name'] : '';
    $ui_brand_href = 'index.php';
    $ui_brand_label = hopia_site_name() . ' - Admin';
    $ui_nav = [
        ['url' => 'index.php', 'label' => 'Dashboard'],
        ['url' => 'products.php', 'label' => 'Products'],
        ['url' => 'orders.php', 'label' => 'Orders'],
    ];
} else {
    $ui_logged_in = !empty($_SESSION['customer_id']);
    $ui_user_name = isset($_SESSION['customer_name']) ? (string) $_SESSION['customer_name'] : '';
    $ui_brand_href = $ui_brand_href_override !== '' ? $ui_brand_href_override : 'products.php';
    $ui_brand_label = hopia_site_name();
    $ui_nav = $ui_logged_in
        ? [
            ['url' => $ui_path_prefix . 'products.php', 'active' => 'products.php', 'label' => 'Shop'],
            ['url' => $ui_path_prefix . 'cart.php', 'active' => 'cart.php', 'label' => 'Cart'],
            ['url' => $ui_path_prefix . 'orders.php', 'active' => 'orders.php', 'label' => 'My Orders'],
            ['url' => $ui_path_prefix . 'account.php', 'active' => 'account.php', 'label' => 'My Account'],
        ]
        : [
            ['url' => $ui_path_prefix . 'products.php', 'active' => 'products.php', 'label' => 'Shop'],
        ];
}

?>
<body class="<?= hopia_e(trim('ui-' . $ui_section . ' ' . $body_class)) ?>">
    <a class="sr-only" href="#site-main">Skip to content</a>
    <header class="site-header">
        <div class="container site-header__inner">
            <a class="brand" href="<?= hopia_e($ui_brand_href) ?>"><?= hopia_e($ui_brand_label) ?></a>

            <nav class="site-nav" aria-label="Primary">
                <ul class="nav">
                    <?php foreach ($ui_nav as $ui_item): ?>
                        <?php
                        $ui_current = $ui_active !== ''
                            ? ($ui_active === ($ui_item['active'] ?? $ui_item['url']))
                            : ($ui_script === ($ui_item['active'] ?? $ui_item['url']));
                        ?>
                        <li>
                            <a href="<?= hopia_e($ui_item['url']) ?>"<?= $ui_current ? ' aria-current="page"' : '' ?>><?= hopia_e($ui_item['label']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <div class="site-header__actions">
                <?php if ($ui_section === 'admin'): ?>
                    <a class="link-subtle" href="../customer/products.php">View Store</a>
                    <?php if ($ui_user_name !== ''): ?>
                        <span class="site-header__user"><?= hopia_e($ui_user_name) ?></span>
                    <?php endif; ?>
                    <a class="btn btn-ghost btn-sm" href="logout.php">Logout</a>
                <?php elseif ($ui_logged_in): ?>
                    <?php if ($ui_user_name !== ''): ?>
                        <span class="site-header__user">Hi, <?= hopia_e($ui_user_name) ?></span>
                    <?php endif; ?>
                    <a class="btn btn-ghost btn-sm" href="<?= hopia_e($ui_path_prefix) ?>logout.php">Logout</a>
                <?php else: ?>
                    <a class="btn btn-secondary btn-sm" href="<?= hopia_e($ui_path_prefix) ?>register.php">Register</a>
                    <a class="btn btn-primary btn-sm" href="<?= hopia_e($ui_path_prefix) ?>login.php">Log In</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main id="site-main" class="site-main">
        <div class="container">
