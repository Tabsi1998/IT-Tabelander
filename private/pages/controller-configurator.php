<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/site-services.php';

$company = $siteConfig['company'];
$pageMeta = page_meta('controller-service-telfs');
$pageSchema = page_schema($pageMeta, $company);
$catalog = controller_catalog();
$contactForm = build_contact_form_view_model($siteConfig);
$configStatus = trim((string) ($_GET['config'] ?? ''));
$phoneLink = 'tel:' . phone_href((string) $company['phone']);
?>
<!DOCTYPE html>
<html lang="<?= e((string) $pageMeta['language']); ?>">
<head>
    <?php require dirname(__DIR__) . '/partials/head.php'; ?>
</head>
<body class="controller-page" data-page-key="controller-service-telfs">
    <div class="site-shell">
        <?php $activePageKey = 'controller-service-telfs'; require dirname(__DIR__) . '/partials/site-header.php'; ?>

        <main id="start">
            <section class="controller-hero section" aria-labelledby="controller-hero-title">
                <div class="controller-hero-copy" data-reveal>
                    <p class="section-eyebrow">PS5-Controller-Upgrades in Telfs</p>
                    <h1 id="controller-hero-title">Bauen Sie sich Ihr <span>Controller-Upgrade zusammen.</span></h1>
                    <p class="hero-lead">DualSense oder DualSense Edge auswählen, gewünschte Upgrades kombinieren und eine übersichtliche Angebotsanfrage vorbereiten. Reparaturen bleiben bewusst individuelle Anfragen.</p>
                    <div class="hero-actions">
                        <a class="button button-primary" href="#konfigurator" data-conversion="primary-cta" data-conversion-location="hero">Controller konfigurieren <span aria-hidden="true">↓</span></a>
                        <a class="button button-secondary" href="<?= e($phoneLink); ?>">Direkt anrufen</a>
                    </div>
                    <ul class="controller-hero-facts" aria-label="Vorteile des Controller-Service">
                        <li><strong>2 Modelle</strong><span>DualSense & Edge</span></li>
                        <li><strong>Mehrfachauswahl</strong><span>Upgrades sinnvoll kombinieren</span></li>
                        <li><strong>Klare Pakete</strong><span>Material, Einbau und Test</span></li>
                    </ul>
                </div>
                <div class="controller-hero-art" data-reveal aria-hidden="true">
                    <div class="controller-orbit controller-orbit-one"></div>
                    <div class="controller-orbit controller-orbit-two"></div>
                    <span class="controller-art-label controller-art-label-one">Hall Effect</span>
                    <span class="controller-art-label controller-art-label-two">Clicky</span>
                    <span class="controller-art-label controller-art-label-three">Back Paddles</span>
                    <svg viewBox="0 0 800 520" focusable="false">
                        <path class="controller-silhouette" d="M201 122C132 140 94 215 71 328c-13 65 2 123 48 139 49 17 85-25 119-79l37-58h250l37 58c34 54 70 96 119 79 46-16 61-74 48-139-23-113-61-188-130-206-53-14-91 8-129 8H330c-38 0-76-22-129-8Z"/>
                        <path class="controller-panel" d="M308 143h184l-24 111H332Z"/>
                        <circle class="controller-accent" cx="286" cy="294" r="43"/>
                        <circle class="controller-accent" cx="514" cy="294" r="43"/>
                        <path class="controller-accent controller-dpad-art" d="M177 226h32v-32h34v32h32v34h-32v32h-34v-32h-32Z"/>
                        <g class="controller-buttons-art"><circle cx="603" cy="208" r="16"/><circle cx="643" cy="248" r="16"/><circle cx="603" cy="288" r="16"/><circle cx="563" cy="248" r="16"/></g>
                    </svg>
                </div>
            </section>

            <section class="controller-configurator section" id="konfigurator" aria-labelledby="configurator-title" data-controller-configurator>
                <header class="controller-config-head" data-reveal>
                    <div>
                        <p class="section-eyebrow">Upgrade-Anfrage zusammenstellen</p>
                        <h2 id="configurator-title">Ihr Controller. Ihr Setup.</h2>
                    </div>
                    <p>Wählen Sie nur die Upgrades, die Sie wirklich möchten. Ich prüfe anschließend intern die technische Kompatibilität und erstelle daraus ein kontrollierbares Angebot.</p>
                </header>

                <?php if (in_array($configStatus, ['invalid', 'incomplete'], true)): ?>
                    <p class="controller-config-error" role="alert">Die Auswahl war unvollständig oder abgelaufen. Bitte wählen Sie ein Modell und mindestens ein Upgrade-Paket erneut aus.</p>
                <?php endif; ?>

                <form class="controller-config-form" action="<?= e(page_url('controller-request.php')); ?>" method="post" data-controller-form novalidate>
                    <input type="hidden" name="form_token" value="<?= e((string) $contactForm['formToken']); ?>">

                    <div class="controller-config-workspace">
                        <div class="controller-config-controls">
                            <fieldset class="config-step" data-config-step="model">
                                <legend><span>01</span><strong>Welcher Controller ist es?</strong></legend>
                                <div class="controller-model-options">
                                    <?php foreach ($catalog['models'] as $modelId => $model): ?>
                                        <label class="controller-choice controller-model-choice">
                                            <input type="radio" name="model" value="<?= e((string) $modelId); ?>" data-label="<?= e((string) $model['shortLabel']); ?>" required>
                                            <span><strong><?= e((string) $model['label']); ?></strong><small><?= e((string) $model['description']); ?></small></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>

                            <fieldset class="config-step config-step-offers" data-config-step="offers">
                                <legend><span>02</span><strong>Welche Upgrades möchten Sie?</strong><small>Faire Gesamtpreise</small></legend>
                                <p class="controller-offer-intro">Alle angezeigten Preise verstehen sich als Gesamtpreis für das genannte Material, den Einbau und den abschließenden Funktionstest.</p>
                                <p class="controller-offer-placeholder" data-offer-placeholder>Wählen Sie zuerst DualSense oder DualSense Edge. Danach erscheinen nur passende Pakete.</p>
                                <div class="controller-offer-options" data-offer-list>
                                    <?php foreach ($catalog['offers'] as $offerId => $offer): ?>
                                        <?php $offerModels = is_array($offer['models'] ?? null) ? $offer['models'] : []; ?>
                                        <label class="controller-choice controller-offer-choice" data-offer-card data-models="<?= e(implode(' ', $offerModels)); ?>">
                                            <input type="checkbox" name="offers[]" value="<?= e((string) $offerId); ?>" data-label="<?= e((string) $offer['shortLabel']); ?>" data-price-cents="<?= e((string) $offer['priceCents']); ?>" data-zone="<?= e((string) $offer['zone']); ?>" data-exclusive-group="<?= e((string) $offer['group']); ?>">
                                            <span>
                                                <span class="controller-offer-copy"><small><?= e((string) $offer['badge']); ?></small><strong><?= e((string) $offer['label']); ?></strong><em><?= e((string) $offer['description']); ?></em></span>
                                                <span class="controller-offer-price"><?= e(controller_price((int) $offer['priceCents'])); ?><small>Pauschal</small></span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="controller-field-error" data-selection-error hidden>Bitte wählen Sie mindestens ein Upgrade-Paket.</p>
                                <p class="controller-package-note"><strong>Wichtig:</strong> Pauschalpreise gelten für einen zur gewählten Leistung passenden, nicht schwer vorbeschädigten Controller. Verdeckte Platinen-, Flüssigkeits- oder Fremdreparaturschäden werden nur nach Ihrer Zustimmung zusätzlich bearbeitet. Versand ist nicht enthalten.</p>
                            </fieldset>

                            <fieldset class="config-step" data-config-step="extras">
                                <legend><span>03</span><strong>Was soll ich zusätzlich wissen?</strong><small>Optional</small></legend>
                                <div class="controller-extra-options">
                                    <?php foreach ($catalog['extras'] as $extraId => $extra): ?>
                                        <label class="controller-choice controller-extra-choice">
                                            <input type="checkbox" name="extras[]" value="<?= e((string) $extraId); ?>" data-label="<?= e((string) $extra['shortLabel']); ?>">
                                            <span><strong><?= e((string) $extra['label']); ?></strong></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <label class="controller-notes">
                                    <span>Kurze Ergänzung <small>(optional)</small></span>
                                    <textarea name="notes" rows="4" maxlength="500" placeholder="Zum Beispiel: gewünschte Farbe, Spielstil oder besondere Wünsche …"></textarea>
                                </label>
                            </fieldset>
                        </div>

                        <aside class="controller-live-panel" aria-label="Vorschau und Zusammenfassung">
                            <div class="controller-live-stage" data-controller-stage data-controller-model="">
                                <p class="controller-live-kicker"><span></span> Live-Vorschau</p>
                                <svg class="controller-live-svg" viewBox="0 0 800 520" role="img" aria-labelledby="controller-svg-title controller-svg-description">
                                    <title id="controller-svg-title">Schematische Vorderansicht eines PS5-Controllers</title>
                                    <desc id="controller-svg-description">Ausgewählte Upgrade-Bereiche werden farblich hervorgehoben.</desc>
                                    <path class="controller-device-shadow" d="M201 122C132 140 94 215 71 328c-13 65 2 123 48 139 49 17 85-25 119-79l37-58h250l37 58c34 54 70 96 119 79 46-16 61-74 48-139-23-113-61-188-130-206-53-14-91 8-129 8H330c-38 0-76-22-129-8Z"/>
                                    <path class="controller-device-body" data-controller-zone="housing" d="M201 110C132 128 94 203 71 316c-13 65 2 123 48 139 49 17 85-25 119-79l37-58h250l37 58c34 54 70 96 119 79 46-16 61-74 48-139-23-113-61-188-130-206-53-14-91 8-129 8H330c-38 0-76-22-129-8Z"/>
                                    <path class="controller-device-center" d="M309 132h182l-23 112H332Z"/>
                                    <rect class="controller-touchpad" x="325" y="144" width="150" height="76" rx="15"/>
                                    <g class="controller-zone controller-zone-triggers" data-controller-zone="triggers">
                                        <path d="M176 129c25-23 66-33 104-18l-13 34c-31-10-58-4-78 12Z"/>
                                        <path d="M624 129c-25-23-66-33-104-18l13 34c31-10 58-4 78 12Z"/>
                                    </g>
                                    <g class="controller-zone" data-controller-zone="charging">
                                        <rect x="375" y="104" width="50" height="18" rx="9"/>
                                    </g>
                                    <g class="controller-zone" data-controller-zone="dpad">
                                        <path d="M174 215h34v-34h36v34h34v36h-34v34h-36v-34h-34Z"/>
                                    </g>
                                    <g class="controller-zone controller-face-buttons" data-controller-zone="buttons">
                                        <circle cx="603" cy="197" r="18"/><circle cx="646" cy="240" r="18"/><circle cx="603" cy="283" r="18"/><circle cx="560" cy="240" r="18"/>
                                    </g>
                                    <g class="controller-zone" data-controller-zone="stick-left">
                                        <circle cx="286" cy="292" r="48"/><circle cx="286" cy="292" r="31"/>
                                    </g>
                                    <g class="controller-zone" data-controller-zone="stick-right">
                                        <circle cx="514" cy="292" r="48"/><circle cx="514" cy="292" r="31"/>
                                    </g>
                                    <g class="controller-zone controller-connection-zone" data-controller-zone="connection">
                                        <circle cx="400" cy="271" r="20"/><path d="M390 271h20M400 261v20"/>
                                    </g>
                                    <g class="controller-zone controller-battery-zone" data-controller-zone="battery">
                                        <path d="M355 343h90v48h-90Z"/><path d="M386 333h28v10h-28Z"/>
                                    </g>
                                    <g class="controller-edge-details" aria-hidden="true">
                                        <circle cx="350" cy="287" r="10"/><circle cx="450" cy="287" r="10"/>
                                        <path d="M348 339l-24 54M452 339l24 54"/>
                                    </g>
                                    <g class="controller-zone controller-back-paddle-zone" data-controller-zone="back-paddles" aria-hidden="true">
                                        <path d="M318 351l-30 75 31 13 39-79Z"/><path d="M482 351l30 75-31 13-39-79Z"/>
                                    </g>
                                </svg>
                                <p class="controller-model-badge" data-model-badge>Noch kein Modell gewählt</p>
                            </div>

                            <div class="controller-summary" aria-live="polite" aria-atomic="true">
                                <p class="controller-summary-eyebrow">Ihre Auswahl</p>
                                <dl>
                                    <div><dt>Modell</dt><dd data-summary-model>Bitte auswählen</dd></div>
                                    <div><dt>Upgrades</dt><dd data-summary-offers>Noch kein Upgrade gewählt</dd></div>
                                    <div><dt>Zusatz</dt><dd data-summary-extras>Keine Zusatzangabe</dd></div>
                                </dl>
                                <div class="controller-assessment">
                                    <span data-price-kicker>Voraussichtliche Paketsumme</span>
                                    <strong data-summary-price>0,00 €</strong>
                                    <small data-price-note>Material, Einbau und Funktionstest laut gewählten Paketen.</small>
                                </div>
                                <button class="button button-primary controller-submit" type="submit">Ins Kontaktformular übernehmen <span aria-hidden="true">↗</span></button>
                                <p class="controller-submit-note">Noch keine Beauftragung · keine automatische Bestellung</p>
                            </div>
                        </aside>
                    </div>
                </form>
            </section>

            <section class="controller-info section" aria-labelledby="controller-info-title">
                <div data-reveal>
                    <p class="section-eyebrow">Was danach passiert</p>
                    <h2 id="controller-info-title">Auswählen. Prüfen. Angebot erhalten.</h2>
                </div>
                <ol>
                    <li data-reveal><span>01</span><div><h3>Anfrage vervollständigen</h3><p>Ihre Auswahl wird in das normale Kontaktformular übernommen. Dort ergänzen Sie nur noch Kontaktdaten und bei Bedarf weitere Hinweise.</p></div></li>
                    <li data-reveal><span>02</span><div><h3>Kompatibilität intern prüfen</h3><p>Ich prüfe Controller-Ausführung, Kombination und Verfügbarkeit der gewählten Komponenten, ohne technische interne Details von Ihnen zu verlangen.</p></div></li>
                    <li data-reveal><span>03</span><div><h3>Angebot erhalten</h3><p>Nach meiner Kontrolle erhalten Sie ein nachvollziehbares Angebot. Erst nach Ihrer Freigabe werden Teile bestellt oder Arbeiten begonnen.</p></div></li>
                </ol>
            </section>

            <section class="controller-boundary section" data-reveal>
                <div><p class="section-eyebrow">Aktueller Annahmeumfang</p><h2>Vorerst bewusst auf PS5 spezialisiert.</h2></div>
                <p>Aktuell werden ausschließlich PS5 DualSense und PS5 DualSense Edge angenommen. Weitere Modelle können später in den Konfigurator aufgenommen werden, sobald sie zum angebotenen Service gehören.</p>
            </section>

            <section class="controller-price-note section" data-reveal aria-labelledby="controller-price-note-title">
                <div><p class="section-eyebrow">Upgrades statt Reparaturdiagnose</p><h2 id="controller-price-note-title">Klare Pakete für planbare Umbauten.</h2></div>
                <p>Der Konfigurator enthält ausschließlich standardisierbare Upgrades mit Gesamtpreisen. Stick-Drift, Ladefehler, Sturz-, Flüssigkeits- und andere Reparaturschäden schildern Sie bitte über die <a href="<?= e(page_url()); ?>#kontakt">individuelle Anfrage</a>. Dafür wird nach Prüfung ein eigener Lösungsweg angeboten.</p>
            </section>
        </main>

        <?php require dirname(__DIR__) . '/partials/site-footer.php'; ?>
    </div>
    <?= cookie_notice_markup(); ?>
    <?php $contactHref = page_url() . '#kontakt'; require dirname(__DIR__) . '/partials/mobile-contact.php'; ?>
    <script src="<?= e(asset_url('js/main.js')); ?>" type="module"></script>
</body>
</html>
