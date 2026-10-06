<?php

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/ui.php';

/*
 * Customer Account Center update endpoint.
 *
 * The Account Center's fetch() submits an application/x-www-form-urlencoded
 * POST, so the fields are read from $_POST exactly like every other form
 * endpoint in this project (login, register, cart, checkout). The response
 * is JSON. It handles both mutations the Account Center needs:
 *
 *   action=profile  - first_name, last_name, email, phone
 *   action=password - current_password, new_password, confirm_password
 *
 * Every business rule is enforced here; the front-end validation is never
 * trusted:
 *
 *   1. The customer must be authenticated (server-side session only).
 *   2. The CSRF token must match the session token (timing-safe compare).
 *   3. The authenticated customer is read from $_SESSION['customer_id'].
 *      No customer id is ever accepted from the browser, so customer A can
 *      never modify customer B.
 *   4. Input is validated server-side against the existing schema limits
 *      (first_name/last_name VARCHAR(100), phone VARCHAR(30)).
 *   5. A changed email requires the current password, and the existing
 *      UNIQUE email constraint is preserved. A duplicate is reported as a
 *      clean validation error, never as a silent merge or new account.
 *   6. Password change verifies the current password, keeps the existing
 *      8-character minimum, validates confirmation, and re-hashes with
 *      password_hash()/PASSWORD_DEFAULT exactly like customer/register.php.
 *
 * Password values are never echoed back in the response and are never
 * written to the session. No prepared statement ever carries a customer id
 * from the browser.
 */

function accountRespondJson(array $payload, int $status): void
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

/*
 * 1. Only POST reaches this handler. A direct URL access changes nothing.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    accountRespondJson([
        'ok' => false,
        'reason' => 'method',
        'message' => 'Account details must be submitted using the account form.',
    ], 405);
}

/*
 * 2. Authentication. Anonymous visitors cannot update any account row.
 */

if (!isset($_SESSION['customer_id'])) {
    accountRespondJson([
        'ok' => false,
        'reason' => 'auth',
        'message' => 'Your session has ended. Please sign in again.',
    ], 401);
}

/*
 * 3. CSRF verification. The token is rotated on every success so a
 * replayed request can never reuse it.
 */

$submittedToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($submittedToken)
    || $submittedToken === ''
    || empty($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $submittedToken)
) {
    accountRespondJson([
        'ok' => false,
        'reason' => 'csrf',
        'message' => 'Your form session has expired. Please try again.',
    ], 403);
}

$customerId = (int) $_SESSION['customer_id'];

/*
 * Read the authenticated customer's current record, including the stored
 * password hash, so email-confirmation and password checks compare against
 * the real row. Scoped to the session customer id.
 */

$currentStmt = $pdo->prepare(
    'SELECT id, first_name, last_name, email, phone, password_hash
     FROM customers
     WHERE id = :customer_id
     LIMIT 1'
);

$currentStmt->execute([':customer_id' => $customerId]);
$currentCustomer = $currentStmt->fetch();

if ($currentCustomer === false) {
    unset($_SESSION['customer_id'], $_SESSION['customer_name']);

    accountRespondJson([
        'ok' => false,
        'reason' => 'not_found',
        'message' => 'We could not find your account.',
    ], 404);
}

$currentHash = (string) $currentCustomer['password_hash'];

$action = isset($_POST['action']) && is_string($_POST['action'])
    ? strtolower(trim($_POST['action']))
    : '';

$actionErrors = [];

/*
 * POST value reader. Anything that is not a string is treated as absent so a
 * crafted array value can never reach strlen()/trim().
 */

function accountPostValue(string $key): string
{
    if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
        return '';
    }

    return trim($_POST[$key]);
}

/*
 * ------------------------------------------------------------------
 * 4. Password change
 * ------------------------------------------------------------------
 */

if ($action === 'password') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    /*
     * Passwords are not trimmed: leading and trailing spaces are valid
     * password characters and silently stripping them would change the
     * value the customer thinks they are setting.
     */

    if (!is_string($currentPassword) || $currentPassword === '') {
        $actionErrors['current_password'] = 'Enter your current password.';
    } elseif (!password_verify($currentPassword, $currentHash)) {
        $actionErrors['current_password'] = 'That current password is incorrect.';
    }

    if (!is_string($newPassword) || $newPassword === '') {
        $actionErrors['new_password'] = 'Enter a new password.';
    } elseif (strlen($newPassword) < 8) {
        $actionErrors['new_password'] = 'Use at least 8 characters.';
    } elseif ($newPassword === (string) $currentPassword) {
        $actionErrors['new_password'] = 'Choose a password you have not used before.';
    }

    if (!is_string($confirmPassword) || $confirmPassword === '') {
        $actionErrors['confirm_password'] = 'Confirm your new password.';
    } elseif (is_string($newPassword) && $confirmPassword !== $newPassword) {
        $actionErrors['confirm_password'] = 'Passwords do not match.';
    }

    if ($actionErrors === []) {
        $updateStmt = $pdo->prepare(
            'UPDATE customers
             SET password_hash = :password_hash
             WHERE id = :customer_id'
        );

        $updateStmt->execute([
            ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':customer_id' => $customerId,
        ]);
    }

    if ($actionErrors !== []) {
        accountRespondJson([
            'ok' => false,
            'reason' => 'validation',
            'message' => $actionErrors[array_key_first($actionErrors)],
            'errors' => $actionErrors,
        ], 422);
    }

    /*
     * Rotate the CSRF token on success, matching the security posture of
     * customer/login.php regenerating the session id. The customer session
     * itself is intentionally preserved: this project has no server-side
     * password fingerprint, so regenerating the session id would sign the
     * customer out after a change they just proved they own.
     */

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    accountRespondJson([
        'ok' => true,
        'reason' => 'password_updated',
        'message' => 'Your password has been updated.',
        'csrf_token' => $_SESSION['csrf_token'],
    ], 200);
}

