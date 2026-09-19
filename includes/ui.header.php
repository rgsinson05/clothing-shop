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
    $ui_brand_href = $ui_brand_href_override !== '' ? $ui_brand_href_override : '../index.php';
    $ui_brand_label = hopia_site_name();
    $ui_nav = $ui_logged_in
        ? [
            ['url' => $ui_path_prefix . 'products.php', 'active' => 'products.php', 'label' => 'Shop'],
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
            <?php if ($ui_section !== 'admin'): ?>
                <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <line x1="4" y1="7" x2="20" y2="7"></line>
                        <line x1="4" y1="12" x2="20" y2="12"></line>
                        <line x1="4" y1="17" x2="20" y2="17"></line>
                    </svg>
                    <span class="sr-only">Menu</span>
                </button>
            <?php endif; ?>

            <nav class="site-nav" id="site-nav" aria-label="Primary">
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

            <?php if ($ui_section !== 'admin' && $ui_logged_in): ?>
                <?php $ui_cart_current = ($ui_active !== '' ? $ui_active : $ui_script) === 'cart.php'; ?>
                <a class="nav__cart" href="<?= hopia_e($ui_path_prefix) ?>cart.php"<?= $ui_cart_current ? ' aria-current="page"' : '' ?>>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <path d="M16 10a4 4 0 0 1-8 0"></path>
                    </svg>
                    <span class="sr-only">Cart</span>
                </a>
            <?php endif; ?>

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

    <?php if ($ui_section !== 'admin'): ?>
        <script>
            (function () {
                var header = document.querySelector('.site-header');
                var toggle = header ? header.querySelector('.nav-toggle') : null;
                if (!header || !toggle) {
                    return;
                }

                function closeMenu() {
                    header.classList.remove('nav-open');
                    toggle.setAttribute('aria-expanded', 'false');
                }

                toggle.addEventListener('click', function () {
                    var open = header.classList.toggle('nav-open');
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                });

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        closeMenu();
                    }
                });

                window.matchMedia('(min-width: 720px)').addEventListener('change', function (event) {
                    if (event.matches) {
                        closeMenu();
                    }
                });
            })();
        </script>
    <?php endif; ?>

    <main id="site-main" class="site-main">
        <div class="container">
