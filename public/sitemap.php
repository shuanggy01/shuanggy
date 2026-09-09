<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/database.php';

header('Content-Type: application/xml; charset=UTF-8');

$baseUrl = 'https://asupanlendir.sbs';

$videos = $pdo->query("
    SELECT
        video_key,
        updated_at,
        created_at
    FROM videos
    WHERE status = 'published'
      AND COALESCE(seo_noindex, 0) = 0
    ORDER BY id DESC
")->fetchAll();

$collections = $pdo->query("
    SELECT
        collection_key,
        updated_at,
        created_at
    FROM collections
    WHERE status = 'published'
      AND COALESCE(seo_noindex, 0) = 0
    ORDER BY id DESC
")->fetchAll();

function xmlEscape(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_XML1 | ENT_QUOTES,
        'UTF-8'
    );
}

function sitemapDate(?string $updatedAt, ?string $createdAt): string
{
    $raw = $updatedAt ?: $createdAt;

    if (!$raw) {
        return date('Y-m-d');
    }

    $time = strtotime($raw);

    return $time
        ? date('Y-m-d', $time)
        : date('Y-m-d');
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

<url>
    <loc><?= xmlEscape($baseUrl . '/') ?></loc>
    <lastmod><?= date('Y-m-d') ?></lastmod>
    <changefreq>daily</changefreq>
    <priority>1.0</priority>
</url>

<?php foreach ($collections as $collection): ?>

<url>
    <loc><?= xmlEscape(
        $baseUrl .
        '/c/' .
        rawurlencode($collection['collection_key'])
    ) ?></loc>
    <lastmod><?= sitemapDate(
        $collection['updated_at'] ?? null,
        $collection['created_at'] ?? null
    ) ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.8</priority>
</url>

<?php endforeach; ?>

<?php foreach ($videos as $video): ?>

<url>
    <loc><?= xmlEscape(
        $baseUrl .
        '/v/' .
        rawurlencode($video['video_key'])
    ) ?></loc>
    <lastmod><?= sitemapDate(
        $video['updated_at'] ?? null,
        $video['created_at'] ?? null
    ) ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.9</priority>
</url>

<?php endforeach; ?>

</urlset>
