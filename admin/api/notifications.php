<?php

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function admin_notifications_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (empty($_SESSION['admin_id'])) {
    admin_notifications_respond(['ok' => false, 'message' => 'Your session has expired. Please sign in again.'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    admin_notifications_respond(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

if ($method === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION['admin_csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !is_string($stored) || $stored === '' || !hash_equals($stored, $token)) {
        admin_notifications_respond(['ok' => false, 'message' => 'Your form session has expired. Please reload the page.'], 403);
    }
    if (($_POST['action'] ?? '') !== 'open') {
        admin_notifications_respond(['ok' => false, 'message' => 'Invalid notification action.'], 400);
    }
}

$view = $_GET['view'] ?? 'count';
if (!is_string($view) || !in_array($view, ['count', 'latest'], true)) {
    admin_notifications_respond(['ok' => false, 'message' => 'Invalid notification view.'], 400);
}
session_write_close();

try {
    require_once __DIR__ . '/../../includes/database.php';
    require_once __DIR__ . '/../../includes/admin_notifications.php';
    if ($method === 'POST') {
        admin_notifications_open($pdo);
    }
    $result = ['ok' => true];
    if ($method === 'POST' || $view === 'latest') {
        $result['notifications'] = admin_notifications_latest($pdo);
    }
    // Count after the read transaction commits, not from its earlier snapshot.
    $result['unread_count'] = admin_notifications_count($pdo);
    $result['badge'] = admin_notifications_badge($result['unread_count']);
    admin_notifications_respond($result);
} catch (Throwable $e) {
    error_log('Admin notifications: ' . $e->getMessage());
    admin_notifications_respond(['ok' => false, 'message' => 'Notifications are temporarily unavailable. Please try again.'], 503);
}
