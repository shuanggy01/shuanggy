<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/database.php';
require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';

admin_require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    http_response_code(404);
    exit('Album tidak ditemukan.');
}

$stmt = $pdo->prepare(
    "SELECT *
     FROM collections
     WHERE id = :id
     LIMIT 1"
);

$stmt->execute([
    ':id' => $id,
]);

$album = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$album) {
    http_response_code(404);
    exit('Album tidak ditemukan.');
}

$error = '';

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && isset($_POST['save_details'])
) {
    admin_verify_csrf();

    try {
        $title = trim((string) ($_POST['title'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'published'));

        if ($title === '') {
            throw new RuntimeException(
                'Judul album tidak boleh kosong.'
            );
        }

        if (mb_strlen($title) > 255) {
            throw new RuntimeException(
                'Judul album terlalu panjang.'
            );
        }

        if (!in_array($status, ['published', 'draft'], true)) {
            throw new RuntimeException(
                'Status album tidak valid.'
            );
        }

        $stmt = $pdo->prepare(
            "UPDATE collections
             SET
                title = :title,
                status = :status,
                updated_at = NOW()
             WHERE id = :id"
        );

        $stmt->execute([
            ':title' => $title,
            ':status' => $status,
            ':id' => $id,
        ]);

        header(
            'Location: /admin/album-mobile-edit.php?id='
            . $id
            . '&saved=details'
        );
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}


if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && isset($_POST['save_videos'])
) {
    admin_verify_csrf();

    try {
        $selected = $_POST['video_ids'] ?? [];

        if (!is_array($selected)) {
            $selected = [];
        }

        $selected = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $selected),
                    fn ($value) => $value > 0
                )
            )
        );

        $currentStmt = $pdo->prepare(
            "SELECT video_id
             FROM collection_videos
             WHERE collection_id = :collection_id
             ORDER BY position ASC, video_id ASC"
        );

        $currentStmt->execute([
            ':collection_id' => $id,
        ]);

        $currentOrder = array_map(
            'intval',
            $currentStmt->fetchAll(PDO::FETCH_COLUMN)
        );

        $ordered = [];

        foreach ($currentOrder as $videoId) {
            if (in_array($videoId, $selected, true)) {
                $ordered[] = $videoId;
            }
        }

        foreach ($selected as $videoId) {
            if (!in_array($videoId, $ordered, true)) {
                $ordered[] = $videoId;
            }
        }

        $pdo->beginTransaction();

        $deleteStmt = $pdo->prepare(
            "DELETE FROM collection_videos
             WHERE collection_id = :collection_id"
        );

        $deleteStmt->execute([
            ':collection_id' => $id,
        ]);

        if ($ordered) {
            $insertStmt = $pdo->prepare(
                "INSERT INTO collection_videos
                    (collection_id, video_id, position)
                 VALUES
                    (:collection_id, :video_id, :position)"
            );

            foreach ($ordered as $index => $videoId) {
                $insertStmt->execute([
                    ':collection_id' => $id,
                    ':video_id' => $videoId,
                    ':position' => $index + 1,
                ]);
            }
        }

        $pdo->commit();

        header(
            'Location: /admin/album-mobile-edit.php?id='
            . $id
            . '&saved=videos#videos'
        );
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();
    }
}


