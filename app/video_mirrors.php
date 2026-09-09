<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ASUPANLENDIR - MULTI SERVER / VIDEO MIRRORS V1
|--------------------------------------------------------------------------
|
| Runtime config:
|   storage/video-mirrors.json
|
| Supported providers:
|   - lulustream -> https://luluvdo.com/e/{filecode}
|   - vidara     -> https://vidara.to/e/{filecode}
|
*/

function vm_defaults(): array
{
    return [
        'enabled' => false,
        'auto_import' => false,
        'start_after_video_id' => 0,
        'providers' => [
            'lulustream' => [
                'enabled' => true,
                'api_key' => '',
                'file_public' => 1,
                'file_adult' => 0,
            ],
            'vidara' => [
                'enabled' => true,
                'api_key' => '',
            ],
        ],
    ];
}

function vm_merge(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = vm_merge($base[$key], $value);
            continue;
        }

        $base[$key] = $value;
    }

    return $base;
}

function vm_config_path(): string
{
    return dirname(__DIR__) . '/storage/video-mirrors.json';
}

function vm_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = vm_defaults();
    $file = vm_config_path();

    if (!is_file($file)) {
        return $config;
    }

    $raw = file_get_contents($file);

    if ($raw === false) {
        return $config;
    }

    $runtime = json_decode($raw, true);

    if (!is_array($runtime)) {
        return $config;
    }

    return $config = vm_merge($config, $runtime);
}

function vm_write_config(array $config): void
{
    $storage = dirname(__DIR__) . '/storage';

    if (!is_dir($storage)) {
        if (!mkdir($storage, 0750, true) && !is_dir($storage)) {
            throw new RuntimeException('Folder storage tidak dapat dibuat.');
        }
    }

    if (!is_writable($storage)) {
        throw new RuntimeException('Folder storage tidak writable oleh PHP.');
    }

    $json = json_encode(
        $config,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        throw new RuntimeException('Gagal membuat JSON konfigurasi mirror.');
    }

    $target = vm_config_path();
    $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));

    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Gagal menulis konfigurasi mirror sementara.');
    }

    @chmod($tmp, 0640);

    if (!rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException('Gagal mengaktifkan konfigurasi mirror.');
    }
}

function vm_provider_label(string $provider): string
{
    return match ($provider) {
        'lulustream' => 'LuluStream',
        'vidara' => 'Vidara',
        default => ucfirst($provider),
    };
}

function vm_embed_url(string $provider, string $fileCode): string
{
    $fileCode = trim($fileCode);

    if ($fileCode === '' || !preg_match('/^[A-Za-z0-9_-]{4,128}$/', $fileCode)) {
        return '';
    }

    return match ($provider) {
        'lulustream' => 'https://luluvdo.com/e/' . rawurlencode($fileCode),
        'vidara' => 'https://vidara.to/e/' . rawurlencode($fileCode),
        default => '',
    };
}

function vm_ready_sources(PDO $pdo, int $videoId): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT provider, file_code, embed_url, progress, ready_at
             FROM video_sources
             WHERE video_id = ?
               AND status = 'ready'
               AND provider IN ('lulustream', 'vidara')
             ORDER BY FIELD(provider, 'lulustream', 'vidara'), id ASC"
        );

        $stmt->execute([$videoId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $result = [];

    foreach ($rows as $row) {
        $provider = (string) ($row['provider'] ?? '');
        $fileCode = (string) ($row['file_code'] ?? '');
        $embed = trim((string) ($row['embed_url'] ?? ''));

        if ($embed === '') {
            $embed = vm_embed_url($provider, $fileCode);
        }

        if ($embed === '') {
            continue;
        }

        $result[] = [
            'provider' => $provider,
            'label' => vm_provider_label($provider),
            'file_code' => $fileCode,
            'embed_url' => $embed,
        ];
    }

    return $result;
}

function vm_provider_enabled(array $config, string $provider): bool
{
    return !empty($config['enabled'])
        && !empty($config['providers'][$provider]['enabled'])
        && trim((string) ($config['providers'][$provider]['api_key'] ?? '')) !== '';
}

function vm_queue_video(
    PDO $pdo,
    int $videoId,
    string $videoUrl,
    ?array $config = null
): int {
    $config ??= vm_config();

    if (!preg_match('~^https?://~i', $videoUrl)) {
        throw new RuntimeException('Video URL harus URL HTTP/HTTPS langsung.');
    }

    $inserted = 0;

    foreach (['lulustream', 'vidara'] as $provider) {
        if (!vm_provider_enabled($config, $provider)) {
            continue;
        }

        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO video_sources
             (video_id, provider, remote_url, status, progress)
             VALUES (?, ?, ?, 'pending', 0)"
        );

        $stmt->execute([$videoId, $provider, $videoUrl]);
        $inserted += $stmt->rowCount();
    }

    return $inserted;
}

