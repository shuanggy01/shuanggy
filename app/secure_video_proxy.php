<?php

declare(strict_types=1);

/**
 * AsupanLendir Secure Video Proxy V1
 *
 * Token format:
 *   base64url( 12-byte IV || AES-GCM ciphertext || 16-byte tag )
 *
 * The secret itself is never sent to the browser.
 */

function svp_config(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $file =
        dirname(__DIR__)
        . '/config/secure-video-proxy.php';

    if (!is_file($file)) {
        throw new RuntimeException(
            'Secure Video Proxy belum dikonfigurasi.'
        );
    }

    $loaded = require $file;

    if (!is_array($loaded)) {
        throw new RuntimeException(
            'Konfigurasi Secure Video Proxy tidak valid.'
        );
    }

    $secret = trim(
        (string) ($loaded['secret'] ?? '')
    );

    $baseUrl = rtrim(
        trim(
            (string) (
                $loaded['proxy_base_url']
                ?? 'https://v.asupanlendir.sbs'
            )
        ),
        '/'
    );

    $ttl = (int) ($loaded['token_ttl'] ?? 7200);

    if (strlen($secret) < 32) {
        throw new RuntimeException(
            'AL_PROXY_SECRET server belum valid.'
        );
    }

    if (
        !filter_var(
            $baseUrl,
            FILTER_VALIDATE_URL
        )
        || strtolower(
            (string) parse_url(
                $baseUrl,
                PHP_URL_SCHEME
            )
        ) !== 'https'
    ) {
        throw new RuntimeException(
            'proxy_base_url harus HTTPS.'
        );
    }

    if ($ttl < 300 || $ttl > 10800) {
        $ttl = 7200;
    }

    $config = [
        'secret' => $secret,
        'proxy_base_url' => $baseUrl,
        'token_ttl' => $ttl,
        'cookie_name' => 'alvp_session',
        'cookie_domain' => '.asupanlendir.sbs',
    ];

    return $config;
}

function svp_base64url_encode(string $data): string
{
    return rtrim(
        strtr(
            base64_encode($data),
            '+/',
            '-_'
        ),
        '='
    );
}

function svp_safe_filename(string $title): string
{
    $title = trim($title);

    if ($title === '') {
        return 'video.mp4';
    }

    $title = preg_replace(
        '/[^\p{L}\p{N}\s._-]+/u',
        '',
        $title
    ) ?? '';

    $title = preg_replace(
        '/\s+/u',
        ' ',
        trim($title)
    ) ?? '';

    if ($title === '') {
        $title = 'video';
    }

    if (!preg_match('/\.mp4$/i', $title)) {
        $title .= '.mp4';
    }

    if (mb_strlen($title, 'UTF-8') > 120) {
        $title =
            mb_substr(
                $title,
                0,
                116,
                'UTF-8'
            )
            . '.mp4';
    }

    return $title;
}

function svp_session_value(): string
{
    $config = svp_config();

    $cookieName =
        (string) $config['cookie_name'];

    $existing = trim(
        (string) (
            $_COOKIE[$cookieName]
            ?? ''
        )
    );

    if (
        preg_match(
            '/^[a-f0-9]{64}$/',
            $existing
        )
    ) {
        return $existing;
    }

    $value = bin2hex(
        random_bytes(32)
    );

    setcookie(
        $cookieName,
        $value,
        [
            'expires' =>
                time() + 604800,
            'path' => '/',
            'domain' =>
                (string) $config['cookie_domain'],
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );

    /*
     * Make the generated value available during this request too.
     */
    $_COOKIE[$cookieName] = $value;

    return $value;
}

function svp_make_token(
    string $videoUrl,
    string $sessionValue,
    bool $download = false,
    string $filename = 'video.mp4'
): string {
    $config = svp_config();

    if (
        !filter_var(
            $videoUrl,
            FILTER_VALIDATE_URL
        )
    ) {
        throw new RuntimeException(
            'URL video tidak valid.'
        );
    }

    $scheme = strtolower(
        (string) parse_url(
            $videoUrl,
            PHP_URL_SCHEME
        )
    );

    if (!in_array(
        $scheme,
        ['https', 'http'],
        true
    )) {
        throw new RuntimeException(
            'Protocol origin video tidak didukung.'
        );
    }

    $now = time();

    $payload = [
        'v' => 1,
        'u' => $videoUrl,
        'iat' => $now,
        'exp' =>
            $now + (int) $config['token_ttl'],
        'sid' => hash(
            'sha256',
            $sessionValue
        ),
        'dl' => $download ? 1 : 0,
        'fn' => svp_safe_filename(
            $filename
        ),
    ];

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        throw new RuntimeException(
            'Gagal membuat payload proxy.'
        );
    }

    $key = hash(
        'sha256',
        (string) $config['secret'],
        true
    );

    $iv = random_bytes(12);
    $tag = '';

    $ciphertext = openssl_encrypt(
        $json,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if (
        $ciphertext === false
        || strlen($tag) !== 16
    ) {
        throw new RuntimeException(
            'Gagal mengenkripsi token video.'
        );
    }

    return svp_base64url_encode(
        $iv
        . $ciphertext
        . $tag
    );
}

function svp_proxy_url(
    string $videoUrl,
    bool $download = false,
    string $filename = 'video.mp4'
): string {
    $config = svp_config();

    $session = svp_session_value();

    $token = svp_make_token(
        $videoUrl,
        $session,
        $download,
        $filename
    );

    return
        (string) $config['proxy_base_url']
        . '/p/'
        . rawurlencode($token);
}
