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

<section class="register-section" aria-labelledby="register-heading">
    <div class="register-wrapper">
        <header class="register-header">
            <p class="register-brand">HOPIA FITS</p>
            <h1 id="register-heading">CREATE YOUR ACCOUNT</h1>
            <p class="register-subtitle">Keep track of your purchases and discover your next fit.</p>
        </header>

        <?php if (isset($errors['general'])): ?>
            <div class="register-alert register-alert--error" role="alert">
                <?= hopia_e($errors['general']) ?>
            </div>
        <?php endif; ?>

        <form class="register-form" method="POST" novalidate>
            <div class="register-form__row">
                <div class="register-form__field">
                    <label class="register-form__label" for="first-name">FIRST NAME</label>
                    <input
                        class="register-form__input <?= isset($errors['first_name']) ? 'is-error' : '' ?>"
                        id="first-name"
                        type="text"
                        name="first_name"
                        value="<?= hopia_e($_POST['first_name'] ?? '') ?>"
                        autocomplete="given-name"
                        required
                    >
                    <?php if (isset($errors['first_name'])): ?>
                        <p class="register-form__error"><?= hopia_e($errors['first_name']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="register-form__field">
                    <label class="register-form__label" for="last-name">LAST NAME</label>
                    <input
                        class="register-form__input <?= isset($errors['last_name']) ? 'is-error' : '' ?>"
                        id="last-name"
                        type="text"
                        name="last_name"
                        value="<?= hopia_e($_POST['last_name'] ?? '') ?>"
                        autocomplete="family-name"
                        required
                    >
                    <?php if (isset($errors['last_name'])): ?>
                        <p class="register-form__error"><?= hopia_e($errors['last_name']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="register-form__field">
                <label class="register-form__label" for="phone">PHONE NUMBER</label>
                <input
                    class="register-form__input"
                    id="phone"
                    type="tel"
                    name="phone"
                    value="<?= hopia_e($_POST['phone'] ?? '') ?>"
                    autocomplete="tel"
                >
            </div>

            <div class="register-form__field">
                <label class="register-form__label" for="password">PASSWORD</label>
                <div class="register-form__password">
                    <input
                        class="register-form__input register-form__input--password <?= isset($errors['password']) ? 'is-error' : '' ?>"
                        id="password"
                        type="password"
                        name="password"
                        autocomplete="new-password"
                        required
                    >
                    <button type="button" class="register-form__toggle" aria-label="Show password" data-toggle="password">
                        <svg class="register-form__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path class="eye-open" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle class="eye-open" cx="12" cy="12" r="3"/>
                            <line class="eye-closed" x1="1" y1="1" x2="23" y2="23" style="display:none"/>
                            <path class="eye-closed" d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" style="display:none"/>
                        </svg>
                    </button>
                </div>
                <?php if (isset($errors['password'])): ?>
                    <p class="register-form__error"><?= hopia_e($errors['password']) ?></p>
                <?php endif; ?>
            </div>

            <div class="register-form__field">
                <label class="register-form__label" for="confirm-password">CONFIRM PASSWORD</label>
                <div class="register-form__password">
                    <input
                        class="register-form__input register-form__input--password <?= isset($errors['confirm_password']) ? 'is-error' : '' ?>"
                        id="confirm-password"
                        type="password"
                        name="confirm_password"
                        autocomplete="new-password"
                        required
                    >
                    <button type="button" class="register-form__toggle" aria-label="Show password" data-toggle="confirm-password">
                        <svg class="register-form__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path class="eye-open" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle class="eye-open" cx="12" cy="12" r="3"/>
                            <line class="eye-closed" x1="1" y1="1" x2="23" y2="23" style="display:none"/>
                            <path class="eye-closed" d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" style="display:none"/>
                        </svg>
                    </button>
                </div>
                <?php if (isset($errors['confirm_password'])): ?>
                    <p class="register-form__error"><?= hopia_e($errors['confirm_password']) ?></p>
                <?php endif; ?>
            </div>

            <?php if (isset($errors['email'])): ?>
                <p class="register-form__error register-form__error--standalone"><?= hopia_e($errors['email']) ?></p>
            <?php endif; ?>

            <button class="register-form__submit" type="submit">CREATE ACCOUNT</button>
        </form>

        <p class="register-footer">
            Already have an account? <a href="login.php">Sign in</a>
        </p>
    </div>
</section>

<style>
/* Registration Page Styles */
.register-page .site-main {
    padding-block: var(--sp-5);
}

.register-section {
    display: flex;
    justify-content: center;
    padding-inline: var(--sp-4);
}

.register-wrapper {
    width: 100%;
    max-width: 480px;
}

/* Header */
.register-header {
    text-align: center;
    margin-bottom: var(--sp-6);
}

.register-brand {
    font-size: 1.375rem;
    font-weight: 900;
    letter-spacing: -0.04em;
    text-transform: uppercase;
    color: var(--color-text);
    line-height: 1;
    margin-bottom: var(--sp-6);
}

.register-header h1 {
    font-size: 1.625rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    color: var(--color-text);
    line-height: 1.2;
    margin: 0;
}

.register-subtitle {
    margin-top: var(--sp-3);
    font-size: 0.9375rem;
    color: var(--color-text-muted);
    line-height: 1.5;
}

/* Alert */
.register-alert {
    padding: 0.875rem 1rem;
    border: 1px solid var(--color-border);
    border-radius: 2px;
    background: var(--color-surface);
    color: var(--color-text-soft);
    font-size: 0.9375rem;
    margin-bottom: var(--sp-5);
}

.register-alert--error {
    background: var(--color-danger-soft);
    border-color: #f0cfcd;
    color: var(--color-danger-dark);
}

/* Form */
.register-form {
    display: flex;
    flex-direction: column;
    gap: var(--sp-5);
}

.register-form__row {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--sp-5);
}

@media (min-width: 480px) {
    .register-form__row {
        grid-template-columns: 1fr 1fr;
    }
}

.register-form__field {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.register-form__label {
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--color-text-soft);
}

.register-form__input {
    width: 100%;
    padding: 0.75rem 0.875rem;
    min-height: 48px;
    background: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    border-radius: 2px;
    font-family: inherit;
    font-size: 1rem;
    color: var(--color-text);
    transition: border-color 150ms ease, box-shadow 150ms ease;
}

.register-form__input::placeholder {
    color: var(--color-text-muted);
    opacity: 1;
}

.register-form__input:focus {
    outline: none;
    border-color: var(--color-text);
    box-shadow: 0 0 0 3px rgba(42, 42, 42, 0.08);
}

.register-form__input.is-error {
    border-color: var(--color-danger);
}

.register-form__input.is-error:focus {
    box-shadow: 0 0 0 3px rgba(168, 58, 52, 0.12);
}

/* Password field */
.register-form__password {
    position: relative;
}

.register-form__input--password {
    padding-right: 3rem;
}

/* Suppress the browser's native password reveal control so only the
   custom show/hide toggle is visible (Edge/IE eye + WebKit auto-fill). */
.register-form__input--password::-ms-reveal,
.register-form__input--password::-ms-clear {
    display: none;
}

.register-form__input--password::-webkit-credentials-auto-fill-button,
.register-form__input--password::-webkit-strong-password-auto-fill-button {
    visibility: hidden;
    pointer-events: none;
}

.register-form__toggle {
    position: absolute;
    right: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 0;
    border-radius: 2px;
    background: transparent;
    color: var(--color-text-muted);
    cursor: pointer;
    transition: color 150ms ease;
}

.register-form__toggle:hover {
    color: var(--color-text);
}

.register-form__toggle:focus-visible {
    outline: 2px solid var(--color-text);
    outline-offset: 1px;
}

.register-form__icon {
    width: 20px;
    height: 20px;
}

/* Error messages */
.register-form__error {
    font-size: 0.8125rem;
    color: var(--color-danger);
    margin: 0;
}

.register-form__error--standalone {
    padding: 0.75rem 0;
    margin-top: calc(var(--sp-3) * -1);
}

@media (min-width: 480px) {
    .register-form__error--standalone {
        margin-top: calc(var(--sp-5) * -1);
    }
}

/* Submit button */
.register-form__submit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: 52px;
    padding: 0.75rem 1.5rem;
    border: 1px solid var(--editorial-ink, #171717);
    border-radius: 2px;
    background: var(--editorial-ink, #171717);
    color: var(--editorial-paper, #f5f2ec);
    font-family: inherit;
    font-size: 0.875rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    cursor: pointer;
    transition: background-color 150ms ease, color 150ms ease;
    margin-top: var(--sp-2);
}

.register-form__submit:hover {
    background: var(--editorial-paper, #f5f2ec);
    color: var(--editorial-ink, #171717);
}

.register-form__submit:focus-visible {
    outline: 2px solid var(--editorial-ink, #171717);
    outline-offset: 3px;
}

.register-form__submit:active {
    transform: translateY(1px);
}

/* Footer */
.register-footer {
    margin-top: var(--sp-6);
    padding-top: var(--sp-5);
    border-top: 1px solid var(--color-border);
    text-align: center;
    font-size: 0.9375rem;
    color: var(--color-text-muted);
}

.register-footer a {
    color: var(--color-text);
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 0.15em;
}

.register-footer a:hover {
    color: var(--color-primary);
}

/* Responsive adjustments */
@media (min-width: 560px) {
    .register-page .site-main {
        padding-block: var(--sp-7);
    }

    .register-section {
        padding-inline: var(--sp-5);
    }

    .register-header h1 {
        font-size: 1.875rem;
    }
}

@media (max-width: 479px) {
    .register-form__row {
        gap: var(--sp-4);
    }
}
</style>

<script>
(function() {
    'use strict';

    // Password show/hide toggle
    document.querySelectorAll('[data-toggle]').forEach(function(button) {
        button.addEventListener('click', function() {
            var targetId = this.getAttribute('data-toggle');
            var input = document.getElementById(targetId);
            if (!input) return;

            var isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            this.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');

            this.querySelectorAll('.eye-open').forEach(function(el) {
                el.style.display = isPassword ? 'none' : '';
            });
            this.querySelectorAll('.eye-closed').forEach(function(el) {
                el.style.display = isPassword ? '' : 'none';
            });
        });
    });
})();
</script>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
