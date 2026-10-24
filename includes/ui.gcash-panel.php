<?php
/*
 * Hopia Fits - shared GCash payment panel with Phase 6 customer recovery.
 *
 * Include-only partial rendered on order-confirmation.php and
 * order-detail.php for an order whose payment method is GCASH. It shows the
 * shop's configured receiving account, the exact amount due (the order's
 * stored server-calculated total: merchandise subtotal + applicable shipping
 * fee), transfer instructions, the current verification state, and the
 * owner-only reference/screenshot submission form.
 *
 * It expects the including page to have required includes/gcash.php and
 * includes/payment.php and to set a single $gcashPanel array:
 *   'order_id'       int
 *   'amount'         string|float  orders.total_amount (exact amount due)
 *   'config'         array         gcash_config()
 *   'payment'        array         the payments row (Phase 2 columns included)
 *   'order'          array         current order and completed-sale flag
 *   'shipment'       array|null    current fulfillment record
 *   'state'          string        payment_gcash_state($payment)
 *   'csrf'           string        the customer CSRF token
 *   'return'         string        'order-confirmation.php' | 'order-detail.php'
 *   'proof_endpoint' string        'payment-proof.php'
 *   'flash'          string        '' | 'submitted' | 'error'
 *   'flash_message'  string        optional specific error text
 *   'modal'          bool          OPTIONAL. When true (Order Confirmation
 *                                  page), the SAME panel + form are rendered
 *                                  inside a centered modal with a dimmed
 *                                  backdrop, a close button, a clearly visible
 *                                  reopen button, and a one-per-browser-session
 *                                  automatic open after order placement. There
 *                                  is still exactly ONE form instance and ONE
 *                                  submission handler - the modal is only a
 *                                  presentation wrapper around the existing
 *                                  panel markup.
 *
 * It assumes the GCash option could only ever be selected when the account is
 * configured, but it still renders a safe "unavailable" notice if, for any
 * reason, the configuration is now missing, and it never prints a placeholder
 * account.
 */

if (!isset($gcashPanel) || !is_array($gcashPanel)) {
    return;
}

require_once __DIR__ . '/shipping.php';

$gp = $gcashPanel;
$gpConfig = $gp['config'] ?? gcash_config();
$gpPayment = $gp['payment'] ?? [];
$gpState = (string) ($gp['state'] ?? 'NOT_SUBMITTED');
$gpOrderId = (int) ($gp['order_id'] ?? 0);
$gpReturn = ($gp['return'] ?? 'order-detail.php') === 'order-confirmation.php'
    ? 'order-confirmation.php'
    : 'order-detail.php';
$gpProofEndpoint = (string) ($gp['proof_endpoint'] ?? 'payment-proof.php');
$gpAmount = number_format((float) ($gp['amount'] ?? 0), 2);
$gpDomId = 'gcash-panel-' . $gpOrderId;
$gpModal = !empty($gp['modal']);

$gpReference = trim((string) ($gpPayment['gcash_reference'] ?? ''));
$gpHasProof = trim((string) ($gpPayment['proof_path'] ?? '')) !== '';
$gpRejection = trim((string) ($gpPayment['rejection_reason'] ?? ''));
$gpVerifiedAt = $gpPayment['verified_at'] ?? null;
$gpSubmittedAt = $gpPayment['submitted_at'] ?? null;

$gpOrder = $gp['order'] ?? [];
$gpShipment = $gp['shipment'] ?? null;
$gpCancelled = ($gpOrder['status'] ?? '') === 'CANCELLED' || ($gpOrder['cancellation_status'] ?? '') === 'APPROVED';
$gpSubmitReason = payment_gcash_submission_reason($gpOrder, $gpPayment, $gpShipment);
$gpCanSubmit = $gpSubmitReason === null && (!empty($gpConfig['configured']) || $gpState === 'REJECTED');
$gpWindow = payment_gcash_recovery_window($gpPayment);
$gpSeconds = (int) ($gpWindow['remaining_seconds'] ?? 0);
$gpRemaining = $gpSeconds < 60 ? 'Less than a minute remaining'
    : intdiv($gpSeconds, 3600) . ' hours ' . intdiv($gpSeconds % 3600, 60) . ' minutes remaining';
$gpCashMethod = payment_gcash_recovery_reason($gpOrder, $gpPayment, $gpShipment) === null
    ? payment_gcash_cash_method($gpShipment) : null;
$gpCashOption = $gpCashMethod !== null
    ? shipping_payment_methods_for_fulfillment($gpShipment['delivery_method'])[$gpCashMethod] : null;
