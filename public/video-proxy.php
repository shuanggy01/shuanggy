<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/app/secure_video_proxy.php';

$key = trim(
    (string) ($_GET['id'] ?? '')
);

if (
    $key === ''
    || !preg_match(
        '/^[A-Za-z0-9_-]{4,64}$/',
        $key
    )
) {
    http_response_code(404);
    exit('Video tidak ditemukan.');
}

$stmt = $pdo->prepare(
    "SELECT
        video_key,
        title,
        video_url,
        status
     FROM videos
     WHERE video_key = :video_key
       AND status = 'published'
     LIMIT 1"
);

$stmt->execute([
    ':video_key' => $key,
]);

$video = $stmt->fetch(
    PDO::FETCH_ASSOC
);

if (!$video) {
    http_response_code(404);
    exit('Video tidak ditemukan.');
}

$videoUrl = trim(
    (string) ($video['video_url'] ?? '')
);

if (
    $videoUrl === ''
    || !filter_var(
        $videoUrl,
        FILTER_VALIDATE_URL
    )
) {
    http_response_code(404);
    exit('File video tidak tersedia.');
}

$download =
    isset($_GET['download'])
    && (string) $_GET['download'] === '1';

try {
    $proxyUrl = svp_proxy_url(
        $videoUrl,
        $download,
        (string) ($video['title'] ?? 'video')
    );
} catch (Throwable $e) {
    error_log(
        'Secure Video Proxy: '
        . $e->getMessage()
    );

    http_response_code(503);
    exit('Video proxy sementara tidak tersedia.');
}

header(
    'Cache-Control: no-store, private, max-age=0'
);

header(
    'Pragma: no-cache'
);

header(
    'X-Robots-Tag: noindex, nofollow',
    true
);

header(
    'Location: ' . $proxyUrl,
    true,
    302
);

exit;
