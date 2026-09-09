<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/database.php';

function dl_e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$key = trim(
    (string) ($_GET['id'] ?? '')
);

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
        thumbnail_url,
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

$title = trim(
    (string) $video['title']
);

if ($title === '') {
    $title = 'Video';
}

$thumbUrl = trim(
    (string) ($video['thumbnail_url'] ?? '')
);

$fullUrl =
    '/f/'
    . rawurlencode(
        (string) $video['video_key']
    );

$goUrl =
    '/download-go.php?id='
    . rawurlencode(
        (string) $video['video_key']
    );

$countdownSeconds = 30;

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
<meta name="robots" content="noindex,nofollow">

<title>Download <?= dl_e($title) ?></title>

<link
    rel="stylesheet"
    href="/assets/download-gate-v1.css?v=1"
>


<?php
if (!function_exists('antiblock_render')) {
    require_once dirname(__DIR__) . '/app/ads.php';
}
echo antiblock_render('download', 'head');
echo ad_meta_verification_render();
?>
<!-- AL_ANTIBLOCK_GLOBAL_V2_download_head -->

</head>

<body>

<main
    class="dl-shell"
    data-download-gate
    data-seconds="<?= $countdownSeconds ?>"
>

<section class="dl-card">

<div class="dl-icon">
    <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 3v11"/>
        <path d="m7 10 5 5 5-5"/>
        <path d="M5 21h14"/>
    </svg>
</div>


<h1>
    <?= dl_e($title) ?>
</h1>

<p class="dl-lead">
    File sedang disiapkan. Tunggu sebentar ya 😆🔥
</p>


<?php if ($thumbUrl !== ''): ?>

<div class="dl-thumb">
    <img
        src="<?= dl_e($thumbUrl) ?>"
        alt=""
    >
</div>

<?php endif; ?>


<div class="dl-ready-copy">
    Download tersedia dalam
</div>


<div class="dl-progress-track">
    <div
        class="dl-progress"
        data-progress
    ></div>
</div>


<div
    class="dl-count"
    data-count
>
    <?= $countdownSeconds ?>
</div>

<div
    class="dl-count-label"
    data-count-label
>
    detik lagi...
</div>


<a
    class="dl-download disabled"
    href="<?= dl_e($goUrl) ?>"
    data-download-button
    aria-disabled="true"
>
    <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 3v11"/>
        <path d="m7 10 5 5 5-5"/>
        <path d="M5 21h14"/>
    </svg>

    <span>
        Tunggu Download...
    </span>
</a>


<a
    class="dl-back"
    href="<?= dl_e($fullUrl) ?>"
>
    ← Kembali ke Full Player
</a>


<div class="dl-note">
    <strong>Tips</strong>
    <span>
        Setelah countdown selesai, tombol Download akan aktif.
    </span>
</div>

</section>

</main>

<script src="/assets/download-gate-v1.js?v=1"></script>


<?php
if (!function_exists('ad_mobile_scripts_render')) { require_once dirname(__DIR__) . '/app/ads.php'; }
echo ad_mobile_scripts_render('download');
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
echo antiblock_render('download', 'body_end');
?>
<!-- AL_ANTIBLOCK_GLOBAL_V2_download_body_end -->

</body>
</html>
