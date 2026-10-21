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
// With MEDIA_KEY set (admin + Worker), /media/ tokens are AES-256-GCM sealed instead of
// plain Base64: the CDN URL is unreadable, and video tokens expire after 6 hours and
// can be locked to the viewer's IP range, so copied links fail for remote uploaders.
function mediaKey(): string {static $k=null;if($k===null){try{$k=decryptSecret((string)setting('media_key',''));}catch(Throwable $e){$k='';}}return $k;}
function clientIpPrefix(): string {
    // Same rule as the Worker: IPv4 /24, IPv6 /64, so small address changes keep working.
    $b=@inet_pton((string)($_SERVER['HTTP_CF_CONNECTING_IP']??$_SERVER['REMOTE_ADDR']??''));
    return $b===false?'':bin2hex(substr($b,0,strlen($b)===4?3:8));
}
function sealMedia(string $url,bool $video,array $site): string {
    $lock=$video&&!empty($site['media_ip_lock']);
    $payload=json_encode(['u'=>$url,'e'=>$video?time()+21600:0,'i'=>$lock?clientIpPrefix():''],JSON_UNESCAPED_SLASHES);
    $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($payload,'aes-256-gcm',hash('sha256',mediaKey(),true),OPENSSL_RAW_DATA,$iv,$tag);
    return rtrim(strtr(base64_encode($iv.$cipher.$tag),'+/','-_'),'=');
}
function mediaPath(string $url,array $site,bool $video=true): string {
    // Files already on this domain (e.g. /thumbs uploads) stay as a plain path.
    if($url!==''&&strcasecmp((string)parse_url($url,PHP_URL_HOST),(string)($_SERVER['HTTP_HOST']??''))===0)return (string)parse_url($url,PHP_URL_PATH);
    if(empty($site['media_proxy'])||$url===''||decodeVideoToken(videoToken($url))===null)return $url;
    return '/media/'.(mediaKey()!==''?sealMedia($url,$video,$site):videoToken($url));
}
// External proxy (e.g. proxy-master on v.videy.ca): videos on the listed hosts play from
// "Proxy video" + Base64(URL), the same format videy.ca uses. Takes priority over /media/.
function externalProxy(string $url,array $site): ?string {
    $base=trim((string)($site['video_proxy']??''));
    if($base===''||!preg_match('~\.(mp4|webm)$~i',(string)parse_url($url,PHP_URL_PATH)))return null;
    $hosts=array_filter(array_map(fn($h)=>strtolower(trim($h)),explode(',',(string)($site['video_proxy_hosts']??''))));
    return in_array(strtolower((string)parse_url($url,PHP_URL_HOST)),$hosts,true)?$base.videoToken($url):null;
}
function videoSource(array $video,array $site): string {return externalProxy((string)$video['video_url'],$site)??mediaPath((string)$video['video_url'],$site);}
// Warm up DNS/TLS to the video host while the page loads (only when the source is a full URL, not /media/...).
function videoPreconnect(array $video,array $site): void {
    $src=videoSource($video,$site);$p=parse_url($src);
    if(!$p||empty($p['host'])||!in_array(strtolower($p['scheme']??''),['http','https'],true))return;
    $o=strtolower($p['scheme']).'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
    echo '<link rel="preconnect" href="'.h($o).'"><link rel="dns-prefetch" href="'.h($o).'">';
}
// Thumbnails are not time/IP locked: link previews (Telegram, etc.) fetch them from their own servers.
function thumbSource(array $video,array $site): string {$t=(string)($video['thumb_url']??'');return $t===''?'assets/placeholder.svg':mediaPath($t,$site,false);}
function findVideoByToken(string $token): ?array {
    $url=decodeVideoToken($token);if($url===null)return null;
    // Only registered videos become watch pages; this does not auto-publish arbitrary URLs.
    $q=db()->prepare('SELECT * FROM videos WHERE video_url=? ORDER BY created_at DESC,id DESC LIMIT 1');$q->execute([$url]);return $q->fetch()?:null;
}