function vm_state_set(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO video_mirror_state (state_key, state_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE
            state_value = VALUES(state_value),
            updated_at = CURRENT_TIMESTAMP"
    );

    $stmt->execute([$key, $value]);
}

function vm_state_get(PDO $pdo, string $key, string $fallback = ''): string
{
    try {
        $stmt = $pdo->prepare(
            "SELECT state_value
             FROM video_mirror_state
             WHERE state_key = ?
             LIMIT 1"
        );
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $fallback : (string) $value;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function vm_http_json(string $baseUrl, array $query, int $timeout = 25): array
{
    $url = $baseUrl . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'AsupanLendir-MirrorWorker/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = $body === false ? curl_error($ch) : '';
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Provider connection error: ' . $curlError);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: AsupanLendir-MirrorWorker/1.0\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new RuntimeException('Provider connection error.');
        }

        $httpCode = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m)) {
                $httpCode = (int) $m[1];
            }
        }
    }

    $json = json_decode((string) $body, true);

    if (!is_array($json)) {
        throw new RuntimeException('Provider mengembalikan JSON tidak valid. HTTP ' . $httpCode);
    }

    return [
        'http_code' => $httpCode,
        'json' => $json,
    ];
}

function vm_upload_lulustream(string $url, array $providerConfig): string
{
    $query = [
        'key' => trim((string) ($providerConfig['api_key'] ?? '')),
        'url' => $url,
        'file_public' => !empty($providerConfig['file_public']) ? 1 : 0,
        'file_adult' => !empty($providerConfig['file_adult']) ? 1 : 0,
    ];

    $response = vm_http_json('https://lulustream.com/api/upload/url', $query);
    $json = $response['json'];

    if ((int) ($json['status'] ?? 0) !== 200) {
        throw new RuntimeException('LuluStream upload ditolak: ' . (string) ($json['msg'] ?? 'Unknown error'));
    }

    $fileCode = trim((string) ($json['result']['filecode'] ?? ''));

    if ($fileCode === '') {
        throw new RuntimeException('LuluStream tidak mengembalikan filecode.');
    }

    return $fileCode;
}

function vm_check_lulustream(string $fileCode, array $providerConfig): array
{
    $key = trim((string) ($providerConfig['api_key'] ?? ''));

    $queueResponse = vm_http_json(
        'https://lulustream.com/api/file/url_uploads',
        [
            'key' => $key,
            'file_code' => $fileCode,
        ]
    );

    $queue = $queueResponse['json'];
    $rows = is_array($queue['result'] ?? null) ? $queue['result'] : [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rowCode = trim((string) ($row['file_code'] ?? ''));

        if ($rowCode !== '' && $rowCode !== $fileCode) {
            continue;
        }

        $status = strtoupper(trim((string) ($row['status'] ?? 'PROCESSING')));
        $progress = max(0, min(99, (int) ($row['progress'] ?? 0)));

        if (preg_match('/ERROR|FAILED|CANCEL|DELETED/', $status)) {
            return [
                'status' => 'failed',
                'progress' => $progress,
                'error' => 'LuluStream queue status: ' . $status,
            ];
        }

        return [
            'status' => 'processing',
            'progress' => $progress,
            'error' => '',
        ];
    }

    $infoResponse = vm_http_json(
        'https://lulustream.com/api/file/info',
        [
            'key' => $key,
            'file_code' => $fileCode,
        ]
    );

    $info = $infoResponse['json'];
    $items = is_array($info['result'] ?? null) ? $info['result'] : [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        if ((string) ($item['file_code'] ?? '') !== $fileCode) {
            continue;
        }

        if ((int) ($item['canplay'] ?? 0) === 1) {
            return [
                'status' => 'ready',
                'progress' => 100,
                'error' => '',
            ];
        }

        $status = (string) ($item['status'] ?? '');

        if (in_array(strtolower($status), ['error', 'blocked', 'deleted'], true)) {
            return [
                'status' => 'failed',
                'progress' => 0,
                'error' => 'LuluStream file status: ' . $status,
            ];
        }
    }

    return [
        'status' => 'processing',
        'progress' => 99,
        'error' => '',
    ];
}

