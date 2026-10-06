<?php

// Hopia's V0.1 shared UI bootstrap. Include-only, no output, no side effects.

if (!function_exists('hopia_e')) {
    function hopia_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('hopia_asset')) {
    function hopia_asset($path)
    {
        $path = ltrim($path, '/');
        $base = defined('HUPIA_BASE') ? rtrim(HUPIA_BASE, '/') : '..';
        return $base === '' ? '/' . $path : $base . '/' . $path;
    }
}

if (!function_exists('hopia_site_name')) {
    function hopia_site_name()
    {
        return "Hopia's Ukay-Ukay";
    }
}

if (!function_exists('hopia_category_icon')) {
    // Custom editorial line-art icons for the homepage "Shop by category" section.
    // Returns trusted, static inline SVG markup (no user input) so the stroke can
    // inherit currentColor and animate on hover/focus. Returns '' for unknown keys.
    function hopia_category_icon($category)
    {
        $key = strtoupper(trim((string) $category));

        $paths = [
            'SHIRTS' =>
                '<path d="M7.5 4 10 6 14 6 16.5 4 21 7 18.5 10 16.5 8.5 16.5 20.5 7.5 20.5 7.5 8.5 5.5 10 3 7 Z"/>'
                . '<path d="M10 6 12 8.2 14 6"/>'
                . '<path d="M12 8.2 12 20.5"/>',
            'PANTS' =>
                '<path d="M6 3 18 3 17 21 13.5 21 12 10.5 10.5 21 7 21 Z"/>'
                . '<path d="M6 5.4 18 5.4"/>'
                . '<path d="M12 5.4 12 10.5"/>',
            'SHORTS' =>
                '<path d="M6 6 18 6 16.5 16 13 16 12 10 11 16 7.5 16 Z"/>'
                . '<path d="M6 8.2 18 8.2"/>'
                . '<path d="M12 8.2 12 10"/>',
        ];

        if (!isset($paths[$key])) {
            return '';
        }

        return '<svg class="home-category__icon" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="1.5" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $paths[$key]
            . '</svg>';
    }
}

if (!function_exists('hopia_checkout_icon')) {
    // Premium bold-outline icons for the Checkout page section headings and
    // payment options. Returns trusted, static inline SVG markup (no user input)
    // so the stroke inherits currentColor and stays consistent with the editorial
    // icon system. A single shared stroke weight keeps every icon uniform.
    // Returns '' for unknown keys.
    function hopia_checkout_icon($name)
    {
        $key = strtolower(trim((string) $name));

        $paths = [
            // Contact information - person / contact outline.
            'contact' =>
                '<path d="M20 21a8 8 0 0 0-16 0"/>'
                . '<circle cx="12" cy="7" r="4"/>',
            // Delivery address - location / map-pin outline.
            'address' =>
                '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/>'
                . '<circle cx="12" cy="10" r="3"/>',
            // Payment method - wallet / card outline.
            'payment' =>
                '<rect x="2" y="5" width="20" height="14" rx="2"/>'
                . '<path d="M2 10h20"/>',
            // Order summary - receipt / document outline.
            'summary' =>
                '<path d="M6 2 18 2 18 21 16 19 14 21 12 19 10 21 8 19 6 21 Z"/>'
                . '<path d="M9 8 15 8"/>'
                . '<path d="M9 12 15 12"/>',
            // Cash on delivery - banknote outline.
            'cash' =>
                '<rect x="2" y="6" width="20" height="12" rx="2"/>'
                . '<circle cx="12" cy="12" r="2.5"/>'
                . '<path d="M6 12h.01"/>'
                . '<path d="M18 12h.01"/>',
            // GCash - mobile wallet outline.
            'gcash' =>
                '<rect x="5" y="2" width="14" height="20" rx="2"/>'
                . '<circle cx="12" cy="9" r="2.5"/>'
                . '<path d="M10 18 14 18"/>',
            // Delivery option heading - courier truck outline.
            'delivery' =>
                '<path d="M10 17h4V5H2v12h3"/>'
                . '<path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5"/>'
                . '<circle cx="7.5" cy="17.5" r="2.5"/>'
                . '<circle cx="17.5" cy="17.5" r="2.5"/>',
            // Local courier option - parcel outline.
            'courier' =>
                '<path d="m7.5 4.27 9 5.15"/>'
                . '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>'
                . '<path d="m3.3 7 8.7 5 8.7-5"/>'
                . '<path d="M12 22V12"/>',
        ];

        if (!isset($paths[$key])) {
            return '';
        }

        return '<svg class="checkout-icon" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $paths[$key]
            . '</svg>';
    }
}

