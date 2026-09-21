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
                            <strong><?= hopia_e($category) ?></strong>
                            <span class="home-category__action">Shop now</span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
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
