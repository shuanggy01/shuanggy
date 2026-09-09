<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';

admin_require_login();

/*
|--------------------------------------------------------------------------
| TAMBAH VIDEO
|--------------------------------------------------------------------------
*/

$error = null;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'add_video'
) {
    admin_verify_csrf();

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $videoUrl = trim($_POST['video_url'] ?? '');
    $thumbnailUrl = trim($_POST['thumbnail_url'] ?? '');
    $videoKey = trim($_POST['video_key'] ?? '');
    $status = $_POST['status'] ?? 'published';

    if ($videoKey === '') {
        $videoKey = strtoupper(
            bin2hex(random_bytes(5))
        );
    }

    if (
        !preg_match(
            '/^[A-Za-z0-9_-]{4,20}$/',
            $videoKey
        )
    ) {
        $error = 'Video key tidak valid.';
    } elseif ($title === '') {
        $error = 'Judul wajib diisi.';
    } elseif (mb_strlen($title) > 255) {
        $error = 'Judul terlalu panjang.';
    } elseif (!admin_valid_url($videoUrl)) {
        $error = 'URL video tidak valid.';
    } elseif (
        $thumbnailUrl !== '' &&
        !admin_valid_url($thumbnailUrl)
    ) {
        $error = 'URL thumbnail tidak valid.';
    } elseif (
        !in_array(
            $status,
            ['published', 'draft', 'hidden'],
            true
        )
    ) {
        $error = 'Status tidak valid.';
    }

    if ($error === null) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO videos
                (
                    video_key,
                    title,
                    description,
                    video_url,
                    thumbnail_url,
                    status
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $videoKey,
                $title,
                $description !== '' ? $description : null,
                $videoUrl,
                $thumbnailUrl !== '' ? $thumbnailUrl : null,
                $status,
            ]);

            admin_flash(
                'Video berhasil ditambahkan ✅'
            );

            admin_redirect('/admin/');

        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $error = 'Video key sudah digunakan.';
            } else {
                throw $e;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| STATISTIK
|--------------------------------------------------------------------------
*/

$stats = $pdo->query("
    SELECT
        COUNT(*) AS total_videos,

        SUM(
            CASE
                WHEN status = 'published'
                THEN 1
                ELSE 0
            END
        ) AS published_videos,

        SUM(
            CASE
                WHEN status = 'draft'
                THEN 1
                ELSE 0
            END
        ) AS draft_videos,

        COALESCE(
            SUM(views),
            0
        ) AS total_views

    FROM videos
")->fetch();

$totalAlbums = (int) $pdo->query("
    SELECT COUNT(*)
    FROM collections
")->fetchColumn();

$albumViews = (int) $pdo->query("
    SELECT COALESCE(SUM(views), 0)
    FROM collections
")->fetchColumn();

/*
|--------------------------------------------------------------------------
| SEARCH + FILTER
|--------------------------------------------------------------------------
*/

$q = trim($_GET['q'] ?? '');

$statusFilter =
    $_GET['status'] ?? 'all';

if (
    !in_array(
        $statusFilter,
        [
            'all',
            'published',
            'draft',
            'hidden'
        ],
        true
    )
) {
    $statusFilter = 'all';
}

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "
        (
            title LIKE :search_title
            OR video_key LIKE :search_key
        )
    ";

    $searchValue = '%' . $q . '%';

    $params['search_title'] = $searchValue;
    $params['search_key'] = $searchValue;
}

if ($statusFilter !== 'all') {
    $where[] =
        'status = :status';

    $params['status'] =
        $statusFilter;
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

$page = max(
    1,
    (int) ($_GET['page'] ?? 1)
);

$perPage = 20;

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM videos
    {$whereSql}
");

$countStmt->execute($params);

$totalFiltered =
    (int) $countStmt->fetchColumn();

$totalPages = max(
    1,
    (int) ceil(
        $totalFiltered / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset =
    ($page - 1) * $perPage;

$sql = "
    SELECT
        id,
        video_key,
        title,
        thumbnail_url,
        views,
        status,
        created_at

    FROM videos

    {$whereSql}

    ORDER BY id DESC

    LIMIT :limit
    OFFSET :offset
";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue(
        ':' . $key,
        $value,
        PDO::PARAM_STR
    );
}

$stmt->bindValue(
    ':limit',
    $perPage,
    PDO::PARAM_INT
);

$stmt->bindValue(
    ':offset',
    $offset,
    PDO::PARAM_INT
);

$stmt->execute();

$videos =
    $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| ALBUM TERBARU
|--------------------------------------------------------------------------
*/

$recentAlbums = $pdo->query("
    SELECT
        c.id,
        c.collection_key,
        c.title,
        c.views,
        c.status,
        c.created_at,
        COUNT(cv.video_id) AS video_count

    FROM collections c

    LEFT JOIN collection_videos cv
        ON cv.collection_id = c.id

    GROUP BY
        c.id,
        c.collection_key,
        c.title,
        c.views,
        c.status,
        c.created_at

    ORDER BY c.id DESC

    LIMIT 6
")->fetchAll();

$flash =
    admin_get_flash();

function dashboardUrl(
    int $page,
    string $q,
    string $status
): string {
    return '/admin/?' .
        http_build_query([
            'q' => $q,
            'status' => $status,
            'page' => $page,
        ]);
}
?>
<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
    Dashboard Admin - AsupanLendir
</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #0b0b0b;
    color: #fff;
    font-family: Arial, Helvetica, sans-serif;
}

.container {
    width: min(1250px, calc(100% - 24px));
    margin: auto;
}

header {
    padding: 17px 0;
    border-bottom: 1px solid #252525;
    position: sticky;
    top: 0;
    background: rgba(11,11,11,.95);
    backdrop-filter: blur(10px);
    z-index: 10;
}

.top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.brand {
    font-size: 21px;
    font-weight: 700;
}

.top-actions {
    display: flex;
    gap: 7px;
    align-items: center;
}

main {
    padding: 24px 0 60px;
}

.btn,
button {
    border: 0;
    border-radius: 8px;
    padding: 10px 13px;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.secondary {
    background: #252525;
    color: #fff;
}

.primary {
    background: #fff;
    color: #111;
}

.danger {
    background: #4a1717;
    color: #ffb4b4;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 13px;
    margin-bottom: 22px;
}

.stat {
    background: #171717;
    border: 1px solid #292929;
    border-radius: 14px;
    padding: 18px;
}

.stat-label {
    color: #888;
    font-size: 13px;
}

.stat-number {
    font-size: 29px;
    font-weight: 700;
    margin-top: 8px;
}

.stat-sub {
    color: #777;
    font-size: 12px;
    margin-top: 7px;
}

.card {
    background: #171717;
    border: 1px solid #292929;
    border-radius: 14px;
    padding: 18px;
    margin-bottom: 22px;
}

.card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 17px;
}

h2 {
    margin: 0;
    font-size: 18px;
}

.quick-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.flash {
    background: #12351f;
    color: #9effbc;
    padding: 12px;
    border-radius: 9px;
    margin-bottom: 18px;
}

.error {
    background: #411818;
    color: #ffb5b5;
    padding: 12px;
    border-radius: 9px;
    margin-bottom: 18px;
}

label {
    display: block;
    margin: 12px 0 6px;
    color: #aaa;
    font-size: 13px;
}

input,
textarea,
select {
    width: 100%;
    background: #0d0d0d;
    color: #fff;
    border: 1px solid #333;
    border-radius: 8px;
    padding: 11px;
    font-size: 14px;
}

textarea {
    min-height: 90px;
    resize: vertical;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.filter {
    display: grid;
    grid-template-columns: 1fr 180px auto;
    gap: 9px;
    margin-bottom: 18px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    padding: 12px 9px;
    border-bottom: 1px solid #292929;
    text-align: left;
    font-size: 13px;
    vertical-align: middle;
}

th {
    color: #888;
}

.video-info {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 220px;
}

.thumb {
    width: 80px;
    aspect-ratio: 16 / 9;
    overflow: hidden;
    border-radius: 7px;
    background: #222;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #777;
    flex: 0 0 auto;
}

.thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.video-title {
    font-weight: 700;
}

.small {
    color: #777;
    font-size: 11px;
    margin-top: 4px;
}

.status {
    display: inline-block;
    padding: 5px 8px;
    background: #292929;
    border-radius: 20px;
    font-size: 11px;
}

.actions {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

.pagination {
    margin-top: 20px;
    display: flex;
    gap: 6px;
    justify-content: center;
    flex-wrap: wrap;
}

.pagination a,
.pagination span {
    min-width: 38px;
    text-align: center;
    padding: 9px;
    border-radius: 7px;
    background: #252525;
    color: #aaa;
    text-decoration: none;
}

.pagination .active {
    background: #fff;
    color: #111;
}

.album-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.album {
    border: 1px solid #292929;
    border-radius: 11px;
    padding: 14px;
    background: #111;
}

.album-title {
    font-weight: 700;
}

.album-meta {
    margin-top: 6px;
    color: #777;
    font-size: 12px;
}

.album-actions {
    margin-top: 12px;
    display: flex;
    gap: 6px;
}

.empty {
    color: #777;
    padding: 25px 0;
    text-align: center;
}

@media (max-width: 800px) {

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .album-grid {
        grid-template-columns: repeat(2, 1fr);
    }

}

@media (max-width: 600px) {

    .container {
        width: calc(100% - 18px);
    }

    .brand {
        font-size: 18px;
    }

    .top .site-link {
        display: none;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .filter {
        grid-template-columns: 1fr;
    }

    .album-grid {
        grid-template-columns: 1fr;
    }

    .stat-number {
        font-size: 24px;
    }

}

</style>

</head>

<body>

<header>

<div class="container top">

    <div class="brand">
        AsupanLendir Admin
    </div>

    <div class="top-actions">

        <a
            class="btn secondary site-link"
            href="/"
            target="_blank"
        >
            🌐 Web
        </a>

        <a
            class="btn secondary"
            href="/admin/analytics.php"
        >
            📊 Analytics
        </a>
<a class="btn" href="/admin/ads-mobile.php">💰 Ads</a>
<a class="btn" href="/admin/settings.php">⚙️ Settings</a>
<a class="btn" href="/admin/mirrors.php">🪞 Mirrors</a>

        <a
            class="btn secondary"
            href="/admin/albums.php"
        >
            📁 Album
        </a>

        <form
            method="post"
            action="/admin/logout.php"
        >
            <?= admin_csrf_input() ?>

            <button
                class="secondary"
                type="submit"
            >
                Logout
            </button>
        </form>

    </div>

</div>

</header>

<main class="container">

<?php if ($flash): ?>

<div class="flash">
    <?= admin_e($flash) ?>
</div>

<?php endif; ?>

<?php if ($error): ?>

<div class="error">
    <?= admin_e($error) ?>
</div>

<?php endif; ?>


<!-- STATISTIK -->

<div class="stats-grid">

<div class="stat">

    <div class="stat-label">
        🎬 Total Video
    </div>

    <div class="stat-number">
        <?= number_format(
            (int) $stats['total_videos']
        ) ?>
    </div>

    <div class="stat-sub">
        <?= number_format(
            (int) $stats['published_videos']
        ) ?> published
    </div>

</div>


<div class="stat">

    <div class="stat-label">
        📁 Total Album
    </div>

    <div class="stat-number">
        <?= number_format($totalAlbums) ?>
    </div>

    <div class="stat-sub">
        <?= number_format($albumViews) ?>
        album views
    </div>

</div>


<div class="stat">

    <div class="stat-label">
        👁 Total Video Views
    </div>

    <div class="stat-number">
        <?= number_format(
            (int) $stats['total_views']
        ) ?>
    </div>

    <div class="stat-sub">
        Semua video
    </div>

</div>


<div class="stat">

    <div class="stat-label">
        📝 Draft
    </div>

    <div class="stat-number">
        <?= number_format(
            (int) $stats['draft_videos']
        ) ?>
    </div>

    <div class="stat-sub">
        Belum dipublikasikan
    </div>

</div>

</div>


<!-- QUICK ACTION -->

<div class="card">

<div class="card-head">

    <h2>Quick Actions</h2>

</div>

<div class="quick-actions">

    <a
        class="btn primary"
        href="#tambah-video"
    >
        + Video
    </a>

    <a
        class="btn secondary"
        href="/admin/analytics.php"
    >
        📊 Analytics
    </a>

    <a
        class="btn secondary"
        href="/admin/albums.php"
    >
        📁 Kelola Album
    </a>

    <a
        class="btn secondary"
        href="/?sort=new"
        target="_blank"
    >
        🌐 Homepage
    </a>

    <a
        class="btn secondary"
        href="/?sort=trending"
        target="_blank"
    >
        🔥 Trending
    </a>

</div>

</div>


<!-- TAMBAH VIDEO -->

<div
    class="card"
    id="tambah-video"
>

<div class="card-head">

    <h2>Tambah Video Manual</h2>

    <span class="small">
        Telegram Bot tetap jadi metode utama
    </span>

</div>

<form method="post">

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="action"
    value="add_video"
>

<div class="form-grid">

<div>

<label>
    Video Key
</label>

<input
    name="video_key"
    maxlength="20"
    placeholder="Kosong = otomatis"
>

</div>

<div>

<label>
    Status
</label>

<select name="status">

<option value="published">
    Published
</option>

<option value="draft">
    Draft
</option>

<option value="hidden">
    Hidden
</option>

</select>

</div>

</div>


<label>Judul</label>

<input
    name="title"
    maxlength="255"
    required
>


<div class="form-grid">

<div>

<label>
    URL Video
</label>

<input
    type="url"
    name="video_url"
    placeholder="https://..."
    required
>

</div>

<div>

<label>
    URL Thumbnail
</label>

<input
    type="url"
    name="thumbnail_url"
    placeholder="Opsional"
>

</div>

</div>


<label>
    Deskripsi
</label>

<textarea name="description"></textarea>

<br>

<button
    class="primary"
    type="submit"
>
    + Tambahkan Video
</button>

</form>

</div>


<!-- VIDEO -->

<div class="card">

<div class="card-head">

    <h2>
        Video
        (<?= number_format($totalFiltered) ?>)
    </h2>

</div>


<form
    method="get"
    class="filter"
>

<input
    type="search"
    name="q"
    value="<?= admin_e($q) ?>"
    placeholder="Cari judul / video key..."
>

<select name="status">

<option
    value="all"
    <?= $statusFilter === 'all'
        ? 'selected'
        : '' ?>
>
    Semua Status
</option>

<option
    value="published"
    <?= $statusFilter === 'published'
        ? 'selected'
        : '' ?>
>
    Published
</option>

<option
    value="draft"
    <?= $statusFilter === 'draft'
        ? 'selected'
        : '' ?>
>
    Draft
</option>

<option
    value="hidden"
    <?= $statusFilter === 'hidden'
        ? 'selected'
        : '' ?>
>
    Hidden
</option>

</select>

<button
    class="primary"
    type="submit"
>
    Cari
</button>

</form>


<?php if ($videos): ?>

<div class="table-wrap">

<table>

<thead>

<tr>

    <th>Video</th>
    <th>Views</th>
    <th>Status</th>
    <th>Dibuat</th>
    <th>Aksi</th>

</tr>

</thead>

<tbody>

<?php foreach ($videos as $video): ?>

<tr>

<td>

<div class="video-info">

<div class="thumb">

<?php if (!empty($video['thumbnail_url'])): ?>

<img
    src="<?= admin_e($video['thumbnail_url']) ?>"
    loading="lazy"
>

<?php else: ?>

▶

<?php endif; ?>

</div>

<div>

<div class="video-title">
    <?= admin_e($video['title']) ?>
</div>

<div class="small">
    <?= admin_e($video['video_key']) ?>
</div>

</div>

</div>

</td>


<td>

<?= number_format(
    (int) $video['views']
) ?>

</td>


<td>

<span class="status">
    <?= admin_e($video['status']) ?>
</span>

</td>


<td>

<div class="small">

<?= admin_e(
    date(
        'd M Y H:i',
        strtotime($video['created_at'])
    )
) ?>

</div>

</td>


<td>

<div class="actions">

<a
    class="btn secondary"
    href="/v/<?= urlencode($video['video_key']) ?>"
    target="_blank"
>
    Lihat
</a>

<a
    class="btn secondary"
    href="/admin/edit.php?id=<?= (int) $video['id'] ?>"
>
    Edit
</a>

<form
    method="post"
    action="/admin/delete.php"
    onsubmit="return confirm('Hapus video ini?')"
>

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="id"
    value="<?= (int) $video['id'] ?>"
>

<button
    class="danger"
    type="submit"
>
    Hapus
</button>

</form>

</div>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>


<?php if ($totalPages > 1): ?>

<div class="pagination">

<?php
$startPage =
    max(1, $page - 2);

$endPage =
    min(
        $totalPages,
        $page + 2
    );
?>


<?php if ($page > 1): ?>

<a href="<?= admin_e(
    dashboardUrl(
        $page - 1,
        $q,
        $statusFilter
    )
) ?>">
    ‹
</a>

<?php endif; ?>


<?php for (
    $i = $startPage;
    $i <= $endPage;
    $i++
): ?>

<?php if ($i === $page): ?>

<span class="active">
    <?= $i ?>
</span>

<?php else: ?>

<a href="<?= admin_e(
    dashboardUrl(
        $i,
        $q,
        $statusFilter
    )
) ?>">
    <?= $i ?>
</a>

<?php endif; ?>

<?php endfor; ?>


<?php if ($page < $totalPages): ?>

<a href="<?= admin_e(
    dashboardUrl(
        $page + 1,
        $q,
        $statusFilter
    )
) ?>">
    ›
</a>

<?php endif; ?>

</div>

<?php endif; ?>


<?php else: ?>

<div class="empty">
    Tidak ada video yang cocok.
</div>

<?php endif; ?>

</div>


<!-- ALBUM TERBARU -->

<div class="card">

<div class="card-head">

    <h2>
        Album Terbaru
    </h2>

    <a
        class="btn secondary"
        href="/admin/albums.php"
    >
        Semua Album →
    </a>

</div>


<?php if ($recentAlbums): ?>

<div class="album-grid">

<?php foreach ($recentAlbums as $album): ?>

<div class="album">

<div class="album-title">

<?= admin_e($album['title']) ?>

</div>

<div class="album-meta">

<?= number_format(
    (int) $album['video_count']
) ?> video

·

<?= number_format(
    (int) $album['views']
) ?> views

·

<?= admin_e($album['status']) ?>

</div>

<div class="album-actions">

<a
    class="btn secondary"
    href="/c/<?= urlencode($album['collection_key']) ?>"
    target="_blank"
>
    Lihat
</a>

<a
    class="btn secondary"
    href="/admin/album-edit.php?id=<?= (int) $album['id'] ?>"
>
    Edit
</a>

</div>

</div>

<?php endforeach; ?>

</div>

<?php else: ?>

<div class="empty">
    Belum ada album.
</div>

<?php endif; ?>

</div>

</main>

</body>
</html>
