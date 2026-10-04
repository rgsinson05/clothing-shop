<?php

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/image-upload.php';

/*
 * Customer Feedback update endpoint (EDIT FEEDBACK).
 *
 * Receives the EDIT FEEDBACK submission from the shared overlay
 * (includes/ui.feedback-overlay.php) and UPDATES the existing
 * customer_feedback row in place. No new row is ever created and the
 * schema is untouched: the UPDATE only sets rating, feedback_text,
 * photo_path, and updated_at, so id, customer_id, order_id,
 * order_item_id, and created_at keep their stored values.
 *
 * Every business rule is enforced here; front-end validation is never
 * trusted:
 *
 *   1. The customer must be authenticated.
 *   2. rating must be an integer from 1 to 5.
 *   3. feedback_text must not be empty after trimming.
 *   4. The feedback row must belong to the authenticated customer.
 *      The row is looked up by customer_id + order_item_id, so the
 *      browser-supplied ids only select which of the customer's own
 *      rows is meant; they can never address someone else's row.
 *   5. The row's original order must still be DELIVERED and must still
 *      contain the row's original order item. The row's stored
 *      order_id/order_item_id are used for this check, never the
 *      browser-supplied ones.
 *
 * Photo editing behavior, driven by the optional photo upload plus the
 * photo_action field the overlay sends:
 *   keep    - photo_path is left unchanged (CASE 1).
 *   replace - the new photo is validated and stored with the shared
 *             imageUploadStore() rules (finfo content check, 5 MB limit,
 *             random filename under images/feedback/), photo_path is
 *             updated to the new relative path, and the previous file
 *             is removed only after the database update succeeds
 *             (CASE 2).
 *   remove  - photo_path becomes NULL and the previous file is removed
 *             only after the database update succeeds (CASE 3/4).
 *
 * Failure handling: when a new photo was stored but the database update
 * fails, the newly uploaded file is removed and the stored record is
 * left unchanged. No SQL error, filesystem path, or stack trace is ever
 * exposed to the customer.
 */

function feedbackUpdateRespondJson(array $payload, int $status): void
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

/*
 * Remove a stored feedback photo when it is safe to do so.
 *
 * The path comes from the database (or from imageUploadStore(), which
 * generates it), never directly from the browser, but it is still
 * constrained to the images/feedback/ directory before anything is
 * unlinked, so a corrupted or crafted value can never reach outside
 * that folder.
 */
