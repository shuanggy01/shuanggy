<?php
// Included by core.php. Global ad slots: one content field + countdown per slot.
// Content is auto-detected at render time (HTML/JS, video, image, iframe/link URL, link list).
function adSlots(): array {
    return [
        'preroll'=>['label'=>'Pre-roll','overlay'=>true,'hint'=>'Sebelum video diputar.'],
        'interstitial'=>['label'=>'Interstitial (tengah video)','overlay'=>true,'times'=>true,'hint'=>'Muncul di detik yang diisi.'],
        'pause'=>['label'=>'Pause','overlay'=>true,'hint'=>'Saat video dijeda.'],
        'download_banner'=>['label'=>'Banner download','overlay'=>false,'hint'=>'Di halaman download.'],
        'popup'=>['label'=>'Popunder / Script halaman','overlay'=>false,'hint'=>'Kode/URL script di halaman tonton.'],
        'direct'=>['label'=>'Direct link','overlay'=>false,'hint'=>'Satu link per baris, bergantian.'],
        'agegate'=>['label'=>'Popup 18+ smartlink','overlay'=>false,'times'=>true,'hint'=>'Smartlink https://, satu per baris (dipilih acak). Muncul saat ▶ ditekan dan di detik yang diisi.'],
    ];
}
function adDefaults(): array {
    $r=[];foreach(adSlots() as $slot=>$m)$r[]=['slot'=>$slot,'enabled'=>false,'content'=>'','seconds'=>$slot==='pause'?0:5,'times'=>$slot==='interstitial'?'30,60':'','height'=>250];
    return $r;
}
// Convert an old-format slot (type/url/link/html/links) to single content text.
function adLegacyContent(array $a): string {
    $type=(string)($a['type']??'');$url=trim((string)($a['url']??''));$link=trim((string)($a['link']??''));
    if(in_array($type,['html','script'],true))return trim((string)($a['html']??''));
    if($type==='links')return implode("\n",(array)($a['links']??[]));
    if($type==='iframe'&&$url!=='')return '<iframe src="'.htmlspecialchars($url,ENT_QUOTES).'" style="border:0;width:100%;height:100%" allowfullscreen></iframe>';
    if($url==='')return '';
    return $link!==''?$url."\n".$link:$url;
}
function mergeAds(array $saved): array {
    $by=[];foreach($saved as $a)if(is_array($a)&&isset($a['slot']))$by[$a['slot']]=$a;
    foreach($by as $k=>$a)if(!array_key_exists('content',$a)){$by[$k]['content']=adLegacyContent($a);}
    // Old Before Play 1-3 are merged into the single Pre-roll slot (first one with content, enabled preferred).
    if(!isset($by['interstitial'])&&(isset($by['before1'])||isset($by['before2'])||isset($by['before3']))){ // legacy data only
        $pick=null;foreach(['preroll','before1','before2','before3'] as $k)if(isset($by[$k])&&trim($by[$k]['content'])!==''){if(!empty($by[$k]['enabled'])){$pick=$by[$k];break;}$pick??=$by[$k];}
        if($pick){$pick['slot']='preroll';$by['preroll']=$pick;}
    }
    $r=[];foreach(adDefaults() as $base){$a=$by[$base['slot']]??[];
        $ad=$base;foreach(['enabled','content','seconds','times','height'] as $k)if(array_key_exists($k,$a))$ad[$k]=$a[$k];
        $ad['enabled']=!empty($ad['enabled']);$ad['content']=(string)$ad['content'];$ad['seconds']=(int)$ad['seconds'];$ad['times']=(string)$ad['times'];
        $r[]=$ad;}
    return $r;
}
function adTimes(string $s): array {
    $t=[];foreach(preg_split('/[^0-9]+/',$s) as $n)if($n!==''&&(int)$n>0)$t[]=(int)$n;
    $t=array_values(array_unique($t));sort($t);return array_slice($t,0,20);
}
function validateAds(array $input,array $existing): array {
    $spec=adSlots();$by=[];
    foreach($input as $a){
        if(!is_array($a)||!isset($spec[$a['slot']??'']))throw new RuntimeException('Slot iklan tidak dikenal. Muat ulang dashboard.');
        if(isset($by[$a['slot']]))throw new RuntimeException('Slot iklan duplikat.');$by[$a['slot']]=$a;
    }
    $r=[];foreach(mergeAds($existing) as $base){$slot=$base['slot'];if(!isset($by[$slot])){$r[]=$base;continue;}$a=$by[$slot];
        $content=trim((string)($a['content']??''));if(strlen($content)>100000)throw new RuntimeException('Isi iklan maksimal 100 KB per slot.');
        $ad=['slot'=>$slot,'enabled'=>!empty($a['enabled']),'content'=>$content,'seconds'=>max(0,min(60,(int)($a['seconds']??$base['seconds']))),'times'=>implode(',',adTimes((string)($a['times']??$base['times']))),'height'=>$base['height']];
        if($ad['enabled']&&$content==='')throw new RuntimeException('Isi iklan untuk '.$spec[$slot]['label'].'.');
        if($ad['enabled']&&$slot==='interstitial'&&$ad['times']==='')throw new RuntimeException('Isi detik muncul untuk Interstitial, misal 30,60.');
        if($ad['enabled']){$p=adParse($content);if($p['kind']==='invalid')throw new RuntimeException('Isi '.$spec[$slot]['label'].' tidak dikenali. Tempel kode HTML/JS atau link https://.');}
        $r[]=$ad;}
    return $r;
}
// Auto-detect what the content is.
function adParse(string $content): array {
    $c=trim($content);$out=['kind'=>'none','url'=>'','link'=>'','links'=>[]];if($c==='')return $out;
    if($c[0]==='<'){$out['kind']='html';
        // Optional: last line = https link opened when the overlay is tapped after the countdown.
        $ls=preg_split('/\r\n|\r|\n/',$c);$last=trim((string)end($ls));
        if(count($ls)>1&&preg_match('~^https://\S+$~i',$last)&&filter_var($last,FILTER_VALIDATE_URL))$out['link']=$last;
        return $out;}
    $lines=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n|\s+/',$c)),'strlen'));
    $urls=[];foreach($lines as $l){if(!preg_match('~^https://~i',$l)||!filter_var($l,FILTER_VALIDATE_URL)){$out['kind']='html';return $out;}$urls[]=$l;}
    if(!$urls){$out['kind']='invalid';return $out;}
    $first=$urls[0];$path=strtolower((string)parse_url($first,PHP_URL_PATH));
    $out['url']=$first;$out['link']=$urls[1]??'';$out['links']=array_values(array_unique($urls));
    if(preg_match('/\.(mp4|webm|m4v|mov)$/',$path))$out['kind']='video';
    elseif(preg_match('/\.(jpe?g|png|gif|webp|avif|svg)$/',$path))$out['kind']='image';
    elseif(preg_match('/\.js$/',$path))$out['kind']='script';
    else $out['kind']='link';
    return $out;
}
// Popup 18+ (age confirmation): "Ya" opens a random smartlink in a new tab and the video keeps playing.
// X closes it and "Keluar" leaves the page, so no control is disguised.
function adAgeGate(array $site): void {
    $ad=adFind($site,'agegate');if(!$ad)return;
    $links=array_values(array_filter(adParse($ad['content'])['links']??[],fn($u)=>str_starts_with($u,'https://')));if(!$links)return;
    $json=json_encode(['links'=>$links,'times'=>adTimes((string)$ad['times'])],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES);
    echo '<link rel="stylesheet" href="assets/agegate.css?v=1"><script type="application/json" id="agegate-data">'.$json.'</script><script src="assets/agegate.js?v=1" defer></script>';
}
function adFind(array $site,string $slot): ?array {
    foreach($site['ads'] as $ad)if($ad['slot']===$slot&&!empty($ad['enabled'])&&trim($ad['content'])!=='')return $ad;return null;
}
// Data sent to the player (raw HTML stays server-side, served by ad.php).
function adPlayerData(array $site): array {
    $r=[];foreach(['preroll','interstitial','pause'] as $slot){$ad=adFind($site,$slot);if(!$ad)continue;$p=adParse($ad['content']);if(in_array($p['kind'],['none','invalid'],true))continue;
        $kind=$p['kind']==='script'?'html':$p['kind']; // .js URL is rendered through ad.php like HTML
        $r[]=['slot'=>$slot,'enabled'=>true,'kind'=>$kind,'url'=>$p['url'],'link'=>$p['link'],'seconds'=>(int)$ad['seconds'],'times'=>$slot==='interstitial'?adTimes($ad['times']):[]];}
    return $r;
}
// <link rel=preconnect> for every ad host, so ads appear faster.
function adPreconnect(array $site): void {
    $hosts=[];foreach($site['ads'] as $ad){if(empty($ad['enabled']))continue;
        if(preg_match_all('~https://([a-z0-9.-]+)~i',(string)$ad['content'],$m))foreach($m[1] as $h)$hosts[strtolower($h)]=1;}
    foreach(array_slice(array_keys($hosts),0,8) as $h)echo '<link rel="preconnect" href="https://'.h($h).'" crossorigin><link rel="dns-prefetch" href="//'.h($h).'">';
}
function adBanner(array $site): void {
    $ad=adFind($site,'download_banner');if(!$ad)return;$p=adParse($ad['content']);
    echo '<aside class="download-ad" aria-label="Iklan"><p class="hint">IKLAN</p>';
    if($p['kind']==='image'){
        $href=$p['link'];if($href!=='')echo '<a href="'.h($href).'" target="_blank" rel="noopener noreferrer sponsored">';
        echo '<img src="'.h($p['url']).'" alt="Iklan" loading="lazy">';if($href!=='')echo '</a>';
    }elseif($p['kind']==='link'){
        echo '<a class="button secondary" href="'.h($p['url']).'" target="_blank" rel="noopener noreferrer sponsored">Buka ↗</a>';
    }elseif($p['kind']==='video'){
        echo '<video src="'.h($p['url']).'" muted playsinline autoplay loop style="max-width:100%"></video>';
    }else{
        echo '<iframe title="Iklan download" src="ad.php?slot=download_banner" height="'.(int)$ad['height'].'" sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"></iframe>';
    }echo '</aside>';
}
function adDirect(array $site,string $id): void {
    $ad=adFind($site,'direct');if(!$ad)return;$p=adParse($ad['content']);if(!$p['links'])return;
    $json=json_encode(['id'=>$id,'links'=>$p['links']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    echo '<a class="button secondary direct-sponsor" data-direct-link href="'.h($p['links'][0]).'" target="_blank" rel="noopener noreferrer sponsored">Sponsor ↗</a><script type="application/json" id="direct-data">'.$json.'</script><script src="assets/direct-v4.js?v=4" defer></script>';
}
function adPopup(array $site): void {
    $ad=adFind($site,'popup');if(!$ad)return;$p=adParse($ad['content']);
    // Trusted administrator code, only on the watch page (top window, never inside ad iframes).
    // Kept inert in a <template>; player.js activates it after the pre-roll so taps don't collide.
    if($p['kind']==='html')$code=$ad['content'];
    elseif(in_array($p['kind'],['script','link'],true))$code='<script data-cfasync="false" src="'.h($p['url']).'"></script>';
    else return;
    echo "\n<template id=\"popup-code\">".$code."</template>\n";
}
// HTML body for sandboxed ad.php frames.
function adFrameHtml(array $site,string $slot): ?string {
    $ad=adFind($site,$slot);if(!$ad)return null;$p=adParse($ad['content']);
    if($p['kind']==='html'){$c=trim($ad['content']);if($p['link']!==''){$ls=preg_split('/\r\n|\r|\n/',$c);array_pop($ls);$c=implode("\n",$ls);}return $c;}
    if($p['kind']==='script')return '<script src="'.h($p['url']).'"></script>';
    return null;
}
