<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/database.php';
require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';

admin_require_login();

function an_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?"
        );

        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function an_scalar(
    PDO $pdo,
    string $sql,
    array $params = [],
    int $fallback = 0
): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $fallback;
    }
}

function an_fetch_all(
    PDO $pdo,
    string $sql,
    array $params = []
): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function an_day_label(string $date): string
{
    $ts = strtotime($date);

    if ($ts === false) {
        return $date;
    }

    $days = [
        0 => 'Min',
        1 => 'Sen',
        2 => 'Sel',
        3 => 'Rab',
        4 => 'Kam',
        5 => 'Jum',
        6 => 'Sab',
    ];

    return $days[(int) date('w', $ts)];
}

$hasVideoViews = an_table_exists($pdo, 'video_views');
$hasCollectionViews = an_table_exists($pdo, 'collection_views');

$totalVideoViews = an_scalar(
    $pdo,
    "SELECT COALESCE(SUM(views), 0)
     FROM videos"
);

$totalAlbumViews = an_scalar(
    $pdo,
    "SELECT COALESCE(SUM(views), 0)
     FROM collections"
);

$totalViews = $totalVideoViews + $totalAlbumViews;

$today = 0;
$last7 = 0;
$last30 = 0;

if ($hasVideoViews) {
    $today += an_scalar(
        $pdo,
        "SELECT COUNT(*)
         FROM video_views
         WHERE view_date = CURDATE()"
    );

    $last7 += an_scalar(
        $pdo,
        "SELECT COUNT(*)
         FROM video_views
         WHERE view_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"
    );

    $last30 += an_scalar(
        $pdo,
        "SELECT COUNT(*)
         FROM video_views
         WHERE view_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)"
    );
}

if ($hasCollectionViews) {
    $today += an_scalar(
        $pdo,
        "SELECT COUNT(*)
         FROM collection_views
         WHERE view_date = CURDATE()"
    );

    $last7 += an_scalar(
        $pdo,
        "SELECT COUNT(*)
         FROM collection_views
         WHERE view_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"
    );

    $last30 += an_scalar(
        $pdo,
        "SELECT COUNT(*)
         FROM collection_views
         WHERE view_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)"
    );
}

$publishedVideos = an_scalar(
    $pdo,
    "SELECT COUNT(*)
     FROM videos
     WHERE status = 'published'"
);

$publishedAlbums = an_scalar(
    $pdo,
    "SELECT COUNT(*)
     FROM collections
     WHERE status = 'published'"
);

/*
|--------------------------------------------------------------------------
| 7-day trend
|--------------------------------------------------------------------------
*/

$videoTrend = [];

if ($hasVideoViews) {
    $rows = an_fetch_all(
        $pdo,
        "SELECT
            view_date,
            COUNT(*) AS total
         FROM video_views
         WHERE view_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         GROUP BY view_date
         ORDER BY view_date ASC"
    );

    foreach ($rows as $row) {
        $videoTrend[(string) $row['view_date']] =
            (int) $row['total'];
    }
}

$albumTrend = [];

if ($hasCollectionViews) {
    $rows = an_fetch_all(
        $pdo,
        "SELECT
            view_date,
            COUNT(*) AS total
         FROM collection_views
         WHERE view_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         GROUP BY view_date
         ORDER BY view_date ASC"
    );

    foreach ($rows as $row) {
        $albumTrend[(string) $row['view_date']] =
            (int) $row['total'];
    }
}

$trend = [];

for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
    $date = date(
        'Y-m-d',
        strtotime('-' . $daysAgo . ' day')
    );

    $videoCount = $videoTrend[$date] ?? 0;
    $albumCount = $albumTrend[$date] ?? 0;

    $trend[] = [
        'date' => $date,
        'label' => an_day_label($date),
        'video' => $videoCount,
        'album' => $albumCount,
        'total' => $videoCount + $albumCount,
    ];
}

$maxTrend = 1;

foreach ($trend as $day) {
    $maxTrend = max(
        $maxTrend,
        (int) $day['total']
    );
}

/*
|--------------------------------------------------------------------------
| Top video / album
|--------------------------------------------------------------------------
*/

$topVideos = [];

