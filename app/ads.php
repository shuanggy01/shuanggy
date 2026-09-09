<?php

declare(strict_types=1);

/**
 * Sistem iklan publik AsupanLendir.
 *
 * Batas konfigurasi sengaja tegas:
 * - storage/ads.json             = iklan reguler + mobile scripts + Anti-AdBlock/global script.
 * - storage/full-player-ads.json = monetisasi KHUSUS Full Player /f/.
 *
 * Kode dari konfigurasi reguler tidak pernah dirender ke scope Full Player.
 */

function ad_default_config(): array
{
    return [
        'enabled' => false,
        'test_mode' => false,
        'slots' => [],
        'mobile_scripts' => [
            'active' => false,
            'target' => 'mobile',
            'code' => '',
            'scopes' => [
                'home' => true,
                'video' => true,
                'category' => true,
                'download' => true,
            ],
        ],
        'antiblock' => [
            'active' => false,
            'position' => 'head',
            'code' => '',
            'scopes' => [
                'home' => true,
                'video' => true,
                'category' => true,
                'download' => true,
            ],
        ],
        'meta_verification' => [
            'active' => false,
            'code' => '',
        ],
    ];
}

function ad_is_mobile(): bool
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') {
        return $cache = false;
    }

    return $cache = (bool) preg_match(
        '/Android|iPhone|iPod|iPad|Mobile|IEMobile|Opera Mini/i',
        $ua
    );
}

function ad_merge_config(array $base, array $override): array
{
    $result = $base;

    foreach ($override as $key => $value) {
        if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
            $result[$key] = ad_merge_config($result[$key], $value);
        } else {
            $result[$key] = $value;
        }
    }

    return $result;
}

function ad_runtime_path(): string
{
    return dirname(__DIR__) . '/storage/ads.json';
}

