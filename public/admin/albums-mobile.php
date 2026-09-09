<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/database.php';
require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';

admin_require_login();

$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? 'all'));

$allowedStatus = ['all', 'published', 'draft'];

if (!in_array($status, $allowedStatus, true)) {
    $status = 'all';
}

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(c.title LIKE :q_title OR c.collection_key LIKE :q_key)';
    $params[':q_title'] = '%' . $q . '%';
    $params[':q_key'] = '%' . $q . '%';
}

if ($status !== 'all') {
    $where[] = 'c.status = :status';
    $params[':status'] = $status;
}

$sql = "
    SELECT
        c.id,
        c.collection_key,
        c.title,
        c.views,
        c.status,
        c.created_at,
        c.updated_at,
        (
            SELECT COUNT(*)
            FROM collection_videos cv_count
            WHERE cv_count.collection_id = c.id
        ) AS video_count,
        (
            SELECT v.thumbnail_url
            FROM collection_videos cv_cover
            JOIN videos v
              ON v.id = cv_cover.video_id
            WHERE cv_cover.collection_id = c.id
            ORDER BY cv_cover.position ASC, cv_cover.video_id ASC
            LIMIT 1
        ) AS cover_url
    FROM collections c
";

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY c.id DESC LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$albums = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = (int) $pdo->query(
    "SELECT COUNT(*) FROM collections"
)->fetchColumn();

$published = (int) $pdo->query(
    "SELECT COUNT(*) FROM collections WHERE status='published'"
)->fetchColumn();

$draft = (int) $pdo->query(
    "SELECT COUNT(*) FROM collections WHERE status='draft'"
)->fetchColumn();

$created = isset($_GET['created']);
$deleted = isset($_GET['deleted']);

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

<title>Pengaturan Kategori</title>

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
            <img
                class="am-brand-mark"
                src="/assets/brand-mark.png"
                alt=""
            >

            <div class="am-brand-copy">
                <strong>Pengaturan Kategori</strong>
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


<main class="am-main alb-main">


<section class="alb-page-head">
    <div>
        <div class="am-eyebrow">Collections</div>
        <h1>Kategori</h1>
        <p>
            Buat dan kelola kategori video dari HP.
        </p>
    </div>

    <a
        class="alb-add"
        href="/admin/album-mobile-new.php"
    >
        <?= am_icon('plus', 18) ?>
        <span>Buat</span>
    </a>
</section>


<?php if ($created): ?>
<div class="alb-flash">
    Kategori berhasil dibuat.
</div>
<?php endif; ?>

<?php if ($deleted): ?>
<div class="alb-flash">
    Kategori berhasil dihapus.
</div>
<?php endif; ?>


<section class="alb-summary">

<div>
    <strong><?= number_format($total) ?></strong>
    <span>Total</span>
</div>

<div>
    <strong><?= number_format($published) ?></strong>
    <span>Published</span>
</div>

<div>
    <strong><?= number_format($draft) ?></strong>
    <span>Draft</span>
</div>

</section>


<form
    class="alb-search"
    method="get"
>

<div class="alb-search-box">
    <?= am_icon('search', 18) ?>

    <input
        type="search"
        name="q"
        value="<?= am_e($q) ?>"
        placeholder="Cari judul atau kode kategori..."
        autocomplete="off"
    >
</div>

<input
    type="hidden"
    name="status"
    value="<?= am_e($status) ?>"
>

</form>


<nav class="alb-filters">

<?php
$filterLabels = [
    'all' => 'Semua',
    'published' => 'Published',
    'draft' => 'Draft',
];
?>

<?php foreach ($filterLabels as $key => $label): ?>
<a
    class="<?= $status === $key ? 'active' : '' ?>"
    href="?status=<?= rawurlencode($key) ?>&q=<?= rawurlencode($q) ?>"
>
    <?= am_e($label) ?>
</a>
<?php endforeach; ?>

</nav>


<section class="alb-list">

<?php if (!$albums): ?>

<div class="am-empty">
    Tidak ada kategori yang cocok.
</div>

<?php else: ?>

<?php foreach ($albums as $album): ?>

<?php
$albumKey = (string) $album['collection_key'];
$title = trim((string) $album['title']);

if ($title === '') {
    $title = 'Untitled Album';
}
?>

<article
    class="alb-card"
    data-album-card
>

<a
    class="alb-cover-wrap"
    href="/c/<?= rawurlencode($albumKey) ?>"
    target="_blank"
    rel="noopener"
>

<?php if (!empty($album['cover_url'])): ?>
<img
    class="alb-cover"
    src="<?= am_e((string) $album['cover_url']) ?>"
    alt=""
    loading="lazy"
>
<?php else: ?>
<div class="alb-cover alb-cover-empty">
    <?= am_icon('album', 23) ?>
</div>
<?php endif; ?>

<span class="alb-count-badge">
    <?= number_format((int) $album['video_count']) ?> video
</span>

</a>


<div class="alb-copy">

<div class="alb-title">
    <?= am_e($title) ?>
</div>

<div class="alb-meta">

<span class="alb-status <?= am_e((string) $album['status']) ?>">
    <?= am_e(ucfirst((string) $album['status'])) ?>
</span>

<span class="alb-views">
    <?= am_icon('eye', 13) ?>
    <?= number_format((int) $album['views']) ?>
</span>

</div>

<div class="alb-key">
    <?= am_e($albumKey) ?>
</div>

</div>


<button
    type="button"
    class="alb-menu-btn"
    aria-label="Menu album"
    data-album-menu-open
>
    <?= am_icon('more', 20) ?>
</button>


<div
    class="alb-menu"
    data-album-menu
    hidden
>

<a
    href="/admin/album-mobile-edit.php?id=<?= (int) $album['id'] ?>"
>
    <?= am_icon('settings', 17) ?>
    <span>Edit Kategori</span>
</a>

<a
    href="/c/<?= rawurlencode($albumKey) ?>"
    target="_blank"
    rel="noopener"
>
    <?= am_icon('globe', 17) ?>
    <span>Buka Kategori</span>
</a>

<button
    type="button"
    data-copy-link="<?= am_e(
        'https://asupanlendir.sbs/c/' . $albumKey
    ) ?>"
>
    <?= am_icon('arrow', 17) ?>
    <span>Copy Link</span>
</button>

<a
    href="/admin/album-mobile-edit.php?id=<?= (int) $album['id'] ?>#videos"
>
    <?= am_icon('play', 17) ?>
    <span>Kelola Video</span>
</a>

<a
    class="danger"
    href="/admin/album-mobile-delete.php?id=<?= (int) $album['id'] ?>"
>
    <?= am_icon('close', 17) ?>
    <span>Delete</span>
</a>

</div>


</article>

<?php endforeach; ?>

<?php endif; ?>

</section>


</main>

</div>

<?= am_bottom_nav('album') ?>

<div class="alb-toast" data-toast>
    Link disalin
</div>

<script src="/assets/admin-mobile-v1.js?v=1"></script>
<script src="/assets/album-manager-mobile-v2.js?v=2"></script>

</body>
</html>
