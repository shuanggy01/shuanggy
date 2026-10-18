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
function folderItems(string $id,array $raw): array {
    $items=array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$raw))));
    if(!$items||count($items)>500)throw new RuntimeException('Folder harus berisi 1–500 posting.');
    foreach($items as $child){if(!preg_match('/^[a-zA-Z0-9_-]{4,40}$/',$child)||$child===$id)throw new RuntimeException('Isi folder tidak valid.');$other=extraPost($child);if(!findVideo($child)&&(!$other||$other['kind']!=='link'))throw new RuntimeException('Video/link isi folder belum tersedia: '.$child);}
    return $items;
}
function listFolders(): array {initPostTables();return db()->query('SELECT * FROM collections ORDER BY created_at DESC')->fetchAll();}
function updateFolder(string $id,string $title,array $raw): void {
    initPostTables();$title=trim($title);
    if($title===''||strlen($title)>600)throw new RuntimeException('Judul folder harus diisi, maksimal 600 byte.');
    $folder=extraPost($id);if(!$folder||$folder['kind']!=='folder')throw new RuntimeException('Folder tidak ditemukan.');
    db()->prepare('UPDATE collections SET title=?,items=? WHERE id=?')->execute([$title,json_encode(folderItems($id,$raw)),$id]);
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
function collectionItems(array $folder): array {
    $out=[];foreach(json_decode($folder['items'],true)??[] as $id){$v=findVideo($id);if($v){$out[]=$v;continue;}$l=extraPost($id);if($l&&$l['kind']==='link')$out[]=['id'=>$id,'title'=>$l['title'],'thumb_url'=>'assets/placeholder.svg','views'=>0];}return $out;
}