if (!function_exists('hopia_confirmation_icon')) {
    // Premium bold-outline icons for the Order Confirmation page. Same shape
    // language and shared stroke weight as hopia_checkout_icon so the two
    // systems read as one. Section icons (purchase, payment) are muted and sit
    // beside their eyebrow headings; receipt marks the order-number panel; the
    // purchase / shop / view icons are action accents inside the buttons.
    // Returns '' for unknown keys.
    function hopia_confirmation_icon($name)
    {
        $key = strtolower(trim((string) $name));

        $paths = [
            // YOUR PURCHASE - package / shopping-bag outline.
            'purchase' =>
                '<path d="M4.5 8.5 12 5 19.5 8.5 12 12 Z"/>'
                . '<path d="M4.5 8.5 4.5 15.5 12 19 12 12"/>'
                . '<path d="M19.5 8.5 19.5 15.5 12 19"/>',
            // HOW YOU WILL PAY - wallet / payment outline.
            'payment' =>
                '<rect x="2.5" y="5.5" width="19" height="13" rx="2"/>'
                . '<path d="M2.5 9.5 21.5 9.5"/>'
                . '<path d="M16 14h1.5"/>',
            // Order-number panel - receipt / document outline.
            'receipt' =>
                '<path d="M7 3h10a1 1 0 0 1 1 1v17l-2.5-1.7L13 21l-2.5-1.7L8 21V4a1 1 0 0 1 1-1Z"/>'
                . '<path d="M10 8h5"/>'
                . '<path d="M10 12h5"/>',
            // VIEW ORDER - arrow-right.
            'view' =>
                '<path d="M4.5 12h13"/>'
                . '<path d="m12.5 7 5 5-5 5"/>',
            // CONTINUE SHOPPING - shopping-bag / tote outline with handle.
            'shop' =>
                '<path d="M6 8h12l1 12.5H5L6 8Z"/>'
                . '<path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
        ];

        if (!isset($paths[$key])) {
            return '';
        }

        return '<svg class="confirmation-icon" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $paths[$key]
            . '</svg>';
    }
}

if (!function_exists('hopia_orders_icon')) {
    // Premium bold-outline icons for the My Orders page. Shared stroke
    // weight with hopia_checkout_icon and hopia_confirmation_icon so every
    // icon family on the customer site reads as one editorial system.
    // Used for filter tabs, status badges, and the VIEW ORDER arrow.
    // Returns trusted, static inline SVG markup (no user input).
    function hopia_orders_icon($name)
    {
        $key = strtolower(trim((string) $name));

        $paths = [
            // ALL - grid / layers outline.
            'layers' =>
                '<path d="m12 3 9 5-9 5-9-5 9-5Z"/>'
                . '<path d="m3 12 9 5 9-5"/>'
                . '<path d="m3 16 9 5 9-5"/>',
            // IN PROGRESS - clock outline.
            'clock' =>
                '<circle cx="12" cy="12" r="9"/>'
                . '<path d="M12 7v5l3.5 2"/>',
            // Status: order being prepared - package outline.
            'package' =>
                '<path d="M21 7.5 12 3 3 7.5"/>'
                . '<path d="m3 7.5 9 5 9-5"/>'
                . '<path d="M12 12.5v8.5"/>'
                . '<path d="M21 7.5v9L12 21"/>'
                . '<path d="M3 7.5v9l9 4.5"/>',
            // DELIVERED - check-circle outline.
            'check-circle' =>
                '<circle cx="12" cy="12" r="9"/>'
                . '<path d="m8.5 12 2.5 2.5 4.5-5"/>',
            // CANCELLED - x-circle outline.
            'x-circle' =>
                '<circle cx="12" cy="12" r="9"/>'
                . '<path d="m9.5 9.5 5 5"/>'
                . '<path d="m14.5 9.5-5 5"/>',
            // DELIVERED status - check outline.
            'check' =>
                '<path d="m5 12 4.5 4.5L19 7"/>',
            // CANCELLED status - x outline.
            'x' =>
                '<path d="m6 6 12 12"/>'
                . '<path d="m18 6-12 12"/>',
            // VIEW ORDER action - arrow-right.
            'arrow-right' =>
                '<path d="M4.5 12h13"/>'
                . '<path d="m12.5 7 5 5-5 5"/>',
        ];

        if (!isset($paths[$key])) {
            return '';
        }

        return '<svg class="orders-icon" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $paths[$key]
            . '</svg>';
    }
}

