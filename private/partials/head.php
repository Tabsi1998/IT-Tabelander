<?php
declare(strict_types=1);

$pageMeta = is_array($pageMeta ?? null) ? $pageMeta : page_meta('home');
$pageSchema = is_array($pageSchema ?? null) ? $pageSchema : [];
$canonical = canonical_url((string) ($pageMeta['path'] ?? ''));
$ogImage = canonical_url(asset_url((string) ($pageMeta['ogImage'] ?? 'img/hero-it-tabelander-1440.webp')));
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e((string) $pageMeta['title']); ?></title>
<meta name="description" content="<?= e((string) $pageMeta['description']); ?>">
<link rel="canonical" href="<?= e($canonical); ?>">
<meta property="og:title" content="<?= e((string) $pageMeta['title']); ?>">
<meta property="og:description" content="<?= e((string) $pageMeta['description']); ?>">
<meta property="og:type" content="<?= e((string) ($pageMeta['ogType'] ?? 'website')); ?>">
<meta property="og:url" content="<?= e($canonical); ?>">
<meta property="og:image" content="<?= e($ogImage); ?>">
<meta property="og:locale" content="<?= e(og_locale((string) $pageMeta['language'])); ?>">
<meta property="og:site_name" content="<?= e((string) config('meta.siteName', 'IT-Tabelander')); ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e((string) $pageMeta['title']); ?>">
<meta name="twitter:description" content="<?= e((string) $pageMeta['description']); ?>">
<meta name="twitter:image" content="<?= e($ogImage); ?>">
<?php if (empty($pageMeta['indexable'])): ?>
    <meta name="robots" content="noindex,nofollow">
<?php endif; ?>
<?php if (trim((string) ($pageMeta['googleSiteVerification'] ?? '')) !== ''): ?>
    <meta name="google-site-verification" content="<?= e((string) $pageMeta['googleSiteVerification']); ?>">
<?php endif; ?>
<meta name="theme-color" content="#08141d">
<script><?= theme_bootstrap_script(); ?></script>
<?= analytics_bootstrap_script(); ?>
<?= site_favicon_markup(); ?>
<?php if (!empty($pageMeta['preloadFonts'])): ?>
    <link rel="preload" href="<?= e(asset_url('fonts/space-grotesk-700.ttf')); ?>" as="font" type="font/ttf" crossorigin>
    <link rel="preload" href="<?= e(asset_url('fonts/manrope-400.ttf')); ?>" as="font" type="font/ttf" crossorigin>
<?php endif; ?>
<link rel="stylesheet" href="<?= e(asset_url('css/styles.css')); ?>">
<?php if ($pageSchema !== []): ?>
    <script type="application/ld+json"><?= json_encode($pageSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
<?php endif; ?>