$gpStateLabel = payment_gcash_state_label($gpState);
$gpStateBadge = payment_gcash_state_badge($gpState);
$gpReopenLabel = $gpState === 'NOT_SUBMITTED' && !$gpCancelled ? 'Pay with GCash' : 'View GCash payment details';
?>

<?php if ($gpModal): ?>
    <?php /*
     * Reopen trigger. Clearly visible on the Order Confirmation page so the
     * customer who closes the modal (without submitting) can always get back
     * to the payment instructions and the single submission form.
     */ ?>
    <aside class="gcash-reopen" aria-label="GCash payment">
        <button type="button" class="btn btn-primary gcash-reopen__btn" data-gcash-modal-open aria-haspopup="dialog">
            <?= hopia_checkout_icon('gcash') ?>
            <span><?= hopia_e($gpReopenLabel) ?></span>
        </button>
        <p class="gcash-reopen__hint">
            Open to review your GCash payment status and any available payment actions.
        </p>
    </aside>

    <?php /*
     * Centered payment modal. Contains the SAME single panel/form below -
     * no duplicate fields, no second handler. Hidden until opened; a
     * <noscript> style at the end of this partial de-modalizes it when
     * JavaScript is unavailable, so the form stays reachable without JS.
     */ ?>
    <div class="gcash-modal" id="<?= hopia_e($gpDomId) ?>-modal" hidden>
        <div class="gcash-modal__backdrop" data-gcash-modal-close></div>

        <div class="gcash-modal__panel" role="dialog" aria-modal="true" aria-labelledby="<?= hopia_e($gpDomId) ?>-modal-title">
            <div class="gcash-modal__head">
                <p class="gcash-modal__eyebrow" id="<?= hopia_e($gpDomId) ?>-modal-title">Complete your payment</p>
                <button type="button" class="gcash-modal__close" data-gcash-modal-close aria-label="Close GCash payment instructions">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M18 6 6 18"></path>
                        <path d="M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="gcash-modal__body">
