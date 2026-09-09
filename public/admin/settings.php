<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/site_settings.php';

admin_require_login();

function st_e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function st_runtime_path(): string
{
    return dirname(__DIR__, 2) . '/storage/site-settings.json';
}

function st_http_url_or_path(string $value): bool
{
    if ($value === '') {
        return true;
    }

    if (str_starts_with($value, '/')) {
        return true;
    }

    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string) parse_url(
        $value,
        PHP_URL_SCHEME
    ));

    return in_array($scheme, ['http', 'https'], true);
}

function st_http_url(string $value): bool
{
    if ($value === '') {
        return true;
    }

    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string) parse_url(
        $value,
        PHP_URL_SCHEME
    ));

    return in_array($scheme, ['http', 'https'], true);
}

function st_hex(string $value): bool
{
    return (bool) preg_match(
        '/^#[0-9A-Fa-f]{6}$/',
        $value
    );
}

function st_write(array $settings): void
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
        $settings,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        throw new RuntimeException(
            'Gagal membuat konfigurasi JSON.'
        );
    }

    $target = st_runtime_path();
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
            'Gagal mengaktifkan konfigurasi.'
        );
    }
}

$current = site_settings();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    try {
        $siteName = trim((string) ($_POST['site_name'] ?? ''));
        $baseUrl = rtrim(
            trim((string) ($_POST['base_url'] ?? '')),
            '/'
        );
        $footerText = trim((string) ($_POST['footer_text'] ?? ''));
        $telegramUrl = trim((string) ($_POST['telegram_url'] ?? ''));

        $homeTitle = trim((string) ($_POST['home_title'] ?? ''));
        $homeDescription = trim((string) ($_POST['home_description'] ?? ''));
        $defaultOgImage = trim((string) ($_POST['default_og_image'] ?? ''));

        $primary = strtoupper(trim((string) ($_POST['primary'] ?? '')));
        $background = strtoupper(trim((string) ($_POST['background'] ?? '')));
        $card = strtoupper(trim((string) ($_POST['card'] ?? '')));
        $text = strtoupper(trim((string) ($_POST['text'] ?? '')));
        $textSecondary = strtoupper(trim((string) ($_POST['text_secondary'] ?? '')));
        $logoUrl = trim((string) ($_POST['logo_url'] ?? ''));
        $faviconUrl = trim((string) ($_POST['favicon_url'] ?? ''));
        $themeColor = strtoupper(trim((string) ($_POST['theme_color'] ?? '')));

        $cdnUrl = rtrim(
            trim((string) ($_POST['cdn_url'] ?? '')),
            '/'
        );

        $apiProviderUrl = rtrim(
            trim((string) ($_POST['api_provider_url'] ?? '')),
            '/'
        );

        $newApiKey = trim((string) ($_POST['api_key'] ?? ''));
        $oldApiKey = (string) (
            $current['integration']['api_key']
            ?? ''
        );

        if ($siteName === '') {
            throw new RuntimeException(
                'Site Name tidak boleh kosong.'
            );
        }

        if (mb_strlen($siteName) > 80) {
            throw new RuntimeException(
                'Site Name maksimal 80 karakter.'
            );
        }

        if (!st_http_url($baseUrl)) {
            throw new RuntimeException(
                'Base URL tidak valid.'
            );
        }

        if ($telegramUrl !== '' && !st_http_url($telegramUrl)) {
            throw new RuntimeException(
                'Telegram URL tidak valid.'
            );
        }

        if (mb_strlen($homeTitle) > 255) {
            throw new RuntimeException(
                'Homepage SEO Title terlalu panjang.'
            );
        }

        if (mb_strlen($homeDescription) > 320) {
            throw new RuntimeException(
                'Homepage Meta Description maksimal 320 karakter.'
            );
        }

        foreach (
            [
                'Primary' => $primary,
                'Background' => $background,
                'Card' => $card,
                'Text' => $text,
                'Text Secondary' => $textSecondary,
                'Theme Color' => $themeColor,
            ]
            as $label => $color
        ) {
            if (!st_hex($color)) {
                throw new RuntimeException(
                    $label . ' harus format #RRGGBB.'
                );
            }
        }

        if (!st_http_url_or_path($logoUrl)) {
            throw new RuntimeException(
                'Logo URL tidak valid.'
            );
        }

        if (!st_http_url_or_path($faviconUrl)) {
            throw new RuntimeException(
                'Favicon URL tidak valid.'
            );
        }

        if (!st_http_url_or_path($defaultOgImage)) {
            throw new RuntimeException(
                'Default OG Image tidak valid.'
            );
        }

        if ($cdnUrl !== '' && !st_http_url($cdnUrl)) {
            throw new RuntimeException(
                'CDN URL tidak valid.'
            );
        }

        if (
            $apiProviderUrl !== ''
            && !st_http_url($apiProviderUrl)
        ) {
            throw new RuntimeException(
                'API Provider URL tidak valid.'
            );
        }

        $settings = [
            'general' => [
                'site_name' => $siteName,
                'base_url' => $baseUrl,
                'footer_text' => $footerText !== ''
                    ? $footerText
                    : $siteName,
                'telegram_url' => $telegramUrl,
            ],
            'seo' => [
                'home_title' => $homeTitle !== ''
                    ? $homeTitle
                    : $siteName,
                'home_description' => $homeDescription,
                'default_og_image' => $defaultOgImage,
                'home_noindex' => isset($_POST['home_noindex']),
            ],
            'appearance' => [
                'primary' => $primary,
                'background' => $background,
                'card' => $card,
                'text' => $text,
                'text_secondary' => $textSecondary,
                'logo_url' => $logoUrl,
                'favicon_url' => $faviconUrl,
                'theme_color' => $themeColor,
            ],
            'integration' => [
                'cdn_url' => $cdnUrl,
                'api_provider_url' => $apiProviderUrl,
                'api_key' => $newApiKey !== ''
                    ? $newApiKey
                    : $oldApiKey,
            ],
        ];

        st_write($settings);

        $current = site_settings_merge(
            site_settings_defaults(),
            $settings
        );

        $message =
            'Website Settings berhasil disimpan.';

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function cv(
    array $data,
    string $section,
    string $key,
    string $fallback = ''
): string {
    return (string) (
        $data[$section][$key]
        ?? $fallback
    );
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>
<title>Website Settings V1 - AsupanLendir</title>

<style>
:root{
    --bg:#0F1115;
    --panel:#1A1F26;
    --panel2:#15191F;
    --line:#2A3038;
    --yellow:#FFC107;
    --text:#FFFFFF;
    --muted:#A7ADB3;
    --green:#48C774;
}
*{box-sizing:border-box}
body{
    margin:0;
    background:
        radial-gradient(circle at 75% -15%,rgba(255,193,7,.055),transparent 34%),
        var(--bg);
    color:var(--text);
    font-family:Arial,Helvetica,sans-serif;
}
.container{
    width:min(1180px,calc(100% - 24px));
    margin:auto;
}
a{color:inherit}
header{
    position:sticky;
    top:0;
    z-index:30;
    background:rgba(15,17,21,.96);
    backdrop-filter:blur(12px);
    border-bottom:1px solid var(--line);
}
.topbar{
    min-height:67px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
}
.brand{font-size:20px;font-weight:800}
.actions{display:flex;gap:7px;flex-wrap:wrap}
.btn{
    display:inline-block;
    border:1px solid var(--line);
    background:var(--panel);
    color:#fff;
    text-decoration:none;
    border-radius:9px;
    padding:10px 13px;
    cursor:pointer;
    font-size:12px;
}
.btn:hover{
    color:var(--yellow);
    border-color:rgba(255,193,7,.35);
}
.btn.primary{
    background:var(--yellow);
    border-color:var(--yellow);
    color:#111318;
    font-weight:800;
}
main{padding:25px 0 55px}
.page-head{
    display:flex;
    justify-content:space-between;
    gap:18px;
    align-items:end;
    margin-bottom:19px;
}
.page-head h1{margin:0;font-size:26px}
.page-head p{
    margin:7px 0 0;
    color:var(--muted);
    font-size:12px;
}
.notice{
    margin-bottom:16px;
    padding:12px 14px;
    border:1px solid var(--line);
    background:var(--panel);
    border-radius:10px;
    font-size:12px;
}
.notice.ok{border-color:rgba(72,199,116,.35)}
.tabs{
    display:flex;
    gap:6px;
    overflow-x:auto;
    scrollbar-width:none;
    border-bottom:1px solid var(--line);
}
.tabs::-webkit-scrollbar{display:none}
.tab{
    flex:0 0 auto;
    padding:12px 15px;
    border:0;
    border-bottom:2px solid transparent;
    background:transparent;
    color:var(--muted);
    cursor:pointer;
    font-size:12px;
    font-weight:700;
}
.tab.active{
    color:var(--yellow);
    border-bottom-color:var(--yellow);
}
.settings-card{
    background:var(--panel);
    border:1px solid var(--line);
    border-top:0;
    border-radius:0 0 13px 13px;
    overflow:hidden;
}
.panel{display:none;padding:19px}
.panel.active{display:block}
.section-title{
    margin-bottom:16px;
    font-size:14px;
    font-weight:800;
}
.grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:15px;
}
.field{min-width:0}
.field.full{grid-column:1/-1}
label{
    display:block;
    margin-bottom:7px;
    font-size:11px;
    font-weight:700;
}
input[type="text"],
input[type="url"],
input[type="password"],
textarea{
    width:100%;
    border:1px solid var(--line);
    background:var(--panel2);
    color:#fff;
    border-radius:9px;
    padding:11px 12px;
    outline:0;
    font:inherit;
    font-size:12px;
}
textarea{
    min-height:100px;
    resize:vertical;
    line-height:1.5;
}
input:focus,
textarea:focus{
    border-color:var(--yellow);
    box-shadow:0 0 0 3px rgba(255,193,7,.07);
}
.help{
    margin-top:6px;
    color:var(--muted);
    font-size:10px;
    line-height:1.45;
}
.color-row{
    display:grid;
    grid-template-columns:48px 1fr;
    gap:8px;
}
input[type="color"]{
    width:48px;
    height:41px;
    border:1px solid var(--line);
    background:var(--panel2);
    border-radius:9px;
    padding:4px;
}
.check{
    display:flex;
    align-items:flex-start;
    gap:9px;
    padding:12px;
    border:1px solid var(--line);
    background:var(--panel2);
    border-radius:10px;
}
.check input{margin-top:2px}
.check strong{display:block;font-size:12px}
.check span{
    display:block;
    margin-top:4px;
    color:var(--muted);
    font-size:10px;
    line-height:1.45;
}
.preview{
    margin-top:19px;
    padding:17px;
    background:
        linear-gradient(135deg,rgba(255,193,7,.05),rgba(15,17,21,.7));
    border:1px solid var(--line);
    border-radius:12px;
}
.preview-title{
    color:var(--muted);
    font-size:10px;
    text-transform:uppercase;
    letter-spacing:.7px;
}
.preview-brand{
    margin-top:10px;
    display:flex;
    align-items:center;
    gap:10px;
    font-size:17px;
    font-weight:800;
}
.preview-brand img{
    width:38px;
    height:38px;
    border-radius:50%;
    object-fit:cover;
}
.preview-btn{
    display:inline-block;
    margin-top:13px;
    padding:9px 13px;
    border-radius:8px;
    background:var(--preview-primary,#FFC107);
    color:#111;
    font-size:11px;
    font-weight:800;
}
.status-box{
    padding:14px;
    border:1px solid var(--line);
    background:var(--panel2);
    border-radius:10px;
}
.status-box strong{font-size:12px}
.status-box p{
    margin:5px 0 0;
    color:var(--muted);
    font-size:10px;
    line-height:1.5;
}
.status{color:var(--green)}
.savebar{
    position:sticky;
    bottom:10px;
    z-index:20;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-top:18px;
    padding:12px;
    border:1px solid var(--line);
    background:rgba(26,31,38,.95);
    backdrop-filter:blur(10px);
    border-radius:12px;
}
.save-note{color:var(--muted);font-size:10px}
@media(max-width:700px){
    .grid{grid-template-columns:1fr}
    .field.full{grid-column:auto}
    .page-head{
        align-items:start;
        flex-direction:column;
    }
}
@media(max-width:520px){
    .container{width:calc(100% - 18px)}
    .brand{font-size:18px}
    .panel{padding:14px}
    .save-note{display:none}
}
</style>
</head>

<body>

<header>
<div class="container topbar">
    <div class="brand">⚙️ Website Settings V1</div>

    <div class="actions">
        <a class="btn" href="/admin/">← Dashboard</a>
        <a class="btn" href="/" target="_blank">🌐 Website</a>
    </div>
</div>
</header>

<main class="container">

<div class="page-head">
    <div>
        <h1>Website Settings</h1>
        <p>
            Kelola konfigurasi umum, SEO, tampilan,
            dan integrasi dari satu tempat.
        </p>
    </div>
</div>

<?php if ($message !== ''): ?>
<div class="notice ok">
    ✅ <?= st_e($message) ?>
</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
<div class="notice">
    ❌ <?= st_e($error) ?>
</div>
<?php endif; ?>

<form method="post">

<?= admin_csrf_input() ?>

<div class="tabs">
    <button class="tab active" type="button" data-tab="general">
        General
    </button>
    <button class="tab" type="button" data-tab="seo">
        SEO
    </button>
    <button class="tab" type="button" data-tab="appearance">
        Appearance
    </button>
    <button class="tab" type="button" data-tab="integration">
        Integration
    </button>
</div>

<div class="settings-card">

<section class="panel active" data-panel="general">
<div class="section-title">General</div>

<div class="grid">

<div class="field">
    <label>Site Name</label>
    <input
        type="text"
        name="site_name"
        maxlength="80"
        value="<?= st_e(cv(
            $current,
            'general',
            'site_name',
            'AsupanLendir'
        )) ?>"
    >
    <div class="help">Nama utama website.</div>
