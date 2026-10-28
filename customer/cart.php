<?php

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/cart_selection.php';
require_once __DIR__ . '/../includes/customer_auth.php';

if (!isset($_SESSION['customer_id'])) {
    hopia_customer_set_intended_destination('cart.php');
    header('Location: login.php');
    exit;
}

$customerId = (int) $_SESSION['customer_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$cartStmt = $pdo->prepare(
    'SELECT id
     FROM carts
     WHERE customer_id = :customer_id'
);

$cartStmt->execute([':customer_id' => $customerId]);
$cart = $cartStmt->fetch();

$cartItems = [];
$cartId = 0;
$selectedIds = [];

if ($cart !== false) {
    $cartId = (int) $cart['id'];
    $selectedIds = cart_selection_prune($pdo, $cartId);
    $itemsStmt = $pdo->prepare(
        'SELECT ci.id AS cart_item_id, p.id, p.name, p.size, p.category, p.color, p.price, p.status, p.retired_at, pi.image_path
         FROM cart_items ci
         INNER JOIN products p ON p.id = ci.product_id
         LEFT JOIN product_images pi
             ON pi.id = (
                 SELECT pi2.id
                 FROM product_images pi2
                 WHERE pi2.product_id = p.id
                 ORDER BY pi2.sort_order ASC, pi2.id ASC
                 LIMIT 1
             )
         WHERE ci.cart_id = :cart_id
         ORDER BY ci.created_at ASC, ci.id ASC'
    );

    $itemsStmt->execute([':cart_id' => $cartId]);
    $cartItems = $itemsStmt->fetchAll();

}

$selectionSummary = cart_selection_summary($cartItems, $selectedIds);
$selectionError = $_SESSION['cart_selection_error'] ?? '';
unset($_SESSION['cart_selection_error']);
$pendingCartPayments = [];
foreach ($_SESSION['checkout_attempts'] ?? [] as $token => $attempt) {
    if ($attempt['customer_id'] === $customerId && $attempt['mode'] === 'cart' && $attempt['cart_id'] === $cartId
        && empty($attempt['order_id']) && (!empty($attempt['proof_path']) || !empty($attempt['reference_valid']))) {
        $pendingCartPayments[$token] = $attempt;
    }
}

require_once __DIR__ . '/../includes/ui.php';

