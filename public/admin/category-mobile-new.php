<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/database.php';
require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';
require dirname(__DIR__, 2) . '/app/category.php';

admin_require_login();

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    try {
        $title = trim((string) ($_POST['title'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $description = trim(
            (string) ($_POST['description'] ?? '')
        );

        $status = trim(
            (string) ($_POST['status'] ?? 'published')
        );

        $keywords = category_normalize_keywords(
            (string) ($_POST['keywords'] ?? '')
        );

        if ($title === '') {
            throw new RuntimeException(
                'Nama kategori tidak boleh kosong.'
            );
        }

        if (!in_array(
            $status,
            ['published', 'draft'],
            true
        )) {
            throw new RuntimeException(
                'Status tidak valid.'
            );
        }

        $slug = $slugInput !== ''
            ? category_slugify($slugInput)
            : category_unique_slug(
                $pdo,
                $title
            );

        if ($slugInput !== '') {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM collections
                 WHERE slug = :slug"
            );

            $stmt->execute([
                ':slug' => $slug,
            ]);

            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException(
                    'Slug sudah digunakan.'
                );
            }
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO collections
                (
                    collection_key,
                    slug,
                    title,
                    description,
                    views,
                    status,
                    category_enabled,
                    created_at,
                    updated_at
                )
             VALUES
                (
                    :collection_key,
                    :slug,
                    :title,
                    :description,
                    0,
                    :status,
                    1,
                    NOW(),
                    NOW()
                )"
        );

        $stmt->execute([
            ':collection_key' =>
                category_generate_key($pdo),
            ':slug' => $slug,
            ':title' => $title,
            ':description' =>
                $description !== ''
                    ? $description
                    : null,
            ':status' => $status,
        ]);

        $id = (int) $pdo->lastInsertId();

        category_save_keywords(
            $pdo,
            $id,
            $keywords
        );

        $pdo->commit();

        header(
            'Location: /admin/category-mobile-edit.php?id='
            . $id
            . '&created=1'
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
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1, viewport-fit=cover"
>
<meta name="theme-color" content="#0B0D10">
<title>Buat Kategori</title>

<link rel="stylesheet" href="/assets/admin-mobile-v1.css?v=1">
<link rel="stylesheet" href="/assets/category-mobile-v1.css?v=1">
</head>

<body class="am-body">

<div class="am-app">

<header class="am-header">
    <div class="am-header-inner">
        <div class="am-brand">

            <a
                class="am-icon-btn cat-back"
                href="/admin/categories-mobile.php"
            >
                <?= am_icon('arrow', 18) ?>
            </a>

            <div class="am-brand-copy">
                <strong>Buat Kategori</strong>
                <span>AUTO MAPPING</span>
            </div>

        </div>
    </div>
</header>


<main class="am-main cat-main">

<?php if ($error !== ''): ?>
<div class="cat-flash error">
    <?= category_e($error) ?>
</div>
<?php endif; ?>


<section class="cat-editor">

<form method="post">

<?= admin_csrf_input() ?>

<div class="cat-field">
    <label>Nama Kategori</label>

    <input
        type="text"
        name="title"
        required
        value="<?= category_e((string) ($_POST['title'] ?? '')) ?>"
        placeholder="Contoh: Koleksi Terbaru"
    >
</div>


<div class="cat-field">
    <label>Slug</label>

    <input
        type="text"
        name="slug"
        value="<?= category_e((string) ($_POST['slug'] ?? '')) ?>"
        placeholder="kosong = otomatis"
    >
</div>


<div class="cat-field">
    <label>Deskripsi Kategori</label>

    <textarea
        name="description"
        rows="5"
        placeholder="Deskripsi yang tampil di halaman kategori..."
><?= category_e((string) ($_POST['description'] ?? '')) ?></textarea>
</div>


<div class="cat-field">
    <label>Keyword Auto Mapping</label>

    <textarea
        name="keywords"
        rows="6"
        placeholder="hijab&#10;jilbab&#10;kerudung"
><?= category_e((string) ($_POST['keywords'] ?? '')) ?></textarea>

    <small>
        Satu keyword per baris atau pisahkan dengan koma.
        Video baru cocok ke semua kategori yang keyword-nya ditemukan.
    </small>
</div>


<div class="cat-field">
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


<button class="cat-save" type="submit">
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
