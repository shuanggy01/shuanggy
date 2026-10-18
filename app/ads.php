<?php
// Included by core.php. Global ad slots: one content field + countdown per slot.
// Content is auto-detected at render time (HTML/JS, video, image, iframe/link URL, link list).
function adSlots(): array {
    return [
        'preroll'=>['label'=>'Pre-roll','overlay'=>true,'hint'=>'Sebelum video diputar.'],
        'interstitial'=>['label'=>'Interstitial (tengah video)','overlay'=>true,'times'=>true,'hint'=>'Muncul di detik yang diisi.'],
        'pause'=>['label'=>'Pause','overlay'=>true,'hint'=>'Saat video dijeda.'],
        'top_banner'=>['label'=>'Banner atas 728x90 (Adsterra)','overlay'=>false,'hint'=>'Kode banner 728x90. Di layar kecil otomatis diperkecil, tampilan web tidak berubah.'],
        'download_banner'=>['label'=>'Banner download (300x250 / Native)','overlay'=>false,'hint'=>'Di halaman download. Ukuran mengikuti kode (standar 300x250); Native Banner menyesuaikan sendiri.'],
        'popup'=>['label'=>'Popunder / Script halaman','overlay'=>false,'hint'=>'Kode/URL script di halaman tonton.'],
        'direct'=>['label'=>'Direct link','overlay'=>false,'hint'=>'Satu link per baris, bergantian.'],
        'agegate'=>['label'=>'Popup 18+ smartlink','overlay'=>false,'times'=>true,'hint'=>'Smartlink https://, satu per baris (dipilih acak). Muncul saat ▶ ditekan dan di detik yang diisi.'],
        // 'config' slots are site code, not ad placements: they are not counted as ads.
        'head_meta'=>['label'=>'Meta verifikasi layanan iklan','config'=>true,'overlay'=>false,'hint'=>'Tempel tag <meta name="…" content="…"> dari layanan iklan. Dipasang di <head> semua halaman.'],
        'adblock'=>['label'=>'Kode anti-adblock (semua layanan iklan)','config'=>true,'overlay'=>false,'opt'=>['type'=>'select','label'=>'Letak kode','choices'=>['head'=>'Di dalam <head>','body'=>'Di akhir <body>'],'default'=>'head'],'hint'=>'Kode anti-adblock / ad recovery dari layanan iklan. Dijalankan di semua halaman publik.'],
        'custom_code'=>['label'=>'Kode bebas (HTML / JS apa saja)','config'=>true,'overlay'=>false,'opt'=>['type'=>'check','label'=>'Pindahkan overlay transparan layar penuh ke area player','default'=>'1'],'hint'=>'Tempel kode apa saja; dijalankan di akhir halaman publik.'],
    ];
}
function adDefaults(): array {
    $r=[];foreach(adSlots() as $slot=>$m)$r[]=['slot'=>$slot,'enabled'=>false,'content'=>'','seconds'=>$slot==='pause'?0:5,'times'=>$slot==='interstitial'?'30,60':'','height'=>250,'opt'=>(string)(adSlots()[$slot]['opt']['default']??'')];
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
        $ad=$base;foreach(['enabled','content','seconds','times','height','opt'] as $k)if(array_key_exists($k,$a))$ad[$k]=$a[$k];
        $ad['enabled']=!empty($ad['enabled']);$ad['content']=(string)$ad['content'];$ad['seconds']=(int)$ad['seconds'];$ad['times']=(string)$ad['times'];$ad['opt']=adCleanOpt($ad['slot'],(string)($ad['opt']??''));
        $r[]=$ad;}
    return $r;
}
// Per-slot option (select / checkbox): anything outside the allowed values falls back to the default.
function adCleanOpt(string $slot,string $v): string {
    $o=adSlots()[$slot]['opt']??null;if(!$o)return '';
    if($o['type']==='check')return $v==='0'?'0':($v==='1'?'1':(string)$o['default']);
    return isset($o['choices'][$v])?$v:(string)$o['default'];
}
function adCounts(array $site): array {
    $spec=adSlots();$on=0;$all=0;
    foreach($site['ads'] as $a){if(!empty($spec[$a['slot']]['config']))continue;$all++;if(!empty($a['enabled']))$on++;}
    return [$on,$all];
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
        $ad=['slot'=>$slot,'enabled'=>!empty($a['enabled']),'content'=>$content,'seconds'=>max(0,min(60,(int)($a['seconds']??$base['seconds']))),'times'=>implode(',',adTimes((string)($a['times']??$base['times']))),'height'=>$base['height'],'opt'=>adCleanOpt($slot,(string)($a['opt']??$base['opt']))];
        if($ad['enabled']&&$slot==='head_meta'&&adCleanMeta($content)==='')throw new RuntimeException('Tidak ada tag <meta name="…" content="…"> yang valid untuk Meta verifikasi.');
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
    $hosts=[];foreach($site['ads'] as $ad){if(empty($ad['enabled'])||$ad['slot']==='head_meta')continue;
        if(preg_match_all('~https://([a-z0-9.-]+)~i',(string)$ad['content'],$m))foreach($m[1] as $h)$hosts[strtolower($h)]=1;}
    foreach(array_slice(array_keys($hosts),0,8) as $h)echo '<link rel="preconnect" href="https://'.h($h).'" crossorigin><link rel="dns-prefetch" href="//'.h($h).'">';
}
// ---- Inline banners -------------------------------------------------------------------
// Banner code runs directly in the page (inside a fixed-size box) instead of a sandboxed
// iframe: ad networks need cookies / referrer / top-window access that a sandbox removes.
// Size comes from the code itself (atOptions width/height); the box is scaled down on small
// screens by assets/adbox.js, so the page layout never changes.
function adBoxSize(string $code,int $dw,int $dh): array {
    $w=$dw;$h=$dh;
    // Only explicit pixel sizes count (atOptions / width="728" / width:728px); never %, max-width, etc.
    if(preg_match('/(?<![-\w])[\'"]?width[\'"]?\s*[:=]\s*[\'"]?(\d{2,4})(?:px)?(?![\d%.\w])/i',$code,$m))$w=max(50,min(1200,(int)$m[1]));
    if(preg_match('/(?<![-\w])[\'"]?height[\'"]?\s*[:=]\s*[\'"]?(\d{2,4})(?:px)?(?![\d%.\w])/i',$code,$m))$h=max(30,min(1000,(int)$m[1]));
    $native=!preg_match('/atOptions/i',$code)&&preg_match('/container-[A-Za-z0-9]+/',$code)===1;
    return [$w,$h,$native];
}
// Plain JS pasted without <script> tags would print as text, so wrap it.
function adCodeHtml(string $content): string {
    $c=trim($content);if($c==='')return '';
    $p=adParse($c);
    if($p['kind']==='script')return '<script data-cfasync="false" src="'.h($p['url']).'"></script>';
    if($p['kind']!=='html')return '';
    $c=preg_replace('/\R\s*https?:\/\/\S+\s*$/','',$c);
    // HTML when it starts with a tag/comment; otherwise it is JS (which may contain "<a" or "<div" in strings).
    if($c[0]==='<')return $c;
    return '<script>'.$c.'</script>';
}
function adBoxMark(): void {$GLOBALS['adbox_used']=true;}
function adBoxScript(): void {if(!empty($GLOBALS['adbox_used']))echo '<script src="assets/adbox.js?v=2" defer></script>';}
function adFit(string $inner,int $w,int $h): string {
    return '<div class="ad-fit" data-w="'.$w.'" data-h="'.$h.'" style="--w:'.$w.';--h:'.$h.'"><div class="ad-fit-inner">'.$inner.'</div></div>';
}
function adBanner(array $site): void {
    $ad=adFind($site,'download_banner');if(!$ad)return;$p=adParse($ad['content']);
    echo '<aside class="download-ad" data-adbox aria-label="Iklan"><p class="hint">IKLAN</p>';
    if($p['kind']==='image'){
        $href=$p['link'];if($href!=='')echo '<a href="'.h($href).'" target="_blank" rel="noopener noreferrer sponsored">';
        echo '<img src="'.h($p['url']).'" alt="Iklan" loading="lazy">';if($href!=='')echo '</a>';
    }elseif($p['kind']==='link'){
        echo '<a class="button secondary" href="'.h($p['url']).'" target="_blank" rel="noopener noreferrer sponsored">Buka ↗</a>';
    }elseif($p['kind']==='video'){
        echo '<video src="'.h($p['url']).'" muted playsinline autoplay loop style="max-width:100%"></video>';
    }else{
        $code=adCodeHtml($ad['content']);[$w,$h,$native]=adBoxSize($ad['content'],300,250);adBoxMark();
        echo $native?'<div class="ad-native">'.$code.'</div>':adFit($code,$w,$h);
    }echo '</aside>';
}
// Top banner (Adsterra 728x90): same box + scaling, so a 728px banner never widens the page.
function adHasTop(array $site): bool {
    $ad=adFind($site,'top_banner');return $ad&&(adParse($ad['content'])['kind']==='image'||adCodeHtml($ad['content'])!=='');
}
function adTopBanner(array $site): void {
    $ad=adFind($site,'top_banner');if(!$ad)return;$p=adParse($ad['content']);
    if($p['kind']==='image'){
        $href=$p['link'];echo '<div class="top-ad top-ad-image" data-adbox>';
        if($href!=='')echo '<a href="'.h($href).'" target="_blank" rel="noopener noreferrer sponsored">';
        echo '<img src="'.h($p['url']).'" alt="Iklan" style="max-width:100%;height:auto;display:block;margin:auto">';if($href!=='')echo '</a>';
        echo '</div>';adBoxMark();return;
    }
    $code=adCodeHtml($ad['content']);if($code==='')return;
    [$w,$h]=adBoxSize($ad['content'],728,90);adBoxMark();
    echo '<div class="top-ad" data-adbox aria-label="Iklan">'.adFit($code,$w,$h).'</div>';
}
// Verification <meta> tags are rebuilt from scratch: only name/property + content survive,
// so nothing else (scripts, refresh, charset) can slip into <head>.
function adCleanMeta(string $raw): string {
    $out=[];
    if(preg_match_all('/<meta\b[^>]*>/i',$raw,$tags)){
        foreach($tags[0] as $tag){
            $attr=[];
            if(preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>\/]+))/',$tag,$m,PREG_SET_ORDER))
                foreach($m as $x)$attr[strtolower($x[1])]=html_entity_decode(($x[2]??'')!==''?$x[2]:(($x[3]??'')!==''?$x[3]:($x[4]??'')),ENT_QUOTES,'UTF-8');
            $key=isset($attr['name'])?'name':(isset($attr['property'])?'property':'');
            if($key===''||!isset($attr['content'])||strlen($attr[$key])>100||strlen($attr['content'])>500)continue;
            if(!preg_match('/^[A-Za-z0-9_.:-]+$/',$attr[$key]))continue;
            $out[]='<meta '.$key.'="'.h($attr[$key]).'" content="'.h($attr['content']).'">';
            if(count($out)>=30)break;
        }
    }
    return implode("\n",$out);
}
function adHeadMeta(array $site): void {
    $ad=adFind($site,'head_meta');if(!$ad)return;$m=adCleanMeta($ad['content']);if($m!=='')echo "\n".$m."\n";
}
// Anti-adblock code (every ad service) goes in <head> or at the end of <body>, as chosen.
function adBlockCode(array $site,string $where): void {
    $ad=adFind($site,'adblock');if(!$ad||adCleanOpt('adblock',(string)($ad['opt']??''))!==$where)return;
    $c=adCodeHtml($ad['content']);if($c!=='')echo "\n".$c."\n";
}
// "Kode bebas": any HTML/JS at the end of the page. On the watch page, a transparent
// full-screen overlay created by that code is moved into the player (assets/overlay-guard.js).
function adCustomCode(array $site,bool $hasPlayer): void {
    $ad=adFind($site,'custom_code');if(!$ad)return;
    $c=adCodeHtml($ad['content']);if($c==='')return;
    if($hasPlayer&&adCleanOpt('custom_code',(string)($ad['opt']??''))==='1')echo '<script src="assets/overlay-guard.js?v=2"></script>';
    echo "\n".$c."\n";
}
function adPageEnd(array $site,bool $hasPlayer): void {adBlockCode($site,'body');adCustomCode($site,$hasPlayer);adBoxScript();}
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
