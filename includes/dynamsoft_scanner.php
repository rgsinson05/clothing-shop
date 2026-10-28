<?php

require_once __DIR__ . '/../vendor/autoload.php';

function hopia_dynamsoft_license(): string
{
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

    foreach (['DYNAMSOFT_LICENSE_KEY', 'DYNAMSOFT_LICENSE_KEY_PRESENT'] as $name) {
        $value = $_ENV[$name] ?? '';
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return '';
}

function hopia_dynamsoft_assets(): void
{
    $license = hopia_dynamsoft_license();
    ?>
    <link rel="stylesheet" href="<?= hopia_e(hopia_asset('css/dynamsoft-scanner.css')) ?>">
    <script>window.HOPIA_DYNAMSOFT_LICENSE = <?= json_encode($license, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="https://cdn.jsdelivr.net/npm/dynamsoft-barcode-reader-bundle@11.6.3200/dist/dbr.bundle.js"></script>
    <script src="<?= hopia_e(hopia_asset('js/dynamsoft-scanner.js')) ?>"></script>
    <?php
}
