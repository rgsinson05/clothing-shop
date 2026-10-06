<?php

session_start();

require_once __DIR__ . '/includes/database.php';

/*
 * Category card images: collect up to three distinct real product photos per
 * category so each card can crossfade through its own images only.
 *
 * One row per image (no product-level LIMIT), so a product that already
 * contributed an image cannot consume a second slot for a sibling image. Only
 * the first three distinct paths are kept; a category with fewer real images
 * simply cycles fewer and is never padded with a duplicate.
 */
$categoryImages = [];
$categoryImageStmt = $pdo->query(
    "SELECT p.category, pi.image_path
     FROM products p
     INNER JOIN product_images pi
         ON pi.product_id = p.id
     WHERE p.status IN ('AVAILABLE', 'SOLD')
       AND p.category IN ('SHIRTS', 'PANTS', 'SHORTS')
     ORDER BY p.category ASC, p.created_at DESC, pi.sort_order ASC, pi.id ASC"
);

foreach ($categoryImageStmt->fetchAll() as $categoryImage) {
    $category = (string) $categoryImage['category'];
    $path = trim((string) $categoryImage['image_path']);
    if ($path === '') {
        continue;
    }

    if (!isset($categoryImages[$category])) {
        $categoryImages[$category] = [];
    }
    // Case-sensitive path compare, so distinct files are never collapsed.
    if (in_array($path, $categoryImages[$category], true)) {
        continue;
    }
    if (count($categoryImages[$category]) >= 3) {
        continue;
    }
    $categoryImages[$category][] = $path;
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
                    <?php
                    $imagePaths = $categoryImages[$category] ?? [];
                    $imageAlt = hopia_e(ucfirst(strtolower($category)));
                    // A single real image still renders as a static card.
                    $rotates = count($imagePaths) > 1;
                    ?>
                    <a class="home-category" href="customer/products.php?category=<?= hopia_e($category) ?>"<?= $rotates ? ' data-image-rotation' : '' ?>>
                        <span class="home-category__image">
                            <?php if ($imagePaths !== []): ?>
                                <?php foreach ($imagePaths as $imageIndex => $imagePath): ?>
                                    <img
                                        src="<?= hopia_e(ltrim($imagePath, '/')) ?>"
                                        alt="<?= $imageIndex === 0 ? $imageAlt : '' ?>"
                                        <?= $imageIndex === 0 ? 'loading="lazy"' : 'loading="lazy" aria-hidden="true"' ?>
                                        <?= $imageIndex === 0 ? '' : 'data-rotation-layer' ?>
                                    >
                                <?php endforeach; ?>
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

    <?php
    $homeWhyCards = [
        [
            'num'   => '01',
            'href'  => 'customer/products.php',
            'title' => 'Unique Finds',
            'text'  => 'Every piece is different.',
            'cta'   => 'Shop the finds',
            'aria'  => 'Unique Finds, browse the available thrift products',
            'icon'  => '<svg class="home-why__svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><path d="M10.5 3 Q11.1 9.9 18 10.5 Q11.1 11.1 10.5 18 Q9.9 11.1 3 10.5 Q9.9 9.9 10.5 3 Z"/><path d="M17.8 14.6 Q18.2 17.4 21 17.8 Q18.2 18.2 17.8 21 Q17.4 18.2 14.6 17.8 Q17.4 17.4 17.8 14.6 Z"/></svg>',
        ],
        [
            'num'   => '02',
            'href'  => 'about.php',
            'title' => 'Wholesale + Retail',
            'text'  => 'Shop for one or shop for more.',
            'cta'   => 'Our story',
            'aria'  => 'Wholesale and Retail, learn more about Hopia Fits',
            'icon'  => '<svg class="home-why__svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><rect x="6.5" y="4.3" width="11" height="4.1" rx="1.1"/><rect x="5.5" y="9.8" width="13" height="4.1" rx="1.1"/><rect x="4.5" y="15.3" width="15" height="4.1" rx="1.1"/></svg>',
        ],
        [
            'num'   => '03',
            'href'  => 'gallery.php',
            'title' => 'Real Thrift Finds',
            'text'  => 'Curated secondhand pieces.',
            'cta'   => 'View gallery',
            'aria'  => 'Real Thrift Finds, view the clothing gallery',
            'icon'  => '<svg class="home-why__svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><path d="M12 8.2 L5 13.9 C4.4 14.4 4.7 15.4 5.5 15.4 L18.5 15.4 C19.3 15.4 19.6 14.4 19 13.9 Z"/><path d="M12 8.2 V6.9 C12 5.9 11.1 5.2 10.1 5.7"/></svg>',
        ],
        [
            'num'   => '04',
            'href'  => 'contact.php',
            'title' => 'Local Shop',
            'text'  => 'Discover Hopia Fits online.',
            'cta'   => 'Find us',
            'aria'  => 'Local Shop, find our contact and location details',
            'icon'  => '<svg class="home-why__svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><path d="M12 20.5 C12 20.5 6 14.6 6 10 A6 6 0 1 1 18 10 C18 14.6 12 20.5 12 20.5 Z"/><circle cx="12" cy="10" r="2.3"/></svg>',
        ],
    ];
    ?>

    <div class="home-why__carousel" data-why-carousel>
        <div class="home-why__viewport">
            <ul class="home-why__grid" data-why-track>
                <?php foreach ($homeWhyCards as $card): ?>
                    <li class="home-why__item">
                        <a class="home-why__card" href="<?= hopia_e($card['href']) ?>" aria-label="<?= hopia_e($card['aria']) ?>">
                            <span class="home-why__num" aria-hidden="true"><?= hopia_e($card['num']) ?></span>
                            <span class="home-why__icon" aria-hidden="true"><?= $card['icon'] ?></span>
                            <span class="home-why__content">
                                <h3 class="home-why__label"><?= hopia_e($card['title']) ?></h3>
                                <span class="home-why__rule" aria-hidden="true"></span>
                                <p class="home-why__text"><?= hopia_e($card['text']) ?></p>
                            </span>
                            <span class="home-why__cta">
                                <span class="home-why__cta-label"><?= hopia_e($card['cta']) ?></span>
                                <span class="home-why__arrow" aria-hidden="true">
                                    <svg class="home-why__arrow-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                                        <line x1="4" y1="12" x2="19" y2="12"/>
                                        <polyline points="12.5 5.5 19 12 12.5 18.5"/>
                                    </svg>
                                </span>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
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
        var dotsContainer = carousel.querySelector('.home-category-carousel__dots');
        if (!viewport || !track) {
            return;
        }

        var mobileQuery = window.matchMedia('(max-width: 719px)');
        var motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

        // Slow, ambient drift (px/second) and how long the carousel must sit idle
        // after an interaction before the drift resumes.
        var AUTO_SPEED = 16;
        var RESUME_DELAY = 2000;

        var state = null;

        function makeClone(card) {
            var clone = card.cloneNode(true);
            clone.classList.add('is-clone');
            clone.setAttribute('aria-hidden', 'true');
            var link = clone.matches('a') ? clone : clone.querySelector('a');
            if (link) {
                link.setAttribute('tabindex', '-1');
            }
            return clone;
        }

        // Width of one full copy of the original cards (the scroll distance that
        // maps a card onto the identical card in the neighbouring copy).
        function measure() {
            if (!state) {
                return;
            }
            var middleFirst = state.originals[0];
            var beforeFirst = track.children[0];
            var width = middleFirst.offsetLeft - beforeFirst.offsetLeft;
            state.singleWidth = width > 0 ? width : 0;
            state.cardStep = state.originals.length ? state.singleWidth / state.originals.length : 0;
        }

        // Seamless infinite loop: keep the scroll position inside the middle copy.
        // Because every copy is identical, shifting by one copy width is invisible.
        function normalizeScroll() {
            if (!state || !state.singleWidth) {
                return;
            }
            var sw = state.singleWidth;
            var sl = viewport.scrollLeft;
            if (sl < sw) {
                viewport.scrollLeft = sl + sw;
            } else if (sl >= sw * 2) {
                viewport.scrollLeft = sl - sw;
            }
        }

        function updateDots() {
            if (!state || !state.dots || !state.dots.length || !state.cardStep) {
                return;
            }
            var count = state.originals.length;
            var idx = Math.round(viewport.scrollLeft / state.cardStep) % count;
            if (idx < 0) {
                idx += count;
            }
            if (idx === state.activeDot) {
                return;
            }
            state.activeDot = idx;
            for (var i = 0; i < state.dots.length; i++) {
                state.dots[i].classList.toggle('is-active', i === idx);
            }
        }

        function tick(now) {
            if (!state || state.paused) {
                if (state) {
                    state.raf = 0;
                }
                return;
            }
            if (state.lastTime === null) {
                state.lastTime = now;
            }
            var dt = (now - state.lastTime) / 1000;
            state.lastTime = now;
            if (dt > 0.1) {
                dt = 0.1; // clamp long frames (tab switch) so it never lurches
            }

            // Drive a float accumulator so sub-pixel steps are not lost on
            // browsers that round scrollLeft to integers.
            state.target += AUTO_SPEED * dt;
            if (state.singleWidth && state.target >= state.singleWidth * 2) {
                state.target -= state.singleWidth;
            }
            viewport.scrollLeft = state.target;

            state.raf = requestAnimationFrame(tick);
        }

        function startAuto() {
            if (!state || !state.paused) {
                return;
            }
            if (motionQuery.matches || state.hovered || state.focused || state.interacting) {
                return;
            }
            state.paused = false;
            state.lastTime = null;
            state.target = viewport.scrollLeft;
            if (!state.raf) {
                state.raf = requestAnimationFrame(tick);
            }
        }

        function stopAuto() {
            if (!state) {
                return;
            }
            state.paused = true;
            state.lastTime = null;
            if (state.raf) {
                cancelAnimationFrame(state.raf);
                state.raf = 0;
            }
        }

        // Manual control always wins: stop the drift immediately on any input.
        function pauseForInteraction() {
            if (!state) {
                return;
            }
            stopAuto();
            if (state.resumeTimer) {
                clearTimeout(state.resumeTimer);
                state.resumeTimer = 0;
            }
        }

        function scheduleResume() {
            if (!state) {
                return;
            }
            if (state.resumeTimer) {
                clearTimeout(state.resumeTimer);
                state.resumeTimer = 0;
            }
            if (state.hovered || state.focused || state.interacting || motionQuery.matches) {
                return;
            }
            state.resumeTimer = setTimeout(function () {
                state.resumeTimer = 0;
                startAuto();
            }, RESUME_DELAY);
        }

        function onScroll() {
            if (!state) {
                return;
            }
            normalizeScroll();
            updateDots();
        }

        // Interaction start (finger/mouse/pen down). Manual control wins, so the
        // drift stops immediately and no resume is queued yet.
        function onInteractStart() {
            if (!state) {
                return;
            }
            state.interacting = true;
            pauseForInteraction();
            carousel.dispatchEvent(new CustomEvent('categorycarousel:interact'));
        }

        // Interaction end (finger lifted / button released). Queue the idle resume.
        // For touch this is driven by touchend/touchcancel, because the browser
        // fires pointercancel at the START of a native scroll, not the end.
        function onInteractEnd() {
            if (!state || !state.interacting) {
                return;
            }
            state.interacting = false;
            scheduleResume();
            carousel.dispatchEvent(new CustomEvent('categorycarousel:idle'));
        }

        function onPointerCancel(event) {
            if (event && event.pointerType === 'touch') {
                return; // touchend/touchcancel own the end of a touch gesture
            }
            onInteractEnd();
        }

        function onWheel() {
            pauseForInteraction();
            scheduleResume();
        }

        function onKeyDown() {
            pauseForInteraction();
            scheduleResume();
        }

        function onEnter() {
            if (!state) {
                return;
            }
            state.hovered = true;
            pauseForInteraction();
        }

        function onLeave() {
            if (!state) {
                return;
            }
            state.hovered = false;
            scheduleResume();
        }

        function onFocusIn() {
            if (!state) {
                return;
            }
            state.focused = true;
            pauseForInteraction();
        }

        function onFocusOut() {
            if (!state) {
                return;
            }
            state.focused = false;
            scheduleResume();
        }

        function bindEvents() {
            viewport.addEventListener('scroll', onScroll, { passive: true });
            viewport.addEventListener('pointerdown', onInteractStart, { passive: true });
            viewport.addEventListener('touchstart', onInteractStart, { passive: true });
            window.addEventListener('pointerup', onInteractEnd, { passive: true });
            window.addEventListener('pointercancel', onPointerCancel, { passive: true });
            window.addEventListener('touchend', onInteractEnd, { passive: true });
            window.addEventListener('touchcancel', onInteractEnd, { passive: true });
            viewport.addEventListener('wheel', onWheel, { passive: true });
            viewport.addEventListener('keydown', onKeyDown);
            viewport.addEventListener('mouseenter', onEnter);
            viewport.addEventListener('mouseleave', onLeave);
            viewport.addEventListener('focusin', onFocusIn);
            viewport.addEventListener('focusout', onFocusOut);
        }

        function unbindEvents() {
            viewport.removeEventListener('scroll', onScroll);
            viewport.removeEventListener('pointerdown', onInteractStart);
            viewport.removeEventListener('touchstart', onInteractStart);
            window.removeEventListener('pointerup', onInteractEnd);
            window.removeEventListener('pointercancel', onPointerCancel);
            window.removeEventListener('touchend', onInteractEnd);
            window.removeEventListener('touchcancel', onInteractEnd);
            viewport.removeEventListener('wheel', onWheel);
            viewport.removeEventListener('keydown', onKeyDown);
            viewport.removeEventListener('mouseenter', onEnter);
            viewport.removeEventListener('mouseleave', onLeave);
            viewport.removeEventListener('focusin', onFocusIn);
            viewport.removeEventListener('focusout', onFocusOut);
        }

        function initCarousel() {
            if (state) {
                return;
            }

            var originals = Array.prototype.slice.call(track.children).filter(function (el) {
                return el.classList && el.classList.contains('home-category') && !el.classList.contains('is-clone');
            });
            if (originals.length < 2) {
                return;
            }

            // Number the cards so each copy shows 01/02/03 consistently.
            originals.forEach(function (card, i) {
                card.setAttribute('data-index', String(i + 1));
            });

            // Duplicate the set before and after the originals so the loop can run
            // in either direction without ever reaching a hard scroll edge. Built
            // once here, never rebuilt while scrolling.
            var firstOriginal = originals[0];
            var beforeFrag = document.createDocumentFragment();
            var afterFrag = document.createDocumentFragment();
            originals.forEach(function (card) {
                beforeFrag.appendChild(makeClone(card));
                afterFrag.appendChild(makeClone(card));
            });
            track.insertBefore(beforeFrag, firstOriginal);
            track.appendChild(afterFrag);

            state = {
                originals: originals,
                dots: dotsContainer ? dotsContainer.querySelectorAll('.home-category-carousel__dot') : null,
                singleWidth: 0,
                cardStep: 0,
                target: 0,
                lastTime: null,
                raf: 0,
                paused: true,
                hovered: false,
                focused: false,
                interacting: false,
                resumeTimer: 0,
                activeDot: -1
            };

            measure();
            // Start on the real (middle) copy so the focusable originals are the
            // cards in view and there is a full copy of runway on each side.
            viewport.scrollLeft = state.singleWidth;
            state.target = viewport.scrollLeft;
            updateDots();

            bindEvents();
            startAuto();
        }

        function destroyCarousel() {
            if (!state) {
                return;
            }

            stopAuto();
            if (state.resumeTimer) {
                clearTimeout(state.resumeTimer);
                state.resumeTimer = 0;
            }
            unbindEvents();

            var clones = track.querySelectorAll('.home-category.is-clone');
            for (var i = 0; i < clones.length; i++) {
                clones[i].parentNode.removeChild(clones[i]);
            }
            var cards = track.querySelectorAll('.home-category');
            for (var j = 0; j < cards.length; j++) {
                cards[j].removeAttribute('data-index');
                cards[j].removeAttribute('aria-hidden');
                cards[j].classList.remove('is-active');
                var link = cards[j].matches('a') ? cards[j] : cards[j].querySelector('a');
                if (link) {
                    link.removeAttribute('tabindex');
                }
            }
            viewport.scrollLeft = 0;

            if (dotsContainer) {
                var dots = dotsContainer.querySelectorAll('.home-category-carousel__dot');
                for (var k = 0; k < dots.length; k++) {
                    dots[k].classList.toggle('is-active', k === 0);
                }
            }

            state = null;
        }

        function updateMode() {
            if (mobileQuery.matches) {
                initCarousel();
            } else {
                destroyCarousel();
            }
        }

        var resizeTimer = 0;
        window.addEventListener('resize', function () {
            if (!state) {
                return;
            }
            if (resizeTimer) {
                clearTimeout(resizeTimer);
            }
            resizeTimer = setTimeout(function () {
                resizeTimer = 0;
                if (!state) {
                    return;
                }
                measure();
                normalizeScroll();
                state.target = viewport.scrollLeft;
                state.activeDot = -1;
                updateDots();
            }, 150);
        });

        function onMotionChange() {
            if (!state) {
                return;
            }
            if (motionQuery.matches) {
                pauseForInteraction();
            } else {
                scheduleResume();
            }
        }

        if (mobileQuery.addEventListener) {
            mobileQuery.addEventListener('change', updateMode);
        } else if (mobileQuery.addListener) {
            mobileQuery.addListener(updateMode);
        }

        if (motionQuery.addEventListener) {
            motionQuery.addEventListener('change', onMotionChange);
        } else if (motionQuery.addListener) {
            motionQuery.addListener(onMotionChange);
        }

        updateMode();
    }());

    /* Category card image crossfade.
       Each card fades between its own real category photos, one at a time, on
       an independent timer. The images are already in the DOM as stacked
       layers, so a step costs one attribute change and one opacity transition
       - no markup is created, swapped, or measured while running, and because
       every layer occupies the same box the card cannot reflow.

       Interaction with the mobile carousel is handled entirely through the
       `categorycarousel:*` events dispatched above. That leaves the swipe,
       finger-following, and auto-scroll logic untouched: this script only ever
       listens, never intercepts a pointer or touch event, so it cannot consume
       a gesture or delay a scroll. */
    (function () {
        var carousel = document.querySelector('[data-category-carousel]');
        if (!carousel) {
            return;
        }

        var track = carousel.querySelector('[data-carousel-track]');
        var motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

        // How long each image holds before the next crossfade begins. The fade
        // duration itself lives in CSS (900ms) and is never driven from here;
        // this only decides when a crossfade starts.
        var HOLD = 4000;
        // Per-card phase offsets, in ms. Staggering them stops the three cards
        // from ever changing on the same frame, while the small spread keeps
        // the section reading as one steady rhythm rather than as noise.
        var OFFSETS = [0, 1300, 2700];
        // Idle grace after a touch or pointer interaction ends, before rotation
        // resumes. Matches the carousel's own RESUME_DELAY so both restart
        // together and the swipe always keeps clear priority.
        var RESUME_DELAY = 2000;

        var cards = [];
        var timer = 0;
        var startedAt = 0;
        var pausedUntil = 0;
        var paused = false;

        // Collect every card that has at least two real layers. Cards with one
        // image, or none, are skipped entirely and stay fully static.
        function collect() {
            var found = [];
            var nodes = carousel.querySelectorAll('.home-category[data-image-rotation]');
            for (var i = 0; i < nodes.length; i++) {
                var layers = nodes[i].querySelectorAll('.home-category__image img');
                if (layers.length < 2) {
                    continue;
                }
                found.push({
                    layers: layers,
                    index: 0,
                    // Every copy of a category shares one phase, so a card and
                    // its carousel clone never show different photos while both
                    // are on screen. Position, not identity, sets the phase.
                    phase: OFFSETS[i % OFFSETS.length]
                });
                // Only the first layer carries the accessible name. The rest
                // are decorative copies of the same product, so they are kept
                // out of the accessibility tree and given no alt text.
                for (var j = 1; j < layers.length; j++) {
                    layers[j].setAttribute('aria-hidden', 'true');
                    layers[j].setAttribute('alt', '');
                }
            }
            return found;
        }

        function show(card, index) {
            if (card.index === index) {
                return;
            }
            card.index = index;
            for (var i = 0; i < card.layers.length; i++) {
                if (i === index) {
                    card.layers[i].setAttribute('data-rotation-active', '');
                } else {
                    card.layers[i].removeAttribute('data-rotation-active');
                }
            }
            preload(card, index);
        }

        // Warm the next photo just before it is needed so a slow connection can
        // never reveal an empty layer mid-fade. The file is fetched and decoded
        // off-screen and never inserted, so it cannot shift layout, flash, or
        // cause a duplicate request once the layer itself loads.
        function preload(card, index) {
            var next = card.layers[(index + 1) % card.layers.length];
            if (!next || next.getAttribute('data-preloaded')) {
                return;
            }
            next.setAttribute('data-preloaded', '');
            var warm = new Image();
            warm.src = next.getAttribute('src');
        }

        function now() {
            return (window.performance && performance.now) ? performance.now() : Date.now();
        }

        function tick(t) {
            if (motionQuery.matches) {
                timer = 0;
                return;
            }
            if (paused) {
                // Hold the current frame, then restart the clock once the grace
                // period ends so the next crossfade happens a full HOLD later.
                if (t < pausedUntil) {
                    timer = requestAnimationFrame(tick);
                    return;
                }
                paused = false;
                startedAt = t;
            }
            for (var i = 0; i < cards.length; i++) {
                var card = cards[i];
                var elapsed = t - startedAt - card.phase;
                if (elapsed < 0) {
                    continue;
                }
                show(card, (Math.floor(elapsed / HOLD) + 1) % card.layers.length);
            }
            timer = requestAnimationFrame(tick);
        }

        function start() {
            if (timer || motionQuery.matches || !cards.length) {
                return;
            }
            startedAt = now();
            timer = requestAnimationFrame(tick);
        }

        // Touch and pointer interaction take full priority: rotation stops
        // immediately and only returns once the carousel has been idle long
        // enough to be trusted again.
        function pauseFor() {
            if (!cards.length) {
                return;
            }
            paused = true;
            pausedUntil = now() + RESUME_DELAY;
        }

        carousel.addEventListener('categorycarousel:interact', pauseFor);
        carousel.addEventListener('categorycarousel:idle', pauseFor);

        function onMotionChange() {
            if (motionQuery.matches) {
                if (timer) {
                    cancelAnimationFrame(timer);
                    timer = 0;
                }
                // Park every card on its first image. CSS has already disabled
                // the fade, so this lands as one stable image with no motion.
                for (var i = 0; i < cards.length; i++) {
                    show(cards[i], 0);
                }
            } else {
                paused = false;
                start();
            }
        }

        if (motionQuery.addEventListener) {
            motionQuery.addEventListener('change', onMotionChange);
        } else if (motionQuery.addListener) {
            motionQuery.addListener(onMotionChange);
        }

        cards = collect();
        for (var i = 0; i < cards.length; i++) {
            preload(cards[i], 0);
        }
        start();

        // The carousel adds its clone copies on the mobile breakpoint, after
        // this script has run, and removes them again on desktop. Rebuild when
        // the card set changes so clones rotate in step and desktop is left
        // with the three original cards only.
        var buildTimer = 0;
        function rebuild() {
            if (buildTimer) {
                clearTimeout(buildTimer);
            }
            buildTimer = setTimeout(function () {
                buildTimer = 0;
                var next = collect();
                if (next.length === cards.length) {
                    return;
                }
                cards = next;
                for (var j = 0; j < cards.length; j++) {
                    preload(cards[j], 0);
                }
                start();
            }, 60);
        }

        if (window.MutationObserver && track) {
            new MutationObserver(rebuild).observe(track, { childList: true });
        }
    }());

    /* "Why Hopia Fits?" editorial cards — mobile horizontal carousel.
       Mirrors the category carousel's architecture so both carousels share one
       interaction language:

       - The viewport is a genuine horizontal scroller, so a finger drag is
         handled by the browser and follows the finger immediately, while
         `touch-action: pan-y` leaves vertical gestures to scroll the page.
         Because the scroll is native, the browser suppresses the click that
         follows a drag, so a tap navigates and a swipe never mis-fires a link.
       - A duplicated set before and after the originals gives the loop a full
         copy of runway on each side; the clones are built once here and are
         hidden from assistive tech and from the tab order.
       - Scrolling by exactly one copy width is invisible because every copy is
         identical, which is what makes the loop seamless. */
    (function () {
        var carousel = document.querySelector('[data-why-carousel]');
        if (!carousel) {
            return;
        }

        var viewport = carousel.querySelector('.home-why__viewport');
        var track = carousel.querySelector('[data-why-track]');
        if (!viewport || !track) {
            return;
        }

        var mobileQuery = window.matchMedia('(max-width: 719px)');
        var motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

        // Very slow ambient drift (px/second) and the idle delay before the
        // drift resumes after the reader lets go.
        var AUTO_SPEED = 14;
        var RESUME_DELAY = 2000;

        var state = null;

        function makeClone(item) {
            var clone = item.cloneNode(true);
            clone.classList.add('is-clone');
            clone.setAttribute('aria-hidden', 'true');
            var link = clone.matches('a') ? clone : clone.querySelector('a');
            if (link) {
                link.setAttribute('tabindex', '-1');
            }
            return clone;
        }

        // Distance that maps a card onto the identical card in the neighbouring
        // copy: the repeat cycle of the whole set.
        function measure() {
            if (!state) {
                return;
            }
            var beforeFirst = track.children[0];
            var middleFirst = state.originals[0];
            var width = middleFirst.offsetLeft - beforeFirst.offsetLeft;
            state.singleWidth = width > 0 ? width : 0;
        }

        // Seamless infinite loop: keep the scroll position inside the middle
        // copy. Stepping by one copy width lands on identical pixels, so the
        // correction is never visible and there is no jump or pause at the seam.
        function normalizeScroll() {
            if (!state || !state.singleWidth) {
                return;
            }
            var sw = state.singleWidth;
            var sl = viewport.scrollLeft;
            if (sl < sw) {
                viewport.scrollLeft = sl + sw;
            } else if (sl >= sw * 2) {
                viewport.scrollLeft = sl - sw;
            }
        }

        function tick(now) {
            if (!state || state.paused) {
                if (state) {
                    state.raf = 0;
                }
                return;
            }
            if (state.lastTime === null) {
                state.lastTime = now;
            }
            var dt = (now - state.lastTime) / 1000;
            state.lastTime = now;
            if (dt > 0.1) {
                dt = 0.1; // clamp long frames (tab switch) so it never lurches
            }

            // Float accumulator, so sub-pixel steps are not lost on browsers
            // that round scrollLeft to whole pixels.
            state.target += AUTO_SPEED * dt;
            if (state.singleWidth && state.target >= state.singleWidth * 2) {
                state.target -= state.singleWidth;
            }
            viewport.scrollLeft = state.target;

            state.raf = requestAnimationFrame(tick);
        }

        function startAuto() {
            if (!state || !state.paused) {
                return;
            }
            // Reduced motion disables the ambient drift entirely. Manual
            // swiping still works, and the cards stay fully usable.
            if (motionQuery.matches || state.focused || state.interacting) {
                return;
            }
            state.paused = false;
            state.lastTime = null;
            state.target = viewport.scrollLeft;
            if (!state.raf) {
                state.raf = requestAnimationFrame(tick);
            }
        }

        function stopAuto() {
            if (!state) {
                return;
            }
            state.paused = true;
            state.lastTime = null;
            if (state.raf) {
                cancelAnimationFrame(state.raf);
                state.raf = 0;
            }
        }

        // Manual control always wins: stop the drift the instant a finger,
        // wheel or key touches the track, so it never fights the reader.
        function pauseForInteraction() {
            if (!state) {
                return;
            }
            stopAuto();
            if (state.resumeTimer) {
                clearTimeout(state.resumeTimer);
                state.resumeTimer = 0;
            }
        }

        function scheduleResume() {
            if (!state) {
                return;
            }
            if (state.resumeTimer) {
                clearTimeout(state.resumeTimer);
                state.resumeTimer = 0;
            }
            if (state.focused || state.interacting || motionQuery.matches) {
                return;
            }
            state.resumeTimer = setTimeout(function () {
                state.resumeTimer = 0;
                startAuto();
            }, RESUME_DELAY);
        }

        function onInteractStart() {
            if (!state) {
                return;
            }
            state.interacting = true;
            pauseForInteraction();
        }

        // touchend/touchcancel own the end of a touch gesture: the browser fires
        // pointercancel at the START of a native scroll, not at the end.
        function onInteractEnd() {
            if (!state || !state.interacting) {
                return;
            }
            state.interacting = false;
            scheduleResume();
        }

        function onPointerCancel(event) {
            if (event && event.pointerType === 'touch') {
                return;
            }
            onInteractEnd();
        }

        function onScroll() {
            normalizeScroll();
        }

        function onFocusIn() {
            if (!state) {
                return;
            }
            state.focused = true;
            pauseForInteraction();
        }

        function onFocusOut() {
            if (!state) {
                return;
            }
            state.focused = false;
            scheduleResume();
        }

        function onKeyDown() {
            pauseForInteraction();
            scheduleResume();
        }

        function onWheel() {
            pauseForInteraction();
            scheduleResume();
        }

        function bindEvents() {
            viewport.addEventListener('scroll', onScroll, { passive: true });
            viewport.addEventListener('pointerdown', onInteractStart, { passive: true });
            viewport.addEventListener('touchstart', onInteractStart, { passive: true });
            window.addEventListener('pointerup', onInteractEnd, { passive: true });
            window.addEventListener('pointercancel', onPointerCancel, { passive: true });
            window.addEventListener('touchend', onInteractEnd, { passive: true });
            window.addEventListener('touchcancel', onInteractEnd, { passive: true });
            viewport.addEventListener('wheel', onWheel, { passive: true });
            viewport.addEventListener('keydown', onKeyDown);
            viewport.addEventListener('focusin', onFocusIn);
            viewport.addEventListener('focusout', onFocusOut);
        }

        function unbindEvents() {
            viewport.removeEventListener('scroll', onScroll);
            viewport.removeEventListener('pointerdown', onInteractStart);
            viewport.removeEventListener('touchstart', onInteractStart);
            window.removeEventListener('pointerup', onInteractEnd);
            window.removeEventListener('pointercancel', onPointerCancel);
            window.removeEventListener('touchend', onInteractEnd);
            window.removeEventListener('touchcancel', onInteractEnd);
            viewport.removeEventListener('wheel', onWheel);
            viewport.removeEventListener('keydown', onKeyDown);
            viewport.removeEventListener('focusin', onFocusIn);
            viewport.removeEventListener('focusout', onFocusOut);
        }

        function initCarousel() {
            if (state) {
                return;
            }

            var originals = Array.prototype.slice.call(track.children).filter(function (el) {
                return el.classList && el.classList.contains('home-why__item') && !el.classList.contains('is-clone');
            });
            if (originals.length < 2) {
                return;
            }

            // Build the flanking copies once. The DOM is never rebuilt while
            // scrolling; only scrollLeft moves.
            var beforeFrag = document.createDocumentFragment();
            var afterFrag = document.createDocumentFragment();
            originals.forEach(function (item) {
                beforeFrag.appendChild(makeClone(item));
                afterFrag.appendChild(makeClone(item));
            });
            track.insertBefore(beforeFrag, originals[0]);
            track.appendChild(afterFrag);

            state = {
                originals: originals,
                singleWidth: 0,
                target: 0,
                lastTime: null,
                raf: 0,
                paused: true,
                focused: false,
                interacting: false,
                resumeTimer: 0
            };

            measure();
            // Open on the real (middle) copy so the focusable originals are the
            // cards in view, with a full copy of runway on each side.
            viewport.scrollLeft = state.singleWidth;
            state.target = viewport.scrollLeft;

            bindEvents();
            startAuto();
        }

        function destroyCarousel() {
            if (!state) {
                return;
            }

            stopAuto();
            if (state.resumeTimer) {
                clearTimeout(state.resumeTimer);
                state.resumeTimer = 0;
            }
            unbindEvents();

            var clones = track.querySelectorAll('.home-why__item.is-clone');
            for (var i = 0; i < clones.length; i++) {
                clones[i].parentNode.removeChild(clones[i]);
            }
            var items = track.querySelectorAll('.home-why__item');
            for (var j = 0; j < items.length; j++) {
                items[j].removeAttribute('aria-hidden');
                var link = items[j].matches('a') ? items[j] : items[j].querySelector('a');
                if (link) {
                    link.removeAttribute('tabindex');
                }
            }
            viewport.scrollLeft = 0;

            state = null;
        }

        function updateMode() {
            // Desktop keeps the static four-card editorial grid.
            if (mobileQuery.matches) {
                initCarousel();
            } else {
                destroyCarousel();
            }
        }

        var resizeTimer = 0;
        window.addEventListener('resize', function () {
            if (!state) {
                return;
            }
            if (resizeTimer) {
                clearTimeout(resizeTimer);
            }
            resizeTimer = setTimeout(function () {
                resizeTimer = 0;
                if (!state) {
                    return;
                }
                measure();
                normalizeScroll();
                state.target = viewport.scrollLeft;
            }, 150);
        });

        function onMotionChange() {
            if (!state) {
                return;
            }
            if (motionQuery.matches) {
                pauseForInteraction();
            } else {
                scheduleResume();
            }
        }

        if (mobileQuery.addEventListener) {
            mobileQuery.addEventListener('change', updateMode);
        } else if (mobileQuery.addListener) {
            mobileQuery.addListener(updateMode);
        }

        if (motionQuery.addEventListener) {
            motionQuery.addEventListener('change', onMotionChange);
        } else if (motionQuery.addListener) {
            motionQuery.addListener(onMotionChange);
        }

        updateMode();
    }());
</script>

<?php require __DIR__ . '/includes/ui.footer.php'; ?>
