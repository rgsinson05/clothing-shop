<?php

/*
 * Unified entry point for the existing online and Store Pickup reservation
 * workflows. Each tab keeps its own controller, eligibility rules, and scanner.
 */

$reservationTab = isset($_GET['tab']) && $_GET['tab'] === 'pickup'
    ? 'pickup'
    : 'online';

if ($reservationTab === 'pickup') {
    require __DIR__ . '/pickup-verify.php';
    return;
}

require __DIR__ . '/order-verify.php';
