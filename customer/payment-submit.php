<?php

/*
 * Customer GCash submission and Phase 6 recovery endpoint.
 *
 * Receives the GCash reference (+ optional screenshot) POSTed from the GCash
 * panel on order-confirmation.php / order-detail.php and records it against
 * the order's EXISTING payments row via the shared, transactional
 * payment_gcash_submit() helper.
 *
 * Rules enforced server-side (never trusted from the browser):
 *   1. The customer must be authenticated.
 *   2. The request must be POST with a valid CSRF token.
 *   3. The order must belong to the authenticated customer.
 *   4. The payment must be GCASH, the order not cancelled, not already PAID.
 *   5. A reference is required; a screenshot is optional and validated.
 *   6. Submitting NEVER marks the payment PAID - it stays PENDING for admin
 *      verification.
 *
 * Uses POST/Redirect/Get: it redirects back to the originating order page
 * with a flag so a refresh never repeats the submission.
 */

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/gcash.php';
require_once __DIR__ . '/../includes/payment.php';

/*
 * Redirect helper. The return target is constrained to the two legitimate
 * customer order pages so a crafted value can never redirect elsewhere.
 */
function paymentSubmitRedirect(string $returnPage, int $orderId, bool $success, string $reason = '', string $successFlag = 'submitted'): never
{
    $page = $returnPage === 'order-confirmation.php' ? 'order-confirmation.php' : 'order-detail.php';

    if ($orderId <= 0) {
        header('Location: orders.php');
        exit;
    }

    $query = 'id=' . $orderId . ($success ? '&gcash=' . rawurlencode($successFlag) : '&gcash=error');
    if (!$success && $reason !== '') {
        $query .= '&gcash_reason=' . rawurlencode($reason);
    }

    header('Location: ' . $page . '?' . $query);
    exit;
}

/* Resolve the return target early so every failure path can use it. */
$returnPage = (($_POST['return'] ?? $_GET['return'] ?? '') === 'order-confirmation.php')
    ? 'order-confirmation.php'
    : 'order-detail.php';

/* Accept only POST; direct access never changes state. */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $directOrderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    paymentSubmitRedirect($returnPage, ($directOrderId && $directOrderId > 0) ? (int) $directOrderId : 0, false);
}

/* 1. Authentication. */
if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}

$customerId = (int) $_SESSION['customer_id'];

/* 2. CSRF (shared customer token convention). */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$filteredOrderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$orderId = ($filteredOrderId && $filteredOrderId > 0) ? (int) $filteredOrderId : 0;

$submittedToken = $_POST['csrf_token'] ?? '';
if (
    !is_string($submittedToken)
    || $submittedToken === ''
    || !hash_equals($_SESSION['csrf_token'], $submittedToken)
) {
    paymentSubmitRedirect($returnPage, $orderId, false, 'bad_request');
}

if ($orderId <= 0) {
    paymentSubmitRedirect($returnPage, 0, false, 'bad_request');
}

/* Every recovery form is tied to the payment version the customer saw. */
$revision = filter_input(INPUT_POST, 'payment_revision', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$action = $_POST['action'] ?? 'submit_gcash';
if ($revision === null || $revision === false || !is_string($action)
    || !in_array($action, ['submit_gcash', 'switch_to_cash'], true)) {
    paymentSubmitRedirect($returnPage, $orderId, false, 'bad_request');
}

if ($action === 'switch_to_cash') {
    $method = $_POST['payment_method'] ?? '';
    if (!is_string($method)) {
        paymentSubmitRedirect('order-detail.php', $orderId, false, 'bad_request');
    }
    $result = payment_gcash_switch_to_cash($pdo, $customerId, $orderId, $method, (int) $revision);
    paymentSubmitRedirect('order-detail.php', $orderId, !empty($result['ok']), (string) ($result['reason'] ?? ''), 'cash_changed');
}

/* 5/6. Record the submission (ownership, state, proof all enforced inside). */
$reference = $_POST['gcash_reference'] ?? '';
if (!is_string($reference)) {
    paymentSubmitRedirect($returnPage, $orderId, false, 'reference_invalid');
}
$proofFile = $_FILES['payment_proof'] ?? null;

$result = payment_gcash_submit($pdo, $customerId, $orderId, $reference, is_array($proofFile) ? $proofFile : null, (int) $revision);

if ($result['ok']) {
    paymentSubmitRedirect($returnPage, $orderId, true, '', !empty($result['resubmitted']) ? 'resubmitted' : 'submitted');
}

paymentSubmitRedirect($returnPage, $orderId, false, (string) ($result['reason'] ?? 'error'));
