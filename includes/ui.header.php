<?php

// Hopia's V0.1 shared site header. Reads optional in-scope config vars set by a page:
//   $ui_section    : 'store' (default) | 'admin'
//   $ui_active     : exact nav URL to mark current (optional; auto-detected when omitted)
//   $body_class    : extra class(es) for the <body>
//   $ui_path_prefix: prefix for store links when rendered outside /customer
//   $ui_brand_href : optional brand URL override
//
// Admin pages render a dedicated dark-slate left sidebar shell (fixed on
// desktop, a drawer on mobile). The customer storefront keeps its original
// top navigation. Both share this one file so the brand, logo, and routes
// stay consistent across every admin page.

require_once __DIR__ . '/ui.php';

// Custom editorial line-art nav icons (Hopias refined editorial resale identity).
// All icons: 24x24 viewBox, thin-to-medium stroke, currentColor, no fills.
$hopia_nav_icons = [
    // Male symbol - MEN
    'men' => function () {
        ?><svg class="nav-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="14" r="4"></circle><line x1="12.8" y1="11.2" x2="21" y2="3"></line><polyline points="15 3 21 3 21 9"></polyline></svg><?php
    },
    // Female symbol - WOMEN
    'women' => function () {
        ?><svg class="nav-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="10" r="4"></circle><line x1="12" y1="14" x2="12" y2="22"></line><line x1="8.5" y1="19" x2="15.5" y2="19"></line></svg><?php
    },
    // Quote / speech bubble - CUSTOMER'S FEEDBACK
    'feedback' => function () {
        ?><svg class="nav-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 11.2a7.6 7.6 0 0 1-7.7 7.4c-1 0-2-.17-2.9-.5L4 19.5l1.4-4A7 7 0 0 1 4.6 11.2 7.6 7.6 0 0 1 12.3 3.8 7.6 7.6 0 0 1 20 11.2z"></path><path d="M9 10.8h.01M12.4 10.8h.01M15.8 10.8h.01"></path></svg><?php
    },
    // Collection grid - EXPLORE
    'explore' => function () {
        ?><svg class="nav-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="4" width="7" height="7" rx="1"></rect><rect x="13" y="4" width="7" height="7" rx="1"></rect><rect x="4" y="13" width="7" height="7" rx="1"></rect><rect x="13" y="13" width="7" height="7" rx="1"></rect></svg><?php
    },
    // T-shirt - category
    'shirt' => function () {
        ?><svg class="nav-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 4 4.6 6.7a1 1 0 0 0-.4 1.2L5.5 11l2.5-.9V20h8v-9.9l2.5.9 1.3-3.1a1 1 0 0 0-.4-1.2L15 4a3 3 0 0 1-6 0z"></path></svg><?php
    },
    // Pants - category
    'pants' => function () {
        ?><svg class="nav-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3h8l1.5 18h-5L12 10.5 11.5 21h-5L8 3z"></path><path d="M8 7h8"></path></svg><?php
    },
    // Shorts - category
    'shorts' => function () {
        ?><svg class="nav-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4h10l2 14h-6.5L12 11l-.5 7H5L7 4z"></path><path d="M7 8h10"></path></svg><?php
    },
    // Info circle - ABOUT
    'about' => function () {
        ?><svg class="nav-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"></circle><path d="M12 11v5"></path><path d="M12 8h.01"></path></svg><?php
    },
    // Gallery frames - GALLERY
    'gallery' => function () {
        ?><svg class="nav-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="6.5" width="14" height="11" rx="1"></rect><path d="M7.5 6.5V5a1.5 1.5 0 0 1 1.5-1.5h10A1.5 1.5 0 0 1 20.5 5v9A1.5 1.5 0 0 1 19 15.5h-1.5"></path></svg><?php
    },
    // Envelope - CONTACT
    'contact' => function () {
        ?><svg class="nav-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="5.5" width="17" height="13" rx="1.5"></rect><path d="m4.5 7 7.5 6 7.5-6"></path></svg><?php
    },
    // Shop / rail - SHOP (mobile)
    'shop' => function () {
        ?><svg class="nav-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8h14l-1 12H6L5 8z"></path><path d="M9 10V6.5a3 3 0 0 1 6 0V10"></path></svg><?php
    },
    // Category icon map for dropdown labels
    'category' => [
        'SHIRTS' => 'shirt',
        'PANTS' => 'pants',
        'SHORTS' => 'shorts',
    ],
    'explore_link' => [
        'About' => 'about',
        'Gallery' => 'gallery',
        'Contact' => 'contact',
    ],
];

