<?php

require_once __DIR__ . '/ui.php';

$page_title = isset($page_title) && $page_title !== ''
    ? (string) $page_title
    : hopia_site_name();

$page_description = isset($page_description)
    ? (string) $page_description
    : "Preloved shirts, pants, and shorts at " . hopia_site_name() . ".";

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f5f4f1">
    <meta name="description" content="<?= hopia_e($page_description) ?>">
    <title><?= hopia_e($page_title) ?></title>
    <link rel="stylesheet" href="<?= hopia_e(hopia_asset('css/styles.css')) ?>">
</head>
