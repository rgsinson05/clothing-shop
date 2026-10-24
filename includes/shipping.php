<?php

/*
 * Hopia Fits - Phase 1 fulfillment & shipping shared helpers.
 *
 * Include-only: this file declares functions and performs no output and no
 * side effects, matching the convention of includes/sales.php and
 * includes/ui.php. It is required by customer/checkout.php and the Phase 1
 * fulfillment tests so the region -> island-group mapping, the shipping
 * rates, and the fulfillment-method validation rules have exactly one
 * source of truth and cannot drift between preview and order creation.
 *
 * Fulfillment methods (mirrors shipments.delivery_method after
 * migration 010):
 *   LOCAL_COURIER - delivered by the shop's own courier; shipping is
 *                   derived from the selected destination's region.
 *   STORE_PICKUP  - collected at the shop; shipping is always 0.
 *
 * Shipping rates (integer centavos, Phase 1):
 *   Luzon    8000  (PHP 80.00)
 *   Visayas 10000  (PHP 100.00)
 *   Mindanao 12000 (PHP 120.00)
 *
 * NOTE on money: all authoritative amounts are computed in integer
 * centavos using round(value * 100), the same approach as
 * includes/sales.php and customer/checkout.php, so floating-point drift
 * can never change a stored total.
 */

if (!function_exists('shipping_fulfillment_methods')) {
    /**
     * The fulfillment methods available at checkout in this phase, in
     * display order. Keys are the stored delivery_method values; values
     * are the customer-facing labels.
     */
    function shipping_fulfillment_methods(): array
    {
        return [
            'LOCAL_COURIER' => 'Local Courier',
            'STORE_PICKUP' => 'Store Pickup',
        ];
    }
}

if (!function_exists('shipping_island_for_region')) {
    /**
     * Map one Philippine region name (exactly as it appears in
     * data/ph-locations.json and the checkout location selector) to its
     * island group: 'LUZON', 'VISAYAS', or 'MINDANAO'.
     *
     * Returns null when the region name is not recognized, so a caller
     * can never silently fall back to a wrong rate. The mapping is
     * intentionally a complete, explicit list of the 17 region names the
     * location data actually contains; it must be revisited whenever the
     * location data adds a region.
     */
    function shipping_island_for_region(string $region): ?string
    {
        $map = [
            'National Capital Region' => 'LUZON',
            'Cordillera Administrative Region' => 'LUZON',
            'Ilocos Region' => 'LUZON',
            'Cagayan Valley' => 'LUZON',
            'Central Luzon' => 'LUZON',
            'Calabarzon' => 'LUZON',
            'Mimaropa' => 'LUZON',
            'Bicol Region' => 'LUZON',
            'Western Visayas' => 'VISAYAS',
            'Central Visayas' => 'VISAYAS',
            'Eastern Visayas' => 'VISAYAS',
            'Zamboanga Peninsula' => 'MINDANAO',
            'Northern Mindanao' => 'MINDANAO',
            'Davao Region' => 'MINDANAO',
            'Soccsksargen' => 'MINDANAO',
            'Caraga' => 'MINDANAO',
            'Bangsamoro Autonomous Region' => 'MINDANAO',
        ];

        $region = trim($region);

        return $map[$region] ?? null;
    }
}

if (!function_exists('shipping_rate_cents_for_island')) {
    /**
     * The Phase 1 shipping rate for one island group, in integer
     * centavos. Returns null for any other value so an unsupported
     * island group can never be priced.
     */
    function shipping_rate_cents_for_island(string $island): ?int
    {
        $rates = [
            'LUZON' => 8000,
            'VISAYAS' => 10000,
            'MINDANAO' => 12000,
        ];

        return $rates[$island] ?? null;
    }
}

if (!function_exists('shipping_rate_cents_for_region')) {
    /**
     * Resolve the courier shipping rate for one region name, in integer
     * centavos, by mapping the region to its island group first. Returns
     * null when the region is not recognized; callers must treat null as
     * "cannot price" and stop, never as zero.
     */
    function shipping_rate_cents_for_region(string $region): ?int
    {
        $island = shipping_island_for_region($region);

        if ($island === null) {
            return null;
        }

        return shipping_rate_cents_for_island($island);
    }
}

if (!function_exists('shipping_island_label')) {
    /**
     * Customer-facing label for one island group ('Luzon', 'Visayas',
     * 'Mindanao'), or null for an unknown value. Used only for display;
     * the customer never chooses an island group separately because the
     * selected address already identifies its region.
     */
    function shipping_island_label(string $island): ?string
    {
        $labels = [
            'LUZON' => 'Luzon',
            'VISAYAS' => 'Visayas',
            'MINDANAO' => 'Mindanao',
        ];

        return $labels[$island] ?? null;
    }
}

