<?php

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/image-upload.php';

/*
 * Customer Feedback submission endpoint.
 *
 * Receives the feedback form submission from the shared overlay
 * (includes/ui.feedback-overlay.php) and persists it to the
 * customer_feedback table. Every business rule is enforced here;
 * the front-end validation is never trusted:
 *
 *   1. The customer must be authenticated.
 *   2. rating must be an integer from 1 to 5.
 *   3. feedback_text must not be empty after trimming.
 *   4. order_id must belong to the authenticated customer.
 *   5. order_item_id must belong to that order.
 *   6. The order must be DELIVERED.
 *   7. The order item must not already have a feedback row.
 *
 * The photo is optional. When one is attached it is validated and stored
 * server-side using the project's shared image upload conventions
 * (includes/image-upload.php): finfo content-type checks, a 5 MB limit, a
 * random filename, and storage under images/feedback/. Only the resulting
 * relative path is saved in photo_path, which stays NULL when no photo is
 * attached. The photo is stored only after every other rule has passed and
 * just before the insert, so a failed validation never creates a record,
 * and a failed insert never leaves an orphaned file (see the catch block).
 */

function feedbackRespondJson(array $payload, int $status): void
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    feedbackRespondJson([
        'ok' => false,
        'reason' => 'method',
        'message' => 'Feedback must be submitted using the feedback form.',
    ], 405);
}

/*
 * 1. Authentication. Anonymous customers cannot submit feedback.
 */

if (!isset($_SESSION['customer_id'])) {
    feedbackRespondJson([
        'ok' => false,
        'reason' => 'auth',
        'message' => 'Please log in to submit feedback.',
    ], 401);
}

$customerId = (int) $_SESSION['customer_id'];

/*
 * 2. Input reception and server-side validation.
 */

$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$orderItemId = filter_input(INPUT_POST, 'order_item_id', FILTER_VALIDATE_INT);
$rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 5],
]);
$feedbackText = filter_input(INPUT_POST, 'feedback_text', FILTER_DEFAULT);

if ($orderId === false || $orderId === null || $orderId < 1) {
    feedbackRespondJson([
        'ok' => false,
        'reason' => 'invalid_order',
        'message' => 'The submitted order is invalid.',
    ], 400);
}

if ($orderItemId === false || $orderItemId === null || $orderItemId < 1) {
    feedbackRespondJson([
        'ok' => false,
        'reason' => 'invalid_item',
        'message' => 'The submitted item is invalid.',
    ], 400);
}

if ($rating === false || $rating === null) {
    feedbackRespondJson([
        'ok' => false,
        'reason' => 'invalid_rating',
        'message' => 'Please choose a rating from 1 to 5 stars.',
    ], 400);
}

if ($feedbackText === null || trim((string) $feedbackText) === '') {
    feedbackRespondJson([
        'ok' => false,
        'reason' => 'invalid_text',
        'message' => 'Please write a few words about your find.',
    ], 400);
}

$feedbackText = trim((string) $feedbackText);

