<?php
function playableProxyUrl(string $url): bool {
    return strlen($url)<=4096 && preg_match('~\.(mp4|webm)$~i',(string)parse_url($url,PHP_URL_PATH))===1;
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
    if(!empty($site['pretty_links'])&&decodeVideoToken(videoToken($url))!==null)return '/index.php?play='.videoToken($url);
    return '/index.php?v='.rawurlencode($video['id']);
}
function videoSource(array $video,array $site): string {
    // V5.1: playback uses the original CDN URL, including when a saved V5
    // media_proxy flag is still true. No Worker deployment is required.
    return (string)$video['video_url'];
}
function findVideoByToken(string $token): ?array {
    $url=decodeVideoToken($token);if($url===null)return null;
    // Only registered videos become watch pages; this does not auto-publish arbitrary URLs.
    $q=db()->prepare('SELECT * FROM videos WHERE video_url=? ORDER BY created_at DESC,id DESC LIMIT 1');$q->execute([$url]);return $q->fetch()?:null;
}
