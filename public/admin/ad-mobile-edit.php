<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/ads.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';

admin_require_login();

function ade_write(array $data): void
{
    $storage = dirname(__DIR__, 2) . '/storage';
    if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) {
        throw new RuntimeException('Folder storage tidak dapat dibuat.');
    }
    if (!is_writable($storage)) {
        throw new RuntimeException('Folder storage tidak writable oleh PHP.');
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Gagal membuat konfigurasi iklan.');
    }

    $target = ad_runtime_path();
    $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Gagal menulis konfigurasi sementara.');
    }
    @chmod($tmp, 0640);
    if (!rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException('Gagal mengaktifkan konfigurasi baru.');
    }
}

$slotDefinitions = [
    'home_top' => [
        'title' => 'Iklan Beranda',
        'description' => 'Banner utama pada halaman beranda.',
        'recommended' => 'Banner responsif, Native Banner, Social Bar ringan, atau display script.',
        'preview' => '/',
    ],
    'player_before_play' => [
        'title' => 'Sebelum Play 1',
        'description' => 'Overlay di dalam player sebelum video pertama kali diputar.',
        'recommended' => 'Before-play overlay, click-to-open ad, interstitial ringan, atau kode custom berbasis interaksi.',
        'preview' => '/v/B4Dc0JUWc',
    ],
    'player_before_play_2' => [
        'title' => 'Sebelum Play 2',
        'description' => 'Iklan kedua sebelum video pertama kali diputar.',
        'recommended' => 'Banner responsif atau script iklan kedua.',
        'preview' => '/v/B4Dc0JUWc',
    ],
    'player_below' => [
        'title' => 'Player • Bawah Video',
        'description' => 'Tepat di bawah pemutar video.',
        'recommended' => 'Banner, Native Ad, In-Page Push, atau display ad setelah player.',
        'preview' => '/v/B4Dc0JUWc',
    ],
    'footer' => [
        'title' => 'Iklan Footer',
        'description' => 'Banner di atas footer halaman publik.',
        'recommended' => 'Banner responsif atau native banner.',
        'preview' => '/',
    ],
];

$slotName = trim((string) ($_GET['slot'] ?? $_POST['slot'] ?? ''));
if (!isset($slotDefinitions[$slotName])) {
    http_response_code(404);
    exit('Slot iklan tidak ditemukan.');
}

