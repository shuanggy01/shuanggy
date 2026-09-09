<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/database.php';

$statements = [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS video_sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    video_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(32) NOT NULL,
    file_code VARCHAR(128) NULL,
    remote_url TEXT NULL,
    embed_url TEXT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    check_failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    last_checked_at DATETIME NULL,
    ready_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_video_provider (video_id, provider),
    KEY idx_status_checked (status, last_checked_at),
    KEY idx_video_status (video_id, status),
    KEY idx_provider_status (provider, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS video_mirror_state (
    state_key VARCHAR(80) NOT NULL,
    state_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (state_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];

foreach ($statements as $sql) {
    $pdo->exec($sql);
}

echo "✅ Database Multi Server V1 siap.\n";
