<?php

declare(strict_types=1);

$envFile = dirname(__DIR__) . '/.env';

if (!is_file($envFile)) {
    throw new RuntimeException('File .env tidak ditemukan.');
}

$env = parse_ini_file($envFile, false, INI_SCANNER_RAW);

if ($env === false) {
    throw new RuntimeException('File .env gagal dibaca.');
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $env['DB_HOST'],
    $env['DB_PORT'],
    $env['DB_DATABASE']
);

$pdo = new PDO(
    $dsn,
    $env['DB_USERNAME'],
    $env['DB_PASSWORD'],
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);
