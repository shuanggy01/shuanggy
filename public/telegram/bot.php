<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/database.php';

$config = require dirname(__DIR__, 2) . '/config/telegram.php';
$root = dirname(__DIR__, 2);

function cfg(string $key, string $default = ''): string {
    global $config;
    return isset($config[$key]) ? (string)$config[$key] : $default;
}

function botLog(string $message): void {
    global $root;
    @file_put_contents(
        $root . '/storage/logs/telegram.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function h(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function telegramRequest(string $method, array $fields): array {
    $url = 'https://api.telegram.org/bot' . cfg('bot_token') . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Telegram CURL: ' . $err);
    }
    curl_close($ch);
    $json = json_decode($body, true);
    if ($code < 200 || $code >= 300 || !is_array($json) || empty($json['ok'])) {
        botLog("Telegram API HTTP {$code}: {$body}");
        return ['ok' => false];
    }
    return $json;
}

function sendMessage(string|int $chatId, string $text, bool $preview = false): void {
    telegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => $preview ? 'false' : 'true',
    ]);
}

function sensorPath(): string {
    global $root;
    return $root . '/storage/bot/sensor.json';
}

function signaturePath(): string {
    global $root;
    return $root . '/storage/bot/signature.txt';
}

function sensorDictionary(): array {
    $file = sensorPath();
    if (!is_file($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function cleanTitle(string $title): string {
    $title = str_replace(['_', '-', '▶️', '✅', "\t", '||'], ' ', $title);
    $title = preg_replace('/\s+/u', ' ', trim($title)) ?? trim($title);
    $kamus = sensorDictionary();
    if ($kamus) {
        $title = str_ireplace(array_keys($kamus), array_values($kamus), $title);
    }
    if ($title === '') $title = 'Video Viral ' . random_int(1000, 9999);
    return mb_convert_case($title, MB_CASE_TITLE, 'UTF-8');
}

function normalizeUrl(string $url): string {
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;

    if (preg_match('#videy\.co/v/([^?/\s]+)#i', $url, $m)) {
        return 'https://cdn.videy.co/' . rawurlencode($m[1]) . '.mp4';
    }
    return $url;
}

function validHttpUrl(string $url): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

function isImageUrl(string $url): bool {
    $path = (string)parse_url($url, PHP_URL_PATH);
    return (bool)preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $path);
}

function parseEntries(string $text, int $skipLines = 0): array {
    $lines = preg_split('/\R/u', trim($text)) ?: [];
    if ($skipLines > 0) $lines = array_slice($lines, $skipLines);

    $entries = [];
    $tempTitle = '';

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        preg_match_all(
            '#(?:https?://)?[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}(?:/[^\s<>"\']*)?#i',
            $line,
            $matches
        );
        $urls = $matches[0] ?? [];

        if (!$urls) {
            $tempTitle = trim(str_replace(['▶️', '✅', "\t", '||'], '', $line));
            continue;
        }

        $titleText = $line;
        $videoUrl = '';
        $thumbUrl = '';

        foreach ($urls as $rawUrl) {
            $titleText = str_replace($rawUrl, '', $titleText);
            $url = normalizeUrl($rawUrl);
            if (!validHttpUrl($url)) continue;
            if (isImageUrl($url)) $thumbUrl = $url;
            else $videoUrl = $url;
        }

        $titleText = trim(str_replace(['▶️', '✅', "\t", '||'], '', $titleText));
        if ($titleText !== '') $title = $titleText;
        elseif ($tempTitle !== '') {
            $title = $tempTitle;
            $tempTitle = '';
        } else $title = 'Video Viral ' . random_int(1000, 9999);

        if ($videoUrl !== '') {
            $entries[] = [
                'title' => cleanTitle($title),
                'video_url' => $videoUrl,
                'thumbnail_url' => $thumbUrl,
            ];
        } elseif ($thumbUrl !== '' && $entries) {
            $last = array_key_last($entries);
            $entries[$last]['thumbnail_url'] = $thumbUrl;
        }
    }

    return $entries;
}

function extractThumbnail(string $videoUrl): string|false {
    $tmp = sys_get_temp_dir() . '/asupan_thumb_' . bin2hex(random_bytes(8)) . '.jpg';
    foreach (['00:00:02', '00:00:01', '00:00:00.5'] as $seek) {
        $cmd = 'timeout 40s ffmpeg -hide_banner -loglevel error -ss '
             . escapeshellarg($seek) . ' -i ' . escapeshellarg($videoUrl)
             . ' -frames:v 1 -q:v 3 -y ' . escapeshellarg($tmp) . ' 2>&1';
        $out = [];
        $rc = 0;
        exec($cmd, $out, $rc);
        if ($rc === 0 && is_file($tmp) && filesize($tmp) > 0) return $tmp;
        @unlink($tmp);
    }
    botLog('FFmpeg gagal: ' . $videoUrl);
    return false;
}

function encodeR2Path(string $path): string {
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function uploadToR2(string $filePath, string $fileName): bool {
    $content = file_get_contents($filePath);
    if ($content === false) return false;

    $accountId = cfg('r2_account_id');
    $accessKey = cfg('r2_access_key');
    $secretKey = cfg('r2_secret_key');
    $bucket = cfg('r2_bucket');
    if ($accountId === '' || $accessKey === '' || $secretKey === '' || $bucket === '') return false;

    $contentType = 'image/jpeg';
    $region = 'auto';
    $service = 's3';
    $host = $accountId . '.r2.cloudflarestorage.com';
    $datetime = gmdate('Ymd\THis\Z');
    $date = gmdate('Ymd');
    $payloadHash = hash('sha256', $content);
    $canonicalUri = '/' . rawurlencode($bucket) . '/' . encodeR2Path($fileName);

    $canonicalHeaders =
        "content-type:{$contentType}\n" .
        "host:{$host}\n" .
        "x-amz-content-sha256:{$payloadHash}\n" .
        "x-amz-date:{$datetime}\n";
    $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest =
        "PUT\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
    $scope = "{$date}/{$region}/{$service}/aws4_request";
    $stringToSign =
        "AWS4-HMAC-SHA256\n{$datetime}\n{$scope}\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization =
        "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, " .
        "SignedHeaders={$signedHeaders}, Signature={$signature}";

    $ch = curl_init('https://' . $host . $canonicalUri);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $content,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $authorization,
            'Content-Type: ' . $contentType,
            'Content-Length: ' . strlen($content),
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $datetime,
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) botLog('R2 CURL: ' . curl_error($ch));
    curl_close($ch);

    if (!in_array($code, [200, 201, 204], true)) {
        botLog("R2 HTTP {$code}: " . (string)$response);
        return false;
    }
    return true;
}

function autoThumbnail(string $videoUrl): string {
    $tmp = extractThumbnail($videoUrl);
    if ($tmp === false) return '';
    $name = 'thumb/' . date('Y/m/') . bin2hex(random_bytes(12)) . '.jpg';
    $ok = uploadToR2($tmp, $name);
    @unlink($tmp);
    return $ok ? rtrim(cfg('r2_public_url'), '/') . '/' . $name : '';
}

function uniqueKey(int $length = 9): string {
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $key = '';
    for ($i = 0; $i < $length; $i++) {
        $key .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $key;
}

function insertVideo(string $title, string $videoUrl, string $thumb = ''): array {
    global $pdo;

    for ($i = 0; $i < 10; $i++) {
        $key = uniqueKey(9);
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO videos
                 (video_key, title, description, video_url, thumbnail_url, views, status)
                 VALUES (?, ?, NULL, ?, ?, 0, 'published')"
            );
            $stmt->execute([$key, $title, $videoUrl, $thumb !== '' ? $thumb : null]);
            return ['id' => (int)$pdo->lastInsertId(), 'key' => $key];
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '23000') throw $e;
        }
    }
    throw new RuntimeException('Gagal membuat video key unik.');
}

