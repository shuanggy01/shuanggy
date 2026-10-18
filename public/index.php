<?php
// Link Shopee/bebas: alihkan secepatnya tanpa sesi, cookie, atau setup tabel.
// Tujuan per ID tidak pernah berubah, jadi redirect boleh disimpan browser.
if(!isset($_GET['play'])&&preg_match('/^[a-zA-Z0-9_-]{4,40}$/',(string)($_GET['v']??''))){
    $fastConfig=is_file(__DIR__.'/../config.php')?require __DIR__.'/../config.php':[];
    $fastDb=($fastConfig['data_dir']??__DIR__.'/../var').'/player.sqlite';
    if(is_file($fastDb)){
        try{$pdo=new PDO('sqlite:'.$fastDb,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec('PRAGMA busy_timeout=2000');$q=$pdo->prepare('SELECT url FROM external_links WHERE id=?');$q->execute([(string)$_GET['v']]);$fastUrl=$q->fetchColumn();}catch(Throwable $e){$fastUrl=false;}
        if(is_string($fastUrl)&&preg_match('~^https?://~i',$fastUrl)){header('Cache-Control: public, max-age=86400');header('Location: '.$fastUrl,true,302);exit;}
        unset($pdo,$q);
    }
}
require __DIR__.'/../app/core.php';
require ROOT.'/app/posts.php';
$site=settings();$hasToken=array_key_exists('play',$_GET);
$id=(string)($_GET['v']??'');$video=$hasToken?findVideoByToken((string)$_GET['play']):($id!==''?findVideo($id):null);
if($hasToken)$id=$video?$video['id']:'invalid-play-token';
if(!$hasToken&&$video&&!empty($site['pretty_links'])&&str_starts_with(postPath($video,$site),'/?play=')){header('Location: '.postPath($video,$site),true,302);exit;}
$extra=$id!==''&&!$video?extraPost($id):null;
if($extra&&$extra['kind']==='link'){header('Location: '.$extra['url'],true,302);exit;}
if($id!==''&&!$video&&!$extra)http_response_code(404);
$videos=[];
if($id===''){$inFolder=folderedIds();foreach(db()->query('SELECT * FROM videos ORDER BY created_at DESC') as $v){if(isset($inFolder[$v['id']]))continue;$videos[]=$v;if(count($videos)>=60)break;}}
if($extra&&$extra['kind']==='folder')$videos=collectionItems($extra);
?><!doctype html><html lang="id" class="<?=$video?'watch-document':''?>"><head><meta charset="utf-8"><base href="/"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0b0a12"><meta name="csrf" content="<?=h($_SESSION['csrf'])?>"><title><?=h($video?$video['title'].' · '.$site['site_name']:$site['site_name'])?></title><meta name="description" content="Tonton video pilihan di <?=h($site['site_name'])?>."><?php if($video):?><meta property="og:title" content="<?=h($video['title'])?>"><meta property="og:image" content="<?=h(thumbSource($video,$site))?>"><?php endif;?><?php if($video)adPreconnect($site);?><link rel="stylesheet" href="assets/style.css?v=7"><link rel="stylesheet" href="assets/mobile-v3.css?v=3"><link rel="stylesheet" href="assets/ads-v4.css?v=6"><link rel="stylesheet" href="assets/header-v5.css?v=6"><?php adHeadMeta($site);adBlockCode($site,'head');?></head><body class="viewer-body <?=$video?'watch-page':''?><?=adHasTop($site)?' has-top-ad':''?>"><header class="viewer-header promo-header"><a class="promo-cta" href="https://pejuanglendir.cc" rel="noopener"><span>cek ribuan koleksi lainnya disini</span><strong>pejuanglendir.cc</strong></a></header><?php adTopBanner($site);?>
<main class="viewer-main">
<?php if($video):?>
<div class="player-shell" id="player-shell"><video id="main-video" playsinline preload="none" poster="<?=h(thumbSource($video,$site))?>" controls></video><div class="start-screen" id="start-screen" hidden><button id="start-play" class="big-play" aria-label="Putar video">▶</button><span>Ketuk untuk menonton</span></div><div id="ad-layer" class="ad-layer"><div id="ad-content" class="ad-content"></div><div id="ad-frames" class="ad-frames"></div><div id="ad-tap" class="ad-tap" hidden></div><span id="ad-count" class="ad-count"></span></div>
<div class="custom-controls" id="custom-controls" hidden><input id="seek" type="range" min="0" max="100" value="0" step="0.1" aria-label="Posisi video"><div class="control-row"><button id="toggle-play" aria-label="Putar">▶</button><span id="time-label">0:00 / 0:00</span><span class="control-space"></span><button id="mute" aria-label="Matikan suara">♪</button><button id="fullscreen" aria-label="Layar penuh">⛶</button></div></div><div id="player-message" class="player-message" role="status" hidden></div></div>
<div class="video-description"><p class="eyebrow">NOW PLAYING</p><h1><?=h($video['title'])?></h1><p class="view-count"><span>▶</span> <span id="views"><?=number_format($video['views'])?></span> penayangan</p></div><div class="viewer-actions"><?php if($site['vip_url']):?><a class="button primary" href="<?=h($site['vip_url'])?>" target="_blank" rel="noopener"><span>♧</span> JOIN VIP</a><?php endif;?><button class="secondary" id="copy-video-link"><span>↗</span> SALIN LINK</button><?php if($site['download']):?><a class="button primary" href="download.php?v=<?=h($video['id'])?>"><span>↓</span> UNDUH</a><?php endif;?></div><p class="viewer-note" role="status"><span id="viewer-note">Selamat menonton.</span> <?php adDirect($site,$video['id']);?></p>
<script type="application/json" id="player-data"><?=json_encode(['id'=>$video['id'],'m'=>base64_encode(videoSource($video,$site)),'sharePath'=>postPath($video,$site),'ads'=>adPlayerData($site)],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script><script src="assets/player.js?v=9" defer></script><script src="assets/viewer-v3.js?v=3" defer></script>
<?php adPopup($site);adAgeGate($site);?>
<?php elseif($id!==''&&!$extra):?><div class="empty card"><h1>Video tidak ditemukan.</h1><p class="muted">Link mungkin sudah dihapus atau tidak lengkap.</p><a class="button primary" href="index.php">Kembali ke beranda</a></div>
<?php else:?><section class="collection-intro"><p class="eyebrow">YOUR NEXT PLAY</p><h1><?php if($extra):?><?=h($extra['title'])?><?php else:?>Satu layar.<br><span>Banyak cerita.</span><?php endif;?></h1><p class="muted">Pilih video dan nikmati setiap momennya.</p></section><div class="public-grid"><?php foreach($videos as $v):?><a class="public-video" href="<?=h(postPath($v,$site))?>"><div class="cover"><img src="<?=h(thumbSource($v,$site))?>" alt="" loading="lazy"><span>▶</span></div><h2><?=h($v['title'])?></h2><p><?=number_format($v['views'])?> penayangan</p></a><?php endforeach;?></div><?php if(!$videos):?><div class="empty card"><span>▷</span><h2>Cerita pertama segera hadir.</h2><p class="muted">Belum ada video yang diterbitkan.</p></div><?php endif;endif;?>
</main><footer class="viewer-footer"><?=h($site['site_name'])?> <span>MADE FOR YOUR SCREEN</span></footer><?php adPageEnd($site,(bool)$video);?></body></html>
