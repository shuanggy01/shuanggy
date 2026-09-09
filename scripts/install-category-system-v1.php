<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/config/database.php';
require $root . '/app/category.php';

function installer_column_exists(
    PDO $pdo,
    string $table,
    string $column
): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?"
    );

    $stmt->execute([
        $table,
        $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function installer_index_exists(
    PDO $pdo,
    string $table,
    string $index
): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND index_name = ?"
    );

    $stmt->execute([
        $table,
        $index,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

echo "Category System V1 installer\n";
echo "============================\n";

if (!installer_column_exists(
    $pdo,
    'collections',
    'slug'
)) {
    $pdo->exec(
        "ALTER TABLE collections
         ADD COLUMN slug VARCHAR(180) NULL
         AFTER collection_key"
    );

    echo "✅ collections.slug ditambahkan\n";
}

if (!installer_column_exists(
    $pdo,
    'collections',
    'description'
)) {
    $pdo->exec(
        "ALTER TABLE collections
         ADD COLUMN description TEXT NULL
         AFTER title"
    );

    echo "✅ collections.description ditambahkan\n";
}

if (!installer_column_exists(
    $pdo,
    'collections',
    'category_enabled'
)) {
    $pdo->exec(
        "ALTER TABLE collections
         ADD COLUMN category_enabled TINYINT(1)
         NOT NULL DEFAULT 1
         AFTER status"
    );

    echo "✅ collections.category_enabled ditambahkan\n";
}

if (!installer_index_exists(
    $pdo,
    'collections',
    'uq_collections_slug'
)) {
    /*
     * Generate slugs for existing collections before unique index.
     */
    $rows = $pdo->query(
        "SELECT id, title, slug
         FROM collections
         ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $update = $pdo->prepare(
        "UPDATE collections
         SET slug = :slug
         WHERE id = :id"
    );

    foreach ($rows as $row) {
        if (trim((string) ($row['slug'] ?? '')) !== '') {
            continue;
        }

        $slug = category_unique_slug(
            $pdo,
            (string) $row['title'],
            (int) $row['id']
        );

        $update->execute([
            ':slug' => $slug,
            ':id' => (int) $row['id'],
        ]);
    }

    $pdo->exec(
        "ALTER TABLE collections
         ADD UNIQUE KEY uq_collections_slug (slug)"
    );

    echo "✅ unique slug index dibuat\n";
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS category_keywords (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        collection_id BIGINT UNSIGNED NOT NULL,
        keyword VARCHAR(100) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_category_keyword (
            collection_id,
            keyword
        ),
        KEY idx_category_keyword_text (keyword),
        CONSTRAINT fk_category_keywords_collection
            FOREIGN KEY (collection_id)
            REFERENCES collections(id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci"
);

echo "✅ category_keywords siap\n";

/*
|--------------------------------------------------------------------------
| Seed requested categories
|--------------------------------------------------------------------------
*/

$seed = [
    [
        'title' => 'Koleksi Bokep Indo Viral',
        'slug' => 'koleksi-bokep-indo-viral',
        'keywords' => [
            'viral',
            'indo viral',
            'indonesia viral',
            'lokal viral',
        ],
    ],
    [
        'title' => 'Koleksi Bokep Hijab Indo',
        'slug' => 'koleksi-bokep-hijab-indo',
        'keywords' => [
            'hijab',
            'jilbab',
            'kerudung',
        ],
    ],
    [
        'title' => 'Koleksi Bokep STW Binor',
        'slug' => 'koleksi-bokep-stw-binor',
        'keywords' => [
            'stw',
            'binor',
            'tante',
        ],
    ],
    [
        'title' => 'Koleksi Bokep Live Record',
        'slug' => 'koleksi-bokep-live-record',
        'keywords' => [
            'live',
            'livestream',
            'live record',
            'record',
            'recording',
            'video call',
            'vc',
        ],
    ],
];

$find = $pdo->prepare(
    "SELECT id
     FROM collections
     WHERE slug = :slug
     LIMIT 1"
);

$insertCollection = $pdo->prepare(
    "INSERT INTO collections
        (
            collection_key,
            slug,
            title,
            description,
            views,
            status,
            category_enabled,
            created_at,
            updated_at
        )
     VALUES
        (
            :collection_key,
            :slug,
            :title,
            NULL,
            0,
            'published',
            1,
            NOW(),
            NOW()
        )"
);

$insertKeyword = $pdo->prepare(
    "INSERT IGNORE INTO category_keywords
        (collection_id, keyword, created_at)
     VALUES
        (:collection_id, :keyword, NOW())"
);

foreach ($seed as $item) {
    $find->execute([
        ':slug' => $item['slug'],
    ]);

    $id = (int) ($find->fetchColumn() ?: 0);

    if ($id <= 0) {
        $insertCollection->execute([
            ':collection_key' =>
                category_generate_key($pdo),
            ':slug' => $item['slug'],
            ':title' => $item['title'],
        ]);

        $id = (int) $pdo->lastInsertId();

        echo "✅ kategori dibuat: "
            . $item['title']
            . "\n";
    } else {
        echo "ℹ️ kategori sudah ada: "
            . $item['title']
            . "\n";
    }

    foreach ($item['keywords'] as $keyword) {
        $insertKeyword->execute([
            ':collection_id' => $id,
            ':keyword' => $keyword,
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Trigger: every new video gets auto-mapped.
|--------------------------------------------------------------------------
|
| Keywords are validated by admin to only contain letters/numbers/spaces,
| making the dynamic REGEXP fragment safe and predictable.
*/

$pdo->exec(
    "DROP TRIGGER IF EXISTS trg_video_auto_category"
);

$triggerSql = <<<'SQL'
CREATE TRIGGER trg_video_auto_category
AFTER INSERT ON videos
FOR EACH ROW
BEGIN
    INSERT INTO collection_videos
        (collection_id, video_id, position)
    SELECT
        matched.collection_id,
        NEW.id,
        (
            SELECT COALESCE(MAX(cv.position), 0) + 1
            FROM collection_videos cv
            WHERE cv.collection_id = matched.collection_id
        ) AS next_position
    FROM (
        SELECT
            ck.collection_id
        FROM category_keywords ck
        JOIN collections c
          ON c.id = ck.collection_id
        WHERE c.category_enabled = 1
          AND c.status = 'published'
          AND LOWER(
                CONCAT(
                    COALESCE(NEW.title, ''),
                    ' ',
                    COALESCE(NEW.description, '')
                )
              )
              REGEXP CONCAT(
                '(^|[^[:alnum:]_])',
                LOWER(ck.keyword),
                '([^[:alnum:]_]|$)'
              )
        GROUP BY ck.collection_id
    ) AS matched
    WHERE NOT EXISTS (
        SELECT 1
        FROM collection_videos cv2
        WHERE cv2.collection_id = matched.collection_id
          AND cv2.video_id = NEW.id
    );
END
SQL;

$pdo->exec($triggerSql);

echo "✅ trigger auto-category aktif\n";
echo "\n";
echo "Selesai.\n";
echo "Video BARU sekarang otomatis dipetakan berdasarkan judul + deskripsi.\n";
echo "Video lama belum diubah. Gunakan remap script jika diperlukan.\n";
