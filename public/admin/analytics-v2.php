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
| SUMMARY VIDEO
|--------------------------------------------------------------------------
*/

$videoSummary = $pdo->query("
    SELECT
        COUNT(*) AS all_time,
        SUM(view_date = CURRENT_DATE()) AS today,
        SUM(view_date >= CURRENT_DATE() - INTERVAL 6 DAY) AS d7,
        SUM(view_date >= CURRENT_DATE() - INTERVAL 29 DAY) AS d30
    FROM video_views
")->fetch();

/*
|--------------------------------------------------------------------------
| SUMMARY ALBUM
|--------------------------------------------------------------------------
*/

$albumSummary = $pdo->query("
    SELECT
        COUNT(*) AS all_time,
        SUM(view_date = CURRENT_DATE()) AS today,
        SUM(view_date >= CURRENT_DATE() - INTERVAL 6 DAY) AS d7,
        SUM(view_date >= CURRENT_DATE() - INTERVAL 29 DAY) AS d30
    FROM collection_views
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

$rawVideoTraffic = $pdo->query("
    SELECT
        view_date,
        COUNT(*) AS views
    FROM video_views
    WHERE view_date >= CURRENT_DATE() - INTERVAL 13 DAY
    GROUP BY view_date
    ORDER BY view_date ASC
")->fetchAll();

$rawAlbumTraffic = $pdo->query("
    SELECT
        view_date,
        COUNT(*) AS views
    FROM collection_views
    WHERE view_date >= CURRENT_DATE() - INTERVAL 13 DAY
    GROUP BY view_date
    ORDER BY view_date ASC
")->fetchAll();

$videoMap = [];
$albumMap = [];

foreach ($rawVideoTraffic as $row) {
    $videoMap[$row['view_date']] = (int) $row['views'];
}

foreach ($rawAlbumTraffic as $row) {
    $albumMap[$row['view_date']] = (int) $row['views'];
}

$traffic = [];
$maxTraffic = 1;

for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} day"));
    $videoViews = $videoMap[$date] ?? 0;
    $albumViews = $albumMap[$date] ?? 0;
    $total = $videoViews + $albumViews;

    $traffic[] = [
        'date' => $date,
        'label' => date('d M', strtotime($date)),
        'video' => $videoViews,
        'album' => $albumViews,
        'total' => $total,
    ];

    $maxTraffic = max($maxTraffic, $total);
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
| TOP ALBUM 7 DAYS
|--------------------------------------------------------------------------
*/

$topAlbums = $pdo->query("
    SELECT
        c.id,
        c.collection_key,
        c.title,
        c.views AS total_views,
        COUNT(cv.id) AS views_7d,
        (
            SELECT COUNT(*)
            FROM collection_videos cx
            WHERE cx.collection_id = c.id
        ) AS video_count
    FROM collections c
    LEFT JOIN collection_views cv
        ON cv.collection_id = c.id
       AND cv.view_date >= CURRENT_DATE() - INTERVAL 6 DAY
    WHERE c.status = 'published'
    GROUP BY
        c.id,
        c.collection_key,
        c.title,
        c.views
    ORDER BY
        views_7d DESC,
        c.views DESC,
        c.created_at DESC
    LIMIT 10
")->fetchAll();

/*
|--------------------------------------------------------------------------
| RECENT VIEWS
|--------------------------------------------------------------------------
*/

$recentVideoViews = $pdo->query("
    SELECT
        vv.created_at,
        v.video_key,
        v.title
    FROM video_views vv
    INNER JOIN videos v
        ON v.id = vv.video_id
    ORDER BY vv.id DESC
    LIMIT 10
")->fetchAll();

$recentAlbumViews = $pdo->query("
    SELECT
        cv.created_at,
        c.collection_key,
        c.title
    FROM collection_views cv
    INNER JOIN collections c
        ON c.id = cv.collection_id
    ORDER BY cv.id DESC
    LIMIT 10
")->fetchAll();

$todayCombined =
    (int) ($videoSummary['today'] ?? 0) +
    (int) ($albumSummary['today'] ?? 0);

$weekCombined =
    (int) ($videoSummary['d7'] ?? 0) +
    (int) ($albumSummary['d7'] ?? 0);

$monthCombined =
    (int) ($videoSummary['d30'] ?? 0) +
    (int) ($albumSummary['d30'] ?? 0);

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Analytics V2 - AsupanLendir Admin</title>

<style>
*{box-sizing:border-box}

body{
    margin:0;
    background:#0b0b0b;
    color:#fff;
    font-family:Arial,Helvetica,sans-serif;
}

.container{
    width:min(1200px,calc(100% - 24px));
    margin:auto;
}

header{
    border-bottom:1px solid #252525;
    padding:17px 0;
    position:sticky;
    top:0;
    background:rgba(11,11,11,.96);
    backdrop-filter:blur(10px);
    z-index:20;
}

.top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
}

.brand{
    font-size:21px;
    font-weight:700;
}

.actions{
    display:flex;
    gap:7px;
    flex-wrap:wrap;
}

.btn{
    display:inline-block;
    border-radius:8px;
    padding:10px 13px;
    background:#252525;
    color:#fff;
    text-decoration:none;
    font-size:13px;
}

main{
    padding:24px 0 55px;
}

.stats{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:13px;
    margin-bottom:22px;
}

.stat,.card{
    background:#171717;
    border:1px solid #292929;
    border-radius:14px;
}

.stat{
    padding:18px;
}

.stat-label{
    color:#888;
    font-size:13px;
}

.stat-value{
    margin-top:8px;
    font-size:29px;
    font-weight:700;
}

.stat-sub{
    margin-top:7px;
    color:#707070;
    font-size:12px;
    line-height:1.5;
}

.card{
    padding:18px;
    margin-bottom:22px;
}

.card-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:18px;
}

.card-head h2{
    margin:0;
    font-size:19px;
}

.card-head span{
    color:#777;
    font-size:12px;
}

.chart{
    height:270px;
    display:flex;
    align-items:stretch;
    gap:7px;
    padding-top:15px;
}

.day{
    flex:1;
    min-width:0;
    display:flex;
    flex-direction:column;
    justify-content:flex-end;
    align-items:center;
}

.day-value{
    min-height:18px;
    color:#aaa;
    font-size:10px;
    margin-bottom:5px;
}

.track{
    width:min(28px,76%);
    height:190px;
    background:#202020;
    border-radius:7px 7px 3px 3px;
    display:flex;
    flex-direction:column;
    justify-content:flex-end;
    overflow:hidden;
}

.video-bar,
.album-bar{
    width:100%;
    min-height:0;
}

.video-bar{
    background:#fff;
}

.album-bar{
    background:#777;
}

.day-label{
    color:#707070;
    font-size:9px;
    margin-top:8px;
    white-space:nowrap;
    transform:rotate(-35deg);
}

.legend{
    display:flex;
    gap:16px;
    margin-top:18px;
    color:#777;
    font-size:12px;
}

.legend span::before{
    content:"";
    display:inline-block;
    width:10px;
    height:10px;
    border-radius:2px;
    margin-right:6px;
    vertical-align:-1px;
    background:#fff;
}

.legend .album::before{
    background:#777;
}

.two-col{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:22px;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
}

th,td{
    text-align:left;
    padding:12px 9px;
    border-bottom:1px solid #292929;
    font-size:13px;
    vertical-align:middle;
}

th{
    color:#7d7d7d;
}

.item-title{
    font-weight:700;
}

.small{
    margin-top:4px;
    color:#777;
    font-size:11px;
}

.rank{
    color:#777;
    width:30px;
}

.number{
    font-variant-numeric:tabular-nums;
}

.recent-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:22px;
}

.recent-item{
    display:flex;
    justify-content:space-between;
    gap:12px;
    padding:11px 0;
    border-bottom:1px solid #292929;
}

.recent-title{
    font-size:13px;
    font-weight:700;
}

.recent-time{
    color:#777;
    font-size:11px;
    white-space:nowrap;
}

.empty{
    color:#777;
    padding:25px 0;
    text-align:center;
}

@media(max-width:850px){
    .stats{
        grid-template-columns:repeat(2,1fr);
    }

    .two-col,
    .recent-grid{
        grid-template-columns:1fr;
    }
}

@media(max-width:600px){
    .container{
        width:calc(100% - 18px);
    }

    .brand{
        font-size:18px;
    }

    .stats{
        grid-template-columns:repeat(2,1fr);
    }

    .stat-value{
        font-size:24px;
    }

    .chart{
        gap:3px;
        height:235px;
    }

    .track{
        height:160px;
        width:72%;
    }

    .day-label{
        font-size:8px;
    }
}
</style>
</head>

<body>

<header>
<div class="container top">

<div class="brand">
    📊 Analytics V2
</div>

<div class="actions">
    <a class="btn" href="/admin/">← Dashboard</a>
    <a class="btn" href="/" target="_blank">🌐 Web</a>
</div>

</div>
</header>

<main class="container">

<div class="stats">

<div class="stat">
    <div class="stat-label">👁 Hari Ini</div>
    <div class="stat-value"><?= number_format($todayCombined) ?></div>
    <div class="stat-sub">
        🎬 <?= number_format((int) ($videoSummary['today'] ?? 0)) ?> video
        ·
        📁 <?= number_format((int) ($albumSummary['today'] ?? 0)) ?> album
    </div>
</div>

<div class="stat">
    <div class="stat-label">🔥 7 Hari</div>
    <div class="stat-value"><?= number_format($weekCombined) ?></div>
    <div class="stat-sub">
        🎬 <?= number_format((int) ($videoSummary['d7'] ?? 0)) ?> video
        ·
        📁 <?= number_format((int) ($albumSummary['d7'] ?? 0)) ?> album
    </div>
</div>

<div class="stat">
    <div class="stat-label">📅 30 Hari</div>
    <div class="stat-value"><?= number_format($monthCombined) ?></div>
    <div class="stat-sub">
        🎬 <?= number_format((int) ($videoSummary['d30'] ?? 0)) ?> video
        ·
        📁 <?= number_format((int) ($albumSummary['d30'] ?? 0)) ?> album
    </div>
</div>

<div class="stat">
    <div class="stat-label">🎬 Video</div>
    <div class="stat-value"><?= number_format($totalVideos) ?></div>
    <div class="stat-sub">
        <?= number_format((int) ($videoSummary['all_time'] ?? 0)) ?> unique records
    </div>
</div>

<div class="stat">
    <div class="stat-label">📁 Album</div>
    <div class="stat-value"><?= number_format($totalAlbums) ?></div>
    <div class="stat-sub">
        <?= number_format((int) ($albumSummary['all_time'] ?? 0)) ?> unique records
    </div>
</div>

<div class="stat">
    <div class="stat-label">📊 Unique Records</div>
    <div class="stat-value">
        <?= number_format(
            (int) ($videoSummary['all_time'] ?? 0) +
            (int) ($albumSummary['all_time'] ?? 0)
        ) ?>
    </div>
    <div class="stat-sub">
        Sejak tracker V2 aktif
    </div>
</div>

</div>


<div class="card">

<div class="card-head">
    <h2>Traffic 14 Hari</h2>
    <span>Video + Album unique views</span>
</div>

<div class="chart">

<?php foreach ($traffic as $day): ?>

<?php
$totalHeight = max(
    2,
    (int) round(($day['total'] / $maxTraffic) * 100)
);

$videoPart = $day['total'] > 0
    ? ($day['video'] / $day['total']) * $totalHeight
    : 0;

$albumPart = $day['total'] > 0
    ? ($day['album'] / $day['total']) * $totalHeight
    : 0;
?>

<div class="day">

<div class="day-value">
    <?= number_format($day['total']) ?>
</div>

<div class="track">

<div
    class="album-bar"
    style="height: <?= max(0, $albumPart) ?>%"
></div>

<div
    class="video-bar"
    style="height: <?= max(2, $videoPart) ?>%"
></div>

</div>

<div class="day-label">
    <?= ae($day['label']) ?>
</div>

</div>

<?php endforeach; ?>

</div>

<div class="legend">
    <span>Video</span>
    <span class="album">Album</span>
</div>

</div>


<div class="two-col">

<div class="card">

<div class="card-head">
    <h2>🏆 Top Video 7 Hari</h2>
    <span>Top 10</span>
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

<td class="rank"><?= $i + 1 ?></td>

<td>
    <a
        href="/v/<?= rawurlencode($video['video_key']) ?>"
        target="_blank"
        style="color:#fff;text-decoration:none"
    >
        <div class="item-title">
            <?= ae($video['title']) ?>
        </div>
        <div class="small">
            <?= ae($video['video_key']) ?>
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

<div class="empty">Belum ada view video.</div>

<?php endif; ?>

</div>


<div class="card">

<div class="card-head">
    <h2>📁 Top Album 7 Hari</h2>
    <span>Top 10</span>
</div>

<?php if ($topAlbums): ?>

<div class="table-wrap">

<table>
<thead>
<tr>
    <th>#</th>
    <th>Album</th>
    <th>7 Hari</th>
    <th>Total</th>
</tr>
</thead>

<tbody>

<?php foreach ($topAlbums as $i => $album): ?>

<tr>

<td class="rank"><?= $i + 1 ?></td>

<td>
    <a
        href="/c/<?= rawurlencode($album['collection_key']) ?>"
        target="_blank"
        style="color:#fff;text-decoration:none"
    >
        <div class="item-title">
            <?= ae($album['title']) ?>
        </div>
        <div class="small">
            <?= number_format((int) $album['video_count']) ?> video
        </div>
    </a>
</td>

<td class="number">
    <?= number_format((int) $album['views_7d']) ?>
</td>

<td class="number">
    <?= number_format((int) $album['total_views']) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>
</table>

</div>

<?php else: ?>

<div class="empty">Belum ada view album.</div>

<?php endif; ?>

</div>

</div>


<div class="recent-grid">

<div class="card">

<div class="card-head">
    <h2>⚡ View Video Terbaru</h2>
    <span>10 terakhir</span>
</div>

<?php foreach ($recentVideoViews as $row): ?>

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
    <?= ae(date('d M H:i', strtotime($row['created_at']))) ?>
</div>

</div>

<?php endforeach; ?>

</div>


<div class="card">

<div class="card-head">
    <h2>📁 View Album Terbaru</h2>
    <span>10 terakhir</span>
</div>

<?php foreach ($recentAlbumViews as $row): ?>

<div class="recent-item">

<div>
    <div class="recent-title">
        <?= ae($row['title']) ?>
    </div>
    <div class="small">
        <?= ae($row['collection_key']) ?>
    </div>
</div>

<div class="recent-time">
    <?= ae(date('d M H:i', strtotime($row['created_at']))) ?>
</div>

</div>

<?php endforeach; ?>

</div>

</div>

</main>

</body>
</html>
