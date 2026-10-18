<?php
require __DIR__.'/../app/core.php';require ROOT.'/app/posts.php';
if($_SERVER['REQUEST_METHOD']!=='POST')fail('Gunakan POST.',405);
$accessFile=ROOT.'/app/bot-access.php';$access=is_file($accessFile)?require $accessFile:[];
$expected=(string)($access['key']??'');$given=(string)($_SERVER['HTTP_X_BOT_KEY']??$_POST['key']??'');
if(strlen($expected)<32||!hash_equals($expected,$given))fail('Kunci API bot tidak cocok.',403);
// This endpoint uses its own key, not browser login or CSRF. No arbitrary server-side URL fetching.
session_write_close();
try {
    $type=(string)($_POST['tipe']??'video');
    // folder_add: id_video = existing folder ID, link_asli = comma-separated post IDs to append.
    if($type==='folder_add')jsonOut(appendFolder((string)($_POST['id_video']??''),explode(',',(string)($_POST['link_asli']??''))));
    $out=registerPost((string)($_POST['id_video']??''),(string)($_POST['judul']??''),$type,(string)($_POST['link_asli']??''));jsonOut($out);
}catch(Throwable $e){fail($e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'Gagal menyimpan posting. Periksa database/izin folder var.',400);}
