<?php

/*
 * Hopia Fits - Legacy product bundle assignment (shared backend logic).
 *
 * Assigns an EXISTING, fully unassigned product row (bundle_id,
 * bundle_item_number, and barcode_value all NULL) to one REAL purchase
 * bundle chosen explicitly by the admin. The bundle is never guessed
 * from category or price: two purchase batches can share a category
 * and capital yet remain separate bundles.
 *
 * Guarantees provided here (all inside ONE database transaction):
 *   * The product is re-read under its own row lock and all three
 *     assignment fields are re-verified NULL immediately before the
 *     update, so concurrent or duplicate assignments are rejected.
 *   * The bundle row is locked with SELECT ... FOR UPDATE, so
 *     concurrent registrations cannot allocate the same
 *     bundle_item_number (same convention as admin/product-create.php).
 *   * Product category must exactly match the bundle category.
 *   * The registered item count may not exceed original_quantity.
 *   * The next bundle_item_number is COALESCE(MAX(bundle_item_number),0)+1
 *     within the locked bundle, and the resulting barcode
 *     (HOPEBUNDLE-<6-digit bundle id>-<2-digit item number>, minimum
 *     paddings only) is protected by the uq_products_barcode and
 *     uq_products_bundle_item unique constraints as a final backstop.
 *   * The update touches ONLY the three bundle-assignment columns in a
 *     single atomic UPDATE guarded by all-three-NULL in its WHERE clause.
 *     The product's name, price, images, description, defects, status
 *     (SOLD/AVAILABLE), and retirement state are never modified, and no
 *     sale, order, or verification record is ever created.
 *
 * Validation problems are reported by throwing RuntimeException with a
 * clear, admin-facing message; the caller decides how to display them.
 */

/**
 * Assign one legacy (fully unassigned) product to an existing purchase
 * bundle, allocating its permanent bundle_item_number and barcode.
 *
 * @return array{
 *   product_id: int,
 *   bundle_id: int,
 *   bundle_item_number: int,
 *   barcode_value: string
 * }
 *
 * @throws RuntimeException with an admin-facing message when any
 *         precondition fails. The transaction is always rolled back
 *         before throwing.
 */
function assignLegacyProductToBundle(PDO $pdo, int $productId, int $bundleId): array
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Assignment must start outside an active transaction.');
    }

    $pdo->beginTransaction();

    try {

        /*
         * 1. Lock the product row and confirm it still exists and is
         *    still fully unassigned (all three fields NULL).
         */
        $productStmt = $pdo->prepare(
            'SELECT id, name, category, status, retired_at,
                    bundle_id, bundle_item_number, barcode_value
             FROM products
             WHERE id = :id
             FOR UPDATE'
        );
        $productStmt->execute([':id' => $productId]);
        $product = $productStmt->fetch();

        if (!$product) {
            throw new RuntimeException('Product not found. It may have been deleted.');
        }

        if (
            $product['bundle_id'] !== null
            || $product['bundle_item_number'] !== null
            || $product['barcode_value'] !== null
        ) {
            throw new RuntimeException(
                'This product is already assigned to a bundle (all-or-none rule: it already carries bundle, item number, and barcode).'
            );
        }

        /*
         * 2. Lock the selected bundle row and confirm it still exists.
         *    The FOR UPDATE lock serializes item-number allocation for
         *    this bundle exactly as in admin/product-create.php.
         */
        $bundleStmt = $pdo->prepare(
            'SELECT id, category, original_quantity
             FROM purchase_bundles
             WHERE id = :id
             FOR UPDATE'
        );
        $bundleStmt->execute([':id' => $bundleId]);
        $bundle = $bundleStmt->fetch();

        if (!$bundle) {
            throw new RuntimeException('Selected purchase bundle not found.');
        }

        /*
         * 3. The product category must match the bundle category exactly.
         */
        if ($product['category'] !== $bundle['category']) {
            throw new RuntimeException(
                'Category mismatch: the product is '
                . $product['category']
                . ' but bundle HOPEBUNDLE-'
                . str_pad((string) $bundleId, 6, '0', STR_PAD_LEFT)
                . ' is '
                . $bundle['category']
                . '.'
            );
        }

        /*
         * 4. Count products already assigned to this bundle and reject
         *    if the purchase-time capacity is reached.
         */
        $countStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM products
             WHERE bundle_id = :bundle_id'
        );
        $countStmt->execute([':bundle_id' => $bundleId]);
        $registeredCount = (int) $countStmt->fetchColumn();

        if ($registeredCount >= (int) $bundle['original_quantity']) {
            throw new RuntimeException(
                'This bundle is already at full capacity ('
                . (int) $bundle['original_quantity']
                . ' items registered).'
            );
        }

        /*
         * 5. Allocate the next bundle_item_number. MAX + 1 is safe
         *    because the bundle row is locked above.
         */
        $seqStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(bundle_item_number), 0) + 1
             FROM products
             WHERE bundle_id = :bundle_id'
        );
        $seqStmt->execute([':bundle_id' => $bundleId]);
        $nextItemNumber = (int) $seqStmt->fetchColumn();

        /*
         * 6. Generate the barcode (minimum paddings, per migration 005).
         */
        $barcodeValue = 'HOPEBUNDLE-'
            . str_pad((string) $bundleId, 6, '0', STR_PAD_LEFT)
            . '-'
            . str_pad((string) $nextItemNumber, 2, '0', STR_PAD_LEFT);

        /*
         * 7. Atomic, all-or-none update. The WHERE clause repeats the
         *    all-three-NULL condition so a concurrent assignment that
         *    slipped past the read makes this UPDATE match zero rows
         *    instead of overwriting an issued barcode.
         */
        $updateStmt = $pdo->prepare(
            'UPDATE products
             SET bundle_id = :bundle_id,
                 bundle_item_number = :bundle_item_number,
                 barcode_value = :barcode_value
             WHERE id = :id
               AND bundle_id IS NULL
               AND bundle_item_number IS NULL
               AND barcode_value IS NULL'
        );
        $updateStmt->execute([
            ':bundle_id' => $bundleId,
            ':bundle_item_number' => $nextItemNumber,
            ':barcode_value' => $barcodeValue,
            ':id' => $productId,
        ]);

        if ($updateStmt->rowCount() !== 1) {
            throw new RuntimeException(
                'This product was just assigned by another request. Assignment aborted.'
            );
        }

        $pdo->commit();

        return [
            'product_id' => $productId,
            'bundle_id' => $bundleId,
            'bundle_item_number' => $nextItemNumber,
            'barcode_value' => $barcodeValue,
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof PDOException) {
            /*
             * Unique-constraint violations (uq_products_barcode or
             * uq_products_bundle_item) surface here as a last-resort
             * guarantee: the database rejects any duplicate barcode or
             * (bundle, item number) pair, and the transaction is rolled
             * back so nothing is half-assigned.
             */
            throw new RuntimeException(
                'The database rejected this assignment because the bundle item number or barcode would not be unique. No changes were made. ('
                . $e->getMessage()
                . ')'
            );
        }

        throw $e;
    }
}