</div>

<div class="field">
    <label>Base URL</label>
    <input
        type="url"
        name="base_url"
        value="<?= st_e(cv(
            $current,
            'general',
            'base_url',
            'https://asupanlendir.sbs'
        )) ?>"
    >
    <div class="help">URL utama tanpa slash di belakang.</div>
</div>

<div class="field">
    <label>Footer Text</label>
    <input
        type="text"
        name="footer_text"
        maxlength="120"
        value="<?= st_e(cv(
            $current,
            'general',
            'footer_text',
            'AsupanLendir'
        )) ?>"
    >
</div>

<div class="field">
    <label>Telegram URL</label>
    <input
        type="url"
        name="telegram_url"
        value="<?= st_e(cv(
            $current,
            'general',
            'telegram_url'
        )) ?>"
        placeholder="https://t.me/..."
    >
    <div class="help">
        Channel/grup publik. Tidak mengubah token bot.
    </div>
</div>

</div>
</section>

<section class="panel" data-panel="seo">
<div class="section-title">Homepage SEO</div>

<div class="grid">

<div class="field full">
    <label>Homepage SEO Title</label>
    <input
        type="text"
        name="home_title"
        maxlength="255"
        value="<?= st_e(cv(
            $current,
            'seo',
            'home_title',
            'AsupanLendir'
        )) ?>"
    >
