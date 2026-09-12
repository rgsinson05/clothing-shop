<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';

$errors = [];

$name = '';
$category = '';
$description = '';
$conditionLabel = '';
$defects = '';
$price = '';
$size = '';
$color = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $category = $_POST['category'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $conditionLabel = trim($_POST['condition_label'] ?? '');
    $defects = trim($_POST['defects'] ?? '');
    $price = trim($_POST['price'] ?? '');
    $size = trim($_POST['size'] ?? '');
    $color = trim($_POST['color'] ?? '');

    /*
     * Basic server-side validation.
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

    if ($price === '' || !is_numeric($price) || (float) $price < 0) {
        $errors[] = 'Please enter a valid price.';
    }

    /*
     * Validate uploaded images.
     */

    $uploadedImages = $_FILES['images'] ?? null;

    if ($uploadedImages && isset($uploadedImages['name'])) {
        $imageCount = count($uploadedImages['name']);

        if ($imageCount > 5) {
            $errors[] = 'You can upload a maximum of 5 images.';
        }

        $allowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/webp',
        ];

        $maxFileSize = 5 * 1024 * 1024; // 5 MB

        for ($i = 0; $i < $imageCount; $i++) {

            /*
             * Ignore empty upload fields.
             */
            if (
                $uploadedImages['error'][$i] === UPLOAD_ERR_NO_FILE ||
                $uploadedImages['name'][$i] === ''
            ) {
                continue;
            }

            if ($uploadedImages['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = 'One of the images could not be uploaded.';
                continue;
            }

            if ($uploadedImages['size'][$i] > $maxFileSize) {
                $errors[] = 'Each image must be 5 MB or smaller.';
                continue;
            }

            /*
             * Check the actual MIME type of the file.
             * Do not rely only on the filename extension.
             */
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($uploadedImages['tmp_name'][$i]);

            if (!in_array($mimeType, $allowedMimeTypes, true)) {
                $errors[] = 'Images must be JPG, PNG, or WebP files.';
            }
        }
    }

    /*
     * Create the product and save its images.
     */

    if (empty($errors)) {

        $stmt = $pdo->prepare(
            'INSERT INTO products
                (name, category, description, condition_label, defects, price, size, color)
             VALUES
                (:name, :category, :description, :condition_label, :defects, :price, :size, :color)'
        );

        $stmt->execute([
            'name' => $name,
            'category' => $category,
            'description' => $description !== '' ? $description : null,
            'condition_label' => $conditionLabel,
            'defects' => $defects !== '' ? $defects : null,
            'price' => $price,
            'size' => $size !== '' ? $size : null,
            'color' => $color !== '' ? $color : null,
        ]);

        $productId = (int) $pdo->lastInsertId();

        /*
         * Make sure the product image directory exists.
         */
        $imageDirectory = __DIR__ . '/../images/products';

        if (!is_dir($imageDirectory)) {
            mkdir($imageDirectory, 0755, true);
        }

        /*
         * Save uploaded images.
         */
        if ($uploadedImages && isset($uploadedImages['name'])) {

            $imageInsert = $pdo->prepare(
                'INSERT INTO product_images
                    (product_id, image_path, sort_order)
                 VALUES
                    (:product_id, :image_path, :sort_order)'
            );

            $sortOrder = 0;

            for ($i = 0; $i < count($uploadedImages['name']); $i++) {

                if (
                    $uploadedImages['error'][$i] === UPLOAD_ERR_NO_FILE ||
                    $uploadedImages['name'][$i] === ''
                ) {
                    continue;
                }

                /*
                 * Determine a safe extension from the actual MIME type.
                 */
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($uploadedImages['tmp_name'][$i]);

                $extensions = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];

                $extension = $extensions[$mimeType];

                /*
                 * Generate a unique filename.
                 */
                $filename = bin2hex(random_bytes(16)) . '.' . $extension;

                $destination = $imageDirectory . '/' . $filename;

                if (!move_uploaded_file(
                    $uploadedImages['tmp_name'][$i],
                    $destination
                )) {
                    $errors[] = 'One of the images could not be saved.';
                    continue;
                }

                /*
                 * Store the relative path in the database.
                 */
                $imagePath = 'images/products/' . $filename;

                $imageInsert->execute([
                    'product_id' => $productId,
                    'image_path' => $imagePath,
                    'sort_order' => $sortOrder,
                ]);

                $sortOrder++;
            }
        }

        /*
         * Return to the product list.
         */
        header('Location: products.php');
        exit;
    }
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Product - Admin - Hopia's Ukay-Ukay</title>

    <style>
        .image-upload {
            margin-top: 10px;
        }

        .image-row {
            margin-bottom: 15px;
        }

        .image-preview {
            display: block;
            width: 150px;
            height: 150px;
            object-fit: cover;
            margin-top: 8px;
            border: 1px solid #ccc;
        }

        .remove-image {
            margin-top: 5px;
        }
    </style>
</head>

