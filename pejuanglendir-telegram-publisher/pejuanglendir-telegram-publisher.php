<?php
/**
 * Plugin Name: PejuangLendir Telegram Publisher
 * Description: Telegram publisher with single-frame auto-thumbnail, always-on 5-frame RetroTube rotation, self-detecting OS workers, performance-safe queue, smart titles, actors/tags, and factual descriptions.
 * Version: 1.9.3.2
 * Author: PejuangLendir
 * Requires at least: 6.5
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-pltp-publisher.php';
require_once __DIR__ . '/includes/class-pltp-auto-media.php';
require_once __DIR__ . '/includes/class-pltp-inline-media.php';

register_activation_hook(__FILE__, ['PLTP_Publisher', 'activate']);
PLTP_Publisher::boot();

register_activation_hook(__FILE__, ['PLTP_Auto_Media', 'activate']);
register_deactivation_hook(__FILE__, ['PLTP_Auto_Media', 'deactivate']);
PLTP_Auto_Media::boot();
