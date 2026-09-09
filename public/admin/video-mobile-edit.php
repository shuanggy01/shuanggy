<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/database.php';
require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';
require dirname(__DIR__, 2) . '/app/thumbnail_upload.php';

admin_require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    http_response_code(404);
    exit('Video tidak ditemukan.');
}

$stmt = $pdo->prepare(
    "SELECT *
     FROM videos
     WHERE id = :id
     LIMIT 1"
);

$stmt->execute([
    ':id' => $id,
]);

$video = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$video) {
    http_response_code(404);
    exit('Video tidak ditemukan.');
}

$error = '';

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && !isset($_POST['save_albums'])
) {
    admin_verify_csrf();

    try {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
        $thumbnailUrl = trim((string) ($_POST['thumbnail_url'] ?? ''));

        $removeThumbnail =
            isset($_POST['remove_thumbnail']);

        if ($removeThumbnail) {
            $thumbnailUrl = '';
        }

        $thumbnailFile =
            $_FILES['thumbnail_file'] ?? null;

        if (
            is_array($thumbnailFile)
            && (int) (
                $thumbnailFile['error']
                ?? UPLOAD_ERR_NO_FILE
            ) !== UPLOAD_ERR_NO_FILE
        ) {
            $thumbnailUrl =
                thumb_upload_to_r2(
                    $thumbnailFile,
                    (string) $video['video_key']
                );
        }

        $status = trim((string) ($_POST['status'] ?? 'published'));

        if ($title === '') {
            throw new RuntimeException(
                'Judul tidak boleh kosong.'
            );
        }

        if (!in_array($status, ['published', 'draft'], true)) {
            throw new RuntimeException(
                'Status tidak valid.'
            );
        }

        if (
            $videoUrl === ''
            || !filter_var($videoUrl, FILTER_VALIDATE_URL)
        ) {
            throw new RuntimeException(
                'URL video tidak valid.'
            );
        }

        if (
            $thumbnailUrl !== ''
            && !filter_var($thumbnailUrl, FILTER_VALIDATE_URL)
        ) {
            throw new RuntimeException(
                'URL thumbnail tidak valid.'
            );
        }

        $stmt = $pdo->prepare(
            "UPDATE videos
             SET
                title = :title,
                description = :description,
                video_url = :video_url,
                thumbnail_url = :thumbnail_url,
                status = :status,
                updated_at = NOW()
             WHERE id = :id"
        );

        $stmt->execute([
            ':title' => $title,
            ':description' => $description,
            ':video_url' => $videoUrl,
            ':thumbnail_url' => $thumbnailUrl,
            ':status' => $status,
            ':id' => $id,
        ]);

        $video['title'] = $title;
        $video['description'] = $description;
        $video['video_url'] = $videoUrl;
        $video['thumbnail_url'] = $thumbnailUrl;
        $video['status'] = $status;

        header(
            'Location: /admin/video-mobile-edit.php?id='
            . $id
            . '&saved=video'
        );
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$albums = [];

try {
    $stmt = $pdo->query(
        "SELECT
            id,
            collection_key,
            title,
            status
         FROM collections
         ORDER BY id DESC"
    );

    $albums = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $albums = [];
}

$currentAlbumIds = [];

try {
    $stmt = $pdo->prepare(
        "SELECT collection_id
         FROM collection_videos
         WHERE video_id = :video_id"
    );

    $stmt->execute([
        ':video_id' => $id,
    ]);

    $currentAlbumIds = array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );
} catch (Throwable $e) {
    $currentAlbumIds = [];
}

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && isset($_POST['save_albums'])
) {
    admin_verify_csrf();

    try {
        $selected = $_POST['album_ids'] ?? [];

        if (!is_array($selected)) {
            $selected = [];
        }

        $selected = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $selected),
                    fn ($v) => $v > 0
                )
            )
        );

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "DELETE FROM collection_videos
             WHERE video_id = :video_id"
        );

        $stmt->execute([
            ':video_id' => $id,
        ]);

        if ($selected) {
            $positionStmt = $pdo->prepare(
                "SELECT COALESCE(MAX(position),0) + 1
                 FROM collection_videos
                 WHERE collection_id = :collection_id"
            );

            $insertStmt = $pdo->prepare(
                "INSERT INTO collection_videos
                    (collection_id, video_id, position)
                 VALUES
                    (:collection_id, :video_id, :position)"
            );

            foreach ($selected as $collectionId) {
                $positionStmt->execute([
                    ':collection_id' => $collectionId,
                ]);

                $position = (int) $positionStmt->fetchColumn();

                $insertStmt->execute([
                    ':collection_id' => $collectionId,
                    ':video_id' => $id,
                    ':position' => $position,
                ]);
            }
        }

        $pdo->commit();

        $currentAlbumIds = $selected;

        header(
            'Location: /admin/video-mobile-edit.php?id='
            . $id
            . '&saved=album#album'
        );
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();
    }
}

$saved = trim((string) ($_GET['saved'] ?? ''));

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

<title>Edit Video</title>

<link
    rel="stylesheet"
    href="/assets/admin-mobile-v1.css?v=1"
>
<link
    rel="stylesheet"
    href="/assets/video-manager-mobile-v2.css?v=2"
>
<link rel="stylesheet" href="/assets/thumbnail-upload-v1.css?v=1">
</head>

<body class="am-body">

<div class="am-app">

