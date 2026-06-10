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
    <title>Datenschutz | <?= e($company['name']); ?></title>
    <meta name="description" content="Datenschutzerklärung von <?= e($company['name']); ?>">
    <link rel="canonical" href="<?= e(canonical_url('datenschutz.php')); ?>">
    <meta name="theme-color" content="#08141d">
    <script><?= theme_bootstrap_script(); ?></script>
    <?= analytics_bootstrap_script(); ?>
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
            <p class="section-eyebrow">Datenschutz</p>
            <h1>Datenschutzerklärung</h1>
            <?php if (legal_notice_pending($company)): ?>
                <p class="legal-alert">Bitte Unternehmens- und Pflichtangaben vor dem Livegang vervollständigen: <?= e(implode(', ', legal_missing_fields($company))); ?></p>
            <?php endif; ?>
            <article class="legal-panel">
                <h2>1. Verantwortlicher</h2>
                <p><strong><?= e($company['name']); ?></strong></p>
                <p><?= e($company['owner']); ?></p>
                <p><?= e(company_address_inline($company)); ?></p>
                <p>E-Mail: <a href="mailto:<?= e($company['email']); ?>"><?= e($company['email']); ?></a></p>
                <p>Telefon: <a href="tel:<?= e(phone_href($company['phone'])); ?>"><?= e($company['phone']); ?></a></p>
            </article>

            <article class="legal-panel">
                <h2>2. Zugriffsdaten und Server-Logs</h2>
                <p>Beim Aufruf der Website verarbeitet der Webserver technisch erforderliche Daten wie IP-Adresse, Datum und Uhrzeit des Abrufs, aufgerufene Datei, übertragene Datenmenge, Browsertyp sowie den HTTP-Statuscode.</p>
                <p>Die Verarbeitung erfolgt zur Bereitstellung und Sicherheit des Webauftritts auf Grundlage von Art. 6 Abs. 1 lit. f DSGVO. Die konkrete Speicherdauer richtet sich nach der Konfiguration des eingesetzten Hostings.</p>
            </article>

            <article class="legal-panel">
                <h2>3. Kontaktaufnahme über Formular, E-Mail oder Telefon</h2>
                <p>Wenn Sie das Kontaktformular nutzen oder direkt per E-Mail bzw. Telefon anfragen, werden Ihre Angaben zur Bearbeitung Ihrer Anfrage verarbeitet. Dazu zählen insbesondere Name, E-Mail-Adresse, Telefonnummer, gewählter Leistungsbereich und die von Ihnen übermittelte Nachricht.</p>
                <p>Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO zur Durchführung vorvertraglicher Maßnahmen sowie Art. 6 Abs. 1 lit. f DSGVO zur geordneten Bearbeitung von Anfragen.</p>
                <p>Wenn die automatische Eingangsbestätigung aktiviert ist, wird die angegebene E-Mail-Adresse zusätzlich für eine Bestätigungs-E-Mail verwendet. Technisch protokolliert wird dabei auch, ob die Übermittlung an den Mailserver erfolgreich war.</p>
                <p>Empfänger der Daten sind interne Ansprechpartner sowie die jeweils eingesetzten Hosting- und Mail-Dienstleister. Die Daten werden so lange gespeichert, wie dies zur Bearbeitung der Anfrage und zur Erfüllung gesetzlicher Pflichten erforderlich ist.</p>
            </article>

            <article class="legal-panel">
                <h2>4. Google-Bewertungen</h2>
                <p>Die Website ist technisch so vorbereitet, dass Bewertungen serverseitig aus der Google Places API geladen und zwischengespeichert werden können. Beim reinen Besuch der Startseite wird dadurch keine direkte Verbindung des Browsers zu Google für den Bewertungsabruf hergestellt.</p>
                <p>Erst wenn Sie auf einen Link wie „Auf Google ansehen“ klicken, verlassen Sie die Website und es wird eine direkte Verbindung zu Google aufgebaut. Dabei gelten die Datenschutzinformationen von Google.</p>
                <p>Wenn Sie die Google-Synchronisierung nicht verwenden, kann der Bewertungsbereich auch mit manuell gepflegten Referenzen betrieben werden.</p>
            </article>

            <article class="legal-panel">
                <h2>5. Cookies und ähnliche Technologien</h2>
                <p>Verwendet werden technisch notwendige Sitzungs-Cookies für Formularschutz und Spam-Abwehr. Die Darstellungs-Einstellung für Hell oder Dunkel wird erst nach aktiver Auswahl lokal im Browser gespeichert.</p>
                <p>Google Analytics wird nur geladen, wenn Sie über den Hinweis auf der Website aktiv zustimmen. Vor einer Zustimmung wird kein Analytics-Skript von Google geladen und keine Analytics-Konfiguration ausgeführt.</p>
                <p>Die Zustimmung oder Ablehnung wird für 30 Tage lokal im Browser gespeichert. Danach wird die Auswahl erneut abgefragt. Sie können die Auswahl jederzeit zurücksetzen und neu treffen.</p>
                <p><button class="button button-secondary button-compact" type="button" data-cookie-reset>Cookie-Einstellungen ändern</button></p>
            </article>

            <article class="legal-panel">
                <h2>6. Google Analytics</h2>
                <p>Diese Website kann nach Ihrer Zustimmung Google Analytics 4 mit der Mess-ID <?= e(config('analytics.googleMeasurementId', '')); ?> verwenden. Anbieter ist Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland.</p>
                <p>Google Analytics hilft zu verstehen, welche Seiten aufgerufen werden und wie der Webauftritt technisch verbessert werden kann. Dabei können Nutzungsdaten, technische Geräte- und Browserinformationen, ungefähre Standortdaten sowie gekürzte oder anderweitig verarbeitete IP-Informationen verarbeitet werden.</p>
                <p>Die Verarbeitung erfolgt nur auf Grundlage Ihrer Einwilligung gemäß Art. 6 Abs. 1 lit. a DSGVO. Eine erteilte Einwilligung kann jederzeit mit Wirkung für die Zukunft widerrufen werden.</p>
            </article>

            <article class="legal-panel">
                <h2>7. Ihre Rechte</h2>
                <p>Ihnen stehen grundsätzlich die Rechte auf Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit sowie Widerspruch zu. Wenn die Verarbeitung auf einer Einwilligung beruht, können Sie diese jederzeit mit Wirkung für die Zukunft widerrufen.</p>
                <p>Wenn Sie der Ansicht sind, dass die Verarbeitung Ihrer Daten gegen Datenschutzrecht verstößt, können Sie sich bei der <a href="<?= e($company['privacyAuthorityUrl']); ?>" target="_blank" rel="noreferrer"><?= e($company['privacyAuthorityName']); ?></a> beschweren.</p>
            </article>

            <article class="legal-panel">
                <h2>8. Stand und Aktualisierung</h2>
                <p>Stand dieser Datenschutzerklärung: <?= e(date('d.m.Y')); ?>.</p>
            </article>
        </section>
    </main>
    <?= cookie_notice_markup(); ?>
    <script src="<?= e(asset_url('js/main.js')); ?>" defer></script>
</body>
</html>
