<?php

/*
 * Shared public Customer's Feedback data access.
 *
 * Used by the public feedback page (customer/feedback.php) and its
 * LOAD MORE endpoint (customer/feedback-load.php), so both render the
 * exact same card markup from the exact same query.
 *
 * Only public-safe columns are read from customer_feedback and customers.
 * The query never selects email, phone, order_id, order_item_id, or the
 * feedback row's own id for public display, because the public card never
 * needs them and they must not be exposed. The row id is read server-side
 * only (never rendered) so the page can correlate a public card with the
 * logged-in customer's own feedback row for the EDIT action. There is no
 * approval/moderation column in this project: every stored feedback row is
 * public.
 */

require_once __DIR__ . '/ui.php';

if (!defined('HOPIA_FEEDBACK_BATCH_SIZE')) {
    define('HOPIA_FEEDBACK_BATCH_SIZE', 12);
}

if (!function_exists('hopia_feedback_display_name')) {
    /**
     * Privacy-filtered customer display name: first name plus last initial.
     * "Maria Santos" becomes "Maria S."; email, phone, and the full last
     * name are never included. Falls back to a neutral label when the
     * stored first name is empty.
     *
     * @param mixed $firstName
     * @param mixed $lastName
     */
    function hopia_feedback_display_name($firstName, $lastName)
    {
        $first = trim((string) $firstName);
        $last = trim((string) $lastName);

        if ($first === '') {
            return 'Hopia Fits customer';
        }

        if ($last === '') {
            return $first;
        }

        $initial = function_exists('mb_substr')
            ? mb_substr($last, 0, 1, 'UTF-8')
            : substr($last, 0, 1);

        $initial = function_exists('mb_strtoupper')
            ? mb_strtoupper($initial, 'UTF-8')
            : strtoupper($initial);

        return $first . ' ' . $initial . '.';
    }
}

if (!function_exists('hopia_feedback_photo_src')) {
    /**
     * Build a page-relative URL for a stored photo_path.
     *
     * photo_path is stored relative to the project root, for example
     * "images/feedback/<file>.png". The public feedback page and its LOAD
     * MORE endpoint both live one level down in /customer, so they need the
     * "../" prefix, matching how product images are referenced from other
     * customer pages. An empty or missing path returns an empty string, so
     * the card keeps its existing placeholder treatment.
     *
     * @param mixed $photoPath
     */
    function hopia_feedback_photo_src($photoPath)
    {
        $path = trim((string) $photoPath);

        if ($path === '') {
            return '';
        }

        return '../' . ltrim($path, '/');
    }
}

if (!function_exists('hopia_feedback_fetch_batch')) {
    /**
     * Fetch one page of public feedback, newest first.
     *
     * One JOIN query loads the whole batch, so there is no per-row query
     * (no N+1). LIMIT and OFFSET are validated integers and are interpolated
     * directly because MySQL does not reliably accept bound placeholders for
     * them with PDO::ATTR_EMULATE_PREPARES disabled; no user-controlled
     * string ever reaches the SQL.
     *
     * One extra row is fetched to decide whether more records remain, so no
     * separate COUNT query is needed.
     *
     * Each entry also carries the feedback row's own id. It is never
     * rendered into markup; it exists only so the page can correlate a
     * public card with the logged-in customer's own feedback row (see
     * hopia_feedback_fetch_customer_rows) and show the owner-only EDIT
     * action. The LOAD MORE endpoint returns rendered HTML only, so the id
     * never leaves the server.
     *
     * @return array{entries: array<int, array<string, mixed>>, has_more: bool}
     */
    function hopia_feedback_fetch_batch(PDO $pdo, int $offset, int $limit = HOPIA_FEEDBACK_BATCH_SIZE): array
    {
        $offset = max(0, $offset);
        $limit = max(1, $limit);

        $sql = 'SELECT cf.id, cf.rating, cf.feedback_text, cf.photo_path, cf.created_at,
                       c.first_name, c.last_name
                FROM customer_feedback cf
                INNER JOIN customers c ON c.id = cf.customer_id
                ORDER BY cf.created_at DESC, cf.id DESC
                LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset;

        $entries = [];

        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $entries[] = [
                'id' => (int) $row['id'],
                'photo_path' => hopia_feedback_photo_src($row['photo_path']),
                'rating' => (int) $row['rating'],
                'feedback_text' => (string) $row['feedback_text'],
                'customer_name' => hopia_feedback_display_name($row['first_name'], $row['last_name']),
                'created_at' => (string) $row['created_at'],
            ];
        }

        $hasMore = count($entries) > $limit;

        if ($hasMore) {
            $entries = array_slice($entries, 0, $limit);
        }

        return ['entries' => $entries, 'has_more' => $hasMore];
    }
}

