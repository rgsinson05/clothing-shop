<?php

session_start();

require_once __DIR__ . '/../includes/database.php';

/*
 * Require the customer to be logged in.
 */

if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}

$customerId = (int) $_SESSION['customer_id'];

/*
 * Load the logged-in customer's account record.
 */

$customerStmt = $pdo->prepare(
    'SELECT first_name, last_name, email, phone
     FROM customers
     WHERE id = :customer_id
     LIMIT 1'
);

$customerStmt->execute([':customer_id' => $customerId]);

$customer = $customerStmt->fetch();

/*
 * If the account record no longer exists, end the stale
 * customer session and return to login.
 */

if ($customer === false) {
    unset($_SESSION['customer_id'], $_SESSION['customer_name']);

    header('Location: login.php');
    exit;
}

$customerPhone = trim((string) $customer['phone']);

/*
 * Build the UI page. Include shared head, header, and footer.
 */

require_once __DIR__ . '/../includes/ui.php';

$page_title = 'My Account - ' . hopia_site_name();
$page_description = 'View your account details at ' . hopia_site_name() . '.';
$ui_active = 'account.php';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';

?>

<section class="account-page">
    <header class="account-header">
        <h1>MY ACCOUNT</h1>
        <p class="account-header__greeting">
            Hi, <?= hopia_e($customer['first_name']) ?>! Here are your account details.
        </p>
    </header>

    <section class="card account-card" aria-label="Account information">
        <div class="card-body">
            <h2 class="card-title">Account Information</h2>

            <dl class="account-details">
                <div>
                    <dt>First Name</dt>
                    <dd><?= hopia_e($customer['first_name']) ?></dd>
                </div>

                <div>
                    <dt>Last Name</dt>
                    <dd><?= hopia_e($customer['last_name']) ?></dd>
                </div>

                <div>
                    <dt>Email</dt>
                    <dd><?= hopia_e($customer['email']) ?></dd>
                </div>

                <div>
                    <dt>Phone</dt>
                    <?php if ($customerPhone !== ''): ?>
                        <dd><?= hopia_e($customerPhone) ?></dd>
                    <?php else: ?>
                        <dd class="account-details__missing">Not provided</dd>
                    <?php endif; ?>
                </div>
            </dl>
        </div>
    </section>

    <div class="account-actions">
        <a class="btn btn-primary account-actions__orders" href="orders.php">VIEW MY ORDERS</a>
        <a class="btn btn-secondary account-actions__shop" href="products.php">CONTINUE SHOPPING</a>
    </div>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
