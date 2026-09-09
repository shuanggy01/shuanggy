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

function pageUrl(array $params = [], string $anchor = ''): string
{
    $query = http_build_query($params);
    return '/' . ($query !== '' ? '?' . $query : '') . $anchor;
}

$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 16;

/*
|--------------------------------------------------------------------------
| GLOBAL COUNTS
|--------------------------------------------------------------------------
*/

$totalVideos = (int) $pdo->query("
    SELECT COUNT(*)
    FROM videos
    WHERE status = 'published'
")->fetchColumn();

$totalAlbums = (int) $pdo->query("
    SELECT COUNT(*)
    FROM collections
    WHERE status = 'published'
")->fetchColumn();

/*
|--------------------------------------------------------------------------
| COLLECTION QUERY
|--------------------------------------------------------------------------
*/

$collectionBaseSql = "
    SELECT
        c.id,
        c.collection_key,
        c.title,
        c.views,
        c.created_at,

        (
            SELECT COUNT(*)
            FROM collection_videos cv_count
            INNER JOIN videos v_count
                ON v_count.id = cv_count.video_id
            WHERE cv_count.collection_id = c.id
              AND v_count.status = 'published'
        ) AS video_count,

        (
            SELECT v_cover.thumbnail_url
            FROM collection_videos cv_cover
            INNER JOIN videos v_cover
                ON v_cover.id = cv_cover.video_id
            WHERE cv_cover.collection_id = c.id
              AND v_cover.status = 'published'
            ORDER BY
                cv_cover.position ASC,
                v_cover.id ASC
            LIMIT 1
        ) AS cover_url,

        COALESCE(
            (
                SELECT COUNT(*)
                FROM collection_views cviews
                WHERE cviews.collection_id = c.id
                  AND cviews.view_date >= CURRENT_DATE() - INTERVAL 6 DAY
            ),
            0
        ) AS views_7d

    FROM collections c
    WHERE c.status = 'published'
";

$collections = [];

if ($q === '') {
    $collections = $pdo->query(
        $collectionBaseSql . "
        ORDER BY c.created_at DESC
        LIMIT 6
        "
    )->fetchAll();
} else {
    $stmt = $pdo->prepare(
        $collectionBaseSql . "
        AND c.title LIKE ?
        ORDER BY c.created_at DESC
        LIMIT 8
        "
    );

    $stmt->execute(['%' . $q . '%']);
    $collections = $stmt->fetchAll();
}

/*
|--------------------------------------------------------------------------
| SEARCH MODE
|--------------------------------------------------------------------------
*/

$searchVideos = [];
$totalSearchVideos = 0;
$searchTotalPages = 1;

if ($q !== '') {
    $like = '%' . $q . '%';

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM videos
        WHERE status = 'published'
          AND (
                title LIKE ?
                OR video_key LIKE ?
          )
    ");

    $countStmt->execute([$like, $like]);
    $totalSearchVideos = (int) $countStmt->fetchColumn();

    $searchTotalPages = max(
        1,
        (int) ceil($totalSearchVideos / $perPage)
    );

    if ($page > $searchTotalPages) {
        $page = $searchTotalPages;
    }

    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("
        SELECT
            video_key,
            title,
            thumbnail_url,
            views,
            created_at
        FROM videos
        WHERE status = 'published'
          AND (
                title LIKE ?
                OR video_key LIKE ?
          )
        ORDER BY created_at DESC
        LIMIT ?
        OFFSET ?
    ");

    $stmt->bindValue(1, $like, PDO::PARAM_STR);
    $stmt->bindValue(2, $like, PDO::PARAM_STR);
    $stmt->bindValue(3, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $searchVideos = $stmt->fetchAll();
}

/*
|--------------------------------------------------------------------------
| HOMEPAGE MODE
|--------------------------------------------------------------------------
*/

$trendingVideos = [];
$latestVideos = [];
$latestTotalPages = 1;

if ($q === '') {

    $trendingVideos = $pdo->query("
        SELECT
            v.video_key,
            v.title,
            v.thumbnail_url,
            v.views,
            v.created_at,
            COALESCE(recent.views_7d, 0) AS views_7d
        FROM videos v

        LEFT JOIN (
            SELECT
                video_id,
                COUNT(*) AS views_7d
            FROM video_views
            WHERE view_date >= CURRENT_DATE() - INTERVAL 6 DAY
            GROUP BY video_id
        ) recent
            ON recent.video_id = v.id

        WHERE v.status = 'published'

        ORDER BY
            views_7d DESC,
            v.created_at DESC

        LIMIT 8
    ")->fetchAll();

    $latestTotalPages = max(
        1,
        (int) ceil($totalVideos / $perPage)
    );

    if ($page > $latestTotalPages) {
        $page = $latestTotalPages;
    }

    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("
        SELECT
            video_key,
            title,
            thumbnail_url,
            views,
            created_at
        FROM videos
        WHERE status = 'published'
        ORDER BY created_at DESC
        LIMIT ?
        OFFSET ?
    ");

    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $latestVideos = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>
<?php if ($q !== ''): ?>
Cari <?= e($q) ?> - <?= e(site_name()) ?>
<?php else: ?>
<?= e(site_home_title()) ?>
<?php endif; ?>
</title>

<?php if ($q !== ''): ?>
<meta name="robots" content="noindex,follow">
<?php endif; ?>

<link
    rel="canonical"
    href="<?= e(site_base_url()) ?>/"
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
    content="<?= e(site_home_title()) ?>"
>

<meta
    property="og:description"
    content="<?= e(site_home_description()) ?>"
>

<meta
    property="og:url"
    content="<?= e(site_base_url()) ?>/"
>

<meta
    property="og:image"
    content="<?= e(site_default_og_image()) ?>"
>

<meta
    property="og:image:width"
    content="1200"
>

<meta
    property="og:image:height"
    content="630"
>

<meta
    name="twitter:card"
    content="summary"
>


<meta
    name="description"
    content="<?= e(site_home_description()) ?>"
>

<style>

:root {
    --bg: #0a0a0a;
    --panel: #141414;
    --panel2: #1b1b1b;
    --line: #262626;
    --text: #f5f5f5;
    --muted: #858585;
    --soft: #b7b7b7;
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

a {
    color: inherit;
}

.container {
    width: min(1240px, calc(100% - 28px));
    margin: auto;
}

/* HEADER */

.site-header {
    position: sticky;
    top: 0;
    z-index: 50;
    background: rgba(10, 10, 10, .96);
    backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--line);
}

.header-main {
    min-height: 68px;
    display: grid;
    grid-template-columns: auto minmax(260px, 600px) auto;
    align-items: center;
    gap: 20px;
}

.logo {
    text-decoration: none;
    font-size: 23px;
    font-weight: 800;
    letter-spacing: -.4px;
}

.search {
    display: flex;
    gap: 8px;
}

.search input {
    width: 100%;
    min-width: 0;
    background: var(--panel);
    border: 1px solid #303030;
    color: #fff;
    border-radius: 11px;
    padding: 11px 13px;
    font-size: 14px;
    outline: 0;
}

.search input:focus {
    border-color: #5b5b5b;
}

.search button {
    border: 0;
    border-radius: 11px;
    background: #fff;
    color: #111;
    font-weight: 700;
    padding: 0 17px;
    cursor: pointer;
}

.header-stats {
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
}

.quick-nav-wrap {
    border-top: 1px solid rgba(255,255,255,.035);
}

.quick-nav {
    min-height: 48px;
    display: flex;
    align-items: center;
    gap: 8px;
    overflow-x: auto;
    scrollbar-width: none;
}

.quick-nav::-webkit-scrollbar {
    display: none;
}

.nav-pill {
    flex: 0 0 auto;
    text-decoration: none;
    padding: 8px 12px;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 999px;
    font-size: 13px;
    color: var(--soft);
}

.nav-pill:hover {
    background: #202020;
    color: #fff;
}

/* MAIN */

main {
    padding: 26px 0 55px;
}

.hero {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    align-items: end;
    margin: 4px 0 29px;
}

.hero h1 {
    margin: 0;
    max-width: 650px;
    font-size: clamp(28px, 5vw, 44px);
    line-height: 1.04;
    letter-spacing: -1.2px;
}

.hero p {
    margin: 9px 0 0;
    color: var(--muted);
    line-height: 1.55;
    max-width: 650px;
}

.hero-side {
    color: var(--muted);
    font-size: 13px;
    white-space: nowrap;
}

.section {
    scroll-margin-top: 125px;
    margin-top: 34px;
}

.section-head {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 15px;
}

.section-title {
    margin: 0;
    font-size: 21px;
    letter-spacing: -.3px;
}

.section-note {
    color: var(--muted);
    font-size: 12px;
}

/* CARDS */

.video-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 19px 16px;
}

.album-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
}

.media-card {
    min-width: 0;
    text-decoration: none;
    display: block;
}

.media-card:hover .media-image img {
    transform: scale(1.025);
}

.media-card:hover .card-title {
    color: #fff;
}

.media-image {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 9;
    overflow: hidden;
    border-radius: 12px;
    background: var(--panel2);
}

.media-image img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: cover;
    transition: transform .18s ease;
}

.no-thumb {
    width: 100%;
    height: 100%;
    display: grid;
    place-items: center;
    color: #707070;
    font-size: 29px;
}

.play {
    position: absolute;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: rgba(0,0,0,.58);
    backdrop-filter: blur(4px);
    color: #fff;
    font-size: 17px;
    opacity: 0;
    transition: opacity .16s ease;
}

.media-card:hover .play {
    opacity: 1;
}

.badge {
    position: absolute;
    right: 8px;
    bottom: 8px;
    padding: 6px 8px;
    border-radius: 7px;
    background: rgba(0,0,0,.76);
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    backdrop-filter: blur(4px);
}

.trending-rank {
    position: absolute;
    left: 8px;
    top: 8px;
    min-width: 29px;
    height: 29px;
    padding: 0 7px;
    display: grid;
    place-items: center;
    border-radius: 8px;
    background: rgba(0,0,0,.76);
    font-size: 11px;
    font-weight: 800;
}

.card-title {
    margin-top: 9px;
    color: #efefef;
    font-size: 14px;
    font-weight: 700;
    line-height: 1.35;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.card-meta {
    margin-top: 5px;
    color: var(--muted);
    font-size: 12px;
}

/* SEARCH */

.search-top {
    display: flex;
    justify-content: space-between;
    align-items: end;
    gap: 15px;
    margin-bottom: 24px;
}

.search-top h1 {
    margin: 0;
    font-size: 27px;
}

.search-term {
    margin-top: 6px;
    color: var(--muted);
    font-size: 13px;
}

.clear-search {
    color: var(--soft);
    text-decoration: none;
    font-size: 13px;
    padding: 9px 11px;
    background: var(--panel);
    border-radius: 8px;
    border: 1px solid var(--line);
}

.empty {
    padding: 45px 20px;
    text-align: center;
    background: var(--panel);
    border: 1px solid var(--line);
    color: var(--muted);
    border-radius: 13px;
}

/* PAGINATION */

.pager {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    margin-top: 31px;
}

.page-btn {
    text-decoration: none;
    padding: 11px 15px;
    border-radius: 9px;
    background: var(--panel2);
    border: 1px solid var(--line);
    color: #ddd;
    font-size: 13px;
}

.page-btn.primary {
    background: #fff;
    color: #111;
    border-color: #fff;
    font-weight: 700;
}

.page-info {
    color: var(--muted);
    font-size: 12px;
    padding: 0 4px;
}

/* FOOTER */

footer {
    border-top: 1px solid var(--line);
    padding: 29px 0 35px;
    color: #666;
    text-align: center;
    font-size: 12px;
}

/* MOBILE */

@media (max-width: 900px) {

    .header-main {
        grid-template-columns: auto 1fr;
        gap: 12px;
        padding: 12px 0;
    }

    .search {
        grid-column: 1 / -1;
        grid-row: 2;
    }

    .header-stats {
        text-align: right;
    }

    .video-grid {
        grid-template-columns: repeat(3, 1fr);
    }

}

@media (max-width: 650px) {

    .container {
        width: calc(100% - 20px);
    }

    .logo {
        font-size: 21px;
    }

    .header-stats {
        font-size: 11px;
    }

    .quick-nav-wrap {
        border-top: 1px solid var(--line);
    }

    main {
        padding-top: 21px;
    }

    .hero {
        align-items: start;
        flex-direction: column;
        margin-bottom: 25px;
    }

    .hero h1 {
        font-size: 30px;
    }

    .hero-side {
        display: none;
    }

    .section {
        margin-top: 30px;
    }

    .video-grid,
    .album-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px 11px;
    }

    .media-image {
        border-radius: 10px;
    }

    .card-title {
        font-size: 13px;
    }

    .play {
        display: none;
    }

    .section-title {
        font-size: 19px;
    }

    .section-note {
        font-size: 11px;
    }

}

</style>

<link rel="stylesheet" href="/assets/theme-pejuang-lendir.css?v=1">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="192x192" href="/favicon-192x192.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="theme-color" content="#0F1115">

<link rel="stylesheet" href="/assets/branding-v2.css?v=2">
<link rel="manifest" href="/site.webmanifest">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="192x192" href="/favicon-192x192.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<link rel="stylesheet" href="/assets/ads-v1.css?v=2">

<?= site_head_common() ?>

<?php if ($q === '' && site_home_noindex()): ?>
<meta name="robots" content="noindex,follow">
<?php endif; ?>


<?php
if (!function_exists('antiblock_render')) {
    require_once dirname(__DIR__) . '/app/ads.php';
}
echo antiblock_render('home', 'head');
?>
<!-- AL_ANTIBLOCK_GLOBAL_V2_home_head -->

</head>

<body>

<header class="site-header">

<div class="container header-main">

<a class="logo" href="/">
    <img
        class="brand-mark"
        src="<?= e(site_logo_url()) ?>"
        alt=""
        width="35"
        height="35"
    >
    <span class="brand-name"><?= e(site_name()) ?></span>
</a>

<form
    class="search"
    method="get"
    action="/"
>
    <input
        type="search"
        name="q"
        value="<?= e($q) ?>"
        placeholder="Cari video atau koleksi..."
        autocomplete="off"
    >

    <button type="submit">
        Cari
    </button>
</form>

<div class="header-stats">
    <?= number_format($totalVideos) ?> video
    ·
    <?= number_format($totalAlbums) ?> album
</div>

</div>

<?php if ($q === ''): ?>

<div class="quick-nav-wrap">

<nav class="container quick-nav">

<a class="nav-pill" href="#trending">
    🔥 Trending
</a>

<a class="nav-pill" href="#koleksi">
    📁 Koleksi
</a>

<a class="nav-pill" href="#terbaru">
    🆕 Terbaru
</a>

</nav>

</div>

<?php endif; ?>

</header>


<main class="container">


<?php if ($q !== ''): ?>

<div class="search-top">

<div>

<h1>
    Hasil pencarian
</h1>

<div class="search-term">
    “<?= e($q) ?>”
    ·
    <?= number_format($totalSearchVideos) ?> video
    <?php if ($collections): ?>
    ·
    <?= number_format(count($collections)) ?> koleksi
    <?php endif; ?>
</div>

</div>

<a
    class="clear-search"
    href="/"
>
    ✕ Bersihkan
</a>

</div>


<?php if ($collections): ?>

<section class="section">

<div class="section-head">

<h2 class="section-title">
    📁 Koleksi
</h2>

<div class="section-note">
    Hasil koleksi
</div>

</div>

<div class="album-grid">

<?php foreach ($collections as $collection): ?>

<a
    class="media-card"
    href="/c/<?= rawurlencode($collection['collection_key']) ?>"
>

<div class="media-image">

<?php if (!empty($collection['cover_url'])): ?>

<img
    src="<?= e($collection['cover_url']) ?>"
    alt="<?= e($collection['title']) ?>"
    loading="lazy"
>

<?php else: ?>

<div class="no-thumb">
    ▶
</div>

<?php endif; ?>

<div class="badge">
    <?= number_format((int) $collection['video_count']) ?> VIDEO
</div>

</div>

<div class="card-title">
    <?= e($collection['title']) ?>
</div>

<div class="card-meta">
    <?= number_format((int) $collection['views']) ?> views
</div>

</a>

<?php endforeach; ?>

</div>

</section>

<?php endif; ?>


<section class="section">

<div class="section-head">

<h2 class="section-title">
    🎬 Video
</h2>

<div class="section-note">
    <?= number_format($totalSearchVideos) ?> hasil
</div>

</div>


<?php if ($searchVideos): ?>

<div class="video-grid">

<?php foreach ($searchVideos as $video): ?>

<a
    class="media-card"
    href="/v/<?= rawurlencode($video['video_key']) ?>"
>

<div class="media-image">

<?php if (!empty($video['thumbnail_url'])): ?>

<img
    src="<?= e($video['thumbnail_url']) ?>"
    alt="<?= e($video['title']) ?>"
    loading="lazy"
>

<?php else: ?>

<div class="no-thumb">
    ▶
</div>

<?php endif; ?>

<div class="play">▶</div>

</div>

<div class="card-title">
    <?= e($video['title']) ?>
</div>

<div class="card-meta">
    <?= number_format((int) $video['views']) ?> views
</div>

</a>

<?php endforeach; ?>

</div>


<?php if ($searchTotalPages > 1): ?>

<div class="pager">

<?php if ($page > 1): ?>

<a
    class="page-btn"
    href="<?= e(pageUrl([
        'q' => $q,
        'page' => $page - 1
    ])) ?>"
>
    ← Sebelumnya
</a>

<?php endif; ?>

<span class="page-info">
    <?= $page ?> / <?= $searchTotalPages ?>
</span>

<?php if ($page < $searchTotalPages): ?>

<a
    class="page-btn primary"
    href="<?= e(pageUrl([
        'q' => $q,
        'page' => $page + 1
    ])) ?>"
