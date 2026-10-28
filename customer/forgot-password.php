<?php

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/password_reset.php';

define('HUPIA_BASE', '..');

$page_title = 'Forgot Password - Hopia Fits';
$page_description = 'Request a secure Hopia Fits password reset link.';
$ui_active = 'login.php';
$body_class = 'login-page';

require_once __DIR__ . '/../includes/ui.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$message = '';
$error = '';
$genericMessage = 'If an account exists for that email address, password reset instructions will be sent.';

if (empty($_SESSION['csrf_token_forgot_password'])) {
    $_SESSION['csrf_token_forgot_password'] = bin2hex(random_bytes(32));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $emailValue = $_POST['email'] ?? '';
    $email = is_string($emailValue) ? trim($emailValue) : '';
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken)
        || !hash_equals($_SESSION['csrf_token_forgot_password'], $submittedToken)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strlen($email) > 255) {
        $error = 'Please enter a valid email address.';
    } else {
        $now = time();
        $lastRequest = (int) ($_SESSION['password_reset_last_request'] ?? 0);
        $_SESSION['password_reset_last_request'] = $now;

        if ($lastRequest < $now - 10) {
            try {
                hopia_password_reset_request($pdo, $email);
            } catch (Throwable $e) {
                error_log('A customer password reset request could not be completed.');
            }
        }

        $message = $genericMessage;
        $_SESSION['csrf_token_forgot_password'] = bin2hex(random_bytes(32));
    }
}
?>

<?php require __DIR__ . '/../includes/ui.head.php'; ?>
<?php require __DIR__ . '/../includes/ui.header.php'; ?>

<section class="auth-page__content" aria-labelledby="forgot-password-title">
    <div class="auth-card">
        <div class="auth-card__lockup">
            <img class="auth-card__logo" src="<?= hopia_e(hopia_asset('assets/videos/pictures/Hopia-fits-logo.webp')) ?>" alt="" width="36" height="36">
            <span class="auth-card__brand">HOPIA FITS</span>
        </div>

        <header class="auth-card__header">
            <p class="auth-card__eyebrow">Account</p>
            <h1 id="forgot-password-title">Reset Your Password</h1>
            <p class="auth-card__subtitle">Enter your account email and we will send a secure reset link.</p>
        </header>

        <?php if ($message !== ''): ?>
            <p class="alert alert-success" role="status"><?= hopia_e($message) ?></p>
        <?php elseif ($error !== ''): ?>
            <p class="alert alert-error" role="alert"><?= hopia_e($error) ?></p>
        <?php endif; ?>

        <form class="auth-form" method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= hopia_e($_SESSION['csrf_token_forgot_password']) ?>">
            <div class="field">
                <label class="field-label" for="email">Email</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    class="input <?= $error !== '' ? 'is-error' : '' ?>"
                    value="<?= hopia_e($_POST['email'] ?? '') ?>"
                    autocomplete="email"
                    maxlength="255"
                    required
                >
            </div>

            <button class="btn-auth-submit" type="submit">Send Reset Instructions</button>
        </form>

        <p class="auth-card__switch">Created your account with Google? <a href="login.php">Sign in with Google</a>.</p>
        <p class="auth-card__switch"><a href="login.php">Back to login</a></p>
    </div>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
