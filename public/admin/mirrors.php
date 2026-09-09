<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';
require dirname(__DIR__, 2) . '/app/video_mirrors.php';

admin_require_login();

function mm_e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function mm_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?"
        );

        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$dbReady = mm_table_exists($pdo, 'video_sources')
    && mm_table_exists($pdo, 'video_mirror_state');

$current = vm_config();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    try {
        $action = (string) ($_POST['action'] ?? 'save');

        if ($action === 'save') {
            $oldAuto = !empty($current['auto_import']);
            $newAuto = isset($_POST['auto_import']);

            $luluOldKey = (string) (
                $current['providers']['lulustream']['api_key']
                ?? ''
            );

            $vidaraOldKey = (string) (
                $current['providers']['vidara']['api_key']
                ?? ''
            );

            $luluNewKey = trim((string) ($_POST['lulu_api_key'] ?? ''));
            $vidaraNewKey = trim((string) ($_POST['vidara_api_key'] ?? ''));

            $startAfter = max(
                0,
                (int) ($current['start_after_video_id'] ?? 0)
            );

            if ($dbReady && $newAuto && !$oldAuto) {
                $startAfter = (int) $pdo->query(
                    'SELECT COALESCE(MAX(id), 0) FROM videos'
                )->fetchColumn();
            }

            $newConfig = [
                'enabled' => isset($_POST['enabled']),
                'auto_import' => $newAuto,
                'start_after_video_id' => $startAfter,
                'providers' => [
                    'lulustream' => [
                        'enabled' => isset($_POST['lulu_enabled']),
                        'api_key' => $luluNewKey !== ''
                            ? $luluNewKey
                            : $luluOldKey,
                        'file_public' => isset($_POST['lulu_public']) ? 1 : 0,
                        'file_adult' => isset($_POST['lulu_adult']) ? 1 : 0,
                    ],
                    'vidara' => [
                        'enabled' => isset($_POST['vidara_enabled']),
                        'api_key' => $vidaraNewKey !== ''
                            ? $vidaraNewKey
                            : $vidaraOldKey,
                    ],
                ],
            ];

            vm_write_config($newConfig);
            $current = vm_merge(vm_defaults(), $newConfig);
            $message = $newAuto && !$oldAuto
                ? 'Settings disimpan. Auto Import dimulai dari video BARU setelah saat ini.'
                : 'Settings Multi Server berhasil disimpan.';
        }

        if ($action === 'queue_video') {
            if (!$dbReady) {
                throw new RuntimeException('Migration database belum dipasang.');
            }

            $videoKey = trim((string) ($_POST['video_key'] ?? ''));

            if (!preg_match('/^[A-Za-z0-9_-]{4,20}$/', $videoKey)) {
                throw new RuntimeException('Video key tidak valid.');
            }

            $stmt = $pdo->prepare(
                "SELECT id, title, video_url
                 FROM videos
                 WHERE video_key = ?
                   AND status = 'published'
                 LIMIT 1"
            );
            $stmt->execute([$videoKey]);
            $video = $stmt->fetch();

            if (!$video) {
                throw new RuntimeException('Video published tidak ditemukan.');
            }

            $inserted = vm_queue_video(
                $pdo,
                (int) $video['id'],
                (string) $video['video_url'],
                $current
            );

            $message = $inserted > 0
                ? 'Video masuk antrean mirror: ' . $video['title'] . ' (' . $inserted . ' provider).'
                : 'Tidak ada antrean baru. Cek provider aktif/API key, atau video sudah pernah di-queue.';
        }

        if ($action === 'retry_failed') {
            if (!$dbReady) {
                throw new RuntimeException('Migration database belum dipasang.');
            }

            $count = (int) $pdo->exec(
                "UPDATE video_sources
                 SET status = 'pending',
                     attempts = 0,
                     check_failures = 0,
                     progress = 0,
                     last_error = NULL,
                     last_checked_at = NULL,
                     ready_at = NULL
                 WHERE status = 'failed'"
            );

            $message = 'Retry dijadwalkan untuk ' . $count . ' source gagal.';
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$counts = [
    'total' => 0,
    'pending' => 0,
    'processing' => 0,
    'ready' => 0,
    'failed' => 0,
];
$recent = [];
$lastWorkerAt = '-';
$lastWorkerSummary = '';

if ($dbReady) {
    $row = $pdo->query(
        "SELECT
            COUNT(*) AS total,
            SUM(status IN ('pending','submitting')) AS pending,
            SUM(status = 'processing') AS processing,
            SUM(status = 'ready') AS ready,
            SUM(status = 'failed') AS failed
         FROM video_sources"
    )->fetch();

    foreach ($counts as $key => $unused) {
        $counts[$key] = (int) ($row[$key] ?? 0);
    }

    $recent = $pdo->query(
        "SELECT
            s.id,
            s.provider,
            s.file_code,
            s.status,
            s.progress,
            s.last_error,
            s.updated_at,
            v.video_key,
            v.title
         FROM video_sources s
         INNER JOIN videos v ON v.id = s.video_id
         ORDER BY s.id DESC
         LIMIT 30"
    )->fetchAll();

    $lastWorkerAt = vm_state_get($pdo, 'last_worker_at', '-');
    $lastWorkerSummary = vm_state_get($pdo, 'last_worker_summary', '');
}

$luluConfigured = trim((string) (
    $current['providers']['lulustream']['api_key']
    ?? ''
)) !== '';

$vidaraConfigured = trim((string) (
    $current['providers']['vidara']['api_key']
    ?? ''
)) !== '';

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Multi Server V1 - AsupanLendir</title>
<style>
:root{
    --bg:#0F1115;
    --panel:#1A1F26;
    --panel2:#15191F;
    --line:#2A3038;
    --yellow:#FFC107;
    --text:#FFFFFF;
    --muted:#A7ADB3;
    --green:#48C774;
    --red:#FF5252;
    --blue:#4DA3FF;
}
*{box-sizing:border-box}
body{
    margin:0;
    background:radial-gradient(circle at 70% -20%,rgba(255,193,7,.055),transparent 34%),var(--bg);
    color:var(--text);
    font-family:Arial,Helvetica,sans-serif;
}
a{color:inherit}
.container{width:min(1180px,calc(100% - 24px));margin:auto}
header{
    position:sticky;top:0;z-index:30;
    background:rgba(15,17,21,.96);
    backdrop-filter:blur(12px);
    border-bottom:1px solid var(--line);
}
.topbar{min-height:67px;display:flex;justify-content:space-between;align-items:center;gap:12px}
.brand{font-size:20px;font-weight:800}
.actions{display:flex;gap:7px;flex-wrap:wrap}
.btn{
    display:inline-block;border:1px solid var(--line);background:var(--panel);color:#fff;
    text-decoration:none;border-radius:9px;padding:10px 13px;cursor:pointer;font-size:12px;
}
.btn.primary{background:var(--yellow);border-color:var(--yellow);color:#111318;font-weight:800}
.btn.danger{border-color:rgba(255,82,82,.35);color:#ffd5d5}
main{padding:24px 0 55px}
.notice{padding:12px 14px;margin-bottom:16px;border-radius:10px;border:1px solid var(--line);background:var(--panel);font-size:12px;line-height:1.5}
.notice.ok{border-color:rgba(72,199,116,.35)}
.notice.error{border-color:rgba(255,82,82,.35);color:#ffd5d5}
.summary{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:18px}
.stat{padding:14px;border:1px solid var(--line);background:var(--panel);border-radius:11px}
.stat-label{font-size:10px;color:var(--muted)}
.stat-value{margin-top:6px;font-size:21px;font-weight:800}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.card{border:1px solid var(--line);background:var(--panel);border-radius:13px;overflow:hidden;margin-bottom:14px}
.card-head{padding:14px 16px;border-bottom:1px solid var(--line);font-size:13px;font-weight:800}
.card-body{padding:16px}
.toggle{
    display:flex;align-items:flex-start;gap:9px;padding:12px;margin-bottom:10px;
    border:1px solid var(--line);background:var(--panel2);border-radius:10px;
}
.toggle input{margin-top:2px}
.toggle strong{display:block;font-size:12px}
.toggle span{display:block;margin-top:4px;color:var(--muted);font-size:10px;line-height:1.45}
.field{margin-bottom:13px}.field:last-child{margin-bottom:0}
label.field-label{display:block;margin-bottom:7px;font-size:11px;font-weight:700}
input[type="text"],input[type="password"]{
    width:100%;border:1px solid var(--line);background:var(--panel2);color:#fff;
    border-radius:9px;padding:11px 12px;outline:0;font:inherit;font-size:12px;
}
.help{margin-top:6px;color:var(--muted);font-size:10px;line-height:1.45}
.provider-status{display:inline-block;margin-left:7px;padding:4px 7px;border-radius:999px;font-size:9px;font-weight:800}
.provider-status.ok{color:var(--green);background:rgba(72,199,116,.08);border:1px solid rgba(72,199,116,.18)}
.provider-status.no{color:var(--red);background:rgba(255,82,82,.06);border:1px solid rgba(255,82,82,.18)}
.worker{display:flex;justify-content:space-between;gap:15px;align-items:start}
.worker code{display:block;margin-top:8px;padding:10px;background:#0d0f12;border:1px solid var(--line);border-radius:8px;color:#d7dbe0;overflow:auto;font-size:10px}
.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse;min-width:760px;font-size:11px}
th,td{padding:11px 10px;text-align:left;border-bottom:1px solid var(--line);vertical-align:top}
th{color:var(--muted);font-size:9px;text-transform:uppercase;letter-spacing:.4px}
.status{font-weight:800}
.status.ready{color:var(--green)}
.status.failed{color:var(--red)}
.status.processing{color:var(--blue)}
.status.pending,.status.submitting{color:var(--yellow)}
.error-text{max-width:260px;color:#d8a3a3;white-space:normal;line-height:1.4}
.savebar{
    position:sticky;bottom:10px;z-index:20;display:flex;justify-content:space-between;align-items:center;
    gap:12px;margin-top:17px;padding:12px;background:rgba(26,31,38,.95);backdrop-filter:blur(10px);
    border:1px solid var(--line);border-radius:12px;
}
.muted{color:var(--muted);font-size:10px;line-height:1.5}
@media(max-width:850px){.summary{grid-template-columns:repeat(3,1fr)}.grid{grid-template-columns:1fr}}
@media(max-width:520px){.container{width:calc(100% - 18px)}.summary{grid-template-columns:repeat(2,1fr)}.brand{font-size:17px}.worker{flex-direction:column}.savebar .muted{display:none}}
</style>
</head>
<body>
<header>
<div class="container topbar">
    <div class="brand">🪞 Multi Server V1</div>
    <div class="actions">
        <a class="btn" href="/admin/">← Dashboard</a>
        <a class="btn" href="/" target="_blank">🌐 Website</a>
    </div>
</div>
</header>

<main class="container">

<?php if ($message !== ''): ?>
<div class="notice ok">✅ <?= mm_e($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
<div class="notice error">❌ <?= mm_e($error) ?></div>
<?php endif; ?>

<?php if (!$dbReady): ?>
<div class="notice error">
    Database Multi Server belum dipasang. Jalankan dari root project:<br>
    <code>php scripts/install-video-mirrors-db.php</code>
</div>
<?php endif; ?>

<div class="summary">
    <div class="stat"><div class="stat-label">Total Source</div><div class="stat-value"><?= number_format($counts['total']) ?></div></div>
    <div class="stat"><div class="stat-label">Pending</div><div class="stat-value"><?= number_format($counts['pending']) ?></div></div>
    <div class="stat"><div class="stat-label">Processing</div><div class="stat-value"><?= number_format($counts['processing']) ?></div></div>
    <div class="stat"><div class="stat-label">Ready</div><div class="stat-value"><?= number_format($counts['ready']) ?></div></div>
    <div class="stat"><div class="stat-label">Failed</div><div class="stat-value"><?= number_format($counts['failed']) ?></div></div>
</div>

<form method="post">
<?= admin_csrf_input() ?>
<input type="hidden" name="action" value="save">

<div class="card">
<div class="card-head">Pengaturan Global</div>
<div class="card-body">
    <label class="toggle">
        <input type="checkbox" name="enabled" value="1" <?= !empty($current['enabled']) ? 'checked' : '' ?>>
        <div><strong>Aktifkan Multi Server</strong><span>Worker boleh mengirim dan mengecek mirror provider.</span></div>
    </label>

    <label class="toggle">
        <input type="checkbox" name="auto_import" value="1" <?= !empty($current['auto_import']) ? 'checked' : '' ?>>
        <div><strong>Auto Import Video Baru</strong><span>Saat baru diaktifkan, video lama tidak otomatis dikirim. Video yang diposting setelah itu akan masuk antrean.</span></div>
    </label>
</div>
</div>

<div class="grid">
<div class="card">
<div class="card-head">
    LuluStream
    <span class="provider-status <?= $luluConfigured ? 'ok' : 'no' ?>">
        <?= $luluConfigured ? 'KEY TERSIMPAN' : 'BELUM ADA KEY' ?>
    </span>
</div>
<div class="card-body">
    <label class="toggle">
        <input type="checkbox" name="lulu_enabled" value="1" <?= !empty($current['providers']['lulustream']['enabled']) ? 'checked' : '' ?>>
        <div><strong>Aktifkan LuluStream</strong><span>Embed: luluvdo.com/e/{filecode}</span></div>
    </label>

    <div class="field">
        <label class="field-label">API Key Baru</label>
        <input type="password" name="lulu_api_key" value="" autocomplete="new-password" placeholder="<?= $luluConfigured ? '•••••••• tersimpan — kosongkan untuk mempertahankan' : 'Paste API key baru' ?>">
        <div class="help">API key tidak pernah dikirim ke browser pengunjung.</div>
    </div>

    <label class="toggle">
        <input type="checkbox" name="lulu_public" value="1" <?= !empty($current['providers']['lulustream']['file_public']) ? 'checked' : '' ?>>
        <div><strong>Public File</strong><span>Kirim file_public=1 ke LuluStream.</span></div>
    </label>

    <label class="toggle">
        <input type="checkbox" name="lulu_adult" value="1" <?= !empty($current['providers']['lulustream']['file_adult']) ? 'checked' : '' ?>>
        <div><strong>Adult Flag</strong><span>Opsional. Aktifkan hanya jika memang sesuai konten dan aturan provider.</span></div>
    </label>
</div>
</div>

<div class="card">
<div class="card-head">
    Vidara
    <span class="provider-status <?= $vidaraConfigured ? 'ok' : 'no' ?>">
        <?= $vidaraConfigured ? 'KEY TERSIMPAN' : 'BELUM ADA KEY' ?>
    </span>
</div>
<div class="card-body">
    <label class="toggle">
        <input type="checkbox" name="vidara_enabled" value="1" <?= !empty($current['providers']['vidara']['enabled']) ? 'checked' : '' ?>>
        <div><strong>Aktifkan Vidara</strong><span>Embed: vidara.to/e/{filecode}</span></div>
    </label>

    <div class="field">
        <label class="field-label">API Key Baru</label>
        <input type="password" name="vidara_api_key" value="" autocomplete="new-password" placeholder="<?= $vidaraConfigured ? '•••••••• tersimpan — kosongkan untuk mempertahankan' : 'Paste API key baru' ?>">
        <div class="help">Field kosong mempertahankan key yang sudah tersimpan.</div>
    </div>
</div>
</div>
</div>

<div class="savebar">
    <div class="muted">Key disimpan di <code>storage/video-mirrors.json</code> di luar public.</div>
    <button class="btn primary" type="submit">💾 Simpan Settings</button>
</div>
</form>

<div class="card" style="margin-top:18px">
<div class="card-head">Worker / Cron</div>
<div class="card-body worker">
    <div>
        <strong style="font-size:12px">Last Worker: <?= mm_e($lastWorkerAt) ?></strong>
        <div class="muted" style="margin-top:5px">Worker memproses upload provider dan menunggu encoding sampai ready.</div>
        <code>php bin/video-mirror-worker.php</code>
        <div class="muted" style="margin-top:8px">Cron yang disarankan: setiap 1 menit. Installer cron tersedia di paket.</div>
        <?php if ($lastWorkerSummary !== ''): ?>
        <code><?= mm_e($lastWorkerSummary) ?></code>
        <?php endif; ?>
    </div>

    <form method="post">
        <?= admin_csrf_input() ?>
        <input type="hidden" name="action" value="retry_failed">
        <button class="btn danger" type="submit">↻ Retry Semua Failed</button>
    </form>
</div>
</div>

<div class="card">
<div class="card-head">Queue Video Lama untuk Test</div>
<div class="card-body">
    <form method="post">
        <?= admin_csrf_input() ?>
        <input type="hidden" name="action" value="queue_video">
        <div class="field">
            <label class="field-label">Video Key</label>
            <input type="text" name="video_key" placeholder="contoh: B4Dc0JUWc" maxlength="20">
            <div class="help">Ini hanya untuk queue manual satu video lama. Auto Import tetap fokus video baru.</div>
        </div>
        <button class="btn" type="submit">＋ Queue ke Provider Aktif</button>
    </form>
</div>
</div>

<div class="card">
<div class="card-head">Mirror Terbaru</div>
<div class="table-wrap">
<table>
<thead>
<tr><th>Video</th><th>Provider</th><th>Status</th><th>Progress</th><th>Filecode</th><th>Error</th><th>Update</th></tr>
</thead>
<tbody>
<?php if (!$recent): ?>
<tr><td colspan="7" class="muted">Belum ada mirror job.</td></tr>
<?php endif; ?>

<?php foreach ($recent as $row): ?>
<tr>
    <td><a href="/v/<?= rawurlencode((string) $row['video_key']) ?>" target="_blank"><?= mm_e($row['title']) ?></a></td>
    <td><?= mm_e(vm_provider_label((string) $row['provider'])) ?></td>
    <td class="status <?= mm_e((string) $row['status']) ?>"><?= mm_e(strtoupper((string) $row['status'])) ?></td>
    <td><?= number_format((int) $row['progress']) ?>%</td>
    <td><?= mm_e((string) ($row['file_code'] ?? '')) ?></td>
    <td class="error-text"><?= mm_e((string) ($row['last_error'] ?? '')) ?></td>
    <td><?= mm_e((string) $row['updated_at']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

</main>
</body>
</html>
