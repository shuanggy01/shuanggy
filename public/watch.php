<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/database.php';

require dirname(__DIR__) . '/app/video_mirrors.php';

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

$videoKey = trim((string) ($_GET['id'] ?? ''));

if (
    $videoKey === '' ||
    !preg_match('/^[A-Za-z0-9_-]{4,20}$/', $videoKey)
) {
    http_response_code(404);
    exit('Video tidak ditemukan.');
}

/*
|--------------------------------------------------------------------------
| VIDEO
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM videos
    WHERE video_key = ?
      AND status = 'published'
    LIMIT 1
");

$stmt->execute([$videoKey]);

$video = $stmt->fetch();

if (!$video) {
    http_response_code(404);
    exit('Video tidak ditemukan.');
}

$mirrorSources = vm_ready_sources(
    $pdo,
    (int) $video['id']
);

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
        INSERT IGNORE INTO video_views
        (
            video_id,
            viewer_hash,
            view_date
        )
        VALUES (?, ?, CURRENT_DATE())
    ");

    $viewStmt->execute([
        (int) $video['id'],
        $viewerHash
    ]);

    if ($viewStmt->rowCount() === 1) {

        $update = $pdo->prepare("
            UPDATE videos
            SET views = views + 1
            WHERE id = ?
        ");

        $update->execute([
            (int) $video['id']
        ]);

        $video['views'] =
            (int) $video['views'] + 1;
    }
}

/*
|--------------------------------------------------------------------------
| ALBUM
|--------------------------------------------------------------------------
*/

