<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/database.php';

$key = trim((string) ($_GET['id'] ?? ''));

if ($key === '') {
    http_response_code(404);
    exit('Kategori tidak ditemukan.');
}

$stmt = $pdo->prepare(
    "SELECT slug
     FROM collections
     WHERE collection_key = :key
       AND category_enabled = 1
     LIMIT 1"
);

$stmt->execute([
    ':key' => $key,
]);

$slug = trim(
    (string) ($stmt->fetchColumn() ?: '')
);

if ($slug === '') {
    http_response_code(404);
    exit('Kategori tidak ditemukan.');
}

header(
    'Location: /kategori/'
    . rawurlencode($slug),
    true,
    301
);

exit;
