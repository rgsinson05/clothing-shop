<?php

/*
 * Hopia Fits - Phase 2 GCash account configuration (single source of truth).
 *
 * Include-only: this file declares functions and performs no output and no
 * side effects, matching includes/sales.php, includes/shipping.php, and
 * includes/order_verify.php. It is required by customer/checkout.php,
 * the customer order confirmation / detail pages, the GCash panel partial,
 * and admin/order-view.php so the shop's GCash receiving account has exactly
 * ONE definition and can never drift between the pages.
 *
 * The receiving account is read from the environment ($_ENV, populated by
 * includes/database.php via vlucas/phpdotenv at request start). It is NEVER
 * hardcoded, so no fictional/placeholder account is ever shipped in source:
 *
 *   GCASH_ACCOUNT_NAME    - registered GCash account holder name
 *   GCASH_ACCOUNT_NUMBER  - receiving GCash mobile number
 *   GCASH_QR_IMAGE        - OPTIONAL project-relative path to a QR image
 *
 * See .env.example for the documented keys. Until BOTH the name and the
 * number are configured with real values, gcash_is_configured() returns
 * false; callers must then withhold the GCash option entirely and must not
 * present any receiving account, so a customer can never be shown a
 * misleading destination (section 2 of the Phase 2 brief).
 */

if (!function_exists('gcash_env')) {
    /**
     * Read one environment value as a trimmed string. $_ENV is preferred
     * (phpdotenv immutable load), with getenv() as a fallback for CLI/test
     * contexts. Returns '' when unset so callers treat blank as "missing".
     */
    function gcash_env(string $key): string
    {
        $value = $_ENV[$key] ?? null;

        if ($value === null || $value === false) {
            $fromGetenv = getenv($key);
            $value = $fromGetenv === false ? '' : $fromGetenv;
        }

        return is_string($value) ? trim($value) : '';
    }
}

if (!function_exists('gcash_safe_qr_path')) {
    /**
     * Validate the optional configured QR image path. Only a project-relative
     * path under images/ with a web image extension is accepted; anything with
     * a scheme, a parent-directory segment, a leading slash, a backslash, or an
     * unexpected extension is rejected (returns '') so a mis-set env value can
     * never point the page at an arbitrary or remote resource. The file must
     * also actually exist on disk.
     */
    function gcash_safe_qr_path(string $raw): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        $normalized = str_replace('\\', '/', $raw);
        $normalized = ltrim($normalized, '/');

        // Must live under images/ and carry no traversal or scheme.
        if (strpos($normalized, '..') !== false) {
            return '';
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $normalized) === 1) {
            return '';
        }
        if (strpos($normalized, 'images/') !== 0) {
            return '';
        }
        if (preg_match('/\.(jpe?g|png|webp|gif)$/i', $normalized) !== 1) {
            return '';
        }

        $absolute = __DIR__ . '/../' . $normalized;

        if (!is_file($absolute)) {
            return '';
        }

        return $normalized;
    }
}

if (!function_exists('gcash_config')) {
    /**
     * The resolved GCash configuration. Always returns an array with:
     *   'name'       string - account holder name ('' when unset)
     *   'number'     string - receiving GCash number ('' when unset)
     *   'qr'         string - validated relative QR path ('' when none/invalid)
     *   'has_qr'     bool   - a usable QR image is configured
     *   'configured' bool   - BOTH name and number are present (QR is optional)
     *   'missing'    array  - machine keys of missing required values
     */
    function gcash_config(): array
    {
        $name = gcash_env('GCASH_ACCOUNT_NAME');
        $number = gcash_env('GCASH_ACCOUNT_NUMBER');
        $qr = gcash_safe_qr_path(gcash_env('GCASH_QR_IMAGE'));

        $missing = [];
        if ($name === '') {
            $missing[] = 'GCASH_ACCOUNT_NAME';
        }
        if ($number === '') {
            $missing[] = 'GCASH_ACCOUNT_NUMBER';
        }

        return [
            'name' => $name,
            'number' => $number,
            'qr' => $qr,
            'has_qr' => $qr !== '',
            'configured' => count($missing) === 0,
            'missing' => $missing,
        ];
    }
}

if (!function_exists('gcash_is_configured')) {
    /**
     * True only when the real receiving account (name AND number) is
     * configured. The QR image is optional and never required. Callers use
     * this to decide whether to OFFER GCash and whether to accept a GCASH
     * submission; a false result must withhold the option and reject GCASH
     * server-side.
     */
    function gcash_is_configured(): bool
    {
        return gcash_config()['configured'];
    }
}

if (!function_exists('gcash_number_digits')) {
    /**
     * The configured GCash number reduced to its digits, for the tel-style
     * copy-to-clipboard value and a canonical display. Returns '' when the
     * number is unset. The human-facing display still uses the raw configured
     * string so the shop controls spacing/formatting.
     */
    function gcash_number_digits(): string
    {
        return preg_replace('/\D+/', '', gcash_config()['number']);
    }
}
