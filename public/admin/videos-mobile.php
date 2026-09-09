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
    $where[] = '(title LIKE :q_title OR video_key LIKE :q_key)';
    $params[':q_title'] = '%' . $q . '%';
    $params[':q_key'] = '%' . $q . '%';
}

if ($status !== 'all') {
    $where[] = 'status = :status';
    $params[':status'] = $status;
}

$sql = "
    SELECT
        id,
        video_key,
        title,
        description,
        video_url,
        thumbnail_url,
        views,
        status,
        created_at,
        updated_at
    FROM videos
";

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY id DESC LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = (int) $pdo->query(
    "SELECT COUNT(*) FROM videos"
)->fetchColumn();

$published = (int) $pdo->query(
    "SELECT COUNT(*) FROM videos WHERE status='published'"
)->fetchColumn();

$draft = (int) $pdo->query(
    "SELECT COUNT(*) FROM videos WHERE status='draft'"
)->fetchColumn();

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

<title>Video Manager Mobile</title>

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
            <img
                class="am-brand-mark"
                src="/assets/brand-mark.png"
                alt=""
            >

            <div class="am-brand-copy">
                <strong>Video Manager</strong>
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


<main class="am-main vm-main">


<section class="vm-page-head">
    <div>
        <div class="am-eyebrow">Content</div>
        <h1>Video</h1>
        <p>
            Cari, buka, edit, dan kelola video dari HP.
        </p>
    </div>

    <a
        class="vm-add"
        href="/admin/#manual-video"
    >
        <?= am_icon('plus', 18) ?>
        <span>Tambah</span>
    </a>
</section>


<?php if ($deleted): ?>
<div class="vm-flash">
    Video berhasil dihapus.
</div>
<?php endif; ?>


<section class="vm-summary">
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
    class="vm-search"
    method="get"
>

<div class="vm-search-box">
    <?= am_icon('search', 18) ?>

    <input
        type="search"
        name="q"
        value="<?= am_e($q) ?>"
        placeholder="Cari judul atau video key..."
        autocomplete="off"
    >
</div>

<input
    type="hidden"
    name="status"
    value="<?= am_e($status) ?>"
>

</form>


<nav class="vm-filters">

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


<section class="vm-list">

<?php if (!$videos): ?>

<div class="am-empty">
    Tidak ada video yang cocok.
</div>

<?php else: ?>

<?php foreach ($videos as $video): ?>

<?php
$videoKey = (string) $video['video_key'];
$title = trim((string) $video['title']);
if ($title === '') {
    $title = 'Untitled Video';
}
?>

<article
    class="vm-card"
    data-video-card
>

<a
    class="vm-thumb-wrap"
    href="/v/<?= rawurlencode($videoKey) ?>"
    target="_blank"
    rel="noopener"
>

<?php if (!empty($video['thumbnail_url'])): ?>
<img
    class="vm-thumb"
    src="<?= am_e((string) $video['thumbnail_url']) ?>"
    alt=""
    loading="lazy"
>
<?php else: ?>
<div class="vm-thumb vm-thumb-empty">
    <?= am_icon('play', 22) ?>
</div>
<?php endif; ?>

<span class="vm-play-badge">
    <?= am_icon('play', 11) ?>
</span>

</a>


<div class="vm-copy">

<div class="vm-title">
    <?= am_e($title) ?>
</div>

<div class="vm-meta">

<span class="vm-status <?= am_e((string) $video['status']) ?>">
    <?= am_e(ucfirst((string) $video['status'])) ?>
</span>

<span class="vm-views">
    <?= am_icon('eye', 13) ?>
    <?= number_format((int) $video['views']) ?>
</span>

</div>

<div class="vm-key">
    <?= am_e($videoKey) ?>
</div>

</div>


<button
    type="button"
    class="vm-menu-btn"
    aria-label="Menu video"
    data-video-menu-open
>
    <?= am_icon('more', 20) ?>
</button>


<div
    class="vm-menu"
    data-video-menu
    hidden
>

<a
    href="/admin/video-mobile-edit.php?id=<?= (int) $video['id'] ?>"
>
    <?= am_icon('settings', 17) ?>
    <span>Edit</span>
</a>

<a
    href="/v/<?= rawurlencode($videoKey) ?>"
    target="_blank"
    rel="noopener"
>
    <?= am_icon('globe', 17) ?>
    <span>Open Video</span>
</a>

<button
    type="button"
    data-copy-link="<?= am_e(
        'https://asupanlendir.sbs/v/' . $videoKey
    ) ?>"
>
    <?= am_icon('arrow', 17) ?>
    <span>Copy Link</span>
</button>

<a
    href="/admin/video-mobile-edit.php?id=<?= (int) $video['id'] ?>#album"
>
    <?= am_icon('album', 17) ?>
    <span>Tambah ke Kategori</span>
</a>

<a
    class="danger"
    href="/admin/video-mobile-delete.php?id=<?= (int) $video['id'] ?>"
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

<?= am_bottom_nav('video') ?>

<div class="vm-toast" data-toast>
    Link disalin
</div>

<script src="/assets/admin-mobile-v1.js?v=1"></script>
<script src="/assets/video-manager-mobile-v2.js?v=2"></script>

</body>
</html>
