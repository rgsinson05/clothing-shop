<?php

// Google Sign-In is temporarily disabled due to hosting DNS resolution failures.
// Remove this guard and restore the login/register buttons to re-enable it.
header('Location: login.php', true, 303);
exit;

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/customer_auth.php';

$source = hopia_google_source($_GET['from'] ?? 'login');

try {
    $config = hopia_google_config();
    $attempt = hopia_google_store_attempt($source);
    $oauth = hopia_google_oauth_client($config, $attempt['state'], $attempt['code_verifier']);
    $authorizationUrl = (string) $oauth->buildFullAuthorizationUri([
        'access_type' => 'online',
        'prompt' => 'select_account',
        'nonce' => $attempt['nonce'],
    ]);

    header('Location: ' . $authorizationUrl, true, 302);
    exit;
} catch (Throwable $e) {
    error_log(
        hopia_google_failure_diagnostic('authorization_start', $e),
        ini_get('error_log') === '' ? 4 : 0
    );
    hopia_google_flash(
        'Continue with Google is not available right now. Please use your email and password.',
        $source
    );
    header('Location: ' . hopia_google_source_path($source), true, 303);
    exit;
}
