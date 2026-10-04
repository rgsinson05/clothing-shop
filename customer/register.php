<?php

require_once __DIR__ . '/../includes/database.php';

define('HOPIA_BASE', '..');

$page_title = 'Create Account - HOPIA FITS';
$page_description = 'Create your HOPIA FITS account to keep track of your purchases and discover your next fit.';
$ui_active = 'register.php';
$body_class = 'register-page';

require_once __DIR__ . '/../includes/ui.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validation
    if ($firstName === '') {
        $errors['first_name'] = 'First name is required.';
    }
    if ($lastName === '') {
        $errors['last_name'] = 'Last name is required.';
    }
    if ($email === '') {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Use at least 8 characters.';
    }
    if ($password !== $confirmPassword) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (empty($errors)) {
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
                $errors['email'] = 'That email address is already registered.';
            } else {
                $errors['general'] = 'Something went wrong while creating your account.';
            }
        }
    }
}
?>

<?php require __DIR__ . '/../includes/ui.head.php'; ?>
<?php require __DIR__ . '/../includes/ui.header.php'; ?>

<section class="auth-page__content" aria-labelledby="register-heading">
    <div class="auth-card">

        <div class="auth-card__lockup">
            <img
                class="auth-card__logo"
                src="<?= hopia_e(hopia_asset('assets/videos/pictures/Hopia-fits-logo.webp')) ?>"
                alt=""
                width="36"
                height="36"
            >
            <span class="auth-card__brand">HOPIA FITS</span>
        </div>

        <header class="auth-card__header">
            <p class="auth-card__eyebrow">Account</p>
            <h1 id="register-heading">Create Your Account</h1>
            <p class="auth-card__subtitle">Keep track of your purchases and discover your next fit.</p>
        </header>

        <?php if (isset($errors['general'])): ?>
            <div class="register-alert register-alert--error" role="alert">
                <?= hopia_e($errors['general']) ?>
            </div>
        <?php endif; ?>

        <form class="auth-form" method="POST" novalidate>
            <div class="auth-form__row">
                <div class="field">
                    <label class="field-label" for="first-name">
                        <svg class="field-label__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="8" r="3.2"></circle>
                            <path d="M5.5 20c.7-3.5 3-5.2 6.5-5.2s5.8 1.7 6.5 5.2"></path>
                        </svg>
                        First Name
                    </label>
                    <input
                        class="input <?= isset($errors['first_name']) ? 'is-error' : '' ?>"
                        id="first-name"
                        type="text"
                        name="first_name"
                        value="<?= hopia_e($_POST['first_name'] ?? '') ?>"
                        autocomplete="given-name"
                        required
                    >
                    <?php if (isset($errors['first_name'])): ?>
                        <p class="auth-form__error"><?= hopia_e($errors['first_name']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label" for="last-name">
                        <svg class="field-label__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="10" cy="8" r="3.2"></circle>
                            <path d="M3.5 20c.7-3.5 3-5.2 6.5-5.2s5.8 1.7 6.5 5.2"></path>
                            <path d="M18.5 10v6M15.5 13h6"></path>
                        </svg>
                        Last Name
                    </label>
                    <input
                        class="input <?= isset($errors['last_name']) ? 'is-error' : '' ?>"
                        id="last-name"
                        type="text"
                        name="last_name"
                        value="<?= hopia_e($_POST['last_name'] ?? '') ?>"
                        autocomplete="family-name"
                        required
                    >
                    <?php if (isset($errors['last_name'])): ?>
                        <p class="auth-form__error"><?= hopia_e($errors['last_name']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="field">
                <label class="field-label" for="email">
                    <svg class="field-label__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="3.5" y="5.5" width="17" height="13" rx="1.5"></rect>
                        <path d="m4.5 7 7.5 6 7.5-6"></path>
                    </svg>
                    Email
                </label>
                <input
                    class="input <?= isset($errors['email']) ? 'is-error' : '' ?>"
                    id="email"
                    type="email"
                    name="email"
                    value="<?= hopia_e($_POST['email'] ?? '') ?>"
                    autocomplete="email"
                    required
                >
                <?php if (isset($errors['email'])): ?>
                    <p class="auth-form__error"><?= hopia_e($errors['email']) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="field-label" for="phone">
                    <svg class="field-label__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M5 3.5h4l1.5 4.5-2.2 1.6a12.5 12.5 0 0 0 6.1 6.1l1.6-2.2 4.5 1.5v4a1.5 1.5 0 0 1-1.6 1.5C10.4 19.8 4.2 13.6 3.5 5.1A1.5 1.5 0 0 1 5 3.5z"></path>
                    </svg>
                    Phone Number
                </label>
                <input
                    class="input"
                    id="phone"
                    type="tel"
                    name="phone"
                    value="<?= hopia_e($_POST['phone'] ?? '') ?>"
                    autocomplete="tel"
                >
            </div>

            <div class="field">
                <label class="field-label" for="password">
                    <svg class="field-label__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="4.5" y="10.5" width="15" height="10" rx="1.5"></rect>
                        <path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"></path>
                        <path d="M12 14.5v2.5"></path>
                    </svg>
                    Password
                </label>
                <div class="input-password">
                    <input
                        class="input <?= isset($errors['password']) ? 'is-error' : '' ?>"
                        id="password"
                        type="password"
                        name="password"
                        autocomplete="new-password"
                        required
                    >
                    <button type="button" class="input-password__toggle" aria-label="Show password" data-password-toggle="password">
                        <svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path class="eye-open" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle class="eye-open" cx="12" cy="12" r="3"/>
                            <line class="eye-closed" x1="1" y1="1" x2="23" y2="23" style="display:none"/>
                            <path class="eye-closed" d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" style="display:none"/>
                        </svg>
                    </button>
                </div>
                <?php if (isset($errors['password'])): ?>
                    <p class="auth-form__error"><?= hopia_e($errors['password']) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="field-label" for="confirm-password">
                    <svg class="field-label__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="4.5" y="10.5" width="15" height="10" rx="1.5"></rect>
                        <path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"></path>
                        <path d="m9.5 15.5 2 2 3.5-3.5"></path>
                    </svg>
                    Confirm Password
                </label>
                <div class="input-password">
                    <input
                        class="input <?= isset($errors['confirm_password']) ? 'is-error' : '' ?>"
                        id="confirm-password"
                        type="password"
                        name="confirm_password"
                        autocomplete="new-password"
                        required
                    >
                    <button type="button" class="input-password__toggle" aria-label="Show password" data-password-toggle="confirm-password">
                        <svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path class="eye-open" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle class="eye-open" cx="12" cy="12" r="3"/>
                            <line class="eye-closed" x1="1" y1="1" x2="23" y2="23" style="display:none"/>
                            <path class="eye-closed" d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" style="display:none"/>
                        </svg>
                    </button>
                </div>
                <?php if (isset($errors['confirm_password'])): ?>
                    <p class="auth-form__error"><?= hopia_e($errors['confirm_password']) ?></p>
                <?php endif; ?>
            </div>

            <button class="btn-auth-submit" type="submit">
                Create Account
                <svg class="btn-auth-submit__arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="4" y1="12" x2="20" y2="12"></line>
                    <polyline points="14 6 20 12 14 18"></polyline>
                </svg>
            </button>
        </form>

        <p class="auth-card__switch">
            Already have an account? <a href="login.php">Sign in</a>
        </p>

    </div>
</section>

<script>
(function () {
    document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
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
    });
})();
</script>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
