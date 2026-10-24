<?php

session_start();

require_once __DIR__ . '/../includes/database.php';

$allowedCategories = ['SHIRTS', 'PANTS', 'SHORTS'];
$allowedGenders = ['MEN', 'WOMEN'];

$searchParam = $_GET['search'] ?? '';
$search = is_string($searchParam) ? trim($searchParam) : '';

$focusSearch = ($_GET['focus'] ?? '') === '1';

$category = $_GET['category'] ?? '';

if (!in_array($category, $allowedCategories, true)) {
    $category = '';
}

// Optional gender filter. Whitelist strictly to MEN or WOMEN.
// A missing or invalid value applies no gender restriction so the general
// Shop page keeps showing the full catalog (including legacy NULL-gender rows).
$gender = $_GET['gender'] ?? '';

if (!in_array($gender, $allowedGenders, true)) {
    $gender = '';
}

$sql = "SELECT p.id, p.name, p.category, p.gender, p.price, p.size, p.color, p.description, p.status, pi.image_path
     FROM products p
     LEFT JOIN product_images pi
         ON pi.id = (
             SELECT pi2.id
             FROM product_images pi2
             WHERE pi2.product_id = p.id
             ORDER BY pi2.sort_order ASC, pi2.id ASC
             LIMIT 1
         )
     WHERE p.status = 'AVAILABLE' AND p.retired_at IS NULL";

$params = [];

if ($category !== '') {
    $sql .= ' AND p.category = :category';
    $params[':category'] = $category;
}

// Gender narrows the set further (gender AND category). Only apply it when a
// valid MEN/WOMEN value is present, using a bound parameter. Rows with a NULL
// gender never satisfy p.gender = :gender, so unclassified legacy products stay
// hidden from gendered results until an admin classifies them.
if ($gender !== '') {
    $sql .= ' AND p.gender = :gender';
    $params[':gender'] = $gender;
}

$sql .= " ORDER BY CASE p.status WHEN 'AVAILABLE' THEN 0 ELSE 1 END, p.created_at DESC";

$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$products = $stmt->fetchAll();

$hasFilters = ($search !== '' || $category !== '' || $gender !== '');

$initialSearchTerms = $search !== ''
    ? preg_split('/\s+/', strtolower($search), -1, PREG_SPLIT_NO_EMPTY)
    : [];

$categoryOptions = [];
$sizeOptions = [];
$colorOptionsByKey = [];

foreach ($products as $product) {
    $productCategory = trim((string) ($product['category'] ?? ''));
    $productSize = trim((string) ($product['size'] ?? ''));
    $productColor = trim((string) ($product['color'] ?? ''));

    if ($productCategory !== '') {
        $categoryOptions[$productCategory] = $productCategory;
    }

    // Size labels remain exact so meaningful distinctions are never merged.
    if ($productSize !== '') {
        $sizeOptions[$productSize] = $productSize;
    }

    // Colors compare case-insensitively while keeping the database untouched.
    if ($productColor !== '') {
        $colorKey = strtolower($productColor);
        if (!isset($colorOptionsByKey[$colorKey])) {
            $colorOptionsByKey[$colorKey] = [
                'value' => $colorKey,
                'label' => ucwords($colorKey, " -/"),
            ];
        }
    }
}

$sortFilterLabels = static function (string $left, string $right): int {
    $comparison = strnatcasecmp($left, $right);
    return $comparison !== 0 ? $comparison : strcmp($left, $right);
};

$categoryOptions = array_values($categoryOptions);
$sizeOptions = array_values($sizeOptions);
usort($categoryOptions, $sortFilterLabels);
usort($sizeOptions, $sortFilterLabels);

$colorOptions = array_values($colorOptionsByKey);
usort($colorOptions, static function (array $left, array $right) use ($sortFilterLabels): int {
    return $sortFilterLabels($left['label'], $right['label']);
});

$filterGroups = [
    'category' => [
        'label' => 'Category',
        'options' => array_map(static function (string $value): array {
            return ['value' => $value, 'label' => $value];
        }, $categoryOptions),
    ],
    'size' => [
        'label' => 'Sizes',
        'options' => array_map(static function (string $value): array {
            return ['value' => $value, 'label' => $value];
        }, $sizeOptions),
    ],
    'color' => [
        'label' => 'Color',
        'options' => $colorOptions,
    ],
];

