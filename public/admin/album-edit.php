<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';

admin_require_login();

$id = max(
    0,
    (int) ($_GET['id'] ?? $_POST['id'] ?? 0)
);

function loadAlbum(PDO $pdo, int $id): array|false
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM collections
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);

    return $stmt->fetch();
}

$album = loadAlbum($pdo, $id);

if (!$album) {
    http_response_code(404);
    exit('Album tidak ditemukan.');
}

$error = null;

/*
|--------------------------------------------------------------------------
| POST ACTION
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    admin_verify_csrf();

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | UPDATE ALBUM
    |--------------------------------------------------------------------------
    */

    if ($action === 'save_album') {

        $title = trim(
            $_POST['title'] ?? ''
        );

        $status =
            $_POST['status']
            ?? 'published';

        if ($title === '') {

            $error =
                'Judul album wajib diisi.';

        } elseif (
            mb_strlen($title) > 255
        ) {

            $error =
                'Judul album terlalu panjang.';

        } elseif (
            !in_array(
                $status,
                [
                    'published',
                    'draft',
                    'hidden'
                ],
                true
            )
        ) {

            $error =
                'Status tidak valid.';
        }

        if ($error === null) {

            $stmt = $pdo->prepare("
                UPDATE collections
                SET
                    title = ?,
                    status = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $title,
                $status,
                $id
            ]);

            admin_flash(
                'Album berhasil diperbarui ✅'
            );

            admin_redirect(
                '/admin/album-edit.php?id=' .
                $id
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE POSITION
    |--------------------------------------------------------------------------
    */

    elseif (
        $action === 'update_position'
    ) {

        $videoId =
            (int) (
                $_POST['video_id']
                ?? 0
            );

        $position = max(
            0,
            (int) (
                $_POST['position']
                ?? 0
            )
        );

        $stmt = $pdo->prepare("
            UPDATE collection_videos
            SET position = ?
            WHERE collection_id = ?
              AND video_id = ?
        ");

        $stmt->execute([
            $position,
            $id,
            $videoId
        ]);

        admin_flash(
            'Urutan video diperbarui ✅'
        );

        admin_redirect(
            '/admin/album-edit.php?id=' .
            $id
        );
    }

    /*
    |--------------------------------------------------------------------------
    | REMOVE VIDEO FROM ALBUM
    |--------------------------------------------------------------------------
    */

    elseif (
        $action === 'remove_video'
    ) {

        $videoId =
            (int) (
                $_POST['video_id']
                ?? 0
            );

        $stmt = $pdo->prepare("
            DELETE FROM collection_videos
            WHERE collection_id = ?
              AND video_id = ?
        ");

        $stmt->execute([
            $id,
            $videoId
        ]);

        admin_flash(
            'Video dilepas dari album. Video asli tetap aman ✅'
        );

        admin_redirect(
            '/admin/album-edit.php?id=' .
            $id
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ADD VIDEO BY KEY
    |--------------------------------------------------------------------------
    */

    elseif (
        $action === 'add_video'
    ) {

        $videoKey =
            trim(
                $_POST['video_key']
                ?? ''
            );

        $stmt = $pdo->prepare("
            SELECT id, title
            FROM videos
            WHERE video_key = ?
            LIMIT 1
        ");

        $stmt->execute([
            $videoKey
        ]);

        $video =
            $stmt->fetch();

        if (!$video) {

            $error =
                'Video key tidak ditemukan.';

        } else {

            $positionStmt =
                $pdo->prepare("
                    SELECT
                        COALESCE(
                            MAX(position),
                            -1
                        ) + 1
                    FROM collection_videos
                    WHERE collection_id = ?
                ");

            $positionStmt->execute([
                $id
            ]);

            $nextPosition =
                (int)
                $positionStmt
                    ->fetchColumn();

            $insert =
                $pdo->prepare("
                    INSERT IGNORE INTO collection_videos
                    (
                        collection_id,
                        video_id,
                        position
                    )
                    VALUES (?, ?, ?)
                ");

            $insert->execute([
                $id,
                $video['id'],
                $nextPosition
            ]);

            if (
                $insert->rowCount() === 0
            ) {

                $error =
                    'Video tersebut sudah ada di album.';

            } else {

                admin_flash(
                    'Video berhasil ditambahkan ke album ✅'
                );

                admin_redirect(
                    '/admin/album-edit.php?id=' .
                    $id
                );
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| RELOAD ALBUM
|--------------------------------------------------------------------------
*/

$album =
    loadAlbum(
        $pdo,
        $id
    );

/*
|--------------------------------------------------------------------------
| VIDEO DALAM ALBUM
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        v.id,
        v.video_key,
        v.title,
        v.thumbnail_url,
        v.views,
        v.status,
        cv.position

    FROM collection_videos cv

    JOIN videos v
        ON v.id = cv.video_id

    WHERE cv.collection_id = ?

    ORDER BY
        cv.position ASC,
        v.id ASC
");

$stmt->execute([$id]);

$videos =
    $stmt->fetchAll();

$flash =
    admin_get_flash();
?>
<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>Edit Album - AsupanLendir</title>

<style>
*{box-sizing:border-box}

body{
    margin:0;
    background:#0c0c0c;
    color:#fff;
    font-family:Arial,sans-serif;
}

.container{
    width:min(1000px,calc(100% - 24px));
    margin:auto;
}

header{
    border-bottom:1px solid #252525;
    padding:18px 0;
}

.top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
}

h1{
    margin:0;
    font-size:21px;
}

main{
    padding:24px 0 50px;
}

.card{
    background:#171717;
    border:1px solid #292929;
    border-radius:14px;
    padding:18px;
    margin-bottom:20px;
}

h2{
    margin:0 0 18px;
    font-size:18px;
}

label{
    display:block;
    color:#aaa;
    font-size:13px;
    margin:13px 0 6px;
}

input,
select{
    width:100%;
    background:#0d0d0d;
    color:#fff;
    border:1px solid #333;
    border-radius:8px;
    padding:12px;
    font-size:15px;
}

button,
.btn{
    border:0;
    border-radius:8px;
    padding:10px 13px;
    font-size:13px;
    text-decoration:none;
    cursor:pointer;
    display:inline-block;
}

.primary{
    background:#fff;
    color:#111;
}

.secondary{
    background:#292929;
    color:#fff;
}

.danger{
    background:#4a1717;
    color:#ffb5b5;
}

.flash{
    background:#12351f;
    color:#a3ffbe;
    padding:12px;
    border-radius:9px;
    margin-bottom:18px;
}

.error{
    background:#421818;
    color:#ffb4b4;
    padding:12px;
    border-radius:9px;
    margin-bottom:18px;
}

.meta{
    color:#888;
    font-size:13px;
    margin-top:5px;
}

.video{
    display:grid;
    grid-template-columns:100px 1fr 100px auto;
    gap:12px;
    align-items:center;

    padding:13px 0;

    border-bottom:1px solid #292929;
}

.thumb{
    width:100px;
    aspect-ratio:16/9;
    background:#222;
    border-radius:8px;
    overflow:hidden;

    display:flex;
    justify-content:center;
    align-items:center;
}

.thumb img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.position-form{
    display:flex;
    align-items:center;
    gap:6px;
}

.position-form input{
    width:62px;
    padding:8px;
}

.actions{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.add-row{
    display:flex;
    gap:8px;
}

.add-row input{
    flex:1;
}

.add-row button{
    white-space:nowrap;
}

@media(max-width:700px){

    .video{
        grid-template-columns:90px 1fr;
    }

    .position-area,
    .video-actions{
        grid-column:2;
    }

}
</style>

</head>

<body>

<header>

<div class="container top">

    <h1>
        Edit Album
    </h1>

    <div>

        <a
            class="btn secondary"
            href="/c/<?= urlencode($album['collection_key']) ?>"
            target="_blank"
        >
            Lihat
        </a>

        <a
            class="btn secondary"
            href="/admin/albums.php"
        >
            ← Album
        </a>

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


<div class="card">

<h2>Informasi Album</h2>

<form method="post">

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="id"
    value="<?= (int) $album['id'] ?>"
>

<input
    type="hidden"
    name="action"
    value="save_album"
>

<label>Album Key</label>

<input
    value="<?= admin_e($album['collection_key']) ?>"
    disabled
>

<label>Judul Album</label>

<input
    name="title"
    maxlength="255"
    value="<?= admin_e($album['title']) ?>"
    required
>

<label>Status</label>

<select name="status">

<?php
foreach (
    [
        'published',
        'draft',
        'hidden'
    ]
    as $status
):
?>

<option
    value="<?= $status ?>"
    <?= $album['status'] === $status ? 'selected' : '' ?>
>
    <?= ucfirst($status) ?>
</option>

<?php endforeach; ?>

</select>

<br><br>

<button
    class="primary"
    type="submit"
>
    Simpan Album
</button>

</form>

</div>


<div class="card">

<h2>
    Tambah Video ke Album
</h2>

<form
    method="post"
    class="add-row"
>

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="id"
    value="<?= (int) $album['id'] ?>"
>

<input
    type="hidden"
    name="action"
    value="add_video"
>

<input
    name="video_key"
    placeholder="Masukkan Video Key, contoh: kmxb6zzlx"
    required
>

<button
    class="primary"
    type="submit"
>
    + Tambah
</button>

</form>

</div>


<div class="card">

<h2>
    Video Dalam Album
    (<?= count($videos) ?>)
</h2>

<?php if (!$videos): ?>

<div class="meta">
    Album ini belum berisi video.
</div>

<?php endif; ?>


<?php foreach ($videos as $video): ?>

<div class="video">

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

<strong>
    <?= admin_e($video['title']) ?>
</strong>

<div class="meta">

    <?= admin_e($video['video_key']) ?>

    ·

    <?= number_format(
        (int) $video['views']
    ) ?> views

    ·

    <?= admin_e($video['status']) ?>

</div>

</div>


<div class="position-area">

<form
    method="post"
    class="position-form"
>

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="id"
    value="<?= (int) $album['id'] ?>"
>

<input
    type="hidden"
    name="action"
    value="update_position"
>

<input
    type="hidden"
    name="video_id"
    value="<?= (int) $video['id'] ?>"
>

<input
    type="number"
    min="0"
    name="position"
    value="<?= (int) $video['position'] ?>"
>

<button
    class="secondary"
    type="submit"
>
    Urut
</button>

</form>

</div>


<div class="video-actions actions">

<a
    class="btn secondary"
    href="/v/<?= urlencode($video['video_key']) ?>"
    target="_blank"
>
    Lihat
</a>


<form
    method="post"
    onsubmit="return confirm('Lepas video dari album? Video aslinya tidak dihapus.')"
>

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="id"
    value="<?= (int) $album['id'] ?>"
>

<input
    type="hidden"
    name="action"
    value="remove_video"
>

<input
    type="hidden"
    name="video_id"
    value="<?= (int) $video['id'] ?>"
>

<button
    class="danger"
    type="submit"
>
    Lepas
</button>

</form>

</div>

</div>

<?php endforeach; ?>

</div>

</main>

</body>
</html>
