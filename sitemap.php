<?php
declare(strict_types=1);

require __DIR__ . '/private/bootstrap.php';

header('Content-Type: application/xml; charset=UTF-8');

function sitemap_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

$entries = [];

foreach (page_registry() as $page) {
    if (!is_array($page) || empty($page['indexable'])) {
        continue;
    }

    $sources = array_filter([
        trim((string) ($page['source'] ?? '')),
        trim((string) ($page['contentSource'] ?? '')),
    ]);
    $sourcePaths = array_values(array_filter(array_map(
        static fn (string $source): string => __DIR__ . '/' . ltrim($source, '/\\'),
        $sources
    ), 'is_file'));

    if ($sourcePaths === []) {
        continue;
    }

    $modifiedTimes = array_map('filemtime', $sourcePaths);
    $modifiedTimes = array_values(array_filter($modifiedTimes, static fn ($value): bool => $value !== false));
    $modifiedAt = $modifiedTimes !== [] ? max($modifiedTimes) : false;
    $entries[] = [
        'loc' => canonical_url((string) ($page['path'] ?? '')),
        'lastmod' => $modifiedAt !== false ? gmdate('Y-m-d', $modifiedAt) : null,
        'changefreq' => (string) ($page['changefreq'] ?? 'monthly'),
        'priority' => (string) ($page['priority'] ?? '0.5'),
    ];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($entries as $entry): ?>
    <url>
        <loc><?= sitemap_xml_escape($entry['loc']); ?></loc>
        <?php if ($entry['lastmod'] !== null): ?><lastmod><?= sitemap_xml_escape($entry['lastmod']); ?></lastmod><?php endif; ?>
        <changefreq><?= sitemap_xml_escape($entry['changefreq']); ?></changefreq>
        <priority><?= sitemap_xml_escape($entry['priority']); ?></priority>
    </url>
<?php endforeach; ?>
</urlset>
