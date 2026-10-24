<?php

/*
 * Customer payment-proof streaming endpoint (Phase 2).
 *
 * Streams the GCash screenshot for ONE order, but ONLY to the authenticated
 * customer who owns that order. Proof files live in the private storage/
 * directory (never under the public image flow) and are reachable only
 * through this gate; the stored path is never exposed and is containment-
 * checked before any byte is read. Any problem returns a generic 404 so
 * order existence/ownership is never revealed.
 */

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/payment.php';

function paymentProofNotFound(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

if (!isset($_SESSION['customer_id'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden.';
    exit;
}

$customerId = (int) $_SESSION['customer_id'];

$orderId = filter_input(INPUT_GET, 'order', FILTER_VALIDATE_INT);
if ($orderId === false || $orderId === null || $orderId <= 0) {
    paymentProofNotFound();
}

/* Ownership gate: the order must belong to this customer. */
$stmt = $pdo->prepare(
    'SELECT p.proof_path
     FROM payments p
     INNER JOIN orders o ON o.id = p.order_id
     WHERE p.order_id = :order_id
       AND o.customer_id = :customer_id
     LIMIT 1'
);
$stmt->execute([':order_id' => (int) $orderId, ':customer_id' => $customerId]);
$row = $stmt->fetch();

if ($row === false) {
    paymentProofNotFound();
}

$absolute = payment_proof_abspath($row['proof_path'] ?? null);
if ($absolute === null || !is_file($absolute)) {
    paymentProofNotFound();
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($absolute);
$allowed = ['image/jpeg', 'image/png', 'image/webp'];
if (!is_string($mime) || !in_array($mime, $allowed, true)) {
    paymentProofNotFound();
}

/* Private, authenticated delivery: never cached by shared caches. */
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($absolute));
header('Content-Disposition: inline; filename="payment-proof-' . (int) $orderId . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');

readfile($absolute);
exit;
