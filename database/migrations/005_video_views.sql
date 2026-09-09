CREATE TABLE IF NOT EXISTS video_views (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    video_id BIGINT UNSIGNED NOT NULL,
    viewer_hash CHAR(64) NOT NULL,
    view_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_video_daily (
        video_id,
        viewer_hash,
        view_date
    ),

    KEY idx_view_date (view_date),

    CONSTRAINT fk_video_views_video
        FOREIGN KEY (video_id)
        REFERENCES videos(id)
        ON DELETE CASCADE
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
