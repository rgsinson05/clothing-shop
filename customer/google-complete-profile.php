<?php

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/customer_auth.php';

define('HUPIA_BASE', '..');

$pendingToken = $_POST['pending'] ?? $_GET['pending'] ?? null;
$pending = hopia_google_pending('profile', $pendingToken);
if ($pending === null) {
    hopia_google_flash('Your Google registration session expired. Please start again.', 'register');
    header('Location: register.php', true, 303);
    exit;
}

$errors = [];
$firstName = trim((string) ($pending['first_name'] ?? ''));
$lastName = trim((string) ($pending['last_name'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = is_string($_POST['first_name'] ?? null) ? trim($_POST['first_name']) : '';
    $lastName = is_string($_POST['last_name'] ?? null) ? trim($_POST['last_name']) : '';

    if (!hopia_google_pending_csrf_valid($pending, $_POST['csrf_token'] ?? null)) {
        $errors['general'] = 'Your session expired. Please start Google registration again.';
    }
    if ($firstName === '' || mb_strlen($firstName, 'UTF-8') > 100) {
        $errors['first_name'] = 'Enter a first name of 100 characters or fewer.';
    }
    if ($lastName === '' || mb_strlen($lastName, 'UTF-8') > 100) {
        $errors['last_name'] = 'Enter a last name of 100 characters or fewer.';
    }

    if ($errors === []) {
        $identity = [
            'provider_subject' => $pending['provider_subject'],
            'email' => $pending['email'],
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];

        try {
            $result = hopia_google_create_customer($pdo, $identity);
            if ($result['status'] === 'link') {
                $linkToken = hopia_google_store_pending($identity, $pending, 'link');
                header('Location: google-link.php?pending=' . rawurlencode($linkToken), true, 303);
                exit;
            }

            $destination = hopia_customer_complete_login(
                $result['customer'],
                $pending['destination'] ?? null
            );
            header('Location: ' . $destination, true, 303);
            exit;
        } catch (Throwable $e) {
            $errors['general'] = 'We could not create your account. Please try again.';
        }
    }
}

$page_title = 'Complete Your Profile - Hopia Fits';
$page_description = 'Complete the required details for your Hopia Fits customer account.';
$ui_active = 'register.php';
$body_class = 'register-page';

require_once __DIR__ . '/../includes/ui.php';
require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<section class="auth-page__content" aria-labelledby="google-profile-title">
    <div class="auth-card">
        <header class="auth-card__header">
            <p class="auth-card__eyebrow">Almost Done</p>
            <h1 id="google-profile-title">Complete Your Profile</h1>
            <p class="auth-card__subtitle">Google did not provide all required name details for <?= hopia_e($pending['email']) ?>.</p>
        </header>

        <?php if (isset($errors['general'])): ?>
            <p class="alert alert-error" role="alert"><?= hopia_e($errors['general']) ?></p>
        <?php endif; ?>

        <form class="auth-form" method="POST" novalidate>
            <input type="hidden" name="pending" value="<?= hopia_e($pendingToken) ?>">
            <input type="hidden" name="csrf_token" value="<?= hopia_e($pending['csrf']) ?>">

            <div class="auth-form__row">
                <div class="field">
                    <label class="field-label" for="google-first-name">First Name</label>
                    <input class="input <?= isset($errors['first_name']) ? 'is-error' : '' ?>" id="google-first-name" type="text" name="first_name" value="<?= hopia_e($firstName) ?>" maxlength="100" autocomplete="given-name" required>
                    <?php if (isset($errors['first_name'])): ?><p class="auth-form__error"><?= hopia_e($errors['first_name']) ?></p><?php endif; ?>
                </div>
                <div class="field">
                    <label class="field-label" for="google-last-name">Last Name</label>
                    <input class="input <?= isset($errors['last_name']) ? 'is-error' : '' ?>" id="google-last-name" type="text" name="last_name" value="<?= hopia_e($lastName) ?>" maxlength="100" autocomplete="family-name" required>
                    <?php if (isset($errors['last_name'])): ?><p class="auth-form__error"><?= hopia_e($errors['last_name']) ?></p><?php endif; ?>
                </div>
            </div>

            <button class="btn-auth-submit" type="submit">Create Customer Account</button>
        </form>

        <p class="auth-card__switch"><a href="register.php">Cancel and return</a></p>
    </div>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