try {
    /*
     * 3. Ownership + 6. Order status. The order must belong to the
     * authenticated customer and must be DELIVERED. Both conditions
     * are checked in a single query, so an order that exists but
     * belongs to someone else is reported the same way as an order
     * that does not exist at all.
     */

    $orderStmt = $pdo->prepare(
        'SELECT id, status
         FROM orders
         WHERE id = :order_id
           AND customer_id = :customer_id
         LIMIT 1'
    );

    $orderStmt->execute([
        ':order_id' => $orderId,
        ':customer_id' => $customerId,
    ]);

    $order = $orderStmt->fetch();

    if ($order === false) {
        feedbackRespondJson([
            'ok' => false,
            'reason' => 'not_found',
            'message' => 'We could not find this order.',
        ], 404);
    }

    if ($order['status'] !== 'DELIVERED') {
        feedbackRespondJson([
            'ok' => false,
            'reason' => 'not_delivered',
            'message' => 'Feedback can only be submitted for delivered orders.',
        ], 409);
    }

    /*
     * 4. The order item must belong to the verified order.
     */

    $itemStmt = $pdo->prepare(
        'SELECT id
         FROM order_items
         WHERE id = :order_item_id
           AND order_id = :order_id
         LIMIT 1'
    );

    $itemStmt->execute([
        ':order_item_id' => $orderItemId,
        ':order_id' => $orderId,
    ]);

    if ($itemStmt->fetch() === false) {
        feedbackRespondJson([
            'ok' => false,
            'reason' => 'invalid_item',
            'message' => 'This item is not part of the order.',
        ], 400);
    }

    /*
     * 5. Duplicate feedback. UNIQUE(order_item_id) in the table is the
     * database backstop; this check provides a clean customer-facing
     * message before the insert is attempted.
     */

    $duplicateStmt = $pdo->prepare(
        'SELECT id
         FROM customer_feedback
         WHERE order_item_id = :order_item_id
         LIMIT 1'
    );

    $duplicateStmt->execute([':order_item_id' => $orderItemId]);

    if ($duplicateStmt->fetch() !== false) {
        feedbackRespondJson([
            'ok' => false,
            'reason' => 'duplicate',
            'message' => 'You have already submitted feedback for this item.',
        ], 409);
    }

    /*
     * 7. Optional photo. Stored only after every other rule has passed, so
     * an invalid order, item, or duplicate never leaves a file on disk. A
     * validation failure returns a clean error and no record is created; a
     * storage failure is reported as a generic server error. The returned
     * value is a relative path such as "images/feedback/<random>.jpg", or
     * NULL when the customer attached no photo.
     */

    $photoPath = null;

    try {
        $photoPath = imageUploadStore($_FILES['photo'] ?? null, 'feedback');
    } catch (InvalidArgumentException $e) {
        feedbackRespondJson([
            'ok' => false,
            'reason' => 'invalid_photo',
            'message' => $e->getMessage(),
        ], 400);
    } catch (RuntimeException $e) {
        error_log('Customer feedback photo storage failed: ' . $e->getMessage());
        feedbackRespondJson([
            'ok' => false,
            'reason' => 'photo_error',
            'message' => $e->getMessage(),
        ], 500);
    }

    /*
     * 8. Insert. photo_path holds the stored relative path, or NULL when no
     * photo was attached. created_at and updated_at are populated by the
     * table defaults.
     */

    $insertStmt = $pdo->prepare(
        'INSERT INTO customer_feedback
            (customer_id, order_id, order_item_id, rating, feedback_text, photo_path)
         VALUES
            (:customer_id, :order_id, :order_item_id, :rating, :feedback_text, :photo_path)'
    );

    $insertStmt->execute([
        ':customer_id' => $customerId,
        ':order_id' => $orderId,
        ':order_item_id' => $orderItemId,
        ':rating' => $rating,
        ':feedback_text' => $feedbackText,
        ':photo_path' => $photoPath,
    ]);
} catch (PDOException $e) {
    /*
     * 9/10/11. Prepared statements are used throughout, and no SQL error,
     * stack trace, or credential is ever exposed to the customer. A
     * duplicate-key violation from a concurrent submission that raced
     * past the duplicate check is reported as a clean duplicate error.
     *
     * If the photo was already stored when the insert failed, remove it so
     * no orphaned upload is left behind. $photoPath is a self-generated
     * relative path (random filename, whitelisted extension), so rebuilding
     * the absolute path here cannot be influenced by the client.
     */

    if (isset($photoPath) && $photoPath !== null) {
        $storedPhoto = __DIR__ . '/../' . $photoPath;

        if (is_file($storedPhoto)) {
            unlink($storedPhoto);
        }
    }

    if ($e->getCode() === '23000') {
        feedbackRespondJson([
            'ok' => false,
            'reason' => 'duplicate',
            'message' => 'You have already submitted feedback for this item.',
        ], 409);
    }

    error_log('Customer feedback submission failed: ' . $e->getMessage());

    feedbackRespondJson([
        'ok' => false,
        'reason' => 'error',
        'message' => 'Something went wrong. Please try again later.',
    ], 500);
}

feedbackRespondJson([
    'ok' => true,
    'reason' => 'submitted',
], 200);
