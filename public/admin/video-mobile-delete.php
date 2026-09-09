<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/database.php';
require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';

admin_require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    http_response_code(404);
    exit('Video tidak ditemukan.');
}

$stmt = $pdo->prepare(
    "SELECT
        id,
        video_key,
        title,
        thumbnail_url,
        views
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "DELETE FROM collection_videos
             WHERE video_id = :video_id"
        );

        $stmt->execute([
            ':video_id' => $id,
        ]);

        if (
            $pdo->query(
                "SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = 'video_sources'"
            )->fetchColumn()
        ) {
            $stmt = $pdo->prepare(
                "DELETE FROM video_sources
                 WHERE video_id = :video_id"
            );

            $stmt->execute([
                ':video_id' => $id,
            ]);
        }

        $stmt = $pdo->prepare(
            "DELETE FROM videos
             WHERE id = :id"
        );

        $stmt->execute([
            ':id' => $id,
        ]);

        $pdo->commit();

        header(
            'Location: /admin/videos-mobile.php?deleted=1'
        );
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();
    }
}

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

<title>Hapus Video</title>

<link
    rel="stylesheet"
    href="/assets/admin-mobile-v1.css?v=1"
>
<link
    rel="stylesheet"
    href="/assets/video-manager-mobile-v2.css?v=2"
>
</head>

<body class="am-body">

<div class="am-app">

<header class="am-header">
    <div class="am-header-inner">
        <div class="am-brand">
            <a
                class="am-icon-btn"
                href="/admin/videos-mobile.php"
            >
                <?= am_icon('arrow', 18) ?>
            </a>

            <div class="am-brand-copy">
                <strong>Hapus Video</strong>
                <span>CONFIRMATION</span>
            </div>
        </div>
    </div>
</header>


<main class="am-main vm-main">

<?php if ($error !== ''): ?>
<div class="vm-flash error">
    <?= am_e($error) ?>
</div>
<?php endif; ?>

<section class="vm-delete-card">

<?php if (!empty($video['thumbnail_url'])): ?>
<img
    src="<?= am_e((string) $video['thumbnail_url']) ?>"
    alt=""
>
<?php endif; ?>

<h1>
    Hapus video ini?
</h1>

<p>
    <?= am_e((string) $video['title']) ?>
</p>

<div class="vm-delete-warning">
    Data video akan dihapus dari database.
    File video/thumbnail eksternal tidak ikut dihapus.
</div>

<form method="post">

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="id"
    value="<?= (int) $video['id'] ?>"
>

<button
    class="vm-delete-confirm"
    type="submit"
>
    Ya, Hapus Video
</button>

<a
    class="vm-cancel"
    href="/admin/videos-mobile.php"
>
    Batal
</a>

</form>

</section>

</main>

</div>

<?= am_bottom_nav('video') ?>

<script src="/assets/admin-mobile-v1.js?v=1"></script>

</body>
</html>
