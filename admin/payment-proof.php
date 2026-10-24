<?php

/*
 * Admin payment-proof streaming endpoint (Phase 2).
 *
 * Streams the GCash screenshot for ONE order to an authenticated admin so the
 * submission can be compared against the real GCash transaction. Proof files
 * live in the private storage/ directory (never under the public image flow)
 * and are reachable only through this gate; the stored path is never exposed
 * and is containment-checked before any byte is read. Any problem returns a
 * generic 404.
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden.';
    exit;
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/payment.php';

function adminPaymentProofNotFound(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

$orderId = filter_input(INPUT_GET, 'order', FILTER_VALIDATE_INT);
if ($orderId === false || $orderId === null || $orderId <= 0) {
    adminPaymentProofNotFound();
}

$stmt = $pdo->prepare('SELECT proof_path FROM payments WHERE order_id = :order_id LIMIT 1');
$stmt->execute([':order_id' => (int) $orderId]);
$row = $stmt->fetch();

if ($row === false) {
    adminPaymentProofNotFound();
}

$absolute = payment_proof_abspath($row['proof_path'] ?? null);
if ($absolute === null || !is_file($absolute)) {
    adminPaymentProofNotFound();
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($absolute);
$allowed = ['image/jpeg', 'image/png', 'image/webp'];
if (!is_string($mime) || !in_array($mime, $allowed, true)) {
    adminPaymentProofNotFound();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($absolute));
header('Content-Disposition: inline; filename="payment-proof-' . (int) $orderId . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');

readfile($absolute);
exit;