if (!function_exists('hopia_order_icon')) {
    // Premium bold-outline icons for the Order Detail page. Same shape
    // language and shared stroke weight as hopia_checkout_icon,
    // hopia_confirmation_icon, and hopia_orders_icon so every icon family
    // on the customer site reads as one editorial system. Used for section
    // headings, progress-timeline nodes, and action controls.
    // Returns trusted, static inline SVG markup (no user input).
    // Returns '' for unknown keys.
    function hopia_order_icon($name)
    {
        $key = strtolower(trim((string) $name));

        $paths = [
            // ORDER STATUS section - package with a small check on the front panel.
            'status' =>
                '<path d="M21 7.5 12 3 3 7.5"/>'
                . '<path d="m3 7.5 9 5 9-5"/>'
                . '<path d="M12 12.5v8.5"/>'
                . '<path d="M21 7.5v9L12 21"/>'
                . '<path d="M3 7.5v9l9 4.5"/>'
                . '<path d="m6.8 15.8 1.6 1.6 2.6-2.9"/>',
            // YOUR PURCHASE section - package / shopping-bag outline.
            'purchase' =>
                '<path d="M4.5 8.5 12 5 19.5 8.5 12 12 Z"/>'
                . '<path d="M4.5 8.5 4.5 15.5 12 19 12 12"/>'
                . '<path d="M19.5 8.5 19.5 15.5 12 19"/>',
            // WHERE IT IS GOING section - location / map-pin outline.
            'location' =>
                '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/>'
                . '<circle cx="12" cy="10" r="3"/>',
            // HOW YOU WILL PAY section - wallet / payment outline.
            'payment' =>
                '<rect x="2.5" y="5.5" width="19" height="13" rx="2"/>'
                . '<path d="M2.5 9.5 21.5 9.5"/>'
                . '<path d="M16 14h1.5"/>',
            // DELIVERY PROGRESS section / SHIPPED step - truck outline.
            'truck' =>
                '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/>'
                . '<path d="M15 18H9"/>'
                . '<path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/>'
                . '<circle cx="17" cy="18" r="2"/>'
                . '<circle cx="7" cy="18" r="2"/>',
            // NEED TO MAKE A CHANGE? section - edit / pencil outline.
            'actions' =>
                '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
            // PENDING step - clock outline.
            'clock' =>
                '<circle cx="12" cy="12" r="9"/>'
                . '<path d="M12 7v5l3.5 2"/>',
            // CONFIRMED step - check outline.
            'check' =>
                '<path d="m5 12 4.5 4.5L19 7"/>',
            // PACKED step - package outline.
            'package' =>
                '<path d="M21 7.5 12 3 3 7.5"/>'
                . '<path d="m3 7.5 9 5 9-5"/>'
                . '<path d="M12 12.5v8.5"/>'
                . '<path d="M21 7.5v9L12 21"/>'
                . '<path d="M3 7.5v9l9 4.5"/>',
            // DELIVERED step - check-circle outline.
            'check-circle' =>
                '<circle cx="12" cy="12" r="9"/>'
                . '<path d="m8.5 12 2.5 2.5 4.5-5"/>',
            // CANCELLED state - x-circle outline.
            'x-circle' =>
                '<circle cx="12" cy="12" r="9"/>'
                . '<path d="m9.5 9.5 5 5"/>'
                . '<path d="m14.5 9.5-5 5"/>',
            // REQUEST CANCELLATION action - close / x outline.
            'x' =>
                '<path d="m6 6 12 12"/>'
                . '<path d="m18 6-12 12"/>',
            // VIEW ALL ORDERS action - arrow-right.
            'arrow-right' =>
                '<path d="M4.5 12h13"/>'
                . '<path d="m12.5 7 5 5-5 5"/>',
            // CONTINUE SHOPPING action - shopping-bag / tote outline with handle.
            'bag' =>
                '<path d="M6 8h12l1 12.5H5L6 8Z"/>'
                . '<path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
        ];

        if (!isset($paths[$key])) {
            return '';
        }

        return '<svg class="order-icon" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $paths[$key]
            . '</svg>';
    }
}

