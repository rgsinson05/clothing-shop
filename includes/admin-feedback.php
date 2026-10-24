<?php

// Admin-only, one-use feedback. Call the redirect helper only after success/commit.
function hopia_admin_success_clear(): void
{
    unset($_SESSION['admin_success']);
}

function hopia_admin_success_redirect(string $destination, string $message): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['admin_success'] = [
        'token' => $token,
        'destination' => $destination,
        'message' => $message,
        'admin_id' => (int) ($_SESSION['admin_id'] ?? 0),
        'expires_at' => time() + 300,
    ];

    return $destination . (strpos($destination, '?') === false ? '?' : '&') . 'admin_success=' . $token;
}

function hopia_admin_success_take(): string
{
    $flash = $_SESSION['admin_success'] ?? null;
    // Consume even on a different page or failed POST; never keep old feedback.
    hopia_admin_success_clear();
    $token = $_GET['admin_success'] ?? null;
    if (!is_array($flash)
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
        || empty($_SESSION['admin_id'])
        || (int) ($flash['admin_id'] ?? 0) !== (int) $_SESSION['admin_id']
        || (int) ($flash['expires_at'] ?? 0) < time()
        || !is_string($token)
        || !is_string($flash['token'] ?? null)
        || !hash_equals($flash['token'], $token)
    ) {
        return '';
    }

    $destination = parse_url((string) ($flash['destination'] ?? ''));
    if (!$destination
        || basename($destination['path'] ?? '') !== basename($_SERVER['SCRIPT_NAME'] ?? '')
    ) {
        return '';
    }

    // Bind feedback to the specific order/receipt, not just the page filename.
    parse_str($destination['query'] ?? '', $expectedQuery);
    foreach ($expectedQuery as $key => $value) {
        if (!is_string($value) || ($_GET[$key] ?? null) !== $value) {
            return '';
        }
    }

    return is_string($flash['message'] ?? null) ? $flash['message'] : '';
}
