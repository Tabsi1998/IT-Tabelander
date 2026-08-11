<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$company = $siteConfig['company'];
$pageMeta = page_meta('impressum');
$pageSchema = [];
?>
<!DOCTYPE html>
<html lang="<?= e($pageMeta['language']); ?>">
<head>
    <?php require dirname(__DIR__) . '/partials/head.php'; ?>
</head>
<body class="legal-body">
    <main class="legal-shell">
        <div class="legal-tools">
            <a class="legal-back" href="<?= e(page_url()); ?>#kontakt">&#8592; Zur Website</a>
            <?= theme_toggle_markup(); ?>
        </div>
        <section class="legal-page">
            <p class="section-eyebrow">Impressum</p>
            <h1>Impressum und Offenlegung</h1>
            <?php if (legal_notice_pending($company)): ?>
                <p class="legal-alert">Bitte folgende Angaben vor dem Livegang vervollständigen: <?= e(implode(', ', legal_missing_fields($company))); ?></p>
            <?php endif; ?>
            <article class="legal-panel">
                <h2>Unternehmensangaben</h2>
                <p><strong><?= e($company['name']); ?></strong></p>
                <p><?= e($company['legalForm']); ?></p>
                <p><?= e($company['owner']); ?></p>
                <p><?= e(company_address_inline($company)); ?></p>
            </article>

            <article class="legal-panel">
                <h2>Kontakt</h2>
                <p>Telefon: <a href="tel:<?= e(phone_href($company['phone'])); ?>"><?= e($company['phone']); ?></a></p>
                <p>E-Mail: <a href="mailto:<?= e($company['email']); ?>"><?= e($company['email']); ?></a></p>
                <p>Website: <a href="<?= e(canonical_url()); ?>"><?= e(canonical_url()); ?></a></p>
            </article>

            <article class="legal-panel">
                <h2>Angaben gemäß § 5 ECG und berufsrechtliche Informationen</h2>
                <p>Unternehmensgegenstand: <?= e($company['websitePurpose']); ?></p>
                <p>Zuständige Aufsichtsbehörde: <a href="<?= e($company['supervisoryAuthorityUrl']); ?>" target="_blank" rel="noreferrer"><?= e($company['supervisoryAuthority']); ?></a></p>
                <p>Kammer / Berufsverband: <a href="<?= e($company['chamberUrl']); ?>" target="_blank" rel="noreferrer"><?= e($company['chamber']); ?></a></p>
                <p>Berufsbezeichnung: <?= e($company['profession']); ?></p>
                <p>Berufszweig: <?= e($company['professionDetail']); ?></p>
                <p>Verleihungsstaat: <?= e($company['memberState']); ?></p>
                <p>Anwendbare Vorschriften: <a href="<?= e($company['tradeRegulationUrl']); ?>" target="_blank" rel="noreferrer"><?= e($company['tradeRegulationLabel']); ?></a></p>
                <?php if (trim((string) $company['vatId']) !== ''): ?>
                    <p>UID-Nummer: <?= e($company['vatId']); ?></p>
                <?php endif; ?>
                <?php if (trim((string) $company['uid']) !== ''): ?>
                    <p>Firmenbuchnummer / Firmenbuchgericht: <?= e($company['uid']); ?></p>
                <?php endif; ?>
            </article>

            <article class="legal-panel">
                <h2>Offenlegung nach § 25 Mediengesetz</h2>
                <p>Medieninhaber: <?= e($company['name']); ?></p>
                <p>Sitz / Wohnort: <?= e(company_address_inline($company)); ?></p>
                <p>Unternehmensgegenstand: <?= e($company['websitePurpose']); ?></p>
                <p>Blattlinie: <?= e($company['mediaLine']); ?></p>
            </article>

            <?php if (trim((string) $company['wkoProfileUrl']) !== ''): ?>
                <article class="legal-panel">
                    <h2>Firmenprofil</h2>
                    <p><a href="<?= e($company['wkoProfileUrl']); ?>" target="_blank" rel="noreferrer">WKO Firmen A-Z Profil aufrufen</a></p>
                </article>
            <?php endif; ?>
        </section>
    </main>
    <?= cookie_notice_markup(); ?>
    <script src="<?= e(asset_url('js/main.js')); ?>" type="module"></script>
</body>
</html>
