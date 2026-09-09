CREATE TABLE IF NOT EXISTS videos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    video_key VARCHAR(20) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,

    video_url TEXT NULL,
    thumbnail_url TEXT NULL,

    views BIGINT UNSIGNED NOT NULL DEFAULT 0,

    status ENUM('draft','published','hidden') NOT NULL DEFAULT 'published',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_video_key (video_key),
    KEY idx_status_created (status, created_at),
    KEY idx_views (views)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