function vm_upload_vidara(string $url, array $providerConfig): string
{
    $response = vm_http_json(
        'https://api.vidara.so/v1/upload/url',
        [
            'api_key' => trim((string) ($providerConfig['api_key'] ?? '')),
            'url' => $url,
        ]
    );

    $json = $response['json'];

    if ((int) ($json['status'] ?? 0) !== 200) {
        throw new RuntimeException('Vidara upload ditolak: ' . (string) ($json['msg'] ?? 'Unknown error'));
    }

    $fileCode = trim((string) ($json['data']['filecode'] ?? ''));

    if ($fileCode === '') {
        throw new RuntimeException('Vidara tidak mengembalikan filecode.');
    }

    return $fileCode;
}

function vm_vidara_info(string $fileCode, string $apiKey): array
{
    $response = vm_http_json(
        'https://api.vidara.so/v1/video/info',
        [
            'api_key' => $apiKey,
            'filecode' => $fileCode,
        ]
    );

    $json = $response['json'];
    $rows = is_array($json['result'] ?? null) ? $json['result'] : [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        if ((string) ($row['filecode'] ?? '') !== $fileCode) {
            continue;
        }

        $status = strtolower(trim((string) ($row['status'] ?? '')));
        $active = (int) ($row['file_active'] ?? 0);

        if ($status === 'active' && $active === 1) {
            return ['status' => 'ready', 'progress' => 100, 'error' => ''];
        }

        if (in_array($status, ['blocked', 'error'], true)) {
            return [
                'status' => 'failed',
                'progress' => 0,
                'error' => 'Vidara file status: ' . $status,
            ];
        }
    }

    return ['status' => 'processing', 'progress' => 99, 'error' => ''];
}

function vm_check_vidara(string $fileCode, array $providerConfig): array
{
    $apiKey = trim((string) ($providerConfig['api_key'] ?? ''));

    $response = vm_http_json(
        'https://api.vidara.so/v1/video/status',
        [
            'api_key' => $apiKey,
            'filecode' => $fileCode,
        ]
    );

    $json = $response['json'];

    if ((int) ($json['status'] ?? 0) !== 200) {
        throw new RuntimeException('Vidara status gagal: ' . (string) ($json['msg'] ?? 'Unknown error'));
    }

    $encodings = $json['result']['encodings'] ?? null;

    if (is_array($encodings) && $encodings !== []) {
        $maxProgress = 0;

        foreach ($encodings as $encoding) {
            if (!is_array($encoding)) {
                continue;
            }

            $raw = (string) ($encoding['progress_percentage'] ?? '0');
            $progress = preg_replace('/\D+/', '', $raw);
            $maxProgress = max($maxProgress, (int) $progress);
        }

        $maxProgress = max(0, min(100, $maxProgress));

        if ($maxProgress < 100) {
            return [
                'status' => 'processing',
                'progress' => $maxProgress,
                'error' => '',
            ];
        }
    }

    return vm_vidara_info($fileCode, $apiKey);
}

