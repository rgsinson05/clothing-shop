<?php

/*
 * Hopia Fits - customer notification API endpoint (Phase 14 backend).
 *
 * Provides the authenticated customer-facing retrieval and read-state
 * management that Phase 15 (bell, badge, dropdown, history UI) will call.
 * Only the backend is implemented here; no bell, badge, dropdown, history
 * page, toast, or live polling is rendered by this endpoint.
 *
 * Security (enforced server-side, never trusted from the browser):
 *   - The customer identity is taken from the authenticated session
 *     ($_SESSION['customer_id']), never from a request parameter.
 *   - Every query is scoped by the authenticated customer id, so a
 *     customer can only read or mark their OWN notifications. Guessing or
 *     modifying a notification id never reaches another customer's rows.
 *   - State-changing requests require the customer CSRF token.
 *   - Only the customer-safe notification fields are returned; no
 *     payment, GCash reference, proof path, internal note, or other
 *     sensitive order data is exposed.
 *
 * Endpoints:
 *   GET  view=count   -> { unread_count }
 *   GET  view=latest  -> { unread_count, notifications (<= limit, newest
 *                          first), has_more }
 *   GET  view=history -> { unread_count, notifications (paginated, newest
 *                          first), has_more }  (bounded page size)
 *   POST action=read  -> mark one notification read (idempotent, ownership
 *                        enforced in the UPDATE)
 *   POST action=read_all -> mark all of the customer's notifications read
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function customer_notifications_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (empty($_SESSION['customer_id'])) {
    customer_notifications_respond(['ok' => false, 'message' => 'Your session has expired. Please sign in again.'], 401);
}

$customerId = (int) $_SESSION['customer_id'];

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    customer_notifications_respond(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

if ($method === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !is_string($stored) || $stored === '' || !hash_equals($stored, $token)) {
        customer_notifications_respond(['ok' => false, 'message' => 'Your form session has expired. Please reload the page.'], 403);
    }
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['read', 'read_all'], true)) {
        customer_notifications_respond(['ok' => false, 'message' => 'Invalid notification action.'], 400);
    }
}

$view = $_GET['view'] ?? 'count';
if (!is_string($view) || !in_array($view, ['count', 'latest', 'history'], true)) {
    customer_notifications_respond(['ok' => false, 'message' => 'Invalid notification view.'], 400);
}

/* Bounded, stable pagination for the history view. */
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
if ($limit === false || $limit === null) {
    $limit = 10;
}
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($page === false || $page === null) {
    $page = 0;
}
$offset = (int) $page * (int) $limit;

session_write_close();

try {
    require_once __DIR__ . '/../../includes/database.php';
    require_once __DIR__ . '/../../includes/customer_notifications.php';

    $result = ['ok' => true];

    if ($method === 'POST') {
        $action = $_POST['action'];
        $notificationId = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($action === 'read') {
            if ($notificationId === false || $notificationId === null) {
                customer_notifications_respond(['ok' => false, 'message' => 'Invalid notification.'], 400);
            }
            /* Ownership is enforced in the UPDATE; a wrong/foreign id is a safe no-op. */
            customer_notifications_mark_read($pdo, $customerId, (int) $notificationId);
        } else {
            customer_notifications_mark_all_read($pdo, $customerId);
        }
        $result['unread_count'] = customer_notifications_unread_count($pdo, $customerId);
        customer_notifications_respond($result);
    }

    if ($view === 'count') {
        $result['unread_count'] = customer_notifications_unread_count($pdo, $customerId);
    } elseif ($view === 'latest') {
        $pageData = customer_notifications_latest($pdo, $customerId, $limit);
        $result['notifications'] = $pageData['notifications'];
        $result['has_more'] = $pageData['has_more'];
        $result['unread_count'] = customer_notifications_unread_count($pdo, $customerId);
    } else {
        $pageData = customer_notifications_latest($pdo, $customerId, $limit, $offset);
        $result['notifications'] = $pageData['notifications'];
        $result['has_more'] = $pageData['has_more'];
        $result['unread_count'] = customer_notifications_unread_count($pdo, $customerId);
    }

    customer_notifications_respond($result);
} catch (Throwable $e) {
    error_log('Customer notifications: ' . $e->getMessage());
    customer_notifications_respond(['ok' => false, 'message' => 'Notifications are temporarily unavailable. Please try again.'], 503);
}
