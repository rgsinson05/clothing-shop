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
        <p class="account-header__eyebrow">ACCOUNT</p>
        <h1>MY ACCOUNT</h1>
        <div class="account-header__rule" aria-hidden="true"></div>
        <p class="account-header__greeting">
            Hi, <?= hopia_e($customer['first_name']) ?>! Here are your account details.
        </p>
    </header>

    <div class="account-dashboard">
        <section class="card account-card" aria-label="Account information">
            <div class="card-body account-card__body">
                <h2 class="account-card__eyebrow">ACCOUNT INFORMATION</h2>

                <dl class="account-details">
                    <div class="account-details__row">
                        <dt class="account-details__label">
                            <span class="account-details__icon" aria-hidden="true">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="8" r="3.6"></circle>
                                    <path d="M5.5 20c.7-3.6 3.2-5.4 6.5-5.4s5.8 1.8 6.5 5.4"></path>
                                </svg>
                            </span>
                            <span class="account-details__label-text">First Name</span>
                        </dt>
                        <dd class="account-details__value"><?= hopia_e($customer['first_name']) ?></dd>
                    </div>

                    <div class="account-details__row">
                        <dt class="account-details__label">
                            <span class="account-details__icon" aria-hidden="true">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="8" r="3.6"></circle>
                                    <path d="M5.5 20c.7-3.6 3.2-5.4 6.5-5.4s5.8 1.8 6.5 5.4"></path>
                                </svg>
                            </span>
                            <span class="account-details__label-text">Last Name</span>
                        </dt>
                        <dd class="account-details__value"><?= hopia_e($customer['last_name']) ?></dd>
                    </div>

                    <div class="account-details__row">
                        <dt class="account-details__label">
                            <span class="account-details__icon" aria-hidden="true">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3.5" y="5.5" width="17" height="13" rx="1.5"></rect>
                                    <path d="m4.5 7 7.5 6 7.5-6"></path>
                                </svg>
                            </span>
                            <span class="account-details__label-text">Email</span>
                        </dt>
                        <dd class="account-details__value account-details__value--text"><?= hopia_e($customer['email']) ?></dd>
                    </div>

                    <div class="account-details__row">
                        <dt class="account-details__label">
                            <span class="account-details__icon" aria-hidden="true">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M5.5 4.5h3l1.5 2.5h4l1.5-2.5h3a1.5 1.5 0 0 1 1.5 1.5v12A1.5 1.5 0 0 1 18.5 19.5h-13A1.5 1.5 0 0 1 4 18V6a1.5 1.5 0 0 1 1.5-1.5z"></path>
                                    <path d="M10.5 12.5a2 2 0 1 0 3 0 2 2 0 0 0-3 0z"></path>
                                </svg>
                            </span>
                            <span class="account-details__label-text">Phone</span>
                        </dt>
                        <?php if ($customerPhone !== ''): ?>
                            <dd class="account-details__value"><?= hopia_e($customerPhone) ?></dd>
                        <?php else: ?>
                            <dd class="account-details__value account-details__missing">Not provided</dd>
                        <?php endif; ?>
                    </div>
                </dl>
            </div>
        </section>

        <aside class="account-quick" aria-label="Quick actions">
            <p class="account-quick__eyebrow">QUICK ACTIONS</p>

            <a class="account-quick__action account-quick__action--primary" href="orders.php">
                <span class="account-quick__icon" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3.5 7.5h17l-1.6 11.4a1.5 1.5 0 0 1-1.5 1.3H6.6a1.5 1.5 0 0 1-1.5-1.3L3.5 7.5z"></path>
                        <path d="M8.5 7.5V6a3.5 3.5 0 0 1 7 0v1.5"></path>
                        <path d="M10 12.5h4"></path>
                    </svg>
                </span>
                <span class="account-quick__copy">
                    <span class="account-quick__label">VIEW MY ORDERS</span>
                    <span class="account-quick__sub">Track and review your purchases</span>
                </span>
                <span class="account-quick__arrow" aria-hidden="true">&rarr;</span>
            </a>

            <a class="account-quick__action account-quick__action--secondary" href="products.php">
                <span class="account-quick__icon" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5.5 8h13l-1 11.5H6.5L5.5 8z"></path>
                        <path d="M9 10.5V7a3 3 0 0 1 6 0v3.5"></path>
                    </svg>
                </span>
                <span class="account-quick__copy">
                    <span class="account-quick__label">CONTINUE SHOPPING</span>
                    <span class="account-quick__sub">Browse the latest finds</span>
                </span>
                <span class="account-quick__arrow" aria-hidden="true">&rarr;</span>
            </a>
        </aside>
    </div>

    <div class="account-actions">
        <div class="account-actions__divider" role="presentation"></div>
        <a class="account-actions__logout" href="logout.php" data-logout>LOG OUT</a>
    </div>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
