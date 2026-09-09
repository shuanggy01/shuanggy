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
    "SELECT
        id,
        collection_key,
        title,
        views
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "DELETE FROM collection_videos
             WHERE collection_id = :collection_id"
        );

        $stmt->execute([
            ':collection_id' => $id,
        ]);

        $stmt = $pdo->prepare(
            "DELETE FROM collections
             WHERE id = :id"
        );

        $stmt->execute([
            ':id' => $id,
        ]);

        $pdo->commit();

        header(
            'Location: /admin/albums-mobile.php?deleted=1'
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

<title>Hapus Kategori</title>

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
            >
                <?= am_icon('arrow', 18) ?>
            </a>

            <div class="am-brand-copy">
                <strong>Hapus Kategori</strong>
                <span>CONFIRMATION</span>
            </div>
        </div>

    </div>
</header>


<main class="am-main alb-main">

<?php if ($error !== ''): ?>
<div class="alb-flash error">
    <?= am_e($error) ?>
</div>
<?php endif; ?>

<section class="alb-delete-card">

<div class="alb-delete-icon">
    <?= am_icon('album', 30) ?>
</div>

<h1>
    Hapus kategori ini?
</h1>

<p>
    <?= am_e((string) $album['title']) ?>
</p>

<div class="alb-delete-warning">
    Kategori dan relasinya akan dihapus.
    Video asli tetap aman dan tidak ikut dihapus.
</div>

<form method="post">

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="id"
    value="<?= (int) $album['id'] ?>"
>

<button
    class="alb-delete-confirm"
    type="submit"
>
    Ya, Hapus Kategori
</button>

<a
    class="alb-cancel"
    href="/admin/albums-mobile.php"
>
    Batal
</a>

</form>

</section>

</main>

</div>

<?= am_bottom_nav('album') ?>

<script src="/assets/admin-mobile-v1.js?v=1"></script>

</body>
</html>
