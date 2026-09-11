<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Hopia's Ukay-Ukay</title>
</head>
<body>

    <h1>Admin Dashboard</h1>

    <p>Welcome, <?= htmlspecialchars($adminName) ?>!</p>

    <p>You are logged in as an administrator.</p>

    <a href="logout.php">Logout</a>

</body>
</html>