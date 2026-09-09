<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/database.php';
require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/admin_mobile_ui.php';

admin_require_login();

function seo_m_column_exists(
    PDO $pdo,
    string $table,
    string $column
): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?"
        );

        $stmt->execute([
            $table,
            $column,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function seo_m_state(array $row): array
{
    $noindex = !empty($row['seo_noindex']);

    $custom =
        trim((string) ($row['seo_title'] ?? '')) !== ''
        || trim((string) ($row['seo_description'] ?? '')) !== ''
        || trim((string) ($row['seo_image_url'] ?? '')) !== '';

    if ($noindex) {
        return ['NOINDEX', 'noindex'];
    }

    if ($custom) {
        return ['CUSTOM', 'custom'];
    }

    return ['AUTO', 'auto'];
}

$videoColumnsReady =
    seo_m_column_exists($pdo, 'videos', 'seo_title')
    && seo_m_column_exists($pdo, 'videos', 'seo_description')
    && seo_m_column_exists($pdo, 'videos', 'seo_image_url')
    && seo_m_column_exists($pdo, 'videos', 'seo_noindex');

$albumColumnsReady =
    seo_m_column_exists($pdo, 'collections', 'seo_title')
    && seo_m_column_exists($pdo, 'collections', 'seo_description')
    && seo_m_column_exists($pdo, 'collections', 'seo_image_url')
    && seo_m_column_exists($pdo, 'collections', 'seo_noindex');

if (!$videoColumnsReady || !$albumColumnsReady) {
    http_response_code(500);
    exit(
        'SEO database belum siap. '
        . 'Pastikan migration SEO Editor V2 sudah terpasang.'
    );
}

$type = trim((string) ($_GET['type'] ?? 'video'));
$q = trim((string) ($_GET['q'] ?? ''));
$state = trim((string) ($_GET['state'] ?? 'all'));

if (!in_array($type, ['video', 'album'], true)) {
    $type = 'video';
}

if (!in_array($state, ['all', 'auto', 'custom', 'noindex'], true)) {
    $state = 'all';
}

$params = [];
$where = [];

if ($type === 'video') {
    if ($q !== '') {
        $where[] = '(v.title LIKE :q_title OR v.video_key LIKE :q_key)';
        $params[':q_title'] = '%' . $q . '%';
        $params[':q_key'] = '%' . $q . '%';
    }

    if ($state === 'auto') {
        $where[] = "
            COALESCE(v.seo_noindex, 0) = 0
            AND COALESCE(v.seo_title, '') = ''
            AND COALESCE(v.seo_description, '') = ''
            AND COALESCE(v.seo_image_url, '') = ''
        ";
    } elseif ($state === 'custom') {
        $where[] = "
            COALESCE(v.seo_noindex, 0) = 0
            AND (
                COALESCE(v.seo_title, '') <> ''
                OR COALESCE(v.seo_description, '') <> ''
                OR COALESCE(v.seo_image_url, '') <> ''
            )
        ";
    } elseif ($state === 'noindex') {
        $where[] = 'COALESCE(v.seo_noindex, 0) = 1';
    }

    $sql = "
        SELECT
            v.id,
            v.video_key AS content_key,
            v.title,
            v.thumbnail_url AS cover_url,
            v.status,
            v.views,
            v.seo_title,
            v.seo_description,
            v.seo_image_url,
            v.seo_noindex
        FROM videos v
    ";

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY v.id DESC LIMIT 100';

} else {
    if ($q !== '') {
        $where[] = '(c.title LIKE :q_title OR c.collection_key LIKE :q_key)';
        $params[':q_title'] = '%' . $q . '%';
        $params[':q_key'] = '%' . $q . '%';
    }

    if ($state === 'auto') {
        $where[] = "
            COALESCE(c.seo_noindex, 0) = 0
            AND COALESCE(c.seo_title, '') = ''
            AND COALESCE(c.seo_description, '') = ''
            AND COALESCE(c.seo_image_url, '') = ''
        ";
    } elseif ($state === 'custom') {
        $where[] = "
            COALESCE(c.seo_noindex, 0) = 0
            AND (
                COALESCE(c.seo_title, '') <> ''
                OR COALESCE(c.seo_description, '') <> ''
                OR COALESCE(c.seo_image_url, '') <> ''
            )
        ";
    } elseif ($state === 'noindex') {
        $where[] = 'COALESCE(c.seo_noindex, 0) = 1';
    }

    $sql = "
        SELECT
            c.id,
            c.collection_key AS content_key,
            c.title,
            c.status,
            c.views,
            c.seo_title,
            c.seo_description,
            c.seo_image_url,
            c.seo_noindex,
            (
                SELECT v.thumbnail_url
                FROM collection_videos cv
                JOIN videos v
                  ON v.id = cv.video_id
                WHERE cv.collection_id = c.id
                ORDER BY cv.position ASC, cv.video_id ASC
                LIMIT 1
            ) AS cover_url,
            (
                SELECT COUNT(*)
                FROM collection_videos cv2
                WHERE cv2.collection_id = c.id
            ) AS video_count
        FROM collections c
    ";

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY c.id DESC LIMIT 100';
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countSql = $type === 'video'
    ? "SELECT
            COUNT(*) AS total,
            SUM(
                CASE WHEN COALESCE(seo_noindex,0)=1
                THEN 1 ELSE 0 END
            ) AS noindex_count,
            SUM(
                CASE
                    WHEN COALESCE(seo_noindex,0)=0
                     AND (
                        COALESCE(seo_title,'') <> ''
                        OR COALESCE(seo_description,'') <> ''
                        OR COALESCE(seo_image_url,'') <> ''
                     )
                    THEN 1 ELSE 0
                END
            ) AS custom_count
       FROM videos"
    : "SELECT
            COUNT(*) AS total,
            SUM(
                CASE WHEN COALESCE(seo_noindex,0)=1
                THEN 1 ELSE 0 END
            ) AS noindex_count,
            SUM(
                CASE
                    WHEN COALESCE(seo_noindex,0)=0
                     AND (
                        COALESCE(seo_title,'') <> ''
                        OR COALESCE(seo_description,'') <> ''
                        OR COALESCE(seo_image_url,'') <> ''
                     )
                    THEN 1 ELSE 0
                END
            ) AS custom_count
       FROM collections";

$counts = $pdo->query($countSql)->fetch(PDO::FETCH_ASSOC) ?: [];

$totalCount = (int) ($counts['total'] ?? 0);
$noindexCount = (int) ($counts['noindex_count'] ?? 0);
$customCount = (int) ($counts['custom_count'] ?? 0);
$autoCount = max(
    0,
    $totalCount - $noindexCount - $customCount
);

$saved = isset($_GET['saved']);

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

<title>SEO Mobile</title>

<link
    rel="stylesheet"
    href="/assets/admin-mobile-v1.css?v=1"
>
<link
    rel="stylesheet"
    href="/assets/seo-mobile-v2.css?v=2"
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
                <strong>SEO Editor</strong>
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


<main class="am-main seo2-main">


<section class="seo2-page-head">
    <div class="am-eyebrow">Search Appearance</div>
    <h1>SEO</h1>
    <p>
        Override SEO hanya jika diperlukan.
        Field kosong tetap memakai SEO otomatis.
    </p>
</section>


<?php if ($saved): ?>
<div class="seo2-flash">
    SEO berhasil disimpan.
</div>
<?php endif; ?>


<nav class="seo2-type-tabs">

<a
    class="<?= $type === 'video' ? 'active' : '' ?>"
    href="?type=video"
>
    <?= am_icon('play', 17) ?>
    <span>Video</span>
</a>

<a
    class="<?= $type === 'album' ? 'active' : '' ?>"
    href="?type=album"
>
    <?= am_icon('album', 17) ?>
    <span>Album</span>
</a>

</nav>


<section class="seo2-summary">

<div>
    <strong><?= number_format($totalCount) ?></strong>
    <span>Total</span>
</div>

<div>
    <strong><?= number_format($autoCount) ?></strong>
    <span>Auto</span>
</div>

<div>
    <strong><?= number_format($customCount) ?></strong>
    <span>Custom</span>
</div>

<div>
    <strong><?= number_format($noindexCount) ?></strong>
    <span>Noindex</span>
</div>

</section>


<form
    class="seo2-search"
    method="get"
>

<input
    type="hidden"
    name="type"
    value="<?= am_e($type) ?>"
>

<input
    type="hidden"
    name="state"
    value="<?= am_e($state) ?>"
>

<div class="seo2-search-box">
    <?= am_icon('search', 18) ?>

    <input
        type="search"
        name="q"
        value="<?= am_e($q) ?>"
        placeholder="Cari judul atau key..."
        autocomplete="off"
    >
</div>

</form>


<nav class="seo2-filters">

<?php
$filters = [
    'all' => 'Semua',
    'auto' => 'Auto',
    'custom' => 'Custom',
    'noindex' => 'Noindex',
];
?>

<?php foreach ($filters as $key => $label): ?>

<a
    class="<?= $state === $key ? 'active' : '' ?>"
    href="?type=<?= rawurlencode($type) ?>&state=<?= rawurlencode($key) ?>&q=<?= rawurlencode($q) ?>"
>
    <?= am_e($label) ?>
</a>

<?php endforeach; ?>

</nav>


<section class="seo2-list">

<?php if (!$items): ?>

<div class="am-empty">
    Tidak ada konten yang cocok.
</div>

<?php else: ?>

<?php foreach ($items as $item): ?>

<?php
[$seoState, $seoClass] = seo_m_state($item);

$title = trim((string) $item['title']);

if ($title === '') {
    $title = $type === 'video'
        ? 'Untitled Video'
        : 'Untitled Album';
}
?>

<a
    class="seo2-card"
    href="/admin/seo-mobile-edit.php?type=<?= rawurlencode($type) ?>&id=<?= (int) $item['id'] ?>"
>

<?php if (!empty($item['cover_url'])): ?>

<img
    class="seo2-cover"
    src="<?= am_e((string) $item['cover_url']) ?>"
    alt=""
    loading="lazy"
>

<?php else: ?>

<div class="seo2-cover seo2-cover-empty">
    <?= am_icon(
        $type === 'video' ? 'play' : 'album',
        20
    ) ?>
</div>

<?php endif; ?>


<div class="seo2-card-copy">

<strong>
    <?= am_e($title) ?>
</strong>

<div class="seo2-card-meta">
    <span class="seo2-state <?= am_e($seoClass) ?>">
        <?= am_e($seoState) ?>
    </span>

    <span>
        <?= number_format((int) ($item['views'] ?? 0)) ?>
        views
    </span>

    <?php if ($type === 'album'): ?>
    <span>
        <?= number_format((int) ($item['video_count'] ?? 0)) ?>
        video
    </span>
    <?php endif; ?>
</div>

<small>
    <?= am_e((string) $item['content_key']) ?>
</small>

</div>


<div class="seo2-arrow">
    <?= am_icon('arrow', 17) ?>
</div>

</a>

<?php endforeach; ?>

<?php endif; ?>

</section>


</main>

</div>

<?= am_bottom_nav('more') ?>

<script src="/assets/admin-mobile-v1.js?v=1"></script>

</body>
</html>