function vm_queue_new_videos(PDO $pdo, array $config, int $limit = 20): int
{
    if (empty($config['enabled']) || empty($config['auto_import'])) {
        return 0;
    }

    $startAfter = max(0, (int) ($config['start_after_video_id'] ?? 0));

    $stmt = $pdo->prepare(
        "SELECT id, video_url
         FROM videos
         WHERE status = 'published'
           AND id > ?
           AND video_url REGEXP '^https?://'
         ORDER BY id ASC
         LIMIT " . max(1, min(100, $limit))
    );

    $stmt->execute([$startAfter]);
    $videos = $stmt->fetchAll();
    $queued = 0;

    foreach ($videos as $video) {
        $queued += vm_queue_video(
            $pdo,
            (int) $video['id'],
            (string) $video['video_url'],
            $config
        );
    }

    return $queued;
}

function vm_submit_pending(PDO $pdo, array $config, int $limit = 10): array
{
    $stmt = $pdo->query(
        "SELECT id, video_id, provider, remote_url, attempts
         FROM video_sources
         WHERE status = 'pending'
         ORDER BY id ASC
         LIMIT " . max(1, min(50, $limit))
    );

    $rows = $stmt->fetchAll();
    $submitted = 0;
    $failed = 0;

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $provider = (string) $row['provider'];
        $remoteUrl = (string) $row['remote_url'];
        $attempts = (int) $row['attempts'];

        if (!vm_provider_enabled($config, $provider)) {
            continue;
        }

        $claim = $pdo->prepare(
            "UPDATE video_sources
             SET status = 'submitting',
                 attempts = attempts + 1,
                 last_error = NULL,
                 last_checked_at = NOW()
             WHERE id = ?
               AND status = 'pending'"
        );
        $claim->execute([$id]);

        if ($claim->rowCount() !== 1) {
            continue;
        }

        try {
            $providerConfig = $config['providers'][$provider] ?? [];

            $fileCode = match ($provider) {
                'lulustream' => vm_upload_lulustream($remoteUrl, $providerConfig),
                'vidara' => vm_upload_vidara($remoteUrl, $providerConfig),
                default => throw new RuntimeException('Provider tidak didukung.'),
            };

            $embed = vm_embed_url($provider, $fileCode);

            $update = $pdo->prepare(
                "UPDATE video_sources
                 SET file_code = ?,
                     embed_url = ?,
                     status = 'processing',
                     progress = 0,
                     check_failures = 0,
                     last_error = NULL,
                     last_checked_at = NOW()
                 WHERE id = ?"
            );
            $update->execute([$fileCode, $embed, $id]);
            $submitted++;

        } catch (Throwable $e) {
            $nextAttempts = $attempts + 1;
            $newStatus = $nextAttempts >= 3 ? 'failed' : 'pending';

            $update = $pdo->prepare(
                "UPDATE video_sources
                 SET status = ?,
                     last_error = ?,
                     last_checked_at = NOW()
                 WHERE id = ?"
            );
            $update->execute([
                $newStatus,
                mb_substr($e->getMessage(), 0, 1000),
                $id,
            ]);

            if ($newStatus === 'failed') {
                $failed++;
            }
        }
    }

    return ['submitted' => $submitted, 'failed' => $failed];
}

function vm_recover_stale_submitting(PDO $pdo): int
{
    $stmt = $pdo->exec(
        "UPDATE video_sources
         SET status = CASE WHEN attempts >= 3 THEN 'failed' ELSE 'pending' END,
             last_error = CASE
                WHEN attempts >= 3 THEN 'Submission timeout setelah 3 percobaan.'
                ELSE 'Submission timeout; dijadwalkan ulang.'
             END
         WHERE status = 'submitting'
           AND last_checked_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );

    return (int) $stmt;
}

