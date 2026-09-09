<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require $root . '/app/admin.php';
require $root . '/app/admin_mobile_ui.php';

$settingsHelper = $root . '/app/site_settings.php';

if (!is_file($settingsHelper)) {
    http_response_code(500);
    exit(
        'Website Settings V1 belum ditemukan. '
        . 'Pastikan app/site_settings.php sudah terpasang.'
    );
}

require $settingsHelper;

admin_require_login();

function sm_e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function sm_runtime_path(): string
{
    return dirname(__DIR__, 2)
        . '/storage/site-settings.json';
}

function sm_http_url(string $value): bool
{
    if ($value === '') {
        return true;
    }

    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower(
        (string) parse_url(
            $value,
            PHP_URL_SCHEME
        )
    );

    return in_array(
        $scheme,
        ['http', 'https'],
        true
    );
}

function sm_url_or_path(string $value): bool
{
    if ($value === '') {
        return true;
    }

    if (str_starts_with($value, '/')) {
        return true;
    }

    return sm_http_url($value);
}

function sm_hex(string $value): bool
{
    return (bool) preg_match(
        '/^#[0-9A-Fa-f]{6}$/',
        $value
    );
}

function sm_write(array $settings): void
{
    $root = dirname(__DIR__, 2);
    $storage = $root . '/storage';

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

    $target = sm_runtime_path();
    $tmp = $target
        . '.tmp.'
        . bin2hex(random_bytes(4));

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

function sm_val(
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

$current = site_settings();
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    try {
        $siteName = trim(
            (string) ($_POST['site_name'] ?? '')
        );

        $baseUrl = rtrim(
            trim(
                (string) ($_POST['base_url'] ?? '')
            ),
            '/'
        );

        $footerText = trim(
            (string) ($_POST['footer_text'] ?? '')
        );

        $telegramUrl = trim(
            (string) ($_POST['telegram_url'] ?? '')
        );

        $homeTitle = trim(
            (string) ($_POST['home_title'] ?? '')
        );

        $homeDescription = trim(
            (string) ($_POST['home_description'] ?? '')
        );

        $defaultOgImage = trim(
            (string) ($_POST['default_og_image'] ?? '')
        );

        $primary = strtoupper(
            trim(
                (string) ($_POST['primary'] ?? '')
            )
        );

        $background = strtoupper(
            trim(
                (string) ($_POST['background'] ?? '')
            )
        );

        $card = strtoupper(
            trim(
                (string) ($_POST['card'] ?? '')
            )
        );

        $text = strtoupper(
            trim(
                (string) ($_POST['text'] ?? '')
            )
        );

        $textSecondary = strtoupper(
            trim(
                (string) ($_POST['text_secondary'] ?? '')
            )
        );

        $logoUrl = trim(
            (string) ($_POST['logo_url'] ?? '')
        );

        $faviconUrl = trim(
            (string) ($_POST['favicon_url'] ?? '')
        );

        $themeColor = strtoupper(
            trim(
                (string) ($_POST['theme_color'] ?? '')
            )
        );

        $cdnUrl = rtrim(
            trim(
                (string) ($_POST['cdn_url'] ?? '')
            ),
            '/'
        );

        $apiProviderUrl = rtrim(
            trim(
                (string) ($_POST['api_provider_url'] ?? '')
            ),
            '/'
        );

        $newApiKey = trim(
            (string) ($_POST['api_key'] ?? '')
        );

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

        if (!sm_http_url($baseUrl)) {
            throw new RuntimeException(
                'Base URL tidak valid.'
            );
        }

        if (
            $telegramUrl !== ''
            && !sm_http_url($telegramUrl)
        ) {
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

        $colors = [
            'Primary' => $primary,
            'Background' => $background,
            'Card' => $card,
            'Text' => $text,
            'Text Secondary' => $textSecondary,
            'Theme Color' => $themeColor,
        ];

        foreach ($colors as $label => $color) {
            if (!sm_hex($color)) {
                throw new RuntimeException(
                    $label
                    . ' harus format #RRGGBB.'
                );
            }
        }

        if (!sm_url_or_path($logoUrl)) {
            throw new RuntimeException(
                'Logo URL tidak valid.'
            );
        }

        if (!sm_url_or_path($faviconUrl)) {
            throw new RuntimeException(
                'Favicon URL tidak valid.'
            );
        }

        if (!sm_url_or_path($defaultOgImage)) {
            throw new RuntimeException(
                'Default OG Image tidak valid.'
            );
        }

        if (
            $cdnUrl !== ''
            && !sm_http_url($cdnUrl)
        ) {
            throw new RuntimeException(
                'CDN URL tidak valid.'
            );
        }

        if (
            $apiProviderUrl !== ''
            && !sm_http_url($apiProviderUrl)
        ) {
            throw new RuntimeException(
                'API Provider URL tidak valid.'
            );
        }

        $settings = [
            'general' => [
                'site_name' => $siteName,
                'base_url' => $baseUrl,
                'footer_text' =>
                    $footerText !== ''
                        ? $footerText
                        : $siteName,
                'telegram_url' => $telegramUrl,
            ],

            'seo' => [
                'home_title' =>
                    $homeTitle !== ''
                        ? $homeTitle
                        : $siteName,
                'home_description' => $homeDescription,
                'default_og_image' => $defaultOgImage,
                'home_noindex' =>
                    isset($_POST['home_noindex']),
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
                'api_key' =>
                    $newApiKey !== ''
                        ? $newApiKey
                        : $oldApiKey,
            ],
        ];

        sm_write($settings);

        header(
            'Location: /admin/settings-mobile.php?saved=1#'
            . rawurlencode(
                trim(
                    (string) ($_POST['active_tab'] ?? 'general')
                )
            )
        );
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$saved = isset($_GET['saved']);

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

<title>Settings Mobile</title>

<link
    rel="stylesheet"
    href="/assets/admin-mobile-v1.css?v=1"
>
<link
    rel="stylesheet"
    href="/assets/settings-mobile-v2.css?v=2"
>
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
                <strong>Website Settings</strong>
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


<main class="am-main set2-main">


<section class="set2-page-head">
    <div class="am-eyebrow">Configuration</div>

    <h1>Settings</h1>

    <p>
        Atur identitas website, homepage SEO, tampilan,
        dan integrasi dari HP.
    </p>
</section>


<?php if ($saved): ?>
<div class="set2-flash">
    Website Settings berhasil disimpan.
</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
<div class="set2-flash error">
    <?= sm_e($error) ?>
</div>
<?php endif; ?>


<form method="post" data-settings-form>

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="active_tab"
    value="general"
    data-active-tab-input
>


<nav class="set2-tabs">

<button
    type="button"
    class="active"
    data-tab="general"
>
    General
</button>

<button
    type="button"
    data-tab="seo"
>
    SEO
</button>

<button
    type="button"
    data-tab="appearance"
>
    Appearance
</button>

<button
    type="button"
    data-tab="integration"
>
    Integration
</button>

</nav>


<section
    class="set2-panel active"
    data-panel="general"
>

<div class="set2-section-title">
    General
</div>


<div class="set2-field">

<label>Site Name</label>

<input
    type="text"
    name="site_name"
    maxlength="80"
    value="<?= sm_e(
        sm_val(
            $current,
            'general',
            'site_name',
            'AsupanLendir'
        )
    ) ?>"
    data-site-name
>

<small>
    Nama utama yang tampil di website.
</small>

</div>


<div class="set2-field">

<label>Base URL</label>

<input
    type="url"
    name="base_url"
    value="<?= sm_e(
        sm_val(
            $current,
            'general',
            'base_url',
            'https://asupanlendir.sbs'
        )
    ) ?>"
>

<small>
    URL utama tanpa slash di belakang.
</small>

</div>


<div class="set2-field">

<label>Footer Text</label>

<input
    type="text"
    name="footer_text"
    maxlength="120"
    value="<?= sm_e(
        sm_val(
            $current,
            'general',
            'footer_text',
            'AsupanLendir'
        )
    ) ?>"
>

</div>


<div class="set2-field">

<label>Telegram URL</label>

<input
    type="url"
    name="telegram_url"
    value="<?= sm_e(
        sm_val(
            $current,
            'general',
            'telegram_url'
        )
    ) ?>"
    placeholder="https://t.me/..."
