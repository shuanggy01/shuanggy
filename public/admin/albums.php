<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';

admin_require_login();

$flash = admin_get_flash();

$albums = $pdo->query("
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
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Album Admin - AsupanLendir</title>

<style>
*{box-sizing:border-box}

body{
    margin:0;
    background:#0c0c0c;
    color:#fff;
    font-family:Arial,sans-serif;
}

.container{
    width:min(1100px,calc(100% - 24px));
    margin:auto;
}

header{
    border-bottom:1px solid #252525;
    padding:18px 0;
}

.top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}

h1{
    margin:0;
    font-size:22px;
}

main{
    padding:24px 0;
}

.card{
    background:#171717;
    border:1px solid #292929;
    border-radius:14px;
    padding:18px;
}

.flash{
    padding:12px;
    margin-bottom:18px;
    background:#12351f;
    color:#a5ffbf;
    border-radius:9px;
}

table{
    width:100%;
    border-collapse:collapse;
}

th,
td{
    padding:13px 9px;
    border-bottom:1px solid #282828;
    text-align:left;
    font-size:14px;
}

th{
    color:#888;
}

.small{
    color:#888;
    font-size:12px;
    margin-top:4px;
}

.badge{
    display:inline-block;
    background:#292929;
    border-radius:20px;
    padding:5px 9px;
    font-size:12px;
}

.actions{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.btn,
button{
    display:inline-block;
    padding:9px 12px;
    border:0;
    border-radius:8px;
    text-decoration:none;
    font-size:13px;
    cursor:pointer;
}

.secondary{
    color:#fff;
    background:#292929;
}

.danger{
    color:#ffb4b4;
    background:#4a1717;
}

.table-wrap{
    overflow-x:auto;
}

.empty{
    padding:40px;
    text-align:center;
    color:#777;
}
</style>
</head>

<body>

<header>
<div class="container top">

    <h1>📁 Album</h1>

    <a class="btn secondary" href="/admin/">
        ← Dashboard
    </a>

</div>
</header>

<main class="container">

<?php if ($flash): ?>

<div class="flash">
    <?= admin_e($flash) ?>
</div>

<?php endif; ?>

<div class="card">

<?php if ($albums): ?>

<div class="table-wrap">

<table>

<thead>
<tr>
    <th>Album</th>
    <th>Video</th>
    <th>Views</th>
    <th>Status</th>
    <th>Aksi</th>
</tr>
</thead>

<tbody>

<?php foreach ($albums as $album): ?>

<tr>

<td>

    <strong>
        <?= admin_e($album['title']) ?>
    </strong>

    <div class="small">
        <?= admin_e($album['collection_key']) ?>
    </div>

</td>

<td>
    <?= number_format((int) $album['video_count']) ?>
</td>

<td>
    <?= number_format((int) $album['views']) ?>
</td>

<td>

<span class="badge">
    <?= admin_e($album['status']) ?>
</span>

</td>

<td>

<div class="actions">

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

    <form
        method="post"
        action="/admin/album-delete.php"
        onsubmit="return confirm('Hapus album ini? Video asli TIDAK ikut terhapus.')"
    >

        <?= admin_csrf_input() ?>

        <input
            type="hidden"
            name="id"
            value="<?= (int) $album['id'] ?>"
        >

        <button class="danger" type="submit">
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

<?php else: ?>

<div class="empty">
    Belum ada album.
</div>

<?php endif; ?>

</div>

</main>

</body>
</html>
