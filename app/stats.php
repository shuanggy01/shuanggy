<?php
// Traffic counters: visitors, page views, plays, downloads per day (Asia/Jakarta) and "online now".
// Logged-in admin and obvious bots/link-preview fetchers are never counted.
function statsDb(): PDO {
    static $ready=false;
    if(!$ready){$ready=true;db()->exec('CREATE TABLE IF NOT EXISTS stats_daily (day TEXT NOT NULL,metric TEXT NOT NULL,n INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,metric));
        CREATE TABLE IF NOT EXISTS stats_visitors (day TEXT NOT NULL,vid TEXT NOT NULL,PRIMARY KEY(day,vid));
        CREATE TABLE IF NOT EXISTS stats_online (vid TEXT PRIMARY KEY,ts INTEGER NOT NULL);');}
    return db();
}
function statsDay(int $offsetDays=0): string {return (new DateTime('now',new DateTimeZone('Asia/Jakarta')))->modify(($offsetDays>=0?'+':'').$offsetDays.' day')->format('Y-m-d');}
function statsSkip(): bool {
    if(!empty($_SESSION['admin']))return true;
    $ua=(string)($_SERVER['HTTP_USER_AGENT']??'');
    return $ua===''||preg_match('/bot|crawl|spider|preview|facebookexternalhit|slurp|curl|wget|python|headless|monitor|telegram/i',$ua)===1;
}
function visitorId(): string {
    $v=(string)($_COOKIE['vid']??'');if(preg_match('/^[a-f0-9]{20}$/',$v))return $v;
    $v=bin2hex(random_bytes(10));$_COOKIE['vid']=$v;
    if(!headers_sent()){$https=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
        setcookie('vid',$v,['expires'=>time()+86400*365,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);}
    return $v;
}
function statsHit(string $metric): void {
    if(statsSkip())return;
    try{statsDb()->prepare('INSERT INTO stats_daily(day,metric,n) VALUES(?,?,1) ON CONFLICT(day,metric) DO UPDATE SET n=n+1')->execute([statsDay(),$metric]);}catch(Throwable $e){}
}
function statsOnlineTouch(): void {
    if(statsSkip())return;
    try{$d=statsDb();$vid=visitorId();$now=time();
        $d->prepare('INSERT INTO stats_online(vid,ts) VALUES(?,?) ON CONFLICT(vid) DO UPDATE SET ts=excluded.ts')->execute([$vid,$now]);
        if(random_int(1,50)===1)$d->prepare('DELETE FROM stats_online WHERE ts<?')->execute([$now-3600]);
    }catch(Throwable $e){}
}
// One page view (watch page, list or folder): counts the view, the unique visitor of the day and marks the visitor online.
function statsTrackPage(): void {
    if(statsSkip())return;
    try{$d=statsDb();$day=statsDay();$vid=visitorId();
        $d->prepare('INSERT INTO stats_daily(day,metric,n) VALUES(?,?,1) ON CONFLICT(day,metric) DO UPDATE SET n=n+1')->execute([$day,'pageviews']);
        $q=$d->prepare('INSERT OR IGNORE INTO stats_visitors(day,vid) VALUES(?,?)');$q->execute([$day,$vid]);
        if($q->rowCount())$d->prepare('INSERT INTO stats_daily(day,metric,n) VALUES(?,?,1) ON CONFLICT(day,metric) DO UPDATE SET n=n+1')->execute([$day,'visitors']);
        if(random_int(1,100)===1)$d->prepare('DELETE FROM stats_visitors WHERE day<?')->execute([statsDay(-3)]);
    }catch(Throwable $e){}
    statsOnlineTouch();
}
function statsOnline(): int {
    try{$q=statsDb()->prepare('SELECT COUNT(*) FROM stats_online WHERE ts>?');$q->execute([time()-75]);return (int)$q->fetchColumn();}catch(Throwable $e){return 0;}
}
function statsSummary(): array {
    $out=['online'=>statsOnline(),'today'=>[],'yesterday'=>[]];
    try{$d=statsDb();foreach(['today'=>0,'yesterday'=>-1] as $k=>$off){$q=$d->prepare('SELECT metric,n FROM stats_daily WHERE day=?');$q->execute([statsDay($off)]);$out[$k]=$q->fetchAll(PDO::FETCH_KEY_PAIR);}}catch(Throwable $e){}
    foreach(['today','yesterday'] as $k)foreach(['visitors','pageviews','plays','downloads'] as $m)$out[$k][$m]=(int)($out[$k][$m]??0);
    return $out;
}
