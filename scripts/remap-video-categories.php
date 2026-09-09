<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/config/database.php';
require $root . '/app/category.php';

$apply = in_array(
    '--apply',
    $argv ?? [],
    true
);

$videos = $pdo->query(
    "SELECT id, video_key, title
     FROM videos
     ORDER BY id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

echo $apply
    ? "MODE: APPLY\n"
    : "MODE: DRY RUN\n";

echo "====================\n";

$total = 0;

foreach ($videos as $video) {
    $id = (int) $video['id'];

    if ($apply) {
        $matches = category_map_video(
            $pdo,
            $id
        );
    } else {
        /*
         * Dry-run using same whole-word PHP logic.
         */
        $stmt = $pdo->prepare(
            "SELECT title, description
             FROM videos
             WHERE id = :id
             LIMIT 1"
        );

        $stmt->execute([
            ':id' => $id,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $haystack = mb_strtolower(
            trim(
                (string) ($row['title'] ?? '')
                . ' '
                . (string) ($row['description'] ?? '')
            ),
            'UTF-8'
        );

        $rows = $pdo->query(
            "SELECT
                ck.collection_id,
                ck.keyword,
                c.title
             FROM category_keywords ck
             JOIN collections c
               ON c.id = ck.collection_id
             WHERE c.category_enabled = 1
               AND c.status = 'published'
             ORDER BY ck.collection_id, ck.id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $found = [];

        foreach ($rows as $map) {
            $keyword = mb_strtolower(
                (string) $map['keyword'],
                'UTF-8'
            );

            $pattern =
                '/(^|[^\p{L}\p{N}_])'
                . preg_quote($keyword, '/')
                . '([^\p{L}\p{N}_]|$)/iu';

            if (preg_match($pattern, $haystack)) {
                $cid = (int) $map['collection_id'];

                if (!isset($found[$cid])) {
                    $found[$cid] = [
                        'collection_id' => $cid,
                        'title' => (string) $map['title'],
                        'keywords' => [],
                    ];
                }

                $found[$cid]['keywords'][] = $keyword;
            }
        }

        $matches = array_values($found);
    }

    if (!$matches) {
        continue;
    }

    $total++;

    echo "\n#{$id} "
        . (string) $video['title']
        . "\n";

    foreach ($matches as $match) {
        echo "  -> "
            . $match['title']
            . " ["
            . implode(
                ', ',
                $match['keywords']
            )
            . "]\n";
    }
}

echo "\n";
echo "Video cocok: {$total}\n";

if (!$apply) {
    echo "Belum ada data yang diubah.\n";
    echo "Jika hasil sudah cocok, jalankan:\n";
    echo "php scripts/remap-video-categories.php --apply\n";
}
