<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only.\n");

$config = require dirname(__DIR__) . '/config/telegram.php';

$endpoint = 'https://api.telegram.org/bot' . $config['bot_token'] . '/setWebhook';
$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'url' => rtrim($config['app_url'], '/') . '/telegram/bot.php',
        'secret_token' => $config['webhook_secret'],
        'allowed_updates' => json_encode(['message']),
        'drop_pending_updates' => 'true',
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_TIMEOUT => 30,
]);
$res = curl_exec($ch);
if ($res === false) {
    fwrite(STDERR, "CURL: " . curl_error($ch) . PHP_EOL);
    exit(1);
}
curl_close($ch);
echo $res . PHP_EOL;
