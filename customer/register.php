<?php

require_once __DIR__ . '/../includes/database.php';

define('HUPIA_BASE', '..');

$page_title = 'Create an Account - Hopia\'s Ukay-Ukay';
$page_description = 'Create a customer account at Hopia\'s Ukay-Ukay.';
$ui_active = 'register.php';
$body_class = 'register-page';

require_once __DIR__ . '/../includes/ui.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if (
        $firstName === '' ||
        $lastName === '' ||
        $email === '' ||
        $password === ''
    ) {
        $message = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters.';
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $sql = "
            INSERT INTO customers
            (first_name, last_name, email, password_hash, phone)
            VALUES
            (:first_name, :last_name, :email, :password_hash, :phone)
        ";

        try {
            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => $email,
                ':password_hash' => $passwordHash,
                ':phone' => $phone
            ]);

            header('Location: ../index.php');
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $message = 'That email address is already registered.';
            } else {
                $message = 'Something went wrong while creating your account.';
            }
        }
    }
}
?>

<?php
require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<section class="register-page__content" aria-labelledby="register-title">
    <div class="register-card">
        <header class="register-card__header">
            <p class="register-card__eyebrow">Welcome to Hopia</p>
            <h1 id="register-title">CREATE AN ACCOUNT</h1>
            <p class="register-card__intro">Join us to keep track of your preloved finds and orders.</p>
        </header>

        <?php if ($message !== ''): ?>
            <p class="alert <?= $message === 'Registration successful!' ? 'alert-success' : 'alert-error' ?>" role="alert">
                <?= hopia_e($message) ?>
            </p>
        <?php endif; ?>

        <form class="register-form" method="POST">
            <div class="register-form__fields">
                <div class="field">
                    <label for="first-name">First Name</label>
                    <input id="first-name" type="text" name="first_name" required>
                </div>

                <div class="field">
                    <label for="last-name">Last Name</label>
                    <input id="last-name" type="text" name="last_name" required>
                </div>

                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" required>
                </div>

                <div class="field">
                    <label for="phone">Phone <span class="register-form__optional">(optional)</span></label>
                    <input id="phone" type="text" name="phone">
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" required>
                    <p class="hint">Use at least 8 characters.</p>
                </div>
            </div>

            <button class="btn btn-primary btn-block register-form__submit" type="submit">Register</button>
        </form>

        <p class="register-card__login">
            Already have an account? <a href="login.php">Log in</a>
        </p>
    </div>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