/*
 * Only 'profile' and 'password' are supported. Reaching this point means
 * the action was not 'password' (that branch exits above), so anything
 * other than 'profile' is a malformed request and is rejected cleanly
 * instead of being silently treated as a personal-information save. This
 * is a 400, kept distinct from the 403 used for a failed CSRF check and
 * the 401 used for an ended session.
 */

if ($action !== 'profile') {
    accountRespondJson([
        'ok' => false,
        'reason' => 'action',
        'message' => 'That account action is not supported.',
    ], 400);
}

/*
 * ------------------------------------------------------------------
 * 5. Personal information update
 * ------------------------------------------------------------------
 */

$firstName = accountPostValue('first_name');
$lastName = accountPostValue('last_name');
$email = accountPostValue('email');
$phone = accountPostValue('phone');

if ($firstName === '') {
    $actionErrors['first_name'] = 'First name is required.';
} elseif (mb_strlen($firstName, 'UTF-8') > 100) {
    $actionErrors['first_name'] = 'First name must be 100 characters or fewer.';
}

if ($lastName === '') {
    $actionErrors['last_name'] = 'Last name is required.';
} elseif (mb_strlen($lastName, 'UTF-8') > 100) {
    $actionErrors['last_name'] = 'Last name must be 100 characters or fewer.';
}

if ($email === '') {
    $actionErrors['email'] = 'Email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $actionErrors['email'] = 'Please enter a valid email address.';
} elseif (mb_strlen($email, 'UTF-8') > 255) {
    $actionErrors['email'] = 'Email must be 255 characters or fewer.';
}

/*
 * Phone stays optional, matching customer/register.php. The existing
 * checkout field limit is 30 characters (orders.shipping_phone and
 * customers.phone are both VARCHAR(30)), so the same limit is enforced
 * here.
 */

if ($phone !== '' && !preg_match('/^[0-9+()\-.\s]+$/', $phone)) {
    $actionErrors['phone'] = 'Use numbers only, with + ( ) - or spaces.';
} elseif (mb_strlen($phone, 'UTF-8') > 30) {
    $actionErrors['phone'] = 'Phone number must be 30 characters or fewer.';
}

/*
 * Email is an account-identity field. A change is only accepted when the
 * customer proves ownership of the account by entering the current
 * password. The submitted value is never used to look up or merge another
 * account.
 */

$emailChanged = strcasecmp($email, (string) $currentCustomer['email']) !== 0;

if ($emailChanged && $actionErrors === []) {
    $confirmPassword = $_POST['current_password'] ?? '';

    if (!is_string($confirmPassword) || $confirmPassword === '') {
        $actionErrors['current_password'] = 'Enter your current password to change your email.';
    } elseif (!password_verify($confirmPassword, $currentHash)) {
        $actionErrors['current_password'] = 'That current password is incorrect.';
    } else {
        /*
         * 6. Enforce the existing UNIQUE email constraint before the
         * update so the customer gets a readable message instead of a
         * database error. The UNIQUE index remains the real backstop and
         * is still caught below.
         */

        $emailOwnerStmt = $pdo->prepare(
            'SELECT id
             FROM customers
             WHERE email = :email
               AND id <> :customer_id
             LIMIT 1'
        );

        $emailOwnerStmt->execute([
            ':email' => $email,
            ':customer_id' => $customerId,
        ]);

        if ($emailOwnerStmt->fetch() !== false) {
            $actionErrors['email'] = 'That email address is already registered.';
        }
    }
}

if ($actionErrors !== []) {
    accountRespondJson([
        'ok' => false,
        'reason' => 'validation',
        'message' => $actionErrors[array_key_first($actionErrors)],
        'errors' => $actionErrors,
    ], 422);
}

try {
    /*
     * Only the authenticated customer's own row is written, identified by
     * the server-side session id. The browser never supplies a customer id.
     */

    $updateStmt = $pdo->prepare(
        'UPDATE customers
         SET first_name = :first_name,
             last_name = :last_name,
             email = :email,
             phone = :phone
         WHERE id = :customer_id'
    );

    $updateStmt->execute([
        ':first_name' => $firstName,
        ':last_name' => $lastName,
        ':email' => $email,
        ':phone' => $phone === '' ? null : $phone,
        ':customer_id' => $customerId,
    ]);
} catch (PDOException $e) {
    /*
     * The UNIQUE(email) index is the authoritative guard. A concurrent
     * registration that claimed the address between the check above and
     * this update is reported as a clean duplicate error. No SQL detail,
     * stack trace, or credential is ever exposed.
     */

    if ($e->getCode() === '23000') {
        accountRespondJson([
            'ok' => false,
            'reason' => 'validation',
            'message' => 'That email address is already registered.',
            'errors' => ['email' => 'That email address is already registered.'],
        ], 422);
    }

    error_log('Customer account update failed: ' . $e->getMessage());

    accountRespondJson([
        'ok' => false,
        'reason' => 'error',
        'message' => 'Something went wrong. Please try again later.',
    ], 500);
}

/*
 * Keep the session's cached first name in step with the record, so the
 * shared header greeting and this page never disagree. The session id is
 * preserved: the customer stays signed in.
 */

$_SESSION['customer_name'] = $firstName;
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

accountRespondJson([
    'ok' => true,
    'reason' => 'profile_updated',
    'message' => 'Your details have been updated.',
    'csrf_token' => $_SESSION['csrf_token'],
    'profile' => [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'phone' => $phone,
        'full_name' => trim($firstName . ' ' . $lastName),
        'initials' => hopia_account_initials($firstName, $lastName),
    ],
], 200);
