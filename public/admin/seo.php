<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';

admin_require_login();

function seo_e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function seo_valid_http_url(string $value): bool
{
    if ($value === '') {
        return true;
    }

    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower(
        (string) parse_url($value, PHP_URL_SCHEME)
    );

    return in_array($scheme, ['http', 'https'], true);
}

$message = '';
$error = '';

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
) {
    admin_verify_csrf();

    $entityType =
        (string) ($_POST['entity_type'] ?? '');

    $key =
        trim((string) ($_POST['key'] ?? ''));

    $seoTitle =
        trim((string) ($_POST['seo_title'] ?? ''));

    $seoDescription =
        trim((string) ($_POST['seo_description'] ?? ''));

    $seoImageUrl =
        trim((string) ($_POST['seo_image_url'] ?? ''));

    $seoNoindex =
        isset($_POST['seo_noindex'])
            ? 1
            : 0;

    if (mb_strlen($seoTitle) > 255) {
        $error =
            'SEO Title maksimal 255 karakter.';
    } elseif (
        mb_strlen($seoDescription) > 320
    ) {
        $error =
            'Meta Description maksimal 320 karakter.';
    } elseif (
        !seo_valid_http_url($seoImageUrl)
    ) {
        $error =
            'OG / Share Image harus URL http/https yang valid.';
    } elseif (
        !preg_match(
            '/^[A-Za-z0-9_-]{4,20}$/',
            $key
        )
    ) {
        $error =
            'Key konten tidak valid.';
    } else {

        if ($entityType === 'video') {

            $stmt = $pdo->prepare("
                UPDATE videos
                SET
                    seo_title = ?,
                    seo_description = ?,
                    seo_image_url = ?,
                    seo_noindex = ?
                WHERE video_key = ?
                LIMIT 1
            ");

            $stmt->execute([
                $seoTitle !== '' ? $seoTitle : null,
                $seoDescription !== '' ? $seoDescription : null,
                $seoImageUrl !== '' ? $seoImageUrl : null,
                $seoNoindex,
                $key,
            ]);

            $message =
                'SEO video berhasil disimpan.';

        } elseif ($entityType === 'album') {

            $stmt = $pdo->prepare("
                UPDATE collections
                SET
                    seo_title = ?,
                    seo_description = ?,
                    seo_image_url = ?,
                    seo_noindex = ?
                WHERE collection_key = ?
                LIMIT 1
            ");

            $stmt->execute([
                $seoTitle !== '' ? $seoTitle : null,
                $seoDescription !== '' ? $seoDescription : null,
                $seoImageUrl !== '' ? $seoImageUrl : null,
                $seoNoindex,
                $key,
            ]);

            $message =
                'SEO album berhasil disimpan.';

        } else {
            $error =
                'Tipe konten tidak valid.';
        }
    }
}

$tab =
    (string) ($_GET['tab'] ?? 'video');

if (!in_array($tab, ['video', 'album'], true)) {
    $tab = 'video';
}

$q =
    trim((string) ($_GET['q'] ?? ''));

$editType =
    (string) ($_GET['type'] ?? '');

$editKey =
    trim((string) ($_GET['id'] ?? ''));

$editing = null;
$defaultImage = '';
$defaultDescription = '';
$publicUrl = '';

if (
    $editType === 'video' &&
    $editKey !== ''
) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM videos
        WHERE video_key = ?
        LIMIT 1
    ");

    $stmt->execute([$editKey]);
    $editing = $stmt->fetch();

    if ($editing) {
        $defaultImage =
            trim(
                (string) (
                    $editing['thumbnail_url']
                    ?? ''
                )
            );

        $defaultDescription =
            trim(
                (string) (
                    $editing['description']
                    ?? ''
                )
            );

        if ($defaultDescription === '') {
            $defaultDescription =
                'Tonton ' .
                $editing['title'] .
                ' di AsupanLendir.';
        }

        $publicUrl =
            'https://asupanlendir.sbs/v/' .
            rawurlencode(
                $editing['video_key']
            );
    }

} elseif (
    $editType === 'album' &&
    $editKey !== ''
) {
    $stmt = $pdo->prepare("
        SELECT
            c.*,
            (
                SELECT COUNT(*)
                FROM collection_videos cv
                WHERE cv.collection_id = c.id
            ) AS video_count,
            (
                SELECT v.thumbnail_url
                FROM collection_videos cv2
                INNER JOIN videos v
                    ON v.id = cv2.video_id
                WHERE cv2.collection_id = c.id
                  AND v.status = 'published'
                  AND v.thumbnail_url IS NOT NULL
                  AND v.thumbnail_url <> ''
                ORDER BY
                    cv2.position ASC,
                    v.id ASC
                LIMIT 1
            ) AS cover_url
        FROM collections c
        WHERE c.collection_key = ?
        LIMIT 1
    ");

    $stmt->execute([$editKey]);
    $editing = $stmt->fetch();

    if ($editing) {
        $defaultImage =
            trim(
                (string) (
                    $editing['cover_url']
                    ?? ''
                )
            );

        $defaultDescription =
            $editing['title'] .
            ' - ' .
            number_format(
                (int) $editing['video_count']
            ) .
            ' video di AsupanLendir.';

        $publicUrl =
            'https://asupanlendir.sbs/c/' .
            rawurlencode(
                $editing['collection_key']
            );
    }
}

$like = '%' . $q . '%';

if ($tab === 'video') {

    if ($q !== '') {
        $stmt = $pdo->prepare("
            SELECT
                video_key AS item_key,
                title,
                thumbnail_url AS image_url,
                seo_title,
                seo_description,
                seo_image_url,
                seo_noindex,
                created_at
            FROM videos
            WHERE
                title LIKE ?
                OR video_key LIKE ?
            ORDER BY id DESC
            LIMIT 50
        ");

        $stmt->execute([$like, $like]);
        $items = $stmt->fetchAll();

    } else {
        $items = $pdo->query("
            SELECT
                video_key AS item_key,
                title,
                thumbnail_url AS image_url,
                seo_title,
                seo_description,
                seo_image_url,
                seo_noindex,
                created_at
            FROM videos
            ORDER BY id DESC
            LIMIT 50
        ")->fetchAll();
    }

} else {

    if ($q !== '') {
        $stmt = $pdo->prepare("
            SELECT
                c.collection_key AS item_key,
                c.title,
                (
                    SELECT v.thumbnail_url
                    FROM collection_videos cv
                    INNER JOIN videos v
                        ON v.id = cv.video_id
                    WHERE cv.collection_id = c.id
                      AND v.thumbnail_url IS NOT NULL
                      AND v.thumbnail_url <> ''
                    ORDER BY
                        cv.position ASC,
                        v.id ASC
                    LIMIT 1
                ) AS image_url,
                c.seo_title,
                c.seo_description,
                c.seo_image_url,
                c.seo_noindex,
                c.created_at
            FROM collections c
            WHERE
                c.title LIKE ?
                OR c.collection_key LIKE ?
            ORDER BY c.id DESC
            LIMIT 50
        ");

        $stmt->execute([$like, $like]);
        $items = $stmt->fetchAll();

    } else {
        $items = $pdo->query("
            SELECT
                c.collection_key AS item_key,
                c.title,
                (
                    SELECT v.thumbnail_url
                    FROM collection_videos cv
                    INNER JOIN videos v
                        ON v.id = cv.video_id
                    WHERE cv.collection_id = c.id
                      AND v.thumbnail_url IS NOT NULL
                      AND v.thumbnail_url <> ''
                    ORDER BY
                        cv.position ASC,
                        v.id ASC
                    LIMIT 1
                ) AS image_url,
                c.seo_title,
                c.seo_description,
                c.seo_image_url,
                c.seo_noindex,
                c.created_at
            FROM collections c
            ORDER BY c.id DESC
            LIMIT 50
        ")->fetchAll();
    }
}

$effectiveTitle = '';

if ($editing) {
    $effectiveTitle =
        trim(
            (string) (
                $editing['seo_title']
                ?? ''
            )
        );

    if ($effectiveTitle === '') {
        $effectiveTitle =
            $editing['title'] .
            ' - AsupanLendir';
    }
}

$effectiveDescription = '';

if ($editing) {
    $effectiveDescription =
        trim(
            (string) (
                $editing['seo_description']
                ?? ''
            )
        );

    if ($effectiveDescription === '') {
        $effectiveDescription =
            $defaultDescription;
    }
}

$effectiveImage = '';

if ($editing) {
    $effectiveImage =
        trim(
            (string) (
                $editing['seo_image_url']
                ?? ''
            )
        );

    if ($effectiveImage === '') {
        $effectiveImage =
            $defaultImage;
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>

<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>SEO Editor V2 - AsupanLendir</title>

<style>

:root{
    --bg:#0F1115;
    --panel:#1A1F26;
    --panel2:#15191F;
    --line:#2A3038;
    --yellow:#FFC107;
    --yellow2:#F2B705;
    --text:#fff;
    --muted:#A7ADB3;
    --danger:#ff6464;
}

*{box-sizing:border-box}

body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:Arial,Helvetica,sans-serif;
}

a{color:inherit}

.container{
    width:min(1180px,calc(100% - 24px));
    margin:auto;
}

header{
    position:sticky;
    top:0;
    z-index:30;
    background:rgba(15,17,21,.96);
    backdrop-filter:blur(12px);
    border-bottom:1px solid var(--line);
}

.topbar{
    min-height:66px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}

.brand{
    font-weight:800;
    font-size:20px;
}

.top-actions{
    display:flex;
    gap:7px;
    flex-wrap:wrap;
}

.btn{
    display:inline-block;
    border:1px solid var(--line);
    background:var(--panel);
    color:#fff;
    text-decoration:none;
    padding:10px 12px;
    border-radius:9px;
    font-size:12px;
    cursor:pointer;
}

.btn:hover{
    border-color:rgba(255,193,7,.35);
    color:var(--yellow);
}

.btn.primary{
    background:var(--yellow);
    border-color:var(--yellow);
    color:#111318;
    font-weight:800;
}

main{
    padding:23px 0 50px;
}

.notice{
    padding:12px 14px;
    margin-bottom:16px;
    border-radius:10px;
    border:1px solid var(--line);
    background:var(--panel);
    font-size:13px;
}

.notice.ok{
    border-color:rgba(255,193,7,.35);
}

.notice.error{
    color:#ffd0d0;
    border-color:rgba(255,100,100,.35);
}

.tabs{
    display:flex;
    gap:8px;
    margin-bottom:16px;
}

.tab{
    padding:9px 13px;
    border:1px solid var(--line);
    border-radius:999px;
    text-decoration:none;
    color:var(--muted);
    background:var(--panel);
    font-size:13px;
}

.tab.active{
    background:var(--yellow);
    color:#111318;
    border-color:var(--yellow);
    font-weight:800;
}

.search{
    display:flex;
    gap:8px;
    margin-bottom:17px;
}

.search input{
    flex:1;
    min-width:0;
    background:var(--panel);
    border:1px solid var(--line);
    color:#fff;
    border-radius:9px;
    padding:11px 12px;
}

.layout{
    display:grid;
    grid-template-columns:minmax(0,1fr) minmax(360px,460px);
    gap:20px;
    align-items:start;
}

.card{
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:13px;
    overflow:hidden;
}

.card-title{
    padding:14px 15px;
    border-bottom:1px solid var(--line);
    font-weight:800;
    font-size:14px;
}

.list-item{
    display:grid;
    grid-template-columns:82px minmax(0,1fr) auto;
    gap:12px;
    padding:12px;
    border-bottom:1px solid var(--line);
    align-items:center;
}

.list-item:last-child{
    border-bottom:0;
}

.thumb{
    width:82px;
    aspect-ratio:16/9;
    object-fit:cover;
    border-radius:8px;
    background:var(--panel2);
}

.thumb.empty{
    display:grid;
    place-items:center;
    color:#666;
}

.item-title{
    font-size:13px;
    font-weight:800;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.item-key{
    margin-top:4px;
    color:var(--muted);
    font-size:11px;
}

.seo-state{
    margin-top:6px;
    color:var(--muted);
    font-size:10px;
}

.seo-state.custom{
    color:var(--yellow);
}

.editor{
    padding:15px;
}

.field{
    margin-bottom:15px;
}

label{
    display:block;
    margin-bottom:7px;
    color:#e7e7e7;
    font-size:12px;
    font-weight:700;
}

input[type="text"],
input[type="url"],
textarea{
    width:100%;
    border:1px solid var(--line);
    background:var(--panel2);
    color:#fff;
    border-radius:9px;
    padding:11px 12px;
    outline:0;
    font:inherit;
    font-size:13px;
}

textarea{
    min-height:95px;
    resize:vertical;
}

input:focus,
textarea:focus{
    border-color:var(--yellow);
    box-shadow:0 0 0 3px rgba(255,193,7,.07);
}

.help{
    margin-top:6px;
    color:var(--muted);
    font-size:10px;
    line-height:1.45;
}

.counter{
    float:right;
    color:var(--muted);
    font-weight:400;
}

.check{
    display:flex;
    align-items:start;
    gap:9px;
    padding:11px;
    background:var(--panel2);
    border:1px solid var(--line);
    border-radius:9px;
}

.check input{
    margin-top:2px;
}

.check strong{
    display:block;
    font-size:12px;
}

.check span{
    display:block;
    margin-top:3px;
    color:var(--muted);
    font-size:10px;
}

.preview-title{
    margin:18px 0 9px;
    font-size:12px;
    color:var(--muted);
    text-transform:uppercase;
    letter-spacing:.5px;
}

.google-preview{
    background:#fff;
    color:#202124;
    border-radius:10px;
    padding:14px;
}

.google-url{
    color:#202124;
    font-size:11px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.google-title{
    margin-top:5px;
    color:#1a0dab;
    font-size:18px;
    line-height:1.25;
}

.google-desc{
    margin-top:5px;
    color:#4d5156;
    font-size:12px;
    line-height:1.45;
}

.share-preview{
    overflow:hidden;
    border:1px solid var(--line);
    background:var(--panel2);
    border-radius:10px;
}

.share-preview img{
    width:100%;
    aspect-ratio:1.91/1;
    display:block;
    object-fit:cover;
    background:#111;
}

.share-body{
    padding:12px;
}

.share-domain{
    color:var(--muted);
    font-size:10px;
}

.share-title{
    margin-top:4px;
    font-size:14px;
    font-weight:800;
}

.share-desc{
    margin-top:5px;
    color:var(--muted);
    font-size:11px;
    line-height:1.4;
}

.empty-editor{
    padding:35px 20px;
    text-align:center;
    color:var(--muted);
    line-height:1.6;
}

@media(max-width:850px){
    .layout{
        grid-template-columns:1fr;
    }
}

@media(max-width:560px){
    .list-item{
        grid-template-columns:70px minmax(0,1fr);
    }

    .list-item .edit-action{
        grid-column:1/-1;
    }

    .thumb{
        width:70px;
    }
}

</style>

</head>
<body>

<header>
<div class="container topbar">

<div class="brand">
    ⚙️ SEO Editor V2
</div>

<div class="top-actions">
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

<?php if ($message !== ''): ?>
<div class="notice ok">
    ✅ <?= seo_e($message) ?>
</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
<div class="notice error">
    ❌ <?= seo_e($error) ?>
</div>
<?php endif; ?>

<div class="tabs">

<a
    class="tab <?= $tab === 'video' ? 'active' : '' ?>"
    href="/admin/seo.php?tab=video"
>
    🎬 Video
</a>

<a
    class="tab <?= $tab === 'album' ? 'active' : '' ?>"
    href="/admin/seo.php?tab=album"
>
    📁 Album
</a>

</div>

<form class="search" method="get">

<input
    type="hidden"
    name="tab"
    value="<?= seo_e($tab) ?>"
>

<input
    type="text"
    name="q"
    value="<?= seo_e($q) ?>"
    placeholder="Cari judul atau key..."
>

<button class="btn primary" type="submit">
    Cari
</button>

</form>


<div class="layout">

<div class="card">

<div class="card-title">
    <?= $tab === 'video'
        ? '🎬 Daftar Video'
        : '📁 Daftar Album' ?>
</div>

<?php foreach ($items as $item): ?>

<div class="list-item">

<?php if (!empty($item['image_url'])): ?>

<img
    class="thumb"
    src="<?= seo_e($item['image_url']) ?>"
    alt=""
    loading="lazy"
>

<?php else: ?>

<div class="thumb empty">
    ▶
</div>

<?php endif; ?>


<div>

<div class="item-title">
    <?= seo_e($item['title']) ?>
</div>

<div class="item-key">
    <?= seo_e($item['item_key']) ?>
</div>

<?php
$hasCustomSeo =
    trim((string) ($item['seo_title'] ?? '')) !== ''
    ||
    trim((string) ($item['seo_description'] ?? '')) !== ''
    ||
    trim((string) ($item['seo_image_url'] ?? '')) !== ''
    ||
    (int) ($item['seo_noindex'] ?? 0) === 1;
?>

<div
    class="seo-state <?= $hasCustomSeo ? 'custom' : '' ?>"
>
    <?= $hasCustomSeo
        ? '● SEO custom'
        : '○ SEO otomatis' ?>
</div>

</div>


<div class="edit-action">

<a
    class="btn"
    href="/admin/seo.php?<?= http_build_query([
        'tab' => $tab,
        'q' => $q,
        'type' => $tab,
        'id' => $item['item_key'],
    ]) ?>"
>
    Edit SEO
</a>

</div>

</div>

<?php endforeach; ?>

</div>


<div class="card">

<div class="card-title">
    SEO Konten
</div>

<?php if ($editing): ?>

<div class="editor">

<div style="font-size:15px;font-weight:800;margin-bottom:15px">
    <?= seo_e($editing['title']) ?>
</div>

<form method="post">

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="entity_type"
    value="<?= seo_e($editType) ?>"
>

<input
    type="hidden"
    name="key"
    value="<?= seo_e($editKey) ?>"
>


<div class="field">

<label>
    SEO Title
    <span
        class="counter"
        id="titleCount"
    >0 / 255</span>
</label>

<input
    id="seoTitle"
    type="text"
    name="seo_title"
    maxlength="255"
    value="<?= seo_e(
        $editing['seo_title']
        ?? ''
    ) ?>"
    placeholder="<?= seo_e(
        $editing['title'] .
        ' - AsupanLendir'
    ) ?>"
>

<div class="help">
    Kosong = otomatis memakai judul konten.
</div>

</div>


<div class="field">

<label>
    Meta Description
    <span
        class="counter"
        id="descCount"
    >0 / 320</span>
</label>

<textarea
    id="seoDescription"
    name="seo_description"
    maxlength="320"
    placeholder="<?= seo_e($defaultDescription) ?>"
><?= seo_e(
    $editing['seo_description']
    ?? ''
) ?></textarea>

<div class="help">
    Kosong = otomatis memakai description/default.
    Untuk hasil Google biasanya ringkas lebih enak dibaca.
</div>

</div>


<div class="field">

<label>
    OG / Share Image
</label>

<input
    id="seoImage"
    type="url"
    name="seo_image_url"
    value="<?= seo_e(
        $editing['seo_image_url']
        ?? ''
    ) ?>"
    placeholder="<?= seo_e($defaultImage) ?>"
>

<div class="help">
    Kosong = otomatis memakai thumbnail/cover.
    Bisa isi URL gambar khusus jika diperlukan.
</div>

</div>


<div class="field">

<label class="check">

<input
    type="checkbox"
    name="seo_noindex"
    value="1"
    <?= (int) (
        $editing['seo_noindex']
        ?? 0
    ) === 1 ? 'checked' : '' ?>
>

<div>
    <strong>
        Noindex
    </strong>
    <span>
        Centang hanya jika halaman tidak ingin
        muncul di mesin pencari.
    </span>
</div>

</label>

</div>


<button
    class="btn primary"
    type="submit"
>
    💾 Simpan SEO
</button>

<a
    class="btn"
    href="<?= seo_e($publicUrl) ?>"
    target="_blank"
>
    Buka Halaman ↗
</a>

</form>


<div class="preview-title">
    Preview Google
</div>

<div class="google-preview">

<div
    class="google-url"
    id="previewUrl"
>
    <?= seo_e($publicUrl) ?>
</div>

<div
    class="google-title"
    id="googleTitle"
>
    <?= seo_e($effectiveTitle) ?>
</div>

<div
    class="google-desc"
    id="googleDesc"
>
    <?= seo_e($effectiveDescription) ?>
</div>

</div>


<div class="preview-title">
    Preview Share
</div>

<div class="share-preview">

<img
    id="shareImage"
    src="<?= seo_e($effectiveImage) ?>"
    alt=""
    <?= $effectiveImage === ''
        ? 'style="display:none"'
        : '' ?>
>

<div class="share-body">

<div class="share-domain">
    asupanlendir.sbs
</div>

<div
    class="share-title"
    id="shareTitle"
>
    <?= seo_e($effectiveTitle) ?>
</div>

<div
    class="share-desc"
    id="shareDesc"
>
    <?= seo_e($effectiveDescription) ?>
</div>

</div>

</div>

</div>

<script>

(() => {

    const title =
        document.getElementById('seoTitle');

    const desc =
        document.getElementById(
            'seoDescription'
        );

    const image =
        document.getElementById('seoImage');

    const titleCount =
        document.getElementById(
            'titleCount'
        );

    const descCount =
        document.getElementById(
            'descCount'
        );

    const googleTitle =
        document.getElementById(
            'googleTitle'
        );

    const googleDesc =
        document.getElementById(
            'googleDesc'
        );

    const shareTitle =
        document.getElementById(
            'shareTitle'
        );

    const shareDesc =
        document.getElementById(
            'shareDesc'
        );

    const shareImage =
        document.getElementById(
            'shareImage'
        );

    const fallbackTitle =
        <?= json_encode(
            $editing['title'] .
            ' - AsupanLendir',
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) ?>;

    const fallbackDesc =
        <?= json_encode(
            $defaultDescription,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) ?>;

    const fallbackImage =
        <?= json_encode(
            $defaultImage,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) ?>;


    function update() {

        const finalTitle =
            title.value.trim()
            || fallbackTitle;

        const finalDesc =
            desc.value.trim()
            || fallbackDesc;

        const finalImage =
            image.value.trim()
            || fallbackImage;

        titleCount.textContent =
            title.value.length +
            ' / 255';

        descCount.textContent =
            desc.value.length +
            ' / 320';

        googleTitle.textContent =
            finalTitle;

        googleDesc.textContent =
            finalDesc;

        shareTitle.textContent =
            finalTitle;

        shareDesc.textContent =
            finalDesc;

        if (finalImage) {
            shareImage.src =
                finalImage;

            shareImage.style.display =
                'block';
        } else {
            shareImage.style.display =
                'none';
        }
    }

    title.addEventListener(
        'input',
        update
    );

    desc.addEventListener(
        'input',
        update
    );

    image.addEventListener(
        'input',
        update
    );

    update();

})();

</script>

<?php else: ?>

<div class="empty-editor">
    Pilih <b>Edit SEO</b> pada video atau album.<br>
    Field boleh dibiarkan kosong karena SEO otomatis
    tetap bekerja.
</div>

<?php endif; ?>

</div>

</div>

</main>

</body>
</html>