if ($hasVideoViews) {
    $topVideos = an_fetch_all(
        $pdo,
        "SELECT
            v.id,
            v.video_key,
            v.title,
            v.thumbnail_url,
            v.views AS all_views,
            COUNT(vv.id) AS unique_7d
         FROM videos v
         JOIN video_views vv
           ON vv.video_id = v.id
          AND vv.view_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         WHERE v.status = 'published'
         GROUP BY
            v.id,
            v.video_key,
            v.title,
            v.thumbnail_url,
            v.views
         ORDER BY unique_7d DESC, v.views DESC
         LIMIT 5"
    );
}

if (!$topVideos) {
    $topVideos = an_fetch_all(
        $pdo,
        "SELECT
            id,
            video_key,
            title,
            thumbnail_url,
            views AS all_views,
            0 AS unique_7d
         FROM videos
         WHERE status = 'published'
         ORDER BY views DESC, id DESC
         LIMIT 5"
    );
}

$topAlbums = [];

if ($hasCollectionViews) {
    $topAlbums = an_fetch_all(
        $pdo,
        "SELECT
            c.id,
            c.collection_key,
            c.title,
            c.views AS all_views,
            COUNT(cv.id) AS unique_7d
         FROM collections c
         JOIN collection_views cv
           ON cv.collection_id = c.id
          AND cv.view_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         WHERE c.status = 'published'
         GROUP BY
            c.id,
            c.collection_key,
            c.title,
            c.views
         ORDER BY unique_7d DESC, c.views DESC
         LIMIT 5"
    );
}

if (!$topAlbums) {
    $topAlbums = an_fetch_all(
        $pdo,
        "SELECT
            id,
            collection_key,
            title,
            views AS all_views,
            0 AS unique_7d
         FROM collections
         WHERE status = 'published'
         ORDER BY views DESC, id DESC
         LIMIT 5"
    );
}

/*
|--------------------------------------------------------------------------
| Best content
|--------------------------------------------------------------------------
*/

$bestVideo = $topVideos[0] ?? null;
$bestAlbum = $topAlbums[0] ?? null;

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

<title>Analytics Mobile</title>

<link
    rel="stylesheet"
    href="/assets/admin-mobile-v1.css?v=1"
>
<link
    rel="stylesheet"
    href="/assets/analytics-mobile-v2.css?v=2"
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
                <strong>Analytics</strong>
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


<main class="am-main an2-main">


<section class="an2-page-head">
    <div class="am-eyebrow">Insights</div>
    <h1>Analytics</h1>
    <p>
        Ringkasan performa video dan album dari data view website.
    </p>
</section>


<section class="an2-summary">

<article>
    <div class="an2-stat-icon">
        <?= am_icon('eye', 18) ?>
    </div>

    <span>Hari Ini</span>
    <strong><?= number_format($today) ?></strong>
    <small>Unique activity</small>
</article>


<article>
    <div class="an2-stat-icon">
        <?= am_icon('chart', 18) ?>
    </div>

    <span>7 Hari</span>
    <strong><?= number_format($last7) ?></strong>
    <small>Unique activity</small>
</article>


<article>
    <div class="an2-stat-icon">
        <?= am_icon('chart', 18) ?>
    </div>

    <span>30 Hari</span>
    <strong><?= number_format($last30) ?></strong>
    <small>Unique activity</small>
</article>


<article>
    <div class="an2-stat-icon">
        <?= am_icon('eye', 18) ?>
    </div>

    <span>Total Views</span>
    <strong><?= number_format($totalViews) ?></strong>
    <small>All time counter</small>
</article>

</section>


<section class="an2-card">

<div class="an2-card-head">
    <div>
        <span>Trend</span>
        <h2>7 Hari Terakhir</h2>
    </div>

    <div class="an2-total-badge">
        <?= number_format($last7) ?>
    </div>
</div>


<div class="an2-chart">

<?php foreach ($trend as $day): ?>

<?php
$height = max(
    5,
    (int) round(
        ((int) $day['total'] / $maxTrend) * 100
    )
);
?>

<div class="an2-bar-item">

<div class="an2-bar-value">
    <?= number_format((int) $day['total']) ?>
</div>

