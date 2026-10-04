<?php

session_start();

require_once __DIR__ . '/includes/database.php';

$categoryImages = [];
$categoryImageStmt = $pdo->query(
    "SELECT p.category, pi.image_path
     FROM products p
     INNER JOIN product_images pi
         ON pi.id = (
             SELECT pi2.id
             FROM product_images pi2
             WHERE pi2.product_id = p.id
             ORDER BY pi2.sort_order ASC, pi2.id ASC
             LIMIT 1
         )
     WHERE p.status IN ('AVAILABLE', 'SOLD')
       AND p.category IN ('SHIRTS', 'PANTS', 'SHORTS')
     ORDER BY p.created_at DESC, p.id DESC"
);

foreach ($categoryImageStmt->fetchAll() as $categoryImage) {
    $category = (string) $categoryImage['category'];
    if (!isset($categoryImages[$category])) {
        $categoryImages[$category] = trim((string) $categoryImage['image_path']);
    }
}

define('HUPIA_BASE', '.');

$page_title = "Hopia's Ukay-Ukay - Pre-loved Finds";
$page_description = 'Pre-loved finds, handpicked for everyday style. Browse the latest shirts, pants, and shorts.';
$ui_active = 'index.php';
$ui_path_prefix = 'customer/';
$ui_brand_href = 'index.php';
$body_class = 'home-page';

require __DIR__ . '/includes/ui.head.php';
require __DIR__ . '/includes/ui.header.php';
?>

<section class="home-hero" aria-labelledby="home-title">
    <video class="home-hero__video" autoplay muted loop playsinline aria-hidden="true" tabindex="-1">
        <source src="assets/videos/luma-0f150a86.mp4" type="video/mp4" media="(max-width: 719px)">
        <source src="assets/videos/luma-454e7711.mp4" type="video/mp4">
    </video>
    <span class="home-hero__scrim" aria-hidden="true"></span>
    <div class="home-hero__content">
        <p class="home-hero__eyebrow">Wholesale &amp; retail</p>
        <h1 id="home-title">Find your<br>next fit.</h1>
        <p class="home-hero__copy">Browse thrift finds in shirts, pants, and shorts.</p>
        <a class="home-hero__cta" href="customer/products.php">Shop now</a>
    </div>
    <a class="home-hero__cue" href="#shop-by-category">
        <span class="home-hero__cue-arrow" aria-hidden="true">&darr;</span>
        <span class="home-hero__cue-label">Shop by category</span>
    </a>
</section>

<section id="shop-by-category" class="home-section home-categories" aria-labelledby="categories-title">
    <div class="home-section__heading">
        <div>
            <span class="home-section__rule" aria-hidden="true"></span>
            <p class="home-section__eyebrow">Find your next staple</p>
            <h2 id="categories-title">Shop by category</h2>
        </div>
    </div>

    <div class="home-category-carousel" data-category-carousel>
        <div class="home-category-carousel__viewport">
            <div class="home-category-grid" id="category-carousel-track" data-carousel-track>
                <?php foreach (['SHIRTS', 'PANTS', 'SHORTS'] as $category): ?>
                    <?php $imagePath = $categoryImages[$category] ?? ''; ?>
                    <a class="home-category" href="customer/products.php?category=<?= hopia_e($category) ?>">
                        <span class="home-category__image">
                            <?php if ($imagePath !== ''): ?>
                                <img src="<?= hopia_e(ltrim($imagePath, '/')) ?>" alt="<?= hopia_e(ucfirst(strtolower($category))) ?>" loading="lazy">
                            <?php else: ?>
                                <span class="home-category__fallback" aria-hidden="true"></span>
                            <?php endif; ?>
                        </span>
                        <span class="home-category__overlay"></span>
                        <span class="home-category__content">
                            <span class="home-category__label">
                            <?= hopia_category_icon($category) ?>
                            <strong><?= hopia_e($category) ?></strong>
                            </span>
                            <span class="home-category__action">Shop now</span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="home-category-carousel__dots" aria-hidden="true">
            <span class="home-category-carousel__dot is-active"></span>
            <span class="home-category-carousel__dot"></span>
            <span class="home-category-carousel__dot"></span>
        </div>
    </div>
</section>

