<?php

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

const HOPIA_PASSWORD_RESET_TTL_SECONDS = 3600;
const HOPIA_PASSWORD_RESET_COOLDOWN_SECONDS = 60;
const HOPIA_PASSWORD_RESET_HOURLY_LIMIT = 5;

function hopia_password_reset_config(): array
{
    $host = trim((string) ($_ENV['BREVO_SMTP_HOST'] ?? ''));
    $port = filter_var($_ENV['BREVO_SMTP_PORT'] ?? null, FILTER_VALIDATE_INT);
    $login = trim((string) ($_ENV['BREVO_SMTP_LOGIN'] ?? ''));
    $key = (string) ($_ENV['BREVO_SMTP_KEY'] ?? '');
    $senderEmail = trim((string) ($_ENV['BREVO_SENDER_EMAIL'] ?? ''));
    $senderName = trim((string) ($_ENV['BREVO_SENDER_NAME'] ?? ''));
    $baseUrl = rtrim(trim((string) ($_ENV['APP_BASE_URL'] ?? '')), '/');

    if ($host === '' || $port === false || $port < 1 || $port > 65535
        || $login === '' || $key === '' || $senderName === ''
        || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Password reset email is not configured.');
    }

    $url = parse_url($baseUrl);
    if (!is_array($url)) {
        throw new RuntimeException('The application base URL is not configured securely.');
    }

    $scheme = strtolower((string) ($url['scheme'] ?? ''));
    $hostName = strtolower((string) ($url['host'] ?? ''));
    $path = (string) ($url['path'] ?? '');
    $isLocalHttp = $scheme === 'http'
        && in_array($hostName, ['localhost', '127.0.0.1', '::1'], true);

    if ($baseUrl === '' || ($scheme !== 'https' && !$isLocalHttp)
        || $hostName === '' || ($path !== '' && $path !== '/')
        || isset($url['user']) || isset($url['pass'])
        || isset($url['query']) || isset($url['fragment'])) {
        throw new RuntimeException('The application base URL is not configured securely.');
    }

    return [
        'host' => $host,
        'port' => (int) $port,
        'login' => $login,
        'key' => $key,
        'sender_email' => $senderEmail,
        'sender_name' => $senderName,
        'base_url' => $baseUrl,
    ];
}

function hopia_password_reset_token_hash(string $token): ?string
{
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
        return null;
    }

    return hash('sha256', $token);
}

function hopia_password_reset_find(PDO $pdo, string $token, bool $forUpdate = false): ?array
{
    $tokenHash = hopia_password_reset_token_hash($token);
    if ($tokenHash === null) {
        return null;
    }

    $sql =
        'SELECT pr.id, pr.customer_id, pr.token_hash, pr.expires_at
         FROM customer_password_resets pr
         INNER JOIN customers c ON c.id = pr.customer_id
         WHERE pr.token_hash = :token_hash
           AND pr.sent_at IS NOT NULL
           AND pr.used_at IS NULL
           AND pr.expires_at > CURRENT_TIMESTAMP
           AND c.password_hash IS NOT NULL
           AND c.password_hash <> \'\'
         LIMIT 1';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':token_hash' => $tokenHash]);
    $reset = $stmt->fetch();

    if ($reset === false || !hash_equals((string) $reset['token_hash'], $tokenHash)) {
        return null;
    }

    return $reset;
}