$page_title = 'Your Cart - ' . hopia_site_name();

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<section class="cart-page">
    <header class="cart-header">
        <h1>YOUR CART</h1>
        <?php if (count($cartItems) > 0): ?>
            <p class="cart-header__count"><?= str_pad((string) count($cartItems), 2, '0', STR_PAD_LEFT) ?> <?= count($cartItems) === 1 ? 'ITEM' : 'ITEMS' ?> IN YOUR BAG</p>
        <?php endif; ?>
        <div class="cart-header__accent" aria-hidden="true"></div>
    </header>

    <?php if ($selectionError !== ''): ?>
        <p class="alert alert-error" role="alert"><?= hopia_e($selectionError) ?></p>
    <?php endif; ?>

    <?php foreach ($pendingCartPayments as $token => $attempt): ?>
        <div class="alert alert-warning" role="status">
            GCash evidence is retained for an unfinished checkout<?= empty($attempt['reference']) ? '' : ' (reference ' . hopia_e($attempt['reference']) . ')' ?>.
            Do not send another payment or apply it to different items.
            <a href="checkout.php?checkout=<?= hopia_e($token) ?>">Resume the original checkout</a> or contact the shop with your receipt.
        </div>
    <?php endforeach; ?>

    <?php if (count($cartItems) === 0): ?>

        <div class="cart-empty">
            <div class="cart-empty__icon" aria-hidden="true">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <path d="M16 10a4 4 0 0 1-8 0"></path>
                </svg>
            </div>
            <h2>Your Cart Is Empty</h2>
            <p>Discover unique pre-loved pieces waiting for a new home.</p>
            <a class="btn btn-primary" href="products.php">Shop All Finds</a>
        </div>

    <?php else: ?>

        <div class="cart-layout">
            <div class="cart-items-wrap">
                <form id="cart-selection-form" method="POST" action="cart-selection.php">
                    <input type="hidden" name="csrf_token" value="<?= hopia_e($csrfToken) ?>">
                    <input type="hidden" name="selection_revision" value="<?= cart_selection_revision($cartId) ?>">
                </form>
                <div class="cart-select-controls" role="group" aria-label="Cart selection controls">
                    <button type="submit" form="cart-selection-form" name="action" value="select_all" class="btn btn-secondary btn-sm" id="cart-select-all">Select All</button>
                    <button type="submit" form="cart-selection-form" name="action" value="deselect_all" class="btn btn-ghost btn-sm" id="cart-deselect-all">Deselect All</button>
                    <button type="submit" form="cart-selection-form" name="action" value="set" class="btn btn-secondary btn-sm" id="cart-save-selection">Update Selection</button>
                    <span class="cart-select-count" id="cart-select-count" aria-live="polite"><?= count($selectedIds) ?> selected</span>
                </div>
                <noscript><p class="hint">Use Update Selection after changing checkboxes to save your selection and update checkout eligibility.</p></noscript>
                <p class="alert alert-error" id="cart-selection-error" role="alert" hidden></p>
                <div class="cart-items" role="list">
                    <?php foreach ($cartItems as $item): ?>
                        <?php
                        $imagePath = trim($item['image_path'] ?? '');
                        $size = trim($item['size'] ?? '');
                        $color = trim($item['color'] ?? '');
                        $category = trim($item['category'] ?? '');
                        $isSelected = in_array((int) $item['cart_item_id'], $selectedIds, true);
                        $isUnavailable = $item['status'] !== 'AVAILABLE' || $item['retired_at'] !== null;
                        $metaParts = [];
                        if ($size !== '') {
                            $metaParts[] = 'Size ' . $size;
                        }
                        if ($color !== '') {
                            $metaParts[] = 'Color ' . $color;
                        }
                        ?>
                        <article class="cart-item<?= $isUnavailable ? ' cart-item--unavailable' : '' ?>" role="listitem" data-cart-item-id="<?= (int) $item['cart_item_id'] ?>" data-price-cents="<?= (int) round((float) $item['price'] * 100) ?>" data-unavailable="<?= $isUnavailable ? '1' : '0' ?>" data-name="<?= hopia_e($item['name'] . ' (#' . (int) $item['id'] . ')') ?>">
                            <label class="cart-item__check">
                                <input type="checkbox" form="cart-selection-form" name="selected[]" class="cart-item__checkbox" value="<?= (int) $item['cart_item_id'] ?>" <?= $isSelected ? 'checked' : '' ?> aria-label="Select <?= hopia_e($item['name']) ?>" aria-describedby="cart-availability-<?= (int) $item['cart_item_id'] ?>">
                            </label>
                            <div class="cart-item__image">
                                <?php if ($imagePath !== ''): ?>
                                    <img
                                        src="<?= hopia_e('../' . ltrim($imagePath, '/')) ?>"
                                        alt="<?= hopia_e($item['name']) ?>"
                                        loading="lazy"
                                    >
                                <?php else: ?>
                                    <div class="cart-item__placeholder">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                            <polyline points="21 15 16 10 5 21"></polyline>
                                        </svg>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="cart-item__details">
                                <p class="cart-item__eyebrow">Curated Thrift Find</p>
                                <?php if ($category !== ''): ?>
                                    <p class="cart-item__category"><?= hopia_e(ucfirst(strtolower($category))) ?></p>
                                <?php endif; ?>
                                <h3 class="cart-item__name"><?= hopia_e($item['name']) ?></h3>
                                <p class="price cart-item__price">₱<?= number_format((float) $item['price'], 2) ?></p>
                                <?php if ($metaParts !== []): ?>
                                    <p class="cart-item__meta"><?= hopia_e(implode(' · ', $metaParts)) ?></p>
                                <?php endif; ?>
                                <p class="cart-item__unavailable" id="cart-availability-<?= (int) $item['cart_item_id'] ?>" <?= $isUnavailable ? '' : 'hidden' ?>>Currently unavailable. Unselect or remove to proceed.</p>
                                <form class="cart-item__actions" method="POST" action="cart-remove.php">
                                    <input type="hidden" name="cart_item_id" value="<?= (int) $item['cart_item_id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= hopia_e($csrfToken) ?>">
                                    <button type="submit" class="cart-item__remove">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                        </svg>
                                        Remove
                                    </button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <aside class="cart-summary" aria-labelledby="cart-summary-heading">
                <div class="cart-summary__inner">
                    <h2 id="cart-summary-heading" class="cart-summary__title">Order Summary</h2>

                    <div class="cart-summary__row">
                        <span class="cart-summary__row-label">Selected items</span>
                        <span class="cart-summary__row-value" id="cart-selected-count-summary"><?= count($selectedIds) ?> / <?= count($cartItems) ?></span>
                    </div>

                    <div class="cart-summary__row cart-summary__row--total">
                        <span class="cart-summary__row-label">Selected merchandise subtotal</span>
                        <span class="cart-summary__row-value price" id="cart-selected-subtotal" aria-live="polite">₱<?= number_format($selectionSummary['subtotal_cents'] / 100, 2) ?></span>
                    </div>

                    <div class="cart-summary__row cart-summary__row--muted">
                        <span class="cart-summary__row-label">Shipping</span>
                        <span class="cart-summary__row-value">To be confirmed</span>
                    </div>

                    <hr class="cart-summary__divider">

                    <p class="cart-select-message" id="cart-select-message" <?= $selectionSummary['message'] === '' ? 'hidden' : '' ?> role="status" aria-live="polite"><?= hopia_e($selectionSummary['message']) ?></p>
                    <p class="hint">Only selected merchandise will be ordered. Unselected items stay in your cart.</p>

                    <div class="cart-summary__actions">
                        <button type="submit" form="cart-selection-form" name="action" value="checkout" class="btn btn-primary cart-summary__checkout" id="cart-checkout-btn" aria-describedby="cart-select-message" <?= $selectionSummary['can_checkout'] ? '' : 'disabled' ?>>
                            <span class="cart-summary__btn-label">Proceed to Checkout</span>
                            <svg class="cart-summary__btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                <path d="M4.5 12h13"/>
                                <path d="m12.5 7 5 5-5 5"/>
                                <path d="m17.5 12-3.2 3.2"/>
                            </svg>
                        </button>
                        <a class="btn btn-secondary cart-summary__continue" href="products.php">
                            <svg class="cart-summary__btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                <path d="M6 8h12l1 12.5H5L6 8Z"/>
                                <path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>
                            </svg>
                            <span class="cart-summary__btn-label">Continue Shopping</span>
                        </a>
                    </div>
                </div>
            </aside>
        </div>

        <script>
        (function () {
            'use strict';
            var form = document.getElementById('cart-selection-form');
            var checkboxes = Array.from(document.querySelectorAll('.cart-item__checkbox'));
            var selectAllBtn = document.getElementById('cart-select-all');
            var deselectAllBtn = document.getElementById('cart-deselect-all');
            var saveBtn = document.getElementById('cart-save-selection');
            var errorEl = document.getElementById('cart-selection-error');
            var countEl = document.getElementById('cart-select-count');
            var countSummary = document.getElementById('cart-selected-count-summary');
            var subtotalEl = document.getElementById('cart-selected-subtotal');
            var msgEl = document.getElementById('cart-select-message');
            var checkoutBtn = document.getElementById('cart-checkout-btn');
            var saving = false;
            var saveFailed = false;
            var selectionCanCheckout = <?= json_encode($selectionSummary['can_checkout']) ?>;
            var invalidResponseMessage = 'The server returned an unexpected cart response. Reload the cart before checking out.';
            var busyControls = checkboxes.concat(Array.from(document.querySelectorAll('.cart-select-controls button, .cart-item__remove')));

            function fmtPeso(cents) {
                return '\u20B1' + (cents / 100).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
            function refreshUI() {
                var total = 0;
                var selected = 0;
                var unavailable = [];
                checkboxes.forEach(function (cb) {
                    if (cb.checked) {
                        selected += 1;
                        var item = cb.closest('.cart-item');
                        total += Number(item.dataset.priceCents);
                        if (item.dataset.unavailable === '1') {
                            unavailable.push(item.dataset.name);
                        }
                    }
                });
                subtotalEl.textContent = fmtPeso(total);
                countEl.textContent = saving ? 'Saving selection...' : selected + ' selected';
                countSummary.textContent = selected + ' / ' + checkboxes.length;
                msgEl.textContent = selected === 0 ? 'Select at least one item' : (unavailable.length
                    ? 'Selected items unavailable: ' + unavailable.join(', ') + '. Unselect or remove each affected item before proceeding.' : '');
                msgEl.hidden = msgEl.textContent === '';
                checkoutBtn.disabled = saving || saveFailed || !selectionCanCheckout || selected === 0 || unavailable.length > 0;
            }
            function applyState(payload) {
                if (!Array.isArray(payload.selected) || !Array.isArray(payload.items)
                    || !Number.isInteger(payload.revision) || payload.revision < 0
                    || !Number.isInteger(payload.cart_count) || payload.cart_count !== payload.items.length
                    || typeof payload.can_checkout !== 'boolean'
                    || payload.items.some(function (item) {
                        return !item || !Number.isInteger(item.id) || item.id < 1
                            || !Number.isInteger(item.price_cents) || typeof item.name !== 'string'
                            || typeof item.unavailable !== 'boolean';
                    })
                    || new Set(payload.items.map(function (item) { return item.id; })).size !== payload.items.length
                    || payload.selected.some(function (id) {
                        return !Number.isInteger(id) || !payload.items.some(function (item) { return item.id === id; });
                    })) {
                    throw new Error(invalidResponseMessage);
                }
                var displayedIds = checkboxes.map(function (cb) { return Number(cb.value); });
                if (payload.cart_count !== checkboxes.length || payload.items.some(function (item) { return displayedIds.indexOf(item.id) === -1; })) {
                    selectionCanCheckout = false;
                    window.location.reload();
                    return;
                }
                form.elements.selection_revision.value = payload.revision;
                checkboxes.forEach(function (cb) {
                    cb.checked = payload.selected.indexOf(Number(cb.value)) !== -1;
                    var state = payload.items.find(function (item) { return item.id === Number(cb.value); });
                    var article = cb.closest('.cart-item');
                    article.dataset.priceCents = state.price_cents;
                    article.dataset.unavailable = state.unavailable ? '1' : '0';
                    article.dataset.name = state.name;
                    article.classList.toggle('cart-item--unavailable', state.unavailable);
                    article.querySelector('.cart-item__price').textContent = fmtPeso(state.price_cents);
                    article.querySelector('.cart-item__unavailable').hidden = !state.unavailable;
                });
                selectionCanCheckout = payload.can_checkout;
            }
            function saveSelection(action, readOnly) {
                if (saving) {
                    return;
                }
                var data = new FormData(form);
                data.set('action', action);
                saving = true;
                saveFailed = false;
                errorEl.hidden = true;
                busyControls.forEach(function (control) { control.disabled = true; });
                refreshUI();
                // Buttons named "action" shadow the form.action URL property.
                fetch(form.getAttribute('action'), {
                    method: readOnly ? 'GET' : 'POST',
                    body: readOnly ? undefined : data,
                    credentials: 'same-origin',
                    cache: 'no-store',
                    keepalive: true,
                    headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
                }).then(function (response) {
                    if (response.redirected) {
                        throw new Error('Your cart request was redirected. Reload the cart and sign in again if needed.');
                    }
                    if ((response.headers.get('Content-Type') || '').toLowerCase().indexOf('application/json') === -1) {
                        throw new Error(invalidResponseMessage);
                    }
                    return response.json().catch(function () {
                        throw new Error(invalidResponseMessage);
                    }).then(function (payload) {
                        if (!payload || typeof payload.ok !== 'boolean') {
                            throw new Error(invalidResponseMessage);
                        }
                        if (!response.ok || !payload.ok) {
                            throw new Error(typeof payload.message === 'string' && payload.message
                                ? payload.message : 'We could not save your selection. Reload the cart and try again.');
                        }
                        return payload;
                    });
                }).then(function (payload) {
                    applyState(payload);
                }).catch(function (error) {
                    saveFailed = true;
                    errorEl.textContent = error instanceof TypeError
                        ? 'We could not update your cart. Retry Update Selection or reload the cart before checking out.'
                        : (error.message || 'We could not save your selection. Retry Update Selection before checking out.');
                    errorEl.hidden = false;
                }).finally(function () {
                    saving = false;
                    busyControls.forEach(function (control) { control.disabled = false; });
                    refreshUI();
                });
            }
            checkboxes.forEach(function (cb) {
                cb.addEventListener('change', function () { saveSelection('set'); });
            });
            [selectAllBtn, deselectAllBtn, saveBtn].forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    if (button !== saveBtn) {
                        checkboxes.forEach(function (cb) { cb.checked = button === selectAllBtn; });
                    }
                    saveSelection(button.value);
                });
            });
            form.addEventListener('submit', function (event) {
                if (checkoutBtn.disabled) {
                    event.preventDefault();
                }
            });
            window.addEventListener('pageshow', function (event) {
                if (event.persisted) {
                    saveSelection('', true);
                }
            });
            refreshUI();
        })();
        </script>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