<section class="home-section home-why" aria-labelledby="why-title">
    <div class="home-section__heading home-why__heading">
        <div>
            <span class="home-section__rule" aria-hidden="true"></span>
            <p class="home-section__eyebrow">A few good reasons</p>
            <h2 id="why-title">Why Hopia Fits<span class="home-why__accent">?</span></h2>
        </div>
    </div>

    <ul class="home-why__grid">
        <li class="home-why__item">
            <span class="home-why__icon" aria-hidden="true">
                <svg class="home-why__svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                    <path d="M10.5 3 Q11.1 9.9 18 10.5 Q11.1 11.1 10.5 18 Q9.9 11.1 3 10.5 Q9.9 9.9 10.5 3 Z"/>
                    <path d="M17.8 14.6 Q18.2 17.4 21 17.8 Q18.2 18.2 17.8 21 Q17.4 18.2 14.6 17.8 Q17.4 17.4 17.8 14.6 Z"/>
                </svg>
            </span>
            <h3 class="home-why__label">Unique Finds</h3>
            <p class="home-why__text">Every piece is different.</p>
        </li>

        <li class="home-why__item">
            <span class="home-why__icon" aria-hidden="true">
                <svg class="home-why__svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                    <rect x="6.5" y="4.3" width="11" height="4.1" rx="1.1"/>
                    <rect x="5.5" y="9.8" width="13" height="4.1" rx="1.1"/>
                    <rect x="4.5" y="15.3" width="15" height="4.1" rx="1.1"/>
                </svg>
            </span>
            <h3 class="home-why__label">Wholesale + Retail</h3>
            <p class="home-why__text">Shop for one or shop for more.</p>
        </li>

        <li class="home-why__item">
            <span class="home-why__icon" aria-hidden="true">
                <svg class="home-why__svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                    <path d="M12 8.2 L5 13.9 C4.4 14.4 4.7 15.4 5.5 15.4 L18.5 15.4 C19.3 15.4 19.6 14.4 19 13.9 Z"/>
                    <path d="M12 8.2 V6.9 C12 5.9 11.1 5.2 10.1 5.7"/>
                </svg>
            </span>
            <h3 class="home-why__label">Real Thrift Finds</h3>
            <p class="home-why__text">Curated secondhand pieces.</p>
        </li>

        <li class="home-why__item">
            <span class="home-why__icon" aria-hidden="true">
                <svg class="home-why__svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                    <path d="M12 20.5 C12 20.5 6 14.6 6 10 A6 6 0 1 1 18 10 C18 14.6 12 20.5 12 20.5 Z"/>
                    <circle cx="12" cy="10" r="2.3"/>
                </svg>
            </span>
            <h3 class="home-why__label">Local Shop</h3>
            <p class="home-why__text">Discover Hopia Fits online.</p>
        </li>
    </ul>
</section>

