<?php
require __DIR__.'/../app/core.php';
require ROOT.'/app/R2.php';
require ROOT.'/app/posts.php';
if($_SERVER['REQUEST_METHOD']!=='POST')fail('Gunakan POST.',405);
csrf();
// Thumbnail uploads arrive as multipart form data; everything else is JSON.
$input=str_starts_with((string)($_SERVER['CONTENT_TYPE']??''),'multipart/form-data')?$_POST:json_decode(file_get_contents('php://input'),true);if(!is_array($input))fail('Data tidak valid.');
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
        foreach(['pretty_links','media_proxy','media_ip_lock'] as $flag)if(array_key_exists($flag,$input))$site[$flag]=!empty($input[$flag]);
        if(array_key_exists('video_proxy',$input)){
            $proxy=trim((string)$input['video_proxy']);if($proxy!==''&&!preg_match('~^https://[a-z0-9.-]+(:\d+)?/[^\s?#]*$~i',$proxy))throw new RuntimeException('Proxy video harus berupa https://domain/path/, misal https://v.videy.ca/play/');
            $site['video_proxy']=$proxy;
            $hosts=array_filter(array_map(fn($h)=>strtolower(trim($h)),explode(',',(string)($input['video_proxy_hosts']??''))));
            foreach($hosts as $h)if(!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/',$h))throw new RuntimeException('Domain sumber tidak valid: '.$h);
            $site['video_proxy_hosts']=implode(',',$hosts);
        }
        $ads=validateAds($input['ads']??[],$site['ads']);
        $site['ads']=$ads;putSetting('site',$site);jsonOut(['ok'=>true]);
    case 'media_key':
        // New random MEDIA_KEY; the same value must be saved in the Worker variables.
        $key=bin2hex(random_bytes(32));putSetting('media_key',encryptSecret($key));jsonOut(['ok'=>true,'key'=>$key]);
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
        $out=registerPost($id,$title,'video',$video.'||'.$thumb);
        $folderId=(string)($input['folder']??'');
        if($folderId!==''){try{appendFolder($folderId,[$id]);}catch(RuntimeException $e){throw new RuntimeException('Video terbit, tetapi gagal masuk folder: '.$e->getMessage());}}
        jsonOut($out);
    case 'folder_save':
        $items=$input['items']??[];if(!is_array($items))throw new RuntimeException('Isi folder tidak valid.');
        $folderId=(string)($input['id']??'');$title=(string)($input['title']??'');
        if($folderId==='')jsonOut(registerPost(bin2hex(random_bytes(6)),$title,'folder',implode(',',array_map('strval',$items))));
        updateFolder($folderId,$title,liveItems($items),true);jsonOut(['ok'=>true,'id'=>$folderId]);
    case 'items_reorder':
        $order=$input['order']??[];if(!is_array($order))throw new RuntimeException('Urutan tidak valid.');
        reorderFolder((string)($input['id']??''),$order);jsonOut(['ok'=>true]);
    case 'folder_delete':
        // Removes the folder only; its videos stay published.
        initPostTables();db()->prepare('DELETE FROM collections WHERE id=?')->execute([(string)($input['id']??'')]);jsonOut(['ok'=>true]);
    case 'video_edit':
        $title=trim((string)($input['title']??''));if($title===''||strlen($title)>600)throw new RuntimeException('Judul tidak valid.');$q=db()->prepare('UPDATE videos SET title=? WHERE id=?');$q->execute([$title,(string)($input['id']??'')]);if(!$q->rowCount())throw new RuntimeException('Video tidak ditemukan (mungkin sudah dihapus). Muat ulang halaman.');jsonOut(['ok'=>true]);
    case 'thumb_upload':
        $v=findVideo((string)($input['id']??''));if(!$v)throw new RuntimeException('Video tidak ditemukan.');
        $f=$_FILES['thumb']??null;if(!is_array($f)||$f['error']!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name']))throw new RuntimeException('Upload gagal. Pilih gambar maksimal 5 MB.');
        if($f['size']>5*1024*1024)throw new RuntimeException('Gambar maksimal 5 MB.');
        // Trust the decoded image type, never the browser-supplied name or MIME.
        $info=@getimagesize($f['tmp_name']);$types=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_WEBP=>'webp'];
        if(!$info||!isset($types[$info[2]]))throw new RuntimeException('Gunakan gambar JPG, PNG, atau WebP.');
        $name=$v['id'].'-'.bin2hex(random_bytes(4)).'.'.$types[$info[2]];$r2=setting('r2',[]);
        if(!empty($r2['secret'])&&!empty($r2['public'])){$key='thumbs/'.$name;(new R2(r2Config()))->request('PUT',$key,[],(string)file_get_contents($f['tmp_name']),$info['mime']);$url=publicObject($key);}
        else{
            $dir=ROOT.'/public/thumbs';if(!is_dir($dir)&&!@mkdir($dir,0755,true))throw new RuntimeException('Folder public/thumbs tidak bisa dibuat. Periksa izin folder di aaPanel.');
            if(!is_file($dir.'/.htaccess'))@file_put_contents($dir.'/.htaccess',"Options -Indexes\n<FilesMatch \"(?i)\\.(php\\d?|phtml|phar|html?|js)$\">\nRequire all denied\n</FilesMatch>\n");
            if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$name))throw new RuntimeException('Gambar tidak bisa disimpan. Periksa izin folder public/thumbs.');
            $https=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
            $url=($https?'https':'http').'://'.preg_replace('/[^a-z0-9.:-]/i','',(string)($_SERVER['HTTP_HOST']??'localhost')).'/thumbs/'.$name;
        }
        db()->prepare('UPDATE videos SET thumb_url=? WHERE id=?')->execute([$url,$v['id']]);jsonOut(['ok'=>true,'thumb_url'=>$url]);
    case 'thumb_set':
        $v=findVideo((string)($input['id']??''));if(!$v)throw new RuntimeException('Video tidak ditemukan.');
        $url=validUrl((string)($input['url']??''));db()->prepare('UPDATE videos SET thumb_url=? WHERE id=?')->execute([$url,$v['id']]);jsonOut(['ok'=>true,'thumb_url'=>$url]);
    case 'video_delete':
        // Remove publication only. Objects are retained deliberately; no silent destructive deletion.
        $vid=(string)($input['id']??'');db()->prepare('DELETE FROM videos WHERE id=?')->execute([$vid]);purgeFromFolders([$vid]);jsonOut(['ok'=>true]);
    case 'link_edit':
        initPostTables();$title=trim((string)($input['title']??''));if($title===''||strlen($title)>600)throw new RuntimeException('Judul tidak valid.');
        $q=db()->prepare('UPDATE external_links SET title=? WHERE id=?');$q->execute([$title,(string)($input['id']??'')]);if(!$q->rowCount())throw new RuntimeException('Link tidak ditemukan (mungkin sudah dihapus). Muat ulang halaman.');jsonOut(['ok'=>true]);
    case 'link_delete':
        initPostTables();$lid=(string)($input['id']??'');db()->prepare('DELETE FROM external_links WHERE id=?')->execute([$lid]);purgeFromFolders([$lid]);jsonOut(['ok'=>true]);
    case 'items_move':
        $moves=$input['items']??[];if(!is_array($moves))throw new RuntimeException('Data pindah tidak valid.');
        jsonOut(['ok'=>true,'moved'=>moveFolderItems($moves,(string)($input['to']??''))]);
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
