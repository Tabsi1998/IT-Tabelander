<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$company = $siteConfig['company'];
?>
<!DOCTYPE html>
<html lang="<?= e(config('meta.language')); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nutzungsbedingungen | <?= e($company['name']); ?></title>
    <meta name="description" content="Nutzungsbedingungen und Hinweise zur Website von <?= e($company['name']); ?>">
    <link rel="canonical" href="<?= e(canonical_url('nutzungsbedingungen.php')); ?>">
    <meta name="theme-color" content="#08141d">
    <script><?= theme_bootstrap_script(); ?></script>
    <?= site_favicon_markup(); ?>
    <link rel="stylesheet" href="<?= e(asset_url('css/styles.css')); ?>">
</head>
<body class="legal-body">
    <main class="legal-shell">
        <div class="legal-tools">
            <a class="legal-back" href="<?= e(page_url()); ?>#kontakt">&#8592; Zur Website</a>
            <?= theme_toggle_markup(); ?>
        </div>
        <section class="legal-page">
            <p class="section-eyebrow">Nutzungsbedingungen</p>
            <h1>Nutzungsbedingungen der Website</h1>
            <article class="legal-panel">
                <h2>1. Geltungsbereich</h2>
                <p>Diese Nutzungsbedingungen regeln die Verwendung der Website von <?= e($company['name']); ?>. Sie beziehen sich ausschließlich auf den Webauftritt und ersetzen keine individuellen Angebote, Projektvereinbarungen oder gesondert vereinbarten Geschäftsbedingungen.</p>
            </article>

            <article class="legal-panel">
                <h2>2. Inhalte und Verfügbarkeit</h2>
                <p>Alle Informationen auf dieser Website dienen der allgemeinen Darstellung der angebotenen Leistungen und stellen kein verbindliches Vertragsangebot dar. Trotz sorgfältiger Pflege kann keine Gewähr für Vollständigkeit, Aktualität oder jederzeitige Verfügbarkeit übernommen werden.</p>
            </article>

            <article class="legal-panel">
                <h2>3. Kontaktanfragen</h2>
                <p>Das Kontaktformular dient ausschließlich der unverbindlichen Anfrage. Ein Vertrag kommt erst zustande, wenn nach Prüfung des Anliegens eine gesonderte Vereinbarung oder ein konkretes Angebot angenommen wird.</p>
            </article>

            <article class="legal-panel">
                <h2>4. Urheber- und Nutzungsrechte</h2>
                <p>Texte, Bilder, Gestaltungselemente und technische Umsetzung dieser Website sind urheberrechtlich geschützt, soweit nicht anders angegeben. Eine Verwendung außerhalb der gesetzlichen Schranken bedarf der vorherigen Zustimmung.</p>
            </article>

            <article class="legal-panel">
                <h2>5. Externe Links</h2>
                <p>Die Website kann Links zu externen Diensten enthalten, etwa zu Google-Bewertungen oder rechtlichen Informationsquellen. Für deren Inhalte sind ausschließlich die jeweiligen Betreiber verantwortlich.</p>
            </article>

            <article class="legal-panel">
                <h2>6. Missbrauch</h2>
                <p>Automatisierte Zugriffe, Störversuche, missbräuchliche Formularnutzung oder sonstige Eingriffe in den Betrieb der Website sind unzulässig.</p>
            </article>

            <article class="legal-panel">
                <h2>7. Ergänzende Vertragsunterlagen</h2>
                <p>Für konkrete IT-Dienstleistungen, laufende Betreuung oder Projekte gelten ergänzend individuelle Angebote, Vereinbarungen und gegebenenfalls gesondert einbezogene Geschäftsbedingungen. Diese Website selbst macht solche Bedingungen nicht automatisch zum Vertragsbestandteil.</p>
            </article>
        </section>
    </main>
    <?= cookie_notice_markup(); ?>
    <script src="<?= e(asset_url('js/main.js')); ?>" defer></script>
</body>
</html>
