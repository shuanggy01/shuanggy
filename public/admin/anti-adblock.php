<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';
require dirname(__DIR__, 2) . '/app/ads.php';

admin_require_login();

function hta_write_runtime(array $data): void
{
    $storage = dirname(__DIR__, 2) . '/storage';
    if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) {
        throw new RuntimeException('Folder storage tidak dapat dibuat.');
    }
    if (!is_writable($storage)) {
        throw new RuntimeException('Folder storage tidak writable.');
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Gagal membuat konfigurasi.');
    }

    $target = ad_runtime_path();
    $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Gagal menulis konfigurasi.');
    }
    @chmod($tmp, 0640);
    if (!rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException('Gagal mengaktifkan konfigurasi.');
    }
}

$current = antiblock_config();
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    try {
        $code = trim((string) ($_POST['code'] ?? ''));
        $position = (string) ($_POST['position'] ?? 'body_end');
        if (!in_array($position, ['body_end', 'head'], true)) {
            $position = 'body_end';
        }
        if (strlen($code) > 300000) {
            throw new RuntimeException('Script terlalu besar. Maksimal 300 KB.');
        }

        $runtime = ad_runtime_config();
        if (!$runtime) {
            $base = ad_config();
            $runtime = [
                'enabled' => !empty($base['enabled']),
                'test_mode' => !empty($base['test_mode']),
                'slots' => is_array($base['slots'] ?? null) ? $base['slots'] : [],
            ];
        }

        $runtime['antiblock'] = [
            'active' => isset($_POST['active']),
            'code' => $code,
            'position' => $position,
            'scopes' => [
                'home' => isset($_POST['scope_home']),
                'video' => isset($_POST['scope_video']),
                'category' => isset($_POST['scope_category']),
                'download' => isset($_POST['scope_download']),
            ],
        ];
        unset($runtime['hilltop']);

        hta_write_runtime($runtime);
        header('Location: /admin/anti-adblock.php?saved=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$current = antiblock_config();
$scopeDefs = [
    'home' => 'Homepage',
    'video' => 'Video /v/',
    'category' => 'Kategori',
    'download' => 'Download /dl/',
];
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0B0D10">
<title>Anti-AdBlock / Global Script</title>
<link rel="stylesheet" href="/assets/admin-mobile-v1.css?v=1">
<link rel="stylesheet" href="/assets/hilltop-antiblock-v1.css?v=1">
</head>
<body class="am-body">
<div class="am-app">
<header class="am-header"><div class="am-header-inner"><div class="am-brand">
<a class="am-icon-btn hta-back" href="/admin/ads-mobile.php"><?= am_icon('arrow', 18) ?></a>
<div class="am-brand-copy"><strong>Anti-AdBlock / Global Script</strong><span>UNIVERSAL REGULAR SITE</span></div>
</div></div></header>
<main class="am-main hta-main">
<section class="hta-head"><div class="hta-icon"><?= am_icon('ads', 22) ?></div><div>
<div class="am-eyebrow">Monetization</div><h1>Anti-AdBlock / Recovery</h1>
<p>Untuk Anti-AdBlock, Ad Recovery, loader, atau global script dari jaringan iklan mana pun.</p>
</div></section>
<?php if (isset($_GET['saved'])): ?><div class="hta-flash">Pengaturan berhasil disimpan.</div><?php endif; ?>
<?php if ($error !== ''): ?><div class="hta-flash error"><?= am_e($error) ?></div><?php endif; ?>
<section class="hta-card"><form method="post">
<?= admin_csrf_input() ?>
<label class="hta-toggle"><span><strong>Aktifkan Anti-AdBlock / Global Script</strong><small>OFF = script tidak dimuat di website reguler.</small></span>
<input type="checkbox" name="active" value="1" <?= !empty($current['active']) ? 'checked' : '' ?>></label>
<div class="hta-field"><label>Custom Anti-AdBlock / Global Code</label>
<textarea name="code" rows="14" spellcheck="false" placeholder="<script src=&quot;...&quot;></script>"><?= am_e((string) ($current['code'] ?? '')) ?></textarea>
<small>Mendukung HTML, JavaScript, CSS, iframe, meta tag, loader, recovery script, dan kode custom dari berbagai jaringan.</small></div>
<div class="hta-field"><label>Posisi Script</label><select name="position">
<option value="body_end" <?= ($current['position'] ?? 'body_end') === 'body_end' ? 'selected' : '' ?>>Sebelum &lt;/body&gt;</option>
<option value="head" <?= ($current['position'] ?? '') === 'head' ? 'selected' : '' ?>>Di &lt;head&gt;</option>
</select><small>Pilih posisi sesuai instruksi jaringan iklan atau kebutuhan script.</small></div>
<div class="hta-scope-title">Tampilkan di website reguler</div><div class="hta-scope-grid">
<?php foreach ($scopeDefs as $key => $label): ?><label class="hta-scope-item">
<input type="checkbox" name="scope_<?= am_e($key) ?>" value="1" <?= !empty($current['scopes'][$key]) ? 'checked' : '' ?>>
<span><?= am_e($label) ?></span></label><?php endforeach; ?>
</div>
<div class="hta-note"><strong>🔒 Full Player /f/ terisolasi</strong><span>Script di halaman ini tidak pernah dimuat pada /f/. Anti-AdBlock, Popunder, Social Bar, atau script khusus /f/ harus dipasang dari menu Full Player /f/.</span></div>
<div class="hta-note"><strong>Catatan eksekusi</strong><span>Tag PHP dapat tersimpan sebagai teks, tetapi tidak dieksekusi oleh server. Area ini dirancang untuk kode browser seperti HTML/JavaScript/CSS.</span></div>
<button type="submit" class="hta-save">Simpan Pengaturan</button>
</form></section>
</main></div>
<?= am_bottom_nav('ads') ?>
<script src="/assets/admin-mobile-v1.js?v=1"></script>
</body></html>
