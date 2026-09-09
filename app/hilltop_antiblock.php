<?php

declare(strict_types=1);

/**
 * Wrapper kompatibilitas untuk URL/file lama.
 * Implementasi sekarang universal melalui app/ads.php (antiblock_render).
 */

if (!function_exists('ad_config')) {
    require_once __DIR__ . '/ads.php';
}
