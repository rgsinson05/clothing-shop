<?php

/*
 * Reusable Customer's Feedback card.
 *
 * Rendered inside a loop on the public feedback page and by the LOAD MORE
 * endpoint (through hopia_feedback_render_cards). Expects a single $card
 * array with the fields:
 *   photo_path, rating, feedback_text, customer_name, created_at
 *
 * All customer-generated values are escaped with hopia_e(), so raw HTML in
 * feedback text or a display name is never interpreted as page markup.
 *
 * Display order is fixed: PHOTO, RATING, FEEDBACK, CUSTOMER, DATE.
 * No product, order, or other metadata is shown here.
 *
 * When the page marks a card as the logged-in customer's own
 * ($card['is_own'] with a $card['edit'] payload from
 * hopia_feedback_edit_payload), a small EDIT action is rendered. It is the
 * only edit control on the page, it appears on the owner's cards only, and
 * anonymous visitors never receive an is_own card, so they never see it.
 */

require_once __DIR__ . '/ui.php';

$card_photo = isset($card['photo_path']) ? trim((string) $card['photo_path']) : '';
$card_rating = isset($card['rating']) ? (int) $card['rating'] : 0;
$card_rating = max(0, min(5, $card_rating));
$card_text = isset($card['feedback_text']) ? (string) $card['feedback_text'] : '';
$card_customer = isset($card['customer_name']) ? trim((string) $card['customer_name']) : '';
$card_created = isset($card['created_at']) ? trim((string) $card['created_at']) : '';
$card_timestamp = $card_created !== '' ? strtotime($card_created) : false;
$card_date_display = $card_timestamp ? date('M j, Y', $card_timestamp) : '';
$card_is_own = !empty($card['is_own']) && isset($card['edit']) && is_array($card['edit']);
$card_edit = $card_is_own ? $card['edit'] : null;

?>
<li class="feedback-card">
    <div class="feedback-card__photo">
        <?php if ($card_photo !== ''): ?>
            <img
                src="<?= hopia_e($card_photo) ?>"
                alt="Photo shared by <?= hopia_e($card_customer !== '' ? $card_customer : 'a customer') ?>"
                loading="lazy"
            >
        <?php else: ?>
            <span class="feedback-card__photo-fallback" aria-hidden="true">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                    <polyline points="21 15 16 10 5 21"></polyline>
                </svg>
            </span>
        <?php endif; ?>
    </div>

    <p class="feedback-card__rating" role="img" aria-label="Rated <?= (int) $card_rating ?> out of 5">
        <?php for ($card_star = 1; $card_star <= 5; $card_star++): ?>
            <span class="feedback-card__star<?= $card_star <= $card_rating ? ' is-filled' : '' ?>" aria-hidden="true">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path></svg>
            </span>
        <?php endfor; ?>
    </p>

    <blockquote class="feedback-card__text"><?= nl2br(hopia_e($card_text)) ?></blockquote>

    <div class="feedback-card__meta">
        <p class="feedback-card__customer"><?= hopia_e($card_customer) ?></p>
        <?php if ($card_date_display !== ''): ?>
            <p class="feedback-card__date"><time datetime="<?= hopia_e($card_created) ?>"><?= hopia_e($card_date_display) ?></time></p>
        <?php endif; ?>
        <?php if ($card_edit !== null): ?>
            <div class="feedback-card__actions">
                <button class="feedback-card__edit" type="button"
                    data-feedback-open
                    data-feedback-mode="edit"
                    data-feedback-order="<?= hopia_e((int) $card_edit['order_id']) ?>"
                    data-feedback-order-item="<?= hopia_e((int) $card_edit['order_item_id']) ?>"
                    data-feedback-name="<?= hopia_e($card_edit['product_name']) ?>"
                    data-feedback-size="<?= hopia_e($card_edit['size']) ?>"
                    data-feedback-color="<?= hopia_e($card_edit['color']) ?>"
                    data-feedback-image="<?= hopia_e($card_edit['image_path']) ?>"
                    data-feedback-rating="<?= hopia_e((int) $card_edit['rating']) ?>"
                    data-feedback-text="<?= hopia_e($card_edit['feedback_text']) ?>"
                    data-feedback-photo="<?= hopia_e($card_edit['photo_path']) ?>"
                    data-feedback-photo-name="<?= hopia_e($card_edit['photo_name']) ?>">
                    EDIT
                </button>
            </div>
        <?php endif; ?>
    </div>
</li>