function ad_runtime_config(): array
{
    $path = ad_runtime_path();
    if (!is_file($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false) {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function ad_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $root = dirname(__DIR__);
    $config = ad_default_config();

    $fileConfig = [];
    $defaultFile = $root . '/config/ads.php';
    if (is_file($defaultFile)) {
        $loaded = require $defaultFile;
        if (is_array($loaded)) {
            $fileConfig = $loaded;
        }
    }

    $runtime = ad_runtime_config();

    // Slot dinormalisasi PER SUMBER sebelum digabung. Tanpa ini, slot yang di
    // config/ads.php masih berbentuk lama (active/target/code) bisa membayangi
    // bentuk desktop/mobile di storage/ads.json dan menghapus iklan diam-diam.
    $slots = ad_normalize_slots(
        is_array($fileConfig['slots'] ?? null) ? $fileConfig['slots'] : []
    );

    $runtimeSlots = ad_normalize_slots(
        is_array($runtime['slots'] ?? null) ? $runtime['slots'] : []
    );

    foreach ($runtimeSlots as $name => $slot) {
        $slots[$name] = isset($slots[$name])
            ? ad_merge_config($slots[$name], $slot)
            : $slot;
    }

    unset($fileConfig['slots'], $runtime['slots']);

    $config = ad_merge_config($config, $fileConfig);
    if ($runtime) {
        $config = ad_merge_config($config, $runtime);
    }

    $config['slots'] = $slots;

    return $cache = $config;
}

/**
 * Bentuk kanonik slot:
 *
 *   ['label' => string, 'desktop' => ['active','code'], 'mobile' => ['active','code']]
 *
 * Menerima bentuk lama (active/target/code) maupun bentuk desktop/mobile, dan
 * selalu mengembalikan bentuk kanonik. Idempoten: aman dipanggil berulang.
 */
function ad_normalize_slot(array $slot): array
{
    $canonical = [
        'desktop' => ['active' => false, 'code' => ''],
        'mobile' => ['active' => false, 'code' => ''],
    ];

    $label = trim((string) ($slot['label'] ?? ''));
    if ($label !== '') {
        // Hanya disertakan bila terisi, supaya label kosong di runtime tidak
        // menimpa label bawaan dari config/ads.php saat digabung.
        $canonical['label'] = $label;
    }

    $hasPerDevice = false;
    foreach (['desktop', 'mobile'] as $device) {
        if (isset($slot[$device]) && is_array($slot[$device])) {
            $hasPerDevice = true;
            $canonical[$device] = [
                'active' => !empty($slot[$device]['active']),
                'code' => (string) ($slot[$device]['code'] ?? ''),
            ];
        }
    }

    $hasLegacy = array_key_exists('code', $slot)
        || array_key_exists('target', $slot);

    if ($hasLegacy) {
        $legacyCode = (string) ($slot['code'] ?? '');

        // Bila satu slot terlanjur memuat kedua bentuk, bentuk lama hanya
        // menang kalau benar-benar membawa kode.
        if (!$hasPerDevice || $legacyCode !== '') {
            $target = (string) ($slot['target'] ?? 'all');
            if (!in_array($target, ['mobile', 'desktop', 'all'], true)) {
                $target = 'all';
            }

            $legacyActive = !array_key_exists('active', $slot)
                || !empty($slot['active']);

            foreach (['desktop', 'mobile'] as $device) {
                $canonical[$device] = [
                    'active' => $legacyActive
                        && ($target === 'all' || $target === $device),
                    'code' => $legacyCode,
                ];
            }
        }
    }

    return $canonical;
}

function ad_normalize_slots(array $slots): array
{
    $out = [];

    foreach ($slots as $name => $slot) {
        if (is_array($slot)) {
            $out[$name] = ad_normalize_slot($slot);
        }
    }

    return $out;
}

/**
 * Varian satu slot untuk perangkat tertentu, selalu lewat bentuk kanonik.
 */
function ad_slot_variant(array $slot, string $device): array
{
    $canonical = ad_normalize_slot($slot);
    $device = $device === 'mobile' ? 'mobile' : 'desktop';

    return $canonical[$device];
}

function ad_slot_payload(string $slotName): array
{
    $config = ad_config();
    $slots = is_array($config['slots'] ?? null) ? $config['slots'] : [];
    $slot = is_array($slots[$slotName] ?? null) ? $slots[$slotName] : [];
    $device = ad_is_mobile() ? 'mobile' : 'desktop';
    $variant = ad_slot_variant($slot, $device);

    return [
        'system_enabled' => !empty($config['enabled']),
        'active' => !empty($variant['active']),
        'test_mode' => !empty($config['test_mode']),
        'label' => trim((string) ($slot['label'] ?? $slotName)),
        'code' => trim((string) ($variant['code'] ?? '')),
        'device' => $device,
    ];
}

function ad_render(string $slotName): string
{
    $slot = ad_slot_payload($slotName);

    if (!$slot['system_enabled'] || !$slot['active']) {
        return '';
    }

    if ($slot['code'] === '' && !$slot['test_mode']) {
        return '';
    }

    ob_start();
    ?>
<div class="ad-slot ad-slot--<?= htmlspecialchars($slot['device'], ENT_QUOTES, 'UTF-8') ?>"
     data-ad-slot="<?= htmlspecialchars($slotName, ENT_QUOTES, 'UTF-8') ?>">
<?php if ($slot['code'] !== ''): ?>
    <div class="ad-slot__code" style="display:flex;flex-direction:column;align-items:center;gap:14px;width:100%;overflow:hidden"><?= $slot['code'] ?></div>
<?php else: ?>
    <div class="ad-slot__test">
        <span class="ad-slot__kicker">IKLAN</span>
        <span class="ad-slot__label"><?= htmlspecialchars($slot['label'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="ad-slot__note">Test Mode • <?= htmlspecialchars($slot['device'], ENT_QUOTES, 'UTF-8') ?></span>
    </div>
<?php endif; ?>
</div>
    <?php
    return (string) ob_get_clean();
}

// ─── Mobile global scripts (REGULAR SITE ONLY) ───────────────────────────────

function ad_mobile_scripts_config(): array
{
    $config = ad_config();
    $defaults = ad_default_config()['mobile_scripts'];
    $mobile = is_array($config['mobile_scripts'] ?? null)
        ? $config['mobile_scripts']
        : [];

    return ad_merge_config($defaults, $mobile);
}

function ad_mobile_scripts_render(string $scope): string
{
    // /f/ adalah zona terisolasi dan tidak pernah menerima global regular scripts.
    if ($scope === 'full') {
        return '';
    }

    $config = ad_config();
    if (empty($config['enabled'])) {
        return '';
    }

    $mobile = ad_mobile_scripts_config();
    if (empty($mobile['active'])) {
        return '';
    }

    $target = (string) ($mobile['target'] ?? 'mobile');
    $device = ad_is_mobile() ? 'mobile' : 'desktop';
    if ($target !== 'all' && $target !== $device) {
        return '';
    }

    $scopes = is_array($mobile['scopes'] ?? null) ? $mobile['scopes'] : [];
    if (empty($scopes[$scope])) {
        return '';
    }

    return trim((string) ($mobile['code'] ?? ''));
}

function ad_meta_verification_render(): string
{
    $config = ad_config();
    $meta = is_array($config['meta_verification'] ?? null)
        ? $config['meta_verification']
        : [];

    if (empty($meta['active'])) {
        return '';
    }

    return trim((string) ($meta['code'] ?? ''));
}

// ─── Universal Anti-AdBlock / Global Script (REGULAR SITE ONLY) ─────────────

function antiblock_config(): array
{
    $config = ad_config();
    $defaults = ad_default_config()['antiblock'];
    $current = is_array($config['antiblock'] ?? null)
        ? $config['antiblock']
        : [];

    // Kompatibilitas konfigurasi lama bernama "hilltop".
    $legacy = is_array($config['hilltop'] ?? null) ? $config['hilltop'] : [];
    if (
        $legacy
        && trim((string) ($current['code'] ?? '')) === ''
        && !empty($legacy['code'])
    ) {
        $current = $legacy;
    }

    $current = ad_merge_config($defaults, $current);

    // Full Player tidak boleh diwarisi dari konfigurasi reguler, apa pun isi JSON lama.
    unset($current['scopes']['full']);

    return $current;
}

function antiblock_render(string $scope, string $position): string
{
    if ($scope === 'full') {
        return '';
    }

    $config = ad_config();
    if (empty($config['enabled'])) {
        return '';
    }

    $antiblock = antiblock_config();
    if (empty($antiblock['active'])) {
        return '';
    }

    if (($antiblock['position'] ?? 'head') !== $position) {
        return '';
    }

    $scopes = is_array($antiblock['scopes'] ?? null) ? $antiblock['scopes'] : [];
    if (empty($scopes[$scope])) {
        return '';
    }

    return trim((string) ($antiblock['code'] ?? ''));
}

// Alias lama dipertahankan agar halaman publik lama tetap kompatibel.
function hilltop_config(): array
{
    return antiblock_config();
}

function hilltop_antiblock_render(string $scope, string $position): string
{
    return antiblock_render($scope, $position);
}

// ─── Full Player /f/ Ads: STORAGE TERPISAH ───────────────────────────────────

function fp_ads_storage_path(): string
{
    return dirname(__DIR__) . '/storage/full-player-ads.json';
}

function fp_ads_default_config(): array
{
    return [
        'enabled' => false,
        'top_ad' => ['active' => false, 'code' => ''],
        'scripts' => ['active' => false, 'code' => ''],
        'antiblock' => [
            'active' => false,
            'position' => 'head',
            'code' => '',
        ],
        'before_play' => [
            'active' => false,
            'code' => '',
            'countdown' => 3,
        ],
        'first_play' => [
            'active' => false,
            'code' => '',
        ],
        'below_player' => ['active' => false, 'code' => ''],
        'on_pause' => ['active' => false, 'code' => ''],
        'timed' => [
            'active' => false,
            'code' => '',
            'after_seconds' => 60,
            'require_interaction' => true,
        ],
        'frequency' => [
            'max_triggers' => 2,
            'cooldown_minutes' => 30,
        ],
    ];
}

function fp_ads_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $config = fp_ads_default_config();
    $path = fp_ads_storage_path();

    if (is_file($path)) {
        $json = file_get_contents($path);
        if ($json !== false) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $config = ad_merge_config($config, $decoded);
            }
        }
    }

    // Defensive normalization for hand-edited/legacy JSON.
    $config['before_play']['countdown'] = max(
        0,
        min(15, (int) ($config['before_play']['countdown'] ?? 3))
    );
    $config['timed']['after_seconds'] = max(
        5,
        min(600, (int) ($config['timed']['after_seconds'] ?? 60))
    );
    $config['frequency']['max_triggers'] = max(
        1,
        min(10, (int) ($config['frequency']['max_triggers'] ?? 2))
    );
    $config['frequency']['cooldown_minutes'] = max(
        0,
        min(1440, (int) ($config['frequency']['cooldown_minutes'] ?? 30))
    );

    $position = (string) ($config['antiblock']['position'] ?? 'head');
    $config['antiblock']['position'] = in_array($position, ['head', 'body_end'], true)
        ? $position
        : 'head';

    return $cache = $config;
}

function fp_ads_system_enabled(): bool
{
    // Sengaja TIDAK bergantung pada master storage/ads.json.
    return !empty(fp_ads_config()['enabled']);
}

function fp_ads_public_payload(): array
{
    $fp = fp_ads_config();

    return [
        'enabled' => fp_ads_system_enabled(),
        'top_ad' => [
            'active' => !empty($fp['top_ad']['active']),
            'code' => trim((string) ($fp['top_ad']['code'] ?? '')),
        ],
        'before_play' => [
            'active' => !empty($fp['before_play']['active']),
            'code' => trim((string) ($fp['before_play']['code'] ?? '')),
            'countdown' => (int) ($fp['before_play']['countdown'] ?? 3),
        ],
        'first_play' => [
            'active' => !empty($fp['first_play']['active']),
            'code' => trim((string) ($fp['first_play']['code'] ?? '')),
        ],
        'below_player' => [
            'active' => !empty($fp['below_player']['active']),
            'code' => trim((string) ($fp['below_player']['code'] ?? '')),
        ],
        'on_pause' => [
            'active' => !empty($fp['on_pause']['active']),
            'code' => trim((string) ($fp['on_pause']['code'] ?? '')),
        ],
        'timed' => [
            'active' => !empty($fp['timed']['active']),
            'code' => trim((string) ($fp['timed']['code'] ?? '')),
            'after_seconds' => (int) ($fp['timed']['after_seconds'] ?? 60),
            'require_interaction' => !empty($fp['timed']['require_interaction']),
        ],
        'frequency' => [
            'max_triggers' => (int) ($fp['frequency']['max_triggers'] ?? 2),
            'cooldown_minutes' => (int) ($fp['frequency']['cooldown_minutes'] ?? 30),
        ],
    ];
}

/**
 * Social Bar / global script khusus /f/, dirender menjelang </body>.
 */
function fp_ads_scripts_render(): string
{
    if (!fp_ads_system_enabled()) {
        return '';
    }

    $fp = fp_ads_config();
    if (empty($fp['scripts']['active'])) {
        return '';
    }

    return trim((string) ($fp['scripts']['code'] ?? ''));
}

/**
 * Anti-AdBlock / recovery script khusus /f/ dengan posisi head atau body_end.
 */
function fp_ads_antiblock_render(string $position): string
{
    if (!fp_ads_system_enabled()) {
        return '';
    }

    if (!in_array($position, ['head', 'body_end'], true)) {
        return '';
    }

    $fp = fp_ads_config();
    $antiblock = is_array($fp['antiblock'] ?? null) ? $fp['antiblock'] : [];

    if (empty($antiblock['active'])) {
        return '';
    }

    if (($antiblock['position'] ?? 'head') !== $position) {
        return '';
    }

    return trim((string) ($antiblock['code'] ?? ''));
}

// Alias kompatibilitas nama lama.
function fp_ads_popunder_render(): string
{
    return fp_ads_scripts_render();
}
