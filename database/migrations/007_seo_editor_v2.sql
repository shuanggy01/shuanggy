-- SEO Editor V2
-- MariaDB 10.11+

ALTER TABLE videos
    ADD COLUMN IF NOT EXISTS seo_title VARCHAR(255) NULL AFTER description;

ALTER TABLE videos
    ADD COLUMN IF NOT EXISTS seo_description VARCHAR(320) NULL AFTER seo_title;

ALTER TABLE videos
    ADD COLUMN IF NOT EXISTS seo_image_url TEXT NULL AFTER seo_description;

ALTER TABLE videos
    ADD COLUMN IF NOT EXISTS seo_noindex TINYINT(1) NOT NULL DEFAULT 0 AFTER seo_image_url;


ALTER TABLE collections
    ADD COLUMN IF NOT EXISTS seo_title VARCHAR(255) NULL AFTER title;

ALTER TABLE collections
    ADD COLUMN IF NOT EXISTS seo_description VARCHAR(320) NULL AFTER seo_title;

ALTER TABLE collections
    ADD COLUMN IF NOT EXISTS seo_image_url TEXT NULL AFTER seo_description;

ALTER TABLE collections
    ADD COLUMN IF NOT EXISTS seo_noindex TINYINT(1) NOT NULL DEFAULT 0 AFTER seo_image_url;
