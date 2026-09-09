<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/ads.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';
admin_require_login();

$current = ad_config();
$meta = is_array($current['meta_verification'] ?? null) ? $current['meta_verification'] : [];
$active = !empty($meta['active']);
$code = (string) ($meta['code'] ?? '');
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();
    try {
        $active = isset($_POST['active']);
        $code = trim((string) ($_POST['code'] ?? ''));
        if (strlen($code) > 50000) {
            throw new RuntimeException('Kode meta terlalu besar.');
        }
        $runtime = ad_runtime_config();
        if (!$runtime) {
            $runtime = ['enabled' => !empty($current['enabled']), 'slots' => []];
        }
        $runtime['meta_verification'] = ['active' => $active, 'code' => $code];
        adm_meta_write($runtime);
        header('Location: /admin/meta-verification.php?saved=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function adm_meta_write(array $data): void
{
    $storage = dirname(__DIR__, 2) . '/storage';
    if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) {
        throw new RuntimeException('Folder storage tidak dapat dibuat.');
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Gagal membuat konfigurasi.');
    }
    $target = ad_runtime_path();
    $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false || !rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException('Gagal menyimpan meta verifikasi.');
    }
}
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0B0D10"><title>Meta Verifikasi</title><link rel="stylesheet" href="/assets/admin-mobile-v1.css?v=1"><link rel="stylesheet" href="/assets/ads-manager-mobile-v2.css?v=2"></head>
<body class="am-body"><div class="am-app"><header class="am-header"><div class="am-header-inner"><div class="am-brand"><a class="am-icon-btn ads2-back" href="/admin/ads-mobile.php"><?= am_icon('arrow',18) ?></a><div class="am-brand-copy"><strong>Meta Verifikasi</strong><span>HEAD WEBSITE</span></div></div></div></header>
<main class="am-main ads2-main"><section class="ads2-editor-head"><div class="ads2-editor-icon"><?= am_icon('globe',22) ?></div><div><h1>Meta Verifikasi</h1><p>Tempel meta tag verifikasi tanpa mengedit file website.</p></div></section>
<?php if (isset($_GET['saved'])): ?><div class="ads2-flash">Meta verifikasi berhasil disimpan.</div><?php endif; ?>
<?php if ($error !== ''): ?><div class="ads2-flash error"><?= am_e($error) ?></div><?php endif; ?>
<section class="ads2-editor-card"><form method="post"><?= admin_csrf_input() ?><label class="ads2-toggle-row"><span><strong>Aktifkan Meta Verifikasi</strong><small>Ditempatkan di bagian head semua halaman publik.</small></span><input type="checkbox" name="active" value="1" <?= $active ? 'checked' : '' ?>></label><div class="ads2-field"><label>Kode Meta</label><textarea name="code" rows="12" spellcheck="false" placeholder="<meta name=&quot;...&quot; content=&quot;...&quot;>"><?= am_e($code) ?></textarea><small>Bisa berisi beberapa meta tag, masing-masing satu baris.</small></div><button type="submit" class="ads2-save-slot">Simpan Meta Verifikasi</button></form></section></main></div><?= am_bottom_nav('ads') ?><script src="/assets/admin-mobile-v1.js?v=1"></script></body></html>
