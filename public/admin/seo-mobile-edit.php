<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/database.php';
require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';

admin_require_login();

$type = trim((string) ($_GET['type'] ?? $_POST['type'] ?? ''));
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if (!in_array($type, ['video', 'album'], true) || $id <= 0) {
    http_response_code(404);
    exit('Konten SEO tidak ditemukan.');
}

function seoe_fallback_video(array $row): array
{
    $title = trim((string) $row['title']);

    if ($title === '') {
        $title = 'Untitled Video';
    }

    $description = trim((string) ($row['description'] ?? ''));

    if ($description === '') {
        $description =
            'Tonton ' . $title . ' di AsupanLendir.';
    }

    return [
        'title' => $title . ' - AsupanLendir',
        'description' => $description,
        'image' => trim((string) ($row['thumbnail_url'] ?? '')),
        'canonical' =>
            'https://asupanlendir.sbs/v/'
            . rawurlencode((string) $row['video_key']),
    ];
}

function seoe_fallback_album(array $row): array
{
    $title = trim((string) $row['title']);

    if ($title === '') {
        $title = 'Untitled Album';
    }

    $count = (int) ($row['video_count'] ?? 0);

    return [
        'title' => $title . ' - AsupanLendir',
        'description' =>
            $title
            . ' berisi '
            . $count
            . ' video di AsupanLendir.',
        'image' => trim((string) ($row['cover_url'] ?? '')),
        'canonical' =>
            'https://asupanlendir.sbs/c/'
            . rawurlencode((string) $row['collection_key']),
    ];
}

if ($type === 'video') {
    $stmt = $pdo->prepare(
        "SELECT
            id,
            video_key,
            title,
            description,
            thumbnail_url,
            status,
            views,
            seo_title,
            seo_description,
            seo_image_url,
            seo_noindex
         FROM videos
         WHERE id = :id
         LIMIT 1"
    );

    $stmt->execute([
        ':id' => $id,
    ]);

    $item = $stmt->fetch(PDO::FETCH_ASSOC);

} else {
    $stmt = $pdo->prepare(
        "SELECT
            c.id,
            c.collection_key,
            c.title,
            c.status,
            c.views,
            c.seo_title,
            c.seo_description,
            c.seo_image_url,
            c.seo_noindex,
            (
                SELECT COUNT(*)
                FROM collection_videos cv2
                WHERE cv2.collection_id = c.id
            ) AS video_count,
            (
                SELECT v.thumbnail_url
                FROM collection_videos cv
                JOIN videos v
                  ON v.id = cv.video_id
                WHERE cv.collection_id = c.id
                ORDER BY cv.position ASC, cv.video_id ASC
                LIMIT 1
            ) AS cover_url
         FROM collections c
         WHERE c.id = :id
         LIMIT 1"
    );

    $stmt->execute([
        ':id' => $id,
    ]);

    $item = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$item) {
    http_response_code(404);
    exit('Konten SEO tidak ditemukan.');
}

