<?php
require __DIR__.'/../app/core.php';
require ROOT.'/app/R2.php';
require ROOT.'/app/posts.php';
if($_SERVER['REQUEST_METHOD']!=='POST')fail('Gunakan POST.',405);
csrf();
$input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))fail('Data tidak valid.');
$action=(string)($input['action']??'');
try {
    if($action==='view') {
        $id=(string)($input['id']??'');if(!findVideo($id))fail('Video tidak ditemukan.',404);
        if(time()-(int)($_SESSION['view_'.$id]??0)>1800){db()->prepare('UPDATE videos SET views=views+1 WHERE id=?')->execute([$id]);$_SESSION['view_'.$id]=time();}
        jsonOut(['ok'=>true]);
    }
    requireAdmin();
    switch($action) {
    case 'settings':
        $site=settings();$site['site_name']=substr(trim((string)($input['site_name']??'Layar')),0,80)?:'Layar';$site['vip_url']=validUrl((string)($input['vip_url']??''));$site['download']=!empty($input['download']);
        foreach(['pretty_links','media_proxy'] as $flag)if(array_key_exists($flag,$input))$site[$flag]=!empty($input[$flag]);
        $ads=validateAds($input['ads']??[],$site['ads']);
        $site['ads']=$ads;putSetting('site',$site);jsonOut(['ok'=>true]);
    case 'r2_save':
        $r=setting('r2',[]);
        $r['account']=trim((string)($input['account']??''));$r['bucket']=trim((string)($input['bucket']??''));$r['public']=rtrim(validUrl((string)($input['public']??''),false),'/');
        if(parse_url($r['public'],PHP_URL_QUERY)!==null||parse_url($r['public'],PHP_URL_FRAGMENT)!==null)throw new RuntimeException('Domain publik tidak boleh berisi query atau fragmen.');
        foreach(['access','secret'] as $k)if(trim((string)($input[$k]??''))!=='')$r[$k]=encryptSecret(trim($input[$k]));
        $plain=$r;foreach(['access','secret'] as $k)$plain[$k]=decryptSecret($r[$k]??'');new R2($plain);putSetting('r2',$r);jsonOut(['ok'=>true]);
    case 'r2_test':
        (new R2(r2Config()))->request('GET','',['list-type'=>'2','max-keys'=>'1']);jsonOut(['ok'=>true,'message'=>'Koneksi dan izin baca bucket berhasil. Posting link tidak memerlukan upload browser.']);
    case 'publish_url':
        $id=bin2hex(random_bytes(6));$title=(string)($input['title']??'');
        $video=(string)($input['video_url']??'');$thumb=(string)($input['thumb_url']??'');
        if(str_contains($video,'||')||str_contains($thumb,'||'))throw new RuntimeException('URL tidak valid.');
        jsonOut(registerPost($id,$title,'video',$video.'||'.$thumb));
    case 'video_edit':
        $title=trim((string)($input['title']??''));if($title===''||strlen($title)>600)throw new RuntimeException('Judul tidak valid.');db()->prepare('UPDATE videos SET title=? WHERE id=?')->execute([$title,(string)($input['id']??'')]);jsonOut(['ok'=>true]);
    case 'video_delete':
        // Remove publication only. Objects are retained deliberately; no silent destructive deletion.
        db()->prepare('DELETE FROM videos WHERE id=?')->execute([(string)($input['id']??'')]);jsonOut(['ok'=>true]);
    case 'password':
        $q=db()->prepare('SELECT * FROM admins WHERE id=?');$q->execute([$_SESSION['admin']]);$admin=$q->fetch();
        if(!$admin||!password_verify((string)($input['current']??''),$admin['password']))throw new RuntimeException('Password lama tidak cocok.');$password=(string)($input['password']??'');if(strlen($password)<12||strlen($password)>200)throw new RuntimeException('Password baru harus 12–200 karakter.');
        db()->prepare('UPDATE admins SET password=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$admin['id']]);session_regenerate_id(true);jsonOut(['ok'=>true]);
    default: fail('Aksi tidak dikenal.',404);
    }
} catch(Throwable $e) {
    if(db()->inTransaction())db()->rollBack();
    if($e instanceof PDOException){error_log('Database operation failed: '.$e->getCode());fail('Database sedang bermasalah. Periksa izin folder data. ',500);}
    fail($e instanceof RuntimeException?$e->getMessage():'Proses gagal. Coba lagi.',400);
}
