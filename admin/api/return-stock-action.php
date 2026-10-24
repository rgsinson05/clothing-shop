<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function return_stock_respond(array $result): void
{
    http_response_code($result['status'] ?? 200);
    unset($result['status']);
    echo json_encode($result);
    exit;
}

if (empty($_SESSION['admin_id'])) return_stock_respond(['ok' => false, 'reason' => 'auth', 'status' => 401]);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return_stock_respond(['ok' => false, 'reason' => 'method', 'status' => 405]);
$token = $_POST['csrf_token'] ?? null;
$stored = $_SESSION['admin_csrf_token'] ?? null;
if (!is_string($token) || !is_string($stored) || $stored === '' || !hash_equals($stored, $token)) return_stock_respond(['ok' => false, 'reason' => 'csrf', 'status' => 403]);
require_once __DIR__ . '/../../includes/return_stock.php';
$ints = [];
foreach (['order_id' => 1, 'cycle' => 1, 'revision' => 1, 'order_item_id' => 1] as $field => $minimum) {
    $value = filter_var($_POST[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
    if ($value === false || !isset($_POST[$field])) return_stock_respond(return_stock_failure('bad_request', 422));
    $ints[$field] = $value;
}
foreach (['action','stage','action_key','reason'] as $field) {
    if (!is_string($_POST[$field] ?? null)) return_stock_respond(return_stock_failure('bad_request', 422));
}
if (!in_array($_POST['action'], ['inspect_stock','resolve_hold','make_available'], true)) return_stock_respond(return_stock_failure('bad_request', 422));
if (isset($_POST['outcome']) && !is_string($_POST['outcome'])) return_stock_respond(return_stock_failure('bad_request', 422));
$adminId = filter_var($_SESSION['admin_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($adminId === false) return_stock_respond(return_stock_failure('admin_required', 403));
session_write_close();
try {
    require_once __DIR__ . '/../../includes/database.php';
    // Preserve the existing courier gate; the new ledger gate stays narrow.
    $courierSchema = delivery_schema_status($pdo);
    if ($courierSchema['state'] !== 'ready') {
        $result = return_stock_failure('schema_unavailable', 503);
        $result['message'] = $courierSchema['message'];
    } else {
        // No user-controlled validationOnly or admin identity is forwarded.
        $result = return_stock_action($pdo, $adminId, $ints['order_id'], $_POST['action'], $ints['cycle'], $ints['revision'],
            $_POST['stage'], $_POST['action_key'], $ints['order_item_id'], $_POST['reason'], $_POST['outcome'] ?? null);
    }
} catch (Throwable $error) {
    $result = return_stock_failure('error', 500);
}
if (!empty($result['ok'])) {
    $messages = [
        'inspect_stock' => 'The immutable stock inspection was recorded. Inventory and payment status are unchanged.',
        'resolve_hold' => 'This item reservation was irreversibly abandoned. The item is still unavailable; financial resolution is separate, and shipping fees and payment status are untouched.',
        'make_available' => 'The audited stock disposition was recorded and the inspected garment is now available. Order, cancellation, payment and shipping fees are unchanged.',
    ];
    $result['message'] = !empty($result['idempotent']) ? 'This exact action was already recorded. The original result is returned; no inventory mutation was repeated.' : $messages[$_POST['action']];
    session_start();
    if ((int) ($_SESSION['admin_id'] ?? 0) === $adminId) {
        require_once __DIR__ . '/../../includes/admin-feedback.php';
        $result['redirect'] = hopia_admin_success_redirect('order-view.php?id=' . $ints['order_id'], $result['message']);
    }
    session_write_close();
}
return_stock_respond($result);
