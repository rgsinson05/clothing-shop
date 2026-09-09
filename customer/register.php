<?php

require_once __DIR__ . '/../includes/database.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if (
        $firstName === '' ||
        $lastName === '' ||
        $email === '' ||
        $password === ''
    ) {
        $message = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters.';
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $sql = "
            INSERT INTO customers
            (first_name, last_name, email, password_hash, phone)
            VALUES
            (:first_name, :last_name, :email, :password_hash, :phone)
        ";

        try {
            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => $email,
                ':password_hash' => $passwordHash,
                ':phone' => $phone
            ]);

            $message = 'Registration successful!';
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $message = 'That email address is already registered.';
            } else {
                $message = 'Something went wrong while creating your account.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create an Account</title>
</head>
<body>

<h1>Create an Account</h1>

<?php if ($message !== ''): ?>
    <p><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<form method="POST">

    <label>
        First Name:
        <input type="text" name="first_name" required>
    </label>

    <br><br>

    <label>
        Last Name:
        <input type="text" name="last_name" required>
    </label>

    <br><br>

    <label>
        Email:
        <input type="email" name="email" required>
    </label>

    <br><br>

    <label>
        Phone:
        <input type="text" name="phone">
    </label>

    <br><br>

    <label>
        Password:
        <input type="password" name="password" required>
    </label>

    <br><br>

    <button type="submit">Register</button>

</form>

</body>
</html>