// Render helper for the shared icon set.
function hopia_nav_icon(array $icons, string $name): void
{
    if (isset($icons[$name]) && $icons[$name] instanceof Closure) {
        $icons[$name]();
    }
}

if (!function_exists('hopia_admin_icon')) {
    // Line-art icons for the admin sidebar. Trusted static inline SVG (no user
    // input); stroke inherits currentColor so the active/hover states colour
    // the icon with the nav text. Returns '' for an unknown key.
    function hopia_admin_icon(string $name): string
    {
        $paths = [
            // Dashboard - 2x2 panel grid.
            'dashboard' =>
                '<rect x="3.5" y="3.5" width="7" height="7" rx="1.3"/>'
                . '<rect x="13.5" y="3.5" width="7" height="4.5" rx="1.3"/>'
                . '<rect x="13.5" y="11.5" width="7" height="9" rx="1.3"/>'
                . '<rect x="3.5" y="13.5" width="7" height="7" rx="1.3"/>',
            // Products - price tag.
            'products' =>
                '<path d="M4 4h6.2a2 2 0 0 1 1.4.6l8 8a2 2 0 0 1 0 2.8l-5.6 5.6a2 2 0 0 1-2.8 0l-8-8A2 2 0 0 1 2.6 11.6L2 5.5"/>'
                . '<circle cx="7.5" cy="7.5" r="1.3"/>',
            // Bundles - stacked layers.
            'bundles' =>
                '<path d="m12 3 9 5-9 5-9-5 9-5Z"/>'
                . '<path d="m3 12 9 5 9-5"/>'
                . '<path d="m3 16.5 9 5 9-5"/>',
            // Legacy assignment - archive box with a down arrow.
            'legacy' =>
                '<rect x="3.5" y="4" width="17" height="4.5" rx="1"/>'
                . '<path d="M5 8.5h14V19a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 19V8.5Z"/>'
                . '<path d="M10 12h4"/>',
            // Barcode labels.
            'barcode' =>
                '<path d="M4 5v14M7 5v14M10.5 5v14M14 5v14M17 5v14M20 5v14"/>',
            // Walk-in sale - cash register / point of sale.
            'register' =>
                '<rect x="3.5" y="9" width="17" height="11" rx="1.5"/>'
                . '<path d="M6.5 9V6.5A1.5 1.5 0 0 1 8 5h5a1.5 1.5 0 0 1 1.5 1.5V9"/>'
                . '<path d="M7.5 13h3M14 13h2.5M7.5 16.5h9"/>',
            // Sales monitoring - bar chart.
            'chart' =>
                '<path d="M4 20V4"/>'
                . '<path d="M4 20h16"/>'
                . '<rect x="7.5" y="12" width="3" height="5"/>'
                . '<rect x="12.5" y="8.5" width="3" height="8.5"/>'
                . '<rect x="17.5" y="14" width="0.01" height="3"/>'
                . '<path d="M8 9.5l3.5-3 3 2 3.5-4"/>',
            // Orders - shopping bag.
            'orders' =>
                '<path d="M6 8h12l1 12.5H5L6 8Z"/>'
                . '<path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
            'bell' =>
                '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
            // Verify items - clipboard with a check.
            'verify' =>
                '<rect x="5" y="4.5" width="14" height="16" rx="1.5"/>'
                . '<path d="M9 4.5V3.6A1.1 1.1 0 0 1 10.1 2.5h3.8A1.1 1.1 0 0 1 15 3.6v.9"/>'
                . '<path d="m8.8 13 2.2 2.2 4.2-4.4"/>',
            // Store Pickup - storefront with a collection check.
            'pickup' =>
                '<path d="M4 10.5V20h16v-9.5"/>'
                . '<path d="M3 10.5 4.5 5h15L21 10.5a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z"/>'
                . '<path d="m9.5 16.5 1.8 1.8 3.4-3.6"/>',
            // View store - storefront / awning.
            'store' =>
                '<path d="M4 10.5V20h16v-9.5"/>'
                . '<path d="M3 10.5 4.5 5h15L21 10.5a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z"/>'
                . '<path d="M10 20v-4.5h4V20"/>',
            // Logout - door with exit arrow.
            'logout' =>
                '<path d="M15 4h2.5A1.5 1.5 0 0 1 19 5.5v13a1.5 1.5 0 0 1-1.5 1.5H15"/>'
                . '<path d="M10 16.5 14.5 12 10 7.5"/>'
                . '<path d="M14.5 12H3.5"/>',
        ];

        if (!isset($paths[$name])) {
            return '';
        }

        return '<svg class="admin-nav__glyph" viewBox="0 0 24 24" width="18" height="18"'
            . ' fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $paths[$name]
            . '</svg>';
    }
}

