<?php

require_once __DIR__ . '/ui.php';

// Footer link prefix: admin pages live in /admin, the storefront in /customer.
$footer_prefix = isset($ui_section) && $ui_section === 'admin'
    ? '../customer/'
    : (isset($ui_path_prefix) ? (string) $ui_path_prefix : '');

// Editorial pages (About, Gallery, Contact) live at the project root; step back
// up from /customer or /admin to reach them.
$footer_root = $footer_prefix === 'customer/' ? '' : '../';

?>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container site-footer__inner">
            <div class="site-footer__top">
                <div class="site-footer__brand-block">
                    <p class="site-footer__brand">HOPIA FITS</p>
                    <p class="site-footer__tagline">Local thrift finds. Wholesale + Retail.</p>
                    <p class="site-footer__location">Roxas City, Philippines.</p>
                    <ul class="site-footer__contact">
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
                </div>

                <nav class="site-footer__nav" aria-label="Footer">
                    <section class="footer-accordion" id="footer-shop">
                        <h3 class="footer-accordion__heading">
                            <button class="footer-accordion__toggle" type="button" aria-expanded="false" aria-controls="footer-shop-panel">
                                <span>Shop</span>
                                <span class="footer-accordion__icon" aria-hidden="true"></span>
                            </button>
                        </h3>
                        <ul class="footer-accordion__panel" id="footer-shop-panel">
                            <li><a href="<?= hopia_e($footer_prefix) ?>products.php?gender=MEN">Men</a></li>
                            <li><a href="<?= hopia_e($footer_prefix) ?>products.php?gender=WOMEN">Women</a></li>
                            <li><a href="<?= hopia_e($footer_prefix) ?>products.php?category=SHIRTS">Shirts</a></li>
                            <li><a href="<?= hopia_e($footer_prefix) ?>products.php?category=PANTS">Pants</a></li>
                            <li><a href="<?= hopia_e($footer_prefix) ?>products.php?category=SHORTS">Shorts</a></li>
                        </ul>
                    </section>

                    <section class="footer-accordion" id="footer-explore">
                        <h3 class="footer-accordion__heading">
                            <button class="footer-accordion__toggle" type="button" aria-expanded="false" aria-controls="footer-explore-panel">
                                <span>Explore</span>
                                <span class="footer-accordion__icon" aria-hidden="true"></span>
                            </button>
                        </h3>
                        <ul class="footer-accordion__panel" id="footer-explore-panel">
                            <li><a href="<?= hopia_e($footer_root) ?>about.php">About</a></li>
                            <li><a href="<?= hopia_e($footer_root) ?>gallery.php">Gallery</a></li>
                            <li><a href="<?= hopia_e($footer_prefix) ?>feedback.php">Customer's Feedback</a></li>
                            <li><a href="<?= hopia_e($footer_root) ?>contact.php">Contact</a></li>
                        </ul>
                    </section>

                    <section class="footer-accordion" id="footer-account">
                        <h3 class="footer-accordion__heading">
                            <button class="footer-accordion__toggle" type="button" aria-expanded="false" aria-controls="footer-account-panel">
                                <span>Account</span>
                                <span class="footer-accordion__icon" aria-hidden="true"></span>
                            </button>
                        </h3>
                        <ul class="footer-accordion__panel" id="footer-account-panel">
                            <li><a href="<?= hopia_e($footer_prefix . (!empty($_SESSION['customer_id']) ? 'account.php' : 'login.php')) ?>">My Account</a></li>
                            <li><a href="<?= hopia_e($footer_prefix) ?>orders.php">My Orders</a></li>
                        </ul>
                    </section>
                </nav>
            </div>

            <div class="site-footer__bottom">
                <span>&copy; <?= hopia_e(date('Y')) ?> HOPIA FITS</span>
                <span>This website is For Educational Purposes Only</span>
            </div>
        </div>
    </footer>

    <?php if (!empty($ui_feedback_overlay)): ?>
        <?php require __DIR__ . '/ui.feedback-overlay.php'; ?>
    <?php endif; ?>

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

    <script>
        /* Global page transition: fade the current page out on normal internal
           navigation, then let the destination fade in (CSS handles the entry).
           Purely progressive enhancement — if anything is unmet we fall straight
           through to the browser's native navigation. */
        (function () {
            var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

            // Always clear the leaving state when a page is shown — including
            // restores from the back/forward cache, so Back never reveals a
            // page frozen mid fade-out.
            window.addEventListener('pageshow', function () {
                document.body.classList.remove('is-leaving');
            });

            // Honour reduced-motion: no fade-out interception at all.
            if (reduceMotion.matches) {
                return;
            }

            var leaving = false;
            var duration = 220;

            document.addEventListener('click', function (event) {
                // Let other handlers (logout modal, add-to-cart, etc.) win, and
                // ignore new-tab / modified / non-primary clicks.
                if (
                    leaving ||
                    event.defaultPrevented ||
                    event.button !== 0 ||
                    event.metaKey ||
                    event.ctrlKey ||
                    event.shiftKey ||
                    event.altKey
                ) {
                    return;
                }

                var target = event.target;
                if (!target || typeof target.closest !== 'function') {
                    return;
                }

                var anchor = target.closest('a');
                if (!anchor) {
                    return;
                }

                // Respect explicit opt-outs and links that must keep native
                // behavior: downloads, framed/new-tab targets, and the logout
                // confirmation flow (which runs its own modal + redirect).
                var explicitTarget = anchor.getAttribute('target');
                if (
                    anchor.hasAttribute('download') ||
                    anchor.hasAttribute('data-no-transition') ||
                    (explicitTarget && explicitTarget !== '_self') ||
                    anchor.closest('[data-logout]') ||
                    anchor.closest('[data-logout-accept]') ||
                    anchor.closest('[data-logout-cancel]')
                ) {
                    return;
                }

                var rawHref = anchor.getAttribute('href');
                if (!rawHref || rawHref.charAt(0) === '#') {
                    return; // missing href or in-page anchor (hero cue, skip link)
                }

                var url;
                try {
                    url = new URL(anchor.href, window.location.href);
                } catch (error) {
                    return;
                }

                // Only plain same-origin http(s) navigations. Skips mailto:,
                // tel:, and any external host.
                if (url.protocol !== 'http:' && url.protocol !== 'https:') {
                    return;
                }
                if (url.origin !== window.location.origin) {
                    return;
                }

                // Same document, only the hash differs → native anchor jump.
                if (
                    url.pathname === window.location.pathname &&
                    url.search === window.location.search &&
                    url.hash
                ) {
                    return;
                }

                leaving = true;
                event.preventDefault();
                document.body.classList.add('is-leaving');

                var destination = anchor.href;
                window.setTimeout(function () {
                    window.location.href = destination;
                }, duration);
            });
        })();
    </script>
</body>
</html>
