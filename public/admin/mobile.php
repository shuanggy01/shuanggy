<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/database.php';
require dirname(__DIR__, 2) . '/app/admin.php';

admin_require_login();

function m_e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function m_icon(string $name, int $size = 20): string
{
    $icons = [
        'home' => '<path d="M3 10.8 12 3l9 7.8"/><path d="M5.5 9.8V21h13V9.8"/><path d="M9.5 21v-6h5v6"/>',
        'play' => '<path d="M8 5v14l11-7Z"/>',
        'album' => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 9h8M8 13h8M8 17h5"/>',
        'ads' => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M7 9h4M7 13h7M17 9v6"/>',
        'more' => '<circle cx="5" cy="12" r="1.5" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.5" fill="currentColor" stroke="none"/>',
        'chart' => '<path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1.2l2-1.5-2-3.4-2.5 1A8 8 0 0 0 14.3 5L14 2h-4l-.3 3A8 8 0 0 0 7.6 6.9l-2.5-1-2 3.4 2 1.5A7 7 0 0 0 5 12c0 .4 0 .8.1 1.2l-2 1.5 2 3.4 2.5-1A8 8 0 0 0 9.7 19l.3 3h4l.3-3a8 8 0 0 0 2.1-1.9l2.5 1 2-3.4-2-1.5A7 7 0 0 0 19 12Z"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>',
        'logout' => '<path d="M10 5H5v14h5"/><path d="M13 8l4 4-4 4M17 12H9"/>',
        'seo' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m20 20-4.6-4.6"/>',
        'mirror' => '<rect x="4" y="3" width="16" height="18" rx="8"/><path d="M8 8c2-2 6-2 8 0"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'eye' => '<path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/>',
        'arrow' => '<path d="M5 12h14M14 7l5 5-5 5"/>',
        'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
    ];

    $body = $icons[$name] ?? $icons['more'];

    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

function m_scalar(PDO $pdo, string $sql): int
{
    try {
        return (int) $pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function m_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?"
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$videoCount = m_scalar($pdo, "SELECT COUNT(*) FROM videos WHERE status='published'");
$albumCount = m_scalar($pdo, "SELECT COUNT(*) FROM collections WHERE status='published'");
$totalViews = m_scalar($pdo, "SELECT COALESCE(SUM(views),0) FROM videos") + m_scalar($pdo, "SELECT COALESCE(SUM(views),0) FROM collections");
$unique7 = 0;

if (m_table_exists($pdo, 'video_views')) {
    $unique7 += m_scalar($pdo, "SELECT COUNT(*) FROM video_views WHERE view_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)");
}
if (m_table_exists($pdo, 'collection_views')) {
    $unique7 += m_scalar($pdo, "SELECT COUNT(*) FROM collection_views WHERE view_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)");
}

$recentVideos = [];
$recentAlbums = [];

try {
    $recentVideos = $pdo->query(
        "SELECT video_key,title,thumbnail_url,views,status FROM videos ORDER BY id DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

try {
    $recentAlbums = $pdo->query(
        "SELECT collection_key,title,views,status FROM collections ORDER BY id DESC LIMIT 2"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0B0D10">
<title>Admin Mobile - AsupanLendir</title>
<style>
:root{--bg:#0B0D10;--surface:#13171D;--surface2:#191E25;--border:#272D35;--gold:#EAB84D;--gold2:#F5C75B;--text:#F7F8FA;--muted:#98A2B3;--muted2:#667085;--success:#22C55E;--danger:#EF4444}
*{box-sizing:border-box}html{background:var(--bg)}body{margin:0;background:radial-gradient(circle at 100% -5%,rgba(234,184,77,.08),transparent 30%),var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}a{color:inherit;text-decoration:none}button{font:inherit}.app{width:min(760px,100%);margin:auto;padding-bottom:104px}.header{position:sticky;top:0;z-index:40;background:rgba(11,13,16,.9);backdrop-filter:blur(18px);border-bottom:1px solid rgba(255,255,255,.04)}.header-in{height:72px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}.brand{display:flex;align-items:center;gap:11px;min-width:0}.mark{width:40px;height:40px;object-fit:cover;border-radius:50%;border:1px solid rgba(234,184,77,.28);box-shadow:0 0 0 4px rgba(234,184,77,.04)}.brand strong{display:block;font-size:15px;line-height:1.1}.brand span{display:block;margin-top:4px;color:var(--gold);font-size:9px;font-weight:800;letter-spacing:1.5px}.icon-btn{width:40px;height:40px;display:grid;place-items:center;color:var(--muted);background:var(--surface);border:1px solid var(--border);border-radius:12px}.main{padding:22px 16px 28px}.eyebrow{color:var(--gold);font-size:10px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase}.hero h1{margin:7px 0 0;font-size:28px;line-height:1.1;letter-spacing:-.8px}.hero p{margin:8px 0 0;color:var(--muted);font-size:12px;line-height:1.55}.stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px;margin:20px 0 24px}.stat{min-height:116px;padding:15px;background:linear-gradient(155deg,rgba(255,255,255,.018),rgba(255,255,255,0)),var(--surface);border:1px solid var(--border);border-radius:16px}.stat-h{display:flex;justify-content:space-between;align-items:center;color:var(--muted);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px}.stat-h svg{color:var(--gold)}.stat-v{margin-top:20px;font-size:28px;line-height:1;font-weight:800;letter-spacing:-.8px}.stat-s{margin-top:7px;color:var(--muted2);font-size:9px}.section{margin-top:24px}.section-h{display:flex;align-items:end;justify-content:space-between;gap:10px;margin-bottom:11px}.section-h h2{margin:0;font-size:15px}.section-h a{display:flex;align-items:center;gap:5px;color:var(--gold);font-size:10px;font-weight:700}.quick,.list{display:grid;gap:9px}.action{min-height:64px;display:flex;align-items:center;gap:12px;padding:12px 13px;background:var(--surface);border:1px solid var(--border);border-radius:15px}.action-i{width:40px;height:40px;flex:0 0 40px;display:grid;place-items:center;color:var(--gold);background:rgba(234,184,77,.08);border:1px solid rgba(234,184,77,.14);border-radius:12px}.action-c{min-width:0;flex:1}.action-c strong{display:block;font-size:12px}.action-c span{display:block;margin-top:4px;color:var(--muted);font-size:9px}.arrow{color:var(--muted2)}.video{display:grid;grid-template-columns:92px minmax(0,1fr) 30px;gap:12px;align-items:center;min-height:88px;padding:10px;background:var(--surface);border:1px solid var(--border);border-radius:15px}.thumb{width:92px;height:68px;object-fit:cover;background:#07080A;border-radius:11px}.vtitle{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:11px;line-height:1.35;font-weight:700}.meta{display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin-top:9px;color:var(--muted);font-size:9px}.status{display:inline-flex;align-items:center;gap:5px}.status:before{content:"";width:6px;height:6px;border-radius:50%;background:var(--success);box-shadow:0 0 0 3px rgba(34,197,94,.08)}.album{display:flex;align-items:center;gap:12px;padding:12px;background:var(--surface);border:1px solid var(--border);border-radius:15px}.album-i{width:42px;height:42px;display:grid;place-items:center;flex:0 0 42px;color:var(--gold);background:rgba(234,184,77,.07);border-radius:12px}.album-c{flex:1;min-width:0}.album-c strong{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:11px}.album-c span{display:block;margin-top:5px;color:var(--muted);font-size:9px}.empty{padding:24px 16px;text-align:center;color:var(--muted);background:var(--surface);border:1px dashed var(--border);border-radius:15px;font-size:11px}.bottom{position:fixed;left:50%;bottom:0;transform:translateX(-50%);z-index:60;width:min(760px,100%);height:78px;padding:8px 10px calc(8px + env(safe-area-inset-bottom));display:grid;grid-template-columns:repeat(5,1fr);background:rgba(16,19,24,.97);backdrop-filter:blur(18px);border-top:1px solid var(--border);box-shadow:0 -14px 40px rgba(0,0,0,.24)}.nav{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;padding:4px;color:var(--muted2);background:transparent;border:0;border-radius:12px}.nav span{font-size:8px;font-weight:700}.nav.active{color:var(--gold)}.backdrop{position:fixed;inset:0;z-index:70;background:rgba(0,0,0,.56);opacity:0;pointer-events:none;transition:.2s}.sheet{position:fixed;left:50%;bottom:0;transform:translate(-50%,105%);z-index:80;width:min(760px,100%);padding:9px 16px calc(22px + env(safe-area-inset-bottom));background:#11151A;border:1px solid var(--border);border-bottom:0;border-radius:24px 24px 0 0;box-shadow:0 18px 50px rgba(0,0,0,.35);transition:transform .24s}.more-open{overflow:hidden}.more-open .backdrop{opacity:1;pointer-events:auto}.more-open .sheet{transform:translate(-50%,0)}.handle{width:40px;height:4px;margin:0 auto 15px;border-radius:999px;background:#343B44}.sheet-h{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:15px}.sheet-h strong{display:block;font-size:16px}.sheet-h span{display:block;margin-top:3px;color:var(--muted);font-size:9px}.more-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.more-grid a,.more-grid form{min-height:58px;display:flex;align-items:center;gap:10px;padding:11px;background:var(--surface);border:1px solid var(--border);border-radius:14px;font-size:11px;font-weight:700}.more-grid form{margin:0}.more-grid form button{width:100%;height:100%;display:flex;align-items:center;gap:10px;padding:0;background:transparent;border:0;color:inherit;text-align:left;font:inherit;font-weight:700;cursor:pointer}.more-grid svg{color:var(--gold)}.more-grid .danger{color:#FF7A7A}.more-grid .danger svg{color:#FF7A7A}@media(min-width:700px){.main{padding-left:22px;padding-right:22px}.stats{grid-template-columns:repeat(4,1fr)}.quick{grid-template-columns:repeat(3,1fr)}}
</style>
</head>
<body>
<div class="app">
<header class="header"><div class="header-in"><div class="brand"><img class="mark" src="/assets/brand-mark.png" alt=""><div><strong>AsupanLendir</strong><span>ADMIN</span></div></div><a class="icon-btn" href="/" target="_blank" rel="noopener" aria-label="Buka website"><?= m_icon('globe',19) ?></a></div></header>
<main class="main">
<section class="hero"><div class="eyebrow">Control Center</div><h1>Dashboard</h1><p>Ringkasan website dan akses cepat untuk pekerjaan yang paling sering dipakai dari HP.</p></section>
<section class="stats">
<article class="stat"><div class="stat-h"><span>Video</span><?= m_icon('play',17) ?></div><div class="stat-v"><?= number_format($videoCount) ?></div><div class="stat-s">Published</div></article>
<article class="stat"><div class="stat-h"><span>Kategori</span><?= m_icon('album',17) ?></div><div class="stat-v"><?= number_format($albumCount) ?></div><div class="stat-s">Published</div></article>
<article class="stat"><div class="stat-h"><span>Total Views</span><?= m_icon('eye',17) ?></div><div class="stat-v"><?= number_format($totalViews) ?></div><div class="stat-s">Video + album</div></article>
<article class="stat"><div class="stat-h"><span>7 Hari</span><?= m_icon('chart',17) ?></div><div class="stat-v"><?= number_format($unique7) ?></div><div class="stat-s">Unique activity</div></article>
</section>
<section class="section"><div class="section-h"><h2>Quick Actions</h2></div><div class="quick">
<a class="action" href="/admin/videos-mobile.php"><span class="action-i"><?= m_icon('plus',20) ?></span><span class="action-c"><strong>Tambah / Kelola Video</strong><span>Buka Video Manager yang sekarang</span></span><span class="arrow"><?= m_icon('arrow',17) ?></span></a>
<a class="action" href="/admin/categories-mobile.php"><span class="action-i"><?= m_icon('album',20) ?></span><span class="action-c"><strong>Kelola Kategori</strong><span>Buat dan atur kategori video</span></span><span class="arrow"><?= m_icon('arrow',17) ?></span></a>
<a class="action" href="/admin/ads-mobile.php"><span class="action-i"><?= m_icon('ads',20) ?></span><span class="action-c"><strong>Kelola Iklan</strong><span>Slot, test mode, dan ad code</span></span><span class="arrow"><?= m_icon('arrow',17) ?></span></a>
</div></section>
<section class="section"><div class="section-h"><h2>Video Terbaru</h2><a href="/admin/videos-mobile.php">Semua <?= m_icon('arrow',14) ?></a></div><div class="list">
<?php if (!$recentVideos): ?><div class="empty">Belum ada video.</div><?php else: ?>
<?php foreach ($recentVideos as $v): ?><a class="video" href="/v/<?= rawurlencode((string)$v['video_key']) ?>" target="_blank" rel="noopener">
<?php if (!empty($v['thumbnail_url'])): ?><img class="thumb" src="<?= m_e((string)$v['thumbnail_url']) ?>" alt="" loading="lazy"><?php else: ?><div class="thumb"></div><?php endif; ?>
<div><div class="vtitle"><?= m_e((string)$v['title']) ?></div><div class="meta"><span class="status"><?= m_e(ucfirst((string)$v['status'])) ?></span><span><?= number_format((int)$v['views']) ?> views</span></div></div><span class="arrow"><?= m_icon('arrow',17) ?></span></a><?php endforeach; ?>
<?php endif; ?></div></section>
<?php if ($recentAlbums): ?><section class="section"><div class="section-h"><h2>Album Terbaru</h2><a href="/admin/categories-mobile.php">Semua <?= m_icon('arrow',14) ?></a></div><div class="list"><?php foreach ($recentAlbums as $a): ?><a class="album" href="/c/<?= rawurlencode((string)$a['collection_key']) ?>" target="_blank" rel="noopener"><span class="album-i"><?= m_icon('album',20) ?></span><span class="album-c"><strong><?= m_e((string)$a['title']) ?></strong><span><?= number_format((int)$a['views']) ?> views</span></span><?= m_icon('arrow',17) ?></a><?php endforeach; ?></div></section><?php endif; ?>
</main>
</div>
<nav class="bottom" aria-label="Admin mobile navigation">
<a class="nav active" href="/admin/mobile.php"><?= m_icon('home',21) ?><span>Home</span></a>
<a class="nav" href="/admin/videos-mobile.php"><?= m_icon('play',21) ?><span>Video</span></a>
<a class="nav" href="/admin/categories-mobile.php"><?= m_icon('album',21) ?><span>Kategori</span></a>
<a class="nav" href="/admin/ads-mobile.php"><?= m_icon('ads',21) ?><span>Ads</span></a>
<button class="nav" type="button" id="moreOpen"><?= m_icon('more',21) ?><span>More</span></button>
</nav>
<div class="backdrop" id="backdrop"></div>
<section class="sheet" id="sheet" aria-hidden="true"><div class="handle"></div><div class="sheet-h"><div><strong>More</strong><span>Admin tools</span></div><button class="icon-btn" type="button" id="moreClose" aria-label="Tutup"><?= m_icon('close',20) ?></button></div><div class="more-grid">
<a href="/admin/analytics-mobile.php"><?= m_icon('chart',20) ?><span>Analytics</span></a>
<a href="/admin/seo-mobile.php"><?= m_icon('seo',20) ?><span>SEO</span></a>
<a href="/admin/settings-mobile.php"><?= m_icon('settings',20) ?><span>Settings</span></a>
<a href="/admin/mirrors.php"><?= m_icon('mirror',20) ?><span>Mirrors</span></a>
<a href="/" target="_blank" rel="noopener"><?= m_icon('globe',20) ?><span>Website</span></a>
<form method="post" action="/admin/logout.php" class="danger"><?= admin_csrf_input() ?><button type="submit"><?= m_icon('logout',20) ?><span>Logout</span></button></form>
</div></section>
<script>
(()=>{const b=document.body,o=document.getElementById('moreOpen'),c=document.getElementById('moreClose'),x=document.getElementById('backdrop'),s=document.getElementById('sheet');const set=v=>{b.classList.toggle('more-open',v);s.setAttribute('aria-hidden',v?'false':'true')};o?.addEventListener('click',()=>set(true));c?.addEventListener('click',()=>set(false));x?.addEventListener('click',()=>set(false));document.addEventListener('keydown',e=>{if(e.key==='Escape')set(false)})})();
</script>
</body>
</html>
