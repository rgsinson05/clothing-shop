<?php

session_start();

if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account</title>
</head>
<body>

<h1>Welcome, <?= htmlspecialchars($_SESSION['customer_name']) ?>!</h1>

<p>You are successfully logged in.</p>

<p>Customer ID: <?= htmlspecialchars((string) $_SESSION['customer_id']) ?></p>

</body>
</html>