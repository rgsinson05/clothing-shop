<?php

/*
 * Hopia Fits - Gallery page (public).
 *
 * Curated, editorial showcase of the shop's real photography.
 * Images are discovered directly from the existing product image
 * directory on disk, so the page only ever renders genuine,
 * already-uploaded Hopia Fits / shop imagery. No imagery is
 * generated, invented, or sourced from stock, and no database
 * writes are performed here.
 */

session_start();

require_once __DIR__ . '/includes/database.php';

define('HUPIA_BASE', '.');

/*
 * Collect the real product photographs that already exist in the
 * project. Only files physically present under images/products are
 * used; each candidate is validated as a decodable raster image
 * before it is shown.
 */
$gallery_items = [];
$gallery_dir   = __DIR__ . '/images/products';

if (is_dir($gallery_dir)) {
    $gallery_extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $gallery_files      = [];

    foreach (scandir($gallery_dir) as $gallery_entry) {
        if ($gallery_entry === '.' || $gallery_entry === '..') {
            continue;
        }

        $gallery_file = $gallery_dir . DIRECTORY_SEPARATOR . $gallery_entry;
        if (!is_file($gallery_file)) {
            continue;
        }

        $gallery_ext = strtolower(pathinfo($gallery_entry, PATHINFO_EXTENSION));
        if (!in_array($gallery_ext, $gallery_extensions, true)) {
            continue;
        }

        // Confirm the file is a real, readable image before using it.
        if (getimagesize($gallery_file) === false) {
            continue;
        }

        $gallery_files[] = $gallery_entry;
    }

    // Stable, deterministic order for a consistent layout.
    sort($gallery_files, SORT_NATURAL | SORT_FLAG_CASE);

    foreach ($gallery_files as $gallery_file) {
        $gallery_items[] = [
            'src' => 'images/products/' . rawurlencode($gallery_file),
            'alt' => 'Hopia Fits product photograph',
        ];
    }
}

/*
 * Category classification for the SHIRTS | PANTS | SHORTS filters.
 *
 * The real uploaded photographs are matched back to their products
 * through the existing database rows, so every tile keeps its genuine
 * shop category. Images that exist on disk but have no matching
 * product row are simply not shown in a category view.
 */
$gallery_categories = ['SHIRTS', 'PANTS', 'SHORTS'];
$gallery_category_map = [];

try {
    $gallery_map_stmt = $pdo->query(
        'SELECT p.category, pi.image_path
         FROM product_images pi
         INNER JOIN products p ON p.id = pi.product_id
         ORDER BY pi.sort_order ASC, pi.id ASC'
    );

    foreach ($gallery_map_stmt->fetchAll() as $gallery_map_row) {
        $gallery_map_file = strtolower(basename(trim((string) $gallery_map_row['image_path'])));
        if ($gallery_map_file !== '' && !isset($gallery_category_map[$gallery_map_file])) {
            $gallery_category_map[$gallery_map_file] = (string) $gallery_map_row['category'];
        }
    }
} catch (Throwable $gallery_map_error) {
    // Classification is best-effort; an unavailable database leaves
    // the category views empty rather than breaking the page.
}

foreach ($gallery_items as $gallery_index => $gallery_item) {
    $gallery_src_file = strtolower(basename(parse_url($gallery_item['src'], PHP_URL_PATH) ?: ''));
    $gallery_items[$gallery_index]['category'] = $gallery_category_map[$gallery_src_file] ?? null;
}

/*
 * Active category for the editorial text filters. There is no "ALL"
 * view by design: the gallery always opens on one category.
 */
$gallery_active_category = strtoupper(trim((string) ($_GET['category'] ?? 'SHIRTS')));
if (!in_array($gallery_active_category, $gallery_categories, true)) {
    $gallery_active_category = 'SHIRTS';
}

$gallery_view_items = array_values(array_filter(
    $gallery_items,
    static function (array $gallery_item) use ($gallery_active_category): bool {
        return $gallery_item['category'] === $gallery_active_category;
    }
));

