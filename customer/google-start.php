<?php

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
    hopia_google_flash(
        'Continue with Google is not available right now. Please use your email and password.',
        $source
    );
    header('Location: ' . hopia_google_source_path($source), true, 303);
    exit;
}
