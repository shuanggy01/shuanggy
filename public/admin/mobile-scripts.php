<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/ads.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';

admin_require_login();

function ms_runtime_path(): string
{
    return dirname(__DIR__, 2) . '/storage/ads.json';
}

function ms_read_runtime(): array
{
    $path = ms_runtime_path();

    if (!is_file($path)) {
        return [];
    }

    $json = file_get_contents($path);

    if ($json === false) {
        return [];
    }

    $data = json_decode($json, true);

    return is_array($data) ? $data : [];
}

function ms_write(array $data): void
{
    $storage = dirname(__DIR__, 2) . '/storage';

    if (!is_dir($storage)) {
        if (
            !mkdir($storage, 0750, true)
            && !is_dir($storage)
        ) {
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
            'Gagal membuat konfigurasi.'
        );
    }

    $target = ms_runtime_path();
    $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));

    if (
        file_put_contents(
            $tmp,
            $json . PHP_EOL,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(
            'Gagal menulis konfigurasi.'
        );
    }

    @chmod($tmp, 0640);

    if (!rename($tmp, $target)) {
        @unlink($tmp);

        throw new RuntimeException(
            'Gagal mengaktifkan konfigurasi.'
        );
    }
}

$current = ad_config();

$mobile = is_array($current['mobile_scripts'] ?? null)
    ? $current['mobile_scripts']
    : [];

$active = !empty($mobile['active']);
$code = (string) ($mobile['code'] ?? '');
$target = (string) ($mobile['target'] ?? 'mobile');

$scopeLabels = [
    'home' => 'Beranda',
    'video' => 'Halaman Video',
    'category' => 'Kategori / Koleksi',
    'download' => 'Halaman Download',
];

$savedScopes = is_array($mobile['scopes'] ?? null) ? $mobile['scopes'] : [];
$scopes = [];
foreach ($scopeLabels as $key => $_label) {
    // Konfigurasi lama tanpa kunci scopes dianggap aktif di semua halaman,
    // supaya perilaku situs tidak berubah diam-diam saat halaman ini dibuka.
    $scopes[$key] = !array_key_exists($key, $savedScopes)
        || !empty($savedScopes[$key]);
}

$error = '';

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    === 'POST'
) {
    admin_verify_csrf();

    try {
        $active = isset($_POST['active']);
        $code = trim((string) ($_POST['code'] ?? ''));
        $target = (string) ($_POST['target'] ?? 'mobile');
        if (!in_array($target, ['mobile', 'desktop', 'all'], true)) {
            throw new RuntimeException('Pilihan perangkat tidak valid.');
        }

        if (strlen($code) > 200000) {
            throw new RuntimeException(
                'Script terlalu besar.'
            );
        }

        $posted = is_array($_POST['scopes'] ?? null) ? $_POST['scopes'] : [];
        foreach ($scopeLabels as $key => $_label) {
            $scopes[$key] = !empty($posted[$key]);
        }

        if (!in_array(true, $scopes, true)) {
            throw new RuntimeException(
                'Pilih minimal satu halaman, atau matikan Global Scripts.'
            );
        }

        $runtime = ms_read_runtime();

        if (!$runtime) {
            $runtime = [
                'enabled' =>
                    !empty($current['enabled']),
                'test_mode' =>
                    !empty($current['test_mode']),
                'slots' =>
                    is_array($current['slots'] ?? null)
                        ? $current['slots']
                        : [],
            ];
        }

        $runtime['mobile_scripts'] = [
            'active' => $active,
            'target' => $target,
            'code' => $code,
            'scopes' => $scopes,
        ];

        ms_write($runtime);

        header(
            'Location: /admin/ads-mobile.php?saved=mobile'
        );
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1, viewport-fit=cover"
>
<meta name="theme-color" content="#0B0D10">

<title>Popunder</title>

<link rel="stylesheet" href="/assets/admin-mobile-v1.css?v=1">
<link rel="stylesheet" href="/assets/ads-manager-mobile-v2.css?v=2">
<link rel="stylesheet" href="/assets/ads-manager-mobile-v3-addon.css?v=1">
</head>

<body class="am-body">

<div class="am-app">

<header class="am-header">
<div class="am-header-inner">

<div class="am-brand">

<a
    class="am-icon-btn ads2-back"
    href="/admin/ads-mobile.php"
>
    <?= am_icon('arrow', 18) ?>
</a>

<div class="am-brand-copy">
    <strong>Popunder</strong>
    <span>SCRIPT IKLAN</span>
</div>

</div>
</div>
</header>


<main class="am-main ads2-main">

<section class="ads2-editor-head">

<div class="ads2-editor-icon">
    <?= am_icon('settings', 22) ?>
</div>

<div>
    <h1>Popunder</h1>
    <p>
        Satu kode untuk HP, desktop, atau semua perangkat.
    </p>
</div>

</section>


<?php if ($error !== ''): ?>
<div class="ads2-flash error">
    <?= am_e($error) ?>
</div>
<?php endif; ?>


<section class="ads2-editor-card">

<form method="post">

<?= admin_csrf_input() ?>


<label class="ads2-toggle-row">

<span>
    <strong>Aktifkan Popunder</strong>
    <small>
        Berlaku di Homepage, /v/, Kategori, dan /dl/.
        /f/ selalu dikecualikan.
    </small>
</span>

<input
    type="checkbox"
    name="active"
    value="1"
    <?= $active ? 'checked' : '' ?>
>

</label>

<div class="ads2-field"><label>Tampilkan di</label><select name="target">
<option value="mobile" <?= $target === 'mobile' ? 'selected' : '' ?>>HP saja</option>
<option value="all" <?= $target === 'all' ? 'selected' : '' ?>>HP + Desktop</option>
<option value="desktop" <?= $target === 'desktop' ? 'selected' : '' ?>>Desktop saja</option>
</select></div>

<div class="ads2-field"><label>Tayangkan di halaman</label>
<?php foreach ($scopeLabels as $scopeKey => $scopeLabel): ?>
<label class="ads2-toggle-row"><span><?= am_e($scopeLabel) ?></span>
<input type="checkbox" name="scopes[<?= am_e($scopeKey) ?>]" value="1" <?= !empty($scopes[$scopeKey]) ? 'checked' : '' ?>></label>
<?php endforeach; ?>
<small>Script global ini dimuat di setiap halaman yang dicentang. Matikan
"Beranda" kalau popunder/social bar hanya untuk halaman video.</small></div>


<div class="ads2-field">

<label>Kode Popunder</label>

<textarea
    name="code"
    rows="16"
    spellcheck="false"
    placeholder="<script src=&quot;...&quot;></script>"
><?= am_e($code) ?></textarea>

<small>
    Tempel script Popunder atau popup dari jaringan iklan.
</small>

</div>


<div class="ads3-warning">

<strong>Full Player dipisahkan</strong>

<span>
    Semua kode /f/ dikelola dari menu Full Player /f/,
    jadi tidak akan dobel dengan Mobile Scripts.
</span>

</div>


<button
    type="submit"
    class="ads2-save-slot"
>
    Simpan Popunder
</button>

</form>

</section>

</main>
</div>

<?= am_bottom_nav('ads') ?>

<script src="/assets/admin-mobile-v1.js?v=1"></script>

</body>
</html>