$fallback = $type === 'video'
    ? seoe_fallback_video($item)
    : seoe_fallback_album($item);

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    try {
        $seoTitle = trim((string) ($_POST['seo_title'] ?? ''));
        $seoDescription = trim((string) ($_POST['seo_description'] ?? ''));
        $seoImage = trim((string) ($_POST['seo_image_url'] ?? ''));
        $seoNoindex = isset($_POST['seo_noindex']) ? 1 : 0;

        if (mb_strlen($seoTitle) > 255) {
            throw new RuntimeException(
                'SEO Title maksimal 255 karakter.'
            );
        }

        if (mb_strlen($seoDescription) > 320) {
            throw new RuntimeException(
                'SEO Description maksimal 320 karakter.'
            );
        }

        if (
            $seoImage !== ''
            && !filter_var($seoImage, FILTER_VALIDATE_URL)
        ) {
            throw new RuntimeException(
                'SEO Image URL tidak valid.'
            );
        }

        $table = $type === 'video'
            ? 'videos'
            : 'collections';

        $stmt = $pdo->prepare(
            "UPDATE {$table}
             SET
                seo_title = :seo_title,
                seo_description = :seo_description,
                seo_image_url = :seo_image_url,
                seo_noindex = :seo_noindex,
                updated_at = NOW()
             WHERE id = :id"
        );

        $stmt->execute([
            ':seo_title' =>
                $seoTitle !== '' ? $seoTitle : null,
            ':seo_description' =>
                $seoDescription !== '' ? $seoDescription : null,
            ':seo_image_url' =>
                $seoImage !== '' ? $seoImage : null,
            ':seo_noindex' => $seoNoindex,
            ':id' => $id,
        ]);

        header(
            'Location: /admin/seo-mobile-edit.php?type='
            . rawurlencode($type)
            . '&id='
            . $id
            . '&saved=1'
        );
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$customTitle = trim((string) ($item['seo_title'] ?? ''));
$customDescription = trim((string) ($item['seo_description'] ?? ''));
$customImage = trim((string) ($item['seo_image_url'] ?? ''));

$previewTitle =
    $customTitle !== ''
        ? $customTitle
        : $fallback['title'];

$previewDescription =
    $customDescription !== ''
        ? $customDescription
        : $fallback['description'];

$previewImage =
    $customImage !== ''
        ? $customImage
        : $fallback['image'];

$saved = isset($_GET['saved']);

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

<title>Edit SEO</title>

<link
    rel="stylesheet"
    href="/assets/admin-mobile-v1.css?v=1"
>
<link
    rel="stylesheet"
    href="/assets/seo-mobile-v2.css?v=2"
>
</head>

<body class="am-body">

<div class="am-app">

<header class="am-header">
    <div class="am-header-inner">

        <div class="am-brand">
            <a
                class="am-icon-btn seo2-back"
                href="/admin/seo-mobile.php?type=<?= rawurlencode($type) ?>"
                aria-label="Kembali"
            >
                <?= am_icon('arrow', 18) ?>
            </a>

            <div class="am-brand-copy">
                <strong>Edit SEO</strong>
                <span>
                    <?= $type === 'video' ? 'VIDEO' : 'ALBUM' ?>
                </span>
            </div>
        </div>

        <div class="am-header-actions">
            <a
                class="am-icon-btn"
                href="<?= am_e($fallback['canonical']) ?>"
                target="_blank"
                rel="noopener"
                aria-label="Buka konten"
            >
                <?= am_icon('globe', 18) ?>
            </a>
        </div>

    </div>
</header>


<main class="am-main seo2-main">


<section class="seo2-editor-title">

<div class="seo2-editor-icon">
    <?= am_icon(
        $type === 'video' ? 'play' : 'album',
        20
    ) ?>
</div>

<div>
    <h1>
        <?= am_e((string) $item['title']) ?>
    </h1>

    <p>
        Field kosong = pakai fallback otomatis.
    </p>
</div>

</section>


<?php if ($saved): ?>
<div class="seo2-flash">
    SEO berhasil disimpan.
</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
<div class="seo2-flash error">
    <?= am_e($error) ?>
</div>
<?php endif; ?>


<section class="seo2-editor-card">

<form method="post">

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="type"
    value="<?= am_e($type) ?>"
>

<input
    type="hidden"
    name="id"
    value="<?= (int) $item['id'] ?>"
>


<div class="seo2-field">

<div class="seo2-label-row">
    <label>SEO Title</label>

    <span
        data-title-count
        data-max="60"
    >
        <?= mb_strlen($customTitle) ?>/60
    </span>
</div>

<input
    type="text"
    name="seo_title"
    maxlength="255"
    value="<?= am_e($customTitle) ?>"
    placeholder="<?= am_e($fallback['title']) ?>"
    data-seo-title
>

<small>
    Rekomendasi sekitar 50–60 karakter.
</small>

</div>


<div class="seo2-field">

<div class="seo2-label-row">
    <label>SEO Description</label>

    <span
        data-description-count
        data-max="160"
    >
        <?= mb_strlen($customDescription) ?>/160
    </span>
</div>

<textarea
    name="seo_description"
    maxlength="320"
    rows="5"
    placeholder="<?= am_e($fallback['description']) ?>"
    data-seo-description
><?= am_e($customDescription) ?></textarea>

<small>
    Rekomendasi sekitar 120–160 karakter.
</small>

</div>


<div class="seo2-field">

<label>SEO / Share Image URL</label>

<input
    type="url"
    name="seo_image_url"
    value="<?= am_e($customImage) ?>"
    placeholder="<?= am_e($fallback['image']) ?>"
    data-seo-image
>

<small>
    Kosong = thumbnail/cover otomatis.
</small>

</div>


<label class="seo2-noindex">

<span>
    <strong>Noindex</strong>
    <small>
        Cegah halaman ini masuk indeks mesin pencari.
    </small>
</span>

<input
    type="checkbox"
    name="seo_noindex"
    value="1"
    <?= !empty($item['seo_noindex']) ? 'checked' : '' ?>
>

</label>


<button
    type="submit"
    class="seo2-save"
>
    Simpan SEO
</button>

</form>

</section>


<section class="seo2-preview-card">

<div class="seo2-preview-head">
    <span>Google Preview</span>
    <small>AUTO / CUSTOM</small>
</div>

<div class="seo2-google-url">
    asupanlendir.sbs
</div>

<div
    class="seo2-google-title"
    data-preview-title
>
    <?= am_e($previewTitle) ?>
</div>

<div
    class="seo2-google-description"
    data-preview-description
>
    <?= am_e($previewDescription) ?>
</div>

</section>


<section class="seo2-preview-card">

<div class="seo2-preview-head">
    <span>Share Preview</span>
    <small>OG / Social</small>
</div>

<div class="seo2-share-image-wrap">

<?php if ($previewImage !== ''): ?>

<img
    src="<?= am_e($previewImage) ?>"
    alt=""
    data-preview-image
>

<?php else: ?>

<div
    class="seo2-share-empty"
    data-preview-image-empty
>
    <?= am_icon('seo', 24) ?>
</div>

<?php endif; ?>

</div>

<div class="seo2-share-copy">

<strong data-share-title>
    <?= am_e($previewTitle) ?>
</strong>

<span data-share-description>
    <?= am_e($previewDescription) ?>
</span>

<small>
    <?= am_e($fallback['canonical']) ?>
</small>

</div>

</section>


<section class="seo2-auto-card">

<div>
    <span>Fallback SEO</span>
    <strong>
        <?= am_e($fallback['title']) ?>
    </strong>
</div>

<p>
    <?= am_e($fallback['description']) ?>
</p>

<a
    href="<?= am_e($fallback['canonical']) ?>"
    target="_blank"
    rel="noopener"
>
    Canonical
    <?= am_icon('arrow', 14) ?>
</a>

</section>


</main>

</div>

<?= am_bottom_nav('more') ?>

<script src="/assets/admin-mobile-v1.js?v=1"></script>
<script src="/assets/seo-mobile-v2.js?v=2"></script>

</body>
</html>