<header class="am-header">
    <div class="am-header-inner">

        <div class="am-brand">
            <a
                class="am-icon-btn"
                href="/admin/videos-mobile.php"
                aria-label="Kembali"
            >
                <?= am_icon('arrow', 18) ?>
            </a>

            <div class="am-brand-copy">
                <strong>Edit Video</strong>
                <span><?= am_e((string) $video['video_key']) ?></span>
            </div>
        </div>

        <div class="am-header-actions">
            <a
                class="am-icon-btn"
                href="/v/<?= rawurlencode((string) $video['video_key']) ?>"
                target="_blank"
                rel="noopener"
            >
                <?= am_icon('globe', 18) ?>
            </a>
        </div>

    </div>
</header>


<main class="am-main vm-main">


<?php if ($error !== ''): ?>
<div class="vm-flash error">
    <?= am_e($error) ?>
</div>
<?php endif; ?>

<?php if ($saved === 'video'): ?>
<div class="vm-flash">
    Video berhasil diperbarui.
</div>
<?php elseif ($saved === 'album'): ?>
<div class="vm-flash">
    Album video berhasil diperbarui.
</div>
<?php endif; ?>


<section class="vm-editor-card">

<div class="vm-editor-head">

<?php if (!empty($video['thumbnail_url'])): ?>
<img
    src="<?= am_e((string) $video['thumbnail_url']) ?>"
    alt=""
>
<?php endif; ?>

<div>
    <strong>
        <?= am_e((string) $video['title']) ?>
    </strong>

    <span>
        <?= number_format((int) $video['views']) ?> views
    </span>
</div>

</div>


<form method="post" enctype="multipart/form-data">

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="id"
    value="<?= (int) $video['id'] ?>"
>

<div class="vm-field">
    <label>Judul</label>

    <input
        type="text"
        name="title"
        required
        value="<?= am_e((string) $video['title']) ?>"
    >
</div>


<div class="vm-field">
    <label>Status</label>

    <select name="status">
        <option
            value="published"
            <?= $video['status'] === 'published' ? 'selected' : '' ?>
        >
            Published
        </option>

        <option
            value="draft"
            <?= $video['status'] === 'draft' ? 'selected' : '' ?>
        >
            Draft
        </option>
    </select>
</div>


<div class="vm-field">
    <label>URL Video</label>

    <input
        type="url"
        name="video_url"
        required
        value="<?= am_e((string) $video['video_url']) ?>"
    >
</div>


<!-- THUMBNAIL_UPLOAD_V1 -->
<div class="vm-thumb-editor">

    <div class="vm-thumb-editor-title">
        Thumbnail
    </div>

    <div class="vm-thumb-preview-wrap">

        <img
            id="vm-thumbnail-preview"
            src="<?= am_e((string) $video['thumbnail_url']) ?>"
            alt=""
            <?= empty($video['thumbnail_url'])
                ? 'hidden'
                : '' ?>
        >

        <div
            id="vm-thumbnail-preview-empty"
            class="vm-thumb-preview-empty"
            <?= !empty($video['thumbnail_url'])
                ? 'hidden'
                : '' ?>
        >
            Belum ada thumbnail
        </div>

    </div>


    <label class="vm-thumb-file">

        <span>
            Pilih Gambar dari HP
        </span>

        <input
            id="vm-thumbnail-file"
            type="file"
            name="thumbnail_file"
            accept="image/jpeg,image/png,image/webp"
        >

    </label>

    <div
        id="vm-thumbnail-file-name"
        class="vm-thumb-file-name"
    ></div>

    <div class="vm-thumb-file-note">
        JPG, PNG, atau WEBP • maksimal 5 MB.
        File akan di-upload langsung ke Cloudflare R2.
    </div>


    <div class="vm-thumb-or">
        atau gunakan URL
    </div>


    <div class="vm-field">
        <label>URL Thumbnail</label>

        <input
            type="url"
            name="thumbnail_url"
            value="<?= am_e((string) $video['thumbnail_url']) ?>"
            placeholder="https://..."
        >
    </div>


    <label class="vm-thumb-remove">

        <input
            type="checkbox"
            name="remove_thumbnail"
            value="1"
        >

        <span>
            Hapus thumbnail saat disimpan
        </span>

    </label>

</div>


<div class="vm-field">
    <label>Deskripsi</label>

    <textarea
        name="description"
        rows="5"
    ><?= am_e((string) $video['description']) ?></textarea>
</div>


<button
    type="submit"
    class="vm-save"
>
    Simpan Perubahan
</button>

</form>

</section>


<section
    class="vm-editor-card"
    id="album"
>

<div class="vm-editor-title">
    Album
</div>

<form method="post">

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="id"
    value="<?= (int) $video['id'] ?>"
>

<input
    type="hidden"
    name="save_albums"
    value="1"
>

<div class="vm-album-checks">

<?php if (!$albums): ?>

<div class="am-empty">
    Belum ada album.
</div>

<?php else: ?>

<?php foreach ($albums as $album): ?>

<label class="vm-check-card">

<input
    type="checkbox"
    name="album_ids[]"
    value="<?= (int) $album['id'] ?>"
    <?= in_array(
        (int) $album['id'],
        $currentAlbumIds,
        true
    ) ? 'checked' : '' ?>
>

<span>
    <strong>
        <?= am_e((string) $album['title']) ?>
    </strong>

    <small>
        <?= am_e(ucfirst((string) $album['status'])) ?>
    </small>
</span>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<button
    type="submit"
    class="vm-save secondary"
>
    Simpan Album
</button>

</form>

</section>


</main>

</div>

<?= am_bottom_nav('video') ?>

<script src="/assets/admin-mobile-v1.js?v=1"></script>
<script src="/assets/thumbnail-upload-v1.js?v=1"></script>

</body>
</html>