>
    Berikutnya →
</a>

<?php endif; ?>

</div>

<?php endif; ?>


<?php else: ?>

<div class="empty">
    Tidak ada video yang cocok dengan “<?= e($q) ?>”.
</div>

<?php endif; ?>

</section>


<?php else: ?>


<section class="hero">

<div>

<h1>
    Video terbaru dan yang lagi ramai.
</h1>

<p>
    Jelajahi video terbaru, koleksi, dan konten yang paling banyak
    dilihat dalam 7 hari terakhir.
</p>

</div>

<div class="hero-side">
    Update otomatis dari posting terbaru
</div>

</section>


<?= ad_render('home_top') ?>

<?php if ($trendingVideos): ?>

<section
    class="section"
    id="trending"
>

<div class="section-head">

<h2 class="section-title">
    🔥 Trending 7 Hari
</h2>

<div class="section-note">
    Berdasarkan unique views
</div>

</div>

<div class="video-grid">

<?php foreach ($trendingVideos as $i => $video): ?>

<a
    class="media-card"
    href="/v/<?= rawurlencode($video['video_key']) ?>"
>

<div class="media-image">

<?php if (!empty($video['thumbnail_url'])): ?>

<img
    src="<?= e($video['thumbnail_url']) ?>"
    alt="<?= e($video['title']) ?>"
    loading="lazy"
