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
