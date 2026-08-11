<?php
declare(strict_types=1);

$activePageKey = (string) ($activePageKey ?? '');
$isHomePage = $activePageKey === 'home';
$homeAnchor = static fn (string $anchor): string => $isHomePage ? '#' . $anchor : page_url() . '#' . $anchor;
?>
<header class="site-header">
    <a class="brand-lockup" href="<?= e($isHomePage ? '#start' : page_url()); ?>" aria-label="IT-Tabelander Startseite">
        <span class="brand-mobile-mark" aria-hidden="true">
            <img class="brand-logo-image" src="<?= e(asset_url('img/logo/IT-Tabelander Logo Dunkel Transparent.png')); ?>" data-theme-logo data-logo-dark-src="<?= e(asset_url('img/logo/IT-Tabelander Logo Hell Transparent.png')); ?>" data-logo-light-src="<?= e(asset_url('img/logo/IT-Tabelander Logo Dunkel Transparent.png')); ?>" alt="" width="560" height="616">
        </span>
        <span class="brand-wordmark"><strong>IT-Tabelander</strong><small>Fabian · Telfs</small></span>
    </a>
    <div class="header-actions">
        <nav class="site-nav" id="site-navigation" aria-label="Hauptnavigation">
            <a href="<?= e($homeAnchor('hilfe')); ?>">Hilfe finden</a>
            <a href="<?= e($homeAnchor('ablauf')); ?>">So läuft’s</a>
            <a href="<?= e($homeAnchor('ueber-mich')); ?>">Über mich</a>
            <?php if ($isHomePage && !empty($hasPublishedReviews)): ?><a href="#bewertungen">Bewertungen</a><?php endif; ?>
            <a href="<?= e($homeAnchor('faq')); ?>">FAQ</a>
            <a href="<?= e($homeAnchor('kontakt')); ?>" class="nav-cta">Problem schildern <span aria-hidden="true">↗</span></a>
            <p class="nav-contact-note"><small>Direkt erreichbar</small><a href="tel:<?= e(phone_href((string) $company['phone'])); ?>"><?= e((string) $company['phone']); ?></a></p>
        </nav>
        <?= theme_toggle_markup(); ?>
        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-navigation" aria-label="Navigation öffnen">
            <span></span>
            <span></span>
        </button>
    </div>
</header>
