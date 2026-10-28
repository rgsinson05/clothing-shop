<?php

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bundle_barcode_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (empty($_SESSION['admin_id'])) {
    bundle_barcode_respond([
        'ok' => false,
        'reason' => 'auth',
        'message' => 'Your session has expired. Please sign in again.',
    ], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    bundle_barcode_respond([
        'ok' => false,
        'reason' => 'method',
        'message' => 'Method not allowed.',
    ], 405);
}

$submittedToken = $_POST['csrf_token'] ?? '';
$storedToken = $_SESSION['bundle_barcode_csrf'] ?? '';

if (
    !is_string($submittedToken)
    || $submittedToken === ''
    || !is_string($storedToken)
    || $storedToken === ''
    || !hash_equals($storedToken, $submittedToken)
) {
    bundle_barcode_respond([
        'ok' => false,
        'reason' => 'csrf',
        'message' => 'Your form session has expired. Please reload the page.',
    ], 403);
}

$submittedBarcode = $_POST['barcode'] ?? '';
$barcode = is_string($submittedBarcode) ? trim($submittedBarcode) : '';

if ($barcode === '') {
    bundle_barcode_respond([
        'ok' => false,
        'reason' => 'invalid',
        'message' => 'Enter or scan a barcode to identify an item.',
    ], 422);
}

if (strlen($barcode) > 64) {
    bundle_barcode_respond([
        'ok' => false,
        'reason' => 'invalid',
        'message' => 'The barcode is invalid.',
    ], 422);
}

require_once __DIR__ . '/../../includes/database.php';

try {
    $stmt = $pdo->prepare(
        'SELECT p.id,
                p.name,
                p.description,
                p.category,
                p.status,
                p.retired_at,
                p.bundle_id,
                p.bundle_item_number,
                p.barcode_value,
                pb.id AS registered_bundle_id,
                pb.category AS bundle_category,
                pb.capital_total,
                pb.original_quantity,
                pb.purchase_date
         FROM products p
         LEFT JOIN purchase_bundles pb ON pb.id = p.bundle_id
         WHERE p.barcode_value = :barcode
         LIMIT 1'
    );
    $stmt->execute([':barcode' => $barcode]);
    $row = $stmt->fetch();

    if ($row === false) {
        bundle_barcode_respond([
            'ok' => false,
            'reason' => 'not_found',
            'message' => 'No registered item matches that barcode.',
        ], 404);
    }

    $product = [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'description' => $row['description'] !== null ? (string) $row['description'] : '',
        'category' => (string) $row['category'],
        'status' => (string) $row['status'],
        'retired' => $row['retired_at'] !== null,
        'barcode' => (string) $row['barcode_value'],
        'bundle_item_number' => $row['bundle_item_number'] !== null
            ? (int) $row['bundle_item_number']
            : null,
    ];

    if ($row['bundle_id'] === null || $row['registered_bundle_id'] === null) {
        bundle_barcode_respond([
            'ok' => false,
            'found' => true,
            'reason' => 'no_bundle',
            'message' => 'Item found, but it does not have an assigned bundle.',
            'product' => $product,
            'bundle' => null,
        ]);
    }

    $bundleId = (int) $row['registered_bundle_id'];
    $bundleCode = 'HOPEBUNDLE-' . str_pad((string) $bundleId, 6, '0', STR_PAD_LEFT);

    bundle_barcode_respond([
        'ok' => true,
        'message' => 'Item identified successfully. Bundle: ' . $bundleCode,
        'product' => $product,
        'bundle' => [
            'id' => $bundleId,
            'code' => $bundleCode,
            'category' => (string) $row['bundle_category'],
            'capital' => number_format((float) $row['capital_total'], 2, '.', ''),
            'original_quantity' => (int) $row['original_quantity'],
            'purchase_date' => $row['purchase_date'] !== null ? (string) $row['purchase_date'] : '',
        ],
    ]);
} catch (Throwable $e) {
    bundle_barcode_respond([
        'ok' => false,
        'reason' => 'error',
        'message' => 'The barcode lookup could not be completed. Please try again.',
    ], 500);
}
