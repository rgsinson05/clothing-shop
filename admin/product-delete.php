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
|--------------------------------------------------------------------------
| Get product
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id, name
    FROM products
    WHERE id = ?
");

$stmt->execute([$productId]);

$product = $stmt->fetch();

if (!$product) {
    header('Location: products.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Get product images
|--------------------------------------------------------------------------
*/

$imageStmt = $pdo->prepare("
    SELECT image_path
    FROM product_images
    WHERE product_id = ?
");

$imageStmt->execute([$productId]);

$images = $imageStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Delete product
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();

    /*
    | Delete the product.
    |
    | product_images records are automatically deleted
    | because of ON DELETE CASCADE.
    */

    $deleteStmt = $pdo->prepare("
        DELETE FROM products
        WHERE id = ?
    ");

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