$current = ad_config();
$slot = is_array($current['slots'][$slotName] ?? null) ? $current['slots'][$slotName] : [];
$label = trim((string) ($slot['label'] ?? '')) ?: $slotDefinitions[$slotName]['title'];
$desktop = ad_slot_variant($slot, 'desktop');
$mobile = ad_slot_variant($slot, 'mobile');
$code = trim((string) ($slot['code'] ?? ''));
$target = (string) ($slot['target'] ?? '');
if ($code === '') {
    $code = trim((string) ($mobile['code'] ?? '')) !== ''
        ? (string) $mobile['code']
        : (string) ($desktop['code'] ?? '');
}
if (!in_array($target, ['mobile', 'desktop', 'all'], true)) {
    $target = !empty($mobile['active']) && !empty($desktop['active'])
        ? 'all'
        : (!empty($mobile['active']) ? 'mobile' : 'desktop');
}
$active = !empty($slot['active']) || !empty($mobile['active']) || !empty($desktop['active']);
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    try {
        $label = trim((string) ($_POST['label'] ?? '')) ?: $slotDefinitions[$slotName]['title'];
        if (mb_strlen($label) > 100) {
            throw new RuntimeException('Nama slot maksimal 100 karakter.');
        }

        $active = isset($_POST['active']);
        $target = (string) ($_POST['target'] ?? 'mobile');
        $code = trim((string) ($_POST['code'] ?? ''));
        if (!in_array($target, ['mobile', 'desktop', 'all'], true)) {
            throw new RuntimeException('Pilihan perangkat tidak valid.');
        }
        if (strlen($code) > 250000) {
            throw new RuntimeException('Kode iklan terlalu besar. Maksimal 250 KB.');
        }

        // Mutasi runtime mentah agar antiblock, mobile scripts, dan slot lain tidak tertimpa.
        $runtime = ad_runtime_config();
        if (!$runtime) {
            $runtime = [
                'enabled' => !empty($current['enabled']),
                'test_mode' => !empty($current['test_mode']),
                'slots' => [],
            ];
        }
        if (!isset($runtime['slots']) || !is_array($runtime['slots'])) {
            $runtime['slots'] = [];
        }

        $runtime['slots'][$slotName] = [
            'label' => $label,
            'active' => $active,
            'target' => $target,
            'code' => $code,
        ];

        ade_write($runtime);
        header('Location: /admin/ads-mobile.php?saved=slot');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0B0D10">
<title>Edit Ad Slot</title>
<link rel="stylesheet" href="/assets/admin-mobile-v1.css?v=1">
<link rel="stylesheet" href="/assets/ads-manager-mobile-v2.css?v=2">
<link rel="stylesheet" href="/assets/ads-manager-mobile-v3-addon.css?v=1">
</head>
<body class="am-body">
<div class="am-app">
<header class="am-header"><div class="am-header-inner">
<div class="am-brand"><a class="am-icon-btn ads2-back" href="/admin/ads-mobile.php" aria-label="Kembali"><?= am_icon('arrow', 18) ?></a>
<div class="am-brand-copy"><strong>Edit Ad Slot</strong><span><?= am_e($slotName) ?></span></div></div>
<div class="am-header-actions"><a class="am-icon-btn" href="<?= am_e($slotDefinitions[$slotName]['preview']) ?>" target="_blank" rel="noopener" aria-label="Preview"><?= am_icon('globe', 18) ?></a></div>
</div></header>

<main class="am-main ads2-main">
<section class="ads2-editor-head"><div class="ads2-editor-icon"><?= am_icon('ads', 22) ?></div><div>
<h1><?= am_e($slotDefinitions[$slotName]['title']) ?></h1>
<p><?= am_e($slotDefinitions[$slotName]['description']) ?></p>
</div></section>

<?php if ($error !== ''): ?><div class="ads2-flash error"><?= am_e($error) ?></div><?php endif; ?>

<section class="ads2-editor-card"><form method="post">
<?= admin_csrf_input() ?>
<input type="hidden" name="slot" value="<?= am_e($slotName) ?>">

<div class="ads2-field"><label>Nama / Label</label>
<input type="text" name="label" maxlength="100" value="<?= am_e($label) ?>">
<small>Label ini dipakai untuk identifikasi slot dan Test Mode.</small></div>

<div class="ads3-warning"><strong>💡 Cocok untuk slot ini</strong><span><?= am_e($slotDefinitions[$slotName]['recommended']) ?></span></div>

<label class="ads2-toggle-row"><span><strong>Aktifkan Iklan</strong><small>Satu kode untuk perangkat yang dipilih.</small></span>
<input type="checkbox" name="active" value="1" <?= $active ? 'checked' : '' ?>></label>
<div class="ads2-field"><label>Tampilkan di</label><select name="target">
<option value="mobile" <?= $target === 'mobile' ? 'selected' : '' ?>>HP saja</option>
<option value="all" <?= $target === 'all' ? 'selected' : '' ?>>HP + Desktop</option>
<option value="desktop" <?= $target === 'desktop' ? 'selected' : '' ?>>Desktop saja</option>
</select><small>Pilih HP + Desktop jika satu kode ingin dipakai di semua perangkat.</small></div>
<div class="ads2-field"><label>Kode Iklan</label>
<textarea name="code" rows="14" spellcheck="false" placeholder="Tempel satu atau beberapa kode iklan..."><?= am_e($code) ?></textarea>
<small>Jika berisi dua banner, keduanya otomatis disusun atas–bawah dengan jarak.</small></div>

<div class="ads3-warning"><strong>Catatan eksekusi</strong><span>Isi textarea dicetak sebagai HTML/JavaScript di halaman publik. Tag PHP boleh tersimpan sebagai teks, tetapi tidak dieksekusi oleh server.</span></div>

<button type="submit" class="ads2-save-slot">Simpan Iklan</button>
</form></section>
</main></div>
<?= am_bottom_nav('ads') ?>
<script src="/assets/admin-mobile-v1.js?v=1"></script>
</body></html>