<div class="an2-bar-track">
    <div
        class="an2-bar"
        style="height:<?= $height ?>%"
        title="<?= am_e(
            $day['date']
            . ': '
            . (string) $day['total']
        ) ?>"
    ></div>
</div>

<div class="an2-bar-day">
    <?= am_e($day['label']) ?>
</div>

</div>

<?php endforeach; ?>

</div>


<div class="an2-chart-note">
    <span>
        Video <?= number_format(array_sum(array_column($trend, 'video'))) ?>
    </span>

    <span>
        Album <?= number_format(array_sum(array_column($trend, 'album'))) ?>
    </span>
</div>

</section>


<section class="an2-mini-grid">

<div class="an2-mini-card">
    <div class="an2-mini-icon">
        <?= am_icon('play', 18) ?>
    </div>

    <span>Published Video</span>
    <strong><?= number_format($publishedVideos) ?></strong>
</div>


<div class="an2-mini-card">
    <div class="an2-mini-icon">
        <?= am_icon('album', 18) ?>
    </div>

    <span>Published Album</span>
    <strong><?= number_format($publishedAlbums) ?></strong>
</div>

</section>


<section class="an2-section">

<div class="an2-section-head">
    <div>
        <span>Ranking</span>
        <h2>Top Video • 7 Hari</h2>
    </div>

    <a href="/admin/videos-mobile.php">
        Video
        <?= am_icon('arrow', 14) ?>
    </a>
</div>


<div class="an2-list">

<?php if (!$topVideos): ?>

<div class="am-empty">
    Belum ada data video.
</div>

<?php else: ?>

<?php foreach ($topVideos as $index => $video): ?>

<a
    class="an2-rank-card"
    href="/v/<?= rawurlencode((string) $video['video_key']) ?>"
    target="_blank"
    rel="noopener"
>

<div class="an2-rank-number">
    <?= $index + 1 ?>
</div>


<?php if (!empty($video['thumbnail_url'])): ?>

<img
    class="an2-thumb"
    src="<?= am_e((string) $video['thumbnail_url']) ?>"
    alt=""
    loading="lazy"
>

<?php else: ?>

<div class="an2-thumb an2-thumb-empty">
    <?= am_icon('play', 18) ?>
</div>

<?php endif; ?>


<div class="an2-rank-copy">

<strong>
    <?= am_e((string) $video['title']) ?>
</strong>

<span>
    <?= number_format((int) $video['unique_7d']) ?>
    unique 7d
    ·
    <?= number_format((int) $video['all_views']) ?>
    total
</span>

</div>


<div class="an2-rank-arrow">
    <?= am_icon('arrow', 16) ?>
</div>

</a>

<?php endforeach; ?>

<?php endif; ?>

</div>

</section>


<section class="an2-section">

<div class="an2-section-head">
    <div>
        <span>Ranking</span>
        <h2>Top Album • 7 Hari</h2>
    </div>

    <a href="/admin/albums-mobile.php">
        Album
        <?= am_icon('arrow', 14) ?>
    </a>
</div>


<div class="an2-list">

<?php if (!$topAlbums): ?>

<div class="am-empty">
    Belum ada data album.
</div>

<?php else: ?>

<?php foreach ($topAlbums as $index => $album): ?>

<a
    class="an2-album-card"
    href="/c/<?= rawurlencode((string) $album['collection_key']) ?>"
    target="_blank"
    rel="noopener"
>

<div class="an2-rank-number">
    <?= $index + 1 ?>
</div>

<div class="an2-album-icon">
    <?= am_icon('album', 18) ?>
</div>


<div class="an2-rank-copy">

<strong>
    <?= am_e((string) $album['title']) ?>
</strong>

<span>
    <?= number_format((int) $album['unique_7d']) ?>
    unique 7d
    ·
    <?= number_format((int) $album['all_views']) ?>
    total
</span>

</div>


<div class="an2-rank-arrow">
    <?= am_icon('arrow', 16) ?>
</div>

</a>

<?php endforeach; ?>

<?php endif; ?>

</div>

</section>


</main>

</div>

<?= am_bottom_nav('more') ?>

<script src="/assets/admin-mobile-v1.js?v=1"></script>

</body>
</html>
