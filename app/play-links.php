<?php
function playableProxyUrl(string $url): bool {
    return strlen($url)<=4096 && preg_match('~\.(mp4|webm|jpe?g|png|webp)$~i',(string)parse_url($url,PHP_URL_PATH))===1;
}
function videoToken(string $url): string {return rtrim(strtr(base64_encode($url),'+/','-_'),'=');}
function decodeVideoToken(string $token): ?string {
    if(strlen($token)>5500||!preg_match('/^[A-Za-z0-9_-]+$/D',$token))return null;
    $raw=base64_decode(strtr($token,'-_','+/'),true);
    if($raw===false||videoToken($raw)!==$token||preg_match('/[\x00-\x20\x7f]/',$raw))return null;
    try{$url=validUrl($raw,false);}catch(Throwable $e){return null;}
    $port=parse_url($url,PHP_URL_PORT);
    return playableProxyUrl($url)&&parse_url($url,PHP_URL_FRAGMENT)===null&&($port===null||$port===443)?$url:null;
}
function postPath(array $video,?array $site=null): string {
    $site=$site??settings();$url=(string)($video['video_url']??'');
    // Short links without index.php; old /index.php?v= and ?play= links keep working.
    if(!empty($site['pretty_links'])&&decodeVideoToken(videoToken($url))!==null)return '/?play='.videoToken($url);
    return '/?v='.rawurlencode($video['id']);
}
// "Putar lewat Worker" on: MP4/WebM and thumbnails load from /media/BASE64 on this
// domain, so the CDN domain is not written in the page. Requires the Worker route
// viduk.fun/media/*. HLS (.m3u8) and other URLs keep loading directly.
function mediaPath(string $url,array $site): string {
    // Files already on this domain (e.g. /thumbs uploads) stay as a plain path.
    if($url!==''&&strcasecmp((string)parse_url($url,PHP_URL_HOST),(string)($_SERVER['HTTP_HOST']??''))===0)return (string)parse_url($url,PHP_URL_PATH);
    return !empty($site['media_proxy'])&&$url!==''&&decodeVideoToken(videoToken($url))!==null?'/media/'.videoToken($url):$url;
}
function videoSource(array $video,array $site): string {return mediaPath((string)$video['video_url'],$site);}
function thumbSource(array $video,array $site): string {$t=(string)($video['thumb_url']??'');return $t===''?'assets/placeholder.svg':mediaPath($t,$site);}
function findVideoByToken(string $token): ?array {
    $url=decodeVideoToken($token);if($url===null)return null;
    // Only registered videos become watch pages; this does not auto-publish arbitrary URLs.
    $q=db()->prepare('SELECT * FROM videos WHERE video_url=? ORDER BY created_at DESC,id DESC LIMIT 1');$q->execute([$url]);return $q->fetch()?:null;
}