if (!function_exists('hopia_account_icon')) {
    // Premium bold-outline icons for the Customer Account Center. Same shape
    // language and shared stroke weight as hopia_checkout_icon,
    // hopia_confirmation_icon, hopia_orders_icon, and hopia_order_icon, so the
    // account page reads as part of the same editorial icon system. Charcoal by
    // default; currentColor inside buttons and inverse surfaces.
    // Returns trusted, static inline SVG markup (no user input).
    function hopia_account_icon($name)
    {
        $key = strtolower(trim((string) $name));

        $paths = [
            // Profile identity area / section heading - person inside a ring.
            'profile' =>
                '<circle cx="12" cy="12" r="9"/>'
                . '<circle cx="12" cy="10" r="3"/>'
                . '<path d="M6.9 18.4a6.4 6.4 0 0 1 10.2 0"/>',
            // First name / last name rows - person outline.
            'user' =>
                '<circle cx="12" cy="8" r="3.5"/>'
                . '<path d="M5.5 20c.8-3.5 3.2-5.3 6.5-5.3s5.7 1.8 6.5 5.3"/>',
            // EMAIL row - envelope outline.
            'mail' =>
                '<rect x="2.5" y="5" width="19" height="14" rx="2"/>'
                . '<path d="m3 7 9 6.5L21 7"/>',
            // PHONE row - handset outline.
            'phone' =>
                '<path d="M5 3.5h4l1.5 4.5-2.2 1.6a12.5 12.5 0 0 0 6.1 6.1l1.6-2.2 4.5 1.5v4a1.5 1.5 0 0 1-1.5 1.5C10.4 19.8 4.2 13.6 3.5 5.1A1.5 1.5 0 0 1 5 3.5z"/>',
            // SECURITY heading / PASSWORD row - padlock outline.
            'lock' =>
                '<rect x="4" y="10" width="16" height="11" rx="2"/>'
                . '<path d="M8 10V7a4 4 0 0 1 8 0v3"/>'
                . '<path d="M12 14.5v2.5"/>',
            // CHANGE PASSWORD action - key outline.
            'key' =>
                '<circle cx="7.5" cy="15.5" r="4"/>'
                . '<path d="m10.4 12.6 9.1-9.1"/>'
                . '<path d="m16.4 6.6 2 2"/>'
                . '<path d="m13.6 9.4 2 2"/>',
            // EDIT PROFILE action - pencil outline.
            'edit' =>
                '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>'
                . '<path d="m15 5 4 4"/>',
            // QUICK ACTIONS heading - layered grid outline.
            'layers' =>
                '<path d="m12 3 9 5-9 5-9-5 9-5Z"/>'
                . '<path d="m3 12 9 5 9-5"/>'
                . '<path d="m3 16 9 5 9-5"/>',
            // VIEW MY ORDERS - package / parcel outline.
            'package' =>
                '<path d="M21 7.5 12 3 3 7.5"/>'
                . '<path d="m3 7.5 9 5 9-5"/>'
                . '<path d="M12 12.5v8.5"/>'
                . '<path d="M21 7.5v9L12 21"/>'
                . '<path d="M3 7.5v9l9 4.5"/>',
            // CONTINUE SHOPPING - shopping bag / tote outline with handle.
            'bag' =>
                '<path d="M6 8h12l1 12.5H5L6 8Z"/>'
                . '<path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
            // Action arrows - arrow-right outline.
            'arrow-right' =>
                '<path d="M4.5 12h13"/>'
                . '<path d="m12.5 7 5 5-5 5"/>',
            // LOG OUT - door with exit arrow outline.
            'sign-out' =>
                '<path d="M15 4h2.5A1.5 1.5 0 0 1 19 5.5v13a1.5 1.5 0 0 1-1.5 1.5H15"/>'
                . '<path d="M10 16.5 14.5 12 10 7.5"/>'
                . '<path d="M14.5 12H3.5"/>',
            // Success feedback - check outline.
            'check' =>
                '<path d="m5 12 4.5 4.5L19 7"/>',
            // Error feedback - circled exclamation outline.
            'alert' =>
                '<circle cx="12" cy="12" r="9"/>'
                . '<path d="M12 7.5v5"/>'
                . '<path d="M12 16h.01"/>',
        ];

        if (!isset($paths[$key])) {
            return '';
        }

        return '<svg class="account-icon" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $paths[$key]
            . '</svg>';
    }
}

if (!function_exists('hopia_account_initials')) {
    // Initials for the Account Center profile identity area, derived from the
    // authenticated customer's stored first and last name. Uses the same
    // mb-aware single-character slicing as hopia_feedback_display_name so
    // multi-byte names are never cut mid-character. Falls back to the site
    // initial when both names are empty.
    function hopia_account_initials($firstName, $lastName)
    {
        $initial = function ($value) {
            $value = trim((string) $value);

            if ($value === '') {
                return '';
            }

            $character = function_exists('mb_substr')
                ? mb_substr($value, 0, 1, 'UTF-8')
                : substr($value, 0, 1);

            return function_exists('mb_strtoupper')
                ? mb_strtoupper($character, 'UTF-8')
                : strtoupper($character);
        };

        $initials = $initial($firstName) . $initial($lastName);

        return $initials === '' ? 'H' : $initials;
    }
}
