<?php

require_once __DIR__ . '/ui.php';

// Footer link prefix: admin pages live in /admin, the storefront in /customer.
$footer_prefix = isset($ui_section) && $ui_section === 'admin'
    ? '../customer/'
    : (isset($ui_path_prefix) ? (string) $ui_path_prefix : '');

?>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container site-footer__inner">
            <p class="site-footer__brand">HOPIA FITS</p>

            <div class="site-footer__cols">
                <section class="footer-accordion" id="footer-shop">
                    <h3 class="footer-accordion__heading">
                        <button class="footer-accordion__toggle" type="button" aria-expanded="false" aria-controls="footer-shop-panel">
                            <span>Shop</span>
                            <span class="footer-accordion__icon" aria-hidden="true"></span>
                        </button>
                    </h3>
                    <ul class="footer-accordion__panel" id="footer-shop-panel">
                        <li><a href="<?= hopia_e($footer_prefix) ?>products.php?category=SHIRTS">Shirts</a></li>
                        <li><a href="<?= hopia_e($footer_prefix) ?>products.php?category=PANTS">Pants</a></li>
                        <li><a href="<?= hopia_e($footer_prefix) ?>products.php?category=SHORTS">Shorts</a></li>
                    </ul>
                </section>

                <section class="footer-accordion" id="footer-connect">
                    <h3 class="footer-accordion__heading">
                        <button class="footer-accordion__toggle" type="button" aria-expanded="false" aria-controls="footer-connect-panel">
                            <span>Connect</span>
                            <span class="footer-accordion__icon" aria-hidden="true"></span>
                        </button>
                    </h3>
                    <ul class="footer-accordion__panel" id="footer-connect-panel">
                        <li>
                            <a href="https://www.facebook.com/maryhope.tumagos.7" target="_blank" rel="noopener">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path d="M13.5 21v-7h2.4l.4-3h-2.8V9.1c0-.9.3-1.5 1.6-1.5h1.3V4.9c-.3 0-1.1-.1-2.1-.1-2.1 0-3.6 1.3-3.6 3.7V11H8.3v3h2.4v7h2.8z"></path>
                                </svg>
                                Facebook
                            </a>
                        </li>
                        <li>
                            <a href="tel:+639100711262">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M5 4h4l1.5 4.5-2 1.5a12 12 0 0 0 5.5 5.5l1.5-2L20 15v4a1.5 1.5 0 0 1-1.7 1.5C10.3 19.6 4.4 13.7 3.5 5.7A1.5 1.5 0 0 1 5 4z"></path>
                                </svg>
                                0910 071 1262
                            </a>
                        </li>
                        <li>
                            <span class="footer-contact__text">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M12 21s-6.5-5.5-6.5-10.5a6.5 6.5 0 0 1 13 0C18.5 15.5 12 21 12 21z"></path>
                                    <circle cx="12" cy="10.5" r="2.2"></circle>
                                </svg>
                                Plaridel Street, Roxas City
                            </span>
                        </li>
                    </ul>
                </section>
            </div>

            <div class="site-footer__bottom">
                <span>&copy; <?= hopia_e(date('Y')) ?> HOPIA FITS</span>
                <span>Philippines</span>
            </div>
        </div>
    </footer>

    <script>
        (function () {
            document.querySelectorAll('.footer-accordion__toggle').forEach(function (toggle) {
                toggle.addEventListener('click', function () {
                    var section = toggle.closest('.footer-accordion');
                    if (!section) {
                        return;
                    }
                    var open = section.classList.toggle('is-open');
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
            });
        })();
    </script>
</body>
</html>
