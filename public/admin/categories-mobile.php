<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/database.php';
require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';
require dirname(__DIR__, 2) . '/app/category.php';

admin_require_login();

$q = trim((string) ($_GET['q'] ?? ''));

$where = "WHERE c.category_enabled = 1";
$params = [];

if ($q !== '') {
    $where .=
        " AND (
            c.title LIKE :q_title
            OR c.slug LIKE :q_slug
        )";

    $params = [
        ':q_title' => '%' . $q . '%',
        ':q_slug' => '%' . $q . '%',
    ];
}

$stmt = $pdo->prepare(
    "SELECT
        c.id,
        c.collection_key,
        c.slug,
        c.title,
        c.description,
        c.views,
        c.status,
        c.seo_title,
        c.seo_description,
        c.seo_noindex,
        (
            SELECT COUNT(*)
            FROM collection_videos cv
            WHERE cv.collection_id = c.id
        ) AS video_count,
        (
            SELECT COUNT(*)
            FROM category_keywords ck
            WHERE ck.collection_id = c.id
        ) AS keyword_count
     FROM collections c
     {$where}
     ORDER BY c.id DESC
     LIMIT 100"
);

$stmt->execute($params);

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM collections
     WHERE category_enabled = 1"
)->fetchColumn();

$published = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM collections
     WHERE category_enabled = 1
       AND status = 'published'"
)->fetchColumn();

$keywords = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM category_keywords"
)->fetchColumn();

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
<title>Kategori Manager</title>

<link rel="stylesheet" href="/assets/admin-mobile-v1.css?v=1">
<link rel="stylesheet" href="/assets/category-mobile-v1.css?v=1">
</head>

<body class="am-body">

<div class="am-app">

<header class="am-header">
    <div class="am-header-inner">

        <div class="am-brand">
            <img
                class="am-brand-mark"
                src="/assets/brand-mark.png"
                alt=""
            >

            <div class="am-brand-copy">
                <strong>Kategori Manager</strong>
                <span>ADMIN MOBILE</span>
            </div>
        </div>

        <div class="am-header-actions">
            <a
                class="am-icon-btn"
                href="/admin/mobile.php"
                aria-label="Dashboard"
            >
                <?= am_icon('home', 19) ?>
            </a>
        </div>

    </div>
</header>


<main class="am-main cat-main">

<section class="cat-head">
    <div>
        <div class="am-eyebrow">Taxonomy</div>
        <h1>Kategori</h1>
        <p>
            Keyword mapping otomatis untuk video baru.
        </p>
    </div>

    <a
        class="cat-add"
        href="/admin/category-mobile-new.php"
    >
        <?= am_icon('plus', 17) ?>
        Buat
    </a>
</section>


<section class="cat-summary">
    <div>
        <strong><?= number_format($total) ?></strong>
        <span>Kategori</span>
    </div>

    <div>
        <strong><?= number_format($published) ?></strong>
        <span>Published</span>
    </div>

    <div>
        <strong><?= number_format($keywords) ?></strong>
        <span>Keywords</span>
    </div>
</section>


<form method="get" class="cat-search">
    <?= am_icon('search', 17) ?>

    <input
        type="search"
        name="q"
        value="<?= category_e($q) ?>"
        placeholder="Cari kategori..."
    >
</form>


<section class="cat-list">

<?php if (!$categories): ?>

<div class="am-empty">
    Belum ada kategori.
</div>

<?php endif; ?>


<?php foreach ($categories as $category): ?>

<a
    class="cat-card"
    href="/admin/category-mobile-edit.php?id=<?= (int) $category['id'] ?>"
>

<div class="cat-icon">
    <?= am_icon('album', 19) ?>
</div>

<div class="cat-copy">
    <strong>
        <?= category_e((string) $category['title']) ?>
    </strong>

    <span>
        <?= number_format((int) $category['video_count']) ?>
        video
        ·
        <?= number_format((int) $category['keyword_count']) ?>
        keyword
        ·
        <?= number_format((int) $category['views']) ?>
        views
    </span>

    <small>
        /kategori/<?= category_e((string) $category['slug']) ?>
    </small>
</div>

<div class="cat-status <?= category_e((string) $category['status']) ?>">
    <?= category_e(strtoupper((string) $category['status'])) ?>
</div>

<div class="cat-arrow">
    <?= am_icon('arrow', 16) ?>
</div>

</a>

<?php endforeach; ?>

</section>

</main>

</div>

<?= am_bottom_nav('album') ?>

<script src="/assets/admin-mobile-v1.js?v=1"></script>

</body>
</html>