// Dynamic page heading based on the active gender + category context.
// Gender present  -> "MEN'S" / "WOMEN'S" (optionally + category, e.g. "MEN'S PANTS").
// Gender absent   -> empty string, preserving the existing category-only and
//                    general Shop behavior (no dedicated heading).
$genderHeadingLabels = [
    'MEN' => "MEN'S",
    'WOMEN' => "WOMEN'S",
];

$pageHeading = '';

if ($gender !== '') {
    $pageHeading = $genderHeadingLabels[$gender];

    if ($category !== '') {
        $pageHeading .= ' ' . $category;
    }
}

require_once __DIR__ . '/../includes/ui.php';

define('HUPIA_BASE', '..');

$page_title = 'Shop - ' . hopia_site_name();
$page_description = 'Preloved shirts, pants, and shorts at ' . hopia_site_name() . '.';
$ui_active = 'products.php';
$body_class = 'catalog-page';

$catalog_logged_in = !empty($_SESSION['customer_id']);

$csrfTokenCart = '';

if ($catalog_logged_in) {
    if (empty($_SESSION['csrf_token_cart'])) {
        $_SESSION['csrf_token_cart'] = bin2hex(random_bytes(32));
    }
    $csrfTokenCart = $_SESSION['csrf_token_cart'];
}

// Same cart-count query/source used by includes/ui.header.php, so the
// products-page cart icon shows the identical badge count.
$catalogCartCount = 0;

if ($catalog_logged_in) {
    $cartCountStmt = $pdo->prepare(
        'SELECT COUNT(ci.id) AS item_count
         FROM carts c
         INNER JOIN cart_items ci ON ci.cart_id = c.id
         WHERE c.customer_id = :customer_id'
    );
    $cartCountStmt->execute([':customer_id' => (int) $_SESSION['customer_id']]);
    $countResult = $cartCountStmt->fetch(PDO::FETCH_ASSOC);
    $catalogCartCount = (int) ($countResult['item_count'] ?? 0);
}

require __DIR__ . '/../includes/ui.head.php';
?>

