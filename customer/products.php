<?php

session_start();

require_once __DIR__ . '/../includes/database.php';

$allowedCategories = ['SHIRTS', 'PANTS', 'SHORTS'];
$allowedGenders = ['MEN', 'WOMEN'];

$search = trim($_GET['search'] ?? '');

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
     WHERE p.status = 'AVAILABLE'";

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

$searchTerms = [];

if ($search !== '') {
    $searchTerms = preg_split('/\s+/', strtolower($search), -1, PREG_SPLIT_NO_EMPTY);
}

$visibleCount = 0;

require_once __DIR__ . '/../includes/ui.php';

define('HUPIA_BASE', '..');

$page_title = 'Shop - ' . hopia_site_name();
$page_description = 'Preloved shirts, pants, and shorts at ' . hopia_site_name() . '.';
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

<body class="ui-store <?= hopia_e($body_class) ?>">
    <a class="sr-only" href="#site-main">Skip to content</a>

    <header class="catalog-head">
        <div class="container catalog-head__inner">
            <a class="catalog-head__icon catalog-head__exit" href="../index.php" aria-label="Close and return to homepage">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <line x1="5" y1="5" x2="19" y2="19"></line>
                    <line x1="19" y1="5" x2="5" y2="19"></line>
                </svg>
            </a>
            <a class="catalog-head__brand" href="../index.php">HOPIA FITS</a>
            <a class="catalog-head__icon catalog-head__cart" href="<?= hopia_e($catalog_logged_in ? 'cart.php' : 'login.php') ?>" aria-label="Cart" style="position: relative;">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 5h2l1.6 10.2a1.5 1.5 0 0 0 1.5 1.3h7.8a1.5 1.5 0 0 0 1.5-1.2L20 8H7"></path>
                    <circle cx="10" cy="20" r="1"></circle>
                    <circle cx="17" cy="20" r="1"></circle>
                </svg>
                <?php if ($catalogCartCount > 0): ?>
                    <span class="cart-badge" aria-label="<?= (int) $catalogCartCount ?> items in cart"><?= hopia_e($catalogCartCount) ?></span>
                <?php endif; ?>
            </a>
        </div>
    </header>

    <main id="site-main" class="site-main">
        <div class="container">

            <?php if ($pageHeading !== ''): ?>
                <h1 class="catalog-heading"><?= hopia_e($pageHeading) ?></h1>
            <?php endif; ?>

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

            <div class="catalog-suggestions" id="catalog-suggestions" role="listbox" aria-label="Product name suggestions" hidden></div>

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

                        $imagePath = trim($product['image_path'] ?? '');

                        $matchesSearch = true;

                        if ($searchTerms) {
                            $nameLower = strtolower($product['name']);
                            foreach ($searchTerms as $term) {
                                if ($term !== '' && strpos($nameLower, $term) === false) {
                                    $matchesSearch = false;
                                    break;
                                }
                            }
                        }

                        if ($matchesSearch) {
                            $visibleCount++;
                        }
                        ?>

                        <article class="catalog-card<?= $isSold ? ' is-sold' : '' ?>"
                            data-category="<?= hopia_e($product['category']) ?>"
                            data-name="<?= hopia_e($product['name']) ?>"
                            data-color="<?= hopia_e($product['color']) ?>"
                            data-size="<?= hopia_e($product['size']) ?>"
                            data-description="<?= hopia_e($product['description']) ?>"
                            <?= $matchesSearch ? '' : ' hidden' ?>>

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

                <div class="empty-state empty-state--search" id="catalog-empty" hidden>
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

    var cartLink = document.querySelector('.catalog-head__cart');

    function updateCartBadge(count) {
        if (!cartLink || typeof count !== 'number' || isNaN(count)) {
            return;
        }
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
    var panel = document.getElementById('catalog-suggestions');

    if (!input) {
        return;
    }

    var cards = Array.prototype.slice.call(document.querySelectorAll('.catalog-card'));

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

    function matchesCard(card, terms) {
        var searchable = [
            card.getAttribute('data-name') || '',
            card.getAttribute('data-category') || '',
            card.getAttribute('data-color') || '',
            card.getAttribute('data-size') || '',
            card.getAttribute('data-description') || ''
        ].join(' ').toLowerCase();
        for (var i = 0; i < terms.length; i++) {
            if (searchable.indexOf(terms[i]) === -1) {
                return false;
            }
        }
        return true;
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
        if (panel) {
            panel.hidden = true;
            panel.innerHTML = '';
        }
        items = [];
        activeIndex = -1;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    function renderSuggestions(terms) {
        hideSuggestions();
        if (!panel || terms.length === 0) {
            return;
        }
        names.forEach(function(name, i) {
            if (!matchesCard({getAttribute: function() { return name; }}, terms)) {
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
            panel.appendChild(item);
            items.push(item);
        });
        if (items.length > 0) {
            panel.hidden = false;
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
        var visible = 0;

        cards.forEach(function(card) {
            var show = !hasQuery || matchesCard(card, terms);
            card.hidden = !show;
            if (show) {
                visible++;
            }
        });

        if (clearBtn) {
            clearBtn.hidden = !hasQuery;
        }
        if (emptyState) {
            if (hasQuery && visible === 0) {
                var queryText = input.value.trim();
                var emptyTitle = document.getElementById('catalog-empty-title');
                if (emptyTitle) {
                    emptyTitle.textContent = 'No products found for "' + queryText + '".';
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

    // Keep the server-rendered query in sync with the live controls on first paint.
    applySearch(true);
})();
</script>

<?php require __DIR__ . '/../includes/ui.footer.php';
