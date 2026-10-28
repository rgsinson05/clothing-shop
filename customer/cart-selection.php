<?php

session_start();

$wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
    || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
header('Cache-Control: no-store');

function cartSelectionRespond(array $payload, int $status = 200): void
{
    global $wantsJson;
    if ($wantsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code($status);
        echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    } else {
        if (!$payload['ok']) {
            $_SESSION['cart_selection_error'] = $payload['message'];
        }
        header('Location: cart.php', true, 303);
    }
    exit;
}

if (!isset($_SESSION['customer_id'])) {
    if (!$wantsJson) {
        header('Location: login.php', true, 303);
        exit;
    }
    cartSelectionRespond(['ok' => false, 'message' => 'Please sign in again.', 'redirect' => 'login.php'], 401);
}

$requestMethod = $_SERVER['REQUEST_METHOD'];
if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    cartSelectionRespond(['ok' => false, 'message' => 'Invalid cart selection request.'], 405);
}

if ($requestMethod === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        cartSelectionRespond(['ok' => false, 'message' => 'Your session expired. Reload the cart before continuing.'], 403);
    }
}

try {
    require_once __DIR__ . '/../includes/database.php';
    require_once __DIR__ . '/../includes/cart_selection.php';

    $cartStmt = $pdo->prepare('SELECT id FROM carts WHERE customer_id = ? LIMIT 1');
    $cartStmt->execute([(int) $_SESSION['customer_id']]);
    $cartId = (int) $cartStmt->fetchColumn();
    $itemsStmt = $pdo->prepare(
        'SELECT ci.id AS cart_item_id, p.id AS product_id, p.name, p.price, p.status, p.retired_at
         FROM cart_items ci INNER JOIN products p ON p.id = ci.product_id
         WHERE ci.cart_id = ? ORDER BY ci.id'
    );
    $itemsStmt->execute([$cartId]);
    $items = $itemsStmt->fetchAll();
    cart_selection_prune($pdo, $cartId);

    if ($requestMethod === 'POST') {
        $action = $_POST['action'] ?? '';
        $revisionValue = $_POST['selection_revision'] ?? null;
        $revision = is_string($revisionValue) ? filter_var($revisionValue, FILTER_VALIDATE_INT) : false;
        if ($revision === false || $revision < 0 || $revision !== cart_selection_revision($cartId)) {
            cartSelectionRespond(['ok' => false, 'message' => 'Your cart selection changed in another tab. Reload the cart before checking out.'], 409);
        }

        $cartIds = array_map('intval', array_column($items, 'cart_item_id'));
        if ($action === 'select_all') {
            // Availability never filters Select All.
            $selectedIds = $cartIds;
        } elseif ($action === 'deselect_all') {
            $selectedIds = [];
        } elseif (in_array($action, ['set', 'checkout'], true)) {
            $values = $_POST['selected'] ?? [];
            $selectedIds = is_array($values) ? cart_selection_ids($values) : null;
            if ($selectedIds === null || array_diff($selectedIds, $cartIds) !== []) {
                cartSelectionRespond(['ok' => false, 'message' => 'The selected cart items could not be found. Reload your cart.'], 422);
            }
        } else {
            cartSelectionRespond(['ok' => false, 'message' => 'Invalid cart selection request.'], 400);
        }
        cart_selection_set($cartId, $selectedIds);
    }

    $selectedIds = cart_selection_get($cartId);
    $summary = cart_selection_summary($items, $selectedIds);
    if ($requestMethod === 'POST' && $action === 'checkout') {
        if (!$summary['can_checkout']) {
            cartSelectionRespond(['ok' => false, 'message' => $summary['message']], 422);
        }
        if (!$wantsJson) {
            header('Location: checkout.php', true, 303);
            exit;
        }
    }
    cartSelectionRespond(array_merge($summary, [
        'ok' => true,
        'selected' => $selectedIds,
        'revision' => cart_selection_revision($cartId),
        'cart_count' => count($items),
        'redirect' => $requestMethod === 'POST' && $action === 'checkout' ? 'checkout.php' : null,
        'items' => array_map(static function (array $item): array {
            return [
                'id' => (int) $item['cart_item_id'],
                'name' => $item['name'] . ' (#' . (int) $item['product_id'] . ')',
                'price_cents' => (int) round((float) $item['price'] * 100),
                'unavailable' => $item['status'] !== 'AVAILABLE' || $item['retired_at'] !== null,
            ];
        }, $items),
    ]));
} catch (Throwable $e) {
    cartSelectionRespond(['ok' => false, 'message' => 'We could not save your selection. Please retry before checking out.'], 500);
}