<body>

    <h1>Add Product</h1>

    <p>
        Welcome, <?= htmlspecialchars($adminName) ?>.
    </p>

    <p>
        <a href="products.php">Back to Products</a>
        |
        <a href="index.php">Dashboard</a>
        |
        <a href="logout.php">Logout</a>
    </p>

    <hr>

    <?php if (!empty($errors)): ?>

        <div>
            <strong>Please fix the following:</strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">

        <div>
            <label for="name">Product Name</label>
            <br>

            <input
                type="text"
                id="name"
                name="name"
                value="<?= htmlspecialchars($name) ?>"
                required
            >
        </div>

        <br>

        <div>
            <label for="category">Category</label>
            <br>

            <select id="category" name="category" required>
                <option value="">Select category</option>

                <option
                    value="SHIRTS"
                    <?= $category === 'SHIRTS' ? 'selected' : '' ?>
                >
                    Shirts
                </option>

                <option
                    value="PANTS"
                    <?= $category === 'PANTS' ? 'selected' : '' ?>
                >
                    Pants
                </option>

                <option
                    value="SHORTS"
                    <?= $category === 'SHORTS' ? 'selected' : '' ?>
                >
                    Shorts
                </option>
            </select>
        </div>

        <br>

        <div>
            <label for="description">Description</label>
            <br>

            <textarea
                id="description"
                name="description"
                rows="5"
            ><?= htmlspecialchars($description) ?></textarea>
        </div>

        <br>

        <div>
            <label for="condition_label">Condition</label>
            <br>

            <input
                type="text"
                id="condition_label"
                name="condition_label"
                placeholder="Example: Good"
                value="<?= htmlspecialchars($conditionLabel) ?>"
                required
            >
        </div>

        <br>

        <div>
            <label for="defects">Defects</label>
            <br>

            <textarea
                id="defects"
                name="defects"
                rows="4"
                placeholder="Example: Minor fading on sleeve"
            ><?= htmlspecialchars($defects) ?></textarea>
        </div>

        <br>

        <div>
            <label for="price">Price</label>
            <br>

            <input
                type="number"
                id="price"
                name="price"
                step="0.01"
                min="0"
                value="<?= htmlspecialchars($price) ?>"
                required
            >
        </div>

        <br>

        <div>
            <label for="size">Size</label>
            <br>

            <input
                type="text"
                id="size"
                name="size"
                placeholder="Example: Large"
                value="<?= htmlspecialchars($size) ?>"
            >
        </div>

        <br>

        <div>
            <label for="color">Color</label>
            <br>

            <input
                type="text"
                id="color"
                name="color"
                placeholder="Example: Black"
                value="<?= htmlspecialchars($color) ?>"
            >
        </div>

        <br>

        <hr>

        <h2>Product Images</h2>

        <p>
            Upload up to 5 images. JPG, PNG, and WebP are supported.
            Maximum 5 MB per image.
        </p>

        <div id="image-upload-container">

            <div class="image-row">
                <input
                    type="file"
                    name="images[]"
                    accept="image/jpeg,image/png,image/webp"
                    class="image-input"
                >

                <img
                    class="image-preview"
                    alt="Image preview"
                    hidden
                >

                <button
                    type="button"
                    class="remove-image"
                    hidden
                >
                    Remove
                </button>
            </div>

        </div>

        <button
            type="button"
            id="add-image"
        >
            + Add another image
        </button>

        <br>
        <br>

        <button type="submit">
            Save Product
        </button>

    </form>

    <script>

        const container = document.getElementById('image-upload-container');
        const addImageButton = document.getElementById('add-image');

        function setupImageRow(row) {

            const input = row.querySelector('.image-input');
            const preview = row.querySelector('.image-preview');
            const removeButton = row.querySelector('.remove-image');

            input.addEventListener('change', function () {

                const file = input.files[0];

                if (!file) {
                    preview.hidden = true;
                    removeButton.hidden = true;
                    preview.removeAttribute('src');
                    return;
                }

                const objectUrl = URL.createObjectURL(file);

                preview.src = objectUrl;
                preview.hidden = false;
                removeButton.hidden = false;
            });

            removeButton.addEventListener('click', function () {

                row.remove();

                updateAddButton();
            });
        }

        function updateAddButton() {

            const rows = container.querySelectorAll('.image-row');

            if (rows.length >= 5) {
                addImageButton.disabled = true;
            } else {
                addImageButton.disabled = false;
            }
        }

        setupImageRow(
            container.querySelector('.image-row')
        );

        addImageButton.addEventListener('click', function () {

            const rows = container.querySelectorAll('.image-row');

            if (rows.length >= 5) {
                return;
            }

            const row = document.createElement('div');

            row.className = 'image-row';

            row.innerHTML = `
                <input
                    type="file"
                    name="images[]"
                    accept="image/jpeg,image/png,image/webp"
                    class="image-input"
                >

                <img
                    class="image-preview"
                    alt="Image preview"
                    hidden
                >

                <button
                    type="button"
                    class="remove-image"
                >
                    Remove
                </button>
            `;

            container.appendChild(row);

            setupImageRow(row);

            updateAddButton();
        });

        updateAddButton();

    </script>

</body>
</html>