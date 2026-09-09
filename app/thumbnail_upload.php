<?php

declare(strict_types=1);

/**
 * Thumbnail Upload V1
 *
 * Uses the existing Cloudflare R2 credentials already stored
 * server-side for the Telegram bot.
 */

function thumb_r2_config(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $root = dirname(__DIR__);

    $candidates = [
        $root . '/config/r2.php',
        $root . '/config/telegram.php',
    ];

    foreach ($candidates as $file) {
        if (!is_file($file)) {
            continue;
        }

        $loaded = require $file;

        if (!is_array($loaded)) {
            continue;
        }

        if (
            !empty($loaded['r2_account_id'])
            && !empty($loaded['r2_access_key'])
            && !empty($loaded['r2_secret_key'])
            && !empty($loaded['r2_bucket'])
            && !empty($loaded['r2_public_url'])
        ) {
            $config = $loaded;
            return $config;
        }
    }

    throw new RuntimeException(
        'Konfigurasi Cloudflare R2 tidak ditemukan.'
    );
}

function thumb_r2_encode_path(string $path): string
{
    return implode(
        '/',
        array_map(
            'rawurlencode',
            explode('/', $path)
        )
    );
}

function thumb_upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE =>
            'Ukuran gambar melebihi batas upload PHP server.',
        UPLOAD_ERR_FORM_SIZE =>
            'Ukuran gambar terlalu besar.',
        UPLOAD_ERR_PARTIAL =>
            'Upload gambar tidak selesai. Coba lagi.',
        UPLOAD_ERR_NO_FILE =>
            'Tidak ada file thumbnail yang dipilih.',
        UPLOAD_ERR_NO_TMP_DIR =>
            'Folder temporary upload server tidak tersedia.',
        UPLOAD_ERR_CANT_WRITE =>
            'Server gagal menyimpan file upload sementara.',
        UPLOAD_ERR_EXTENSION =>
            'Upload dihentikan oleh ekstensi PHP.',
        default =>
            'Upload thumbnail gagal.',
    };
}

function thumb_validate_upload(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            thumb_upload_error_message($error)
        );
    }

    $tmp = (string) ($file['tmp_name'] ?? '');

    if (
        $tmp === ''
        || !is_uploaded_file($tmp)
        || !is_file($tmp)
    ) {
        throw new RuntimeException(
            'File upload thumbnail tidak valid.'
        );
    }

    $size = (int) ($file['size'] ?? 0);
    $max = 5 * 1024 * 1024;

    if ($size <= 0) {
        throw new RuntimeException(
            'File thumbnail kosong.'
        );
    }

    if ($size > $max) {
        throw new RuntimeException(
            'Thumbnail maksimal 5 MB.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException(
            'Format thumbnail harus JPG, PNG, atau WEBP.'
        );
    }

    $imageInfo = @getimagesize($tmp);

    if (!is_array($imageInfo)) {
        throw new RuntimeException(
            'File yang dipilih bukan gambar yang valid.'
        );
    }

    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);

    if ($width < 50 || $height < 50) {
        throw new RuntimeException(
            'Resolusi thumbnail terlalu kecil.'
        );
    }

    if ($width > 12000 || $height > 12000) {
        throw new RuntimeException(
            'Resolusi thumbnail terlalu besar.'
        );
    }

    return [
        'tmp_name' => $tmp,
        'size' => $size,
        'mime' => $mime,
        'extension' => $allowed[$mime],
        'width' => $width,
        'height' => $height,
    ];
}

function thumb_r2_put(
    string $filePath,
    string $objectPath,
    string $contentType
): void {
    $config = thumb_r2_config();

    $content = file_get_contents($filePath);

    if ($content === false) {
        throw new RuntimeException(
            'Gagal membaca file thumbnail.'
        );
    }

    $accountId = trim(
        (string) $config['r2_account_id']
    );

    $accessKey = trim(
        (string) $config['r2_access_key']
    );

    $secretKey = (string) $config['r2_secret_key'];

    $bucket = trim(
        (string) $config['r2_bucket']
    );

    $region = 'auto';
    $service = 's3';

    $host =
        $accountId
        . '.r2.cloudflarestorage.com';

    $datetime = gmdate('Ymd\THis\Z');
    $date = gmdate('Ymd');

    $payloadHash = hash(
        'sha256',
        $content
    );

    $canonicalUri =
        '/'
        . rawurlencode($bucket)
        . '/'
        . thumb_r2_encode_path($objectPath);

    $canonicalHeaders =
        "content-type:{$contentType}\n"
        . "host:{$host}\n"
        . "x-amz-content-sha256:{$payloadHash}\n"
        . "x-amz-date:{$datetime}\n";

    $signedHeaders =
        'content-type;host;x-amz-content-sha256;x-amz-date';

    $canonicalRequest =
        "PUT\n"
        . $canonicalUri
        . "\n\n"
        . $canonicalHeaders
        . "\n"
        . $signedHeaders
        . "\n"
        . $payloadHash;

    $scope =
        "{$date}/{$region}/{$service}/aws4_request";

    $stringToSign =
        "AWS4-HMAC-SHA256\n"
        . $datetime
        . "\n"
        . $scope
        . "\n"
        . hash(
            'sha256',
            $canonicalRequest
        );

    $kDate = hash_hmac(
        'sha256',
        $date,
        'AWS4' . $secretKey,
        true
    );

    $kRegion = hash_hmac(
        'sha256',
        $region,
        $kDate,
        true
    );

    $kService = hash_hmac(
        'sha256',
        $service,
        $kRegion,
        true
    );

    $kSigning = hash_hmac(
        'sha256',
        'aws4_request',
        $kService,
        true
    );

    $signature = hash_hmac(
        'sha256',
        $stringToSign,
        $kSigning
    );

    $authorization =
        'AWS4-HMAC-SHA256 Credential='
        . $accessKey
        . '/'
        . $scope
        . ', SignedHeaders='
        . $signedHeaders
        . ', Signature='
        . $signature;

    $ch = curl_init(
        'https://'
        . $host
        . $canonicalUri
    );

    curl_setopt_array(
        $ch,
        [
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
        ]
    );

    $response = curl_exec($ch);

    if ($response === false) {
        $message = curl_error($ch);
        curl_close($ch);

        throw new RuntimeException(
            'Upload R2 gagal: ' . $message
        );
    }

    $code = (int) curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if (
        !in_array(
            $code,
            [200, 201, 204],
            true
        )
    ) {
        throw new RuntimeException(
            'Cloudflare R2 menolak upload '
            . '(HTTP '
            . $code
            . ').'
        );
    }
}

function thumb_upload_to_r2(
    array $file,
    string $videoKey
): string {
    $validated =
        thumb_validate_upload($file);

    $safeKey = preg_replace(
        '/[^A-Za-z0-9_-]/',
        '',
        $videoKey
    ) ?? '';

    if ($safeKey === '') {
        $safeKey = 'video';
    }

    $objectPath =
        'thumb/manual/'
        . date('Y/m/')
        . $safeKey
        . '-'
        . bin2hex(random_bytes(10))
        . '.'
        . $validated['extension'];

    thumb_r2_put(
        $validated['tmp_name'],
        $objectPath,
        $validated['mime']
    );

    $config = thumb_r2_config();

    return
        rtrim(
            (string) $config['r2_public_url'],
            '/'
        )
        . '/'
        . $objectPath;
}
