<?php

declare(strict_types=1);

function site_settings_defaults(): array
{
    return [
        'general' => [
            'site_name' => 'AsupanLendir',
            'base_url' => 'https://asupanlendir.sbs',
            'footer_text' => 'AsupanLendir',
            'telegram_url' => '',
        ],
        'seo' => [
            'home_title' => 'AsupanLendir',
            'home_description' =>
                'Video terbaru, koleksi, dan konten trending di AsupanLendir.',
            'default_og_image' =>
                'https://asupanlendir.sbs/assets/og-asupanlendir.jpg',
            'home_noindex' => false,
        ],
        'appearance' => [
            'primary' => '#FFC107',
            'background' => '#0F1115',
            'card' => '#1A1F26',
            'text' => '#FFFFFF',
            'text_secondary' => '#A7ADB3',
            'logo_url' => '/assets/brand-mark.png',
            'favicon_url' => '/favicon.ico',
            'theme_color' => '#0F1115',
        ],
        'integration' => [
            'cdn_url' => 'https://cdn.videy.ca',
            'api_provider_url' => '',
            'api_key' => '',
        ],
    ];
}

function site_settings_merge(array $base, array $override): array
{
    foreach ($override as $section => $values) {
        if (!is_array($values)) {
            continue;
        }

        if (!isset($base[$section]) || !is_array($base[$section])) {
            $base[$section] = [];
        }

        foreach ($values as $key => $value) {
            $base[$section][$key] = $value;
        }
    }

    return $base;
}

function site_settings(): array
{
    static $settings = null;

    if ($settings !== null) {
        return $settings;
    }

    $settings = site_settings_defaults();
    $file = dirname(__DIR__) . '/storage/site-settings.json';

    if (!is_file($file)) {
        return $settings;
    }

    $json = file_get_contents($file);

    if ($json === false) {
        return $settings;
    }

    $runtime = json_decode($json, true);

    if (!is_array($runtime)) {
        return $settings;
    }

    return $settings = site_settings_merge($settings, $runtime);
}

function site_setting(
    string $section,
    string $key,
    mixed $fallback = null
): mixed {
    $settings = site_settings();

    return $settings[$section][$key] ?? $fallback;
}

function site_name(): string
{
    $value = trim((string) site_setting(
        'general',
        'site_name',
        'AsupanLendir'
    ));

    return $value !== '' ? $value : 'AsupanLendir';
}

function site_base_url(): string
{
    $value = rtrim(
        trim((string) site_setting(
            'general',
            'base_url',
            'https://asupanlendir.sbs'
        )),
        '/'
    );

    return $value !== ''
        ? $value
        : 'https://asupanlendir.sbs';
}

function site_footer_text(): string
{
    $value = trim((string) site_setting(
        'general',
        'footer_text',
        site_name()
    ));

    return $value !== ''
        ? $value
        : site_name();
}

function site_home_title(): string
{
    $value = trim((string) site_setting(
        'seo',
        'home_title',
        site_name()
    ));

    return $value !== ''
        ? $value
        : site_name();
}

function site_home_description(): string
{
    $value = trim((string) site_setting(
        'seo',
        'home_description',
        ''
    ));

    if ($value !== '') {
        return $value;
    }

    return 'Video terbaru, koleksi, dan konten trending di '
        . site_name()
        . '.';
}

function site_default_og_image(): string
{
    return trim((string) site_setting(
        'seo',
        'default_og_image',
        ''
    ));
}

function site_home_noindex(): bool
{
    return (bool) site_setting(
        'seo',
        'home_noindex',
        false
    );
}

function site_logo_url(): string
{
    $value = trim((string) site_setting(
        'appearance',
        'logo_url',
        '/assets/brand-mark.png'
    ));

    return $value !== ''
        ? $value
        : '/assets/brand-mark.png';
}

function site_favicon_url(): string
{
    $value = trim((string) site_setting(
        'appearance',
        'favicon_url',
        '/favicon.ico'
    ));

    return $value !== ''
        ? $value
        : '/favicon.ico';
}

function site_valid_hex(
    mixed $value,
    string $fallback
): string {
    $value = strtoupper(trim((string) $value));

    return preg_match(
        '/^#[0-9A-F]{6}$/',
        $value
    )
        ? $value
        : $fallback;
}

function site_appearance(): array
{
    return [
        'primary' => site_valid_hex(
            site_setting('appearance', 'primary', '#FFC107'),
            '#FFC107'
        ),
        'background' => site_valid_hex(
            site_setting('appearance', 'background', '#0F1115'),
            '#0F1115'
        ),
        'card' => site_valid_hex(
            site_setting('appearance', 'card', '#1A1F26'),
            '#1A1F26'
        ),
        'text' => site_valid_hex(
            site_setting('appearance', 'text', '#FFFFFF'),
            '#FFFFFF'
        ),
        'text_secondary' => site_valid_hex(
            site_setting('appearance', 'text_secondary', '#A7ADB3'),
            '#A7ADB3'
        ),
        'theme_color' => site_valid_hex(
            site_setting('appearance', 'theme_color', '#0F1115'),
            '#0F1115'
        ),
    ];
}

function site_head_common(): string
{
    $a = site_appearance();

    $favicon = htmlspecialchars(
        site_favicon_url(),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $theme = htmlspecialchars(
        $a['theme_color'],
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $css = sprintf(
        ':root{'
        . '--pl-primary:%1$s;'
        . '--primary:%1$s;'
        . '--yellow:%1$s;'
        . '--pl-dark:%2$s;'
        . '--bg:%2$s;'
        . '--pl-card:%3$s;'
        . '--panel:%3$s;'
        . '--pl-text:%4$s;'
        . '--text:%4$s;'
        . '--pl-text-secondary:%5$s;'
        . '--muted:%5$s;'
        . '}',
        $a['primary'],
        $a['background'],
        $a['card'],
        $a['text'],
        $a['text_secondary']
    );

    if (!function_exists('ad_meta_verification_render')) {
        require_once __DIR__ . '/ads.php';
    }

    return
        '<link rel="icon" href="' . $favicon . '">' 
        . "\n"
        . '<meta name="theme-color" content="' . $theme . '">'
        . "\n"
        . '<style>' . $css . '</style>'
        . "\n"
        . ad_meta_verification_render();
}