if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && isset($_POST['move_video'])
) {
    admin_verify_csrf();

    try {
        $videoId = (int) ($_POST['video_id'] ?? 0);
        $direction = trim((string) ($_POST['direction'] ?? ''));

        if ($videoId <= 0 || !in_array($direction, ['up', 'down'], true)) {
            throw new RuntimeException(
                'Perintah urutan video tidak valid.'
            );
        }

        $stmt = $pdo->prepare(
            "SELECT
                video_id,
                position
             FROM collection_videos
             WHERE collection_id = :collection_id
             ORDER BY position ASC, video_id ASC"
        );

        $stmt->execute([
            ':collection_id' => $id,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $index = null;

        foreach ($rows as $i => $row) {
            if ((int) $row['video_id'] === $videoId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            throw new RuntimeException(
                'Video tidak ada di album.'
            );
        }

        $target = $direction === 'up'
            ? $index - 1
            : $index + 1;

        if ($target >= 0 && $target < count($rows)) {
            [$rows[$index], $rows[$target]] = [
                $rows[$target],
                $rows[$index],
            ];

            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare(
                "UPDATE collection_videos
                 SET position = :position
                 WHERE collection_id = :collection_id
                   AND video_id = :video_id"
            );

            foreach ($rows as $i => $row) {
                $updateStmt->execute([
                    ':position' => $i + 1,
                    ':collection_id' => $id,
                    ':video_id' => (int) $row['video_id'],
                ]);
            }

            $pdo->commit();
        }

        header(
            'Location: /admin/album-mobile-edit.php?id='
            . $id
            . '&saved=order#videos'
        );
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();
    }
}


$stmt = $pdo->prepare(
    "SELECT
        cv.video_id,
        cv.position,
        v.video_key,
        v.title,
        v.thumbnail_url,
        v.views,
        v.status
     FROM collection_videos cv
     JOIN videos v
       ON v.id = cv.video_id
     WHERE cv.collection_id = :collection_id
     ORDER BY cv.position ASC, cv.video_id ASC"
);

$stmt->execute([
    ':collection_id' => $id,
]);

$currentVideos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$currentVideoIds = array_map(
    fn ($row) => (int) $row['video_id'],
    $currentVideos
);

$allVideos = $pdo->query(
    "SELECT
        id,
        video_key,
        title,
        thumbnail_url,
        views,
        status
     FROM videos
     ORDER BY id DESC
     LIMIT 300"
)->fetchAll(PDO::FETCH_ASSOC);

$created = isset($_GET['created']);
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

<title>Edit Kategori</title>

<link
    rel="stylesheet"
    href="/assets/admin-mobile-v1.css?v=1"
>
<link
    rel="stylesheet"
    href="/assets/album-manager-mobile-v2.css?v=2"
>
</head>

<body class="am-body">

<div class="am-app">

<header class="am-header">
    <div class="am-header-inner">

        <div class="am-brand">
            <a
                class="am-icon-btn alb-back"
                href="/admin/albums-mobile.php"
                aria-label="Kembali"
            >
                <?= am_icon('arrow', 18) ?>
            </a>

            <div class="am-brand-copy">
                <strong>Edit Kategori</strong>
                <span><?= am_e((string) $album['collection_key']) ?></span>
            </div>
        </div>

        <div class="am-header-actions">
            <a
                class="am-icon-btn"
                href="/c/<?= rawurlencode((string) $album['collection_key']) ?>"
                target="_blank"
                rel="noopener"
                aria-label="Buka album"
            >
                <?= am_icon('globe', 18) ?>
            </a>
        </div>

    </div>
</header>


<main class="am-main alb-main">


<?php if ($error !== ''): ?>
<div class="alb-flash error">
    <?= am_e($error) ?>
</div>
<?php endif; ?>

<?php if ($created): ?>
<div class="alb-flash">
    Kategori dibuat. Sekarang pilih videonya.
</div>
<?php elseif ($saved === 'details'): ?>
<div class="alb-flash">
    Detail kategori berhasil disimpan.
</div>
<?php elseif ($saved === 'videos'): ?>
<div class="alb-flash">
    Daftar video kategori berhasil disimpan.
</div>
<?php elseif ($saved === 'order'): ?>
<div class="alb-flash">
    Urutan video berhasil diperbarui.
</div>
<?php endif; ?>


<section class="alb-editor-card">

<div class="alb-editor-title">
    Detail Kategori
</div>

<form method="post">

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="id"
    value="<?= (int) $album['id'] ?>"
>

<input
    type="hidden"
    name="save_details"
    value="1"
>

<div class="alb-field">
    <label>Judul</label>

    <input
        type="text"
        name="title"
        required
        maxlength="255"
        value="<?= am_e((string) $album['title']) ?>"
    >
</div>


<div class="alb-field">
    <label>Status</label>

    <select name="status">
        <option
            value="published"
            <?= $album['status'] === 'published' ? 'selected' : '' ?>
        >
            Published
        </option>

        <option
            value="draft"
            <?= $album['status'] === 'draft' ? 'selected' : '' ?>
        >
            Draft
        </option>
    </select>
</div>


<button
    type="submit"
    class="alb-save"
>
    Simpan Detail
</button>

</form>

</section>


<section
    class="alb-editor-card"
    id="videos"
>

<div class="alb-editor-title">
    Urutan Video
</div>

<?php if (!$currentVideos): ?>

<div class="am-empty">
    Belum ada video di kategori.
</div>

<?php else: ?>

<div class="alb-order-list">

<?php foreach ($currentVideos as $index => $video): ?>

<div class="alb-order-card">

<?php if (!empty($video['thumbnail_url'])): ?>
<img
    src="<?= am_e((string) $video['thumbnail_url']) ?>"
    alt=""
    loading="lazy"
>
<?php else: ?>
<div class="alb-order-empty">
    <?= am_icon('play', 18) ?>
</div>
<?php endif; ?>

<div class="alb-order-copy">
    <strong>
        <?= am_e((string) $video['title']) ?>
    </strong>

    <span>
        #<?= $index + 1 ?>
        · <?= number_format((int) $video['views']) ?> views
    </span>
</div>

<div class="alb-order-actions">

<form method="post">
    <?= admin_csrf_input() ?>

    <input
        type="hidden"
        name="id"
        value="<?= (int) $album['id'] ?>"
    >

    <input
        type="hidden"
        name="move_video"
        value="1"
    >

    <input
        type="hidden"
        name="video_id"
        value="<?= (int) $video['video_id'] ?>"
    >

    <input
        type="hidden"
        name="direction"
        value="up"
    >

    <button
        type="submit"
        <?= $index === 0 ? 'disabled' : '' ?>
        aria-label="Naik"
    >
        ↑
    </button>
</form>


<form method="post">
    <?= admin_csrf_input() ?>

    <input
        type="hidden"
        name="id"
        value="<?= (int) $album['id'] ?>"
    >

    <input
        type="hidden"
        name="move_video"
        value="1"
    >

    <input
        type="hidden"
        name="video_id"
        value="<?= (int) $video['video_id'] ?>"
    >

    <input
        type="hidden"
        name="direction"
        value="down"
    >

    <button
        type="submit"
        <?= $index === count($currentVideos) - 1 ? 'disabled' : '' ?>
        aria-label="Turun"
    >
        ↓
    </button>
</form>

</div>

</div>

<?php endforeach; ?>

</div>

<?php endif; ?>

</section>


<section class="alb-editor-card">

<div class="alb-editor-title">
    Pilih Video
</div>

<form method="post">

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="id"
    value="<?= (int) $album['id'] ?>"
>

<input
    type="hidden"
    name="save_videos"
    value="1"
>

<div class="alb-video-checks">

<?php if (!$allVideos): ?>

<div class="am-empty">
    Belum ada video.
</div>

<?php else: ?>

<?php foreach ($allVideos as $video): ?>

<label class="alb-check-card">

<input
    type="checkbox"
    name="video_ids[]"
    value="<?= (int) $video['id'] ?>"
    <?= in_array(
        (int) $video['id'],
        $currentVideoIds,
        true
    ) ? 'checked' : '' ?>
>

<?php if (!empty($video['thumbnail_url'])): ?>
<img
    src="<?= am_e((string) $video['thumbnail_url']) ?>"
    alt=""
    loading="lazy"
>
<?php else: ?>
<div class="alb-check-empty">
    <?= am_icon('play', 16) ?>
</div>
<?php endif; ?>

<span>
    <strong>
        <?= am_e((string) $video['title']) ?>
    </strong>

    <small>
        <?= am_e(ucfirst((string) $video['status'])) ?>
        · <?= number_format((int) $video['views']) ?> views
    </small>
</span>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>


<button
    type="submit"
    class="alb-save secondary"
>
    Simpan Daftar Video
</button>

</form>

</section>


</main>

</div>

<?= am_bottom_nav('album') ?>

<script src="/assets/admin-mobile-v1.js?v=1"></script>

</body>
</html>