if (!function_exists('shipping_cents_to_string')) {
    /**
     * Format an integer number of centavos as a canonical DECIMAL(10,2)
     * string such as "80.00" for storage and exact comparison. Mirrors
     * sales_cents_to_string so both money paths format identically.
     */
    function shipping_cents_to_string(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}

if (!function_exists('shipping_resolve_fulfillment')) {
    /**
     * Authoritative fulfillment + shipping resolution for one checkout
     * submission, shared by customer/checkout.php order creation and the
     * Phase 1 tests so preview and persistence can never drift.
     *
     * Inputs:
     *   $fulfillmentMethod - the submitted delivery method value
     *   $region            - the submitted delivery address region
     *
     * Returns an array that always carries 'ok'. On success it also
     * carries:
     *   'method'         - the validated fulfillment method
     *   'rate_cents'     - the authoritative shipping fee in centavos
     *   'island'         - the destination island group (null for pickup)
     * On failure it carries 'reason' (a stable machine-readable code)
     * and 'message' (a customer-facing sentence):
     *   'invalid_method' - the method is not one of the available options
     *   'missing_region' - courier selected but no region was submitted
     *   'unknown_region' - the region does not map to an island group
     *
     * Store pickup is ALWAYS charged exactly zero; the region is not
     * required and never priced, even when one is present.
     */
    function shipping_resolve_fulfillment(string $fulfillmentMethod, string $region): array
    {
        $method = trim($fulfillmentMethod);

        if (!isset(shipping_fulfillment_methods()[$method])) {
            return [
                'ok' => false,
                'reason' => 'invalid_method',
                'message' => 'Please choose a valid delivery option.',
            ];
        }

        if ($method === 'STORE_PICKUP') {
            return [
                'ok' => true,
                'method' => 'STORE_PICKUP',
                'rate_cents' => 0,
                'island' => null,
            ];
        }

        $region = trim($region);

        if ($region === '') {
            return [
                'ok' => false,
                'reason' => 'missing_region',
                'message' => 'Please select your complete delivery location.',
            ];
        }

        $island = shipping_island_for_region($region);

        if ($island === null) {
            return [
                'ok' => false,
                'reason' => 'unknown_region',
                'message' => 'We could not determine the shipping rate for your location. Please re-select your location.',
            ];
        }

        $rate = shipping_rate_cents_for_island($island);

        if ($rate === null) {
            return [
                'ok' => false,
                'reason' => 'unknown_region',
                'message' => 'We could not determine the shipping rate for your location. Please re-select your location.',
            ];
        }

        return [
            'ok' => true,
            'method' => 'LOCAL_COURIER',
            'rate_cents' => $rate,
            'island' => $island,
        ];
    }
}

if (!function_exists('shipping_pickup_details')) {
    /**
     * The single source of truth for the store-pickup information shown
     * at checkout and on order pages. The shop address and contact are
     * used exactly as supplied; no more specific street address or
     * coordinates are invented. The Maps link is used verbatim.
     */
    function shipping_pickup_details(): array
    {
        return [
            'shop' => "Hopia's Ukay-Ukay",
            'address' => 'Plaridel Street, Roxas City',
            'contact' => '0910 071 1262',
            'contact_href' => 'tel:+639100711262',
            'maps_url' => 'https://maps.app.goo.gl/PAQjGh3jpVa3crDV7',
        ];
    }
}

if (!function_exists('shipping_payment_methods_for_fulfillment')) {
    /**
     * The CASH payment method offered for one fulfillment method: Cash on
     * Delivery for Local Courier, Cash on Pickup for Store Pickup.
     *
     * NOTE: This Phase 1 helper is NOT the source of truth for the checkout
     * payment options and is currently unused by the pages. As of Phase 2,
     * customer/checkout.php builds the payment choices inline and additionally
     * offers GCash for EITHER fulfillment when the shop's GCash account is
     * configured (see includes/gcash.php, gcash_is_configured()). This helper
     * is retained only for the cash-method labels and intentionally does not
     * decide GCash availability.
     *
     * Keys are stored payments.payment_method values; values carry the label
     * and the description shown in the payment card.
     */
    function shipping_payment_methods_for_fulfillment(string $fulfillmentMethod): array
    {
        if ($fulfillmentMethod === 'STORE_PICKUP') {
            return [
                'CASH_ON_PICKUP' => [
                    'label' => 'Cash on Pickup',
                    'description' => 'Pay in cash when you collect your order at the shop.',
                ],
            ];
        }

        return [
            'COD' => [
                'label' => 'Cash on Delivery',
                'description' => 'Pay in cash when your order arrives.',
            ],
        ];
    }
}