>

<small>
    Link publik channel/group. Tidak mengubah token bot.
</small>

</div>


</section>


<section
    class="set2-panel"
    data-panel="seo"
>

<div class="set2-section-title">
    Homepage SEO
</div>


<div class="set2-field">

<label>Homepage SEO Title</label>

<input
    type="text"
    name="home_title"
    maxlength="255"
    value="<?= sm_e(
        sm_val(
            $current,
            'seo',
            'home_title',
            'AsupanLendir'
        )
    ) ?>"
>

</div>


<div class="set2-field">

<label>Homepage Meta Description</label>

<textarea
    name="home_description"
    rows="5"
    maxlength="320"
><?= sm_e(
    sm_val(
        $current,
        'seo',
        'home_description'
    )
) ?></textarea>

<small>
    SEO video/album tetap dikelola lewat SEO Editor.
</small>

</div>


<div class="set2-field">

<label>Default OG / Share Image</label>

<input
    type="text"
    name="default_og_image"
    value="<?= sm_e(
        sm_val(
            $current,
            'seo',
            'default_og_image'
        )
    ) ?>"
    placeholder="/assets/og-asupanlendir.jpg"
>

<small>
    URL absolut atau path lokal diawali /.
</small>

</div>


<label class="set2-toggle-row">

<span>
    <strong>Noindex Homepage</strong>
    <small>
        Normalnya OFF. Aktifkan hanya jika homepage
        tidak ingin masuk indeks mesin pencari.
    </small>
</span>

