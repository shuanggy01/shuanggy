<?php

declare(strict_types=1);

function category_e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function category_slugify(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');

    if (function_exists('iconv')) {
        $ascii = @iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $value
        );

        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
    }

    $value = preg_replace(
        '/[^a-z0-9]+/i',
        '-',
        $value
    ) ?? '';

    $value = trim($value, '-');

    return $value !== ''
        ? strtolower($value)
        : 'kategori';
}

function category_unique_slug(
    PDO $pdo,
    string $title,
    ?int $ignoreId = null
): string {
    $base = category_slugify($title);
    $slug = $base;

    for ($i = 1; $i <= 100; $i++) {
        $sql = "
            SELECT COUNT(*)
            FROM collections
            WHERE slug = :slug
        ";

        $params = [
            ':slug' => $slug,
        ];

        if ($ignoreId !== null) {
            $sql .= " AND id <> :ignore_id";
            $params[':ignore_id'] = $ignoreId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ((int) $stmt->fetchColumn() === 0) {
            return $slug;
        }

        $slug = $base . '-' . ($i + 1);
    }

    return $base . '-' . bin2hex(random_bytes(3));
}

function category_generate_key(PDO $pdo): string
{
    $alphabet =
        'ABCDEFGHJKLMNPQRSTUVWXYZ'
        . 'abcdefghijkmnopqrstuvwxyz'
        . '23456789';

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $key = '';

        for ($i = 0; $i < 8; $i++) {
            $key .= $alphabet[
                random_int(
                    0,
                    strlen($alphabet) - 1
                )
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM collections
             WHERE collection_key = :key"
        );

        $stmt->execute([
            ':key' => $key,
        ]);

        if ((int) $stmt->fetchColumn() === 0) {
            return $key;
        }
    }

    throw new RuntimeException(
        'Gagal membuat category key unik.'
    );
}

function category_normalize_keywords(
    string $raw
): array {
    $parts = preg_split(
        '/[\r\n,]+/u',
        $raw
    ) ?: [];

    $keywords = [];

    foreach ($parts as $part) {
        $keyword = mb_strtolower(
            trim($part),
            'UTF-8'
        );

        $keyword = preg_replace(
            '/\s+/u',
            ' ',
            $keyword
        ) ?? '';

        if ($keyword === '') {
            continue;
        }

        /*
         * Trigger uses keyword as a REGEXP fragment.
         * Keep accepted mapping terms intentionally simple.
         */
        if (!preg_match(
            '/^[\p{L}\p{N} ]{2,50}$/u',
            $keyword
        )) {
            throw new RuntimeException(
                'Keyword hanya boleh huruf, angka, dan spasi '
                . '(2–50 karakter): '
                . $keyword
            );
        }

        $keywords[$keyword] = true;
    }

    return array_keys($keywords);
}

function category_get_keywords(
    PDO $pdo,
    int $collectionId
): array {
    $stmt = $pdo->prepare(
        "SELECT keyword
         FROM category_keywords
         WHERE collection_id = :collection_id
         ORDER BY keyword ASC"
    );

    $stmt->execute([
        ':collection_id' => $collectionId,
    ]);

    return array_map(
        'strval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );
}

function category_save_keywords(
    PDO $pdo,
    int $collectionId,
    array $keywords
): void {
    $delete = $pdo->prepare(
        "DELETE FROM category_keywords
         WHERE collection_id = :collection_id"
    );

    $delete->execute([
        ':collection_id' => $collectionId,
    ]);

    if (!$keywords) {
        return;
    }

    $insert = $pdo->prepare(
        "INSERT INTO category_keywords
            (collection_id, keyword, created_at)
         VALUES
            (:collection_id, :keyword, NOW())"
    );

    foreach ($keywords as $keyword) {
        $insert->execute([
            ':collection_id' => $collectionId,
            ':keyword' => $keyword,
        ]);
    }
}

function category_map_video(
    PDO $pdo,
    int $videoId
): array {
    $stmt = $pdo->prepare(
        "SELECT
            id,
            title,
            description
         FROM videos
         WHERE id = :id
         LIMIT 1"
    );

    $stmt->execute([
        ':id' => $videoId,
    ]);

    $video = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$video) {
        return [];
    }

    $haystack = mb_strtolower(
        trim(
            (string) ($video['title'] ?? '')
            . ' '
            . (string) ($video['description'] ?? '')
        ),
        'UTF-8'
    );

    $stmt = $pdo->query(
        "SELECT
            ck.collection_id,
            ck.keyword,
            c.title
         FROM category_keywords ck
         JOIN collections c
           ON c.id = ck.collection_id
         WHERE c.category_enabled = 1
           AND c.status = 'published'
         ORDER BY ck.collection_id ASC, ck.id ASC"
    );

    $matches = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $keyword = mb_strtolower(
            (string) $row['keyword'],
            'UTF-8'
        );

        $pattern =
            '/(^|[^\p{L}\p{N}_])'
            . preg_quote($keyword, '/')
            . '([^\p{L}\p{N}_]|$)/iu';

        if (!preg_match($pattern, $haystack)) {
            continue;
        }

        $cid = (int) $row['collection_id'];

        if (!isset($matches[$cid])) {
            $matches[$cid] = [
                'collection_id' => $cid,
                'title' => (string) $row['title'],
                'keywords' => [],
            ];
        }

        $matches[$cid]['keywords'][] = $keyword;
    }

    if (!$matches) {
        return [];
    }

    $check = $pdo->prepare(
        "SELECT COUNT(*)
         FROM collection_videos
         WHERE collection_id = :collection_id
           AND video_id = :video_id"
    );

    $position = $pdo->prepare(
        "SELECT COALESCE(MAX(position), 0) + 1
         FROM collection_videos
         WHERE collection_id = :collection_id"
    );

    $insert = $pdo->prepare(
        "INSERT INTO collection_videos
            (collection_id, video_id, position)
         VALUES
            (:collection_id, :video_id, :position)"
    );

    foreach ($matches as $cid => $match) {
        $check->execute([
            ':collection_id' => $cid,
            ':video_id' => $videoId,
        ]);

        if ((int) $check->fetchColumn() > 0) {
            continue;
        }

        $position->execute([
            ':collection_id' => $cid,
        ]);

        $nextPosition =
            (int) $position->fetchColumn();

        $insert->execute([
            ':collection_id' => $cid,
            ':video_id' => $videoId,
            ':position' => $nextPosition,
        ]);
    }

    return array_values($matches);
}
