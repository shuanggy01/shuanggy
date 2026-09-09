<?php

declare(strict_types=1);

/**
 * Wrapper kompatibilitas — semua fungsi full player ads sudah terintegrasi ke app/ads.php.
 * File ini dipertahankan agar halaman lama tidak error.
 */

if (!function_exists('ad_config')) {
    require_once __DIR__ . '/ads.php';
}