</div>

<div class="field full">
    <label>Homepage Meta Description</label>
    <textarea
        name="home_description"
        maxlength="320"
    ><?= st_e(cv(
        $current,
        'seo',
        'home_description'
    )) ?></textarea>

    <div class="help">
        SEO per-video/per-album tetap dikelola lewat SEO Editor.
    </div>
</div>

<div class="field full">
    <label>Default OG / Share Image</label>
    <input
        type="text"
        name="default_og_image"
        value="<?= st_e(cv(
            $current,
            'seo',
            'default_og_image'
        )) ?>"
        placeholder="/assets/og-asupanlendir.jpg"
    >
</div>

<div class="field full">
    <label class="check">
        <input
            type="checkbox"
            name="home_noindex"
            value="1"
            <?= !empty(
                $current['seo']['home_noindex']
                ?? false
            ) ? 'checked' : '' ?>
        >
        <div>
            <strong>Noindex Homepage</strong>
            <span>
                Normalnya jangan dicentang.
            </span>
        </div>
    </label>
</div>

</div>
</section>

<section class="panel" data-panel="appearance">
<div class="section-title">Appearance</div>

<div class="grid">

<?php
$colors = [
    'primary' => ['Primary', '#FFC107'],
    'background' => ['Background', '#0F1115'],
    'card' => ['Card', '#1A1F26'],
    'text' => ['Text', '#FFFFFF'],
    'text_secondary' => ['Text Secondary', '#A7ADB3'],
    'theme_color' => ['Browser Theme Color', '#0F1115'],
];
?>

