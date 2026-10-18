<?php
require __DIR__.'/../app/core.php';require ROOT.'/app/R2.php';
$site=settings();$v=findVideo((string)($_GET['v']??''));
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: same-origin');
if(!$v||!$site['download']){http_response_code(404);exit('Unduhan tidak tersedia.');}
$id=$v['id'];$now=time();$waitSeconds=30;
$_SESSION['download_wait']=$_SESSION['download_wait']??[];
foreach($_SESSION['download_wait'] as $key=>$entry)if(($entry['expires']??0)<$now)unset($_SESSION['download_wait'][$key]);
$gate=$_SESSION['download_wait'][$id]??null;
if(isset($_GET['file'])){
    $ticket=(string)($_GET['ticket']??'');
    if(!$gate||!hash_equals($gate['ticket'],$ticket)||$now<$gate['ready']){
        header('Location: download.php?v='.rawurlencode($id),true,303);exit;
    }
    session_write_close();
    $source=videoSource($v,$site);
    if(str_starts_with($source,'/media/')){header('Location: '.$source.'?download=1',true,302);exit;}

    if($v['video_key']===''){header('Location: '.$v['video_url'],true,302);exit;}
    try{
        // Preserve support for videos uploaded with the older R2 uploader.
        if(publicObject($v['video_key'])!==$v['video_url']){header('Location: '.$v['video_url'],true,302);exit;}
        $ext=pathinfo($v['video_key'],PATHINFO_EXTENSION);
        $url=(new R2(r2Config()))->presign('GET',$v['video_key'],['response-content-disposition'=>'attachment; filename="video-'.$id.'.'.$ext.'"']);
        header('Location: '.$url,true,302);exit;
    }catch(Throwable $e){http_response_code(503);exit('Unduhan belum tersedia. Hubungi pengelola website.');}
}
if(!$gate){
    if(count($_SESSION['download_wait'])>=30)array_shift($_SESSION['download_wait']);
    $gate=['ready'=>$now+$waitSeconds,'expires'=>$now+1800,'ticket'=>bin2hex(random_bytes(16))];
    $_SESSION['download_wait'][$id]=$gate;
}
$remaining=max(0,$gate['ready']-$now);
$downloadUrl='download.php?v='.rawurlencode($id).'&file=1&ticket='.rawurlencode($gate['ticket']);
$format=strtoupper(pathinfo((string)parse_url($v['video_url'],PHP_URL_PATH),PATHINFO_EXTENSION));
if(!in_array($format,['MP4','WEBM','M3U8'],true))$format='VIDEO';
session_write_close();
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#171717"><meta name="robots" content="noindex,follow"><title>Download · <?=h($v['title'])?></title><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/mobile-v3.css?v=3"><link rel="stylesheet" href="assets/ads-v4.css?v=4"></head>
<body class="download-body"><main class="download-main"><article class="download-card">
<p class="download-label">DOWNLOAD VIDEO</p>
<h1 class="download-title"><?=h($v['title'])?></h1>
<img class="download-cover" src="<?=h($v['thumb_url']?:'assets/placeholder.svg')?>" alt="Thumbnail video" width="1280" height="720">
<div class="download-meta"><span id="download-duration" hidden></span><span>Format: <?=h($format)?></span></div>
<?php adBanner($site);?>
<section class="download-wait" aria-label="Persiapan unduhan" data-id="<?=h($id)?>" data-remaining="<?=$remaining?>" data-total="<?=$waitSeconds?>">
<p class="download-status" id="download-status" role="status"><?=$remaining?'Download sedang disiapkan':'Download siap'?></p>
<span class="download-clock" id="download-clock" aria-label="Sisa waktu"><?=$remaining?'00:'.str_pad((string)$remaining,2,'0',STR_PAD_LEFT):'00:00'?></span>
<progress class="download-progress" id="download-progress" max="<?=$waitSeconds?>" value="<?=$waitSeconds-$remaining?>" aria-label="Persiapan download"></progress>
<p class="download-help" id="download-help"><?=$remaining?'Mohon tunggu. Tombol download akan tersedia otomatis.':'Video siap. Tekan tombol di bawah untuk membuka file.'?></p>
<a class="button primary download-ready" id="download-ready" href="<?=h($downloadUrl)?>" rel="nofollow" <?=$remaining?'hidden':''?>>Download video</a>
<noscript><p class="download-help">Tunggu 30 detik, lalu muat ulang halaman ini untuk menampilkan tombol download.</p></noscript>
</section>
<?php adDirect($site,$id);?>
<a class="button download-back" href="<?=h(postPath($v,$site))?>">← Kembali ke halaman video</a>
</article></main><script src="assets/download-v3.js?v=3" defer></script></body></html>