<?php endif; ?>

                <section class="gcash-panel" id="<?= hopia_e($gpDomId) ?>" aria-labelledby="<?= hopia_e($gpDomId) ?>-title">
                    <div class="gcash-panel__head">
                        <div class="gcash-panel__title-wrap">
                            <?= hopia_checkout_icon('gcash') ?>
                            <h2 class="gcash-panel__title" id="<?= hopia_e($gpDomId) ?>-title">GCash payment</h2>
                        </div>
                        <?php if ($gpStateLabel !== ''): ?>
                            <span class="badge <?= hopia_e($gpStateBadge) ?>"><?= hopia_e($gpStateLabel) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (($gp['flash'] ?? '') === 'resubmitted' && $gpState === 'AWAITING' && !$gpCancelled): ?>
                        <p class="alert alert-success" role="status">
                            Your corrected GCash payment has been submitted and is awaiting admin verification.
                        </p>
                    <?php elseif (($gp['flash'] ?? '') === 'submitted' && $gpState === 'AWAITING' && !$gpCancelled): ?>
                        <p class="alert alert-success" role="status">
                            Thank you. Your GCash reference has been submitted and is awaiting verification by our team.
                        </p>
                    <?php elseif (($gp['flash'] ?? '') === 'error'): ?>
                        <p class="alert alert-error" role="alert">
                            <?= hopia_e(($gp['flash_message'] ?? '') !== '' ? $gp['flash_message'] : 'Your GCash details could not be submitted. Please review and try again.') ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($gpState === 'REJECTED'): ?>
                        <p class="gcash-panel__note gcash-panel__note--bad" role="status">
                            Your GCash payment was not verified.
                            <?php if ($gpRejection !== ''): ?>
                                <br><strong>Rejection reason:</strong> <?= hopia_e($gpRejection) ?>
                            <?php endif; ?>
                            <?php if ($gpCancelled): ?>
                                <br>This order has been cancelled. Payment recovery is no longer available.
                            <?php elseif ($gpWindow['deadline_at'] === null): ?>
                                <br>The resubmission deadline is unavailable. Please contact the shop for assistance.
                            <?php elseif ($gpWindow['expired']): ?>
                                <br>The 24-hour resubmission period has expired. Your order may be cancelled by the shop if payment is not corrected. Please contact the shop if you need assistance.
                            <?php else: ?>
                                <br>Please correct your payment details and resubmit within 24 hours of the rejection. If you do not resubmit within this period, the shop may cancel your order.
                            <?php endif; ?>
                            <?php if ($gpWindow['deadline_at'] !== null): ?>
                                <br><strong>Resubmission deadline:</strong> <?= hopia_e(date('M j, Y g:i A', strtotime($gpWindow['deadline_at']))) ?> (Philippine time, UTC+8).
                                <?php if (!$gpWindow['expired'] && !$gpCancelled): ?>
                                    <br><?= hopia_e($gpRemaining) ?> as of this page load.
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
                    <?php elseif ($gpCancelled): ?>
                        <p class="gcash-panel__note">This order has been cancelled. Payment recovery is no longer available.</p>
                    <?php elseif ($gpState === 'PAID'): ?>
                        <p class="gcash-panel__note gcash-panel__note--ok">
                            We have verified your GCash payment<?= $gpVerifiedAt ? ' on ' . hopia_e(date('M j, Y g:i A', strtotime((string) $gpVerifiedAt))) : '' ?>.
                            No further action is needed.
                        </p>
                    <?php elseif ($gpState === 'AWAITING'): ?>
                        <p class="gcash-panel__note">
                            We received your reference and are verifying your payment against our GCash account.
                            Please wait for the shop to review it before submitting again. Your payment is not yet verified.
                        </p>
                    <?php elseif ($gpCanSubmit): ?>
                        <p class="gcash-panel__note">Send the exact amount below to our GCash account, then submit your reference for verification.</p>
                    <?php endif; ?>

                    <?php if (!$gpCancelled && in_array($gpState, ['NOT_SUBMITTED', 'REJECTED'], true)
                        && $gpSubmitReason !== null && !in_array($gpSubmitReason, ['deadline_expired', 'deadline_unknown'], true)): ?>
                        <p class="gcash-panel__note"><?= hopia_e(payment_message($gpSubmitReason)) ?></p>
                    <?php endif; ?>

                    <?php if (empty($gpConfig['configured'])): ?>

                        <p class="alert alert-warning" role="status">
                            <?php if ($gpState === 'REJECTED' && $gpCanSubmit): ?>
                                GCash account details are temporarily unavailable. You can still correct the details of a payment already sent; contact the shop before sending another payment.
                            <?php else: ?>
                                GCash payment is temporarily unavailable. Please contact the shop to arrange payment for this order.
                            <?php endif; ?>
                        </p>

                    <?php else: ?>

                        <div class="gcash-panel__account">
                            <dl class="gcash-panel__details">
                                <div class="gcash-panel__row gcash-panel__row--amount">
                                    <dt>Amount due</dt>
                                    <dd class="gcash-panel__amount">&#8369;<?= hopia_e($gpAmount) ?></dd>
                                </div>
                                <div class="gcash-panel__row">
                                    <dt>Account name</dt>
                                    <dd><?= hopia_e($gpConfig['name']) ?></dd>
                                </div>
                                <div class="gcash-panel__row">
                                    <dt>GCash number</dt>
                                    <dd class="gcash-panel__number-row">
                                        <span class="gcash-panel__number" data-gcash-number><?= hopia_e($gpConfig['number']) ?></span>
                                        <button type="button" class="btn btn-secondary btn-sm gcash-panel__copy" data-gcash-copy aria-label="Copy GCash number">
                                            Copy number
                                        </button>
                                        <span class="gcash-panel__copied" data-gcash-copied hidden role="status">Copied</span>
                                    </dd>
                                </div>
                            </dl>

                            <?php if (!empty($gpConfig['has_qr'])): ?>
                                <figure class="gcash-panel__qr">
                                    <img src="<?= hopia_e(hopia_asset($gpConfig['qr'])) ?>" alt="GCash QR code for <?= hopia_e($gpConfig['name']) ?>" loading="lazy" width="180" height="180">
                                    <figcaption>Scan to pay in the GCash app</figcaption>
                                </figure>
                            <?php endif; ?>
                        </div>

                        <?php if ($gpState === 'NOT_SUBMITTED' && $gpCanSubmit): ?>
                        <ol class="gcash-panel__steps">
                            <li>Open your GCash app and send <strong>&#8369;<?= hopia_e($gpAmount) ?></strong> to the account above.</li>
                            <li>Note the <strong>reference number</strong> shown on your GCash receipt.</li>
                            <li>Enter that reference below (optionally attach a screenshot) and submit.</li>
                            <li>Our team verifies the transfer in our GCash account before marking the order paid.</li>
                        </ol>
                        <?php endif; ?>

                    <?php endif; ?>

                        <?php if ($gpReference !== ''): ?>
                            <div class="gcash-panel__submitted">
                                <p class="gcash-panel__submitted-line">
                                    <span>Your reference</span>
                                    <code><?= hopia_e($gpReference) ?></code>
                                </p>
                                <?php if ($gpSubmittedAt): ?>
                                    <p class="text-small muted">Submitted <?= hopia_e(date('M j, Y g:i A', strtotime((string) $gpSubmittedAt))) ?></p>
                                <?php endif; ?>
                                <?php if ($gpHasProof): ?>
                                    <p class="gcash-panel__submitted-line">
                                        <span>Your screenshot</span>
                                        <a href="<?= hopia_e($gpProofEndpoint . '?order=' . $gpOrderId) ?>" target="_blank" rel="noopener noreferrer">View uploaded screenshot</a>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($gpCanSubmit): ?>
                            <form class="gcash-panel__form" method="POST" action="payment-submit.php" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= hopia_e($gp['csrf'] ?? '') ?>">
                                <input type="hidden" name="order_id" value="<?= $gpOrderId ?>">
                                <input type="hidden" name="return" value="<?= hopia_e($gpReturn) ?>">
                                <input type="hidden" name="action" value="submit_gcash">
                                <input type="hidden" name="payment_revision" value="<?= (int) ($gpPayment['revision'] ?? 0) ?>">

                                <div class="field">
                                    <label for="<?= hopia_e($gpDomId) ?>-ref"><?= $gpReference !== '' ? 'Update GCash reference number' : 'GCash reference number' ?></label>
                                    <input
                                        type="text"
                                        id="<?= hopia_e($gpDomId) ?>-ref"
                                        name="gcash_reference"
                                        maxlength="64"
                                        inputmode="latin"
                                        autocomplete="off"
                                        value="<?= hopia_e($gpReference) ?>"
                                        required
                                    >
                                    <p class="hint">Enter the reference exactly as shown on your GCash receipt.</p>
                                </div>

                                <div class="field">
                                    <label for="<?= hopia_e($gpDomId) ?>-proof">Payment screenshot <span class="muted">(optional)</span></label>
                                    <input
                                        type="file"
                                        id="<?= hopia_e($gpDomId) ?>-proof"
                                        name="payment_proof"
                                        accept="image/jpeg,image/png,image/webp"
                                    >
                                    <p class="hint">JPG, PNG, or WebP, up to 5 MB. Only you and the shop can view it.</p>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <?= $gpState === 'REJECTED' ? 'Resubmit GCash Payment' : 'Submit GCash Payment' ?>
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($gpCashMethod !== null): ?>
                            <form class="gcash-panel__form" method="POST" action="payment-submit.php"
                                  onsubmit="return confirm('Change this order to the selected cash payment method? Payment will still be due.');">
                                <input type="hidden" name="csrf_token" value="<?= hopia_e($gp['csrf'] ?? '') ?>">
                                <input type="hidden" name="order_id" value="<?= $gpOrderId ?>">
                                <input type="hidden" name="return" value="order-detail.php">
                                <input type="hidden" name="action" value="switch_to_cash">
                                <input type="hidden" name="payment_revision" value="<?= (int) ($gpPayment['revision'] ?? 0) ?>">
                                <div class="field">
                                    <label for="<?= hopia_e($gpDomId) ?>-cash">Prefer to pay in cash?</label>
                                    <select id="<?= hopia_e($gpDomId) ?>-cash" name="payment_method" required>
                                        <option value="<?= hopia_e($gpCashMethod) ?>"><?= hopia_e($gpCashOption['label']) ?></option>
                                    </select>
                                    <p class="hint"><?= hopia_e($gpCashOption['description']) ?> Changing the method does not mark your payment as collected.</p>
                                </div>
                                <button type="submit" class="btn btn-secondary">Change Payment Method</button>
                            </form>
                        <?php endif; ?>

                        <script>
                        (function () {
                            var panel = document.getElementById(<?= json_encode($gpDomId) ?>);
                            if (!panel || panel.dataset.gcashBound === '1') {
                                return;
                            }
                            panel.dataset.gcashBound = '1';

                            var copyBtn = panel.querySelector('[data-gcash-copy]');
                            var numberEl = panel.querySelector('[data-gcash-number]');
                            var copied = panel.querySelector('[data-gcash-copied]');
                            if (!copyBtn || !numberEl) {
                                return;
                            }

                            copyBtn.addEventListener('click', function () {
                                var value = (numberEl.textContent || '').replace(/\s+/g, '');
                                var done = function () {
                                    if (copied) {
                                        copied.hidden = false;
                                        window.setTimeout(function () { copied.hidden = true; }, 2000);
                                    }
                                };
                                if (navigator.clipboard && navigator.clipboard.writeText) {
                                    navigator.clipboard.writeText(value).then(done).catch(function () { done(); });
                                } else {
                                    var tmp = document.createElement('textarea');
                                    tmp.value = value;
                                    tmp.setAttribute('readonly', '');
                                    tmp.style.position = 'absolute';
                                    tmp.style.left = '-9999px';
                                    document.body.appendChild(tmp);
                                    tmp.select();
                                    try { document.execCommand('copy'); } catch (e) {}
                                    document.body.removeChild(tmp);
                                    done();
                                }
                            });
                        })();
                        </script>

                </section>

