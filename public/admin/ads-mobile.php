<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/ads.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';

admin_require_login();

function adm_ads_runtime_path(): string
{
    return dirname(__DIR__, 2) . '/storage/ads.json';
}

function adm_ads_write(array $data): void
{
    $root = dirname(__DIR__, 2);
    $storage = $root . '/storage';

    if (!is_dir($storage)) {
        if (!mkdir($storage, 0750, true) && !is_dir($storage)) {
            throw new RuntimeException(
                'Folder storage tidak dapat dibuat.'
            );
        }
    }

    if (!is_writable($storage)) {
        throw new RuntimeException(
            'Folder storage tidak writable oleh PHP.'
        );
    }

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        throw new RuntimeException(
            'Gagal membuat konfigurasi iklan.'
        );
    }

    $target = adm_ads_runtime_path();
    $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));

    if (
        file_put_contents(
            $tmp,
            $json . PHP_EOL,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(
            'Gagal menulis konfigurasi sementara.'
        );
    }

    @chmod($tmp, 0640);

    if (!rename($tmp, $target)) {
        @unlink($tmp);

        throw new RuntimeException(
            'Gagal mengaktifkan konfigurasi baru.'
        );
    }
}

$slotDefinitions = [
    'home_top' => [
        'title' => 'Iklan Beranda',
        'short' => 'Beranda',
        'description' => 'Banner utama halaman beranda.',
        'preview' => '/',
    ],
    'home_mid' => [
        'title' => 'Beranda • Sebelum Video Terbaru',
        'short' => 'Beranda Tengah',
        'description' => 'Di antara Trending dan Video Terbaru.',
        'preview' => '/',
    ],
    'player_above' => [
        'title' => 'Player • Atas Video',
        'short' => 'Player Above',
        'description' => 'Tepat di atas pemutar video.',
        'preview' => '/v/B4Dc0JUWc',
    ],
    'player_before_play' => [
        'title' => 'Sebelum Play 1',
        'short' => 'Sebelum Play 1',
        'description' => 'Iklan pertama sebelum video diputar.',
        'preview' => '/v/B4Dc0JUWc',
    ],
    'player_before_play_2' => [
        'title' => 'Sebelum Play 2',
        'short' => 'Sebelum Play 2',
        'description' => 'Iklan kedua sebelum video diputar.',
        'preview' => '/v/B4Dc0JUWc',
    ],
    'player_below' => [
        'title' => 'Player • Bawah Video',
        'short' => 'Player Below',
        'description' => 'Tepat di bawah pemutar video.',
        'preview' => '/v/B4Dc0JUWc',
    ],
    'player_related' => [
        'title' => 'Player • Sebelum Related',
        'short' => 'Player Related',
        'description' => 'Di atas daftar video terkait.',
        'preview' => '/v/B4Dc0JUWc',
    ],
    'album_top' => [
        'title' => 'Kategori • Sebelum Daftar Video',
        'short' => 'Kategori',
        'description' => 'Di atas daftar video halaman kategori/koleksi.',
        'preview' => '/',
    ],
    'footer' => [
        'title' => 'Iklan Footer',
        'short' => 'Footer',
        'description' => 'Banner di atas footer halaman publik.',
        'preview' => '/',
    ],
];