<script>
    (function () {
        var cue = document.querySelector('.home-hero__cue');
        if (!cue) {
            return;
        }

        cue.addEventListener('click', function (event) {
            event.preventDefault();
            var target = document.querySelector(cue.getAttribute('href'));
            if (!target) {
                return;
            }

            var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
            target.scrollIntoView({
                behavior: reduceMotion.matches ? 'auto' : 'smooth',
                block: 'start'
            });
        });
    }());

    (function () {
        var carousel = document.querySelector('[data-category-carousel]');
        if (!carousel) {
            return;
        }

        var viewport = carousel.querySelector('.home-category-carousel__viewport');
        var track = carousel.querySelector('[data-carousel-track]');
        var dotsContainer = carousel.querySelector('.home-category-carousel__dots');
        var mobileQuery = window.matchMedia('(max-width: 719px)');
        var state = null;

        function setTrackPosition(animate) {
            if (!state || !state.slides.length) {
                return;
            }

            var slideWidth = state.slides[0].getBoundingClientRect().width;
            var gap = parseFloat(window.getComputedStyle(track).columnGap || window.getComputedStyle(track).gap) || 0;

            if (!animate) {
                track.style.transition = 'none';
            }

            track.style.transform = 'translate3d(-' + ((slideWidth + gap) * state.index) + 'px, 0, 0)';

            if (!animate) {
                void track.offsetWidth;
                track.style.transition = '';
            }
        }

        function updateSlideAccessibility() {
            if (!state) {
                return;
            }

            state.slides.forEach(function (slide, index) {
                var active = index === state.index;
                var link = slide.matches('a') ? slide : slide.querySelector('a');

                slide.classList.toggle('is-active', active);
                slide.setAttribute('aria-hidden', active ? 'false' : 'true');
                if (link) {
                    link.setAttribute('tabindex', active ? '0' : '-1');
                }
            });
            updateDots();
        }

        function updateDots() {
            if (!state || !dotsContainer) {
                return;
            }
            var dots = dotsContainer.querySelectorAll('.home-category-carousel__dot');
            dots.forEach(function (dot, i) {
                dot.classList.toggle('is-active', i === state.index);
            });
        }

        function snapTo(index) {
            state.index = index;
            state.animating = false;
            updateSlideAccessibility();
            setTrackPosition(false);
        }

        function moveTo(index) {
            if (!state || state.animating) {
                return;
            }

            state.index = index;
            state.animating = true;
            updateSlideAccessibility();
            setTrackPosition(true);
        }

        function moveBy(direction) {
            if (!state || state.animating) {
                return;
            }

            moveTo(state.index + direction);
        }

        function destroyCarousel() {
            if (!state) {
                return;
            }

            track.querySelectorAll('.is-clone').forEach(function (clone) {
                clone.remove();
            });
            track.querySelectorAll('.home-category').forEach(function (slide) {
                slide.classList.remove('is-active');
                slide.removeAttribute('aria-hidden');
                var link = slide.matches('a') ? slide : slide.querySelector('a');
                if (link) {
                    link.removeAttribute('tabindex');
                }
            });
            track.style.transform = '';
            track.style.transition = '';
            state = null;
            if (dotsContainer) {
                dotsContainer.querySelectorAll('.home-category-carousel__dot').forEach(function (dot) {
                    dot.classList.remove('is-active');
                });
                var firstDot = dotsContainer.querySelector('.home-category-carousel__dot');
                if (firstDot) {
                    firstDot.classList.add('is-active');
                }
            }
        }

        function initCarousel() {
            if (state) {
                return;
            }

            var originals = Array.prototype.slice.call(track.children);
            if (originals.length < 2) {
                return;
            }

            var previousClone = originals[originals.length - 1].cloneNode(true);
            var nextClone = originals[0].cloneNode(true);
            previousClone.classList.add('is-clone');
            nextClone.classList.add('is-clone');
            track.insertBefore(previousClone, originals[0]);
            track.appendChild(nextClone);

            state = {
                index: 1,
                slides: Array.prototype.slice.call(track.children),
                animating: false
            };
            updateSlideAccessibility();
            setTrackPosition(false);
        }

        track.addEventListener('transitionend', function (event) {
            if (!state || event.target !== track || event.propertyName !== 'transform') {
                return;
            }

            if (state.index === 0) {
                snapTo(state.slides.length - 2);
            } else if (state.index === state.slides.length - 1) {
                snapTo(1);
            } else {
                state.animating = false;
            }
        });

        var startX = 0;
        var startY = 0;
        var trackingPointer = false;
        var suppressClick = false;

        viewport.addEventListener('pointerdown', function (event) {
            if (!state || event.isPrimary === false || (event.pointerType === 'mouse' && event.button !== 0)) {
                return;
            }

            startX = event.clientX;
            startY = event.clientY;
            trackingPointer = true;
            suppressClick = false;
            if (viewport.setPointerCapture) {
                viewport.setPointerCapture(event.pointerId);
            }
        });

        viewport.addEventListener('pointerup', function (event) {
            if (!trackingPointer) {
                return;
            }

            trackingPointer = false;
            var distanceX = event.clientX - startX;
            var distanceY = event.clientY - startY;
            if (Math.abs(distanceX) < 40 || Math.abs(distanceX) < Math.abs(distanceY)) {
                return;
            }

            suppressClick = true;
            moveBy(distanceX < 0 ? 1 : -1);
        });

        viewport.addEventListener('pointercancel', function () {
            trackingPointer = false;
        });

        track.addEventListener('click', function (event) {
            if (!suppressClick) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            suppressClick = false;
        });

        function updateMode() {
            if (mobileQuery.matches) {
                initCarousel();
            } else {
                destroyCarousel();
            }
        }

        window.addEventListener('resize', function () {
            if (!state) {
                return;
            }

            if (state.animating) {
                snapTo(state.index === 0 ? state.slides.length - 2 : (state.index === state.slides.length - 1 ? 1 : state.index));
            } else {
                setTrackPosition(false);
            }
        });

        if (mobileQuery.addEventListener) {
            mobileQuery.addEventListener('change', updateMode);
        } else {
            mobileQuery.addListener(updateMode);
        }

        updateMode();
    }());
</script>

<?php require __DIR__ . '/includes/ui.footer.php'; ?>
