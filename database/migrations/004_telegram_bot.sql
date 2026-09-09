-- Bot Telegram + album/folder untuk AsupanLendir

CREATE TABLE IF NOT EXISTS telegram_updates (
    update_id BIGINT UNSIGNED NOT NULL,
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (update_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    collection_key VARCHAR(20) NOT NULL,
    title VARCHAR(255) NOT NULL,
    views BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('draft','published','hidden') NOT NULL DEFAULT 'published',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_collection_key (collection_key),
    KEY idx_collection_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collection_videos (
    collection_id BIGINT UNSIGNED NOT NULL,
    video_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (collection_id, video_id),
    KEY idx_collection_position (collection_id, position),
    CONSTRAINT fk_collection_videos_collection
        FOREIGN KEY (collection_id) REFERENCES collections(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_collection_videos_video
        FOREIGN KEY (video_id) REFERENCES videos(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
