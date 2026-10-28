<?php

// Session selections refer to owned cart_items identities, never checkbox positions.
if (!function_exists('cart_selection_ids')) {
    function cart_selection_ids(array $values): ?array
    {
        $ids = [];
        foreach ($values as $value) {
            if (!is_int($value) && !is_string($value)) {
                return null;
            }
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                return null;
            }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}

if (!function_exists('cart_selection_get')) {
    function cart_selection_get(int $cartId): array
    {
        $ids = $_SESSION['cart_selection'][$cartId] ?? [];
        return is_array($ids) ? (cart_selection_ids($ids) ?? []) : [];
    }
}

if (!function_exists('cart_selection_revision')) {
    function cart_selection_revision(int $cartId): int
    {
        return (int) ($_SESSION['cart_selection_revision'][$cartId] ?? 0);
    }
}

if (!function_exists('cart_selection_set')) {
    function cart_selection_set(int $cartId, array $ids): void
    {
        $ids = cart_selection_ids($ids);
        if ($ids === null) {
            throw new InvalidArgumentException('Invalid cart item identity.');
        }
        if (cart_selection_get($cartId) !== $ids) {
            $_SESSION['cart_selection_revision'][$cartId] = cart_selection_revision($cartId) + 1;
        }
        $_SESSION['cart_selection'][$cartId] = $ids;
    }
}

if (!function_exists('cart_selection_prune')) {
    function cart_selection_prune(PDO $pdo, int $cartId): array
    {
        $selected = cart_selection_get($cartId);
        if ($selected === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($selected), '?'));
        $stmt = $pdo->prepare('SELECT id FROM cart_items WHERE cart_id = ? AND id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$cartId], $selected));
        // Only genuinely removed cart entries are pruned. Sold/retired products stay selected.
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        cart_selection_set($cartId, $ids);
        return cart_selection_get($cartId);
    }
}

if (!function_exists('cart_selection_remove')) {
    function cart_selection_remove(int $cartId, array $removedIds): void
    {
        cart_selection_set($cartId, array_values(array_diff(cart_selection_get($cartId), $removedIds)));
    }
}

if (!function_exists('cart_selection_unavailable_message')) {
    function cart_selection_unavailable_message(array $items): string
    {
        return $items === [] ? '' : 'Selected items unavailable: ' . implode(', ', $items)
            . '. Unselect or remove each affected item before proceeding.';
    }
}

if (!function_exists('cart_selection_summary')) {
    function cart_selection_summary(array $items, array $selectedIds): array
    {
        $subtotalCents = 0;
        $unavailable = [];
        foreach ($items as $item) {
            if (!in_array((int) $item['cart_item_id'], $selectedIds, true)) {
                continue;
            }
            $subtotalCents += (int) round((float) $item['price'] * 100);
            if ($item['status'] !== 'AVAILABLE' || $item['retired_at'] !== null) {
                $unavailable[] = $item['name'] . ' (#' . (int) ($item['product_id'] ?? $item['id']) . ')';
            }
        }
        return [
            'subtotal_cents' => $subtotalCents,
            'unavailable' => $unavailable,
            'message' => $selectedIds === [] ? 'Select at least one item' : cart_selection_unavailable_message($unavailable),
            'can_checkout' => $selectedIds !== [] && $unavailable === [],
        ];
    }
}