<?php foreach ($colors as $key => [$label, $fallback]): ?>

<div class="field">
    <label><?= st_e($label) ?></label>

    <div class="color-row">
        <input
            type="color"
            data-color-picker="<?= st_e($key) ?>"
            value="<?= st_e(cv(
                $current,
                'appearance',
                $key,
                $fallback
            )) ?>"
        >

        <input
            type="text"
            data-color-text="<?= st_e($key) ?>"
            name="<?= st_e($key) ?>"
            maxlength="7"
            value="<?= st_e(cv(
                $current,
                'appearance',
                $key,
                $fallback
            )) ?>"
        >
    </div>
</div>

<?php endforeach; ?>

<div class="field full">
    <label>Logo URL</label>
    <input
        id="logoUrl"
        type="text"
        name="logo_url"
        value="<?= st_e(cv(
            $current,
            'appearance',
            'logo_url',
            '/assets/brand-mark.png'
        )) ?>"
    >
</div>

<div class="field full">
    <label>Favicon URL</label>
    <input
        type="text"
        name="favicon_url"
        value="<?= st_e(cv(
            $current,
            'appearance',
            'favicon_url',
            '/favicon.ico'
        )) ?>"
    >
</div>

</div>

<div class="preview">
    <div class="preview-title">Preview</div>

    <div class="preview-brand">
        <img
            id="logoPreview"
            src="<?= st_e(cv(
                $current,
                'appearance',
                'logo_url',
                '/assets/brand-mark.png'
            )) ?>"
            alt=""
        >
        <span id="siteNamePreview">
            <?= st_e(cv(
                $current,
                'general',
                'site_name',
                'AsupanLendir'
            )) ?>
        </span>
    </div>

    <div class="preview-btn" id="primaryPreview">
        Button Primary
    </div>
