<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/inventory_guard.php';

$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$productId) {
    header('Location: products.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Get product
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT id, name, bundle_id
     FROM products
     WHERE id = ?'
);

$stmt->execute([$productId]);

$product = $stmt->fetch();

if (!$product) {
    header('Location: products.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Refuse deletion of bundle-linked products.
|--------------------------------------------------------------------------
| Bundle-linked products carry permanently issued barcodes and must not be
| hard-deleted.  They are retired via product-retire.php instead.
| Only unbundled (legacy) products can be hard-deleted through this handler.
*/

if (!empty($product['bundle_id'])) {
    header('Location: products.php?delete_error=bundled');
    exit;
}

/*
|--------------------------------------------------------------------------
| Get product images
|--------------------------------------------------------------------------
*/

$imageStmt = $pdo->prepare(
    'SELECT image_path
     FROM product_images
     WHERE product_id = ?'
);

$imageStmt->execute([$productId]);

$images = $imageStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Delete product
|--------------------------------------------------------------------------
*/

try {

    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();

    $freshStmt = $pdo->prepare('SELECT bundle_id FROM products WHERE id = ? FOR UPDATE');
    $freshStmt->execute([$productId]);
    $freshProduct = $freshStmt->fetch();
    if ($freshProduct === false || $freshProduct['bundle_id'] !== null) {
        throw new RuntimeException('The product cannot be deleted in its current state.');
    }
    inventory_assert_unlinked_locked($pdo, (int) $productId);

    /*
    | Delete the product.
    |
    | product_images records are automatically deleted
    | because of ON DELETE CASCADE.
    */

    $deleteStmt = $pdo->prepare(
        'DELETE FROM products
         WHERE id = ?'
    );

    $deleteStmt->execute([$productId]);

    $pdo->commit();

    /*
    |--------------------------------------------------------------------------
    | Delete physical image files
    |--------------------------------------------------------------------------
    */

    foreach ($images as $image) {

        $filePath = __DIR__ . '/../' . $image['image_path'];

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    header('Location: products.php?deleted=1');
    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    header('Location: products.php?delete_error=1');
    exit;
}