<?php require __DIR__ . '/../includes/ui.header.php'; ?>

            <?php if ($pageHeading !== ''): ?>
                <h1 class="catalog-heading"><?= hopia_e($pageHeading) ?></h1>
            <?php endif; ?>

            <div class="catalog-tools">
                <form class="catalog-search" id="catalog-search-form" role="search">
                    <label class="sr-only" for="catalog-search-input">Search products</label>
                    <?php if ($gender !== ''): ?>
                        <input type="hidden" name="gender" value="<?= hopia_e($gender) ?>">
                    <?php endif; ?>
                    <?php if ($category !== ''): ?>
                        <input type="hidden" name="category" value="<?= hopia_e($category) ?>">
                    <?php endif; ?>
                    <svg class="catalog-search__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="6.5"></circle>
                        <line x1="16" y1="16" x2="20.5" y2="20.5"></line>
                    </svg>
                    <input
                        id="catalog-search-input"
                        type="search"
                        name="search"
                        placeholder="Search products"
                        value="<?= hopia_e($search) ?>"
                        autocomplete="off"
                        role="combobox"
                        aria-expanded="false"
                        aria-autocomplete="list"
                        aria-controls="catalog-suggestions"<?= $focusSearch ? ' autofocus' : '' ?>
                    >
                    <button type="button" class="catalog-search__clear" id="catalog-search-clear" aria-label="Clear search" hidden>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                            <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                    </button>
                </form>

                <button
                    type="button"
                    class="catalog-filter-trigger"
                    id="catalog-filter-trigger"
                    aria-haspopup="dialog"
                    aria-controls="catalog-filter-modal"
                    aria-expanded="false"
                >
                    <svg class="catalog-filter-trigger__icon" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <defs>
                            <linearGradient id="catalog-filter-gradient" x1="3" y1="3" x2="21" y2="21" gradientUnits="userSpaceOnUse">
                                <stop stop-color="#d7875d"/>
                                <stop offset="1" stop-color="#7a3828"/>
                            </linearGradient>
                        </defs>
                        <path d="M4 6h16M7 12h10M10 18h4" stroke="url(#catalog-filter-gradient)"/>
                    </svg>
                    <span>Filter</span>
                    <span class="catalog-filter-trigger__count" id="catalog-filter-count" hidden></span>
                </button>
            </div>

            <div class="catalog-suggestions" id="catalog-suggestions" role="listbox" aria-label="Product name suggestions" hidden></div>

            <div class="catalog-filter-modal" id="catalog-filter-modal" hidden>
                <div class="catalog-filter-modal__backdrop" data-filter-close aria-hidden="true"></div>
                <section
                    class="catalog-filter-modal__panel"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="catalog-filter-title"
                >
                    <div class="catalog-filter-modal__head">
                        <div>
                            <p class="catalog-filter-modal__eyebrow">Refine your fit</p>
                            <h2 class="catalog-filter-modal__title" id="catalog-filter-title">Filter Products</h2>
                        </div>
                        <button type="button" class="catalog-filter-modal__close" data-filter-close aria-label="Close filters">
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                                <path d="M5 5l14 14M19 5L5 19"/>
                            </svg>
                        </button>
                    </div>

                    <form class="catalog-filter-form" id="catalog-filter-form">
                        <div class="catalog-filter-form__groups">
                            <?php foreach ($filterGroups as $groupKey => $group): ?>
                                <?php $groupOptions = $group['options']; ?>
                                <fieldset class="catalog-filter-group" data-filter-options-group>
                                    <legend><?= hopia_e($group['label']) ?></legend>
                                    <div class="catalog-filter-group__options" id="catalog-filter-options-<?= hopia_e($groupKey) ?>">
                                        <?php if (count($groupOptions) === 0): ?>
                                            <p class="catalog-filter-group__empty">No options available</p>
                                        <?php else: ?>
                                            <?php foreach ($groupOptions as $optionIndex => $option): ?>
                                                <?php $optionId = 'catalog-filter-' . $groupKey . '-' . $optionIndex; ?>
                                                <label class="catalog-filter-option"<?= $optionIndex >= 5 ? ' data-filter-extra hidden' : '' ?>>
                                                    <input
                                                        id="<?= hopia_e($optionId) ?>"
                                                        type="checkbox"
                                                        value="<?= hopia_e($option['value']) ?>"
                                                        data-filter-group="<?= hopia_e($groupKey) ?>"
                                                    >
                                                    <span><?= hopia_e($option['label']) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (count($groupOptions) > 5): ?>
                                        <button
                                            type="button"
                                            class="catalog-filter-group__toggle"
                                            data-filter-show-more
                                            aria-controls="catalog-filter-options-<?= hopia_e($groupKey) ?>"
                                            aria-expanded="false"
                                        >Show More</button>
                                    <?php endif; ?>
                                </fieldset>
                            <?php endforeach; ?>
                        </div>

                        <div class="catalog-filter-form__actions">
                            <button type="button" class="catalog-filter-action catalog-filter-action--reset" id="catalog-filter-reset">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M4 4v6h6"/>
                                    <path d="M5.5 15a8 8 0 1 0 .8-7.8L4 10"/>
                                </svg>
                                Reset
                            </button>
                            <button type="submit" class="catalog-filter-action catalog-filter-action--search">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                                    <circle cx="11" cy="11" r="6.5"></circle>
                                    <path d="M16 16l4.5 4.5"/>
                                </svg>
                                Search
                            </button>
                        </div>
                    </form>
                </section>
            </div>

            <?php if (count($products) === 0): ?>

                <?php if ($hasFilters): ?>
                    <div class="empty-state empty-state--search">
                        <p class="empty-state__title">Nothing found.</p>
                        <p>Try another fit.</p>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <p>No products are available right now. Please check back later.</p>
                    </div>
                <?php endif; ?>

            <?php else: ?>

                <div class="catalog-grid">

                    <?php foreach ($products as $product): ?>

                        <?php
                        $productId = (int) $product['id'];

                        $isSold = ($product['status'] ?? '') === 'SOLD';

                        $productUrl = 'product.php?id=' . $productId;

                        $imagePath = trim((string) ($product['image_path'] ?? ''));

                        // Progressive fallback for submitted/search URLs. The live script
                        // below applies the same five-field search plus active filters.
                        $matchesInitialSearch = true;
                        if ($initialSearchTerms) {
                            $productSearchText = strtolower(implode(' ', [
                                (string) ($product['name'] ?? ''),
                                (string) ($product['category'] ?? ''),
                                (string) ($product['color'] ?? ''),
                                (string) ($product['size'] ?? ''),
                                (string) ($product['description'] ?? ''),
                            ]));
                            foreach ($initialSearchTerms as $term) {
                                if (strpos($productSearchText, $term) === false) {
                                    $matchesInitialSearch = false;
                                    break;
                                }
                            }
                        }
                        ?>

                        <article class="catalog-card<?= $isSold ? ' is-sold' : '' ?>"
                            data-category="<?= hopia_e(trim((string) ($product['category'] ?? ''))) ?>"
                            data-name="<?= hopia_e(trim((string) ($product['name'] ?? ''))) ?>"
                            data-color="<?= hopia_e(trim((string) ($product['color'] ?? ''))) ?>"
                            data-color-filter="<?= hopia_e(strtolower(trim((string) ($product['color'] ?? '')))) ?>"
                            data-size="<?= hopia_e(trim((string) ($product['size'] ?? ''))) ?>"
                            data-description="<?= hopia_e(trim((string) ($product['description'] ?? ''))) ?>"
                            <?= $matchesInitialSearch ? '' : ' hidden' ?>>

                            <div class="catalog-card__image">
                                <?php if ($imagePath !== ''): ?>
                                    <?php if (!$isSold): ?>
                                        <a class="catalog-card__image-link" href="<?= hopia_e($productUrl) ?>">
                                    <?php endif; ?>
                                        <img
                                            src="<?= hopia_e('../' . ltrim($imagePath, '/')) ?>"
                                            alt="<?= hopia_e($product['name']) ?>"
                                            loading="lazy"
                                        >
                                    <?php if (!$isSold): ?>
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="catalog-card__placeholder">No image</span>
                                <?php endif; ?>

                                <?php if (!$isSold): ?>
                                    <?php if ($catalog_logged_in): ?>
                                        <form class="catalog-quickadd" method="POST" action="cart-add.php">
                                            <input type="hidden" name="csrf_token" value="<?= hopia_e($csrfTokenCart) ?>">
                                            <input type="hidden" name="product_id" value="<?= $productId ?>">
                                            <button type="submit" class="catalog-card__cart" aria-label="Add <?= hopia_e($product['name']) ?> to cart">
                                                <span class="catalog-card__cart-drop">
                                                    <svg class="catalog-card__cart-arrow" width="14" height="12.25" viewBox="0 0 24 21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M12 5v14"></path>
                                                        <path d="M5 12l7 7 7-7"></path>
                                                    </svg>
                                                </span>
                                                <svg class="catalog-card__cart-bag" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                                                    <path d="M3 6h18"></path>
                                                    <path d="M16 10a4 4 0 0 1-8 0"></path>
                                                </svg>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <a class="catalog-card__cart" href="login.php" aria-label="Log in to add <?= hopia_e($product['name']) ?> to cart">
                                            <span class="catalog-card__cart-drop">
                                                <svg class="catalog-card__cart-arrow" width="14" height="12.25" viewBox="0 0 24 21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <path d="M12 5v14"></path>
                                                    <path d="M5 12l7 7 7-7"></path>
                                                </svg>
                                            </span>
                                            <svg class="catalog-card__cart-bag" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                                                <path d="M3 6h18"></path>
                                                <path d="M16 10a4 4 0 0 1-8 0"></path>
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <div class="catalog-card__body">
                                <p class="catalog-card__category"><?= hopia_e($product['category']) ?></p>

                                <h2 class="catalog-card__name">
                                    <?php if (!$isSold): ?>
                                        <a href="<?= hopia_e($productUrl) ?>"><?= hopia_e($product['name']) ?></a>
                                    <?php else: ?>
                                        <?= hopia_e($product['name']) ?>
                                    <?php endif; ?>
                                </h2>

                                <p class="catalog-card__detail">
                                    <span class="price catalog-card__price">&#8369;<?= number_format((float) $product['price'], 2) ?></span>
                                    <?php if ($isSold): ?>
                                        <span class="catalog-card__sold">Sold</span>
                                    <?php endif; ?>
                                </p>
                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

                <div class="empty-state empty-state--search" id="catalog-empty" role="status" aria-live="polite" hidden>
                    <p class="empty-state__title" id="catalog-empty-title">Nothing found.</p>
                    <p>Try another fit.</p>
                </div>

            <?php endif; ?>

