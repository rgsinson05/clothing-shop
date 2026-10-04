<?php

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * Hopia Fits operates in the Philippines (Asia/Manila, UTC+8, no DST).
 * PHP and MySQL must agree on this timezone so order timestamps are
 * generated, stored, and displayed consistently in Philippine local time.
 *
 * PHP side: date()/strtotime() default to Asia/Manila.
 * MySQL side: the connection session is pinned to +08:00 below, so
 * CURRENT_TIMESTAMP (used by orders.created_at DEFAULT) evaluates to
 * Philippine time and TIMESTAMP columns are read back in +08:00.
 *
 * The fixed +08:00 offset is used instead of the named 'Asia/Manila'
 * zone on the MySQL side because the Philippines observes no DST and
 * named zones require the MySQL time zone tables to be populated,
 * which is not guaranteed on a default install.
 */
date_default_timezone_set('Asia/Manila');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$dsn = 'mysql:host=' . $_ENV['DB_HOST']
     . ';dbname=' . $_ENV['DB_NAME']
     . ';charset=utf8mb4';

$pdo = new PDO(
    $dsn,
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

/*
 * Pin the MySQL session to Philippine time (+08:00). This is issued as
 * an explicit statement rather than a driver-specific init-command
 * constant so it behaves consistently across PHP versions (the init
 * command constant is deprecated on PHP 8.5+). The app uses no
 * persistent connections, so this runs once per request alongside the
 * connection it configures.
 */
$pdo->exec("SET time_zone = '+08:00'");