<?php if ($gpModal): ?>
            </div><!-- .gcash-modal__body -->
        </div><!-- .gcash-modal__panel -->
    </div><!-- .gcash-modal -->

    <noscript>
        <style>
            /* Without JavaScript the modal degrades to an inline panel so the
               single payment form stays visible and usable. */
            .gcash-modal { position: static; display: block; padding: 0; }
            .gcash-modal[hidden] { display: block; }
            .gcash-modal__backdrop { display: none; }
            .gcash-modal__panel { max-height: none; overflow: visible; box-shadow: none; border: 0; border-radius: 0; padding: 0; background: transparent; }
            .gcash-modal__head { display: none; }
            .gcash-reopen { display: none; }
        </style>
    </noscript>

    <script>
    (function () {
        'use strict';

        var modal = document.getElementById(<?= json_encode($gpDomId) ?> + '-modal');
        if (!modal || modal.dataset.gcashModalBound === '1') {
            return;
        }
        modal.dataset.gcashModalBound = '1';

        var panel = modal.querySelector('.gcash-modal__panel');
        var openTrigger = document.querySelector('[data-gcash-modal-open]');
        var closeBtn = modal.querySelector('.gcash-modal__close');
        var lastFocused = null;

        /* One automatic open per order per browser session: the customer who
           just placed a GCash order immediately sees how to pay, while a
           refresh or revisit of the same order page does not re-open it. */
        var sessionKey = 'hopiaGcashModalShown.<?= (int) $gpOrderId ?>';
        var flash = <?= json_encode((string) ($gp['flash'] ?? '')) ?>;
        var state = <?= json_encode($gpState) ?>;

        function alreadyShownThisSession() {
            try {
                return window.sessionStorage.getItem(sessionKey) === '1';
            } catch (e) {
                return false;
            }
        }

        function markShownThisSession() {
            try {
                window.sessionStorage.setItem(sessionKey, '1');
            } catch (e) {
                /* Private modes may refuse storage; the flag is best-effort. */
            }
        }

        function openModal() {
            lastFocused = document.activeElement;
            modal.hidden = false;
            document.body.classList.add('is-modal-open');
            if (closeBtn) {
                closeBtn.focus();
            }
        }

        function closeModal() {
            modal.hidden = true;
            document.body.classList.remove('is-modal-open');
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
            lastFocused = null;
        }

        if (openTrigger) {
            openTrigger.addEventListener('click', openModal);
        }

        modal.addEventListener('click', function (event) {
            if (event.target.hasAttribute && event.target.hasAttribute('data-gcash-modal-close')) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (modal.hidden) {
                return;
            }

            if (event.key === 'Escape') {
                closeModal();
                return;
            }

            if (event.key !== 'Tab' || !panel) {
                return;
            }

            /* Keep Tab focus inside the dialog (same approach as the
               checkout location modal). */
            var focusables = panel.querySelectorAll('button:not([hidden]):not([disabled]), input, [href], select, textarea');

            if (focusables.length === 0) {
                return;
            }

            var first = focusables[0];
            var last = focusables[focusables.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        /* Consume the submission flash from the URL so a refresh of the
           post-submit page does not look like a fresh submission result. */
        if (flash === 'submitted' || flash === 'resubmitted' || flash === 'error') {
            try {
                var url = new URL(window.location.href);
                url.searchParams.delete('gcash');
                url.searchParams.delete('gcash_reason');
                window.history.replaceState({}, '', url.toString());
            } catch (e) {
                /* History API is best-effort only. */
            }
        }

        /* Automatic open: after arriving from a successful GCash order
           placement (once per session), or right after the submit/redirect
           back so the result message is actually seen. Never for an
           already-verified payment. */
        var shouldAutoOpen =
            (flash === 'submitted' || flash === 'resubmitted' || flash === 'error')
            || (!alreadyShownThisSession() && state !== 'PAID');

        if (shouldAutoOpen) {
            openModal();
            markShownThisSession();
        }
    })();
    </script>
<?php endif; ?>