function feedbackUpdateRemovePhoto(string $photoPath): void
{
    $photoPath = trim($photoPath);

    if ($photoPath === '' || strpos($photoPath, 'images/feedback/') !== 0) {
        return;
    }

    $feedbackDir = realpath(__DIR__ . '/../images/feedback');
    $real = realpath(__DIR__ . '/../' . $photoPath);

    if ($feedbackDir === false || $real === false) {
        return;
    }

    if (strpos($real, $feedbackDir . DIRECTORY_SEPARATOR) !== 0) {
        return;
    }

    if (is_file($real)) {
        @unlink($real);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    feedbackUpdateRespondJson([
        'ok' => false,
        'reason' => 'method',
        'message' => 'Feedback must be updated using the feedback form.',
    ], 405);
}

/*
 * 1. Authentication. The customer_id always comes from the session,
 *    never from the request.
 */

if (!isset($_SESSION['customer_id'])) {
    feedbackUpdateRespondJson([
        'ok' => false,
        'reason' => 'auth',
        'message' => 'Please log in to update feedback.',
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
$photoAction = trim((string) filter_input(INPUT_POST, 'photo_action', FILTER_DEFAULT));

if ($orderId === false || $orderId === null || $orderId < 1) {
    feedbackUpdateRespondJson([
        'ok' => false,
        'reason' => 'invalid_order',
        'message' => 'The submitted order is invalid.',
    ], 400);
}

if ($orderItemId === false || $orderItemId === null || $orderItemId < 1) {
    feedbackUpdateRespondJson([
        'ok' => false,
        'reason' => 'invalid_item',
        'message' => 'The submitted item is invalid.',
    ], 400);
}

if ($rating === false || $rating === null) {
    feedbackUpdateRespondJson([
        'ok' => false,
        'reason' => 'invalid_rating',
        'message' => 'Please choose a rating from 1 to 5 stars.',
    ], 400);
}

if ($feedbackText === null || trim((string) $feedbackText) === '') {
    feedbackUpdateRespondJson([
        'ok' => false,
        'reason' => 'invalid_text',
        'message' => 'Please write a few words about your find.',
    ], 400);
}

$feedbackText = trim((string) $feedbackText);

$storedNewPhoto = null;

try {
    /*
     * 3. Ownership. The row is looked up by the authenticated
     * customer's id plus the order item, so a customer can only ever
     * receive their own feedback row. UNIQUE(order_item_id) in the table
     * guarantees at most one row matches.
     */

    $feedbackStmt = $pdo->prepare(
        'SELECT id, order_id, order_item_id, photo_path
         FROM customer_feedback
         WHERE order_item_id = :order_item_id
           AND customer_id = :customer_id
         LIMIT 1'
    );

    $feedbackStmt->execute([
        ':order_item_id' => $orderItemId,
        ':customer_id' => $customerId,
    ]);

    $feedback = $feedbackStmt->fetch();

    if ($feedback === false) {
        feedbackUpdateRespondJson([
            'ok' => false,
            'reason' => 'not_found',
            'message' => 'We could not find this feedback.',
        ], 404);
    }

    /*
     * 4. The feedback must still belong to the original delivered order
     * item. The row's stored order_id and order_item_id are used, so the
     * check always validates the order item the feedback was actually
     * submitted for.
     */

    $orderStmt = $pdo->prepare(
        'SELECT o.id
         FROM orders o
         INNER JOIN order_items oi
            ON oi.id = :order_item_id
           AND oi.order_id = o.id
         WHERE o.id = :order_id
           AND o.customer_id = :customer_id
           AND o.status = "DELIVERED"
         LIMIT 1'
    );

    $orderStmt->execute([
        ':order_item_id' => (int) $feedback['order_item_id'],
        ':order_id' => (int) $feedback['order_id'],
        ':customer_id' => $customerId,
    ]);

    if ($orderStmt->fetch() === false) {
        feedbackUpdateRespondJson([
            'ok' => false,
            'reason' => 'not_delivered',
            'message' => 'Feedback can only be edited for delivered orders.',
        ], 409);
    }

    /*
     * 5. Photo. The stored path is the default (CASE 1: no change). A
     * real uploaded file always means replace (CASE 2); otherwise an
     * explicit photo_action=remove means the customer chose REMOVE
     * PHOTO (CASE 3/4). The new photo is validated and stored with the
     * shared imageUploadStore() rules only after every other rule has
     * passed, so a failed validation never updates the record.
     */

    $oldPhotoPath = $feedback['photo_path'] !== null ? (string) $feedback['photo_path'] : '';
    $newPhotoPath = $oldPhotoPath;

    $hasNewPhoto = isset($_FILES['photo']) && is_array($_FILES['photo'])
        && (int) ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        && ($_FILES['photo']['name'] ?? '') !== '';

    if ($hasNewPhoto) {
        try {
            $storedNewPhoto = imageUploadStore($_FILES['photo'], 'feedback');
        } catch (InvalidArgumentException $e) {
            feedbackUpdateRespondJson([
                'ok' => false,
                'reason' => 'invalid_photo',
                'message' => $e->getMessage(),
            ], 400);
        } catch (RuntimeException $e) {
            error_log('Customer feedback photo storage failed: ' . $e->getMessage());
            feedbackUpdateRespondJson([
                'ok' => false,
                'reason' => 'photo_error',
                'message' => 'The photo could not be saved. Please try again.',
            ], 500);
        }

        if ($storedNewPhoto !== null) {
            $newPhotoPath = $storedNewPhoto;
        }
    } elseif ($photoAction === 'remove') {
        $newPhotoPath = null;
    }

    /*
     * 6. Update the existing row in place. Only rating, feedback_text,
     * and photo_path are written; id, customer_id, order_id,
     * order_item_id, and created_at are not in the statement and keep
     * their stored values. updated_at is refreshed explicitly so a
     * successful save always records the edit time.
     */

    $updateStmt = $pdo->prepare(
        'UPDATE customer_feedback
         SET rating = :rating,
             feedback_text = :feedback_text,
             photo_path = :photo_path,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id
           AND customer_id = :customer_id'
    );

    $updateStmt->execute([
        ':rating' => $rating,
        ':feedback_text' => $feedbackText,
        ':photo_path' => $newPhotoPath,
        ':id' => (int) $feedback['id'],
        ':customer_id' => $customerId,
    ]);

    /*
     * 7. Old photo cleanup, only after the database update succeeded.
     * The old file is removed only when it is no longer referenced by
     * the record, so the database never points at a deleted file.
     */

    if ($storedNewPhoto !== null) {
        if ($oldPhotoPath !== '' && $oldPhotoPath !== $storedNewPhoto) {
            feedbackUpdateRemovePhoto($oldPhotoPath);
        }
    } elseif ($newPhotoPath === null && $oldPhotoPath !== '') {
        feedbackUpdateRemovePhoto($oldPhotoPath);
    }
} catch (PDOException $e) {
    /*
     * If a new photo was already stored when the update failed, remove
     * it so no orphaned upload is left behind and the stored record is
     * left unchanged. Prepared statements are used throughout, and no
     * SQL error, stack trace, or credential is ever exposed.
     */

    if ($storedNewPhoto !== null) {
        feedbackUpdateRemovePhoto($storedNewPhoto);
    }

    error_log('Customer feedback update failed: ' . $e->getMessage());

    feedbackUpdateRespondJson([
        'ok' => false,
        'reason' => 'error',
        'message' => 'Something went wrong. Please try again later.',
    ], 500);
}

feedbackUpdateRespondJson([
    'ok' => true,
    'reason' => 'updated',
], 200);