function insertCollection(string $title, array $videoIds): string {
    global $pdo;
    $pdo->beginTransaction();
    try {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $key = uniqueKey(8);
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO collections (collection_key, title, status)
                     VALUES (?, ?, 'published')"
                );
                $stmt->execute([$key, $title]);
                break;
            } catch (PDOException $e) {
                if ((string)$e->getCode() !== '23000') throw $e;
                if ($attempt === 9) throw $e;
            }
        }
        $collectionId = (int)$pdo->lastInsertId();
        $rel = $pdo->prepare(
            "INSERT INTO collection_videos (collection_id, video_id, position)
             VALUES (?, ?, ?)"
        );
        foreach (array_values($videoIds) as $pos => $videoId) {
            $rel->execute([$collectionId, $videoId, $pos + 1]);
        }
        $pdo->commit();
        return $key;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function alreadyProcessed(int $updateId): bool {
    global $pdo;
    $stmt = $pdo->prepare("SELECT 1 FROM telegram_updates WHERE update_id = ? LIMIT 1");
    $stmt->execute([$updateId]);
    return (bool)$stmt->fetchColumn();
}

function markProcessed(int $updateId): void {
    global $pdo;
    $stmt = $pdo->prepare("INSERT IGNORE INTO telegram_updates (update_id) VALUES (?)");
    $stmt->execute([$updateId]);
}

