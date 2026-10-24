<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function delivery_respond(array $result): void
{
    http_response_code($result['status'] ?? 200);
    unset($result['status']);
    echo json_encode($result);
    exit;
}

if (empty($_SESSION['admin_id'])) delivery_respond(['ok' => false, 'reason' => 'auth', 'status' => 401]);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') delivery_respond(['ok' => false, 'reason' => 'method', 'status' => 405]);
$token = $_POST['csrf_token'] ?? null;
$stored = $_SESSION['admin_csrf_token'] ?? null;
if (!is_string($token) || !is_string($stored) || $stored === '' || !hash_equals($stored, $token)) {
    delivery_respond(['ok' => false, 'reason' => 'csrf', 'status' => 403]);
}
require_once __DIR__ . '/../../includes/delivery.php';
$ints = [];
foreach (['order_id' => 1, 'cycle' => 1, 'revision' => 0] as $field => $minimum) {
    $value = filter_var($_POST[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
    if ($value === false || !isset($_POST[$field])) delivery_respond(delivery_failure('bad_request', 422));
    $ints[$field] = $value;
}
foreach (['action', 'stage', 'action_key'] as $field) {
    if (!is_string($_POST[$field] ?? null)) delivery_respond(delivery_failure('bad_request', 422));
}
if (!in_array($_POST['action'], delivery_action_names(), true)) delivery_respond(delivery_failure('bad_request', 422));
$attempt = isset($_POST['attempt']) ? filter_var($_POST['attempt'], FILTER_VALIDATE_INT) : null;
$item = isset($_POST['order_item_id']) ? filter_var($_POST['order_item_id'], FILTER_VALIDATE_INT) : null;
if ($attempt === false || $item === false || !is_string($_POST['reason'] ?? '')
    || (isset($_POST['outcome']) && !is_string($_POST['outcome']))) delivery_respond(delivery_failure('bad_request', 422));
if ($_POST['action'] === 'inspect_return' && ($_POST['outcome'] ?? null) !== 'MANUAL_REVIEW') {
    delivery_respond(delivery_failure('bad_request', 422));
}
$adminId = (int) $_SESSION['admin_id'];
session_write_close();
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/delivery_schema.php';
$schema = delivery_schema_status($pdo);
if ($schema['state'] !== 'ready') {
    delivery_respond(['ok' => false, 'reason' => 'schema_unavailable', 'status' => 503,
        'message' => $schema['message']]);
}
$result = delivery_action($pdo, $adminId, $ints['order_id'], $_POST['action'],
    $ints['cycle'], $ints['revision'], $_POST['stage'], $_POST['action_key'], $attempt,
    $_POST['reason'] ?? '', $item, $_POST['outcome'] ?? null);
if (!empty($result['ok'])) {
    $result['message'] = !empty($result['idempotent'])
        ? 'This exact action was already recorded. No action was repeated; the latest order state is shown.'
        : ($_POST['action'] === 'make_available'
            ? 'The audited stock release was recorded. Payment, customer receipt, courier status and sale state were not changed.'
            : 'The delivery action was recorded. Review the updated shipment and history below. Payment, customer receipt and inventory were not changed.');
    // Use the existing one-use, admin/order-bound feedback after confirmed success.
    session_start();
    if ((int) ($_SESSION['admin_id'] ?? 0) === $adminId) {
        require_once __DIR__ . '/../../includes/admin-feedback.php';
        $result['redirect'] = hopia_admin_success_redirect('order-view.php?id=' . $ints['order_id'], $result['message']);
    }
    session_write_close();
}
delivery_respond($result);
