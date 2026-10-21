<?php
require __DIR__.'/../app/core.php';
header("Content-Security-Policy: base-uri 'none'; form-action 'none'");
$slot=(string)($_GET['slot']??'');
$html=in_array($slot,['preroll','interstitial','pause','download_banner'],true)?adFrameHtml(settings(),$slot):null;
if($html!==null){
    header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>html,body{margin:0;height:100%;background:transparent;color:#fff;color-scheme:dark}body{display:grid;place-items:center}img,video,iframe{max-width:100%}</style></head><body>'.$html.'</body></html>';exit;
}
http_response_code(404);
