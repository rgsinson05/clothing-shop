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
| Handle image deletion
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_image_id'])) {

    $imageId = filter_input(INPUT_POST, 'delete_image_id', FILTER_VALIDATE_INT);

    if ($imageId) {

        $stmt = $pdo->prepare("
            SELECT image_path
            FROM product_images
            WHERE id = ? AND product_id = ?
        ");

        $stmt->execute([$imageId, $productId]);

        $image = $stmt->fetch();

        if ($image) {

            $deleteStmt = $pdo->prepare("
                DELETE FROM product_images
                WHERE id = ? AND product_id = ?
            ");

            $deleteStmt->execute([$imageId, $productId]);

            $filePath = __DIR__ . '/../' . $image['image_path'];

            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    header('Location: product-edit.php?id=' . $productId);
    exit;
}

/*
|--------------------------------------------------------------------------
| Get product
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
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
| Get existing images
|--------------------------------------------------------------------------
*/

$imageStmt = $pdo->prepare("
    SELECT *
    FROM product_images
    WHERE product_id = ?
    ORDER BY sort_order ASC, id ASC
");

$imageStmt->execute([$productId]);

$images = $imageStmt->fetchAll();

$errors = [];

/*
|--------------------------------------------------------------------------
| Handle product update
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_image_id'])) {

    $name = trim($_POST['name'] ?? '');
    $category = $_POST['category'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $conditionLabel = trim($_POST['condition_label'] ?? '');
    $defects = trim($_POST['defects'] ?? '');
    $price = trim($_POST['price'] ?? '');
    $size = trim($_POST['size'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $status = $_POST['status'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($name === '') {
        $errors[] = 'Product name is required.';
    }

    $allowedCategories = ['SHIRTS', 'PANTS', 'SHORTS'];

    if (!in_array($category, $allowedCategories, true)) {
        $errors[] = 'Please select a valid category.';
    }

    if ($conditionLabel === '') {
        $errors[] = 'Condition is required.';
    }

    if ($price === '' || !is_numeric($price) || (float)$price < 0) {
        $errors[] = 'Please enter a valid price.';
    }

    $allowedStatuses = ['AVAILABLE', 'SOLD'];

    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] = 'Please select a valid status.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate uploaded images
    |--------------------------------------------------------------------------
    */

    $uploadedFiles = [];

    if (
        isset($_FILES['images']) &&
        isset($_FILES['images']['name']) &&
        is_array($_FILES['images']['name'])
    ) {

        foreach ($_FILES['images']['name'] as $index => $originalName) {

            if ($_FILES['images']['error'][$index] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($_FILES['images']['error'][$index] !== UPLOAD_ERR_OK) {
                $errors[] = "Image upload failed for {$originalName}.";
                continue;
            }

            $tmpName = $_FILES['images']['tmp_name'][$index];
            $fileSize = $_FILES['images']['size'][$index];

            if ($fileSize > 5 * 1024 * 1024) {
                $errors[] = "{$originalName} is larger than 5 MB.";
                continue;
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($tmpName);

            $allowedMimeTypes = [
                'image/jpeg',
                'image/png',
                'image/webp'
            ];

            if (!in_array($mimeType, $allowedMimeTypes, true)) {
                $errors[] = "{$originalName} is not a supported image type.";
                continue;
            }

            $uploadedFiles[] = [
                'tmp_name' => $tmpName,
                'mime_type' => $mimeType
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Maximum 5 images total
    |--------------------------------------------------------------------------
    */

    if (count($images) + count($uploadedFiles) > 5) {
        $errors[] = 'A product can have a maximum of 5 images.';
    }

    /*
    |--------------------------------------------------------------------------
    | Update database
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        try {

            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare("
                UPDATE products
                SET
                    name = ?,
                    category = ?,
                    description = ?,
                    condition_label = ?,
                    defects = ?,
                    price = ?,
                    size = ?,
                    color = ?,
                    status = ?
                WHERE id = ?
            ");

            $updateStmt->execute([
                $name,
                $category,
                $description !== '' ? $description : null,
                $conditionLabel,
                $defects !== '' ? $defects : null,
                (float)$price,
                $size !== '' ? $size : null,
                $color !== '' ? $color : null,
                $status,
                $productId
            ]);

            /*
            |--------------------------------------------------------------------------
            | Save uploaded images
            |--------------------------------------------------------------------------
            */

            if (!empty($uploadedFiles)) {

                $uploadDirectory = __DIR__ . '/../images/products';

                if (!is_dir($uploadDirectory)) {
                    if (!mkdir($uploadDirectory, 0755, true)) {
                        throw new RuntimeException('Could not create image directory.');
                    }
                }

                $sortOrder = count($images);

                $imageStmt = $pdo->prepare("
                    INSERT INTO product_images
                    (product_id, image_path, sort_order)
                    VALUES (?, ?, ?)
                ");

                foreach ($uploadedFiles as $file) {

                    $extensionMap = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp'
                    ];

                    $extension = $extensionMap[$file['mime_type']];

                    $filename = bin2hex(random_bytes(16)) . '.' . $extension;

                    $destination = $uploadDirectory . '/' . $filename;

                    if (!move_uploaded_file($file['tmp_name'], $destination)) {
                        throw new RuntimeException('Failed to save an uploaded image.');
                    }

                    $imagePath = 'images/products/' . $filename;

                    $imageStmt->execute([
                        $productId,
                        $imagePath,
                        $sortOrder
                    ]);

                    $sortOrder++;
                }
            }

            $pdo->commit();

            header('Location: product-edit.php?id=' . $productId . '&updated=1');
            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = 'Something went wrong while updating the product.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Keep submitted values on validation error
    |--------------------------------------------------------------------------
    */

    $product['name'] = $name;
    $product['category'] = $category;
    $product['description'] = $description;
    $product['condition_label'] = $conditionLabel;
    $product['defects'] = $defects;
    $product['price'] = $price;
    $product['size'] = $size;
    $product['color'] = $color;
    $product['status'] = $status;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Product</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 900px;
            margin: 40px auto;
            padding: 0 20px;
        }

        label {
            display: block;
            margin-top: 15px;
            margin-bottom: 5px;
            font-weight: bold;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 10px;
            box-sizing: border-box;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        button {
            margin-top: 20px;
            padding: 10px 16px;
            cursor: pointer;
        }

        .errors {
            background: #ffe5e5;
            padding: 15px;
            margin-bottom: 20px;
        }

        .success {
            background: #e5ffe8;
            padding: 15px;
            margin-bottom: 20px;
        }

        .images {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 15px;
        }

        .image-card {
            width: 150px;
        }

        .image-card img {
            width: 150px;
            height: 150px;
            object-fit: cover;
            display: block;
        }

        .image-card button {
            width: 100%;
            margin-top: 5px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
        }

        .upload-section {
            margin-top: 30px;
        }
    </style>
</head>

<body>

    <a class="back-link" href="products.php">
        ← Back to Products
    </a>

    <h1>Edit Product</h1>

    <?php if (isset($_GET['updated'])): ?>
        <div class="success">
            Product updated successfully.
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="errors">
            <strong>Please fix the following:</strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li>
                        <?= htmlspecialchars($error) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">

        <label for="name">Product Name</label>
        <input
            type="text"
            id="name"
            name="name"
            value="<?= htmlspecialchars($product['name']) ?>"
            required
        >

        <label for="category">Category</label>
        <select id="category" name="category" required>
            <option value="SHIRTS" <?= $product['category'] === 'SHIRTS' ? 'selected' : '' ?>>
                Shirts
            </option>

            <option value="PANTS" <?= $product['category'] === 'PANTS' ? 'selected' : '' ?>>
                Pants
            </option>

            <option value="SHORTS" <?= $product['category'] === 'SHORTS' ? 'selected' : '' ?>>
                Shorts
            </option>
        </select>

        <label for="description">Description</label>
        <textarea
            id="description"
            name="description"
        ><?= htmlspecialchars($product['description'] ?? '') ?></textarea>

        <label for="condition_label">Condition</label>
        <input
            type="text"
            id="condition_label"
            name="condition_label"
            value="<?= htmlspecialchars($product['condition_label']) ?>"
            required
        >

        <label for="defects">Defects</label>
        <textarea
            id="defects"
            name="defects"
        ><?= htmlspecialchars($product['defects'] ?? '') ?></textarea>

        <label for="price">Price</label>
        <input
            type="number"
            id="price"
            name="price"
            step="0.01"
            min="0"
            value="<?= htmlspecialchars($product['price']) ?>"
            required
        >

        <label for="size">Size</label>
        <input
            type="text"
            id="size"
            name="size"
            value="<?= htmlspecialchars($product['size'] ?? '') ?>"
        >

        <label for="color">Color</label>
        <input
            type="text"
            id="color"
            name="color"
            value="<?= htmlspecialchars($product['color'] ?? '') ?>"
        >

        <label for="status">Status</label>
        <select id="status" name="status" required>

            <option value="AVAILABLE" <?= $product['status'] === 'AVAILABLE' ? 'selected' : '' ?>>
                Available
            </option>

            <option value="SOLD" <?= $product['status'] === 'SOLD' ? 'selected' : '' ?>>
                Sold
            </option>

        </select>

        <div class="upload-section">

            <h2>Add Images</h2>

            <p>
                You can have up to 5 images total.
            </p>

            <input
                type="file"
                name="images[]"
                accept="image/jpeg,image/png,image/webp"
                multiple
            >

        </div>

        <button type="submit">
            Save Changes
        </button>

    </form>

     <div class="upload-section">

        <h2>Existing Images</h2>

        <?php if (empty($images)): ?>

            <p>No images uploaded.</p>

        <?php else: ?>

            <div class="images">

                <?php foreach ($images as $image): ?>

                    <div class="image-card">

                        <img
                            src="../<?= htmlspecialchars($image['image_path']) ?>"
                            alt="Product image"
                        >

                        <form method="POST">
                            <button
                                type="submit"
                                name="delete_image_id"
                                value="<?= $image['id'] ?>"
                                onclick="return confirm('Remove this image?')"
                            >
                                Remove
                            </button>
                        </form>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</body>
</html>

</body>
</html>