<div id="cart-toast" class="toast" role="status" aria-live="polite">
    <div class="toast__content">
        <svg class="toast__icon" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
            <path d="M5 10l4 4 6-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <span id="cart-toast-text">Added to cart</span>
    </div>
    <a id="cart-toast-link" href="cart.php" class="btn btn-sm">View Cart</a>
    <button type="button" class="toast__close" aria-label="Dismiss">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
    </button>
</div>

<script>
(function() {
    var toast = document.getElementById('cart-toast');
    if (!toast) {
        return;
    }

    var toastText = document.getElementById('cart-toast-text');
    var toastLink = document.getElementById('cart-toast-link');
    var closeBtn = toast.querySelector('.toast__close');
    var hideTimer = null;

    function showToast(message, isError) {
        if (hideTimer) {
            clearTimeout(hideTimer);
        }
        toast.classList.remove('is-visible', 'toast--error');
        if (toastText) {
            toastText.textContent = message;
        }
        if (toastLink) {
            toastLink.hidden = isError;
        }
        if (isError) {
            toast.classList.add('toast--error');
        }
        // Force reflow so consecutive messages re-animate.
        void toast.offsetWidth;
        toast.classList.add('is-visible');
        hideTimer = setTimeout(hideToast, 4000);
    }

    function hideToast() {
        if (hideTimer) {
            clearTimeout(hideTimer);
            hideTimer = null;
        }
        toast.classList.remove('is-visible');
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', hideToast);
    }

    var MESSAGES = {
        auth: 'Please log in to add items to your cart.',
        csrf: 'Your session expired. Please try again.',
        unavailable: 'Sorry, this piece is no longer available.',
        invalid: 'This item could not be added.',
        error: 'Something went wrong. Please try again.'
    };

    // Both the desktop and mobile cart icons in the shared site header carry the
    // cart count, so a quick-add must keep every badge in sync.
    var cartLinks = Array.prototype.slice.call(document.querySelectorAll('.header-cart'));

    function updateCartBadge(count) {
        if (typeof count !== 'number' || isNaN(count)) {
            return;
        }
        cartLinks.forEach(function(cartLink) {
            var badge = cartLink.querySelector('.cart-badge');
            if (count > 0) {
                if (badge) {
                    badge.textContent = count;
                    badge.setAttribute('aria-label', count + ' items in cart');
                } else {
                    badge = document.createElement('span');
                    badge.className = 'cart-badge';
                    badge.setAttribute('aria-label', count + ' items in cart');
                    badge.textContent = count;
                    cartLink.appendChild(badge);
                }
            } else if (badge) {
                badge.parentNode.removeChild(badge);
            }
        });
    }

    document.querySelectorAll('.catalog-quickadd').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var button = form.querySelector('.catalog-card__cart');
            if (!button || button.disabled) {
                return;
            }
            button.disabled = true;
            button.classList.add('is-busy');

            fetch('cart-add.php', {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(response) {
                return response.json().catch(function() {
                    throw new Error('invalid');
                });
            })
            .then(function(data) {
                if (data.ok) {
                    showToast('Added to cart', false);
                    updateCartBadge(data.cart_count);
                } else {
                    if (data.reason === 'auth' && data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }
                    showToast(MESSAGES[data.reason] || MESSAGES.error, true);
                }
                // Server rotates the CSRF token on every response; keep every card in sync.
                if (data.token) {
                    document.querySelectorAll('.catalog-quickadd input[name="csrf_token"]').forEach(function(tokenField) {
                        tokenField.value = data.token;
                    });
                }
            })
            .catch(function() {
                showToast(MESSAGES.error, true);
            })
            .finally(function() {
                button.disabled = false;
                button.classList.remove('is-busy');
            });
        });
    });
})();