$current = ad_config();
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    try {
        // Simpan hanya master flag tanpa menimpa slot, Anti-AdBlock,
        // Mobile Scripts, atau konfigurasi lain yang sudah ada.
        $runtime = ad_runtime_config();

        if (!$runtime) {
            $runtime = [
                'slots' => is_array($current['slots'] ?? null)
                    ? $current['slots']
                    : [],
            ];
        }

        $runtime['enabled'] = isset($_POST['enabled']);
        $runtime['test_mode'] = isset($_POST['test_mode']);

        adm_ads_write($runtime);

        header(
            'Location: /admin/ads-mobile.php?saved=global'
        );
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$saved = trim((string) ($_GET['saved'] ?? ''));

$activeCount = 0;
$liveCount = 0;

foreach ($slotDefinitions as $slotName => $definition) {
    $slot = is_array($current['slots'][$slotName] ?? null)
        ? $current['slots'][$slotName]
        : [];

    $desktop = ad_slot_variant($slot, 'desktop');
    $mobile = ad_slot_variant($slot, 'mobile');

    $desktopLive = !empty($desktop['active'])
        && trim((string) ($desktop['code'] ?? '')) !== '';
    $mobileLiveSlot = !empty($mobile['active'])
        && trim((string) ($mobile['code'] ?? '')) !== '';

    if (!empty($desktop['active']) || !empty($mobile['active'])) {
        $activeCount++;
    }

    if ($desktopLive || $mobileLiveSlot) {
        $liveCount++;
    }
}

$mobileScripts = is_array($current['mobile_scripts'] ?? null)
    ? $current['mobile_scripts']
    : [];

$mobileLive =
    !empty($mobileScripts['active'])
    && trim((string) ($mobileScripts['code'] ?? '')) !== '';

$antiBlock = antiblock_config();
$antiLive = !empty($antiBlock['active'])
    && trim((string) ($antiBlock['code'] ?? '')) !== '';

$fullRuntimePath =
    dirname(__DIR__, 2)
    . '/storage/full-player-ads.json';

$fullRuntime = [];

if (is_file($fullRuntimePath)) {
    $fullJson = file_get_contents($fullRuntimePath);

    if ($fullJson !== false) {
        $decoded = json_decode($fullJson, true);

        if (is_array($decoded)) {
            $fullRuntime = $decoded;
        }
    }
}

$fullEnabled = !empty($fullRuntime['enabled']);

$fullHasCode =
    trim((string) ($fullRuntime['scripts']['code'] ?? '')) !== ''
    || trim((string) ($fullRuntime['before_play']['code'] ?? '')) !== ''
    || trim((string) ($fullRuntime['below_player']['code'] ?? '')) !== ''
    || trim((string) ($fullRuntime['on_pause']['code'] ?? '')) !== '';

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1, viewport-fit=cover"
>
<meta name="theme-color" content="#0B0D10">

<title>Ads Manager Mobile</title>

<link
    rel="stylesheet"
    href="/assets/admin-mobile-v1.css?v=1"
>
<link
    rel="stylesheet"
    href="/assets/ads-manager-mobile-v2.css?v=2"
>
<link rel="stylesheet" href="/assets/ads-manager-mobile-v3-addon.css?v=1">
</head>

<body class="am-body">

<div class="am-app">

<header class="am-header">
    <div class="am-header-inner">

        <div class="am-brand">
            <img
                class="am-brand-mark"
                src="/assets/brand-mark.png"
                alt=""
            >

            <div class="am-brand-copy">
                <strong>Ads Manager</strong>
                <span>ADMIN MOBILE</span>
            </div>
        </div>

        <div class="am-header-actions">
            <a
                class="am-icon-btn"
                href="/admin/mobile.php"
                aria-label="Dashboard"
            >
                <?= am_icon('home', 19) ?>
            </a>
        </div>

    </div>
</header>


<main class="am-main ads2-main">


<section class="ads2-page-head">
    <div>
        <div class="am-eyebrow">Monetization</div>
        <h1>Ads</h1>
        <p>
            Semua pengaturan penting dalam satu tempat.
        </p>
    
</div>
</section>


<?php if ($saved === 'global'): ?>
<div class="ads2-flash">
    Pengaturan global berhasil disimpan.
</div>
<?php elseif ($saved === 'slot'): ?>
<div class="ads2-flash">
    Slot iklan berhasil disimpan.
</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
<div class="ads2-flash error">
    <?= am_e($error) ?>
</div>
<?php endif; ?>


<section class="ads2-summary">

<div>
    <strong>
        <?= !empty($current['enabled']) ? 'ON' : 'OFF' ?>
    </strong>
    <span>Sistem</span>
</div>

<div>
    <strong>
        <?= !empty($current['test_mode']) ? 'ON' : 'OFF' ?>
    </strong>
    <span>Test Mode</span>
</div>

<div>
    <strong><?= number_format($activeCount) ?></strong>
    <span>Slot Aktif</span>
</div>

<div>
    <strong><?= number_format($liveCount) ?></strong>
    <span>Live Code</span>
</div>

</section>


<section class="ads2-global-card">

<div class="ads2-card-title">
    Pengaturan Global
</div>

<form method="post">

<?= admin_csrf_input() ?>


<label class="ads2-toggle-row">

<span>
    <strong>Aktifkan Sistem Iklan</strong>
    <small>
        OFF = seluruh slot hilang dari website.
    </small>
</span>

<input
    type="checkbox"
    name="enabled"
    value="1"
    <?= !empty($current['enabled']) ? 'checked' : '' ?>
>

</label>


<label class="ads2-toggle-row">

<span>
    <strong>Test Mode</strong>
    <small>
        Slot kosong akan tampil sebagai kotak contoh IKLAN.
    </small>
</span>

<input
    type="checkbox"
    name="test_mode"
    value="1"
    <?= !empty($current['test_mode']) ? 'checked' : '' ?>
>

</label>


<button
    type="submit"
    class="ads2-save-global"
>
    Simpan Pengaturan Global
</button>

</form>

</section>


<section class="ads2-section">

<div class="ads2-section-head">
    <h2>Posisi Iklan</h2>
    <span><?= count($slotDefinitions) ?> slot</span>
</div>


<div class="ads2-slot-list">

<?php foreach ($slotDefinitions as $slotName => $definition): ?>

<?php
$slot = is_array($current['slots'][$slotName] ?? null)
    ? $current['slots'][$slotName]
    : [];

$desktop = ad_slot_variant($slot, 'desktop');
$mobile = ad_slot_variant($slot, 'mobile');
$desktopLive = !empty($desktop['active']) && trim((string) ($desktop['code'] ?? '')) !== '';
$mobileLiveSlot = !empty($mobile['active']) && trim((string) ($mobile['code'] ?? '')) !== '';
$anyActive = !empty($desktop['active']) || !empty($mobile['active']);

if (!$anyActive) {
    $state = 'OFF';
    $stateClass = 'off';
} elseif ($desktopLive && $mobileLiveSlot) {
    $state = 'LIVE D+M';
    $stateClass = 'live';
} elseif ($desktopLive) {
    $state = 'DESKTOP';
    $stateClass = 'live';
} elseif ($mobileLiveSlot) {
    $state = 'MOBILE';
    $stateClass = 'live';
} elseif (!empty($current['test_mode'])) {
    $state = 'TEST';
    $stateClass = 'test';
} else {
    $state = 'EMPTY';
    $stateClass = 'empty';
}
?>

<a
    class="ads2-slot-card"
    href="/admin/ad-mobile-edit.php?slot=<?= rawurlencode($slotName) ?>"
>

<div class="ads2-slot-icon">
    <?= am_icon('ads', 20) ?>
</div>

<div class="ads2-slot-copy">
    <strong>
        <?= am_e($definition['short']) ?>
    </strong>

    <span>
        <?= am_e($definition['description']) ?>
    </span>
</div>

<div class="ads2-slot-state <?= am_e($stateClass) ?>">
    <?= am_e($state) ?>
</div>

<div class="ads2-slot-arrow">
    <?= am_icon('arrow', 17) ?>
</div>

</a>

<?php endforeach; ?>



<a class="ads2-slot-card ads3-mobile-script-card" href="/admin/mobile-scripts.php">
<div class="ads2-slot-icon"><?= am_icon('settings', 20) ?></div>
<div class="ads2-slot-copy">
    <strong>Popunder</strong>
    <span>Satu kode untuk HP saja, desktop saja, atau keduanya.</span>
</div>
<div class="ads2-slot-state <?= $mobileLive ? 'live' : 'empty' ?>">
    <?= $mobileLive ? 'LIVE CODE' : 'EMPTY' ?>
</div>
<div class="ads2-slot-arrow"><?= am_icon('arrow', 17) ?></div>
</a>

<a class="ads2-slot-card" href="/admin/anti-adblock.php">
<div class="ads2-slot-icon"><?= am_icon('settings', 20) ?></div>
<div class="ads2-slot-copy"><strong>Global Script</strong><span>Script umum untuk seluruh halaman reguler.</span></div>
<div class="ads2-slot-state <?= $antiLive ? 'live' : (!empty($antiBlock['active']) ? 'empty' : 'off') ?>"><?= $antiLive ? 'LIVE CODE' : (!empty($antiBlock['active']) ? 'EMPTY' : 'OFF') ?></div>
<div class="ads2-slot-arrow"><?= am_icon('arrow', 17) ?></div>
</a>

<a class="ads2-slot-card" href="/admin/meta-verification.php">
<div class="ads2-slot-icon"><?= am_icon('globe', 20) ?></div>
<div class="ads2-slot-copy"><strong>Meta Verifikasi</strong><span>Google, Bing, jaringan iklan, dan layanan lainnya.</span></div>
<div class="ads2-slot-arrow"><?= am_icon('arrow', 17) ?></div>
</a>

</div>

</section>


</main>

</div>

<?= am_bottom_nav('ads') ?>

<script src="/assets/admin-mobile-v1.js?v=1"></script>

</body>
</html>