$albumStmt = $pdo->prepare("
    SELECT
        c.id,
        c.collection_key,
        c.title
    FROM collections c
    INNER JOIN collection_videos cv
        ON cv.collection_id = c.id
    WHERE cv.video_id = ?
      AND c.status = 'published'
    ORDER BY c.id DESC
    LIMIT 1
");

$albumStmt->execute([
    (int) $video['id']
]);

$album = $albumStmt->fetch();

/*
|--------------------------------------------------------------------------
| PREVIOUS / NEXT
|--------------------------------------------------------------------------
*/

$previousVideo = null;
$nextVideo = null;

if ($album) {

    $navStmt = $pdo->prepare("
        SELECT
            v.id,
            v.video_key,
            v.title,
            cv.position
        FROM collection_videos cv
        INNER JOIN videos v
            ON v.id = cv.video_id
        WHERE cv.collection_id = ?
          AND v.status = 'published'
        ORDER BY
            cv.position ASC,
            v.id ASC
    ");

    $navStmt->execute([
        (int) $album['id']
    ]);

    $albumVideos = $navStmt->fetchAll();

    foreach ($albumVideos as $index => $row) {

        if ((int) $row['id'] !== (int) $video['id']) {
            continue;
        }

        if ($index > 0) {
            $previousVideo =
                $albumVideos[$index - 1];
        }

        if ($index < count($albumVideos) - 1) {
            $nextVideo =
                $albumVideos[$index + 1];
        }

        break;
    }

} else {

    $prevStmt = $pdo->prepare("
        SELECT
            video_key,
            title
        FROM videos
        WHERE status = 'published'
          AND id < ?
        ORDER BY id DESC
        LIMIT 1
    ");

    $prevStmt->execute([
        (int) $video['id']
    ]);

    $previousVideo =
        $prevStmt->fetch() ?: null;

    $nextStmt = $pdo->prepare("
        SELECT
            video_key,
            title
        FROM videos
        WHERE status = 'published'
          AND id > ?
        ORDER BY id ASC
        LIMIT 1
    ");

    $nextStmt->execute([
        (int) $video['id']
    ]);

    $nextVideo =
        $nextStmt->fetch() ?: null;
}

/*
|--------------------------------------------------------------------------
| RELATED VIDEOS
|--------------------------------------------------------------------------
*/

if ($album) {

    $relatedStmt = $pdo->prepare("
        SELECT
            v.video_key,
            v.title,
            v.thumbnail_url,
            v.views
        FROM collection_videos cv
        INNER JOIN videos v
            ON v.id = cv.video_id
        WHERE cv.collection_id = ?
          AND v.id != ?
          AND v.status = 'published'
        ORDER BY
            cv.position ASC,
            v.id ASC
        LIMIT 8
    ");

    $relatedStmt->execute([
        (int) $album['id'],
        (int) $video['id']
    ]);

} else {

    $relatedStmt = $pdo->prepare("
        SELECT
            video_key,
            title,
            thumbnail_url,
            views
        FROM videos
        WHERE id != ?
          AND status = 'published'
        ORDER BY created_at DESC
        LIMIT 8
    ");

    $relatedStmt->execute([
        (int) $video['id']
    ]);
}

$relatedVideos =
    $relatedStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| SEO / SHARE
|--------------------------------------------------------------------------
*/

$appUrl = site_base_url();

$currentUrl =
    $appUrl .
    '/v/' .
    rawurlencode($video['video_key']);

$description =
    trim(
        (string) (
            $video['description']
            ?? ''
        )
    );

if ($description === '') {
    $description =
        'Tonton ' .
        $video['title'] .
        ' di ' . site_name() . '.';
}

$customSeoTitle =
    trim(
        (string) (
            $video['seo_title']
            ?? ''
        )
    );

$pageTitle =
    $customSeoTitle !== ''
        ? $customSeoTitle
        : $video['title'] . ' - ' . site_name();

$customSeoDescription =
    trim(
        (string) (
            $video['seo_description']
            ?? ''
        )
    );

$seoDescription =
    $customSeoDescription !== ''
        ? $customSeoDescription
        : mb_substr(
            preg_replace('/\s+/', ' ', $description),
            0,
            180
        );

$thumbnail =
    trim(
        (string) (
            $video['thumbnail_url']
            ?? ''
        )
    );

$customSeoImage =
    trim(
        (string) (
            $video['seo_image_url']
            ?? ''
        )
    );

$shareImage =
    $customSeoImage !== ''
        ? $customSeoImage
        : $thumbnail;

$seoNoindex =
    (int) (
        $video['seo_noindex']
        ?? 0
    ) === 1;

$videoUrl =
    '/vp/'
    . rawurlencode(
        (string) $video['video_key']
    );

$videoPath =
    strtolower(
        (string) parse_url(
            $videoUrl,
            PHP_URL_PATH
        )
    );

$videoType =
    str_contains($videoPath, '.m3u8')
        ? 'application/vnd.apple.mpegurl'
        : 'video/mp4';

$shareText =
    $video['title'] .
    "\n" .
    $currentUrl;

$whatsappUrl =
    'https://wa.me/?text=' .
    rawurlencode($shareText);

$telegramUrl =
    'https://t.me/share/url?' .
    http_build_query([
        'url'  => $currentUrl,
        'text' => $video['title']
    ]);

$uploadedAt = !empty($video['created_at'])
    ? date('d M Y', strtotime($video['created_at']))
    : '-';

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
    content="video.other"
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

<meta
    property="og:video"
    content="<?= e($videoUrl) ?>"
>

<meta
    property="og:video:type"
    content="<?= e($videoType) ?>"
>

<link rel="stylesheet" href="/assets/theme-pejuang-lendir.css?v=1">
<link rel="stylesheet" href="/assets/branding-v2.css?v=2">
<link rel="manifest" href="/site.webmanifest">

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="192x192" href="/favicon-192x192.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<meta name="theme-color" content="#0F1115">

<style>

:root {
    --bg: #0F1115;
    --panel: #1A1F26;
    --panel2: #15191F;
    --line: #2A3038;
    --primary: #FFC107;
    --primary-dark: #F2B705;
    --text: #FFFFFF;
    --muted: #A7ADB3;
    --soft: #D5D9DE;
}

* {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {
    margin: 0;
    background:
        radial-gradient(
            circle at 50% -15%,
            rgba(255,193,7,.06),
            transparent 30%
        ),
        var(--bg);
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
    z-index: 50;
    background: rgba(15,17,21,.96);
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
    font-size: 22px;
    font-weight: 800;
}

.brand-mark {
    width: 35px;
    height: 35px;
    border-radius: 50%;
}

.home-link {
    text-decoration: none;
    color: var(--muted);
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 9px 12px;
    font-size: 12px;
}

/* MAIN */

main {
    padding: 22px 0 55px;
}

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 15px;
}

.breadcrumb {
    display: flex;
    gap: 7px;
    align-items: center;
    flex-wrap: wrap;
    font-size: 12px;
    color: var(--muted);
}

.breadcrumb a {
    color: var(--muted);
    text-decoration: none;
}

.breadcrumb a:hover {
    color: var(--primary);
}

.detail-label {
    color: var(--muted);
    font-size: 12px;
}

/* CUSTOM PLAYER */

.player-card {
    width: min(960px, 100%);
    margin-left: auto;
    margin-right: auto;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 12px;
    box-shadow:
        0 18px 40px rgba(0,0,0,.28),
        0 0 0 1px rgba(255,193,7,.025);
}

.player-shell {
    position: relative;
    overflow: hidden;
    border-radius: 12px;
    background: #000;
    aspect-ratio: 16 / 9;
    border: 1px solid rgba(255,193,7,.12);
}

.player-shell video {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
    background: #000;
}

/* subtle player gradient */
.player-shell::after {
    content: "";
    position: absolute;
    inset: auto 0 0 0;
    height: 42%;
    pointer-events: none;
    background:
        linear-gradient(
            to top,
            rgba(0,0,0,.72),
            transparent
        );
    opacity: 1;
    transition: opacity .25s ease;
}

.player-shell.controls-hidden::after {
    opacity: 0;
}

.big-play {
    position: absolute;
    z-index: 4;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    width: 70px;
    height: 70px;
    border-radius: 50%;
    border: 0;
    background: var(--primary);
    color: #101216;
    display: grid;
    place-items: center;
    font-size: 27px;
    cursor: pointer;
    box-shadow:
        0 10px 35px rgba(0,0,0,.30),
        0 0 24px rgba(255,193,7,.30);
    transition:
        transform .16s ease,
        opacity .16s ease;
}

.big-play:hover {
    transform:
        translate(-50%, -50%)
        scale(1.05);
}

.big-play.hidden {
    opacity: 0;
    pointer-events: none;
}

.controls {
    position: absolute;
    z-index: 5;
    left: 12px;
    right: 12px;
    bottom: 10px;
    display: grid;
    grid-template-columns:
        auto
        minmax(0, 1fr)
        auto;
    gap: 11px;
    align-items: center;
    transition:
        opacity .22s ease,
        transform .22s ease;
}

.player-shell.controls-hidden .controls {
    opacity: 0;
    transform: translateY(8px);
    pointer-events: none;
}

.control-btn {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,.10);
    background: rgba(15,17,21,.70);
    backdrop-filter: blur(8px);
    color: #fff;
    display: grid;
    place-items: center;
    cursor: pointer;
    font-size: 16px;
}

.control-btn:hover {
    color: var(--primary);
    border-color: rgba(255,193,7,.35);
}

.timeline-wrap {
    min-width: 0;
}

.time-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 6px;
    color: #fff;
    font-size: 11px;
    font-variant-numeric: tabular-nums;
}

.seek {
    width: 100%;
    height: 5px;
    appearance: none;
    -webkit-appearance: none;
    background:
        linear-gradient(
            to right,
            var(--primary) 0%,
            var(--primary) var(--seek, 0%),
            rgba(255,255,255,.24) var(--seek, 0%),
            rgba(255,255,255,.24) 100%
        );
    border-radius: 999px;
    outline: 0;
    cursor: pointer;
}

.seek::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 13px;
    height: 13px;
    border-radius: 50%;
    background: var(--primary);
    border: 2px solid #fff;
    box-shadow: 0 2px 7px rgba(0,0,0,.35);
}

.seek::-moz-range-thumb {
    width: 11px;
    height: 11px;
    border-radius: 50%;
    background: var(--primary);
    border: 2px solid #fff;
}

.right-controls {
    display: flex;
    gap: 7px;
}

.player-hint {
    padding: 10px 3px 0;
    color: var(--muted);
    font-size: 11px;
    text-align: right;
}

/* VIDEO INFO */

.video-head {
    display: flex;
    justify-content: space-between;
    gap: 22px;
    align-items: start;
    margin-top: 20px;
}

.video-title-wrap {
    min-width: 0;
}

h1 {
    margin: 0;
    color: #fff;
    font-size: clamp(23px, 4vw, 31px);
    line-height: 1.14;
    letter-spacing: -.55px;
}

.album-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 10px;
    text-decoration: none;
    color: var(--primary);
    font-size: 12px;
    font-weight: 700;
}

.views-badge {
    flex: 0 0 auto;
    padding: 8px 11px;
    border: 1px solid rgba(255,193,7,.24);
    background: rgba(255,193,7,.07);
    color: var(--primary);
    border-radius: 9px;
    font-size: 12px;
    font-weight: 700;
}

/* ACTIONS */

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 17px;
}

.btn {
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 10px 13px;
    background: var(--panel);
    color: #eee;
    text-decoration: none;
    cursor: pointer;
    font-size: 13px;
}

.btn:hover {
    color: var(--primary);
    border-color: rgba(255,193,7,.30);
}

.btn.primary {
    background: var(--primary);
    border-color: var(--primary);
    color: #111318;
    font-weight: 800;
}

.btn.primary:hover {
    background: var(--primary-dark);
    color: #111318;
}

/* INFO GRID */

.info-card {
    margin-top: 17px;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 13px;
    overflow: hidden;
}

.info-card-title {
    padding: 13px 15px;
    border-bottom: 1px solid var(--line);
    font-size: 13px;
    font-weight: 800;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
}

.info-item {
    min-width: 0;
    padding: 14px 15px;
    border-right: 1px solid var(--line);
}

.info-item:last-child {
    border-right: 0;
}

.info-label {
    color: var(--muted);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .5px;
}

.info-value {
    margin-top: 6px;
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.info-value a {
    color: var(--primary);
    text-decoration: none;
}

/* DESCRIPTION */

.description {
    margin-top: 17px;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 13px;
    padding: 15px 16px;
    line-height: 1.62;
    color: #D8DCE0;
    font-size: 14px;
}

/* PREV NEXT */

.nav-videos {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-top: 22px;
}

.nav-card {
    min-width: 0;
    text-decoration: none;
    padding: 14px;
    border: 1px solid var(--line);
    background: var(--panel);
    border-radius: 12px;
}

.nav-card:hover {
    border-color: rgba(255,193,7,.24);
}

.nav-label {
    color: var(--muted);
    font-size: 11px;
    margin-bottom: 6px;
}

.nav-title {
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.nav-card.next {
    text-align: right;
}

/* RELATED */

.related {
    margin-top: 36px;
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
    color: #fff;
    font-size: 20px;
}

.section-head a,
.section-head span {
    color: var(--muted);
    font-size: 12px;
    text-decoration: none;
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
    border: 1px solid rgba(255,255,255,.035);
}

.thumb img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: cover;
    transition: transform .18s ease;
}

.card:hover .thumb img {
    transform: scale(1.025);
}

.card:hover .thumb {
    border-color: rgba(255,193,7,.22);
}

.placeholder {
    height: 100%;
    display: grid;
    place-items: center;
    color: #6d6d6d;
    font-size: 26px;
}

.play-badge {
    position: absolute;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    color: #111318;
    background: var(--primary);
    opacity: 0;
    transition: opacity .16s ease;
}

.card:hover .play-badge {
    opacity: 1;
}

.related-title {
    margin-top: 8px;
    color: #efefef;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.related-meta {
    margin-top: 5px;
    color: var(--muted);
    font-size: 11px;
}

/* FOOTER */

footer {
    border-top: 1px solid var(--line);
    text-align: center;
    color: #6B727A;
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
    background: var(--primary);
    color: #111318;
    padding: 10px 14px;
    border-radius: 9px;
    font-size: 12px;
    font-weight: 800;
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

/* MOBILE */

@media (max-width: 820px) {

    .grid {
        grid-template-columns: repeat(3, 1fr);
    }

}

@media (max-width: 620px) {

    .container {
        width: calc(100% - 20px);
    }

    main {
        padding-top: 17px;
    }

    .player-card {
        padding: 8px;
        border-radius: 13px;
    }

    .player-shell {
        border-radius: 10px;
    }

    .big-play {
        width: 59px;
        height: 59px;
        font-size: 23px;
    }

    .controls {
        left: 8px;
        right: 8px;
        bottom: 7px;
        gap: 7px;
    }

    .control-btn {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        font-size: 14px;
    }

    .right-controls .mute-btn {
        display: none;
    }

    .time-row {
        font-size: 9px;
    }

    .player-hint {
        display: none;
    }

    .video-head {
        flex-direction: column;
        gap: 10px;
    }

    .views-badge {
        align-self: flex-start;
    }

    .actions {
        gap: 7px;
    }

    .btn {
        padding: 10px 12px;
        font-size: 12px;
    }

    .info-grid {
        grid-template-columns: 1fr;
    }

    .info-item {
        border-right: 0;
        border-bottom: 1px solid var(--line);
    }

    .info-item:last-child {
        border-bottom: 0;
    }

    .nav-videos {
        grid-template-columns: 1fr;
    }

    .nav-card.next {
        text-align: left;
    }

    .grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px 11px;
    }

    .related-title {
        font-size: 12px;
    }

    .play-badge {
        display: none;
    }

}

</style>

<link rel="stylesheet" href="/assets/ads-v1.css?v=2">

<?= site_head_common() ?>

<link rel="stylesheet" href="/assets/mirrors-v1.css?v=1">


<style>
/* Player Actions + Above Ad V1 */
.btn.download-btn{
    color:#17120A;
    background:linear-gradient(135deg,#F5C75B,#EAB84D);
    border-color:#EAB84D;
    font-weight:800;
    box-shadow:0 8px 22px rgba(234,184,77,.13);
}
.btn.download-btn:hover{
    background:linear-gradient(135deg,#FFD66D,#F2C04F);
}
</style>


<?php
if (!function_exists('antiblock_render')) {
    require_once dirname(__DIR__) . '/app/ads.php';
}
echo antiblock_render('video', 'head');
?>
<!-- AL_ANTIBLOCK_GLOBAL_V2_video_head -->

</head>

<body>

<header>

<div class="container header-inner">

<a
    class="logo"
    href="/"
>
    <img
        class="brand-mark"
        src="<?= e(site_logo_url()) ?>"
        alt=""
        width="35"
        height="35"
    >
    <span class="brand-name"><?= e(site_name()) ?></span>
</a>

<a
    class="home-link"
    href="/"
>
    ← Homepage
</a>

</div>

</header>


<main class="container">


<div class="topbar">

<div class="breadcrumb">

<a href="/">
    Home
</a>

<?php if ($album): ?>

<span>›</span>

<a
    href="/c/<?= rawurlencode($album['collection_key']) ?>"
>
    <?= e($album['title']) ?>
</a>

<?php endif; ?>

<span>›</span>

<span>
    Detail Video
</span>

</div>

<div class="detail-label">
    Detail Video
</div>

</div>


<?= ad_render('player_above') ?>

<section class="player-card">

<div
    class="player-shell"
    id="playerShell"
>

<video
    id="videoPlayer"
    controls
    playsinline
    preload="metadata"
    <?php if ($thumbnail !== ''): ?>
    poster="<?= e($thumbnail) ?>"
    <?php endif; ?>
>

<source
    src="<?= e($videoUrl) ?>"
    type="<?= e($videoType) ?>"
>

Browser Anda tidak mendukung pemutar video.

</video>

<?php if ($mirrorSources): ?>
<div
    class="mirror-frame-wrap"
    id="mirrorFrameWrap"
    hidden
>
    <iframe
        id="mirrorFrame"
        src="about:blank"
        title="Server video alternatif"
        allow="autoplay; fullscreen; picture-in-picture"
        allowfullscreen
        referrerpolicy="strict-origin-when-cross-origin"
    ></iframe>
</div>
<?php endif; ?>



<button
    class="big-play"
    id="bigPlay"
    type="button"
    aria-label="Putar video"
>
    ▶
</button>


<div
    class="controls"
    id="customControls"
>

<button
    class="control-btn"
    id="playBtn"
    type="button"
    aria-label="Play atau pause"
>
    ▶
</button>


<div class="timeline-wrap">

<div class="time-row">
    <span id="currentTime">00:00</span>
    <span id="duration">00:00</span>
</div>

<input
    class="seek"
    id="seek"
    type="range"
    min="0"
    max="1000"
    value="0"
    step="1"
    aria-label="Posisi video"
>

</div>


<div class="right-controls">

<button
    class="control-btn mute-btn"
    id="muteBtn"
    type="button"
    aria-label="Mute"
>
    🔊
</button>

<button
    class="control-btn"
    id="fullscreenBtn"
    type="button"
    aria-label="Fullscreen"
>
    ⛶
</button>

</div>

</div>

</div>

<div class="player-hint">
    Ketuk video untuk play / pause
</div>

</section>



<?php if ($mirrorSources): ?>

<div
    class="server-switcher"
    id="serverSwitcher"
>

<div class="server-switcher-head">
    <div class="server-switcher-title">
        Pilih Server
    </div>
    <div class="server-switcher-note">
        Server Utama tetap menjadi default
    </div>
</div>

<div class="server-tabs">

<button
    class="server-tab active"
    type="button"
    data-server-type="direct"
>
    ▶ Utama
</button>

<?php foreach ($mirrorSources as $mirror): ?>

<button
    class="server-tab"
    type="button"
    data-server-type="iframe"
    data-server-url="<?= e($mirror['embed_url']) ?>"
>
    <?= e($mirror['label']) ?>
</button>

<?php endforeach; ?>

</div>

</div>

<?php endif; ?>

<?= ad_render('player_below') ?>

<div class="video-head">

<div class="video-title-wrap">

<h1>
    <?= e($video['title']) ?>
</h1>

<?php if ($album): ?>

<a
    class="album-chip"
    href="/c/<?= rawurlencode($album['collection_key']) ?>"
>
    📁 <?= e($album['title']) ?>
</a>

<?php endif; ?>

</div>

<div class="views-badge">
    👁 <?= number_format((int) $video['views']) ?> views
</div>

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

<a
    class="btn"
    href="/f/<?= rawurlencode($video['video_key']) ?>"
    target="_blank"
    rel="noopener"
>
    Open Video ↗
</a>

<a
    class="btn download-btn"
    href="/dl/<?= rawurlencode($video['video_key']) ?>"
>
    ⬇ Download
</a>

</div>


<div class="info-card">

<div class="info-card-title">
    Informasi Video
</div>

<div class="info-grid">

<div class="info-item">
    <div class="info-label">
        Views
    </div>
    <div class="info-value">
        <?= number_format((int) $video['views']) ?>
    </div>
</div>

<div class="info-item">
    <div class="info-label">
        Tanggal Upload
    </div>
    <div class="info-value">
        <?= e($uploadedAt) ?>
    </div>
</div>

<div class="info-item">
    <div class="info-label">
        Link Video
    </div>
    <div class="info-value">
        <a href="<?= e($currentUrl) ?>">
            /v/<?= e($video['video_key']) ?>
        </a>
    </div>
</div>

</div>

</div>


<?php if (!empty($video['description'])): ?>

<div class="description">
    <?= nl2br(e($video['description'])) ?>
</div>

<?php endif; ?>


<?php if ($previousVideo || $nextVideo): ?>

<div class="nav-videos">

<?php if ($previousVideo): ?>

<a
    class="nav-card"
    href="/v/<?= rawurlencode($previousVideo['video_key']) ?>"
>

<div class="nav-label">
    ← Sebelumnya
</div>

<div class="nav-title">
    <?= e($previousVideo['title']) ?>
</div>

</a>

<?php else: ?>

<div></div>

<?php endif; ?>


<?php if ($nextVideo): ?>

<a
    class="nav-card next"
    href="/v/<?= rawurlencode($nextVideo['video_key']) ?>"
>

<div class="nav-label">
    Berikutnya →
</div>

<div class="nav-title">
    <?= e($nextVideo['title']) ?>
</div>

</a>

<?php endif; ?>

</div>

<?php endif; ?>


<?= ad_render('player_related') ?>

<?php if ($relatedVideos): ?>

<section class="related">

<div class="section-head">

<h2>

<?php if ($album): ?>
    Video Lain di Album
<?php else: ?>
    Video Lainnya
<?php endif; ?>

</h2>

<?php if ($album): ?>

<a
    href="/c/<?= rawurlencode($album['collection_key']) ?>"
>
    Lihat album →
</a>

<?php else: ?>

<span>
    Rekomendasi terbaru
</span>

<?php endif; ?>

</div>


<div class="grid">

<?php foreach ($relatedVideos as $related): ?>

<a
    class="card"
    href="/v/<?= rawurlencode($related['video_key']) ?>"
>

<div class="thumb">

<?php if (!empty($related['thumbnail_url'])): ?>

<img
    src="<?= e($related['thumbnail_url']) ?>"
    alt="<?= e($related['title']) ?>"
    loading="lazy"
>

<?php else: ?>

<div class="placeholder">
    ▶
</div>

<?php endif; ?>

<div class="play-badge">
    ▶
</div>

</div>

<div class="related-title">
    <?= e($related['title']) ?>
</div>

<div class="related-meta">
    <?= number_format((int) $related['views']) ?> views
</div>

</a>

<?php endforeach; ?>

</div>

</section>

<?php endif; ?>


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
    $video['title'],
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE
) ?>;


/*
|--------------------------------------------------------------------------
| SHARE
|--------------------------------------------------------------------------
*/

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


/*
|--------------------------------------------------------------------------
| CUSTOM PLAYER
|--------------------------------------------------------------------------
*/

(() => {

    const video =
        document.getElementById('videoPlayer');

    const shell =
        document.getElementById('playerShell');

    const bigPlay =
        document.getElementById('bigPlay');

    const playBtn =
        document.getElementById('playBtn');

    const muteBtn =
        document.getElementById('muteBtn');

    const fullscreenBtn =
        document.getElementById('fullscreenBtn');

    const seek =
        document.getElementById('seek');

    const currentTimeEl =
        document.getElementById('currentTime');

    const durationEl =
        document.getElementById('duration');

    if (!video) {
        return;
    }

    /*
     * Native controls are kept in HTML as fallback.
     * Once JS is active, custom controls take over.
     */
    video.controls = false;

    let hideTimer = null;
    let seeking = false;


    function formatTime(seconds) {

        if (!Number.isFinite(seconds)) {
            return '00:00';
        }

        seconds =
            Math.max(0, Math.floor(seconds));

        const hours =
            Math.floor(seconds / 3600);

        const minutes =
            Math.floor((seconds % 3600) / 60);

        const secs =
            seconds % 60;

        if (hours > 0) {
            return (
                String(hours).padStart(2, '0') +
                ':' +
                String(minutes).padStart(2, '0') +
                ':' +
                String(secs).padStart(2, '0')
            );
        }

        return (
            String(minutes).padStart(2, '0') +
            ':' +
            String(secs).padStart(2, '0')
        );
    }


    function updatePlayUI() {

        const paused =
            video.paused || video.ended;

        playBtn.textContent =
            paused ? '▶' : '❚❚';

        bigPlay.classList.toggle(
            'hidden',
            !paused
        );

        playBtn.setAttribute(
            'aria-label',
            paused ? 'Putar video' : 'Jeda video'
        );
    }


    function updateMuteUI() {

        muteBtn.textContent =
            video.muted || video.volume === 0
                ? '🔇'
                : '🔊';
    }


    function updateTimeline() {

        if (!Number.isFinite(video.duration)) {
            return;
        }

        durationEl.textContent =
            formatTime(video.duration);

        currentTimeEl.textContent =
            formatTime(video.currentTime);

        if (!seeking && video.duration > 0) {

            const progress =
                video.currentTime /
                video.duration;

            const value =
                Math.round(progress * 1000);

            seek.value = value;

            seek.style.setProperty(
                '--seek',
                (progress * 100) + '%'
            );
        }
    }


    async function togglePlay() {

        if (video.paused || video.ended) {

            try {
                await video.play();
            } catch (e) {}

        } else {

            video.pause();
        }
    }


    function showControls() {

        shell.classList.remove(
            'controls-hidden'
        );

        clearTimeout(hideTimer);

        if (!video.paused) {

            hideTimer =
                setTimeout(() => {

                    shell.classList.add(
                        'controls-hidden'
                    );

                }, 2600);
        }
    }


    async function toggleFullscreen() {

        try {

            if (!document.fullscreenElement) {

                if (shell.requestFullscreen) {
                    await shell.requestFullscreen();
                    return;
                }

                /*
                 * iOS Safari video fullscreen fallback.
                 */
                if (video.webkitEnterFullscreen) {
                    video.webkitEnterFullscreen();
                }

            } else {

                await document.exitFullscreen();
            }

        } catch (e) {}
    }


    bigPlay.addEventListener(
        'click',
        (event) => {
            event.stopPropagation();
            togglePlay();
        }
    );


    playBtn.addEventListener(
        'click',
        (event) => {
            event.stopPropagation();
            togglePlay();
        }
    );


    muteBtn.addEventListener(
        'click',
        (event) => {

            event.stopPropagation();

            video.muted =
                !video.muted;

            updateMuteUI();
        }
    );


    fullscreenBtn.addEventListener(
        'click',
        (event) => {

            event.stopPropagation();

            toggleFullscreen();
        }
    );


    seek.addEventListener(
        'input',
        () => {

            seeking = true;

            const progress =
                Number(seek.value) / 1000;

            seek.style.setProperty(
                '--seek',
                (progress * 100) + '%'
            );

            if (
                Number.isFinite(video.duration)
            ) {
                currentTimeEl.textContent =
                    formatTime(
                        video.duration *
                        progress
                    );
            }
        }
    );


    seek.addEventListener(
        'change',
        () => {

            if (
                Number.isFinite(video.duration)
            ) {

                video.currentTime =
                    video.duration *
                    (
                        Number(seek.value) /
                        1000
                    );
            }

            seeking = false;
            updateTimeline();
        }
    );


    /*
     * Clicking the video itself toggles play / pause.
     */
    video.addEventListener(
        'click',
        () => {
            togglePlay();
        }
    );


    shell.addEventListener(
        'mousemove',
        showControls
    );

    shell.addEventListener(
        'touchstart',
        showControls,
        { passive: true }
    );


    video.addEventListener(
        'loadedmetadata',
        updateTimeline
    );

    video.addEventListener(
        'durationchange',
        updateTimeline
    );

    video.addEventListener(
        'timeupdate',
        updateTimeline
    );

    video.addEventListener(
        'play',
        () => {
            updatePlayUI();
            showControls();
        }
    );

    video.addEventListener(
        'pause',
        () => {
            updatePlayUI();
            showControls();
        }
    );

    video.addEventListener(
        'ended',
        () => {
            updatePlayUI();
            showControls();
        }
    );

    video.addEventListener(
        'volumechange',
        updateMuteUI
    );


    updatePlayUI();
    updateMuteUI();
    updateTimeline();
    showControls();

})();

</script>

<script src="/assets/mirrors-v1.js?v=1" defer></script>


<?php
if (!function_exists('ad_config')) { require_once dirname(__DIR__) . '/app/ads.php'; }
$__alPlayerBefore = ad_slot_payload('player_before_play');
$__alPlayerBefore2 = ad_slot_payload('player_before_play_2');
?>
<link rel="stylesheet" href="/assets/player-ads-v1.css?v=2">
<script>
window.AL_PLAYER_ADS = <?= json_encode(['before'=>[$__alPlayerBefore,$__alPlayerBefore2]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<script src="/assets/player-ads-v1.js?v=1"></script>
<!-- AL_PLAYER_ADS_V1 -->


<?php
if (!function_exists('ad_mobile_scripts_render')) { require_once dirname(__DIR__) . '/app/ads.php'; }
echo ad_mobile_scripts_render('video');
?>
<!-- AL_MOBILE_SCRIPTS_V1 -->


<?php
if (!function_exists('site_footer_render')) {
    require_once dirname(__DIR__) . '/app/site_footer.php';
}
?>
<link rel="stylesheet" href="/assets/legal-pages-v1.css?v=1">
<?= site_footer_render() ?>

<?php
if (!function_exists('antiblock_render')) {
    require_once dirname(__DIR__) . '/app/ads.php';
}
echo antiblock_render('video', 'body_end');
?>
<!-- AL_ANTIBLOCK_GLOBAL_V2_video_body_end -->

</body>
</html>
