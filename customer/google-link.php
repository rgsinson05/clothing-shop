<?php

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/customer_auth.php';

define('HUPIA_BASE', '..');

$pendingToken = $_POST['pending'] ?? $_GET['pending'] ?? null;
$pending = hopia_google_pending('link', $pendingToken);
if ($pending === null) {
    hopia_google_flash('Your Google linking session expired. Please start again.', 'login');
    header('Location: login.php', true, 303);
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? null;
    $confirmed = ($_POST['confirm_link'] ?? null) === '1';

    if (!hopia_google_pending_csrf_valid($pending, $_POST['csrf_token'] ?? null)) {
        $message = 'Your session expired. Please start Google sign-in again.';
    } elseif (!$confirmed) {
        $message = 'Confirm that you want to link this Google account.';
    } elseif (!is_string($password) || $password === '') {
        $message = 'Enter your Hopia Fits password.';
    } else {
        $customer = hopia_google_email_customer($pdo, $pending['email']);
        $passwordHash = $customer['password_hash'] ?? null;

        if ($customer === null || !is_string($passwordHash) || $passwordHash === ''
            || !password_verify($password, $passwordHash)) {
            $message = 'That password is incorrect. Your accounts were not linked.';
        } else {
            try {
                $pdo->beginTransaction();
                $linkedCustomer = hopia_google_identity_customer($pdo, $pending['provider_subject']);

                if ($linkedCustomer !== null && (int) $linkedCustomer['id'] !== (int) $customer['id']) {
                    throw new RuntimeException('Google identity is already linked.');
                }

                if ($linkedCustomer === null) {
                    $identityStmt = $pdo->prepare(
                        "INSERT INTO customer_identities (customer_id, provider, provider_subject)
                         VALUES (:customer_id, 'google', :provider_subject)"
                    );
                    $identityStmt->execute([
                        ':customer_id' => (int) $customer['id'],
                        ':provider_subject' => $pending['provider_subject'],
                    ]);
                }

                $pdo->commit();
                $destination = hopia_customer_complete_login(
                    $customer,
                    $pending['destination'] ?? null
                );
                header('Location: ' . $destination, true, 303);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = 'We could not link the accounts. Please start Google sign-in again.';
            }
        }
    }
}

$page_title = 'Link Google Account - Hopia Fits';
$page_description = 'Verify your existing Hopia Fits account before linking Google.';
$ui_active = 'login.php';
$body_class = 'login-page';

require_once __DIR__ . '/../includes/ui.php';
require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<section class="auth-page__content" aria-labelledby="google-link-title">
    <div class="auth-card">
        <header class="auth-card__header">
            <p class="auth-card__eyebrow">Account Security</p>
            <h1 id="google-link-title">Link Your Account</h1>
            <p class="auth-card__subtitle">
                An existing Hopia Fits account uses <?= hopia_e($pending['email']) ?>.
                Enter its password to prove ownership before linking Google.
            </p>
        </header>

        <?php if ($message !== ''): ?>
            <p class="alert alert-error" role="alert"><?= hopia_e($message) ?></p>
        <?php endif; ?>

        <form class="auth-form" method="POST" novalidate>
            <input type="hidden" name="pending" value="<?= hopia_e($pendingToken) ?>">
            <input type="hidden" name="csrf_token" value="<?= hopia_e($pending['csrf']) ?>">

            <div class="field">
                <label class="field-label" for="link-password">Existing Account Password</label>
                <input class="input" id="link-password" type="password" name="password" autocomplete="current-password" required>
            </div>

            <label class="checkbox-label google-link-confirm">
                <input class="checkbox-input" type="checkbox" name="confirm_link" value="1" required>
                <span class="checkbox-custom" aria-hidden="true"></span>
                Link this Google identity to my existing customer account.
            </label>

            <button class="btn-auth-submit" type="submit">Verify and Link Google</button>
        </form>

        <p class="auth-card__switch"><a href="login.php">Return to login</a></p>
    </div>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
