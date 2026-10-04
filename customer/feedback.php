<?php

session_start();

/*
 * Public Customer's Feedback page.
 *
 * Read-only presentation of real customer_feedback records. Anonymous
 * visitors can view feedback; no login is required. The first batch is
 * rendered server-side and further batches are appended through
 * customer/feedback-load.php, so there is no infinite scroll and no
 * polling or real-time update. There is no admin approval workflow in this
 * project: every stored feedback row is public by existence.
 */

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/feedback-data.php';

/*
 * Initial batch only. A database problem degrades to the existing empty
 * state instead of exposing an error to the visitor.
 */

try {
    $feedbackBatch = hopia_feedback_fetch_batch($pdo, 0, HOPIA_FEEDBACK_BATCH_SIZE);
} catch (PDOException $e) {
    error_log('Public feedback page load failed: ' . $e->getMessage());
    $feedbackBatch = ['entries' => [], 'has_more' => false];
}

$feedbackEntries = $feedbackBatch['entries'];

$feedbackCount = count($feedbackEntries);

$feedbackHasMore = $feedbackBatch['has_more'];

/*
 * Owner correlation for the EDIT action. When a customer is logged in,
 * their own feedback rows are fetched separately (scoped to their
 * customer_id) and matched to the public cards by feedback id. Only the
 * owner's cards are flagged, so the EDIT action never appears on another
 * customer's feedback and never appears for anonymous visitors.
 */

$customerId = isset($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : null;
$ownFeedbackRows = $customerId !== null
    ? hopia_feedback_fetch_customer_rows($pdo, $customerId)
    : [];

foreach ($feedbackEntries as $index => $card) {
    $ownRow = $ownFeedbackRows[(int) $card['id']] ?? null;

    if ($ownRow !== null) {
        $feedbackEntries[$index]['is_own'] = true;
        $feedbackEntries[$index]['edit'] = hopia_feedback_edit_payload($ownRow);
    }
}

$page_title = "Customer's Feedback - " . hopia_site_name();
$page_description = 'What our customers say about their Hopia Fits finds.';
$ui_active = 'feedback.php';
$body_class = 'feedback-page-body';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';

?>

<section class="feedback-page" aria-labelledby="feedback-page-title">
    <header class="feedback-page__header">
        <h1 class="feedback-page__title" id="feedback-page-title">CUSTOMER'S FEEDBACK</h1>
        <p class="feedback-page__intro">What our customers say about their Hopia Fits finds.</p>
    </header>

    <?php if ($feedbackCount === 0): ?>

        <div class="feedback-empty">
            <p class="feedback-empty__title">NO CUSTOMER FEEDBACK YET.</p>
            <p class="feedback-empty__copy">Check back soon to see what our customers say about their Hopia Fits finds.</p>
        </div>

    <?php else: ?>

        <ul class="feedback-grid" role="list">
            <?php foreach ($feedbackEntries as $card): ?>
                <?php require __DIR__ . '/../includes/ui.feedback-card.php'; ?>
            <?php endforeach; ?>
        </ul>

    <?php endif; ?>

    <div class="feedback-loadmore"<?= $feedbackHasMore ? '' : ' hidden' ?>>
        <button class="feedback-loadmore__button" type="button">LOAD MORE</button>
        <p class="feedback-loadmore__status" role="status" hidden></p>
    </div>
</section>

<script>
    (function () {
        var section = document.querySelector('.feedback-page');
        if (!section) {
            return;
        }

        var grid = section.querySelector('.feedback-grid');
        var loadMore = section.querySelector('.feedback-loadmore');
        var button = loadMore ? loadMore.querySelector('.feedback-loadmore__button') : null;
        var status = loadMore ? loadMore.querySelector('.feedback-loadmore__status') : null;

        if (!grid || !loadMore || !button) {
            return;
        }

        function setStatus(message) {
            if (!status) {
                return;
            }
            status.textContent = message;
            status.hidden = message === '';
        }

        /* Manual LOAD MORE only: the next batch is requested once per click
           and appended below the cards already on screen, which are never
           reloaded or replaced. */
        button.addEventListener('click', function () {
            var offset = grid.querySelectorAll('.feedback-card').length;

            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            setStatus('Loading more feedback...');

            fetch('feedback-load.php?offset=' + encodeURIComponent(String(offset)), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return null;
                    });
                })
                .then(function (data) {
                    button.disabled = false;
                    button.removeAttribute('aria-busy');

                    if (!data || !data.ok || typeof data.html !== 'string') {
                        setStatus('Something went wrong. Please try again.');
                        return;
                    }

                    if (data.html !== '') {
                        grid.insertAdjacentHTML('beforeend', data.html);
                    }

                    setStatus('');

                    if (!data.has_more) {
                        loadMore.hidden = true;
                    }
                })
                .catch(function () {
                    button.disabled = false;
                    button.removeAttribute('aria-busy');
                    setStatus('Something went wrong. Please try again.');
                });
        });
    })();
</script>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
