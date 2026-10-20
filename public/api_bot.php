<?php
require __DIR__.'/../app/core.php';require ROOT.'/app/posts.php';
if($_SERVER['REQUEST_METHOD']!=='POST')botFail('Gunakan POST.',405);
$accessFile=ROOT.'/app/bot-access.php';$access=is_file($accessFile)?require $accessFile:[];
$expected=(string)($access['key']??'');$given=(string)($_SERVER['HTTP_X_BOT_KEY']??$_POST['key']??'');
// Replies carry both our fields and the legacy ones the Python bot reads ({status:'sukses', pesan}).
function botOut(array $v,int $code=200): never {jsonOut($v+['status'=>'sukses','pesan'=>(string)($v['pesan']??(!empty($v['duplicate'])?'Sudah ada (duplikat).':'Tersimpan.'))],$code);}
function botFail(string $m,int $code=400): never {jsonOut(['status'=>'gagal','pesan'=>$m,'error'=>$m],$code);}
if(strlen($expected)<32||!hash_equals($expected,$given))botFail('Key ditolak. Kunci API bot tidak cocok.',403);
// This endpoint uses its own key, not browser login or CSRF. No arbitrary server-side URL fetching.
session_write_close();
try {
    if((string)($_POST['action']??'')==='ping')botOut(['ok'=>true,'pesan'=>'Key diterima.']);
    $type=(string)($_POST['tipe']??'video');
    // folder_add: id_video = existing folder ID, link_asli = comma-separated post IDs to append.
    if($type==='folder_add')botOut(appendFolder((string)($_POST['id_video']??''),explode(',',(string)($_POST['link_asli']??''))));
    $botId=(string)($_POST['id_video']??'');$title=(string)($_POST['judul']??'');$value=(string)($_POST['link_asli']??'');
    // Opt-in (short_id=1): a long ID becomes a short deterministic ID and the reply carries the real one.
    // Off by default, because bots that build links from their own ID would print a wrong link.
    $short=(string)($_POST['short_id']??'')==='1'&&strlen($botId)>8&&preg_match('/^[a-zA-Z0-9_-]{4,40}$/',$botId);
    botOut($short?registerShort($botId,$title,$type,$value):registerPost($botId,$title,$type,$value));
}catch(Throwable $e){botFail($e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'Gagal menyimpan posting. Periksa database/izin folder var.',400);}
