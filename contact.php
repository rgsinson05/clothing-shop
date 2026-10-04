<?php

session_start();

require_once __DIR__ . '/includes/database.php';

define('HUPIA_BASE', '.');

$page_title       = 'Contact - Hopia Fits';
$page_description = 'Get in touch with Hopia Fits. Find us on Facebook, call us, or visit us at Plaridel Street, Roxas City.';
$ui_active        = 'contact.php';
$ui_path_prefix   = 'customer/';
$ui_brand_href    = 'index.php';

require __DIR__ . '/includes/ui.head.php';
require __DIR__ . '/includes/ui.header.php';
?>

<section class="contact-page" aria-labelledby="contact-title">

    <div class="contact-card">

        <div class="contact-card__intro">
            <p class="contact-card__eyebrow">
                <span class="contact-card__index" aria-hidden="true">01 /</span>
                Get in touch
            </p>

            <h1 class="contact-card__title" id="contact-title">Contact</h1>

            <div class="contact-card__rule" aria-hidden="true"></div>

            <p class="contact-card__copy">
                Questions about a piece, an order, or a fitting — send a message
                through any of the channels listed and we will get back to you.
            </p>

            <p class="contact-card__meta">Hopia Fits — Curated Resale</p>
        </div>

        <div class="contact-card__details">

            <dl class="contact-list">

                <div class="contact-item">
                    <dt class="contact-item__label">
                        <span class="contact-item__index" aria-hidden="true">02</span>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 21s-6.5-5.4-6.5-10.4a6.5 6.5 0 0 1 13 0C18.5 15.6 12 21 12 21z"></path>
                            <circle cx="12" cy="10.5" r="2.2"></circle>
                        </svg>
                        Location
                    </dt>
                    <dd class="contact-item__value">Plaridel Street, Roxas City</dd>
                </div>

                <div class="contact-item">
                    <dt class="contact-item__label">
                        <span class="contact-item__index" aria-hidden="true">03</span>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M5 4h4l1.5 4.5-2 1.5a12 12 0 0 0 5.5 5.5l1.5-2L20 15v4a1.5 1.5 0 0 1-1.7 1.5C10.3 19.6 4.4 13.7 3.5 5.7A1.5 1.5 0 0 1 5 4z"></path>
                        </svg>
                        Phone
                    </dt>
                    <dd class="contact-item__value">
                        <a class="contact-item__link" href="tel:+639100711262">0910 071 1262</a>
                    </dd>
                </div>

                <div class="contact-item">
                    <dt class="contact-item__label">
                        <span class="contact-item__index" aria-hidden="true">04</span>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M15.6 4.6h-1.8a3.2 3.2 0 0 0-3.2 3.2V20"></path>
                            <path d="M8.4 11.3h6.4"></path>
                        </svg>
                        Facebook
                    </dt>
                    <dd class="contact-item__value">
                        <a class="contact-item__link" href="https://www.facebook.com/maryhope.tumagos.7" target="_blank" rel="noopener">maryhope.tumagos.7</a>
                    </dd>
                </div>

            </dl>

        </div>

    </div>

</section>

<?php require __DIR__ . '/includes/ui.footer.php'; ?>
