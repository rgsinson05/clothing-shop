<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';

$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$productId) {
    header('Location: products.php');
    exit;
}

/*
 * CSRF token validation.
 * Uses timing-safe comparison to prevent timing attacks.
 */

    $csrfToken = filter_input(INPUT_POST, 'csrf_token', FILTER_DEFAULT);
    $storedToken = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($csrfToken)
        || $csrfToken === ''
        || !is_string($storedToken)
        || $storedToken === ''
        || !hash_equals($storedToken, $csrfToken)
    ) {
        unset($_SESSION['csrf_token']);
        header('Location: products.php');
        exit;
    }

try {

    $pdo->beginTransaction();

    /*
     * Fetch the product and lock it for update.
     * Only bundle-linked products can be retired through this handler.
     * Legacy unbundled products are handled by product-delete.php.
     */
    $stmt = $pdo->prepare(
        'SELECT id, name, bundle_id, status, retired_at
         FROM products
         WHERE id = :id
         FOR UPDATE'
    );

    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch();

    if (!$product) {
        $pdo->rollBack();
        header('Location: products.php?retire_error=not_found');
        exit;
    }

    /*
     * Reject products that are not bundle-linked.
     * Unbundled legacy products are hard-deleted via product-delete.php.
     */
    if ((int) $product['bundle_id'] === 0 || $product['bundle_id'] === null) {
        $pdo->rollBack();
        header('Location: products.php?retire_error=not_bundled');
        exit;
    }

    /*
     * Reject already-retired items.
     */
    if (!empty($product['retired_at'])) {
        $pdo->rollBack();
        header('Location: products.php?retire_error=already_retired');
        exit;
    }

    /*
     * Only AVAILABLE items may be retired.
     * A SOLD item belongs to an order and must not be retired.
     */
    if ($product['status'] !== 'AVAILABLE') {
        $pdo->rollBack();
        header('Location: products.php?retire_error=not_available');
        exit;
    }

    /*
     * Perform the retirement.
     * retired_at is set to the current timestamp; no other field is changed.
     * The product row, barcode, bundle assignment, and all images are preserved.
     */
    $updateStmt = $pdo->prepare(
        'UPDATE products
         SET retired_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );

    $updateStmt->execute([':id' => $productId]);

    $pdo->commit();

    header('Location: products.php?retired=1');
    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    header('Location: products.php?retire_error=1');
    exit;
}