>

<?php else: ?>

<div class="no-thumb">
    ▶
</div>

<?php endif; ?>

<div class="trending-rank">
    #<?= $i + 1 ?>
</div>

<?php if ((int) $video['views_7d'] > 0): ?>

<div class="badge">
    🔥 <?= number_format((int) $video['views_7d']) ?>
</div>

<?php endif; ?>

<div class="play">
    ▶
</div>

</div>

<div class="card-title">
    <?= e($video['title']) ?>
</div>

<div class="card-meta">
    <?= number_format((int) $video['views_7d']) ?>
    views / 7 hari
</div>

</a>

<?php endforeach; ?>

</div>

</section>

<?php endif; ?>


<?php if ($collections): ?>

<section
    class="section"
    id="koleksi"
>

<div class="section-head">

<h2 class="section-title">
    📁 Koleksi Terbaru
</h2>

<div class="section-note">
    <?= number_format($totalAlbums) ?> album
</div>

</div>

<div class="album-grid">

<?php foreach ($collections as $collection): ?>

<a
    class="media-card"
    href="/c/<?= rawurlencode($collection['collection_key']) ?>"
>

<div class="media-image">

<?php if (!empty($collection['cover_url'])): ?>

<img
    src="<?= e($collection['cover_url']) ?>"
    alt="<?= e($collection['title']) ?>"
    loading="lazy"
