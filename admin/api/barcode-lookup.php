<?php

/*
 * Hopia Fits - Phase A walk-in barcode lookup (PREVIEW ONLY).
 *
 * Thin HTTP wrapper: validates the admin session and the walk-in CSRF token,
 * then delegates to sales_lookup_barcode() in includes/sales.php. It performs
 * NO writes: scanning or typing a barcode never reserves a product, marks
 * anything SOLD, creates a sale, or locks a bundle. Eligibility returned here
 * is a PREVIEW only and is authoritatively re-checked under row locks in
 * admin/api/walkin-sale.php at confirmation.
 */

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function barcode_lookup_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

/* Authenticated admin session is required. */
if (empty($_SESSION['admin_id'])) {
    barcode_lookup_respond(['ok' => false, 'reason' => 'auth', 'message' => 'Your session has expired. Please sign in again.'], 401);
}

/* POST only. */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    barcode_lookup_respond(['ok' => false, 'reason' => 'method', 'message' => 'Method not allowed.'], 405);
}

/*
 * CSRF validation (walk-in token). Reject a missing, empty, or non-string
 * token BEFORE hash_equals(). The token is NOT rotated or unset here: lookups
 * happen many times per draft and change no state, so a stable per-session
 * token is correct and keeps the scan page working across repeated scans.
 */
$submittedToken = $_POST['csrf_token'] ?? '';
$storedToken = $_SESSION['walkin_csrf'] ?? '';

if (
    !is_string($submittedToken)
    || $submittedToken === ''
    || !is_string($storedToken)
    || $storedToken === ''
    || !hash_equals($storedToken, $submittedToken)
) {
    barcode_lookup_respond(['ok' => false, 'reason' => 'csrf', 'message' => 'Your form session has expired. Please reload the scan page.'], 403);
}

require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/sales.php';

$barcode = $_POST['barcode'] ?? '';
$draft = $_POST['draft'] ?? [];

try {
    $result = sales_lookup_barcode(
        $pdo,
        is_string($barcode) ? $barcode : '',
        is_array($draft) ? $draft : []
    );
    barcode_lookup_respond($result);
} catch (Throwable $e) {
    barcode_lookup_respond(['ok' => false, 'reason' => 'error', 'message' => 'The lookup could not be completed. Please try again.'], 500);
}
