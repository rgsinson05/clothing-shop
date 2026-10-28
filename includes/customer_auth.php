<?php

use Google\Auth\AccessToken;
use Google\Auth\OAuth2;

const HOPIA_GOOGLE_ATTEMPT_TTL = 600;
const HOPIA_GOOGLE_PENDING_TTL = 600;

function hopia_customer_safe_destination(?string $destination): ?string
{
    $allowed = [
        'cart.php',
        'checkout.php',
        'account.php',
        'orders.php',
    ];

    return is_string($destination) && in_array($destination, $allowed, true)
        ? $destination
        : null;
}

function hopia_customer_set_intended_destination(string $destination): void
{
    $safeDestination = hopia_customer_safe_destination($destination);
    if ($safeDestination === null) {
        return;
    }

    $_SESSION['customer_login_destination'] = [
        'path' => $safeDestination,
        'created_at' => time(),
    ];
}

function hopia_customer_intended_destination(): ?string
{
    $context = $_SESSION['customer_login_destination'] ?? null;
    if (!is_array($context)
        || (int) ($context['created_at'] ?? 0) < time() - 1800) {
        unset($_SESSION['customer_login_destination']);
        return null;
    }

    $destination = hopia_customer_safe_destination($context['path'] ?? null);
    if ($destination === null) {
        unset($_SESSION['customer_login_destination']);
    }

    return $destination;
}

function hopia_customer_complete_login(array $customer, ?string $destination = null): string
{
    session_regenerate_id(true);

    foreach (array_keys($_SESSION) as $sessionKey) {
        if (is_string($sessionKey) && str_starts_with($sessionKey, 'admin_')) {
            unset($_SESSION[$sessionKey]);
        }
    }

    $_SESSION['customer_id'] = (int) $customer['id'];
    $_SESSION['customer_name'] = (string) $customer['first_name'];

    $pendingToken = $_SESSION['buy_now_pending_login'] ?? null;
    $buyNowContext = $_SESSION['buy_now_checkout'] ?? null;
    $safeDestination = hopia_customer_safe_destination($destination)
        ?? hopia_customer_intended_destination();

    unset(
        $_SESSION['buy_now_pending_login'],
        $_SESSION['csrf_token_login'],
        $_SESSION['customer_login_destination'],
        $_SESSION['google_oauth_attempts'],
        $_SESSION['google_pending_identities']
    );

    if (is_string($pendingToken) && is_array($buyNowContext)
        && is_string($buyNowContext['token'] ?? null)
        && hash_equals($buyNowContext['token'], $pendingToken)
        && ($buyNowContext['customer_id'] ?? null) === null
        && (int) ($buyNowContext['created_at'] ?? 0) >= time() - 1800) {
        $_SESSION['buy_now_checkout']['customer_id'] = (int) $customer['id'];
        return 'checkout.php?buy_now=' . rawurlencode($pendingToken);
    }

    return $safeDestination ?? '../index.php';
}

function hopia_google_source($source): string
{
    return $source === 'register' ? 'register' : 'login';
}

function hopia_google_source_path(string $source): string
{
    return hopia_google_source($source) === 'register' ? 'register.php' : 'login.php';
}

function hopia_google_flash(string $message, string $source = 'login'): void
{
    $_SESSION['google_auth_flash'] = [
        'message' => $message,
        'source' => hopia_google_source($source),
    ];
}

function hopia_google_take_flash(string $source): ?string
{
    $flash = $_SESSION['google_auth_flash'] ?? null;
    if (!is_array($flash) || ($flash['source'] ?? null) !== hopia_google_source($source)) {
        return null;
    }

    unset($_SESSION['google_auth_flash']);
    return is_string($flash['message'] ?? null) ? $flash['message'] : null;
}

function hopia_google_button(string $source): void
{
    $source = hopia_google_source($source);
    ?>
    <a class="google-auth-button" href="google-start.php?from=<?= htmlspecialchars($source, ENT_QUOTES, 'UTF-8') ?>">
        <svg class="google-auth-button__icon" width="20" height="20" viewBox="0 0 18 18" aria-hidden="true" focusable="false">
            <path fill="#4285F4" d="M17.64 9.205c0-.638-.057-1.252-.164-1.841H9v3.482h4.844a4.14 4.14 0 0 1-1.797 2.716v2.258h2.909c1.703-1.568 2.684-3.879 2.684-6.615Z"/>
            <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.909-2.258c-.806.54-1.835.859-3.047.859-2.344 0-4.328-1.584-5.037-3.711H.956v2.332A9 9 0 0 0 9 18Z"/>
            <path fill="#FBBC05" d="M3.963 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.281-1.71V4.958H.956A9 9 0 0 0 0 9c0 1.452.347 2.827.956 4.042l3.007-2.332Z"/>
            <path fill="#EA4335" d="M9 3.579c1.321 0 2.507.454 3.441 1.346l2.581-2.581C13.463.892 11.426 0 9 0A9 9 0 0 0 .956 4.958L3.963 7.29C4.672 5.163 6.656 3.579 9 3.579Z"/>
        </svg>
        <span>Continue with Google</span>
    </a>
    <?php
}