</div>
</section>

<section class="panel" data-panel="integration">
<div class="section-title">Integration</div>

<div class="grid">

<div class="field">
    <label>CDN Base URL</label>
    <input
        type="url"
        name="cdn_url"
        value="<?= st_e(cv(
            $current,
            'integration',
            'cdn_url',
            'https://cdn.videy.ca'
        )) ?>"
    >
    <div class="help">
        Informasi CDN/R2. Belum mengubah upload bot.
    </div>
</div>

<div class="field">
    <label>API Provider URL</label>
    <input
        type="url"
        name="api_provider_url"
        value="<?= st_e(cv(
            $current,
            'integration',
            'api_provider_url'
        )) ?>"
        placeholder="https://provider.example/api"
    >
    <div class="help">
        Disiapkan untuk API Import berikutnya.
    </div>
</div>

<div class="field full">
    <label>API Key</label>
    <input
        type="password"
        name="api_key"
        value=""
        autocomplete="new-password"
        placeholder="<?= !empty(
            $current['integration']['api_key']
            ?? ''
        )
            ? '•••••••• tersimpan — kosongkan untuk mempertahankan'
            : 'Belum ada API key' ?>"
    >
    <div class="help">
        Field kosong mempertahankan key lama.
        Belum digunakan sampai API Import dibuat.
    </div>
</div>

<div class="field full">
    <div class="status-box">
        <strong>
            Telegram Bot:
            <span class="status">Existing / tetap aktif</span>
        </strong>
        <p>
            Settings V1 tidak menyentuh token bot, webhook,
            R2 secret, atau channel ID.
        </p>
    </div>
</div>

<div class="field full">
    <div class="status-box">
        <strong>
            API Import:
            <span style="color:var(--muted)">
                Prepared / belum aktif
            </span>
        </strong>
        <p>
            Provider URL dan API key hanya disimpan.
            Tidak ada request API pada Settings V1.
        </p>
    </div>
</div>

</div>
</section>

</div>

<div class="savebar">
    <div class="save-note">
        Perubahan publik aktif setelah disimpan.
    </div>

    <button class="btn primary" type="submit">
        💾 Save All Changes
    </button>
</div>

</form>

</main>

<script>
(() => {
    const tabs = document.querySelectorAll('[data-tab]');
    const panels = document.querySelectorAll('[data-panel]');

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const name = tab.dataset.tab;

            tabs.forEach(x => x.classList.remove('active'));
            panels.forEach(x => x.classList.remove('active'));

            tab.classList.add('active');

            document
                .querySelector('[data-panel="' + name + '"]')
                ?.classList.add('active');

            history.replaceState(null, '', '#' + name);
        });
    });

    const requested = location.hash.replace('#', '');

    if (requested) {
        document
            .querySelector('[data-tab="' + requested + '"]')
            ?.click();
    }

    document
        .querySelectorAll('[data-color-picker]')
        .forEach(picker => {
            const key = picker.dataset.colorPicker;
            const text = document.querySelector(
                '[data-color-text="' + key + '"]'
            );

            picker.addEventListener('input', () => {
                text.value = picker.value.toUpperCase();
                refreshPreview();
            });

            text.addEventListener('input', () => {
                if (/^#[0-9a-f]{6}$/i.test(text.value)) {
                    picker.value = text.value;
                    refreshPreview();
                }
            });
        });

    const siteName = document.querySelector('[name="site_name"]');
    const logo = document.getElementById('logoUrl');

    function refreshPreview() {
        const primary = document.querySelector('[name="primary"]')?.value
            || '#FFC107';

        document
            .getElementById('primaryPreview')
            ?.style.setProperty(
                '--preview-primary',
                primary
            );

        const namePreview = document.getElementById('siteNamePreview');

        if (namePreview && siteName) {
            namePreview.textContent =
                siteName.value || 'AsupanLendir';
        }

        const logoPreview = document.getElementById('logoPreview');

        if (logoPreview && logo && logo.value.trim()) {
            logoPreview.src = logo.value.trim();
        }
    }

    siteName?.addEventListener('input', refreshPreview);
    logo?.addEventListener('input', refreshPreview);

    refreshPreview();
})();
</script>

</body>
</html>
