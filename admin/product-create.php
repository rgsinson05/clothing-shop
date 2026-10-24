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
$gender = '';
$description = '';
$conditionLabel = '';
$defects = '';
$price = '';
$size = '';
$color = '';

/*
 * Bundle-based item registration.
 * The product-create page is only accessible when a valid bundle_id is
 * present in the query string.  Access without a bundle_id redirects
 * the admin to the bundles page.
 *
 * Legacy product creation (unbundled) is removed from this flow.
 * All new physical garments must be registered through a bundle.
 */
$bundleId = filter_input(INPUT_GET, 'bundle_id', FILTER_VALIDATE_INT);

if (!$bundleId) {
    header('Location: bundles.php');
    exit;
}

/*
 * Load the selected bundle so the form can show its details.
 * The actual capacity and category checks happen inside the
 * transactional registration path below.
 */
$bundleStmt = $pdo->prepare('SELECT id, category FROM purchase_bundles WHERE id = :id');
$bundleStmt->execute([':id' => $bundleId]);
$selectedBundle = $bundleStmt->fetch();

if (!$selectedBundle) {
    header('Location: bundles.php?error=invalid_bundle');
    exit;
}

$bundleCategory = $selectedBundle['category'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['name'] ?? '');
    $category = $_POST['category'] ?? '';
    $gender = $_POST['gender'] ?? '';
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

    $allowedGenders = ['MEN', 'WOMEN'];

    if (!in_array($gender, $allowedGenders, true)) {
        $errors[] = 'Please select a valid gender.';
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

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($uploadedImages['tmp_name'][$i]);

            if (!in_array($mimeType, $allowedMimeTypes, true)) {
                $errors[] = 'Images must be JPG, PNG, or WebP files.';
            }
        }
    }

    /*
     * Register the item through the selected bundle.
     * All steps run inside a single transaction with a FOR UPDATE lock
     * on the bundle row so concurrent registrations cannot allocate
     * the same bundle_item_number.
     */

    if (empty($errors)) {

        try {

            $pdo->beginTransaction();

            /*
             * 1. Lock the bundle row and re-confirm it still exists.
             */
            $lockBundleStmt = $pdo->prepare(
                'SELECT id, category, original_quantity
                 FROM purchase_bundles
                 WHERE id = :id
                 FOR UPDATE'
            );
            $lockBundleStmt->execute([':id' => $bundleId]);
            $lockedBundle = $lockBundleStmt->fetch();

            if (!$lockedBundle) {
                throw new RuntimeException('Bundle not found.');
            }

            /*
             * 2. Confirm product category matches bundle category exactly.
             */
            if ($category !== $lockedBundle['category']) {
                throw new RuntimeException(
                    'Product category must match the bundle category (' . $lockedBundle['category'] . ').'
                );
            }

            /*
             * 3. Count registered items in this bundle.
             */
            $countStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM products
                 WHERE bundle_id = :bundle_id'
            );
            $countStmt->execute([':bundle_id' => $bundleId]);
            $registeredCount = (int) $countStmt->fetchColumn();

            /*
             * 4. Reject if bundle capacity is already reached.
             */
            if ($registeredCount >= (int) $lockedBundle['original_quantity']) {
                throw new RuntimeException(
                    'This bundle is already at full capacity (' . (int) $lockedBundle['original_quantity'] . ' items).'
                );
            }

            /*
             * 5. Allocate the next bundle_item_number for this bundle.
             *    MAX(bundle_item_number) + 1 is safe because the bundle row
             *    is locked, preventing any concurrent registration from
             *    allocating the same number.
             */
            $seqStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(bundle_item_number), 0) + 1
                 FROM products
                 WHERE bundle_id = :bundle_id'
            );
            $seqStmt->execute([':bundle_id' => $bundleId]);
            $nextItemNumber = (int) $seqStmt->fetchColumn();

            /*
             * 6. Generate the barcode.
             *    Format: HOPEBUNDLE-<6-digit bundle id>-<2-digit item number>
             *    Both paddings are minimum widths; values expand naturally.
             */
            $barcodeValue = 'HOPEBUNDLE-'
                . str_pad((string) $bundleId, 6, '0', STR_PAD_LEFT)
                . '-'
                . str_pad((string) $nextItemNumber, 2, '0', STR_PAD_LEFT);

            /*
             * 7. Insert the product with bundle assignment fields.
             */
            $insertStmt = $pdo->prepare(
                'INSERT INTO products
                    (name, category, gender, description, condition_label, defects,
                     price, size, color, bundle_id, bundle_item_number, barcode_value)
                 VALUES
                    (:name, :category, :gender, :description, :condition_label, :defects,
                     :price, :size, :color, :bundle_id, :bundle_item_number, :barcode_value)'
            );

            $insertStmt->execute([
                'name' => $name,
                'category' => $category,
                'gender' => $gender,
                'description' => $description !== '' ? $description : null,
                'condition_label' => $conditionLabel,
                'defects' => $defects !== '' ? $defects : null,
                'price' => $price,
                'size' => $size !== '' ? $size : null,
                'color' => $color !== '' ? $color : null,
                'bundle_id' => $bundleId,
                'bundle_item_number' => $nextItemNumber,
                'barcode_value' => $barcodeValue,
            ]);

            $productId = (int) $pdo->lastInsertId();

            /*
             * 8. Save uploaded images.
             */
            $imageDirectory = __DIR__ . '/../images/products';

            if (!is_dir($imageDirectory)) {
                mkdir($imageDirectory, 0755, true);
            }

            if ($uploadedImages && isset($uploadedImages['name'])) {

                $imageInsert = $pdo->prepare(
                    'INSERT INTO product_images
                        (product_id, image_path, sort_order)
                     VALUES
                        (:product_id, :image_path, :sort_order)'
                );

                $sortOrder = 0;
                $savedFilePaths = [];

                for ($i = 0; $i < count($uploadedImages['name']); $i++) {

                    if (
                        $uploadedImages['error'][$i] === UPLOAD_ERR_NO_FILE ||
                        $uploadedImages['name'][$i] === ''
                    ) {
                        continue;
                    }

                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file($uploadedImages['tmp_name'][$i]);

                    $extensions = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp',
                    ];

                    $extension = $extensions[$mimeType] ?? 'jpg';

                    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
                    $destination = $imageDirectory . '/' . $filename;

                    if (!move_uploaded_file(
                        $uploadedImages['tmp_name'][$i],
                        $destination
                    )) {
                        throw new RuntimeException('One of the images could not be saved.');
                    }

                    $savedFilePaths[] = $destination;

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
             * 9. If image save encountered errors, roll back.
             */
            if (!empty($errors)) {
                throw new RuntimeException('Image save failed.');
            }

            $pdo->commit();

            header('Location: products.php?registered=1');
            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            /*
             * Clean up image files that were written to disk before the
             * database operation failed.  $savedFilePaths is in-memory and
             * survives the rollback, so it is safe to use here.
             * No database query is made here because the product INSERT
             * and any prior image INSERTs were rolled back.
             */
            foreach ($savedFilePaths ?? [] as $filePath) {
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }

            $errors[] = 'Something went wrong while registering the item. Please try again.';
        }
    }
}

