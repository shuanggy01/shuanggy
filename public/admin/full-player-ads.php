<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/full_player_ads.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';

admin_require_login();

function fpa_write_config(array $data): void
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
        throw new RuntimeException('Gagal membuat konfigurasi.');
    }

    $target = fp_ads_storage_path();
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

function fpa_validate_code(string $code): void
{
    if (strlen($code) > 300000) {
        throw new RuntimeException('Kode iklan terlalu besar. Maksimal 300 KB per kolom.');
    }
}

function fpa_int(string $name, int $default, int $min, int $max): int
{
    $value = (int) ($_POST[$name] ?? $default);
    if ($value < $min || $value > $max) {
        throw new RuntimeException(sprintf('%s harus antara %d dan %d.', $name, $min, $max));
    }
    return $value;
}

$current = fp_ads_config();
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    try {
        $topCode = trim((string) ($_POST['top_code'] ?? ''));
        $scriptsCode = trim((string) ($_POST['scripts_code'] ?? ''));
        $antiblockCode = trim((string) ($_POST['antiblock_code'] ?? ''));
        $beforeCode = trim((string) ($_POST['before_code'] ?? ''));
        $firstPlayCode = trim((string) ($_POST['first_play_code'] ?? ''));
        $pauseCode = trim((string) ($_POST['pause_code'] ?? ''));
        $belowCode = trim((string) ($_POST['below_code'] ?? ''));
        $timedCode = trim((string) ($_POST['timed_code'] ?? ''));

        foreach ([
            $topCode,
            $scriptsCode,
            $antiblockCode,
            $beforeCode,
            $firstPlayCode,
            $pauseCode,
            $belowCode,
            $timedCode,
        ] as $code) {
            fpa_validate_code($code);
        }

        $countdown = fpa_int('before_countdown', 3, 0, 15);
        $timedAfter = fpa_int('timed_after_seconds', 60, 5, 600);
        $maxTriggers = fpa_int('max_triggers', 2, 1, 10);
        $cooldownMinutes = fpa_int('cooldown_minutes', 30, 0, 1440);

        $position = (string) ($_POST['antiblock_position'] ?? 'head');
        if (!in_array($position, ['head', 'body_end'], true)) {
            throw new RuntimeException('Posisi Anti-AdBlock tidak valid.');
        }

        $current = [
            'enabled' => isset($_POST['enabled']),
            'top_ad' => [
                'active' => isset($_POST['top_active']),
                'code' => $topCode,
            ],
            'scripts' => [
                'active' => isset($_POST['scripts_active']),
                'code' => $scriptsCode,
            ],
            'antiblock' => [
                'active' => isset($_POST['antiblock_active']),
                'position' => $position,
                'code' => $antiblockCode,
            ],
            'before_play' => [
                'active' => isset($_POST['before_active']),
                'code' => $beforeCode,
                'countdown' => $countdown,
            ],
            'first_play' => [
                'active' => isset($_POST['first_play_active']),
                'code' => $firstPlayCode,
            ],
            'on_pause' => [
                'active' => isset($_POST['pause_active']),
                'code' => $pauseCode,
            ],
            'below_player' => [
                'active' => isset($_POST['below_active']),
                'code' => $belowCode,
            ],
            'timed' => [
                'active' => isset($_POST['timed_active']),
                'code' => $timedCode,
                'after_seconds' => $timedAfter,
                'require_interaction' => isset($_POST['timed_require_interaction']),
            ],
            'frequency' => [
                'max_triggers' => $maxTriggers,
                'cooldown_minutes' => $cooldownMinutes,
            ],
        ];

        fpa_write_config($current);
        header('Location: /admin/full-player-ads.php?saved=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$current = fp_ads_config();
$top = is_array($current['top_ad'] ?? null) ? $current['top_ad'] : [];
$scripts = is_array($current['scripts'] ?? null) ? $current['scripts'] : [];
$antiblock = is_array($current['antiblock'] ?? null) ? $current['antiblock'] : [];
$before = is_array($current['before_play'] ?? null) ? $current['before_play'] : [];
$firstPlay = is_array($current['first_play'] ?? null) ? $current['first_play'] : [];
$pause = is_array($current['on_pause'] ?? null) ? $current['on_pause'] : [];
$below = is_array($current['below_player'] ?? null) ? $current['below_player'] : [];
$timed = is_array($current['timed'] ?? null) ? $current['timed'] : [];
$frequency = is_array($current['frequency'] ?? null) ? $current['frequency'] : [];
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0B0D10">
<title>Full Player Ads</title>
<link rel="stylesheet" href="/assets/admin-mobile-v1.css?v=1">
<link rel="stylesheet" href="/assets/ads-manager-mobile-v2.css?v=2">
<link rel="stylesheet" href="/assets/full-player-ads-admin-v3.css?v=3">
</head>
<body class="am-body">
<div class="am-app">
<header class="am-header"><div class="am-header-inner">
<div class="am-brand"><a class="am-icon-btn fpa-back" href="/admin/ads-mobile.php"><?= am_icon('arrow', 18) ?></a>
<div class="am-brand-copy"><strong>Full Player Ads</strong><span>AGGRESSIVE /F/ ZONE</span></div></div>
<div class="am-header-actions"><a class="am-icon-btn" href="/f/B4Dc0JUWc" target="_blank" rel="noopener"><?= am_icon('globe', 18) ?></a></div>
</div></header>

<main class="am-main ads2-main">
<section class="fpa-hero"><div class="am-eyebrow">Telegram Full Video Monetization</div><h1>Full Player /f/</h1>
<p>Zona monetisasi mandiri. Trigger agresif di halaman ini tidak diwariskan ke homepage, /v/, kategori, download, atau halaman reguler lain.</p></section>
<?php if (isset($_GET['saved'])): ?><div class="ads2-flash">✅ Full Player Ads berhasil disimpan.</div><?php endif; ?>
<?php if ($error !== ''): ?><div class="ads2-flash error"><?= am_e($error) ?></div><?php endif; ?>

<form method="post"><?= admin_csrf_input() ?>
<section class="fpa-card fpa-master"><label class="ads2-toggle-row"><span><strong>Aktifkan Monetization /f/</strong>
<small>Master khusus Full Player. Tetap independen dari Sistem Iklan reguler.</small></span>
<input type="checkbox" name="enabled" value="1" <?= !empty($current['enabled']) ? 'checked' : '' ?>></label></section>

<section class="fpa-card fpa-accent-card">
<div class="fpa-card-head"><div><strong>🌶️ Frequency Control</strong><span>Membatasi First Play + Timed Secondary agar tetap agresif tanpa berubah jadi spam tanpa ujung.</span></div></div>
<div class="fpa-grid-2">
<label class="fpa-field"><span>Max aggressive triggers</span><select name="max_triggers"><?php for ($i = 1; $i <= 10; $i++): ?><option value="<?= $i ?>" <?= (int) ($frequency['max_triggers'] ?? 2) === $i ? 'selected' : '' ?>><?= $i ?>x</option><?php endfor; ?></select></label>
<label class="fpa-field"><span>Cooldown window</span><select name="cooldown_minutes"><?php foreach ([0, 5, 10, 15, 30, 60, 120, 360, 720, 1440] as $minute): ?><option value="<?= $minute ?>" <?= (int) ($frequency['cooldown_minutes'] ?? 30) === $minute ? 'selected' : '' ?>><?= $minute === 0 ? 'Session only' : $minute . ' menit' ?></option><?php endforeach; ?></select></label>
</div>
<div class="fpa-help">💡 Rekomendasi awal: <strong>2x / 30 menit</strong>. First Play memakai satu jatah, Timed Secondary memakai satu jatah berikutnya.</div>
</section>

<section class="fpa-card"><div class="fpa-card-head"><div><strong>Top Floating Ad</strong><span>Banner/native yang melayang di bagian atas player /f/.</span></div><label class="fpa-switch"><input type="checkbox" name="top_active" value="1" <?= !empty($top['active']) ? 'checked' : '' ?>></label></div>
<textarea name="top_code" rows="7" spellcheck="false" placeholder="Paste banner / native / iframe code..."><?= am_e((string) ($top['code'] ?? '')) ?></textarea>
<div class="fpa-help">💡 Cocok untuk banner 320×50, 300×100, native kecil, atau iframe responsif. Slot ini dieksekusi saat halaman /f/ dibuka.</div></section>

<section class="fpa-card fpa-trigger-card"><div class="fpa-card-head"><div><strong>🔥 First Play Trigger</strong><span>Menjalankan script pada interaksi Play pertama. Titik terbaik untuk Popunder.</span></div><label class="fpa-switch"><input type="checkbox" name="first_play_active" value="1" <?= !empty($firstPlay['active']) ? 'checked' : '' ?>></label></div>
<textarea name="first_play_code" rows="8" spellcheck="false" placeholder="Paste Popunder / click-trigger script..."><?= am_e((string) ($firstPlay['code'] ?? '')) ?></textarea>
<div class="fpa-help">💡 Script diinjeksi langsung di user gesture pertama, sehingga lebih cocok untuk jaringan Popunder dibanding menjalankannya otomatis saat page load. Maksimal mengikuti Frequency Control di atas.<br><br>🆕 <strong>Mau pasang beberapa Smart Link/banner sekaligus?</strong> Tulis kode pertama, lalu ketik <code>&lt;!-- @next --&gt;</code> di baris baru, lalu tempel kode berikutnya, dan seterusnya. Nanti akan tampil sebagai overlay satu-per-satu sesuai urutan (dengan tombol "Lanjut"), bukan langsung semua sekaligus. Kalau cuma diisi satu kode saja (tanpa <code>@next</code>), tetap jalan diam-diam seperti biasa cocok untuk Popunder murni.</div></section>

<section class="fpa-card"><div class="fpa-card-head"><div><strong>Before Play Overlay</strong><span>Muncul setelah user menekan Play pertama, sebelum video benar-benar diputar.</span></div><label class="fpa-switch"><input type="checkbox" name="before_active" value="1" <?= !empty($before['active']) ? 'checked' : '' ?>></label></div>
<div class="fpa-inline-field"><label>Delay tombol lanjut</label><select name="before_countdown"><?php for ($i = 0; $i <= 15; $i++): ?><option value="<?= $i ?>" <?= (int) ($before['countdown'] ?? 3) === $i ? 'selected' : '' ?>><?= $i ?> detik</option><?php endfor; ?></select></div>
<textarea name="before_code" rows="8" spellcheck="false" placeholder="Paste HTML / JavaScript creative Before Play..."><?= am_e((string) ($before['code'] ?? '')) ?></textarea>
<div class="fpa-help">💡 Cocok untuk interstitial ringan, native creative, banner, atau click ad. Rekomendasi delay 2–4 detik.</div></section>

<section class="fpa-card"><div class="fpa-card-head"><div><strong>Pause Ad</strong><span>Muncul menimpa video setiap kali pengunjung menekan pause (setelah video pernah diputar).</span></div><label class="fpa-switch"><input type="checkbox" name="pause_active" value="1" <?= !empty($pause['active']) ? 'checked' : '' ?>></label></div>
<textarea name="pause_code" rows="8" spellcheck="false" placeholder="Paste banner / native / iframe untuk overlay saat pause..."><?= am_e((string) ($pause['code'] ?? '')) ?></textarea>
<div class="fpa-help">💡 Dieksekusi lazy — kode baru dimuat pertama kali video di-pause, bukan saat halaman dibuka. Cocok untuk banner/native yang menemani jeda tonton. Tidak memakai jatah Frequency Control (bisa muncul tiap kali pause).</div></section>

<section class="fpa-card fpa-trigger-card"><div class="fpa-card-head"><div><strong>⏱️ Timed Secondary Trigger</strong><span>Trigger kedua setelah user benar-benar menonton selama durasi tertentu.</span></div><label class="fpa-switch"><input type="checkbox" name="timed_active" value="1" <?= !empty($timed['active']) ? 'checked' : '' ?>></label></div>
<div class="fpa-grid-2">
<label class="fpa-field"><span>Watch time</span><select name="timed_after_seconds"><?php foreach ([15, 30, 45, 60, 90, 120, 180, 300, 600] as $sec): ?><option value="<?= $sec ?>" <?= (int) ($timed['after_seconds'] ?? 60) === $sec ? 'selected' : '' ?>><?= $sec ?> detik</option><?php endforeach; ?></select></label>
<label class="fpa-check"><input type="checkbox" name="timed_require_interaction" value="1" <?= !empty($timed['require_interaction']) ? 'checked' : '' ?>><span>Tunggu interaksi berikutnya</span></label>
</div>
<textarea name="timed_code" rows="8" spellcheck="false" placeholder="Paste secondary Popunder / interaction script..."><?= am_e((string) ($timed['code'] ?? '')) ?></textarea>
<div class="fpa-help">💡 Jika “Tunggu interaksi berikutnya” aktif, setelah watch time tercapai script baru ditembak pada tap/click berikutnya. Ini lebih ramah popup blocker dan tetap memakai jatah Frequency Control.</div></section>

<section class="fpa-card"><div class="fpa-card-head"><div><strong>Bottom Floating Ad</strong><span>Versi /f/ dari Below Player, dibuat floating karena player memenuhi layar.</span></div><label class="fpa-switch"><input type="checkbox" name="below_active" value="1" <?= !empty($below['active']) ? 'checked' : '' ?>></label></div>
<textarea name="below_code" rows="7" spellcheck="false" placeholder="Paste banner / native ad HTML atau JavaScript..."><?= am_e((string) ($below['code'] ?? '')) ?></textarea>
<div class="fpa-help">💡 Cocok untuk banner responsif, native kecil, atau In-Page creative. Ditempatkan mengambang di bawah player dengan jarak aman dari tepi layar.</div></section>

<section class="fpa-card"><div class="fpa-card-head"><div><strong>Social Bar / Global Scripts</strong><span>Script yang memang harus aktif sepanjang halaman /f/.</span></div><label class="fpa-switch"><input type="checkbox" name="scripts_active" value="1" <?= !empty($scripts['active']) ? 'checked' : '' ?>></label></div>
<textarea name="scripts_code" rows="9" spellcheck="false" placeholder="<script src=&quot;...social-bar/global...&quot;></script>"><?= am_e((string) ($scripts['code'] ?? '')) ?></textarea>
<div class="fpa-help">💡 Cocok untuk Social Bar, loader, push/in-page global, atau network script yang memang harus dimuat saat page load. Untuk Popunder yang ingin dipicu user, lebih baik gunakan First Play.</div></section>

<section class="fpa-card"><div class="fpa-card-head"><div><strong>Anti-AdBlock / Recovery /f/</strong><span>Recovery script khusus /f/, tidak berkaitan dengan Anti-AdBlock website reguler.</span></div><label class="fpa-switch"><input type="checkbox" name="antiblock_active" value="1" <?= !empty($antiblock['active']) ? 'checked' : '' ?>></label></div>
<div class="fpa-inline-field"><label>Posisi script</label><select name="antiblock_position"><option value="head" <?= ($antiblock['position'] ?? 'head') === 'head' ? 'selected' : '' ?>>&lt;head&gt;</option><option value="body_end" <?= ($antiblock['position'] ?? 'head') === 'body_end' ? 'selected' : '' ?>>Sebelum &lt;/body&gt;</option></select></div>
<textarea name="antiblock_code" rows="8" spellcheck="false" placeholder="Paste Anti-AdBlock / Ad Recovery script..."><?= am_e((string) ($antiblock['code'] ?? '')) ?></textarea>
<div class="fpa-help">💡 Bisa dipakai untuk HilltopAds, Monetag, Adsterra, AdMaven, atau recovery script lain. Tetap terisolasi khusus /f/.</div></section>

<section class="fpa-card"><div class="fpa-help"><strong>Custom code:</strong> HTML, JavaScript, CSS, iframe, meta tag, dan script modifikasi dapat disimpan. Script di slot dinamis sekarang diinjeksi ulang sebagai elemen script aktif agar kode jaringan iklan benar-benar dieksekusi. Tag PHP tetap hanya tersimpan sebagai teks dan tidak dieksekusi server.</div></section>
<button type="submit" class="fpa-save">Simpan Full Player Ads</button>
</form></main></div>
<?= am_bottom_nav('ads') ?>
<script src="/assets/admin-mobile-v1.js?v=1"></script>
</body></html>
