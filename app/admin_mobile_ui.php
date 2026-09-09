<?php

declare(strict_types=1);

function am_e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function am_icon(string $name, int $size = 20): string
{
    $icons = [
        'home' =>
            '<path d="M3 10.8 12 3l9 7.8"/>'
            . '<path d="M5.5 9.8V21h13V9.8"/>'
            . '<path d="M9.5 21v-6h5v6"/>',

        'play' =>
            '<path d="M8 5v14l11-7Z"/>',

        'album' =>
            '<rect x="4" y="4" width="16" height="16" rx="3"/>'
            . '<path d="M8 9h8M8 13h8M8 17h5"/>',

        'ads' =>
            '<rect x="3" y="5" width="18" height="14" rx="3"/>'
            . '<path d="M7 9h4M7 13h7M17 9v6"/>',

        'more' =>
            '<circle cx="5" cy="12" r="1.4" fill="currentColor" stroke="none"/>'
            . '<circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/>'
            . '<circle cx="19" cy="12" r="1.4" fill="currentColor" stroke="none"/>',

        'chart' =>
            '<path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/>',

        'search' =>
            '<circle cx="11" cy="11" r="7"/>'
            . '<path d="m20 20-4-4"/>',

        'settings' =>
            '<circle cx="12" cy="12" r="3"/>'
            . '<path d="M19 12a7 7 0 0 0-.1-1.2l2-1.5-2-3.4-2.5 1A8 8 0 0 0 14.3 5L14 2h-4l-.3 3A8 8 0 0 0 7.6 6.9l-2.5-1-2 3.4 2 1.5A7 7 0 0 0 5 12c0 .4 0 .8.1 1.2l-2 1.5 2 3.4 2.5-1A8 8 0 0 0 9.7 19l.3 3h4l.3-3a8 8 0 0 0 2.1-1.9l2.5 1 2-3.4-2-1.5A7 7 0 0 0 19 12Z"/>',

        'globe' =>
            '<circle cx="12" cy="12" r="9"/>'
            . '<path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>',

        'logout' =>
            '<path d="M10 5H5v14h5"/>'
            . '<path d="M13 8l4 4-4 4M17 12H9"/>',

        'seo' =>
            '<circle cx="10.5" cy="10.5" r="6.5"/>'
            . '<path d="m20 20-4.6-4.6"/>',

        'mirror' =>
            '<rect x="4" y="3" width="16" height="18" rx="8"/>'
            . '<path d="M8 8c2-2 6-2 8 0"/>',

        'plus' =>
            '<path d="M12 5v14M5 12h14"/>',

        'eye' =>
            '<path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/>'
            . '<circle cx="12" cy="12" r="2.5"/>',

        'arrow' =>
            '<path d="M5 12h14M14 7l5 5-5 5"/>',

        'close' =>
            '<path d="m6 6 12 12M18 6 6 18"/>',
    ];

    $body = $icons[$name] ?? $icons['more'];

    return sprintf(
        '<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" '
        . 'fill="none" xmlns="http://www.w3.org/2000/svg" '
        . 'stroke="currentColor" stroke-width="1.8" '
        . 'stroke-linecap="round" stroke-linejoin="round" '
        . 'aria-hidden="true">%2$s</svg>',
        $size,
        $body
    );
}

function am_bottom_nav(string $active = 'home'): string
{
    $items = [
        'home' => [
            'Home',
            '/admin/mobile.php',
            'home',
        ],
        'video' => [
            'Video',
            '/admin/videos-mobile.php',
            'play',
        ],
        'album' => [
            'Kategori',
            '/admin/categories-mobile.php',
            'album',
        ],
        'ads' => [
            'Ads',
            '/admin/ads-mobile.php',
            'ads',
        ],
    ];

    ob_start();
    ?>
<nav class="am-bottom-nav" aria-label="Admin mobile navigation">
    <?php foreach ($items as $key => [$label, $href, $icon]): ?>
        <a
            href="<?= am_e($href) ?>"
            class="am-nav-item <?= $active === $key ? 'active' : '' ?>"
        >
            <?= am_icon($icon, 21) ?>
            <span><?= am_e($label) ?></span>
        </a>
    <?php endforeach; ?>

    <button
        type="button"
        class="am-nav-item"
        data-more-open
    >
        <?= am_icon('more', 21) ?>
        <span>More</span>
    </button>
</nav>

<div
    class="am-sheet-backdrop"
    data-more-backdrop
></div>

<section
    class="am-more-sheet"
    data-more-sheet
    aria-hidden="true"
>
    <div class="am-sheet-handle"></div>

    <div class="am-sheet-head">
        <div>
            <strong>More</strong>
            <span>Admin tools</span>
        </div>

        <button
            type="button"
            class="am-icon-btn"
            data-more-close
            aria-label="Tutup"
        >
            <?= am_icon('close', 20) ?>
        </button>
    </div>

    <div class="am-more-grid">
        <a href="/admin/analytics-mobile.php">
            <?= am_icon('chart', 20) ?>
            <span>Analytics</span>
        </a>

        <a href="/admin/seo-mobile.php">
            <?= am_icon('seo', 20) ?>
            <span>SEO</span>
        </a>

        <a href="/admin/settings-mobile.php">
            <?= am_icon('settings', 20) ?>
            <span>Settings</span>
        </a>

        <a href="/admin/mirrors.php">
            <?= am_icon('mirror', 20) ?>
            <span>Mirrors</span>
        </a>

        <a href="/" target="_blank" rel="noopener">
            <?= am_icon('globe', 20) ?>
            <span>Website</span>
        </a>

        <a href="/admin/logout.php" class="danger">
            <?= am_icon('logout', 20) ?>
            <span>Logout</span>
        </a>
    </div>
</section>
    <?php

    return (string) ob_get_clean();
}
