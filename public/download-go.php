<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/database.php';

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

$video = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$video) {
    http_response_code(404);
    exit('Video tidak ditemukan.');
}

$url = trim(
    (string) $video['video_url']
);

if (
    $url === ''
    || !filter_var(
        $url,
        FILTER_VALIDATE_URL
    )
) {
    http_response_code(404);
    exit('File video tidak tersedia.');
}

$scheme = strtolower(
    (string) parse_url(
        $url,
        PHP_URL_SCHEME
    )
);

if (!in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    exit('URL video tidak valid.');
}

header(
    'Cache-Control: no-store, private, max-age=0'
);

header(
    'X-Robots-Tag: noindex, nofollow',
    true
);

$proxyDownloadUrl =
    '/vp/'
    . rawurlencode($key)
    . '?download=1';

header(
    'Location: ' . $proxyDownloadUrl,
    true,
    302
);

exit;