function hopia_google_config(): array
{
    $clientId = trim((string) ($_ENV['GOOGLE_OAUTH_CLIENT_ID'] ?? ''));
    $clientSecret = trim((string) ($_ENV['GOOGLE_OAUTH_CLIENT_SECRET'] ?? ''));
    $redirectUri = trim((string) ($_ENV['GOOGLE_OAUTH_REDIRECT_URI'] ?? ''));

    $parts = parse_url($redirectUri);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $isLocalHttp = ($parts['scheme'] ?? '') === 'http'
        && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    $isHttps = ($parts['scheme'] ?? '') === 'https';

    if ($clientId === '' || $clientSecret === '' || $redirectUri === ''
        || (!$isHttps && !$isLocalHttp)
        || !isset($parts['path'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        throw new RuntimeException('Google authentication is not configured.');
    }

    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
    ];
}

function hopia_google_oauth_client(
    array $config,
    ?string $state = null,
    ?string $codeVerifier = null
): OAuth2 {
    return new OAuth2([
        'authorizationUri' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'tokenCredentialUri' => 'https://oauth2.googleapis.com/token',
        'redirectUri' => $config['redirect_uri'],
        'clientId' => $config['client_id'],
        'clientSecret' => $config['client_secret'],
        'scope' => ['openid', 'email', 'profile'],
        'state' => $state,
        'codeVerifier' => $codeVerifier,
    ]);
}

function hopia_base64url_random(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function hopia_google_store_attempt(string $source): array
{
    $now = time();
    $attempts = $_SESSION['google_oauth_attempts'] ?? [];
    $attempts = is_array($attempts) ? $attempts : [];

    foreach ($attempts as $attemptState => $attempt) {
        if (!is_array($attempt)
            || (int) ($attempt['created_at'] ?? 0) < $now - HOPIA_GOOGLE_ATTEMPT_TTL) {
            unset($attempts[$attemptState]);
        }
    }

    while (count($attempts) >= 5) {
        array_shift($attempts);
    }

    $state = hopia_base64url_random(32);
    $attempt = [
        'state' => $state,
        'nonce' => hopia_base64url_random(32),
        'code_verifier' => hopia_base64url_random(64),
        'source' => hopia_google_source($source),
        'destination' => hopia_customer_intended_destination(),
        'created_at' => $now,
    ];

    $attempts[$state] = $attempt;
    $_SESSION['google_oauth_attempts'] = $attempts;

    return $attempt;
}

function hopia_google_take_attempt($returnedState): ?array
{
    if (!is_string($returnedState) || $returnedState === '') {
        return null;
    }

    $attempts = $_SESSION['google_oauth_attempts'] ?? [];
    if (!is_array($attempts) || !isset($attempts[$returnedState])
        || !is_array($attempts[$returnedState])) {
        return null;
    }

    $attempt = $attempts[$returnedState];
    unset($attempts[$returnedState]);
    $_SESSION['google_oauth_attempts'] = $attempts;

    if ((int) ($attempt['created_at'] ?? 0) < time() - HOPIA_GOOGLE_ATTEMPT_TTL
        || !is_string($attempt['state'] ?? null)
        || !hash_equals($attempt['state'], $returnedState)) {
        return null;
    }

    return $attempt;
}

function hopia_google_validate_id_token(
    string $idToken,
    array $config,
    string $expectedNonce
): array {
    $claims = (new AccessToken())->verify($idToken, [
        'audience' => $config['client_id'],
        'throwException' => true,
    ]);

    if (!is_array($claims)
        || !is_string($claims['nonce'] ?? null)
        || !hash_equals($expectedNonce, $claims['nonce'])
        || ($claims['email_verified'] ?? null) !== true
        || !is_string($claims['sub'] ?? null)
        || $claims['sub'] === ''
        || strlen($claims['sub']) > 255
        || !is_string($claims['email'] ?? null)
        || !filter_var($claims['email'], FILTER_VALIDATE_EMAIL)
        || strlen($claims['email']) > 255
        || (int) ($claims['exp'] ?? 0) <= time()
        || (int) ($claims['iat'] ?? 0) > time() + 300) {
        throw new UnexpectedValueException('The Google identity claims are invalid.');
    }

    $firstName = is_string($claims['given_name'] ?? null)
        ? trim($claims['given_name'])
        : '';
    $lastName = is_string($claims['family_name'] ?? null)
        ? trim($claims['family_name'])
        : '';

    return [
        'provider_subject' => $claims['sub'],
        'email' => strtolower($claims['email']),
        'first_name' => mb_strlen($firstName, 'UTF-8') <= 100 ? $firstName : '',
        'last_name' => mb_strlen($lastName, 'UTF-8') <= 100 ? $lastName : '',
    ];
}

function hopia_google_identity_customer(PDO $pdo, string $providerSubject): ?array
{
    $stmt = $pdo->prepare(
        "SELECT c.id, c.first_name, c.last_name, c.email
         FROM customer_identities ci
         INNER JOIN customers c ON c.id = ci.customer_id
         WHERE ci.provider = 'google'
           AND ci.provider_subject = :provider_subject
         LIMIT 1"
    );
    $stmt->execute([':provider_subject' => $providerSubject]);
    $customer = $stmt->fetch();

    return $customer === false ? null : $customer;
}

function hopia_google_email_customer(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, first_name, last_name, email, password_hash
         FROM customers
         WHERE email = :email
         LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $customer = $stmt->fetch();

    return $customer === false ? null : $customer;
}

function hopia_google_create_customer(PDO $pdo, array $identity): array
{
    try {
        $pdo->beginTransaction();

        $linked = hopia_google_identity_customer($pdo, $identity['provider_subject']);
        if ($linked !== null) {
            $pdo->commit();
            return ['status' => 'customer', 'customer' => $linked];
        }

        $emailCustomer = hopia_google_email_customer($pdo, $identity['email']);
        if ($emailCustomer !== null) {
            $pdo->rollBack();
            return ['status' => 'link'];
        }

        $customerStmt = $pdo->prepare(
            'INSERT INTO customers (first_name, last_name, email, password_hash, phone)
             VALUES (:first_name, :last_name, :email, NULL, NULL)'
        );
        $customerStmt->execute([
            ':first_name' => $identity['first_name'],
            ':last_name' => $identity['last_name'],
            ':email' => $identity['email'],
        ]);
        $customerId = (int) $pdo->lastInsertId();

        $identityStmt = $pdo->prepare(
            "INSERT INTO customer_identities (customer_id, provider, provider_subject)
             VALUES (:customer_id, 'google', :provider_subject)"
        );
        $identityStmt->execute([
            ':customer_id' => $customerId,
            ':provider_subject' => $identity['provider_subject'],
        ]);

        $pdo->commit();
        return [
            'status' => 'customer',
            'customer' => [
                'id' => $customerId,
                'first_name' => $identity['first_name'],
                'last_name' => $identity['last_name'],
                'email' => $identity['email'],
            ],
        ];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e->getCode() === '23000') {
            $linked = hopia_google_identity_customer($pdo, $identity['provider_subject']);
            if ($linked !== null) {
                return ['status' => 'customer', 'customer' => $linked];
            }
            if (hopia_google_email_customer($pdo, $identity['email']) !== null) {
                return ['status' => 'link'];
            }
        }

        throw $e;
    }
}

function hopia_google_store_pending(array $identity, array $attempt, string $purpose): string
{
    $now = time();
    $pendingIdentities = $_SESSION['google_pending_identities'] ?? [];
    $pendingIdentities = is_array($pendingIdentities) ? $pendingIdentities : [];

    foreach ($pendingIdentities as $pendingToken => $pendingIdentity) {
        if (!is_array($pendingIdentity)
            || (int) ($pendingIdentity['created_at'] ?? 0) < $now - HOPIA_GOOGLE_PENDING_TTL) {
            unset($pendingIdentities[$pendingToken]);
        }
    }

    while (count($pendingIdentities) >= 5) {
        array_shift($pendingIdentities);
    }

    $pendingToken = hopia_base64url_random(32);
    $pendingIdentities[$pendingToken] = [
        'provider_subject' => $identity['provider_subject'],
        'email' => $identity['email'],
        'first_name' => $identity['first_name'],
        'last_name' => $identity['last_name'],
        'purpose' => $purpose,
        'source' => hopia_google_source($attempt['source'] ?? 'login'),
        'destination' => hopia_customer_safe_destination($attempt['destination'] ?? null),
        'csrf' => bin2hex(random_bytes(32)),
        'created_at' => $now,
    ];
    $_SESSION['google_pending_identities'] = $pendingIdentities;

    return $pendingToken;
}

function hopia_google_pending(string $purpose, $pendingToken): ?array
{
    if (!is_string($pendingToken) || $pendingToken === '') {
        return null;
    }

    $pendingIdentities = $_SESSION['google_pending_identities'] ?? [];
    $pending = is_array($pendingIdentities) ? ($pendingIdentities[$pendingToken] ?? null) : null;
    if (!is_array($pending)
        || ($pending['purpose'] ?? null) !== $purpose
        || (int) ($pending['created_at'] ?? 0) < time() - HOPIA_GOOGLE_PENDING_TTL
        || !is_string($pending['provider_subject'] ?? null)
        || !is_string($pending['email'] ?? null)) {
        unset($_SESSION['google_pending_identities'][$pendingToken]);
        return null;
    }

    return $pending;
}

function hopia_google_pending_csrf_valid(array $pending, $submitted): bool
{
    $stored = $pending['csrf'] ?? null;
    return is_string($submitted) && is_string($stored) && $stored !== ''
        && hash_equals($stored, $submitted);
}
