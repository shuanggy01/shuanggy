<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = dirname(__DIR__);

require $root . '/config/database.php';
require $root . '/app/video_mirrors.php';

$lockPath = sys_get_temp_dir() . '/asupanlendir-video-mirror-worker.lock';
$lock = fopen($lockPath, 'c+');

if ($lock === false) {
    fwrite(STDERR, "Tidak bisa membuat worker lock.\n");
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Worker lain masih berjalan. Skip.\n";
    fclose($lock);
    exit(0);
}

$logDir = $root . '/storage/logs';

if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}

$logFile = $logDir . '/video-mirrors.log';

try {
    $summary = vm_worker_run($pdo);

    $line = sprintf(
        "[%s] enabled=%s queued=%d submitted=%d checked=%d ready=%d failed=%d recovered=%d\n",
        date('Y-m-d H:i:s'),
        !empty($summary['enabled']) ? 'yes' : 'no',
        (int) $summary['queued'],
        (int) $summary['submitted'],
        (int) $summary['checked'],
        (int) $summary['ready'],
        (int) $summary['failed'],
        (int) $summary['recovered']
    );

    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

} catch (Throwable $e) {
    $line = sprintf(
        "[%s] ERROR: %s @ %s:%d\n",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    );

    fwrite(STDERR, $line);
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    exit(1);

} finally {
    @flock($lock, LOCK_UN);
    @fclose($lock);
}