<input
    type="checkbox"
    name="home_noindex"
    value="1"
    <?= !empty(
        $current['seo']['home_noindex']
        ?? false
    ) ? 'checked' : '' ?>
>

</label>


</section>


<section
    class="set2-panel"
    data-panel="appearance"
>

<div class="set2-section-title">
    Appearance
</div>


<?php
$colorFields = [
    'primary' => [
        'Primary',
        '#FFC107',
    ],
    'background' => [
        'Background',
        '#0F1115',
    ],
    'card' => [
        'Card',
        '#1A1F26',
    ],
    'text' => [
        'Text',
        '#FFFFFF',
    ],
    'text_secondary' => [
        'Text Secondary',
        '#A7ADB3',
    ],
    'theme_color' => [
        'Browser Theme',
        '#0F1115',
    ],
];
?>


<div class="set2-color-grid">

<?php foreach (
    $colorFields
    as $key => [$label, $fallback]
): ?>

<div class="set2-color-card">

<label>
    <?= sm_e($label) ?>
</label>

<div class="set2-color-row">

<input
    type="color"
    value="<?= sm_e(
        sm_val(
            $current,
            'appearance',
            $key,
            $fallback
        )
    ) ?>"
    data-color-picker="<?= sm_e($key) ?>"
>

<input
    type="text"
    name="<?= sm_e($key) ?>"
    maxlength="7"
    value="<?= sm_e(
        sm_val(
            $current,
            'appearance',
            $key,
            $fallback
        )
    ) ?>"
    data-color-text="<?= sm_e($key) ?>"
>

</div>

</div>

<?php endforeach; ?>

</div>


<div class="set2-field">

<label>Logo URL</label>

<input
    type="text"
    name="logo_url"
    value="<?= sm_e(
        sm_val(
            $current,
            'appearance',
            'logo_url',
            '/assets/brand-mark.png'
        )
    ) ?>"
    data-logo-url
>

</div>


<div class="set2-field">

<label>Favicon URL</label>

<input
    type="text"
    name="favicon_url"
    value="<?= sm_e(
        sm_val(
            $current,
            'appearance',
            'favicon_url',
            '/favicon.ico'
        )
    ) ?>"
>

</div>


<div class="set2-preview">

<div class="set2-preview-top">
    Preview
</div>

<div class="set2-preview-brand">

<img
    src="<?= sm_e(
        sm_val(
            $current,
            'appearance',
            'logo_url',
            '/assets/brand-mark.png'
        )
    ) ?>"
    alt=""
    data-logo-preview
>

<div>
    <strong data-name-preview>
        <?= sm_e(
            sm_val(
                $current,
                'general',
                'site_name',
                'AsupanLendir'
            )
        ) ?>
    </strong>

    <span>
        Premium mobile theme
    </span>
</div>

</div>

<div
    class="set2-preview-button"
    data-primary-preview
>
    Primary Button
</div>

</div>


</section>


<section
    class="set2-panel"
    data-panel="integration"
>

<div class="set2-section-title">
    Integration
</div>


<div class="set2-field">

<label>CDN Base URL</label>

<input
    type="url"
    name="cdn_url"
    value="<?= sm_e(
        sm_val(
            $current,
            'integration',
            'cdn_url',
            'https://cdn.videy.ca'
        )
    ) ?>"
>

<small>
    Informasi CDN/R2. Tidak mengubah proses upload bot.
</small>

</div>


<div class="set2-field">

<label>API Provider URL</label>

<input
    type="url"
    name="api_provider_url"
    value="<?= sm_e(
        sm_val(
            $current,
            'integration',
            'api_provider_url'
        )
    ) ?>"
    placeholder="https://provider.example/api"
>

<small>
    Tempat persiapan integrasi provider.
</small>

</div>


<div class="set2-field">

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

<small>
    Kosong = mempertahankan key lama.
</small>

</div>


<div class="set2-status-card">

<div class="set2-status-icon">
    <?= am_icon('settings', 18) ?>
</div>

<div>
    <strong>
        Telegram Bot
    </strong>

    <span>
        Existing / tetap aktif
    </span>

    <small>
        Halaman ini tidak menyentuh token bot,
        webhook, R2 secret, atau channel ID.
    </small>
</div>

</div>


<div class="set2-status-card">

<div class="set2-status-icon">
    <?= am_icon('globe', 18) ?>
</div>

<div>
    <strong>
        API Import
    </strong>

    <span class="muted">
        Prepared / belum aktif
    </span>

    <small>
        Provider URL dan API key hanya disimpan.
        Tidak menjalankan request API.
    </small>
</div>

</div>


</section>


<div class="set2-savebar">

<span>
    Perubahan aktif setelah disimpan.
</span>

<button
    type="submit"
>
    Simpan
</button>

</div>


</form>


</main>

</div>

<?= am_bottom_nav('more') ?>

<script src="/assets/admin-mobile-v1.js?v=1"></script>
<script src="/assets/settings-mobile-v2.js?v=2"></script>

</body>
</html>