>

<?php else: ?>

<div class="no-thumb">
    ▶
</div>

<?php endif; ?>

<div class="badge">
    <?= number_format((int) $collection['video_count']) ?> VIDEO
</div>

</div>

<div class="card-title">
    <?= e($collection['title']) ?>
</div>

<div class="card-meta">

<?= number_format((int) $collection['views']) ?> views

<?php if ((int) $collection['views_7d'] > 0): ?>
    ·
    <?= number_format((int) $collection['views_7d']) ?> / 7 hari
<?php endif; ?>

</div>

</a>

<?php endforeach; ?>

</div>

</section>

<?php endif; ?>


<?= ad_render('home_mid') ?>

<section
    class="section"
    id="terbaru"
>

<div class="section-head">

<h2 class="section-title">
    🆕 Video Terbaru
</h2>

<div class="section-note">
    Halaman <?= $page ?> dari <?= $latestTotalPages ?>
</div>

</div>


<?php if ($latestVideos): ?>

<div class="video-grid">

<?php foreach ($latestVideos as $video): ?>

<a
    class="media-card"
    href="/v/<?= rawurlencode($video['video_key']) ?>"
>

<div class="media-image">

<?php if (!empty($video['thumbnail_url'])): ?>

<img
    src="<?= e($video['thumbnail_url']) ?>"
    alt="<?= e($video['title']) ?>"
    loading="lazy"
