<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/database.php';

require dirname(__DIR__) . '/app/site_settings.php';

require dirname(__DIR__) . '/app/ads.php';

function e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$collectionKey = trim((string) ($_GET['id'] ?? ''));

if (
    $collectionKey === '' ||
    !preg_match('/^[A-Za-z0-9_-]{4,20}$/', $collectionKey)
) {
    http_response_code(404);
    exit('Koleksi tidak ditemukan.');
}

/*
|--------------------------------------------------------------------------
| COLLECTION
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM collections
    WHERE collection_key = ?
      AND status = 'published'
    LIMIT 1
");

$stmt->execute([$collectionKey]);

$collection = $stmt->fetch();

if (!$collection) {
    http_response_code(404);
    exit('Koleksi tidak ditemukan.');
}

/*
|--------------------------------------------------------------------------
| UNIQUE VIEW COUNTER
|--------------------------------------------------------------------------
*/

$shouldCountView =
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';

$userAgent = strtolower(
    (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
);

if (
    preg_match(
        '/bot|crawler|spider|slurp|facebookexternalhit|telegrambot|whatsapp|discordbot|twitterbot|preview|curl|wget/i',
        $userAgent
    )
) {
    $shouldCountView = false;
}

if ($shouldCountView) {

    $viewerId =
        (string) ($_COOKIE['al_viewer'] ?? '');

    if (
        !preg_match(
            '/^[a-f0-9]{32}$/',
            $viewerId
        )
    ) {
        $viewerId =
            bin2hex(random_bytes(16));

        setcookie(
            'al_viewer',
            $viewerId,
            [
                'expires'  => time() + 31536000,
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    $viewerHash =
        hash('sha256', $viewerId);

    $viewStmt = $pdo->prepare("
        INSERT IGNORE INTO collection_views
        (
            collection_id,
            viewer_hash,
            view_date
        )
        VALUES (?, ?, CURRENT_DATE())
    ");

    $viewStmt->execute([
        (int) $collection['id'],
        $viewerHash
    ]);

    if ($viewStmt->rowCount() === 1) {

        $update = $pdo->prepare("
            UPDATE collections
            SET views = views + 1
            WHERE id = ?
        ");

        $update->execute([
            (int) $collection['id']
        ]);

        $collection['views'] =
            (int) $collection['views'] + 1;
    }
}

/*
|--------------------------------------------------------------------------
| VIDEOS IN COLLECTION
|--------------------------------------------------------------------------
*/

$videosStmt = $pdo->prepare("
    SELECT
        v.id,
        v.video_key,
        v.title,
        v.thumbnail_url,
        v.views,
        v.created_at,
        cv.position,

        COALESCE(
            (
                SELECT COUNT(*)
                FROM video_views vv
                WHERE vv.video_id = v.id
                  AND vv.view_date >= CURRENT_DATE() - INTERVAL 6 DAY
            ),
            0
        ) AS views_7d

    FROM collection_videos cv

    INNER JOIN videos v
        ON v.id = cv.video_id

    WHERE cv.collection_id = ?
      AND v.status = 'published'

    ORDER BY
        cv.position ASC,
        v.id ASC
");

$videosStmt->execute([
    (int) $collection['id']
]);

$videos = $videosStmt->fetchAll();

$videoCount = count($videos);

/*
|--------------------------------------------------------------------------
| COLLECTION STATS
|--------------------------------------------------------------------------
*/

$weekStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM collection_views
    WHERE collection_id = ?
      AND view_date >= CURRENT_DATE() - INTERVAL 6 DAY
");

$weekStmt->execute([
    (int) $collection['id']
]);

$views7d = (int) $weekStmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| COVER / SEO / SHARE
|--------------------------------------------------------------------------
*/

$coverUrl = '';

foreach ($videos as $row) {
    if (!empty($row['thumbnail_url'])) {
        $coverUrl = (string) $row['thumbnail_url'];
        break;
    }
}

$appUrl = site_base_url();

$currentUrl =
    $appUrl .
    '/c/' .
    rawurlencode($collection['collection_key']);

$description =
    $collection['title'] .
    ' - ' .
    number_format($videoCount) .
    ' video di ' . site_name() . '.';

$customSeoTitle =
    trim(
        (string) (
            $collection['seo_title']
            ?? ''
        )
    );

$pageTitle =
    $customSeoTitle !== ''
        ? $customSeoTitle
        : $collection['title'] . ' - ' . site_name();

$customSeoDescription =
    trim(
        (string) (
            $collection['seo_description']
            ?? ''
        )
    );

$seoDescription =
    $customSeoDescription !== ''
        ? $customSeoDescription
        : $description;

$customSeoImage =
    trim(
        (string) (
            $collection['seo_image_url']
            ?? ''
        )
    );

$shareImage =
    $customSeoImage !== ''
        ? $customSeoImage
        : $coverUrl;

$seoNoindex =
    (int) (
        $collection['seo_noindex']
        ?? 0
    ) === 1;

$shareText =
    $collection['title'] .
    "\n" .
    $currentUrl;

$whatsappUrl =
    'https://wa.me/?text=' .
    rawurlencode($shareText);

$telegramUrl =
    'https://t.me/share/url?' .
    http_build_query([
        'url' => $currentUrl,
        'text' => $collection['title']
    ]);

?>
<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title><?= e($pageTitle) ?></title>

<meta
    name="description"
    content="<?= e($seoDescription) ?>"
>

<link
    rel="canonical"
    href="<?= e($currentUrl) ?>"
>

<meta
    property="og:type"
    content="website"
>

<meta
    property="og:site_name"
    content="<?= e(site_name()) ?>"
>

<meta
    property="og:title"
    content="<?= e($pageTitle) ?>"
>

<meta
    property="og:description"
    content="<?= e($seoDescription) ?>"
>

<meta
    property="og:url"
    content="<?= e($currentUrl) ?>"
>

<?php if ($seoNoindex): ?>

<meta
    name="robots"
    content="noindex,follow"
>

<?php endif; ?>

<?php if ($shareImage !== ''): ?>

<meta
    property="og:image"
    content="<?= e($shareImage) ?>"
>

<meta
    name="twitter:card"
    content="summary_large_image"
>

<meta
    name="twitter:image"
    content="<?= e($shareImage) ?>"
>

<?php endif; ?>

<style>

:root {
    --bg: #0a0a0a;
    --panel: #151515;
    --panel2: #1c1c1c;
    --line: #272727;
    --text: #f4f4f4;
    --muted: #858585;
    --soft: #b9b9b9;
}

* {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: Arial, Helvetica, sans-serif;
}

.container {
    width: min(1120px, calc(100% - 28px));
    margin: auto;
}

/* HEADER */

header {
    position: sticky;
    top: 0;
    z-index: 40;
    background: rgba(10, 10, 10, .96);
    backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--line);
}

.header-inner {
    min-height: 67px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
}

.logo {
    color: #fff;
    text-decoration: none;
    font-size: 23px;
    font-weight: 800;
    letter-spacing: -.4px;
}

.home-link {
    text-decoration: none;
    color: var(--soft);
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 9px;
    padding: 9px 12px;
    font-size: 12px;
}

/* MAIN */

main {
    padding: 24px 0 55px;
}

.breadcrumb {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: 16px;
    color: var(--muted);
    font-size: 12px;
}

.breadcrumb a {
    color: var(--soft);
    text-decoration: none;
}

.breadcrumb .sep {
    color: #4b4b4b;
}

/* HERO */

.album-hero {
    display: grid;
    grid-template-columns: minmax(260px, 420px) 1fr;
    gap: 24px;
    align-items: center;
    padding: 18px;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 15px;
}

.album-cover {
    position: relative;
    aspect-ratio: 16 / 9;
    overflow: hidden;
    border-radius: 12px;
    background: var(--panel2);
}

.album-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.cover-placeholder {
    width: 100%;
    height: 100%;
    display: grid;
    place-items: center;
    color: #6d6d6d;
    font-size: 40px;
}

.cover-badge {
    position: absolute;
    right: 10px;
    bottom: 10px;
    padding: 7px 9px;
    border-radius: 8px;
    background: rgba(0,0,0,.75);
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    backdrop-filter: blur(4px);
}

.album-info h1 {
    margin: 0;
    font-size: clamp(26px, 5vw, 38px);
    line-height: 1.08;
    letter-spacing: -.8px;
}

.album-meta {
    margin-top: 12px;
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    color: var(--muted);
    font-size: 13px;
}

.dot {
    color: #4b4b4b;
}

.album-desc {
    margin-top: 13px;
    color: #b8b8b8;
    line-height: 1.55;
    font-size: 13px;
}

.actions {
    margin-top: 18px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.btn {
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 10px 13px;
    background: var(--panel2);
    color: #eee;
    text-decoration: none;
    cursor: pointer;
    font-size: 13px;
}

.btn:hover {
    background: #242424;
}

.btn.primary {
    background: #fff;
    color: #111;
    border-color: #fff;
    font-weight: 700;
}

/* CONTENT */

.section {
    margin-top: 34px;
}

.section-head {
    display: flex;
    justify-content: space-between;
    align-items: end;
    gap: 12px;
    margin-bottom: 15px;
}

.section-head h2 {
    margin: 0;
    font-size: 21px;
}

.section-head span {
    color: var(--muted);
    font-size: 12px;
}

.grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px 14px;
}

.card {
    color: #fff;
    text-decoration: none;
    min-width: 0;
}

.thumb {
    position: relative;
    aspect-ratio: 16 / 9;
    overflow: hidden;
    border-radius: 11px;
    background: var(--panel2);
}

.thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform .18s ease;
}

.card:hover .thumb img {
    transform: scale(1.025);
}

.placeholder {
    width: 100%;
    height: 100%;
    display: grid;
    place-items: center;
    color: #6d6d6d;
    font-size: 28px;
}

.order-badge {
    position: absolute;
    left: 8px;
    top: 8px;
    min-width: 29px;
    height: 29px;
    padding: 0 7px;
    display: grid;
    place-items: center;
    border-radius: 8px;
    background: rgba(0,0,0,.74);
    color: #fff;
    font-size: 11px;
    font-weight: 800;
}

.trend-badge {
    position: absolute;
    right: 8px;
    bottom: 8px;
    padding: 6px 8px;
    border-radius: 7px;
    background: rgba(0,0,0,.74);
    color: #fff;
    font-size: 10px;
    font-weight: 800;
}

.play {
    position: absolute;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    width: 40px;
    height: 40px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: rgba(0,0,0,.58);
    opacity: 0;
    transition: opacity .16s ease;
}

.card:hover .play {
    opacity: 1;
}

.video-title {
    margin-top: 8px;
    color: #efefef;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.video-meta {
    margin-top: 5px;
    color: var(--muted);
    font-size: 11px;
}

.empty {
    padding: 46px 20px;
    text-align: center;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 13px;
    color: var(--muted);
}

/* FOOTER */

footer {
    border-top: 1px solid var(--line);
    text-align: center;
    color: #626262;
    font-size: 12px;
    padding: 30px 0 36px;
}

/* TOAST */

.toast {
    position: fixed;
    left: 50%;
    bottom: 24px;
    transform: translate(-50%, 20px);
    z-index: 100;
    background: #fff;
    color: #111;
    padding: 10px 14px;
    border-radius: 9px;
    font-size: 12px;
    font-weight: 700;
    opacity: 0;
    pointer-events: none;
    transition:
        opacity .18s ease,
        transform .18s ease;
}

.toast.show {
    opacity: 1;
    transform: translate(-50%, 0);
}

/* RESPONSIVE */

@media (max-width: 820px) {

    .album-hero {
        grid-template-columns: 1fr;
        align-items: start;
    }

    .album-cover {
        max-width: 600px;
    }

    .grid {
        grid-template-columns: repeat(3, 1fr);
    }

}

@media (max-width: 620px) {

    .container {
        width: calc(100% - 20px);
    }

    .logo {
        font-size: 21px;
    }

    main {
        padding-top: 19px;
    }

    .album-hero {
        padding: 13px;
        gap: 17px;
        border-radius: 12px;
    }

    .album-cover {
        border-radius: 10px;
    }

    .album-info h1 {
        font-size: 28px;
    }

    .grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px 11px;
    }

    .video-title {
        font-size: 12px;
    }

    .play {
        display: none;
    }

}

</style>

<link rel="stylesheet" href="/assets/theme-pejuang-lendir.css?v=1">
<link rel="stylesheet" href="/assets/branding-v2.css?v=2">
<link rel="manifest" href="/site.webmanifest">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="192x192" href="/favicon-192x192.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="theme-color" content="#0F1115">

<link rel="stylesheet" href="/assets/ads-v1.css?v=1">

<?= site_head_common() ?>

</head>

<body>

<header>

<div class="container header-inner">

<a
    class="logo"
    href="/"
><?= e(site_name()) ?></a>

<a
    class="home-link"
    href="/"
>
    ← Homepage
</a>

</div>

</header>


<main class="container">


<div class="breadcrumb">

<a href="/">
    Home
</a>

<span class="sep">›</span>

<span>
    <?= e($collection['title']) ?>
</span>

</div>


<section class="album-hero">

<div class="album-cover">

<?php if ($coverUrl !== ''): ?>

<img
    src="<?= e($coverUrl) ?>"
    alt="<?= e($collection['title']) ?>"
>

<?php else: ?>

<div class="cover-placeholder">
    ▶
</div>

<?php endif; ?>

<div class="cover-badge">
    <?= number_format($videoCount) ?> VIDEO
</div>

</div>


<div class="album-info">

<h1>
    <?= e($collection['title']) ?>
</h1>

<div class="album-meta">

<span>
    📁 <?= number_format($videoCount) ?> video
</span>

<span class="dot">•</span>

<span>
    👁 <?= number_format((int) $collection['views']) ?> views
</span>

<?php if ($views7d > 0): ?>

<span class="dot">•</span>

<span>
    🔥 <?= number_format($views7d) ?> / 7 hari
</span>

<?php endif; ?>

</div>

<div class="album-desc">
    Jelajahi semua video dalam koleksi ini.
    Urutan video mengikuti susunan album.
</div>

<div class="actions">

<button
    class="btn primary"
    type="button"
    onclick="copyLink()"
>
    🔗 Copy Link
</button>

<button
    class="btn"
    type="button"
    onclick="nativeShare()"
>
    📤 Share
</button>

<a
    class="btn"
    href="<?= e($whatsappUrl) ?>"
    target="_blank"
    rel="noopener"
>
    WhatsApp
</a>

<a
    class="btn"
    href="<?= e($telegramUrl) ?>"
    target="_blank"
    rel="noopener"
>
    Telegram
</a>

</div>

</div>

</section>


<?= ad_render('album_top') ?>

<section class="section">

<div class="section-head">

<h2>
    Semua Video
</h2>

<span>
    <?= number_format($videoCount) ?> video
</span>

</div>


<?php if ($videos): ?>

<div class="grid">

<?php foreach ($videos as $index => $video): ?>

<a
    class="card"
    href="/v/<?= rawurlencode($video['video_key']) ?>"
>

<div class="thumb">

<?php if (!empty($video['thumbnail_url'])): ?>

<img
    src="<?= e($video['thumbnail_url']) ?>"
    alt="<?= e($video['title']) ?>"
    loading="lazy"
>

<?php else: ?>

<div class="placeholder">
    ▶
</div>

<?php endif; ?>

<div class="order-badge">
    #<?= $index + 1 ?>
</div>

<?php if ((int) $video['views_7d'] > 0): ?>

<div class="trend-badge">
    🔥 <?= number_format((int) $video['views_7d']) ?>
</div>

<?php endif; ?>

<div class="play">
    ▶
</div>

</div>

<div class="video-title">
    <?= e($video['title']) ?>
</div>

<div class="video-meta">
    <?= number_format((int) $video['views']) ?> views
</div>

</a>

<?php endforeach; ?>

</div>

<?php else: ?>

<div class="empty">
    Album ini belum memiliki video yang dipublikasikan.
</div>

<?php endif; ?>

</section>


</main>


<footer>

<div class="container">
    <?= e(site_footer_text()) ?>
</div>

</footer>


<div
    class="toast"
    id="toast"
>
    Link berhasil disalin
</div>


<script>

const pageUrl = <?= json_encode(
    $currentUrl,
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE
) ?>;

const pageTitle = <?= json_encode(
    $collection['title'],
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE
) ?>;

let toastTimer = null;

function showToast(text) {

    const toast =
        document.getElementById('toast');

    toast.textContent = text;
    toast.classList.add('show');

    clearTimeout(toastTimer);

    toastTimer = setTimeout(() => {
        toast.classList.remove('show');
    }, 1800);
}

async function copyLink() {

    try {

        await navigator.clipboard.writeText(
            pageUrl
        );

        showToast(
            'Link berhasil disalin'
        );

    } catch (e) {

        window.prompt(
            'Salin link:',
            pageUrl
        );
    }
}

async function nativeShare() {

    if (navigator.share) {

        try {

            await navigator.share({
                title: pageTitle,
                url: pageUrl
            });

        } catch (e) {}

        return;
    }

    copyLink();
}

</script>

</body>
</html>