(function() {
    var input = document.getElementById('catalog-search-input');
    var clearBtn = document.getElementById('catalog-search-clear');
    var emptyState = document.getElementById('catalog-empty');
    var emptyTitle = document.getElementById('catalog-empty-title');
    var suggestionPanel = document.getElementById('catalog-suggestions');
    var filterModal = document.getElementById('catalog-filter-modal');
    var filterTrigger = document.getElementById('catalog-filter-trigger');
    var filterCount = document.getElementById('catalog-filter-count');
    var searchForm = document.getElementById('catalog-search-form');
    var filterForm = document.getElementById('catalog-filter-form');
    var filterReset = document.getElementById('catalog-filter-reset');

    if (!input) {
        return;
    }

    var cards = Array.prototype.slice.call(document.querySelectorAll('.catalog-card'));
    var appliedFilters = {
        category: [],
        size: [],
        color: []
    };
    var lastFocusedElement = null;

    // One data source: unique product names taken from the grid cards themselves.
    var names = [];
    var seen = {};

    cards.forEach(function(card) {
        var name = (card.getAttribute('data-name') || '').trim();
        var key = name.toLowerCase();
        if (name !== '' && !seen[key]) {
            seen[key] = true;
            names.push(name);
        }
    });

    var items = [];
    var activeIndex = -1;

    // Touch handling: tell a tap (select) apart from a vertical scroll (ignore),
    // so dragging over the list scrolls it instead of selecting a suggestion.
    var TAP_MOVE_THRESHOLD = 10;
    var pointerStartX = 0;
    var pointerStartY = 0;
    var pointerMoved = false;
    var trackedPointerId = null;

    function matchesText(searchable, terms) {
        searchable = searchable.toLowerCase();
        for (var i = 0; i < terms.length; i++) {
            if (searchable.indexOf(terms[i]) === -1) {
                return false;
            }
        }
        return true;
    }

    function matchesCard(card, terms) {
        var searchable = [
            card.getAttribute('data-name') || '',
            card.getAttribute('data-category') || '',
            card.getAttribute('data-color') || '',
            card.getAttribute('data-size') || '',
            card.getAttribute('data-description') || ''
        ].join(' ');
        return matchesText(searchable, terms);
    }

    function matchesAppliedFilters(card) {
        var filterAttributes = {
            category: 'data-category',
            size: 'data-size',
            color: 'data-color-filter'
        };
        var groups = Object.keys(appliedFilters);

        for (var i = 0; i < groups.length; i++) {
            var group = groups[i];
            var selectedValues = appliedFilters[group];
            if (selectedValues.length === 0) {
                continue;
            }
            var cardValue = (card.getAttribute(filterAttributes[group]) || '').trim();
            if (selectedValues.indexOf(cardValue) === -1) {
                return false;
            }
        }
        return true;
    }

    function hasAppliedFilters() {
        return Object.keys(appliedFilters).some(function(group) {
            return appliedFilters[group].length > 0;
        });
    }

    function selectedFilterCount() {
        return Object.keys(appliedFilters).reduce(function(total, group) {
            return total + appliedFilters[group].length;
        }, 0);
    }

    function updateFilterIndicator() {
        if (!filterCount || !filterTrigger) {
            return;
        }
        var count = selectedFilterCount();
        filterCount.hidden = count === 0;
        filterCount.textContent = count;
        filterTrigger.classList.toggle('has-filters', count > 0);
        filterTrigger.setAttribute('aria-label', count > 0
            ? 'Filter products, ' + count + ' filters applied'
            : 'Filter products');
    }

    function setActive(index) {
        activeIndex = index;
        items.forEach(function(item, i) {
            item.classList.toggle('is-active', i === index);
            if (i === index) {
                item.setAttribute('aria-selected', 'true');
            } else {
                item.removeAttribute('aria-selected');
            }
        });
        if (index >= 0 && items[index]) {
            input.setAttribute('aria-activedescendant', items[index].id);
            items[index].scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function hideSuggestions() {
        if (suggestionPanel) {
            suggestionPanel.hidden = true;
            suggestionPanel.innerHTML = '';
        }
        items = [];
        activeIndex = -1;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    function renderSuggestions(terms) {
        hideSuggestions();
        if (!suggestionPanel || terms.length === 0) {
            return;
        }
        names.forEach(function(name, i) {
            if (!matchesText(name, terms)) {
                return;
            }
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'catalog-suggestions__item';
            item.id = 'catalog-suggestion-' + i;
            item.setAttribute('role', 'option');
            item.textContent = name;
            // Select on a genuine tap/click. Selection is deferred to pointerup so a
            // drag/scroll can cancel it, and only non-touch pointers preventDefault
            // (to keep input focus and beat the blur timer); touch must stay default
            // so the browser can scroll the list natively.
            item.addEventListener('pointerdown', function(e) {
                trackedPointerId = e.pointerId;
                pointerStartX = e.clientX;
                pointerStartY = e.clientY;
                pointerMoved = false;
                if (e.pointerType !== 'touch') {
                    e.preventDefault();
                }
            });
            item.addEventListener('pointermove', function(e) {
                if (trackedPointerId !== e.pointerId || pointerMoved) {
                    return;
                }
                if (Math.abs(e.clientX - pointerStartX) > TAP_MOVE_THRESHOLD ||
                    Math.abs(e.clientY - pointerStartY) > TAP_MOVE_THRESHOLD) {
                    pointerMoved = true;
                }
            });
            item.addEventListener('pointerup', function(e) {
                if (trackedPointerId !== e.pointerId) {
                    return;
                }
                trackedPointerId = null;
                if (!pointerMoved) {
                    selectSuggestion(name);
                }
            });
            item.addEventListener('pointercancel', function(e) {
                if (trackedPointerId === e.pointerId) {
                    trackedPointerId = null;
                    pointerMoved = false;
                }
            });
            suggestionPanel.appendChild(item);
            items.push(item);
        });
        if (items.length > 0) {
            suggestionPanel.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }
    }

    function selectSuggestion(name) {
        input.value = name;
        applySearch(true);
        input.focus();
    }

    function applySearch(suppressPanel) {
        var terms = input.value.trim().toLowerCase().split(/\s+/).filter(function(term) {
            return term !== '';
        });
        var hasQuery = terms.length > 0;
        var hasFilters = hasAppliedFilters();
        var visible = 0;

        cards.forEach(function(card) {
            var matchesQuery = !hasQuery || matchesCard(card, terms);
            var show = matchesQuery && matchesAppliedFilters(card);
            card.hidden = !show;
            if (show) {
                visible++;
            }
        });

        if (clearBtn) {
            clearBtn.hidden = !hasQuery;
        }
        if (emptyState) {
            if ((hasQuery || hasFilters) && visible === 0) {
                if (emptyTitle) {
                    if (hasQuery && hasFilters) {
                        emptyTitle.textContent = 'No products match your search and filters.';
                    } else if (hasQuery) {
                        emptyTitle.textContent = 'No products found for "' + input.value.trim() + '".';
                    } else {
                        emptyTitle.textContent = 'No products match these filters.';
                    }
                }
                emptyState.hidden = false;
            } else {
                emptyState.hidden = true;
            }
        }

        if (suppressPanel || !hasQuery) {
            hideSuggestions();
        } else {
            renderSuggestions(terms);
        }
    }

    function readDraftFilters() {
        var nextFilters = {
            category: [],
            size: [],
            color: []
        };

        if (!filterForm) {
            return nextFilters;
        }

        filterForm.querySelectorAll('input[data-filter-group]:checked').forEach(function(checkbox) {
            var group = checkbox.getAttribute('data-filter-group');
            if (nextFilters[group]) {
                nextFilters[group].push(checkbox.value);
            }
        });

        return nextFilters;
    }

    function syncDraftFilters() {
        if (!filterForm) {
            return;
        }
        filterForm.querySelectorAll('input[data-filter-group]').forEach(function(checkbox) {
            var group = checkbox.getAttribute('data-filter-group');
            checkbox.checked = !!appliedFilters[group] && appliedFilters[group].indexOf(checkbox.value) !== -1;
        });
    }

    function collapseOptionGroups() {
        if (!filterForm) {
            return;
        }
        filterForm.querySelectorAll('[data-filter-options-group]').forEach(function(group) {
            group.querySelectorAll('[data-filter-extra]').forEach(function(option) {
                option.hidden = true;
            });
            var toggle = group.querySelector('[data-filter-show-more]');
            if (toggle) {
                toggle.textContent = 'Show More';
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function openFilterModal() {
        if (!filterModal || !filterTrigger) {
            return;
        }
        hideSuggestions();
        syncDraftFilters();
        lastFocusedElement = filterTrigger;
        filterModal.hidden = false;
        filterTrigger.setAttribute('aria-expanded', 'true');
        document.body.classList.add('is-modal-open');
        var closeButton = filterModal.querySelector('.catalog-filter-modal__close');
        if (closeButton) {
            closeButton.focus();
        }
    }

    function closeFilterModal(restoreFocus) {
        if (!filterModal || filterModal.hidden) {
            return;
        }
        filterModal.hidden = true;
        document.body.classList.remove('is-modal-open');
        if (filterTrigger) {
            filterTrigger.setAttribute('aria-expanded', 'false');
        }
        if (restoreFocus && lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
            lastFocusedElement.focus();
        }
    }

    function getModalFocusableElements() {
        if (!filterModal) {
            return [];
        }
        return Array.prototype.slice.call(filterModal.querySelectorAll(
            'button:not([disabled]), input:not([disabled]), [href], [tabindex]:not([tabindex="-1"])'
        )).filter(function(element) {
            return !element.closest('[hidden]');
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            input.value = '';
            applySearch();
            input.focus();
        });
    }

    input.addEventListener('input', function() {
        applySearch(false);
    });

    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            // Keep in-memory filters active when Enter submits the search field.
            e.preventDefault();
            applySearch(true);
        });
    }

    input.addEventListener('keydown', function(e) {
        if (items.length === 0) {
            return;
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((activeIndex + 1) % items.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((activeIndex - 1 + items.length) % items.length);
        } else if (e.key === 'Enter' && activeIndex >= 0) {
            e.preventDefault();
            selectSuggestion(items[activeIndex].textContent);
        } else if (e.key === 'Escape') {
            hideSuggestions();
        }
    });

    input.addEventListener('blur', function() {
        setTimeout(hideSuggestions, 120);
    });

    if (filterTrigger) {
        filterTrigger.addEventListener('click', openFilterModal);
    }

    if (filterModal) {
        filterModal.querySelectorAll('[data-filter-close]').forEach(function(control) {
            control.addEventListener('click', function() {
                closeFilterModal(true);
            });
        });
    }

    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            appliedFilters = readDraftFilters();
            updateFilterIndicator();
            applySearch(true);
            closeFilterModal(true);
        });

        filterForm.querySelectorAll('[data-filter-show-more]').forEach(function(toggle) {
            toggle.addEventListener('click', function() {
                var group = toggle.closest('[data-filter-options-group]');
                if (!group) {
                    return;
                }
                var expand = toggle.getAttribute('aria-expanded') !== 'true';
                group.querySelectorAll('[data-filter-extra]').forEach(function(option) {
                    option.hidden = !expand;
                });
                toggle.textContent = expand ? 'Show Less' : 'Show More';
                toggle.setAttribute('aria-expanded', expand ? 'true' : 'false');
            });
        });
    }

    if (filterReset) {
        filterReset.addEventListener('click', function() {
            appliedFilters = {
                category: [],
                size: [],
                color: []
            };
            input.value = '';
            if (filterForm) {
                filterForm.reset();
            }
            collapseOptionGroups();
            updateFilterIndicator();
            applySearch(true);
            closeFilterModal(true);
        });
    }

    document.addEventListener('keydown', function(e) {
        if (!filterModal || filterModal.hidden) {
            return;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            closeFilterModal(true);
            return;
        }
        if (e.key !== 'Tab') {
            return;
        }
        var focusable = getModalFocusableElements();
        if (focusable.length === 0) {
            e.preventDefault();
            return;
        }
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });

    // Keep the server-rendered query in sync with the live controls on first paint.
    updateFilterIndicator();
    applySearch(true);
})();
</script>

<?php require __DIR__ . '/../includes/ui.footer.php';