/*
 * For form display, show live bundle capacity.
 */
$formBundleStmt = $pdo->prepare(
    'SELECT pb.id, pb.category, pb.original_quantity,
            COUNT(p.id) AS registered_count
     FROM purchase_bundles pb
     LEFT JOIN products p ON p.bundle_id = pb.id
     WHERE pb.id = :id
     GROUP BY pb.id'
);
$formBundleStmt->execute([':id' => $bundleId]);
$formBundle = $formBundleStmt->fetch();

$bundleCapacityRemaining = 0;
if ($formBundle) {
    $bundleCapacityRemaining = (int) $formBundle['original_quantity'] - (int) $formBundle['registered_count'];
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';

$page_title = "Register Item - Admin - Hopia's Ukay-Ukay";
$page_description = "Register a new item through a purchase bundle.";
$ui_section = 'admin';
$ui_active = 'bundles.php';
$body_class = 'admin-product-form-page';

$bundleCode = 'HOPEBUNDLE-' . str_pad((string) $bundleId, 6, '0', STR_PAD_LEFT);
$categoryLabel = [
    'SHIRTS' => 'Shirts',
    'PANTS' => 'Pants',
    'SHORTS' => 'Shorts',
][$bundleCategory] ?? $bundleCategory;

?>

<?php require __DIR__ . '/../includes/ui.head.php'; ?>
<?php require __DIR__ . '/../includes/ui.header.php'; ?>

<div class="admin-product-form-page">
    <header class="admin-page-header">
        <div>
            <a class="admin-page-header__back" href="bundles.php">&larr; Back to Bundles</a>
            <p class="admin-page-header__eyebrow">Bundle item registration</p>
            <h1>REGISTER ITEM</h1>
            <p class="admin-page-header__copy">
                Registering item for bundle
                <code class="bundle-code-inline"><?= htmlspecialchars($bundleCode) ?></code>
                &mdash; <?= htmlspecialchars($categoryLabel) ?>.
                <?php if ($bundleCapacityRemaining > 0): ?>
                    <span class="badge badge-available"><?= (int) $bundleCapacityRemaining ?> slot<?= $bundleCapacityRemaining === 1 ? '' : 's' ?> remaining</span>.
                <?php else: ?>
                    <span class="badge badge-sold">Bundle is at full capacity</span>.
                <?php endif; ?>
            </p>
        </div>
    </header>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" role="alert">
            <strong>Please fix the following:</strong>
            <ul class="alert__list">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

    <?php endif; ?>

    <form class="admin-form-layout" method="POST" enctype="multipart/form-data">
        <section class="admin-form-card" aria-labelledby="product-details-title">
            <h2 id="product-details-title">Item details</h2>
            <p class="admin-form-card__intro">Enter the details for this unique item. Its barcode and bundle assignment are assigned automatically.</p>

            <div class="admin-form-grid">
                <div class="field field--wide">
                    <label for="name">Product Name</label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($name) ?>" required>
                </div>

                <div class="field">
                    <label for="category">Category</label>
                    <select id="category" name="category" required>
                        <option value="">Select category</option>
                        <option value="SHIRTS"<?= $category === 'SHIRTS' ? ' selected' : '' ?>>Shirts</option>
                        <option value="PANTS"<?= $category === 'PANTS' ? ' selected' : '' ?>>Pants</option>
                        <option value="SHORTS"<?= $category === 'SHORTS' ? ' selected' : '' ?>>Shorts</option>
                    </select>
                </div>

                <div class="field">
                    <label for="gender">Gender</label>
                    <select id="gender" name="gender" required>
                        <option value="">Select gender</option>
                        <option value="MEN"<?= $gender === 'MEN' ? ' selected' : '' ?>>Men</option>
                        <option value="WOMEN"<?= $gender === 'WOMEN' ? ' selected' : '' ?>>Women</option>
                    </select>
                </div>

                <div class="field">
                    <label for="condition_label">Condition</label>
                    <input type="text" id="condition_label" name="condition_label" placeholder="Example: Good" value="<?= htmlspecialchars($conditionLabel) ?>" required>
                </div>

                <div class="field field--wide">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="5"><?= htmlspecialchars($description) ?></textarea>
                </div>

                <div class="field field--wide">
                    <label for="defects">Defects</label>
                    <textarea id="defects" name="defects" rows="4" placeholder="Example: Minor fading on sleeve"><?= htmlspecialchars($defects) ?></textarea>
                </div>

                <div class="field">
                    <label for="price">Price</label>
                    <input type="number" id="price" name="price" step="0.01" min="0" value="<?= htmlspecialchars($price) ?>" required>
                </div>

                <div class="field">
                    <label for="size">Size</label>
                    <input type="text" id="size" name="size" placeholder="Example: Large" value="<?= htmlspecialchars($size) ?>">
                </div>

                <div class="field">
                    <label for="color">Color</label>
                    <input type="text" id="color" name="color" placeholder="Example: Black" value="<?= htmlspecialchars($color) ?>">
                </div>
            </div>

            <div class="admin-form-actions">
                <button class="btn btn-primary" type="submit"<?= $bundleCapacityRemaining <= 0 ? ' disabled' : '' ?>>Register Item</button>
                <a class="btn btn-secondary" href="bundles.php">Cancel</a>
            </div>
        </section>

        <section class="admin-form-card admin-image-upload" aria-labelledby="product-images-title">
            <h2 id="product-images-title">Product Images</h2>
            <p class="admin-image-upload__hint">
                Upload up to 5 images. JPG, PNG, and WebP are supported. Maximum 5 MB per image.
            </p>

            <div id="image-upload-container">
                <div class="image-row admin-image-row">
                    <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" class="image-input">
                    <img class="image-preview admin-image-preview" alt="Image preview" hidden>
                    <button type="button" class="btn btn-danger btn-sm remove-image" hidden>Remove</button>
                </div>
            </div>

            <button class="btn btn-secondary admin-image-upload__add" type="button" id="add-image">
                + Add another image
            </button>
        </section>
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

            row.className = 'image-row admin-image-row';

            row.innerHTML = `
                <input
                    type="file"
                    name="images[]"
                    accept="image/jpeg,image/png,image/webp"
                    class="image-input"
                >

                <img
                    class="image-preview admin-image-preview"
                    alt="Image preview"
                    hidden
                >

                <button
                    type="button"
                    class="btn btn-danger btn-sm remove-image"
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
</div>

<?php require __DIR__ . '/../includes/ui.footer.php';
