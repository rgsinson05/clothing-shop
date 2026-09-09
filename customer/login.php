<?php

session_start();

require_once __DIR__ . '/../includes/database.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $message = 'Please enter your email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } else {
        $sql = "
            SELECT id, first_name, last_name, email, password_hash
            FROM customers
            WHERE email = :email
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':email' => $email
        ]);

        $customer = $stmt->fetch();

        if ($customer && password_verify($password, $customer['password_hash'])) {
            $_SESSION['customer_id'] = $customer['id'];
            $_SESSION['customer_name'] = $customer['first_name'];

            header('Location: account.php');
            exit;
        }

        $message = 'Invalid email or password.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Login</title>
</head>
<body>

<h1>Customer Login</h1>

<?php if ($message !== ''): ?>
    <p><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<form method="POST">

    <label>
        Email:
        <input
            type="email"
            name="email"
            required
        >
    </label>

    <br><br>

    <label>
        Password:
        <input
            type="password"
            name="password"
            required
        >
    </label>

    <br><br>

    <button type="submit">Login</button>

</form>

</body>
</html>