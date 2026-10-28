<?php

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/password_reset.php';

define('HUPIA_BASE', '..');

$page_title = 'Reset Password - Hopia Fits';
$page_description = 'Choose a new password for your Hopia Fits account.';
$ui_active = 'login.php';
$body_class = 'login-page';

require_once __DIR__ . '/../includes/ui.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$success = !empty($_SESSION['password_reset_success']);
unset($_SESSION['password_reset_success']);

$tokenValue = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    ? ($_POST['token'] ?? '')
    : ($_GET['token'] ?? '');
$token = is_string($tokenValue) ? $tokenValue : '';
$errors = [];
$reset = $success ? null : hopia_password_reset_find($pdo, $token);

if ($reset !== null && empty($_SESSION['csrf_token_reset_password'])) {
    $_SESSION['csrf_token_reset_password'] = bin2hex(random_bytes(32));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $reset !== null) {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!is_string($submittedCsrf)
        || empty($_SESSION['csrf_token_reset_password'])
        || !hash_equals($_SESSION['csrf_token_reset_password'], $submittedCsrf)) {
        $errors['general'] = 'Your session expired. Please open the reset link again.';
    }

    if (!is_string($newPassword) || $newPassword === '') {
        $errors['password'] = 'Enter a new password.';
    } elseif (strlen($newPassword) < 8) {
        $errors['password'] = 'Use at least 8 characters.';
    }

    if (!is_string($confirmPassword) || $confirmPassword === '') {
        $errors['confirm_password'] = 'Confirm your new password.';
    } elseif (is_string($newPassword) && $confirmPassword !== $newPassword) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if ($errors === []) {
        try {
            $customerId = hopia_password_reset_consume($pdo, $token, $newPassword);
            if ($customerId === null) {
                $reset = null;
            } else {
                if ((int) ($_SESSION['customer_id'] ?? 0) === $customerId) {
                    unset($_SESSION['customer_id'], $_SESSION['customer_name'], $_SESSION['csrf_token']);
                    session_regenerate_id(true);
                }

                unset($_SESSION['csrf_token_reset_password']);
                $_SESSION['password_reset_success'] = true;
                header('Location: reset-password.php', true, 303);
                exit;
            }
        } catch (Throwable $e) {
            error_log('A customer password reset could not be completed.');
            $errors['general'] = 'Something went wrong. Please try again later.';
        }
    }
}
?>

<?php require __DIR__ . '/../includes/ui.head.php'; ?>
<?php require __DIR__ . '/../includes/ui.header.php'; ?>

<section class="auth-page__content" aria-labelledby="reset-password-title">
    <div class="auth-card">
        <div class="auth-card__lockup">
            <img class="auth-card__logo" src="<?= hopia_e(hopia_asset('assets/videos/pictures/Hopia-fits-logo.webp')) ?>" alt="" width="36" height="36">
            <span class="auth-card__brand">HOPIA FITS</span>
        </div>

        <header class="auth-card__header">
            <p class="auth-card__eyebrow">Account Security</p>
            <h1 id="reset-password-title"><?= $success ? 'Password Updated' : 'Choose a New Password' ?></h1>
            <p class="auth-card__subtitle">
                <?= $success ? 'Your password has been reset. Sign in using your new password.' : 'Use at least 8 characters for your new password.' ?>
            </p>
        </header>

        <?php if ($success): ?>
            <p class="alert alert-success" role="status">Your password has been updated successfully.</p>
            <a class="btn-auth-submit" href="login.php">Continue to Login</a>
        <?php elseif ($reset === null): ?>
            <p class="alert alert-error" role="alert">This password reset link is invalid or has expired.</p>
            <p class="auth-card__switch"><a href="forgot-password.php">Request a new reset link</a></p>
        <?php else: ?>
            <?php if (isset($errors['general'])): ?>
                <p class="alert alert-error" role="alert"><?= hopia_e($errors['general']) ?></p>
            <?php endif; ?>

            <form class="auth-form" method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= hopia_e($_SESSION['csrf_token_reset_password']) ?>">
                <input type="hidden" name="token" value="<?= hopia_e($token) ?>">

                <div class="field">
                    <label class="field-label" for="password">New Password</label>
                    <input id="password" type="password" name="password" class="input <?= isset($errors['password']) ? 'is-error' : '' ?>" autocomplete="new-password" minlength="8" required>
                    <?php if (isset($errors['password'])): ?>
                        <p class="auth-form__error"><?= hopia_e($errors['password']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label" for="confirm-password">Confirm New Password</label>
                    <input id="confirm-password" type="password" name="confirm_password" class="input <?= isset($errors['confirm_password']) ? 'is-error' : '' ?>" autocomplete="new-password" minlength="8" required>
                    <?php if (isset($errors['confirm_password'])): ?>
                        <p class="auth-form__error"><?= hopia_e($errors['confirm_password']) ?></p>
                    <?php endif; ?>
                </div>

                <button class="btn-auth-submit" type="submit">Update Password</button>
            </form>

            <p class="auth-card__switch"><a href="login.php">Back to login</a></p>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