if (!function_exists('hopia_feedback_render_cards')) {
    /**
     * Render a batch of entries to the exact <li> card markup used on the
     * page, so the initial render and the LOAD MORE response can never drift
     * apart. All user-generated text and names are escaped inside
     * ui.feedback-card.php through hopia_e().
     *
     * @param array<int, array<string, mixed>> $entries
     */
    function hopia_feedback_render_cards(array $entries): string
    {
        $html = '';

        foreach ($entries as $card) {
            ob_start();
            require __DIR__ . '/ui.feedback-card.php';
            $html .= (string) ob_get_clean();
        }

        return $html;
    }
}

if (!function_exists('hopia_feedback_fetch_customer_rows')) {
    /**
     * Fetch the authenticated customer's OWN feedback rows, keyed by
     * feedback id, including the order item's product snapshot.
     *
     * Scoped to customer_id in the WHERE clause, so a customer can only
     * ever receive their own rows: the owner-only EDIT action on the public
     * feedback page and the edit-overlay prefill both depend on this.
     * Read-only; nothing here writes to customer_feedback.
     *
     * @return array<int, array<string, mixed>>
     */
    function hopia_feedback_fetch_customer_rows(PDO $pdo, int $customerId): array
    {
        $stmt = $pdo->prepare(
            'SELECT cf.id, cf.order_id, cf.order_item_id, cf.rating, cf.feedback_text, cf.photo_path,
                    oi.product_name, oi.size, oi.color, pi.image_path
             FROM customer_feedback cf
             LEFT JOIN order_items oi ON oi.id = cf.order_item_id
             LEFT JOIN product_images pi ON pi.id = (
                 SELECT pi2.id
                 FROM product_images pi2
                 WHERE pi2.product_id = oi.product_id
                 ORDER BY pi2.sort_order ASC, pi2.id ASC
                 LIMIT 1
             )
             WHERE cf.customer_id = :customer_id'
        );

        $stmt->execute([':customer_id' => $customerId]);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['id']] = [
                'order_id' => (int) $row['order_id'],
                'order_item_id' => (int) $row['order_item_id'],
                'rating' => (int) $row['rating'],
                'feedback_text' => (string) $row['feedback_text'],
                'photo_path' => (string) $row['photo_path'],
                'product_name' => (string) $row['product_name'],
                'size' => (string) $row['size'],
                'color' => (string) $row['color'],
                'image_path' => (string) $row['image_path'],
            ];
        }

        return $rows;
    }
}

if (!function_exists('hopia_feedback_edit_payload')) {
    /**
     * Build the edit-overlay prefill payload for one of the customer's own
     * feedback rows.
     *
     * photo_path becomes a page-relative src using the same "../" prefix as
     * hopia_feedback_photo_src(), and photo_name is the stored filename
     * shown under the current-photo preview. The product snapshot lets the
     * overlay show the same item reference it shows in LEAVE FEEDBACK mode.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    function hopia_feedback_edit_payload(array $row): array
    {
        $photoPath = trim((string) ($row['photo_path'] ?? ''));
        $imagePath = trim((string) ($row['image_path'] ?? ''));

        return [
            'order_id' => (int) ($row['order_id'] ?? 0),
            'order_item_id' => (int) ($row['order_item_id'] ?? 0),
            'rating' => max(1, min(5, (int) ($row['rating'] ?? 0))),
            'feedback_text' => (string) ($row['feedback_text'] ?? ''),
            'photo_path' => hopia_feedback_photo_src($photoPath),
            'photo_name' => $photoPath !== '' ? basename($photoPath) : '',
            'product_name' => trim((string) ($row['product_name'] ?? '')),
            'size' => trim((string) ($row['size'] ?? '')),
            'color' => trim((string) ($row['color'] ?? '')),
            'image_path' => $imagePath !== '' ? '../' . ltrim($imagePath, '/') : '',
        ];
    }
}

if (!function_exists('hopia_feedback_fetch_customer_item_map')) {
    /**
     * Fetch the customer's own feedback rows for a set of order items,
     * keyed by order_item_id.
     *
     * Powers the LEAVE FEEDBACK / EDIT FEEDBACK decision on My Orders and
     * Order Detail: a row here means the customer already submitted
     * feedback for that item. Scoped to customer_id, so another
     * customer's feedback never flips the label. Read-only.
     *
     * @param array<int> $orderItemIds
     * @return array<int, array<string, mixed>>
     */
    function hopia_feedback_fetch_customer_item_map(PDO $pdo, int $customerId, array $orderItemIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $orderItemIds)));
        $ids = array_filter($ids, function ($id) {
            return $id > 0;
        });

        if (count($ids) === 0) {
            return [];
        }

        /* Every value is an intval'd integer, so the IN list is safe. */
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare(
            'SELECT order_item_id, rating, feedback_text, photo_path
             FROM customer_feedback
             WHERE customer_id = ? AND order_item_id IN (' . $placeholders . ')'
        );

        $stmt->execute(array_merge([$customerId], $ids));

        $map = [];

        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['order_item_id']] = [
                'rating' => (int) $row['rating'],
                'feedback_text' => (string) $row['feedback_text'],
                'photo_path' => (string) $row['photo_path'],
            ];
        }

        return $map;
    }
}
