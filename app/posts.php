<?php
declare(strict_types=1);
function initPostTables(): void {
    db()->exec('CREATE TABLE IF NOT EXISTS external_links (id TEXT PRIMARY KEY,title TEXT NOT NULL,url TEXT NOT NULL,type TEXT NOT NULL,created_at INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS collections (id TEXT PRIMARY KEY,title TEXT NOT NULL,items TEXT NOT NULL,created_at INTEGER NOT NULL);');
}
function extraPost(string $id): ?array {
    initPostTables();
    $q=db()->prepare('SELECT *, "folder" AS kind FROM collections WHERE id=?');$q->execute([$id]);$r=$q->fetch();if($r)return $r;
    $q=db()->prepare('SELECT *, "link" AS kind FROM external_links WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;
}
// Same rules for folders from the bot and from the admin studio.
function folderItems(string $id,array $raw,bool $allowEmpty=false): array {
    $items=array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$raw))));
    if((!$items&&!$allowEmpty)||count($items)>500)throw new RuntimeException('Folder harus berisi 1–500 posting.');
    foreach($items as $child){if(!preg_match('/^[a-zA-Z0-9_-]{4,40}$/',$child)||$child===$id)throw new RuntimeException('Isi folder tidak valid.');$other=extraPost($child);if(!findVideo($child)&&(!$other||$other['kind']!=='link'))throw new RuntimeException('Video/link isi folder belum tersedia: '.$child);}
    return $items;
}
function listFolders(): array {initPostTables();return db()->query('SELECT * FROM collections ORDER BY created_at DESC')->fetchAll();}
// IDs that already live in a folder: hidden from the loose video lists.
function folderedIds(): array {$ids=[];foreach(listFolders() as $f)foreach(json_decode($f['items'],true)??[] as $i)$ids[(string)$i]=$f['title'];return $ids;}
function updateFolder(string $id,string $title,array $raw,bool $allowEmpty=false): void {
    initPostTables();$title=trim($title);
    if($title===''||strlen($title)>600)throw new RuntimeException('Judul folder harus diisi, maksimal 600 byte.');
    $folder=extraPost($id);if(!$folder||$folder['kind']!=='folder')throw new RuntimeException('Folder tidak ditemukan.');
    db()->prepare('UPDATE collections SET title=?,items=? WHERE id=?')->execute([$title,json_encode(folderItems($id,$raw,$allowEmpty)),$id]);
}
// Keep only IDs that still resolve to a video or link (folders may hold IDs of posts deleted long ago).
function liveItems(array $items): array {
    return array_values(array_filter(array_map('strval',$items),function($i){if(findVideo($i))return true;$o=extraPost($i);return $o&&$o['kind']==='link';}));
}
// Run a read-modify-write on folders under one write lock, so concurrent writers (the Telegram bot,
// another admin tab) cannot overwrite each other with a stale list.
function withFolderLock(callable $fn){
    initPostTables();db()->exec('BEGIN IMMEDIATE');
    try{$r=$fn();db()->exec('COMMIT');return $r;}catch(Throwable $e){db()->exec('ROLLBACK');throw $e;}
}
function setFolderItems(string $id,array $items): void {db()->prepare('UPDATE collections SET items=? WHERE id=?')->execute([json_encode(array_values($items)),$id]);}
// Drop post IDs from every folder (a deleted video/link must not linger inside folders).
function purgeFromFolders(array $ids): void {
    $ids=array_map('strval',$ids);
    withFolderLock(function() use($ids){
        foreach(listFolders() as $f){
            $items=json_decode($f['items'],true)??[];$keep=array_values(array_filter($items,fn($i)=>!in_array((string)$i,$ids,true)));
            if(count($keep)!==count($items))setFolderItems($f['id'],$keep);
        }
    });
}
// Move posts between folders: $moves = [{id, ...}], $to = target folder ID or '' to take them out of
// every folder. The client's idea of the source folder is not trusted: each ID is removed from all folders.
function moveFolderItems(array $moves,string $to): int {
    $idRe='/^[a-zA-Z0-9_-]{4,40}$/';
    if($to!==''&&!preg_match($idRe,$to))throw new RuntimeException('Folder tujuan tidak valid.');
    $ids=[];
    foreach($moves as $m){
        if(!is_array($m))continue;$id=trim((string)($m['id']??''));
        if(!preg_match($idRe,$id))throw new RuntimeException('ID item tidak valid.');
        $ids[$id]=true;
    }
    if(!$ids)throw new RuntimeException('Pilih item yang mau dipindah.');
    if(count($ids)>500)throw new RuntimeException('Maksimal 500 item sekali pindah.');
    $list=array_map('strval',array_keys($ids));
    return withFolderLock(function() use($list,$to){
        $target=null;
        if($to!==''){$target=extraPost($to);if(!$target||$target['kind']!=='folder')throw new RuntimeException('Folder tujuan tidak ditemukan.');}
        $existing=$target?liveItems(json_decode($target['items'],true)??[]):[];
        foreach($list as $id)if($id===$to)throw new RuntimeException('Folder tidak bisa dimasukkan ke dirinya sendiri.');
        foreach($list as $id)if(!findVideo($id)){$o=extraPost($id);if(!$o||$o['kind']!=='link')throw new RuntimeException('Video/link belum tersedia: '.$id);}
        foreach(listFolders() as $f){
            if($target&&$f['id']===$to)continue;
            $items=json_decode($f['items'],true)??[];$keep=array_values(array_filter($items,fn($i)=>!in_array((string)$i,$list,true)));
            if(count($keep)!==count($items))setFolderItems($f['id'],$keep);
        }
        if($target){
            $new=array_values(array_unique([...array_values(array_filter($existing,fn($i)=>!in_array($i,$list,true))),...$list]));
            if(count($new)>500)throw new RuntimeException('Folder maksimal 500 item.');
            setFolderItems($to,$new);
        }
        return count($list);
    });
}
// Reorder a folder on the server: $order is applied to the CURRENT list. IDs the client does not know about
// (added meanwhile, e.g. by the bot) are kept at the end; unknown IDs are ignored. Title is untouched.
function reorderFolder(string $id,array $order): void {
    withFolderLock(function() use($id,$order){
        $f=extraPost($id);if(!$f||$f['kind']!=='folder')throw new RuntimeException('Folder tidak ditemukan.');
        $cur=array_map('strval',json_decode($f['items'],true)??[]);
        $first=array_values(array_filter(array_unique(array_map('strval',$order)),fn($i)=>in_array($i,$cur,true)));
        setFolderItems($id,[...$first,...array_values(array_diff($cur,$first))]);
    });
}
// Append posts to an existing folder (admin "Masukkan ke folder" and bot /tambah). Re-sending the same IDs is harmless.
function appendFolder(string $id,array $raw): array {
    withFolderLock(function() use($id,$raw){
        $folder=extraPost($id);if(!$folder||$folder['kind']!=='folder')throw new RuntimeException('Folder tidak ditemukan: '.$id);
        updateFolder($id,$folder['title'],[...liveItems(json_decode($folder['items'],true)??[]),...$raw]);
    });
    return ['ok'=>true,'id'=>$id,'type'=>'folder','duplicate'=>false,'path'=>'/?v='.rawurlencode($id)];
}
function registerPost(string $id,string $title,string $type,string $value): array {
    initPostTables();$title=trim($title);
    if(!preg_match('/^[a-zA-Z0-9_-]{4,40}$/',$id))throw new RuntimeException('ID posting tidak valid.');
    if($title===''||strlen($title)>600)throw new RuntimeException('Judul harus diisi, maksimal 600 byte.');
    if(!in_array($type,['video','folder','shopee','bebas'],true))throw new RuntimeException('Jenis posting tidak didukung.');
    $videoUrl='';$thumb='';$items=[];$url='';
    if($type==='video'){
        $parts=explode('||',$value,2);$videoUrl=validUrl($parts[0],false);$thumb=validUrl($parts[1]??'');
        $ext=strtolower(pathinfo(parse_url($videoUrl,PHP_URL_PATH)??'',PATHINFO_EXTENSION));
        if(!in_array($ext,['mp4','webm','m3u8'],true))throw new RuntimeException('Gunakan URL video langsung .mp4, .webm, atau .m3u8.');
    }elseif($type==='folder'){
        $items=folderItems($id,explode(',',$value));
    }else $url=validUrl($value,false);
    db()->exec('BEGIN IMMEDIATE');
    try {
        $existing=findVideo($id);$other=extraPost($id);
        if($existing||$other){
            $same=$existing?($type==='video'&&$existing['video_url']===$videoUrl):($type==='folder'?($other['kind']==='folder'&&json_decode($other['items'],true)===$items):($other['kind']==='link'&&$other['url']===$url));
            if(!$same)throw new RuntimeException('ID sudah dipakai posting berbeda.');
            db()->exec('COMMIT');return ['ok'=>true,'id'=>$id,'type'=>$type,'duplicate'=>true,'path'=>$type==='video'?postPath(['id'=>$id,'video_url'=>$videoUrl]):'/?v='.rawurlencode($id)];
        }
        if($type==='video')db()->prepare('INSERT INTO videos(id,title,video_key,thumb_key,video_url,thumb_url,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$id,$title,'','',$videoUrl,$thumb,time()]);
        elseif($type==='folder')db()->prepare('INSERT INTO collections VALUES(?,?,?,?)')->execute([$id,$title,json_encode($items),time()]);
        else db()->prepare('INSERT INTO external_links VALUES(?,?,?,?,?)')->execute([$id,$title,$url,$type,time()]);
        db()->exec('COMMIT');return ['ok'=>true,'id'=>$id,'type'=>$type,'duplicate'=>false,'path'=>$type==='video'?postPath(['id'=>$id,'video_url'=>$videoUrl]):'/?v='.rawurlencode($id)];
    }catch(Throwable $e){db()->exec('ROLLBACK');throw $e;}
}
// Short public IDs (5 letters/digits). The same seed always gives the same ID, so a retried bot request
// finds its own post again; if the short ID belongs to a different post, the next length is tried.
function shortPostId(string $seed,int $len): string {
    $abc='0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';$h=hash('sha256',$seed,true);$o='';
    for($i=0;$i<$len;$i++)$o.=$abc[ord($h[$i])%62];return $o;
}
function registerShort(string $seed,string $title,string $type,string $value,int $start=5): array {
    for($len=$start;$len<=12;$len++){
        try{return registerPost(shortPostId($seed,$len),$title,$type,$value);}
        catch(RuntimeException $e){if($e->getMessage()!=='ID sudah dipakai posting berbeda.')throw $e;}
    }
    throw new RuntimeException('Gagal membuat ID pendek unik.');
}
function collectionItems(array $folder): array {
    $out=[];foreach(json_decode($folder['items'],true)??[] as $id){$v=findVideo($id);if($v){$out[]=$v;continue;}$l=extraPost($id);if($l&&$l['kind']==='link')$out[]=['id'=>$id,'title'=>$l['title'],'thumb_url'=>'assets/placeholder.svg','views'=>0];}return $out;
}