$ui_section = isset($ui_section) && $ui_section === 'admin' ? 'admin' : 'store';
$ui_active = isset($ui_active) ? (string) $ui_active : '';
$body_class = isset($body_class) ? (string) $body_class : '';
$ui_path_prefix = isset($ui_path_prefix) ? (string) $ui_path_prefix : '';
$ui_brand_href_override = isset($ui_brand_href) ? (string) $ui_brand_href : '';

$ui_script = isset($_SERVER['SCRIPT_NAME']) ? basename((string) $_SERVER['SCRIPT_NAME']) : '';

if ($ui_section === 'store' && !empty($_SESSION['customer_id']) && empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$ui_cart_count = 0;
if (!empty($_SESSION['customer_id']) && isset($pdo)) {
    $cartCountStmt = $pdo->prepare(
        'SELECT COUNT(ci.id) AS item_count
         FROM carts c
         INNER JOIN cart_items ci ON ci.cart_id = c.id
         WHERE c.customer_id = :customer_id'
    );
    $cartCountStmt->execute([':customer_id' => (int) $_SESSION['customer_id']]);
    $countResult = $cartCountStmt->fetch(PDO::FETCH_ASSOC);
    $ui_cart_count = (int) ($countResult['item_count'] ?? 0);
}

if ($ui_section === 'admin') {
    $ui_logged_in = !empty($_SESSION['admin_id']);
    $ui_user_name = isset($_SESSION['admin_name']) ? (string) $_SESSION['admin_name'] : '';
    $ui_brand_href = 'index.php';
    $ui_brand_label = 'Hopia Fits';

    // Grouped admin navigation. Every URL is a real existing admin route.
    $ui_admin_nav = [
        'OVERVIEW' => [
            ['url' => 'index.php', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ],
        'INVENTORY' => [
            ['url' => 'products.php', 'label' => 'Products', 'icon' => 'products'],
            ['url' => 'bundles.php', 'label' => 'Bundles & Profitability', 'icon' => 'bundles'],
            ['url' => 'legacy-assign.php', 'label' => 'Legacy Inventory Assignment', 'icon' => 'legacy'],
            ['url' => 'labels.php', 'label' => 'Barcode Labels', 'icon' => 'barcode'],
        ],
        'SALES' => [
            ['url' => 'scan.php', 'label' => 'Walk-in Sale', 'icon' => 'register'],
            ['url' => 'sales.php', 'label' => 'Sales Monitoring', 'icon' => 'chart'],
        ],
        'ONLINE ORDERS' => [
            ['url' => 'orders.php', 'label' => 'Orders', 'icon' => 'orders'],
            ['url' => 'notifications.php', 'label' => 'Notifications', 'icon' => 'bell'],
            ['url' => 'order-verify.php', 'label' => 'Scan Online Orders', 'icon' => 'verify'],
        ],
        'PICK-UP ORDERS' => [
            ['url' => 'pickups.php', 'label' => 'Store Pickup', 'icon' => 'pickup'],
            // Dedicated Store Pickup barcode-verification destination. It is
            // separate from the courier scanner (admin/order-verify.php) and
            // gates ORDER_RECEIVED -> PREPARING on every item being verified.
            ['url' => 'pickup-verify.php', 'label' => 'Scan Pickup Orders', 'icon' => 'verify'],
        ],
    ];

    // Compact initials for the sidebar footer identity badge.
    $ui_admin_initials = '';
    $ui_name_parts = preg_split('/\s+/', trim($ui_user_name));
    if ($ui_name_parts && $ui_name_parts[0] !== '') {
        $first = $ui_name_parts[0];
        $last = count($ui_name_parts) > 1 ? $ui_name_parts[count($ui_name_parts) - 1] : '';
        $slice = function ($value) {
            return function_exists('mb_substr') ? mb_substr($value, 0, 1, 'UTF-8') : substr($value, 0, 1);
        };
        $upper = function ($value) {
            return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
        };
        $ui_admin_initials = $upper($slice($first) . ($last !== '' ? $slice($last) : ''));
    }
    if ($ui_admin_initials === '') {
        $ui_admin_initials = 'HF';
    }
} else {
    $ui_logged_in = !empty($_SESSION['customer_id']);
    $ui_user_name = isset($_SESSION['customer_name']) ? (string) $_SESSION['customer_name'] : '';
    $ui_brand_href = $ui_brand_href_override !== '' ? $ui_brand_href_override : '../index.php';
    $ui_brand_label = 'HOPIA FITS';

    // Root-level pages set $ui_path_prefix = 'customer/'; /customer/*.php pages leave it
    // empty. Derive a matching relative prefix back to the project root for the public
    // pages (Customer's Feedback, About, Gallery, Contact).
    $ui_root_prefix = $ui_path_prefix === '' ? '../' : '';
    $ui_nav = [
        ['url' => $ui_path_prefix . 'products.php', 'active' => 'products.php', 'label' => 'Shop'],
    ];
}

?>
<body class="<?= hopia_e(trim('ui-' . $ui_section . ' ' . $body_class)) ?>">
    <a class="sr-only" href="#site-main">Skip to content</a>
<?php if ($ui_section === 'admin'): ?>
    <?php
    $ui_admin_logo = hopia_asset('assets/videos/pictures/Hopia-fits-logo.webp');
    ?>
    <div class="admin-shell" id="admin-shell">
        <button class="admin-shell__backdrop no-print" type="button" data-admin-nav-close aria-label="Close navigation" hidden></button>

        <aside class="admin-sidebar no-print" id="admin-sidebar">
            <a class="admin-sidebar__brand" href="<?= hopia_e($ui_brand_href) ?>">
                <img class="admin-sidebar__logo" src="<?= hopia_e($ui_admin_logo) ?>" alt="Hopia Fits" width="40" height="40">
                <span class="admin-sidebar__brand-text">
                    <span class="admin-sidebar__brand-name"><?= hopia_e($ui_brand_label) ?></span>
                    <span class="admin-sidebar__brand-sub">Admin Console</span>
                </span>
            </a>

            <nav class="admin-sidebar__nav" aria-label="Admin navigation">
                <?php foreach ($ui_admin_nav as $ui_group => $ui_group_items): ?>
                    <p class="admin-nav__group"><?= hopia_e($ui_group) ?></p>
                    <ul class="admin-nav__list">
                        <?php foreach ($ui_group_items as $ui_item): ?>
                            <?php
                            $ui_current = $ui_active !== ''
                                ? ($ui_active === $ui_item['url'])
                                : ($ui_script === $ui_item['url']);
                            ?>
                            <li>
                                <a class="admin-nav__link<?= $ui_current ? ' is-active' : '' ?>" href="<?= hopia_e($ui_item['url']) ?>"<?= $ui_current ? ' aria-current="page"' : '' ?>>
                                    <span class="admin-nav__icon"><?= hopia_admin_icon($ui_item['icon']) ?></span>
                                    <span class="admin-nav__label"><?= hopia_e($ui_item['label']) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </nav>

            <div class="admin-sidebar__footer">
                <a class="admin-sidebar__link" href="../customer/products.php">
                    <span class="admin-nav__icon"><?= hopia_admin_icon('store') ?></span>
                    <span class="admin-nav__label">View Store</span>
                </a>
                <?php if ($ui_user_name !== ''): ?>
                    <div class="admin-sidebar__identity">
                        <span class="admin-sidebar__avatar" aria-hidden="true"><?= hopia_e($ui_admin_initials) ?></span>
                        <span class="admin-sidebar__identity-text">
                            <span class="admin-sidebar__identity-name"><?= hopia_e($ui_user_name) ?></span>
                            <span class="admin-sidebar__identity-role">Administrator</span>
                        </span>
                    </div>
                <?php endif; ?>
                <a class="admin-sidebar__link admin-sidebar__link--logout" href="logout.php">
                    <span class="admin-nav__icon"><?= hopia_admin_icon('logout') ?></span>
                    <span class="admin-nav__label">Logout</span>
                </a>
            </div>
        </aside>

        <div class="admin-main">
            <header class="admin-topbar admin-topbar--notifications no-print">
                <button class="admin-topbar__toggle" type="button" aria-expanded="false" aria-controls="admin-sidebar" data-admin-nav-toggle>
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <line x1="4" y1="7" x2="20" y2="7"></line>
                        <line x1="4" y1="12" x2="20" y2="12"></line>
                        <line x1="4" y1="17" x2="20" y2="17"></line>
                    </svg>
                    <span class="sr-only">Toggle navigation</span>
                </button>
                <a class="admin-topbar__brand" href="<?= hopia_e($ui_brand_href) ?>">
                    <img class="admin-topbar__logo" src="<?= hopia_e($ui_admin_logo) ?>" alt="" width="30" height="30">
                    <span><?= hopia_e($ui_brand_label) ?></span>
                </a>
                <div class="admin-topbar__utilities">
                    <?php require __DIR__ . '/ui.admin-theme-toggle.php'; ?>
                    <?php require __DIR__ . '/ui.admin-notifications.php'; ?>
                </div>
            </header>

            <main id="site-main" class="admin-content">
                <div class="admin-content__inner">
                    <?php require __DIR__ . '/ui.admin-success.php'; ?>
<?php else: ?>
    <header class="site-header">
        <div class="container site-header__inner">
            <a class="brand" href="<?= hopia_e($ui_brand_href) ?>"><img class="brand__logo" src="<?= hopia_e(hopia_asset('assets/videos/pictures/Hopia-fits-logo.webp')) ?>" alt="" width="43" height="43"><span class="brand__text"><?= hopia_e($ui_brand_label) ?></span></a>
                <div class="site-header__mobile-tools">
                    <a class="header-search-toggle" href="<?= hopia_e($ui_path_prefix) ?>products.php?focus=1">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="6.5"></circle>
                            <path d="m16 16 4.5 4.5"></path>
                        </svg>
                        <span class="sr-only">Search products</span>
                    </a>
                    <?php $ui_mobile_cart_current = ($ui_active !== '' ? $ui_active : $ui_script) === 'cart.php'; ?>
                    <a class="header-icon header-cart header-cart--mobile" href="<?= hopia_e($ui_path_prefix . ($ui_logged_in ? 'cart.php' : 'login.php')) ?>"<?= $ui_mobile_cart_current ? ' aria-current="page"' : '' ?> aria-label="Cart">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 5h2l1.6 10.2a1.5 1.5 0 0 0 1.5 1.3h7.8a1.5 1.5 0 0 0 1.5-1.2L20 8H7"></path>
                            <circle cx="10" cy="20" r="1"></circle>
                            <circle cx="17" cy="20" r="1"></circle>
                        </svg>
                        <?php if ($ui_cart_count > 0): ?>
                            <span class="cart-badge" aria-label="<?= (int) $ui_cart_count ?> items in cart"><?= hopia_e($ui_cart_count) ?></span>
                        <?php endif; ?>
                        <span class="sr-only">Cart</span>
                    </a>
                    <?php if ($ui_logged_in): ?>
                        <button class="header-icon customer-notifications__bell" type="button" data-customer-notification-bell aria-label="Notifications" aria-expanded="false" aria-controls="customer-notifications" hidden>
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
                            <span class="customer-notifications__badge" data-notification-badge aria-hidden="true" hidden></span>
                        </button>
                    <?php endif; ?>
                    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <line x1="4" y1="7" x2="20" y2="7"></line>
                        <line x1="4" y1="12" x2="20" y2="12"></line>
                        <line x1="4" y1="17" x2="20" y2="17"></line>
                    </svg>
                    <span class="sr-only">Menu</span>
                    </button>
                </div>

                <nav class="site-nav" id="site-nav" aria-label="Primary">
                    <ul class="nav">
                        <?php $ui_shop_current = ($ui_active !== '' ? $ui_active : $ui_script) === 'products.php'; ?>
                        <li class="mobile-nav-shop"><a href="<?= hopia_e($ui_path_prefix) ?>products.php"<?= $ui_shop_current ? ' aria-current="page"' : '' ?>><?php hopia_nav_icon($hopia_nav_icons, 'shop'); ?>Shop</a></li>
                        <?php foreach (['men' => 'Men', 'women' => 'Women'] as $genderKey => $genderLabel): ?>
                            <?php $genderUrl = strtoupper($genderKey); ?>
                            <li class="nav-dropdown">
                                <a class="nav-dropdown__link" href="<?= hopia_e($ui_path_prefix) ?>products.php?gender=<?= hopia_e($genderUrl) ?>"><?php hopia_nav_icon($hopia_nav_icons, $genderKey); ?><?= hopia_e($genderLabel) ?></a>
                                <button class="nav-dropdown__toggle" type="button" aria-label="Show <?= hopia_e($genderLabel) ?> categories" aria-expanded="false" aria-controls="nav-<?= hopia_e($genderKey) ?>"><span aria-hidden="true">&#9662;</span></button>
                                <div class="nav-dropdown__menu" id="nav-<?= hopia_e($genderKey) ?>">
                                    <p class="nav-dropdown__label">SHOP <?= hopia_e(strtoupper($genderLabel)) ?></p>
                                    <?php foreach (['SHIRTS', 'PANTS', 'SHORTS'] as $category): ?>
                                        <a href="<?= hopia_e($ui_path_prefix) ?>products.php?gender=<?= hopia_e($genderUrl) ?>&category=<?= hopia_e($category) ?>"><?php hopia_nav_icon($hopia_nav_icons, $hopia_nav_icons['category'][$category] ?? strtolower($category)); ?><?= hopia_e(ucfirst(strtolower($category))) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        <?php
                        // Primary direct link: Customer's Feedback. The customer/ prefix set by
                        // root pages resolves the link back into /customer from every context.
                        $ui_feedback_href = $ui_path_prefix . 'feedback.php';
                        $ui_feedback_current = basename($ui_active !== '' ? $ui_active : $ui_script) === 'feedback.php';
                        ?>
                        <li class="nav-primary">
                            <a href="<?= hopia_e($ui_feedback_href) ?>"<?= $ui_feedback_current ? ' aria-current="page"' : '' ?>><?php hopia_nav_icon($hopia_nav_icons, 'feedback'); ?>Customer's Feedback</a>
                        </li>
                        <?php
                        // Explore groups the editorial pages About, Gallery and Contact, which
                        // live at the project root. $ui_root_prefix steps back up from /customer.
                        $ui_explore_current = basename($ui_active !== '' ? $ui_active : $ui_script);
                        $ui_explore_links = [
                            ['href' => $ui_root_prefix . 'about.php',   'file' => 'about.php',   'label' => 'About'],
                            ['href' => $ui_root_prefix . 'gallery.php', 'file' => 'gallery.php', 'label' => 'Gallery'],
                            ['href' => $ui_root_prefix . 'contact.php', 'file' => 'contact.php', 'label' => 'Contact'],
                        ];
                        ?>
                        <li class="nav-dropdown nav-dropdown--explore">
                            <button class="nav-dropdown__toggle" type="button" aria-expanded="false" aria-controls="nav-explore"><?php hopia_nav_icon($hopia_nav_icons, 'explore'); ?>Explore <span aria-hidden="true">&#9662;</span></button>
                            <div class="nav-dropdown__menu" id="nav-explore">
                                <p class="nav-dropdown__label">EXPLORE</p>
                                <?php foreach ($ui_explore_links as $ui_explore_link): ?>
                                    <a href="<?= hopia_e($ui_explore_link['href']) ?>"<?= $ui_explore_current === $ui_explore_link['file'] ? ' aria-current="page"' : '' ?>><?php hopia_nav_icon($hopia_nav_icons, strtolower($ui_explore_link['label'])); ?><?= hopia_e($ui_explore_link['label']) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </li>
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
                <a class="header-icon header-search-desktop" href="<?= hopia_e($ui_path_prefix) ?>products.php?focus=1" aria-label="Search products">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="6.5"></circle>
                        <path d="m16 16 4.5 4.5"></path>
                    </svg>
                    <span class="sr-only">Search products</span>
                </a>

            <div class="site-header__actions">
                    <div class="header-account">
                        <button class="header-icon header-account__toggle" type="button" aria-expanded="false" aria-controls="header-account-menu" aria-label="Account">
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="8" r="3.2"></circle>
                                <path d="M5.5 20c.7-3.5 3-5.2 6.5-5.2s5.8 1.7 6.5 5.2"></path>
                            </svg>
                            <span class="sr-only">Account</span>
                        </button>
                        <div class="header-account__menu" id="header-account-menu">
                            <p class="header-account__label">ACCOUNT</p>
                            <a href="<?= hopia_e($ui_path_prefix . ($ui_logged_in ? 'account.php' : 'login.php')) ?>"<?= $ui_account_current ? ' aria-current="page"' : '' ?>><svg class="nav-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.2"></circle><path d="M5.5 20c.7-3.5 3-5.2 6.5-5.2s5.8 1.7 6.5 5.2"></path></svg>My Account</a>
                            <?php if ($ui_logged_in): ?>
                                <a href="<?= hopia_e($ui_path_prefix) ?>orders.php"><svg class="nav-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="1"></rect><path d="M9 4V3.2A1.2 1.2 0 0 1 10.2 2h3.6A1.2 1.2 0 0 1 15 3.2V4"></path><path d="M8.5 10h7M8.5 14h7M8.5 18h4"></path></svg>My Orders</a>
                                <div class="header-account__divider" aria-hidden="true"></div>
                                <a class="header-logout" href="<?= hopia_e($ui_path_prefix) ?>logout.php" data-logout>Log Out</a>
                            <?php else: ?>
                                <a href="<?= hopia_e($ui_path_prefix) ?>register.php"><svg class="nav-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="8" r="3.2"></circle><path d="M3.5 20c.7-3.5 3-5.2 6.5-5.2s5.8 1.7 6.5 5.2"></path><path d="M18.5 10v6M15.5 13h6"></path></svg>Register</a>
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
                        <?php if ($ui_cart_count > 0): ?>
                            <span class="cart-badge" aria-label="<?= (int) $ui_cart_count ?> items in cart"><?= hopia_e($ui_cart_count) ?></span>
                        <?php endif; ?>
                        <span class="sr-only">Cart</span>
                    </a>
                    <?php if ($ui_logged_in): ?>
                        <button class="header-icon customer-notifications__bell" type="button" data-customer-notification-bell aria-label="Notifications" aria-expanded="false" aria-controls="customer-notifications" hidden>
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
                            <span class="customer-notifications__badge" data-notification-badge aria-hidden="true" hidden></span>
                        </button>
                    <?php endif; ?>
            </div>
        </div>
    </header>

    <?php if ($ui_logged_in): ?>
        <?php require __DIR__ . '/ui.customer-notifications.php'; ?>
    <?php endif; ?>

    <?php if ($ui_logged_in): ?>
        <div class="logout-confirm" id="logout-confirm" role="dialog" aria-modal="true" aria-labelledby="logout-confirm-title" hidden>
            <div class="logout-confirm__backdrop" data-logout-cancel></div>
            <div class="logout-confirm__panel" role="document">
                <p class="logout-confirm__eyebrow">ACCOUNT</p>
                <div class="logout-confirm__rule logout-confirm__rule--top" aria-hidden="true"></div>
                <h2 class="logout-confirm__title" id="logout-confirm-title">READY TO LEAVE?</h2>
                <p class="logout-confirm__copy">You'll need to sign in again to access your account.</p>
                <div class="logout-confirm__rule logout-confirm__rule--bottom" aria-hidden="true"></div>
                <div class="logout-confirm__actions">
                    <button class="logout-confirm__stay" type="button" data-logout-cancel>STAY SIGNED IN</button>
                    <a class="logout-confirm__leave" href="<?= hopia_e($ui_path_prefix) ?>logout.php" data-logout-accept>LOG OUT <span class="logout-confirm__arrow" aria-hidden="true">&rarr;</span></a>
                </div>
            </div>
        </div>

        <script>
            (function () {
                var modal = document.getElementById('logout-confirm');
                var lastTrigger = null;
                if (!modal) {
                    return;
                }

                function openLogoutConfirm(redirectHref) {
                    lastTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
                    modal.setAttribute('data-logout-href', redirectHref);
                    modal.hidden = false;
                    document.body.classList.add('is-modal-open');
                    var stay = modal.querySelector('.logout-confirm__stay');
                    if (stay) {
                        stay.focus();
                    }
                }

                function closeLogoutConfirm() {
                    modal.hidden = true;
                    document.body.classList.remove('is-modal-open');
                    if (lastTrigger && document.contains(lastTrigger)) {
                        lastTrigger.focus();
                    }
                    lastTrigger = null;
                }

                document.addEventListener('click', function (event) {
                    var trigger = event.target.closest('[data-logout]');
                    if (!trigger) {
                        return;
                    }

                    event.preventDefault();
                    openLogoutConfirm(trigger.getAttribute('href') || modal.querySelector('[data-logout-accept]').getAttribute('href'));
                });

                modal.addEventListener('click', function (event) {
                    if (event.target.closest('[data-logout-cancel]')) {
                        event.preventDefault();
                        closeLogoutConfirm();
                        return;
                    }

                    var accept = event.target.closest('[data-logout-accept]');
                    if (accept) {
                        event.preventDefault();
                        var href = modal.getAttribute('data-logout-href') || accept.getAttribute('href');
                        modal.setAttribute('aria-busy', 'true');
                        window.location.href = href;
                    }
                });

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape' && !modal.hidden) {
                        closeLogoutConfirm();
                    }
                });
            })();
        </script>
    <?php endif; ?>

        <script>
            (function () {
                var header = document.querySelector('.site-header');
                var toggle = header ? header.querySelector('.nav-toggle') : null;
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
                        // On desktop the dropdown is driven by CSS :hover / :focus-within,
                        // so a click must not latch .is-open — otherwise the menu stays
                        // open after the pointer leaves the trigger.
                        if (window.matchMedia('(min-width: 720px)').matches) {
                            return;
                        }
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

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        closeMenu();
                        closeDropdowns();
                        closeAccount();
                    }
                });

                window.matchMedia('(min-width: 720px)').addEventListener('change', function (event) {
                    if (event.matches) {
                        closeMenu();
                    }
                });
            })();
        </script>

    <main id="site-main" class="site-main">
        <div class="container">
<?php endif; ?>