>

<?php else: ?>

<div class="no-thumb">
    ▶
</div>

<?php endif; ?>

<div class="play">
    ▶
</div>

</div>

<div class="card-title">
    <?= e($video['title']) ?>
</div>

<div class="card-meta">
    <?= number_format((int) $video['views']) ?> views
</div>

</a>

<?php endforeach; ?>

</div>


<?php if ($latestTotalPages > 1): ?>

<div class="pager">

<?php if ($page > 1): ?>

<a
    class="page-btn"
    href="<?= e(pageUrl(
        ['page' => $page - 1],
        '#terbaru'
    )) ?>"
>
    ← Sebelumnya
</a>

<?php endif; ?>

<span class="page-info">
    <?= $page ?> / <?= $latestTotalPages ?>
</span>

<?php if ($page < $latestTotalPages): ?>

<a
    class="page-btn primary"
    href="<?= e(pageUrl(
        ['page' => $page + 1],
        '#terbaru'
    )) ?>"
>
    Muat berikutnya →
</a>

<?php endif; ?>

</div>

<?php endif; ?>


<?php else: ?>

<div class="empty">
    Belum ada video.
</div>

<?php endif; ?>

</section>


<?php endif; ?>


</main>


<footer>

<div class="container">
    <?= e(site_footer_text()) ?>
</div>

</footer>


<?php
if (!function_exists('ad_mobile_scripts_render')) { require_once dirname(__DIR__) . '/app/ads.php'; }
echo ad_mobile_scripts_render('home');
?>
<!-- AL_MOBILE_SCRIPTS_V1 -->


<?php
if (!function_exists('site_footer_render')) {
    require_once dirname(__DIR__) . '/app/site_footer.php';
}
?>
<link rel="stylesheet" href="/assets/legal-pages-v1.css?v=1">
<?= site_footer_render() ?>

<link rel="stylesheet" href="/assets/homepage-footer-polish-v2.css?v=1">
<script src="/assets/homepage-footer-polish-v2.js?v=1"></script>
<!-- AL_HOMEPAGE_FOOTER_POLISH_V2 -->

<?php
if (!function_exists('antiblock_render')) {
    require_once dirname(__DIR__) . '/app/ads.php';
}
echo antiblock_render('home', 'body_end');
?>
<!-- AL_ANTIBLOCK_GLOBAL_V2_home_body_end -->

</body>
</html>
