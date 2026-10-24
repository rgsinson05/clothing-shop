<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';

$errors = [];

$category = '';
$capitalTotal = '';
$originalQuantity = '';
$purchaseDate = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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
        header('Location: bundles.php');
        exit;
    }

    $category = $_POST['category'] ?? '';
    $capitalTotal = trim($_POST['capital_total'] ?? '');
    $originalQuantity = trim($_POST['original_quantity'] ?? '');
    $purchaseDate = $_POST['purchase_date'] ?? '';

    /*
     * Basic server-side validation.
     */

    $allowedCategories = ['SHIRTS', 'PANTS', 'SHORTS'];

    if (!in_array($category, $allowedCategories, true)) {
        $errors[] = 'Please select a valid category.';
    }

    if ($capitalTotal === '' || !is_numeric($capitalTotal) || (float) $capitalTotal <= 0) {
        $errors[] = 'Total capital must be a positive number.';
    }

    if ($originalQuantity === '' || !is_numeric($originalQuantity) || (int) $originalQuantity < 1) {
        $errors[] = 'Original quantity must be at least 1.';
    }

    if ($purchaseDate !== '') {
        $dateParts = explode('-', $purchaseDate);
        if (
            count($dateParts) !== 3
            || !checkdate((int) $dateParts[1], (int) $dateParts[2], (int) $dateParts[0])
            || strtotime($purchaseDate) === false
        ) {
            $errors[] = 'Please enter a valid purchase date (YYYY-MM-DD).';
        }
    }

    if (empty($errors)) {

        try {

            $stmt = $pdo->prepare(
                'INSERT INTO purchase_bundles
                    (category, capital_total, original_quantity, purchase_date)
                 VALUES
                    (:category, :capital_total, :original_quantity, :purchase_date)'
            );

            $stmt->execute([
                'category' => $category,
                'capital_total' => (float) $capitalTotal,
                'original_quantity' => (int) $originalQuantity,
                'purchase_date' => $purchaseDate !== '' ? $purchaseDate : null,
            ]);

            $newBundleId = (int) $pdo->lastInsertId();

            header('Location: bundles.php?created=1');
            exit;

        } catch (Throwable $e) {
            $errors[] = 'Something went wrong while creating the bundle. Please try again.';
        }
    }

    /*
     * Regenerate CSRF token on error so the form can be resubmitted.
     */
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

} else {

    /*
     * Fresh GET request: generate a CSRF token for the form.
     */
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

$csrfTokenValue = $_SESSION['csrf_token'];
$adminName = $_SESSION['admin_name'] ?? 'Admin';

$page_title = "Add Bundle - Admin - Hopia's Ukay-Ukay";
$page_description = "Create a new purchase bundle for Hopia's inventory.";
$ui_section = 'admin';
$ui_active = 'bundles.php';
$body_class = 'admin-product-form-page';

?>

<?php require __DIR__ . '/../includes/ui.head.php'; ?>
<?php require __DIR__ . '/../includes/ui.header.php'; ?>

<div class="admin-product-form-page">
    <header class="admin-page-header">
        <div>
            <a class="admin-page-header__back" href="bundles.php">&larr; Back to Bundles</a>
            <p class="admin-page-header__eyebrow">Bundle management</p>
            <h1>ADD BUNDLE</h1>
            <p class="admin-page-header__copy">
                Create one bundle row per actual purchased batch. Each physical purchase receives its own distinct bundle — bundles are never merged by category or capital.
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

    <form class="admin-form-layout" method="POST" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfTokenValue, ENT_QUOTES, 'UTF-8') ?>">
        <section class="admin-form-card" aria-labelledby="bundle-details-title">
            <h2 id="bundle-details-title">Bundle details</h2>
            <p class="admin-form-card__intro">
                Enter the details of the purchase bundle. Each registered item under this bundle will receive a unique, permanent barcode.
            </p>

            <div class="admin-form-grid">
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
                    <label for="capital_total">Total Capital (PHP)</label>
                    <input
                        type="number"
                        id="capital_total"
                        name="capital_total"
                        step="0.01"
                        min="0.01"
                        value="<?= htmlspecialchars($capitalTotal) ?>"
                        placeholder="0.00"
                        required
                    >
                </div>

                <div class="field">
                    <label for="original_quantity">Original Quantity</label>
                    <input
                        type="number"
                        id="original_quantity"
                        name="original_quantity"
                        min="1"
                        step="1"
                        value="<?= htmlspecialchars($originalQuantity) ?>"
                        placeholder="1"
                        required
                    >
                    <p class="hint">Number of items in this bundle at time of purchase.</p>
                </div>

                <div class="field">
                    <label for="purchase_date">Purchase Date <span class="hint-inline">(optional)</span></label>
                    <input
                        type="date"
                        id="purchase_date"
                        name="purchase_date"
                        value="<?= htmlspecialchars($purchaseDate) ?>"
                    >
                </div>
            </div>

            <div class="admin-form-actions">
                <button class="btn btn-primary" type="submit">Create Bundle</button>
                <a class="btn btn-secondary" href="bundles.php">Cancel</a>
            </div>
        </section>
    </form>
</div>

<?php require __DIR__ . '/../includes/ui.footer.php';
