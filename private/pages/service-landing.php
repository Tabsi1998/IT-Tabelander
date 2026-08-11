<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$landingPageKey = (string) ($landingPageKey ?? '');
$company = $siteConfig['company'];
$pageMeta = page_meta($landingPageKey);
$pageSchema = page_schema($pageMeta, $company);
$page = landing_page_content($landingPageKey);

if ($landingPageKey === '' || $page === [] || empty($pageMeta['indexable'])) {
    http_response_code(404);
    exit('Seite nicht gefunden.');
}

$phoneLink = 'tel:' . phone_href((string) $company['phone']);
?>
<!DOCTYPE html>
<html lang="<?= e((string) $pageMeta['language']); ?>">
<head>
    <?php require dirname(__DIR__) . '/partials/head.php'; ?>
</head>
<body class="landing-body">
    <div class="site-shell">
        <header class="site-header">
            <a class="brand-lockup" href="<?= e(page_url()); ?>" aria-label="IT-Tabelander Startseite">
                <span class="brand-mobile-mark" aria-hidden="true">
                    <img class="brand-logo-image" src="<?= e(asset_url('img/logo/IT-Tabelander Logo Dunkel Transparent.png')); ?>" data-theme-logo data-logo-dark-src="<?= e(asset_url('img/logo/IT-Tabelander Logo Hell Transparent.png')); ?>" data-logo-light-src="<?= e(asset_url('img/logo/IT-Tabelander Logo Dunkel Transparent.png')); ?>" alt="" width="560" height="616">
                </span>
                <span class="brand-banner-shell" aria-hidden="true">
                    <img class="brand-banner-image" src="<?= e(asset_url('img/logo/IT-Tabelander Banner Dunkel Transparent.png')); ?>" data-theme-logo data-logo-dark-src="<?= e(asset_url('img/logo/IT-Tabelander Banner Hell Transparent.png')); ?>" data-logo-light-src="<?= e(asset_url('img/logo/IT-Tabelander Banner Dunkel Transparent.png')); ?>" alt="" width="1317" height="254">
                </span>
            </a>
            <div class="header-actions">
                <nav class="site-nav" id="site-navigation" aria-label="Hauptnavigation">
                    <a href="<?= e(page_url('pc-reparatur-telfs')); ?>">PC-Reparatur</a>
                    <a href="<?= e(page_url('it-betreuung-telfs')); ?>">IT-Betreuung</a>
                    <a href="<?= e(page_url('wlan-netzwerk-telfs')); ?>">WLAN</a>
                    <a class="nav-cta" href="<?= e(page_url()); ?>#kontakt">Kontakt</a>
                </nav>
                <?= theme_toggle_markup(); ?>
                <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-navigation" aria-label="Navigation öffnen">
                    <span></span>
                    <span></span>
                </button>
            </div>
        </header>

        <main id="start">
            <section class="landing-hero section">
                <div class="landing-hero-copy" data-reveal>
                    <p class="section-eyebrow"><?= e((string) $page['eyebrow']); ?></p>
                    <h1><?= e((string) $page['headline']); ?></h1>
                    <p class="hero-lead"><?= e((string) $page['lead']); ?></p>
                    <div class="hero-actions">
                        <a class="button button-primary" href="<?= e(page_url()); ?>#kontakt"><?= e((string) $page['primaryCta']); ?></a>
                        <a class="button button-secondary" href="<?= e($phoneLink); ?>">Jetzt anrufen: <?= e((string) $company['phone']); ?></a>
                    </div>
                    <p class="landing-area"><?= e((string) $company['serviceArea']); ?></p>
                </div>
                <div class="landing-hero-media" data-reveal>
                    <img src="<?= e(asset_url((string) $page['imageBase'] . '-640.webp')); ?>" srcset="<?= e(asset_url((string) $page['imageBase'] . '-640.webp')); ?> 640w, <?= e(asset_url((string) $page['imageBase'] . '-' . (string) $page['imageLargeWidth'] . '.webp')); ?> <?= e((string) $page['imageLargeWidth']); ?>w" sizes="(max-width: 980px) 100vw, 46vw" alt="<?= e((string) $page['imageAlt']); ?>" width="<?= e((string) $page['imageWidth']); ?>" height="<?= e((string) $page['imageHeight']); ?>" fetchpriority="high" decoding="async">
                </div>
            </section>

            <section class="landing-section section" aria-labelledby="problems-title">
                <div class="section-heading" data-reveal>
                    <p class="section-eyebrow">Ausgangslage</p>
                    <h2 id="problems-title"><?= e((string) $page['problemTitle']); ?></h2>
                </div>
                <div class="landing-card-grid">
                    <?php foreach ($page['problems'] as $problem): ?>
                        <article class="landing-card" data-reveal>
                            <h3><?= e((string) $problem['title']); ?></h3>
                            <p><?= e((string) $problem['text']); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="landing-section landing-section-accent section" aria-labelledby="services-title">
                <div class="section-heading" data-reveal>
                    <p class="section-eyebrow">Leistungen</p>
                    <h2 id="services-title"><?= e((string) $page['serviceTitle']); ?></h2>
                    <p><?= e((string) $page['serviceLead']); ?></p>
                </div>
                <div class="landing-service-grid">
                    <?php foreach ($page['services'] as $service): ?>
                        <article class="landing-service" data-reveal>
                            <h3><?= e((string) $service['title']); ?></h3>
                            <p><?= e((string) $service['text']); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="landing-split section">
                <div data-reveal>
                    <p class="section-eyebrow">Einsatzgebiet</p>
                    <h2><?= e((string) $page['audienceTitle']); ?></h2>
                </div>
                <p data-reveal><?= e((string) $page['audienceText']); ?></p>
            </section>

            <aside class="landing-trust section" aria-label="Über IT-Tabelander" data-reveal>
                <p><strong>Direkter Ansprechpartner</strong><span><?= e((string) $company['owner']); ?>, Inhaber von IT-Tabelander</span></p>
                <p><strong>Standort</strong><span><?= e((string) $company['postalCode'] . ' ' . (string) $company['city']); ?></span></p>
                <p><strong>Arbeitsweise</strong><span>Verständliche Diagnose, klare Empfehlung und nachvollziehbare Übergabe</span></p>
            </aside>

            <section class="landing-section section" aria-labelledby="process-title">
                <div class="section-heading" data-reveal>
                    <p class="section-eyebrow">Ablauf</p>
                    <h2 id="process-title"><?= e((string) $page['processTitle']); ?></h2>
                </div>
                <ol class="landing-process">
                    <?php foreach ($page['process'] as $index => $step): ?>
                        <li data-reveal>
                            <span><?= e((string) ($index + 1)); ?></span>
                            <div><h3><?= e((string) $step['title']); ?></h3><p><?= e((string) $step['text']); ?></p></div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <section class="landing-cta section" aria-labelledby="landing-cta-title" data-reveal>
                <div>
                    <p class="section-eyebrow">Kontakt</p>
                    <h2 id="landing-cta-title"><?= e((string) $page['ctaTitle']); ?></h2>
                    <p><?= e((string) $page['ctaText']); ?></p>
                </div>
                <div class="hero-actions">
                    <a class="button button-primary" href="<?= e(page_url()); ?>#kontakt">Kontaktformular öffnen</a>
                    <a class="button button-secondary" href="<?= e($phoneLink); ?>"><?= e((string) $company['phone']); ?></a>
                </div>
            </section>
        </main>

        <footer class="site-footer">
            <p><strong><?= e((string) $company['name']); ?></strong> · <?= e(company_address_inline($company)); ?></p>
            <nav aria-label="Rechtliches">
                <a href="<?= e(page_url('impressum.php')); ?>">Impressum</a>
                <a href="<?= e(page_url('datenschutz.php')); ?>">Datenschutz</a>
                <a href="<?= e(page_url('nutzungsbedingungen.php')); ?>">Nutzungsbedingungen</a>
            </nav>
        </footer>
    </div>
    <?= cookie_notice_markup(); ?>
    <script src="<?= e(asset_url('js/main.js')); ?>" defer></script>
</body>
</html>
