<?php

/*
 * Public Customer's Feedback LOAD MORE endpoint.
 *
 * Anonymous visitors can read feedback: there is no session, no
 * authentication, and no admin approval workflow (this project has none).
 * Every stored feedback row is public by existence.
 *
 * GET offset=<int>   number of cards the page has already shown (default 0).
 *
 * Responds with JSON: { ok, html, has_more }.
 *   html     - the escaped, server-rendered card markup for the next batch.
 *   has_more - whether another batch exists after this batch.
 *
 * The page appends html below the existing cards and hides LOAD MORE when
 * has_more is false. No database id, order id, order item id, email, or
 * phone is ever returned.
 *
 * When a customer is logged in, their own rows in the batch are flagged
 * with is_own + an edit payload so appended cards can show the same
 * owner-only EDIT action as the first batch. The flag is derived from a
 * customer-scoped query, so another customer's cards are never flagged and
 * anonymous visitors never see edit controls.
 */

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/feedback-data.php';

session_start();

header('Content-Type: application/json; charset=UTF-8');

$offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);

if ($offset === false || $offset === null || $offset < 0) {
    $offset = 0;
}

$customerId = isset($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : null;
$ownFeedbackRows = $customerId !== null
    ? hopia_feedback_fetch_customer_rows($pdo, $customerId)
    : [];

try {
    $batch = hopia_feedback_fetch_batch($pdo, $offset, HOPIA_FEEDBACK_BATCH_SIZE);
} catch (PDOException $e) {
    error_log('Public feedback load failed: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'html' => '',
        'has_more' => false,
    ]);
    exit;
}

foreach ($batch['entries'] as $index => $entry) {
    $ownRow = $ownFeedbackRows[(int) $entry['id']] ?? null;

    if ($ownRow !== null) {
        $batch['entries'][$index]['is_own'] = true;
        $batch['entries'][$index]['edit'] = hopia_feedback_edit_payload($ownRow);
    }
}

echo json_encode([
    'ok' => true,
    'html' => hopia_feedback_render_cards($batch['entries']),
    'has_more' => $batch['has_more'],
]);
