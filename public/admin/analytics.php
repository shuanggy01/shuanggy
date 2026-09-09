<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';

admin_require_login();

function ae(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$summary = $pdo->query("
    SELECT
        COUNT(*) AS total_unique_views,
        SUM(view_date = CURRENT_DATE()) AS today_views,
        SUM(view_date >= CURRENT_DATE() - INTERVAL 6 DAY) AS views_7d,
        SUM(view_date >= CURRENT_DATE() - INTERVAL 29 DAY) AS views_30d
    FROM video_views
")->fetch();

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
| TRAFFIC 14 DAYS
|--------------------------------------------------------------------------
*/

$rawTraffic = $pdo->query("
    SELECT
        view_date,
        COUNT(*) AS views
    FROM video_views
    WHERE view_date >= CURRENT_DATE() - INTERVAL 13 DAY
    GROUP BY view_date
    ORDER BY view_date ASC
")->fetchAll();

$trafficMap = [];

foreach ($rawTraffic as $row) {
    $trafficMap[$row['view_date']] = (int) $row['views'];
}

$traffic = [];
$maxTraffic = 1;

for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} day"));
    $views = $trafficMap[$date] ?? 0;

    $traffic[] = [
        'date' => $date,
        'label' => date('d M', strtotime($date)),
        'views' => $views,
    ];

    $maxTraffic = max($maxTraffic, $views);
}

/*
|--------------------------------------------------------------------------
| TOP VIDEO 7 DAYS
|--------------------------------------------------------------------------
*/

$topVideos = $pdo->query("
    SELECT
        v.id,
        v.video_key,
        v.title,
        v.thumbnail_url,
        v.views AS total_views,
        COUNT(vv.id) AS views_7d

    FROM videos v

    LEFT JOIN video_views vv
        ON vv.video_id = v.id
       AND vv.view_date >= CURRENT_DATE() - INTERVAL 6 DAY

    WHERE v.status = 'published'

    GROUP BY
        v.id,
        v.video_key,
        v.title,
        v.thumbnail_url,
        v.views

    ORDER BY
        views_7d DESC,
        v.views DESC,
        v.created_at DESC

    LIMIT 10
")->fetchAll();

/*
|--------------------------------------------------------------------------
| TOP VIDEO 30 DAYS
|--------------------------------------------------------------------------
*/

$top30 = $pdo->query("
    SELECT
        v.video_key,
        v.title,
        COUNT(vv.id) AS views_30d

    FROM videos v

    LEFT JOIN video_views vv
        ON vv.video_id = v.id
       AND vv.view_date >= CURRENT_DATE() - INTERVAL 29 DAY

    WHERE v.status = 'published'

    GROUP BY
        v.id,
        v.video_key,
        v.title

    ORDER BY
        views_30d DESC,
        v.created_at DESC

    LIMIT 5
")->fetchAll();

/*
|--------------------------------------------------------------------------
| RECENT UNIQUE VIEWS
|--------------------------------------------------------------------------
*/

$recent = $pdo->query("
    SELECT
        vv.created_at,
        vv.view_date,
        v.video_key,
        v.title

    FROM video_views vv

    INNER JOIN videos v
        ON v.id = vv.video_id

    ORDER BY vv.id DESC

    LIMIT 20
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>Analytics - AsupanLendir Admin</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #0b0b0b;
    color: #fff;
    font-family: Arial, Helvetica, sans-serif;
}

.container {
    width: min(1200px, calc(100% - 24px));
    margin: auto;
}

header {
    border-bottom: 1px solid #252525;
    padding: 17px 0;
    position: sticky;
    top: 0;
    background: rgba(11, 11, 11, .96);
    backdrop-filter: blur(10px);
    z-index: 20;
}

.top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.brand {
    font-size: 21px;
    font-weight: 700;
}

.actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

.btn {
    display: inline-block;
    border-radius: 8px;
    padding: 10px 13px;
    background: #252525;
    color: #fff;
    text-decoration: none;
    font-size: 13px;
}

main {
    padding: 24px 0 55px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 13px;
    margin-bottom: 22px;
}

.stat,
.card {
    background: #171717;
    border: 1px solid #292929;
    border-radius: 14px;
}

.stat {
    padding: 18px;
}

.stat-label {
    color: #888;
    font-size: 13px;
}

.stat-value {
    margin-top: 8px;
    font-size: 29px;
    font-weight: 700;
}

.stat-sub {
    margin-top: 6px;
    color: #707070;
    font-size: 12px;
}

.card {
    padding: 18px;
    margin-bottom: 22px;
}

.card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
}

.card-head h2 {
    margin: 0;
    font-size: 19px;
}

.card-head span {
    color: #777;
    font-size: 12px;
}

.chart {
    height: 260px;
    display: flex;
    align-items: stretch;
    gap: 7px;
    padding-top: 15px;
}

.bar-item {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    align-items: center;
}

.bar-value {
    min-height: 18px;
    color: #aaa;
    font-size: 10px;
    margin-bottom: 5px;
}

.bar-track {
    width: min(26px, 72%);
    height: 190px;
    background: #202020;
    border-radius: 7px 7px 3px 3px;
    display: flex;
    align-items: flex-end;
    overflow: hidden;
}

.bar {
    width: 100%;
    min-height: 2px;
    background: #fff;
    border-radius: 7px 7px 0 0;
}

.bar-label {
    color: #707070;
    font-size: 9px;
    margin-top: 8px;
    white-space: nowrap;
    transform: rotate(-35deg);
    transform-origin: center;
}

.two-col {
    display: grid;
    grid-template-columns: 1.5fr 1fr;
    gap: 22px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    text-align: left;
    padding: 12px 9px;
    border-bottom: 1px solid #292929;
    font-size: 13px;
    vertical-align: middle;
}

th {
    color: #7d7d7d;
}

.video {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 230px;
}

.thumb {
    width: 76px;
    aspect-ratio: 16 / 9;
    border-radius: 7px;
    overflow: hidden;
    background: #222;
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #777;
}

.thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.video-title {
    font-weight: 700;
}

.small {
    margin-top: 4px;
    color: #777;
    font-size: 11px;
}

.rank {
    color: #777;
    width: 30px;
}

.number {
    font-variant-numeric: tabular-nums;
}

.top30-item {
    padding: 13px 0;
    border-bottom: 1px solid #292929;
}

.top30-item:last-child {
    border-bottom: 0;
}

.top30-title {
    font-weight: 700;
    line-height: 1.4;
}

.top30-meta {
    margin-top: 5px;
    color: #777;
    font-size: 12px;
}

.recent-list {
    display: grid;
    gap: 0;
}

.recent-item {
    padding: 12px 0;
    border-bottom: 1px solid #292929;
    display: flex;
    justify-content: space-between;
    gap: 12px;
}

.recent-title {
    font-size: 13px;
    font-weight: 700;
}

.recent-time {
    color: #777;
    font-size: 11px;
    white-space: nowrap;
}

.empty {
    color: #777;
    padding: 25px 0;
    text-align: center;
}

@media (max-width: 850px) {

    .stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .two-col {
        grid-template-columns: 1fr;
    }

}

@media (max-width: 600px) {

    .container {
        width: calc(100% - 18px);
    }

    .brand {
        font-size: 18px;
    }

    .stat-value {
        font-size: 24px;
    }

    .chart {
        gap: 3px;
        height: 230px;
    }

    .bar-track {
        height: 160px;
        width: 70%;
    }

    .bar-label {
        font-size: 8px;
    }

}

</style>

</head>

<body>

<header>

<div class="container top">

<div class="brand">
    📊 Analytics
</div>

<div class="actions">

<a class="btn" href="/admin/">
    ← Dashboard
</a>

<a class="btn" href="/" target="_blank">
    🌐 Web
</a>

</div>

</div>

</header>

<main class="container">

<div class="stats">

<div class="stat">

<div class="stat-label">
    👁 Hari Ini
</div>

<div class="stat-value">
    <?= number_format((int) ($summary['today_views'] ?? 0)) ?>
</div>

<div class="stat-sub">
    Unique video views
</div>

</div>


<div class="stat">

<div class="stat-label">
    🔥 7 Hari
</div>

<div class="stat-value">
    <?= number_format((int) ($summary['views_7d'] ?? 0)) ?>
</div>

<div class="stat-sub">
    Unique views minggu ini
</div>

</div>


<div class="stat">

<div class="stat-label">
    📅 30 Hari
</div>

<div class="stat-value">
    <?= number_format((int) ($summary['views_30d'] ?? 0)) ?>
</div>

<div class="stat-sub">
    Unique views 30 hari
</div>

</div>


<div class="stat">

<div class="stat-label">
    🎬 Konten
</div>

<div class="stat-value">
    <?= number_format($totalVideos + $totalAlbums) ?>
</div>

<div class="stat-sub">
    <?= number_format($totalVideos) ?> video
    ·
    <?= number_format($totalAlbums) ?> album
</div>

</div>

</div>


<div class="card">

<div class="card-head">

<h2>
    Traffic 14 Hari Terakhir
</h2>

<span>
    Unique video views
</span>

</div>

<div class="chart">

<?php foreach ($traffic as $day): ?>

<?php
$height = max(
    2,
    (int) round(
        ($day['views'] / $maxTraffic) * 100
    )
);
?>

<div class="bar-item">

<div class="bar-value">
    <?= number_format($day['views']) ?>
</div>

<div class="bar-track">

<div
    class="bar"
    style="height: <?= $height ?>%"
></div>

</div>

<div class="bar-label">
    <?= ae($day['label']) ?>
</div>

</div>

<?php endforeach; ?>

</div>

</div>


<div class="two-col">

<div>

<div class="card">

<div class="card-head">

<h2>
    🏆 Top Video 7 Hari
</h2>

<span>
    Top 10
</span>

</div>

<?php if ($topVideos): ?>

<div class="table-wrap">

<table>

<thead>
<tr>
    <th>#</th>
    <th>Video</th>
    <th>7 Hari</th>
    <th>Total</th>
</tr>
</thead>

<tbody>

<?php foreach ($topVideos as $i => $video): ?>

<tr>

<td class="rank">
    <?= $i + 1 ?>
</td>

<td>

<a
    class="video"
    href="/v/<?= rawurlencode($video['video_key']) ?>"
    target="_blank"
    style="color:#fff;text-decoration:none"
>

<div class="thumb">

<?php if (!empty($video['thumbnail_url'])): ?>

<img
    src="<?= ae($video['thumbnail_url']) ?>"
    loading="lazy"
>

<?php else: ?>

▶

<?php endif; ?>

</div>

<div>

<div class="video-title">
    <?= ae($video['title']) ?>
</div>

<div class="small">
    <?= ae($video['video_key']) ?>
</div>

</div>

</a>

</td>

<td class="number">
    <?= number_format((int) $video['views_7d']) ?>
</td>

<td class="number">
    <?= number_format((int) $video['total_views']) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php else: ?>

<div class="empty">
    Belum ada data view.
</div>

<?php endif; ?>

</div>

</div>


<div>

<div class="card">

<div class="card-head">

<h2>
    📈 Top 30 Hari
</h2>

<span>
    Top 5
</span>

</div>

<?php foreach ($top30 as $i => $video): ?>

<div class="top30-item">

<div class="top30-title">
    <?= ($i + 1) ?>.
    <?= ae($video['title']) ?>
</div>

<div class="top30-meta">
    <?= number_format((int) $video['views_30d']) ?>
    unique views
</div>

</div>

<?php endforeach; ?>

</div>


<div class="card">

<div class="card-head">

<h2>
    ⚡ View Terbaru
</h2>

<span>
    20 terakhir
</span>

</div>

<div class="recent-list">

<?php foreach ($recent as $row): ?>

<div class="recent-item">

<div>

<div class="recent-title">
    <?= ae($row['title']) ?>
</div>

<div class="small">
    <?= ae($row['video_key']) ?>
</div>

</div>

<div class="recent-time">

<?= ae(
    date(
        'd M H:i',
        strtotime($row['created_at'])
    )
) ?>

</div>

</div>

<?php endforeach; ?>

</div>

</div>

</div>

</div>

</main>

</body>
</html>
