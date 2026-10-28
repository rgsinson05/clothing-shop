<?php

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/customer_auth.php';

$attempt = hopia_google_take_attempt($_GET['state'] ?? null);
$source = hopia_google_source($attempt['source'] ?? 'login');

if ($attempt === null) {
    hopia_google_flash('Your Google sign-in session expired. Please try again.', $source);
    header('Location: ' . hopia_google_source_path($source), true, 303);
    exit;
}

if (isset($_GET['error'])) {
    $message = $_GET['error'] === 'access_denied'
        ? 'Google sign-in was cancelled. You can try again or use your password.'
        : 'Google could not complete sign-in. Please try again.';
    hopia_google_flash($message, $source);
    header('Location: ' . hopia_google_source_path($source), true, 303);
    exit;
}

$code = $_GET['code'] ?? null;
if (!is_string($code) || $code === '') {
    hopia_google_flash('Google could not complete sign-in. Please try again.', $source);
    header('Location: ' . hopia_google_source_path($source), true, 303);
    exit;
}

$failureStage = 'configuration';

try {
    $config = hopia_google_config();
    $oauth = hopia_google_oauth_client($config, null, $attempt['code_verifier']);
    $oauth->setCode($code);
    $failureStage = 'token_exchange';
    $tokens = $oauth->fetchAuthToken();
    $idToken = $tokens['id_token'] ?? null;

    $failureStage = 'id_token_response';
    if (!is_string($idToken) || $idToken === '') {
        throw new UnexpectedValueException('The token response did not contain an ID token.');
    }

    $failureStage = 'id_token_validation';
    $identity = hopia_google_validate_id_token($idToken, $config, $attempt['nonce']);
    $failureStage = 'identity_lookup';
    $linkedCustomer = hopia_google_identity_customer($pdo, $identity['provider_subject']);

    if ($linkedCustomer !== null) {
        $destination = hopia_customer_complete_login(
            $linkedCustomer,
            $attempt['destination'] ?? null
        );
        header('Location: ' . $destination, true, 303);
        exit;
    }

    $failureStage = 'email_lookup';
    if (hopia_google_email_customer($pdo, $identity['email']) !== null) {
        $pendingToken = hopia_google_store_pending($identity, $attempt, 'link');
        header('Location: google-link.php?pending=' . rawurlencode($pendingToken), true, 303);
        exit;
    }

    if ($identity['first_name'] === '' || $identity['last_name'] === '') {
        $pendingToken = hopia_google_store_pending($identity, $attempt, 'profile');
        header('Location: google-complete-profile.php?pending=' . rawurlencode($pendingToken), true, 303);
        exit;
    }

    $failureStage = 'customer_creation';
    $result = hopia_google_create_customer($pdo, $identity);
    if ($result['status'] === 'link') {
        $pendingToken = hopia_google_store_pending($identity, $attempt, 'link');
        header('Location: google-link.php?pending=' . rawurlencode($pendingToken), true, 303);
        exit;
    }

    $destination = hopia_customer_complete_login(
        $result['customer'],
        $attempt['destination'] ?? null
    );
    header('Location: ' . $destination, true, 303);
    exit;
} catch (Throwable $e) {
    $diagnostic = sprintf(
        'Google OAuth callback failed at %s: %s (%s)',
        $failureStage,
        get_class($e),
        (string) $e->getCode()
    );

    if ($failureStage === 'token_exchange'
        && $e instanceof GuzzleHttp\Exception\ConnectException) {
        $curlErrno = null;
        $connectionError = null;

        if (method_exists($e, 'getHandlerContext')) {
            $handlerContext = $e->getHandlerContext();
            if (is_array($handlerContext)) {
                $curlErrno = is_int($handlerContext['errno'] ?? null)
                    ? $handlerContext['errno']
                    : null;
                $connectionError = is_string($handlerContext['error'] ?? null)
                    ? $handlerContext['error']
                    : null;
            }
        }

        if ($curlErrno === null || $connectionError === null) {
            $matched = preg_match(
                '/\AcURL error ([0-9]+): (.*?)'
                    . ' \(see https:\/\/curl\.se\/libcurl\/c\/libcurl-errors\.html\)'
                    . '(?: for .*)?\z/s',
                $e->getMessage(),
                $connectionMatches
            );
            if ($matched === 1) {
                $curlErrno = (int) $connectionMatches[1];
                $connectionError = $connectionMatches[2];
            }
        }

        if (is_string($connectionError)) {
            $connectionError = preg_replace(
                [
                    '/https?:\/\/\S+/i',
                    '/\b(code|client_secret|access_token|id_token|refresh_token|code_verifier|session_id)\s*[:=]\s*\S+/i',
                    '/[\x00-\x1F\x7F]+/',
                ],
                ['[redacted-url]', '$1=[redacted]', ' '],
                $connectionError
            );
            $connectionError = is_string($connectionError)
                ? substr(trim($connectionError), 0, 500)
                : null;
        }

        $diagnostic .= sprintf(
            '; curl_errno=%s; connection_error=%s',
            $curlErrno === null ? 'unavailable' : (string) $curlErrno,
            $connectionError === null || $connectionError === ''
                ? 'unavailable'
                : $connectionError
        );
    }

    error_log($diagnostic, 4);
    hopia_google_flash(
        'We could not verify your Google account. Please try again or use your password.',
        $source
    );
    header('Location: ' . hopia_google_source_path($source), true, 303);
    exit;
}
