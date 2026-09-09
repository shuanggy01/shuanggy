<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/database.php';
require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';
require dirname(__DIR__, 2) . '/app/category.php';

admin_require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    http_response_code(404);
    exit('Kategori tidak ditemukan.');
}

$stmt = $pdo->prepare(
    "SELECT *
     FROM collections
     WHERE id = :id
       AND category_enabled = 1
     LIMIT 1"
);

$stmt->execute([
    ':id' => $id,
]);

$category = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$category) {
    http_response_code(404);
    exit('Kategori tidak ditemukan.');
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    try {
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug = category_slugify(
            (string) ($_POST['slug'] ?? '')
        );

        $description = trim(
            (string) ($_POST['description'] ?? '')
        );

        $status = trim(
            (string) ($_POST['status'] ?? 'published')
        );

        $seoTitle = trim(
            (string) ($_POST['seo_title'] ?? '')
        );

        $seoDescription = trim(
            (string) ($_POST['seo_description'] ?? '')
        );

        $seoImage = trim(
            (string) ($_POST['seo_image_url'] ?? '')
        );

        $noindex =
            isset($_POST['seo_noindex'])
                ? 1
                : 0;

        $keywords = category_normalize_keywords(
            (string) ($_POST['keywords'] ?? '')
        );

        if ($title === '') {
            throw new RuntimeException(
                'Nama kategori tidak boleh kosong.'
            );
        }

        if ($slug === '') {
            $slug = category_unique_slug(
                $pdo,
                $title,
                $id
            );
        }

        $check = $pdo->prepare(
            "SELECT COUNT(*)
             FROM collections
             WHERE slug = :slug
               AND id <> :id"
        );

        $check->execute([
            ':slug' => $slug,
            ':id' => $id,
        ]);

        if ((int) $check->fetchColumn() > 0) {
            throw new RuntimeException(
                'Slug sudah digunakan kategori lain.'
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

        if (mb_strlen($seoTitle) > 255) {
            throw new RuntimeException(
                'SEO Title terlalu panjang.'
            );
        }

        if (mb_strlen($seoDescription) > 320) {
            throw new RuntimeException(
                'SEO Description maksimal 320 karakter.'
            );
        }

        if (
            $seoImage !== ''
            && !filter_var(
                $seoImage,
                FILTER_VALIDATE_URL
            )
        ) {
            throw new RuntimeException(
                'SEO Image URL tidak valid.'
            );
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "UPDATE collections
             SET
                slug = :slug,
                title = :title,
                description = :description,
                status = :status,
                seo_title = :seo_title,
                seo_description = :seo_description,
                seo_image_url = :seo_image_url,
                seo_noindex = :seo_noindex,
                updated_at = NOW()
             WHERE id = :id"
        );

        $stmt->execute([
            ':slug' => $slug,
            ':title' => $title,
            ':description' =>
                $description !== ''
                    ? $description
                    : null,
            ':status' => $status,
            ':seo_title' =>
                $seoTitle !== ''
                    ? $seoTitle
                    : null,
            ':seo_description' =>
                $seoDescription !== ''
                    ? $seoDescription
                    : null,
            ':seo_image_url' =>
                $seoImage !== ''
                    ? $seoImage
                    : null,
            ':seo_noindex' => $noindex,
            ':id' => $id,
        ]);

        category_save_keywords(
            $pdo,
            $id,
            $keywords
        );

        $pdo->commit();

        header(
            'Location: /admin/category-mobile-edit.php?id='
            . $id
            . '&saved=1'
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
    "SELECT *
     FROM collections
     WHERE id = :id
     LIMIT 1"
);

$stmt->execute([
    ':id' => $id,
]);

$category = $stmt->fetch(PDO::FETCH_ASSOC);

$keywords = category_get_keywords(
    $pdo,
    $id
);

$videoCountStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM collection_videos
     WHERE collection_id = :id"
);

$videoCountStmt->execute([
    ':id' => $id,
]);

$videoCount = (int) $videoCountStmt->fetchColumn();

$saved = isset($_GET['saved']);
$created = isset($_GET['created']);

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
<title>Edit Kategori</title>

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
                <strong>Edit Kategori</strong>
                <span>
                    <?= number_format($videoCount) ?> VIDEO
                </span>
            </div>

        </div>

        <div class="am-header-actions">
            <a
                class="am-icon-btn"
                href="/kategori/<?= rawurlencode((string) $category['slug']) ?>"
                target="_blank"
                rel="noopener"
            >
                <?= am_icon('globe', 18) ?>
            </a>
        </div>

    </div>
</header>


<main class="am-main cat-main">

<?php if ($saved || $created): ?>
<div class="cat-flash">
    <?= $created
        ? 'Kategori berhasil dibuat.'
        : 'Kategori berhasil disimpan.' ?>
</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
<div class="cat-flash error">
    <?= category_e($error) ?>
</div>
<?php endif; ?>


<section class="cat-map-note">
    <strong>Auto Mapping Aktif</strong>
    <span>
        Video baru akan membaca judul + deskripsi dan bisa masuk
        ke beberapa kategori sekaligus.
    </span>
</section>


<section class="cat-editor">

<form method="post">

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="id"
    value="<?= (int) $category['id'] ?>"
>


<div class="cat-field">
    <label>Nama Kategori</label>

    <input
        type="text"
        name="title"
        required
        value="<?= category_e((string) $category['title']) ?>"
    >
</div>


<div class="cat-field">
    <label>Slug</label>

    <input
        type="text"
        name="slug"
        value="<?= category_e((string) $category['slug']) ?>"
    >

    <small>
        URL: /kategori/<?= category_e((string) $category['slug']) ?>
    </small>
</div>


<div class="cat-field">
    <label>Deskripsi Kategori</label>

    <textarea
        name="description"
        rows="5"
><?= category_e((string) ($category['description'] ?? '')) ?></textarea>
</div>


<div class="cat-field">
    <label>Keyword Auto Mapping</label>

    <textarea
        name="keywords"
        rows="7"
><?= category_e(implode("\n", $keywords)) ?></textarea>

    <small>
        Contoh video “Sicantik Hijab Live …” akan cocok ke kategori
        yang memiliki keyword “hijab” dan “live”.
    </small>
</div>


<div class="cat-field">
    <label>Status</label>

    <select name="status">
        <option
            value="published"
            <?= $category['status'] === 'published'
                ? 'selected'
                : '' ?>
        >
            Published
        </option>

        <option
            value="draft"
            <?= $category['status'] === 'draft'
                ? 'selected'
                : '' ?>
        >
            Draft
        </option>
    </select>
</div>


<div class="cat-divider">
    SEO Kategori
</div>


<div class="cat-field">
    <label>SEO Title</label>

    <input
        type="text"
        name="seo_title"
        maxlength="255"
        value="<?= category_e((string) ($category['seo_title'] ?? '')) ?>"
        placeholder="kosong = nama kategori"
    >
</div>


<div class="cat-field">
    <label>SEO Description</label>

    <textarea
        name="seo_description"
        maxlength="320"
        rows="4"
><?= category_e((string) ($category['seo_description'] ?? '')) ?></textarea>
</div>


<div class="cat-field">
    <label>SEO / Share Image URL</label>

    <input
        type="url"
        name="seo_image_url"
        value="<?= category_e((string) ($category['seo_image_url'] ?? '')) ?>"
        placeholder="https://..."
    >
</div>


<label class="cat-toggle">

<span>
    <strong>Noindex</strong>
    <small>
        Cegah kategori masuk indeks mesin pencari.
    </small>
</span>

<input
    type="checkbox"
    name="seo_noindex"
    value="1"
    <?= !empty($category['seo_noindex'])
        ? 'checked'
        : '' ?>
>

</label>


<button class="cat-save" type="submit">
    Simpan Kategori
</button>

</form>

</section>

</main>

</div>

<?= am_bottom_nav('album') ?>
<script src="/assets/admin-mobile-v1.js?v=1"></script>

</body>
</html>
