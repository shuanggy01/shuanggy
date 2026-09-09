<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/app/full_player_ads.php';

function fp_e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function fp_is_bot(): bool
{
    $ua = strtolower(
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
    );

    if ($ua === '') {
        return false;
    }

    return (bool) preg_match(
        '/bot|crawler|spider|slurp|facebookexternalhit|telegrambot|whatsapp|discordbot|twitterbot|preview|curl|wget/i',
        $ua
    );
}

function fp_track_view(PDO $pdo, int $videoId): void
{
    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        !== 'GET'
        || fp_is_bot()
    ) {
        return;
    }

    $cookieName = 'al_viewer';

    $viewer = trim(
        (string) ($_COOKIE[$cookieName] ?? '')
    );

    if (
        $viewer === ''
        || !preg_match(
            '/^[a-f0-9]{32}$/',
            $viewer
        )
    ) {
        try {
            $viewer = bin2hex(random_bytes(16));

            setcookie(
                $cookieName,
                $viewer,
                [
                    'expires' => time() + 31536000,
                    'path' => '/',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        } catch (Throwable $e) {
            return;
        }
    }

    $viewerHash = hash('sha256', $viewer);

    try {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO video_views
                (video_id, viewer_hash, view_date, created_at)
             VALUES
                (:video_id, :viewer_hash, CURDATE(), NOW())"
        );

        $stmt->execute([
            ':video_id' => $videoId,
            ':viewer_hash' => $viewerHash,
        ]);

        if ($stmt->rowCount() === 1) {
            $update = $pdo->prepare(
                "UPDATE videos
                 SET views = views + 1
                 WHERE id = :id"
            );

            $update->execute([
                ':id' => $videoId,
            ]);
        }
    } catch (Throwable $e) {
        // View tracking must never break playback.
    }
}

$key = trim((string) ($_GET['id'] ?? ''));

if (
    $key === ''
    || !preg_match(
        '/^[A-Za-z0-9_-]{4,64}$/',
        $key
    )
) {
    http_response_code(404);
    exit('Video tidak ditemukan.');
}

$stmt = $pdo->prepare(
    "SELECT
        id,
        video_key,
        title,
        description,
        video_url,
        thumbnail_url,
        views,
        status
     FROM videos
     WHERE video_key = :video_key
       AND status = 'published'
     LIMIT 1"
);

$stmt->execute([
    ':video_key' => $key,
]);

$video = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$video) {
    http_response_code(404);
    exit('Video tidak ditemukan.');
}

fp_track_view(
    $pdo,
    (int) $video['id']
);

$title = trim((string) $video['title']);

if ($title === '') {
    $title = 'Video';
}

$description = trim(
    (string) ($video['description'] ?? '')
);

if ($description === '') {
    $description =
        'Tonton ' . $title . ' di AsupanLendir.';
}

$videoUrl =
    '/vp/'
    . rawurlencode(
        (string) $video['video_key']
    );

$thumbUrl = trim(
    (string) ($video['thumbnail_url'] ?? '')
);

$baseUrl = 'https://asupanlendir.sbs';

$fullUrl =
    $baseUrl
    . '/f/'
    . rawurlencode(
        (string) $video['video_key']
    );

$normalUrl =
    $baseUrl
    . '/v/'
    . rawurlencode(
        (string) $video['video_key']
    );

$downloadUrl =
    $baseUrl
    . '/dl/'
    . rawurlencode(
        (string) $video['video_key']
    );

?>
<!DOCTYPE html>
<html lang="id">
<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1, viewport-fit=cover"
>

<meta name="theme-color" content="#000000">
<meta name="robots" content="noindex,follow">

<title><?= fp_e($title) ?> - Full Player</title>

<link
    rel="canonical"
    href="<?= fp_e($normalUrl) ?>"
>

<meta property="og:type" content="video.other">
<meta property="og:site_name" content="AsupanLendir">
<meta property="og:title" content="<?= fp_e($title) ?>">
<meta property="og:description" content="<?= fp_e($description) ?>">
<meta property="og:url" content="<?= fp_e($fullUrl) ?>">

<?php if ($thumbUrl !== ''): ?>
<meta property="og:image" content="<?= fp_e($thumbUrl) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?= fp_e($thumbUrl) ?>">
<?php endif; ?>

<meta name="twitter:title" content="<?= fp_e($title) ?>">
<meta name="twitter:description" content="<?= fp_e($description) ?>">

<link
    rel="stylesheet"
    href="/assets/full-player-v1.css?v=12"
>


<!-- Full Player /f/ terisolasi dari Anti-AdBlock/global script reguler. -->
<?= fp_ads_antiblock_render('head') ?>
<?php
require_once dirname(__DIR__) . '/app/ads.php';
echo ad_meta_verification_render();
?>

</head>

<body>

<main
    class="fp-shell"
    data-full-player
    data-share-url="<?= fp_e($fullUrl) ?>"
    data-share-title="<?= fp_e($title) ?>"
>

<video
    class="fp-video"
    data-video
    src="<?= fp_e($videoUrl) ?>"
    poster="<?= fp_e($thumbUrl) ?>"
    controls
    controlsList="nodownload"
    playsinline
    preload="metadata"
    oncontextmenu="return false;"
></video>


<div class="fp-topbar">

<div class="fp-top-actions">

<a
    class="fp-icon-btn fp-download-btn"
    href="<?= fp_e($downloadUrl) ?>"
    aria-label="Download"
    title="Download"
>
    <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 3v12"/>
        <path d="m7 10 5 5 5-5"/>
        <path d="M5 21h14"/>
    </svg>
</a>


<button
    class="fp-icon-btn"
    type="button"
    data-share
    aria-label="Bagikan"
    title="Bagikan"
>
    <svg viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="18" cy="5" r="2"/>
        <circle cx="6" cy="12" r="2"/>
        <circle cx="18" cy="19" r="2"/>
        <path d="m8 11 8-5M8 13l8 5"/>
    </svg>
</button>


<button
    class="fp-icon-btn"
    type="button"
    data-fullscreen
    aria-label="Fullscreen"
    title="Fullscreen"
>
    <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"/>
    </svg>
</button>

</div>

</div>


<button
    class="fp-play"
    type="button"
    data-play
    aria-label="Putar video"
>
    <span>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M8 5v14l11-7Z"/>
        </svg>
    </span>

    <small>Tap untuk Putar</small>
</button>


<div
    class="fp-toast"
    data-toast
>
    Link disalin
</div>

</main>

<script src="/assets/full-player-v1.js?v=11"></script>



<!-- Tidak ada global regular script pada /f/. -->

<?php $__alFullAds = fp_ads_public_payload(); ?>

<link
    rel="stylesheet"
    href="/assets/full-player-ads-v3.css?v=3"
>

<script>
window.AL_FULL_PLAYER_ADS =
<?= json_encode(
    $__alFullAds,
    JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
) ?>;
</script>

<?= fp_ads_scripts_render() ?>
<?= fp_ads_antiblock_render('body_end') ?>

<script
    src="/assets/full-player-ads-v3.js?v=3"
></script>

<!-- AL_FULL_PLAYER_TELEGRAM_MONETIZATION_V3 -->

</body>
</html>
