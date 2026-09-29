<?php

session_start();

require_once __DIR__ . '/../includes/database.php';

define('HUPIA_BASE', '..');

$page_title = 'Customer Login - Hopia Fits';
$page_description = 'Sign in to your Hopia Fits account.';
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

<section class="auth-page__content" aria-labelledby="login-title">
    <div class="auth-card">

        <div class="auth-card__brand">Hopia Fits</div>

        <header class="auth-card__header">
            <h1 id="login-title">Welcome Back</h1>
            <p class="auth-card__subtitle">Sign in to continue browsing your finds.</p>
        </header>

        <?php if ($message !== ''): ?>
            <p class="alert alert-error" role="alert"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>

        <form class="auth-form" method="POST" novalidate>
            <div class="field">
                <label class="field-label" for="email">Email</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    class="input"
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                    autocomplete="email"
                    required
                >
            </div>

            <div class="field">
                <label class="field-label" for="password">Password</label>
                <div class="input-password">
                    <input
                        id="password"
                        type="password"
                        name="password"
                        class="input"
                        autocomplete="current-password"
                        required
                    >
                    <button
                        type="button"
                        class="input-password__toggle"
                        aria-label="Show password"
                        data-password-toggle="password"
                    >
                        <svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path class="eye-open" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle class="eye-open" cx="12" cy="12" r="3"/>
                            <line class="eye-closed" x1="1" y1="1" x2="23" y2="23" style="display:none"/>
                            <path class="eye-closed" d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" style="display:none"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="auth-form__extras">
                <div class="auth-form__remember">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember" class="checkbox-input">
                        <span class="checkbox-custom" aria-hidden="true"></span>
                        Remember me
                    </label>
                </div>
                <span class="auth-form__forgot">
                    <a href="#" class="link-forgot" tabindex="0" aria-label="Forgot password (not available)">Forgot password?</a>
                </span>
            </div>

            <button class="btn-auth-submit" type="submit">Login</button>
        </form>

        <p class="auth-card__switch">
            New to Hopia Fits? <a href="register.php">Create an account</a>
        </p>

    </div>
</section>

<script>
(function () {
    var btn = document.querySelector('[data-password-toggle]');
    if (!btn) return;
    var input = document.getElementById(btn.getAttribute('data-password-toggle'));
    if (!input) return;

    var eyeOpen = btn.querySelectorAll('.eye-open');
    var eyeClosed = btn.querySelectorAll('.eye-closed');

    function apply(show) {
        input.type = show ? 'text' : 'password';
        eyeOpen.forEach(function (el) { el.style.display = show ? 'none' : ''; });
        eyeClosed.forEach(function (el) { el.style.display = show ? '' : 'none'; });
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    }

    btn.addEventListener('click', function () {
        apply(input.type === 'password');
    });
})();
</script>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