/* --- Verifikasi request Telegram --- */
$expected = cfg('webhook_secret');
$received = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
    http_response_code(403);
    exit('Forbidden');
}

$update = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($update)) {
    http_response_code(200);
    exit('OK');
}

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_flush();
    @flush();
}

$updateId = (int)($update['update_id'] ?? 0);
if ($updateId <= 0 || alreadyProcessed($updateId)) exit;

$lockFile = sys_get_temp_dir() . '/asupan_bot_' . $updateId . '.lock';
$lock = fopen($lockFile, 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;

try {
    $message = $update['message'] ?? null;
    if (!is_array($message)) {
        markProcessed($updateId);
        exit;
    }

    $chatId = (string)($message['chat']['id'] ?? '');
    $fromId = (string)($message['from']['id'] ?? '');
    $text = trim((string)($message['text'] ?? $message['caption'] ?? ''));

    if ($chatId === '' || $text === '' || !hash_equals(cfg('admin_chat_id'), $fromId)) {
        markProcessed($updateId);
        exit;
    }

    $kamus = sensorDictionary();

    if ($text === '/start') {
        sendMessage($chatId,
            "🔥 <b>Halo Bosku! Bot AsupanLendir siap.</b>\n\n" .
            "Kirim judul + URL video untuk post.\n" .
            "Bulk post tetap didukung.\n\n" .
            "<code>/folder Judul Album</code>\n" .
            "<code>/add kata pengganti</code>\n" .
            "<code>/setsign teks</code>\n" .
            "<code>/stats</code>"
        );
        markProcessed($updateId);
        exit;
    }

    if ($text === '/stats') {

        $stats = $pdo->query("
            SELECT
                (
                    SELECT COUNT(*)
                    FROM videos
                    WHERE status = 'published'
                ) AS videos,

                (
                    SELECT COUNT(*)
                    FROM collections
                    WHERE status = 'published'
                ) AS albums,

                (
                    SELECT COALESCE(SUM(views), 0)
                    FROM videos
                    WHERE status = 'published'
                ) AS video_total_views,

                (
                    SELECT COALESCE(SUM(views), 0)
                    FROM collections
                    WHERE status = 'published'
                ) AS album_total_views,

                (
                    SELECT COUNT(*)
                    FROM video_views
                    WHERE view_date = CURRENT_DATE()
                ) AS video_today,

                (
                    SELECT COUNT(*)
                    FROM collection_views
                    WHERE view_date = CURRENT_DATE()
                ) AS album_today,

                (
                    SELECT COUNT(*)
                    FROM video_views
                    WHERE view_date >=
                        CURRENT_DATE() - INTERVAL 6 DAY
                ) AS video_7d,

                (
                    SELECT COUNT(*)
                    FROM collection_views
                    WHERE view_date >=
                        CURRENT_DATE() - INTERVAL 6 DAY
                ) AS album_7d,

                (
                    SELECT COUNT(*)
                    FROM video_views
                    WHERE view_date >=
                        CURRENT_DATE() - INTERVAL 29 DAY
                ) AS video_30d,

                (
                    SELECT COUNT(*)
                    FROM collection_views
                    WHERE view_date >=
                        CURRENT_DATE() - INTERVAL 29 DAY
                ) AS album_30d
        ")->fetch();


        $topVideos = $pdo->query("
            SELECT
                v.title,
                COUNT(vv.id) AS views_7d

            FROM videos v

            LEFT JOIN video_views vv
                ON vv.video_id = v.id
               AND vv.view_date >=
                   CURRENT_DATE() - INTERVAL 6 DAY

            WHERE v.status = 'published'

            GROUP BY
                v.id,
                v.title

            HAVING views_7d > 0

            ORDER BY
                views_7d DESC,
                v.created_at DESC

            LIMIT 3
        ")->fetchAll();


        $topAlbums = $pdo->query("
            SELECT
                c.title,
                COUNT(cv.id) AS views_7d

            FROM collections c

            LEFT JOIN collection_views cv
                ON cv.collection_id = c.id
               AND cv.view_date >=
                   CURRENT_DATE() - INTERVAL 6 DAY

            WHERE c.status = 'published'

            GROUP BY
                c.id,
                c.title

            HAVING views_7d > 0

            ORDER BY
                views_7d DESC,
                c.created_at DESC

            LIMIT 3
        ")->fetchAll();


        $today =
            (int) $stats['video_today'] +
            (int) $stats['album_today'];

        $week =
            (int) $stats['video_7d'] +
            (int) $stats['album_7d'];

        $month =
            (int) $stats['video_30d'] +
            (int) $stats['album_30d'];

        $totalViews =
            (int) $stats['video_total_views'] +
            (int) $stats['album_total_views'];


        $msg =
            "📊 <b>STATISTIK ASUPANLENDIR</b>\n\n" .

            "👁 Hari ini: <b>" .
            number_format($today) .
            "</b>\n" .

            "   🎬 Video: " .
            number_format((int) $stats['video_today']) .
            "\n" .

            "   📁 Album: " .
            number_format((int) $stats['album_today']) .
            "\n\n" .

            "🔥 7 hari: <b>" .
            number_format($week) .
            "</b>\n" .

            "📅 30 hari: <b>" .
            number_format($month) .
            "</b>\n\n" .

            "🎬 Video: <b>" .
            number_format((int) $stats['videos']) .
            "</b>\n" .

            "📁 Album: <b>" .
            number_format((int) $stats['albums']) .
            "</b>\n" .

            "👀 Total views: <b>" .
            number_format($totalViews) .
            "</b>";


        $msg .=
            "\n\n🏆 <b>Top Video 7 Hari</b>\n";

        if ($topVideos) {

            foreach ($topVideos as $i => $row) {

                $msg .=
                    ($i + 1) .
                    ". " .
                    h($row['title']) .
                    " — <b>" .
                    number_format(
                        (int) $row['views_7d']
                    ) .
                    "</b>\n";
            }

        } else {

            $msg .=
                "Belum ada view video baru.\n";
        }


        $msg .=
            "\n📁 <b>Top Album 7 Hari</b>\n";

        if ($topAlbums) {

            foreach ($topAlbums as $i => $row) {

                $msg .=
                    ($i + 1) .
                    ". " .
                    h($row['title']) .
                    " — <b>" .
                    number_format(
                        (int) $row['views_7d']
                    ) .
                    "</b>\n";
            }

        } else {

            $msg .=
                "Belum ada view album baru.\n";
        }


        sendMessage(
            $chatId,
            trim($msg)
        );

        markProcessed($updateId);
        exit;
    }

    if (preg_match('/^\/add\s+(\S+)\s+(.+)$/us', $text, $m)) {
        $ori = mb_strtolower(trim($m[1]));
        $ubah = trim($m[2]);
        $kamus[$ori] = $ubah;
        file_put_contents(
            sensorPath(),
            json_encode($kamus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
        sendMessage($chatId, "✅ <b>" . h($ori) . "</b> → <b>" . h($ubah) . "</b>");
        markProcessed($updateId);
        exit;
    }

    if (str_starts_with($text, '/setsign')) {
        $sign = trim(substr($text, 8));
        if ($sign === '') {
            sendMessage($chatId, "⚠️ Format: <code>/setsign teks_signature</code>");
        } else {
            file_put_contents(signaturePath(), $sign, LOCK_EX);
            sendMessage($chatId, "✅ <b>Signature diperbarui.</b>\n\n<blockquote>{$sign}</blockquote>");
        }
        markProcessed($updateId);
        exit;
    }

    $appUrl = rtrim(cfg('app_url'), '/');

    if (str_starts_with(strtolower($text), '/folder')) {
        $lines = preg_split('/\R/u', trim($text)) ?: [];
        $folderTitle = cleanTitle(trim(substr($lines[0] ?? '', 7)));
        if ($folderTitle === '') $folderTitle = 'Koleksi Video Viral ' . random_int(1000, 9999);

        $entries = parseEntries($text, 1);
        if (!$entries) {
            sendMessage($chatId, "⚠️ Tidak menemukan video untuk folder.");
            markProcessed($updateId);
            exit;
        }

        $ids = [];
        foreach ($entries as $entry) {
            $thumb = $entry['thumbnail_url'];
            if ($thumb === '') $thumb = autoThumbnail($entry['video_url']);
            $video = insertVideo($entry['title'], $entry['video_url'], $thumb);
            $ids[] = $video['id'];
        }

        $collectionKey = insertCollection($folderTitle, $ids);
        $link = $appUrl . '/c/' . rawurlencode($collectionKey);
        $reply =
            "<b>🔥 UPDATE ASUPAN</b>\n\n" .
            "▶️ <b>" . h($folderTitle) . " (ALBUM)</b>\n{$link}\n\n";

        $sign = is_file(signaturePath()) ? trim((string)file_get_contents(signaturePath())) : '';
        if ($sign !== '') $reply .= "<blockquote>{$sign}</blockquote>";

        sendMessage($chatId, trim($reply));
        if (cfg('channel_id') !== '') sendMessage(cfg('channel_id'), trim($reply), true);
        markProcessed($updateId);
        exit;
    }

    $entries = parseEntries($text);
    if (!$entries) {
        sendMessage($chatId,
            "⚠️ Tidak menemukan URL video.\n\n" .
            "Contoh:\n<code>Judul Video\nhttps://domain.com/video.mp4</code>"
        );
        markProcessed($updateId);
        exit;
    }

    $reply = "<b>🔥 UPDATE ASUPAN</b>\n\n";
    foreach ($entries as $entry) {
        $thumb = $entry['thumbnail_url'];
        if ($thumb === '') $thumb = autoThumbnail($entry['video_url']);

        $video = insertVideo($entry['title'], $entry['video_url'], $thumb);
        $link = $appUrl . '/v/' . rawurlencode($video['key']);

        $reply .= "▶️ " . h($entry['title']) . "\n{$link}\n\n";
    }

    $sign = is_file(signaturePath()) ? trim((string)file_get_contents(signaturePath())) : '';
    if ($sign !== '') $reply .= "<blockquote>{$sign}</blockquote>";

    sendMessage($chatId, trim($reply));
    if (cfg('channel_id') !== '') sendMessage(cfg('channel_id'), trim($reply), true);
    markProcessed($updateId);

} catch (Throwable $e) {
    botLog($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    try {
        $chatId = (string)($update['message']['chat']['id'] ?? '');
        if ($chatId !== '') sendMessage($chatId, "❌ Bot error. Cek <code>storage/logs/telegram.log</code>");
    } catch (Throwable $ignore) {}
} finally {
    @flock($lock, LOCK_UN);
    @fclose($lock);
    @unlink($lockFile);
}
