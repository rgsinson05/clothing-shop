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
    $ui_brand_label = 'HOPIA FITS';
    $ui_nav = [
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
                <div class="site-header__mobile-tools">
                    <button class="header-search-toggle" type="button" aria-expanded="false" aria-controls="header-search">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="6.5"></circle>
                            <path d="m16 16 4.5 4.5"></path>
                        </svg>
                        <span class="sr-only">Search products</span>
                    </button>
                    <?php $ui_mobile_cart_current = ($ui_active !== '' ? $ui_active : $ui_script) === 'cart.php'; ?>
                    <a class="header-icon header-cart header-cart--mobile" href="<?= hopia_e($ui_path_prefix . ($ui_logged_in ? 'cart.php' : 'login.php')) ?>"<?= $ui_mobile_cart_current ? ' aria-current="page"' : '' ?> aria-label="Cart">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 5h2l1.6 10.2a1.5 1.5 0 0 0 1.5 1.3h7.8a1.5 1.5 0 0 0 1.5-1.2L20 8H7"></path>
                            <circle cx="10" cy="20" r="1"></circle>
                            <circle cx="17" cy="20" r="1"></circle>
                        </svg>
                        <span class="sr-only">Cart</span>
                    </a>
                    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <line x1="4" y1="7" x2="20" y2="7"></line>
                        <line x1="4" y1="12" x2="20" y2="12"></line>
                        <line x1="4" y1="17" x2="20" y2="17"></line>
                    </svg>
                    <span class="sr-only">Menu</span>
                    </button>
                </div>
            <?php endif; ?>

            <?php if ($ui_section === 'admin'): ?>
                <nav class="site-nav" id="site-nav" aria-label="Primary">
                    <ul class="nav">
                        <?php foreach ($ui_nav as $ui_item): ?>
                            <?php
                            $ui_current = $ui_active !== ''
                                ? ($ui_active === ($ui_item['active'] ?? $ui_item['url']))
                                : ($ui_script === ($ui_item['active'] ?? $ui_item['url']));
                            ?>
                            <li><a href="<?= hopia_e($ui_item['url']) ?>"<?= $ui_current ? ' aria-current="page"' : '' ?>><?= hopia_e($ui_item['label']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            <?php else: ?>
                <nav class="site-nav" id="site-nav" aria-label="Primary">
                    <ul class="nav">
                        <?php $ui_shop_current = ($ui_active !== '' ? $ui_active : $ui_script) === 'products.php'; ?>
                        <li class="mobile-nav-shop"><a href="<?= hopia_e($ui_path_prefix) ?>products.php"<?= $ui_shop_current ? ' aria-current="page"' : '' ?>>Shop</a></li>
                        <?php foreach (['men' => 'Men', 'women' => 'Women'] as $genderKey => $genderLabel): ?>
                            <li class="nav-dropdown">
                                <a class="nav-dropdown__link" href="<?= hopia_e($ui_path_prefix) ?>products.php"><?= hopia_e($genderLabel) ?></a>
                                <button class="nav-dropdown__toggle" type="button" aria-label="Show <?= hopia_e($genderLabel) ?> categories" aria-expanded="false" aria-controls="nav-<?= hopia_e($genderKey) ?>"><span aria-hidden="true">&#9662;</span></button>
                                <div class="nav-dropdown__menu" id="nav-<?= hopia_e($genderKey) ?>">
                                    <?php foreach (['SHIRTS', 'PANTS', 'SHORTS'] as $category): ?>
                                        <a href="<?= hopia_e($ui_path_prefix) ?>products.php?category=<?= hopia_e($category) ?>"><?= hopia_e(ucfirst(strtolower($category))) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        <?php $ui_account_current = ($ui_active !== '' ? $ui_active : $ui_script) === 'account.php'; ?>
                        <li class="mobile-nav-account">
                            <a href="<?= hopia_e($ui_path_prefix . ($ui_logged_in ? 'account.php' : 'login.php')) ?>"<?= $ui_account_current ? ' aria-current="page"' : '' ?>>
                                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="12" cy="8" r="3.2"></circle>
                                    <path d="M5.5 20c.7-3.5 3-5.2 6.5-5.2s5.8 1.7 6.5 5.2"></path>
                                </svg>
                                <span>Account</span>
                            </a>
                        </li>
                    </ul>
                </nav>
                <form class="header-search" id="header-search" method="GET" action="<?= hopia_e($ui_path_prefix) ?>products.php" role="search">
                    <label class="sr-only" for="header-search-input">Search products</label>
                    <input id="header-search-input" type="search" name="search" placeholder="Search products" autocomplete="off">
                    <button type="submit" aria-label="Submit product search">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="6.5"></circle>
                            <path d="m16 16 4.5 4.5"></path>
                        </svg>
                    </button>
                </form>
            <?php endif; ?>

            <div class="site-header__actions">
                <?php if ($ui_section === 'admin'): ?>
                    <a class="link-subtle" href="../customer/products.php">View Store</a>
                    <?php if ($ui_user_name !== ''): ?>
                        <span class="site-header__user"><?= hopia_e($ui_user_name) ?></span>
                    <?php endif; ?>
                    <a class="btn btn-ghost btn-sm" href="logout.php">Logout</a>
                <?php else: ?>
                    <div class="header-account">
                        <button class="header-icon header-account__toggle" type="button" aria-expanded="false" aria-controls="header-account-menu" aria-label="Account">
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="8" r="3.2"></circle>
                                <path d="M5.5 20c.7-3.5 3-5.2 6.5-5.2s5.8 1.7 6.5 5.2"></path>
                            </svg>
                            <span class="sr-only">Account</span>
                        </button>
                        <div class="header-account__menu" id="header-account-menu">
                            <a href="<?= hopia_e($ui_path_prefix . ($ui_logged_in ? 'account.php' : 'login.php')) ?>"<?= $ui_account_current ? ' aria-current="page"' : '' ?>>Account</a>
                            <?php if ($ui_logged_in): ?>
                                <a href="<?= hopia_e($ui_path_prefix) ?>logout.php">Logout</a>
                            <?php else: ?>
                                <a href="<?= hopia_e($ui_path_prefix) ?>register.php">Register</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php $ui_cart_current = ($ui_active !== '' ? $ui_active : $ui_script) === 'cart.php'; ?>
                    <a class="header-icon header-cart header-cart--desktop" href="<?= hopia_e($ui_path_prefix . ($ui_logged_in ? 'cart.php' : 'login.php')) ?>"<?= $ui_cart_current ? ' aria-current="page"' : '' ?> aria-label="Cart">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 5h2l1.6 10.2a1.5 1.5 0 0 0 1.5 1.3h7.8a1.5 1.5 0 0 0 1.5-1.2L20 8H7"></path>
                            <circle cx="10" cy="20" r="1"></circle>
                            <circle cx="17" cy="20" r="1"></circle>
                        </svg>
                        <span class="sr-only">Cart</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <?php if ($ui_section !== 'admin'): ?>
        <script>
            (function () {
                var header = document.querySelector('.site-header');
                var toggle = header ? header.querySelector('.nav-toggle') : null;
                var searchToggle = header ? header.querySelector('.header-search-toggle') : null;
                var search = header ? header.querySelector('.header-search') : null;
                var accountToggle = header ? header.querySelector('.header-account__toggle') : null;
                var account = header ? header.querySelector('.header-account') : null;
                if (!header || !toggle) {
                    return;
                }

                function closeMenu() {
                    header.classList.remove('nav-open');
                    toggle.setAttribute('aria-expanded', 'false');
                }

                function closeDropdowns() {
                    header.querySelectorAll('.nav-dropdown.is-open').forEach(function (dropdown) {
                        dropdown.classList.remove('is-open');
                        var button = dropdown.querySelector('.nav-dropdown__toggle');
                        if (button) {
                            button.setAttribute('aria-expanded', 'false');
                        }
                    });
                }

                function closeAccount() {
                    if (account && accountToggle) {
                        account.classList.remove('is-open');
                        accountToggle.setAttribute('aria-expanded', 'false');
                    }
                }

                toggle.addEventListener('click', function () {
                    var open = header.classList.toggle('nav-open');
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                });

                header.querySelectorAll('.nav-dropdown__toggle').forEach(function (button) {
                    button.addEventListener('click', function () {
                        var dropdown = button.closest('.nav-dropdown');
                        var open = dropdown.classList.toggle('is-open');
                        button.setAttribute('aria-expanded', open ? 'true' : 'false');
                    });
                });

                if (accountToggle && account) {
                    accountToggle.addEventListener('click', function () {
                        var open = account.classList.toggle('is-open');
                        accountToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    });
                }

                if (searchToggle && search) {
                    searchToggle.addEventListener('click', function () {
                        var open = search.classList.toggle('is-open');
                        searchToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                        if (open) {
                            search.querySelector('input').focus();
                        }
                    });
                }

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        closeMenu();
                        closeDropdowns();
                        closeAccount();
                        if (search && searchToggle) {
                            search.classList.remove('is-open');
                            searchToggle.setAttribute('aria-expanded', 'false');
                        }
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
