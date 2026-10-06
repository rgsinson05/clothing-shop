<?php

session_start();

require_once __DIR__ . '/../includes/database.php';

if (isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, name, password_hash
             FROM admins
             WHERE email = :email
             LIMIT 1'
        );

        $stmt->execute([
            'email' => $email
        ]);

        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);

            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];

            header('Location: index.php');
            exit;
        }

        $error = 'Invalid email or password.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f5f4f1">
    <meta name="description" content="Sign in to manage Hopia Fits.">
    <title>Admin Login - Hopia's Ukay-Ukay</title>
    <link rel="stylesheet" href="../css/styles.css">
</head>
<body class="admin-login-page">

    <main class="admin-login" id="site-main">
        <div class="admin-login__card">

            <div class="auth-card__lockup admin-login__lockup">
                <img
                    class="auth-card__logo admin-login__logo"
                    src="../assets/videos/pictures/Hopia-fits-logo.webp"
                    alt="Hopia Fits"
                    width="44"
                    height="44"
                >
            </div>

            <header class="auth-card__header">
                <p class="auth-card__eyebrow">Admin Portal</p>
                <h1>Administrator Sign In</h1>
                <p class="auth-card__subtitle">Sign in to manage Hopia Fits.</p>
            </header>

            <?php if ($error !== ''): ?>
                <p class="alert alert-error admin-login__alert" role="alert"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <form class="auth-form" method="POST">

                <div class="field">
                    <label class="field-label" for="email">
                        <svg class="field-label__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3.5" y="5.5" width="17" height="13" rx="1.5"></rect>
                            <path d="m4.5 7 7.5 6 7.5-6"></path>
                        </svg>
                        Email
                    </label>
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
                            <svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path class="eye-open" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle class="eye-open" cx="12" cy="12" r="3"/>
                                <line class="eye-closed" x1="1" y1="1" x2="23" y2="23" style="display:none"/>
                                <path class="eye-closed" d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" style="display:none"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button class="btn-auth-submit admin-login__submit" type="submit">
                    Sign In
                    <svg class="btn-auth-submit__arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="4" y1="12" x2="20" y2="12"></line>
                        <polyline points="14 6 20 12 14 18"></polyline>
                    </svg>
                </button>

            </form>

            <p class="admin-login__note">Authorized administrators only.</p>

        </div>
    </main>

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

</body>
</html>