/*
 * Desktop "editorial quilt" emphasis.
 *
 * On wider screens a repeating rhythm gives the grid a curated,
 * non-uniform feel while keeping a single controlled column system:
 * every 4th tile becomes a large square feature and the 4th tile in
 * each group closes the row as a wide 2 x 1 panel. The small-image
 * count is deliberately left untouched, so a short gallery stays a
 * clean, aligned set of equal tiles instead of an awkward mosaic.
 */
$gallery_featured = count($gallery_view_items) >= 6;

$page_title       = 'Gallery - Hopia Fits';
$page_description = 'A visual look at real Hopia Fits finds - preloved shirts, pants, and shorts.';
$ui_active        = 'gallery.php';
$ui_path_prefix   = 'customer/';
$ui_brand_href    = 'index.php';

require __DIR__ . '/includes/ui.head.php';
require __DIR__ . '/includes/ui.header.php';
?>

<section class="gallery-page" aria-labelledby="gallery-title">

    <header class="gallery-page__header">
        <p class="gallery-page__eyebrow">HOPIA FITS</p>
        <h1 class="gallery-page__title" id="gallery-title">GALLERY</h1>
        <p class="gallery-page__intro">A closer look at the pieces that pass through the shop.</p>
    </header>

    <hr class="gallery-page__rule" aria-hidden="true">

    <nav class="gallery-filters" aria-label="Gallery categories">
        <?php
        $gallery_filter_icons = [
            'SHIRTS' => '<svg class="gallery-filter__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.38 3.46 16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"/></svg>',
            'PANTS' => '<svg class="gallery-filter__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h12v4H6z"/><path d="M6 7l-1.5 14h6.5L12 10"/><path d="M18 7l1.5 14h-6.5L12 10"/><path d="M12 7v3"/></svg>',
            'SHORTS' => '<svg class="gallery-filter__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 3h16v3H4z"/><path d="M4 6l-1.2 12h7.2L12 6"/><path d="M20 6l1.2 12h-7.2L12 6"/><path d="M12 6v3"/></svg>',
        ];
        ?>
        <?php foreach ($gallery_categories as $gallery_filter_category): ?>
            <?php $gallery_filter_active = $gallery_filter_category === $gallery_active_category; ?>
            <a
                class="gallery-filter<?= $gallery_filter_active ? ' is-active' : '' ?>"
                href="gallery.php?category=<?= hopia_e(strtolower($gallery_filter_category)) ?>"
                <?= $gallery_filter_active ? 'aria-current="true"' : '' ?>
            ><?= $gallery_filter_icons[$gallery_filter_category] ?? '' ?><?= hopia_e($gallery_filter_category) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($gallery_view_items): ?>

        <div class="gallery-grid<?= $gallery_featured ? ' gallery-grid--featured' : '' ?>">
            <?php foreach ($gallery_view_items as $gallery_index => $gallery_item): ?>
                <?php
                $gallery_is_featured = $gallery_featured
                    && ($gallery_index % 4 === 0)
                    && ($gallery_index + 3 < count($gallery_view_items));
                $gallery_is_wide = $gallery_featured
                    && ($gallery_index % 4 === 3)
                    && ($gallery_index + 1 < count($gallery_view_items));

                $gallery_classes = ['gallery-item'];
                if ($gallery_is_featured) {
                    $gallery_classes[] = 'gallery-item--feature';
                }
                if ($gallery_is_wide) {
                    $gallery_classes[] = 'gallery-item--wide';
                }
                ?>
                <figure class="<?= hopia_e(implode(' ', $gallery_classes)) ?>">
                    <img
                        class="gallery-item__image"
                        src="<?= hopia_e($gallery_item['src']) ?>"
                        alt="<?= hopia_e($gallery_item['alt']) ?>"
                        loading="<?= $gallery_index === 0 ? 'eager' : 'lazy' ?>"
                        decoding="async"
                    >
                </figure>
            <?php endforeach; ?>
        </div>

    <?php else: ?>

        <div class="gallery-empty">
            <p class="gallery-empty__title">No <?= hopia_e(strtolower($gallery_active_category)) ?> in the gallery yet</p>
            <p class="gallery-empty__copy">Photographs of our current <?= hopia_e(strtolower($gallery_active_category)) ?> will appear here.</p>
        </div>

    <?php endif; ?>

</section>

<?php require __DIR__ . '/includes/ui.footer.php'; ?>
