CREATE TABLE IF NOT EXISTS collection_views (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    collection_id BIGINT UNSIGNED NOT NULL,
    viewer_hash CHAR(64) NOT NULL,
    view_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_collection_daily (
        collection_id,
        viewer_hash,
        view_date
    ),

    KEY idx_collection_view_date (view_date),

    CONSTRAINT fk_collection_views_collection
        FOREIGN KEY (collection_id)
        REFERENCES collections(id)
        ON DELETE CASCADE
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
