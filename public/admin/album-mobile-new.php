<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/database.php';
require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';

admin_require_login();

function alb_generate_key(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $key = '';

        for ($i = 0; $i < 8; $i++) {
            $key .= $alphabet[
                random_int(0, strlen($alphabet) - 1)
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM collections
             WHERE collection_key = :key"
        );

        $stmt->execute([
            ':key' => $key,
        ]);

        if ((int) $stmt->fetchColumn() === 0) {
            return $key;
        }
    }

    throw new RuntimeException(
        'Gagal membuat album key unik.'
    );
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
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

        $key = alb_generate_key($pdo);

        $stmt = $pdo->prepare(
            "INSERT INTO collections
                (collection_key, title, views, status, created_at, updated_at)
             VALUES
                (:collection_key, :title, 0, :status, NOW(), NOW())"
        );

        $stmt->execute([
            ':collection_key' => $key,
            ':title' => $title,
            ':status' => $status,
        ]);

        $id = (int) $pdo->lastInsertId();

        header(
            'Location: /admin/album-mobile-edit.php?id='
            . $id
            . '&created=1'
        );
        exit;

    } catch (Throwable $e) {
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

<title>Buat Kategori</title>

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
                <strong>Buat Kategori</strong>
                <span>NEW COLLECTION</span>
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


<section class="alb-editor-card">

<div class="alb-editor-title">
    Kategori Baru
</div>

<form method="post">

<?= admin_csrf_input() ?>

<div class="alb-field">
    <label>Judul Kategori</label>

    <input
        type="text"
        name="title"
        required
        maxlength="255"
        value="<?= am_e((string) ($_POST['title'] ?? '')) ?>"
        placeholder="Contoh: Koleksi Terbaru"
    >
</div>


<div class="alb-field">
    <label>Status</label>

    <select name="status">
        <option value="published">
            Published
        </option>

        <option value="draft">
            Draft
        </option>
    </select>
</div>


<button
    type="submit"
    class="alb-save"
>
    Buat Kategori
</button>

</form>

</section>

</main>

</div>

<?= am_bottom_nav('album') ?>

<script src="/assets/admin-mobile-v1.js?v=1"></script>

</body>
</html>