function hopia_password_reset_send_mail(array $config, array $customer, string $token): void
{
    $resetUrl = $config['base_url'] . '/customer/reset-password.php?token=' . rawurlencode($token);
    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeName = htmlspecialchars((string) $customer['first_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $config['host'];
    $mail->Port = $config['port'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['login'];
    $mail->Password = $config['key'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Timeout = 15;
    $mail->getSMTPInstance()->Timelimit = 20;
    $mail->CharSet = PHPMailer::CHARSET_UTF8;

    $mail->setFrom($config['sender_email'], $config['sender_name']);
    $mail->addAddress((string) $customer['email'], (string) $customer['first_name']);
    $mail->isHTML(true);
    $mail->Subject = 'Reset Your Hopia Fits Password';
    $mail->Body = '<p>Hello ' . $safeName . ',</p>'
        . '<p>We received a request to reset your Hopia Fits password.</p>'
        . '<p><a href="' . $safeUrl . '">Reset your password</a></p>'
        . '<p>If the button does not work, open this link:<br><a href="' . $safeUrl . '">' . $safeUrl . '</a></p>'
        . '<p>This link expires in 60 minutes and can be used only once.</p>'
        . '<p>If you did not request this reset, you can safely ignore this email.</p>';
    $mail->AltBody = "Hello " . (string) $customer['first_name'] . ",\n\n"
        . "We received a request to reset your Hopia Fits password.\n\n"
        . "Reset your password: " . $resetUrl . "\n\n"
        . "This link expires in 60 minutes and can be used only once.\n"
        . "If you did not request this reset, you can safely ignore this email.";

    if (!$mail->send()) {
        throw new MailException('SMTP did not accept the password reset message.');
    }
}

function hopia_password_reset_discard(PDO $pdo, int $resetId): void
{
    try {
        $stmt = $pdo->prepare(
            'UPDATE customer_password_resets
             SET used_at = COALESCE(used_at, CURRENT_TIMESTAMP)
             WHERE id = :id'
        );
        $stmt->execute([':id' => $resetId]);
    } catch (Throwable $ignored) {
        // A token without sent_at remains unusable even if cleanup fails.
    }
}

function hopia_password_reset_activate(PDO $pdo, int $resetId, int $customerId): bool
{
    try {
        $pdo->beginTransaction();

        $customerLock = $pdo->prepare('SELECT id FROM customers WHERE id = :id FOR UPDATE');
        $customerLock->execute([':id' => $customerId]);
        if ($customerLock->fetch() === false) {
            $pdo->rollBack();
            return false;
        }

        $latestStmt = $pdo->prepare(
            'SELECT id
             FROM customer_password_resets
             WHERE customer_id = :customer_id
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $latestStmt->execute([':customer_id' => $customerId]);
        $latestId = (int) ($latestStmt->fetchColumn() ?: 0);

        if ($latestId !== $resetId) {
            $discardStmt = $pdo->prepare(
                'UPDATE customer_password_resets SET used_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $discardStmt->execute([':id' => $resetId]);
            $pdo->commit();
            return false;
        }

        $activateStmt = $pdo->prepare(
            'UPDATE customer_password_resets
             SET sent_at = CURRENT_TIMESTAMP
             WHERE id = :id AND sent_at IS NULL AND used_at IS NULL'
        );
        $activateStmt->execute([':id' => $resetId]);
        if ($activateStmt->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }

        $invalidateStmt = $pdo->prepare(
            'UPDATE customer_password_resets
             SET used_at = CURRENT_TIMESTAMP
             WHERE customer_id = :customer_id
               AND id <> :id
               AND used_at IS NULL'
        );
        $invalidateStmt->execute([
            ':customer_id' => $customerId,
            ':id' => $resetId,
        ]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function hopia_password_reset_request(PDO $pdo, string $email): void
{
    $lookup = $pdo->prepare(
        'SELECT id, first_name, email, password_hash
         FROM customers
         WHERE email = :email
         LIMIT 1'
    );
    $lookup->execute([':email' => $email]);
    $customer = $lookup->fetch();
    $passwordHash = $customer['password_hash'] ?? null;

    if ($customer === false || !is_string($passwordHash) || $passwordHash === '') {
        return;
    }

    $config = hopia_password_reset_config();
    $customerId = (int) $customer['id'];
    $now = time();
    $cooldownSince = date('Y-m-d H:i:s', $now - HOPIA_PASSWORD_RESET_COOLDOWN_SECONDS);
    $hourSince = date('Y-m-d H:i:s', $now - 3600);
    $pruneBefore = date('Y-m-d H:i:s', $now - 30 * 86400);
    $expiresAt = date('Y-m-d H:i:s', $now + HOPIA_PASSWORD_RESET_TTL_SECONDS);

    try {
        $pdo->beginTransaction();

        $customerLock = $pdo->prepare(
            'SELECT id, password_hash FROM customers WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $customerLock->execute([':id' => $customerId]);
        $lockedCustomer = $customerLock->fetch();
        $lockedHash = $lockedCustomer['password_hash'] ?? null;
        if ($lockedCustomer === false || !is_string($lockedHash) || $lockedHash === '') {
            $pdo->rollBack();
            return;
        }

        $pruneStmt = $pdo->prepare(
            'DELETE FROM customer_password_resets
             WHERE customer_id = :customer_id AND created_at < :prune_before'
        );
        $pruneStmt->execute([
            ':customer_id' => $customerId,
            ':prune_before' => $pruneBefore,
        ]);

        $rateStmt = $pdo->prepare(
            'SELECT COUNT(*) AS hourly_count, MAX(created_at) AS last_created_at
             FROM customer_password_resets
             WHERE customer_id = :customer_id AND created_at >= :hour_since'
        );
        $rateStmt->execute([
            ':customer_id' => $customerId,
            ':hour_since' => $hourSince,
        ]);
        $rate = $rateStmt->fetch();

        if ((int) ($rate['hourly_count'] ?? 0) >= HOPIA_PASSWORD_RESET_HOURLY_LIMIT
            || (($rate['last_created_at'] ?? null) !== null
                && (string) $rate['last_created_at'] >= $cooldownSince)) {
            $pdo->commit();
            return;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $token);
        $insertStmt = $pdo->prepare(
            'INSERT INTO customer_password_resets (customer_id, token_hash, expires_at)
             VALUES (:customer_id, :token_hash, :expires_at)'
        );
        $insertStmt->execute([
            ':customer_id' => $customerId,
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
        ]);
        $resetId = (int) $pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    try {
        hopia_password_reset_send_mail($config, $customer, $token);
        if (!hopia_password_reset_activate($pdo, $resetId, $customerId)) {
            throw new RuntimeException('The password reset token could not be activated.');
        }
    } catch (Throwable $e) {
        hopia_password_reset_discard($pdo, $resetId);
        throw $e;
    }
}

function hopia_password_reset_consume(PDO $pdo, string $token, string $newPassword): ?int
{
    $candidate = hopia_password_reset_find($pdo, $token);
    if ($candidate === null) {
        return null;
    }

    $customerId = (int) $candidate['customer_id'];

    try {
        $pdo->beginTransaction();

        $customerLock = $pdo->prepare('SELECT id FROM customers WHERE id = :id FOR UPDATE');
        $customerLock->execute([':id' => $customerId]);
        if ($customerLock->fetch() === false) {
            $pdo->rollBack();
            return null;
        }

        $reset = hopia_password_reset_find($pdo, $token, true);
        if ($reset === null || (int) $reset['customer_id'] !== $customerId) {
            $pdo->rollBack();
            return null;
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $updateCustomer = $pdo->prepare(
            'UPDATE customers SET password_hash = :password_hash WHERE id = :customer_id'
        );
        $updateCustomer->execute([
            ':password_hash' => $passwordHash,
            ':customer_id' => $customerId,
        ]);

        $consumeTokens = $pdo->prepare(
            'UPDATE customer_password_resets
             SET used_at = CURRENT_TIMESTAMP
             WHERE customer_id = :customer_id AND used_at IS NULL'
        );
        $consumeTokens->execute([':customer_id' => $customerId]);

        $pdo->commit();
        return $customerId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
