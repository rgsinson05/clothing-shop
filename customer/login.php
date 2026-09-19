<?php

session_start();

require_once __DIR__ . '/../includes/database.php';

define('HUPIA_BASE', '..');

$page_title = 'Customer Login - Hopia\'s Ukay-Ukay';
$page_description = 'Log in to your customer account at Hopia\'s Ukay-Ukay.';
$ui_active = 'login.php';
$body_class = 'login-page';

require_once __DIR__ . '/../includes/ui.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $message = 'Please enter your email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } else {
        $sql = "
            SELECT id, first_name, last_name, email, password_hash
            FROM customers
            WHERE email = :email
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':email' => $email
        ]);

        $customer = $stmt->fetch();

        if ($customer && password_verify($password, $customer['password_hash'])) {
            session_regenerate_id(true);

            $_SESSION['customer_id'] = $customer['id'];
            $_SESSION['customer_name'] = $customer['first_name'];

            header('Location: ../index.php');
            exit;
        }

        $message = 'Invalid email or password.';
    }
}
?>

<?php
require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<section class="login-page__content" aria-labelledby="login-title">
    <div class="login-card">
        <header class="login-card__header">
            <p class="login-card__eyebrow">Hopia's Ukay-Ukay</p>
            <h1 id="login-title">WELCOME BACK</h1>
            <p class="login-card__intro">Log in to continue browsing your preloved finds.</p>
        </header>

        <?php if ($message !== ''): ?>
            <p class="alert alert-error" role="alert">
                <?= htmlspecialchars($message) ?>
            </p>
        <?php endif; ?>

        <form class="login-form" method="POST">
            <div class="field">
                <label for="email">Email</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    required
                >
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    required
                >
            </div>

            <button class="btn btn-primary btn-block login-form__submit" type="submit">Login</button>
        </form>

        <p class="login-card__register">
            New to Hopia? <a href="register.php">Create an account</a>
        </p>
    </div>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
