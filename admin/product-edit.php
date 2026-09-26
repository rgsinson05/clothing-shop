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
    $gender = $_POST['gender'] ?? '';
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

    $allowedGenders = ['MEN', 'WOMEN'];

    if (!in_array($gender, $allowedGenders, true)) {
        $errors[] = 'Please select a valid gender.';
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
                    gender = ?,
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
                $gender,
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
    $product['gender'] = $gender;
    $product['description'] = $description;
    $product['condition_label'] = $conditionLabel;
    $product['defects'] = $defects;
    $product['price'] = $price;
    $product['size'] = $size;
    $product['color'] = $color;
    $product['status'] = $status;
}

$page_title = "Edit Product - Admin - Hopia's Ukay-Ukay";
$page_description = "Edit an existing product in Hopia's inventory.";
$ui_section = 'admin';
$ui_active = 'products.php';
$body_class = 'admin-product-form-page';

?>

<?php require __DIR__ . '/../includes/ui.head.php'; ?>
<?php require __DIR__ . '/../includes/ui.header.php'; ?>

<div class="admin-product-form-page">
    <header class="admin-page-header">
        <div>
            <a class="admin-page-header__back" href="products.php">&larr; Back to Products</a>
            <p class="admin-page-header__eyebrow">Inventory management</p>
            <h1>EDIT PRODUCT</h1>
            <p class="admin-page-header__copy">
                Update the details for this unique item while keeping its inventory history intact.
            </p>
        </div>
    </header>

    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success" role="status">
            Product updated successfully.
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" role="alert">
            <strong>Please fix the following:</strong>
            <ul class="alert__list">
                <?php foreach ($errors as $error): ?>
                    <li>
                        <?= htmlspecialchars($error) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form class="admin-form-layout" method="POST" enctype="multipart/form-data">
        <section class="admin-form-card" aria-labelledby="product-details-title">
            <h2 id="product-details-title">Product details</h2>
            <p class="admin-form-card__intro">Update the existing information for this product.</p>

            <div class="admin-form-grid">
                <div class="field field--wide">
                    <label for="name">Product Name</label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($product['name']) ?>" required>
                </div>

                <div class="field">
                    <label for="category">Category</label>
                    <select id="category" name="category" required>
                        <option value="SHIRTS"<?= $product['category'] === 'SHIRTS' ? ' selected' : '' ?>>Shirts</option>
                        <option value="PANTS"<?= $product['category'] === 'PANTS' ? ' selected' : '' ?>>Pants</option>
                        <option value="SHORTS"<?= $product['category'] === 'SHORTS' ? ' selected' : '' ?>>Shorts</option>
                    </select>
                </div>

                <div class="field">
                    <label for="gender">Gender</label>
                    <?php $currentGender = $product['gender'] ?? ''; ?>
                    <select id="gender" name="gender" required>
                        <option value="" disabled<?= $currentGender === '' ? ' selected' : '' ?>>Select gender</option>
                        <option value="MEN"<?= $currentGender === 'MEN' ? ' selected' : '' ?>>Men</option>
                        <option value="WOMEN"<?= $currentGender === 'WOMEN' ? ' selected' : '' ?>>Women</option>
                    </select>
                </div>

                <div class="field">
                    <label for="condition_label">Condition</label>
                    <input type="text" id="condition_label" name="condition_label" value="<?= htmlspecialchars($product['condition_label']) ?>" required>
                </div>

                <div class="field field--wide">
                    <label for="description">Description</label>
                    <textarea id="description" name="description"><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                </div>

                <div class="field field--wide">
                    <label for="defects">Defects</label>
                    <textarea id="defects" name="defects"><?= htmlspecialchars($product['defects'] ?? '') ?></textarea>
                </div>

                <div class="field">
                    <label for="price">Price</label>
                    <input type="number" id="price" name="price" step="0.01" min="0" value="<?= htmlspecialchars($product['price']) ?>" required>
                </div>

                <div class="field">
                    <label for="size">Size</label>
                    <input type="text" id="size" name="size" value="<?= htmlspecialchars($product['size'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="color">Color</label>
                    <input type="text" id="color" name="color" value="<?= htmlspecialchars($product['color'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status" required>
                        <option value="AVAILABLE"<?= $product['status'] === 'AVAILABLE' ? ' selected' : '' ?>>Available</option>
                        <option value="SOLD"<?= $product['status'] === 'SOLD' ? ' selected' : '' ?>>Sold</option>
                    </select>
                </div>
            </div>

            <div class="admin-form-actions">
                <button class="btn btn-primary" type="submit">Save Changes</button>
                <a class="btn btn-secondary" href="products.php">Cancel</a>
            </div>
        </section>

        <section class="admin-form-card admin-image-upload" aria-labelledby="add-images-title">
            <h2 id="add-images-title">Add Images</h2>
            <p class="admin-image-upload__hint">You can have up to 5 images total.</p>
            <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
        </section>
    </form>

    <section class="admin-form-card admin-existing-images" aria-labelledby="existing-images-title">
        <h2 id="existing-images-title">Existing Images</h2>

        <?php if (empty($images)): ?>
            <p class="muted">No images uploaded.</p>

        <?php else: ?>

            <div class="admin-image-grid">

                <?php foreach ($images as $image): ?>

                    <div class="admin-image-card">

                        <img
                            src="../<?= htmlspecialchars($image['image_path']) ?>"
                            alt="Product image"
                        >

                        <form method="POST">
                            <button
                                class="btn btn-danger btn-sm"
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

    </section>
</div>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
