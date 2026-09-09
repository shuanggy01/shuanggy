<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';

admin_require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT *
    FROM videos
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$id]);
$video = $stmt->fetch();

if (!$video) {
    http_response_code(404);
    exit('Video tidak ditemukan.');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $videoUrl = trim($_POST['video_url'] ?? '');
    $thumbnailUrl = trim($_POST['thumbnail_url'] ?? '');
    $status = $_POST['status'] ?? '';

    if ($title === '') {
        $error = 'Judul wajib diisi.';
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

        $update = $pdo->prepare("
            UPDATE videos
            SET
                title = ?,
                description = ?,
                video_url = ?,
                thumbnail_url = ?,
                status = ?
            WHERE id = ?
        ");

        $update->execute([
            $title,
            $description ?: null,
            $videoUrl,
            $thumbnailUrl ?: null,
            $status,
            $id,
        ]);

        admin_flash('Video berhasil diperbarui ✅');
        admin_redirect('/admin/');
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Edit Video</title>

<style>
*{box-sizing:border-box}
body{
    background:#0c0c0c;
    color:#fff;
    font-family:Arial;
    margin:0;
}
.box{
    width:min(700px,calc(100% - 24px));
    margin:30px auto;
    background:#171717;
    padding:20px;
    border-radius:14px;
}
label{
    display:block;
    color:#aaa;
    font-size:13px;
    margin:14px 0 6px;
}
input,textarea,select{
    width:100%;
    background:#0d0d0d;
    border:1px solid #333;
    color:#fff;
    padding:12px;
    border-radius:8px;
}
textarea{min-height:120px}
button,a{
    display:inline-block;
    padding:11px 15px;
    margin-top:16px;
    border:0;
    border-radius:8px;
    text-decoration:none;
}
button{
    background:#fff;
    color:#111;
}
a{
    background:#292929;
    color:#fff;
}
.error{
    padding:12px;
    background:#421818;
    color:#ffb5b5;
    border-radius:8px;
}
</style>
</head>

<body>

<div class="box">

<h1>Edit Video</h1>

<?php if ($error): ?>
<div class="error">
    <?= admin_e($error) ?>
</div>
<?php endif; ?>

<form method="post">

<?= admin_csrf_input() ?>

<input
    type="hidden"
    name="id"
    value="<?= (int) $video['id'] ?>"
>

<label>Video Key</label>

<input
    value="<?= admin_e($video['video_key']) ?>"
    disabled
>

<label>Judul</label>

<input
    name="title"
    value="<?= admin_e($video['title']) ?>"
    required
>

<label>URL Video</label>

<input
    type="url"
    name="video_url"
    value="<?= admin_e($video['video_url']) ?>"
    required
>

<label>URL Thumbnail</label>

<input
    type="url"
    name="thumbnail_url"
    value="<?= admin_e($video['thumbnail_url']) ?>"
>

<label>Deskripsi</label>

<textarea name="description"><?= admin_e($video['description']) ?></textarea>

<label>Status</label>

<select name="status">

<?php foreach (['published','draft','hidden'] as $status): ?>

<option
    value="<?= $status ?>"
    <?= $video['status'] === $status ? 'selected' : '' ?>
>
    <?= ucfirst($status) ?>
</option>

<?php endforeach; ?>

</select>

<button type="submit">
    Simpan
</button>

<a href="/admin/">
    Kembali
</a>

</form>

</div>

</body>
</html>
