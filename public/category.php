<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/database.php';

$adsHelper = dirname(__DIR__) . '/app/ads.php';

if (is_file($adsHelper)) {
    require_once $adsHelper;
}

function cp_e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$slug = trim((string) ($_GET['slug'] ?? ''));

if (
    $slug === ''
    || !preg_match(
        '/^[a-z0-9-]{1,180}$/',
        $slug
    )
) {
    http_response_code(404);
    exit('Kategori tidak ditemukan.');
}

$stmt = $pdo->prepare(
    "SELECT
        id,
        collection_key,
        slug,
        title,
        description,
        views,
        status,
        seo_title,
        seo_description,
        seo_image_url,
        seo_noindex
     FROM collections
     WHERE slug = :slug
       AND category_enabled = 1
       AND status = 'published'
     LIMIT 1"
);

$stmt->execute([
    ':slug' => $slug,
]);

$category = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$category) {
    http_response_code(404);
    exit('Kategori tidak ditemukan.');
}

$stmt = $pdo->prepare(
    "SELECT
        v.id,
        v.video_key,
        v.title,
        v.thumbnail_url,
        v.views,
        v.created_at
     FROM collection_videos cv
     JOIN videos v
       ON v.id = cv.video_id
     WHERE cv.collection_id = :collection_id
       AND v.status = 'published'
     ORDER BY cv.position ASC, v.id DESC"
);

$stmt->execute([
    ':collection_id' => (int) $category['id'],
]);

$videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = trim((string) ($category['seo_title'] ?? ''));

if ($title === '') {
    $title =
        (string) $category['title']
        . ' - AsupanLendir';
}

$description = trim(
    (string) ($category['seo_description'] ?? '')
);

if ($description === '') {
    $description = trim(
        (string) ($category['description'] ?? '')
    );
}

if ($description === '') {
    $description =
        'Koleksi video kategori '
        . (string) $category['title']
        . ' di AsupanLendir.';
}

$image = trim(
    (string) ($category['seo_image_url'] ?? '')
);

if ($image === '' && $videos) {
    $image = trim(
        (string) ($videos[0]['thumbnail_url'] ?? '')
    );
}

$canonical =
    'https://asupanlendir.sbs/kategori/'
    . rawurlencode((string) $category['slug']);

?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1, viewport-fit=cover"
>
<meta name="theme-color" content="#0F1115">

<title><?= cp_e($title) ?></title>

<meta
    name="description"
    content="<?= cp_e($description) ?>"
>

<meta
    name="robots"
    content="<?= !empty($category['seo_noindex'])
        ? 'noindex,follow'
        : 'index,follow' ?>"
>

<link
    rel="canonical"
    href="<?= cp_e($canonical) ?>"
>

<meta property="og:type" content="website">
<meta property="og:site_name" content="AsupanLendir">
<meta property="og:title" content="<?= cp_e($title) ?>">
<meta property="og:description" content="<?= cp_e($description) ?>">
<meta property="og:url" content="<?= cp_e($canonical) ?>">

<?php if ($image !== ''): ?>
<meta property="og:image" content="<?= cp_e($image) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?= cp_e($image) ?>">
<?php endif; ?>

<link
    rel="stylesheet"
    href="/assets/category-public-v1.css?v=1"
<link rel="stylesheet" href="/assets/ads-v1.css?v=2">
>


<?php
if (!function_exists('antiblock_render')) {
    require_once dirname(__DIR__) . '/app/ads.php';
}
echo antiblock_render('category', 'head');
echo ad_meta_verification_render();
?>
<!-- AL_ANTIBLOCK_GLOBAL_V2_category_head -->

</head>

<body>

<header class="cp-header">
    <a class="cp-brand" href="/">
        <img src="/assets/brand-mark.png" alt="">
        <strong>AsupanLendir</strong>
    </a>

    <a class="cp-home" href="/">
        Homepage
    </a>
</header>


<main class="cp-main">

<?php
if (
    function_exists('ad_render')
) {
    echo '<div style="width:100%;text-align:center;display:flex;justify-content:center;overflow:hidden;">';
    echo ad_render('album_top');
    echo '</div>';
}
?>


<section class="cp-hero">

<div class="cp-eyebrow">
    KATEGORI
</div>

<h1>
    <?= cp_e((string) $category['title']) ?>
</h1>

<p>
    <?= nl2br(
        cp_e(
            trim(
                (string) ($category['description'] ?? '')
            ) !== ''
                ? (string) $category['description']
                : $description
        )
    ) ?>
</p>

<div class="cp-meta">
    <span>
        <?= number_format(count($videos)) ?>
        video
    </span>

    <span>
        <?= number_format((int) $category['views']) ?>
        views
    </span>
</div>

</section>


<section class="cp-grid">

<?php if (!$videos): ?>

<div class="cp-empty">
    Belum ada video di kategori ini.
</div>

<?php endif; ?>


<?php foreach ($videos as $video): ?>

<a
    class="cp-card"
    href="/v/<?= rawurlencode((string) $video['video_key']) ?>"
>

<div class="cp-thumb">

<?php if (!empty($video['thumbnail_url'])): ?>
<img
    src="<?= cp_e((string) $video['thumbnail_url']) ?>"
    alt=""
    loading="lazy"
>
<?php endif; ?>

<div class="cp-play">
    ▶
</div>

</div>

<strong>
    <?= cp_e((string) $video['title']) ?>
</strong>

<span>
    <?= number_format((int) $video['views']) ?>
    views
</span>

</a>

<?php endforeach; ?>

</section>

</main>


<?php
if (!function_exists('ad_mobile_scripts_render')) { require_once dirname(__DIR__) . '/app/ads.php'; }
echo ad_mobile_scripts_render('category');
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
echo antiblock_render('category', 'body_end');
?>
<!-- AL_ANTIBLOCK_GLOBAL_V2_category_body_end -->

</body>
</html>
