<?php

session_start();

require_once __DIR__ . '/includes/database.php';

define('HUPIA_BASE', '.');

$page_title       = 'About - Hopia Fits';
$page_description = 'Hopia Fits is a local thrift shop offering wholesale and retail secondhand clothing, including shirts, pants, and shorts.';
$ui_active        = 'about.php';
$ui_path_prefix   = 'customer/';
$ui_brand_href    = 'index.php';

require __DIR__ . '/includes/ui.head.php';
require __DIR__ . '/includes/ui.header.php';
?>

<section class="about-page" aria-labelledby="about-title">

    <header class="about-header">
        <div class="about-header__mark">
            <img
                class="about-header__logo"
                src="assets/videos/pictures/Hopia-fits-logo.webp"
                alt="Hopia Fits logo"
                width="74"
                height="55"
            >
            <h1 class="about-header__name" id="about-title">HOPIA FITS</h1>
            <svg
                class="about-header__hanger"
                viewBox="0 0 64 64"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
                focusable="false"
            >
                <path class="about-header__hanger-accent" d="M32 17v-2.5a5 5 0 1 1 5-5"/>
                <path d="M8 36 32 17l24 19"/>
                <path d="M8 36h48"/>
            </svg>
        </div>
    </header>

    <hr class="about-rule" aria-hidden="true">

    <div class="about-body">
        <p>Hopia Fits is a local thrift shop offering wholesale and retail secondhand clothing, including shirts, pants, and shorts. Each piece is unique, with its own available size, color, price, and condition.</p>
        <p>We make it easier to discover thrift finds online while keeping the individuality that makes every piece different.</p>
    </div>

    <hr class="about-rule" aria-hidden="true">

    <p class="about-tagline">FIND YOUR NEXT FIT.</p>

</section>

<?php require __DIR__ . '/includes/ui.footer.php'; ?>