function vm_check_processing(PDO $pdo, array $config, int $limit = 30): array
{
    $stmt = $pdo->query(
        "SELECT id, provider, file_code, check_failures
         FROM video_sources
         WHERE status = 'processing'
           AND file_code IS NOT NULL
           AND file_code <> ''
           AND (
                last_checked_at IS NULL
                OR last_checked_at <= DATE_SUB(NOW(), INTERVAL 45 SECOND)
           )
         ORDER BY COALESCE(last_checked_at, '1970-01-01 00:00:00') ASC
         LIMIT " . max(1, min(100, $limit))
    );

    $rows = $stmt->fetchAll();
    $ready = 0;
    $failed = 0;
    $checked = 0;

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $provider = (string) $row['provider'];
        $fileCode = (string) $row['file_code'];

        if (!vm_provider_enabled($config, $provider)) {
            continue;
        }

        try {
            $providerConfig = $config['providers'][$provider] ?? [];

            $result = match ($provider) {
                'lulustream' => vm_check_lulustream($fileCode, $providerConfig),
                'vidara' => vm_check_vidara($fileCode, $providerConfig),
                default => throw new RuntimeException('Provider tidak didukung.'),
            };

            $status = (string) ($result['status'] ?? 'processing');
            $progress = max(0, min(100, (int) ($result['progress'] ?? 0)));
            $error = trim((string) ($result['error'] ?? ''));

            if ($status === 'ready') {
                $update = $pdo->prepare(
                    "UPDATE video_sources
                     SET status = 'ready',
                         progress = 100,
                         check_failures = 0,
                         last_error = NULL,
                         ready_at = NOW(),
                         last_checked_at = NOW()
                     WHERE id = ?"
                );
                $update->execute([$id]);
                $ready++;
            } elseif ($status === 'failed') {
                $update = $pdo->prepare(
                    "UPDATE video_sources
                     SET status = 'failed',
                         progress = ?,
                         last_error = ?,
                         last_checked_at = NOW()
                     WHERE id = ?"
                );
                $update->execute([$progress, mb_substr($error, 0, 1000), $id]);
                $failed++;
            } else {
                $update = $pdo->prepare(
                    "UPDATE video_sources
                     SET progress = ?,
                         check_failures = 0,
                         last_error = NULL,
                         last_checked_at = NOW()
                     WHERE id = ?"
                );
                $update->execute([$progress, $id]);
            }

            $checked++;

        } catch (Throwable $e) {
            $failures = (int) $row['check_failures'] + 1;

            $update = $pdo->prepare(
                "UPDATE video_sources
                 SET check_failures = ?,
                     last_error = ?,
                     last_checked_at = NOW()
                 WHERE id = ?"
            );
            $update->execute([
                min(255, $failures),
                mb_substr($e->getMessage(), 0, 1000),
                $id,
            ]);
        }
    }

    return [
        'checked' => $checked,
        'ready' => $ready,
        'failed' => $failed,
    ];
}

function vm_worker_run(PDO $pdo): array
{
    $config = vm_config();

    if (empty($config['enabled'])) {
        vm_state_set($pdo, 'last_worker_at', date('Y-m-d H:i:s'));
        vm_state_set($pdo, 'last_worker_summary', 'Mirror system OFF');

        return [
            'enabled' => false,
            'queued' => 0,
            'submitted' => 0,
            'checked' => 0,
            'ready' => 0,
            'failed' => 0,
            'recovered' => 0,
        ];
    }

    $recovered = vm_recover_stale_submitting($pdo);
    $queued = vm_queue_new_videos($pdo, $config, 20);
    $submission = vm_submit_pending($pdo, $config, 12);
    $checks = vm_check_processing($pdo, $config, 40);

    $summary = [
        'enabled' => true,
        'queued' => $queued,
        'submitted' => (int) $submission['submitted'],
        'checked' => (int) $checks['checked'],
        'ready' => (int) $checks['ready'],
        'failed' => (int) $submission['failed'] + (int) $checks['failed'],
        'recovered' => $recovered,
    ];

    vm_state_set($pdo, 'last_worker_at', date('Y-m-d H:i:s'));
    vm_state_set($pdo, 'last_worker_summary', json_encode($summary, JSON_UNESCAPED_SLASHES));

    return $summary;
}
