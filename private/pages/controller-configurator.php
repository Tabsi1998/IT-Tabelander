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
                    <img class="controller-hero-product" src="<?= e(asset_url('img/controller/controller-dualsense-premium.png')); ?>" alt="" width="768" height="512" loading="eager" decoding="async">
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
                                <div class="controller-product-visual" role="img" aria-label="Live-Vorschau des gewählten Controllers mit markierten Upgrade-Bereichen">
                                    <img class="controller-product-image controller-product-dualsense" src="<?= e(asset_url('img/controller/controller-dualsense-premium.png')); ?>" alt="" width="768" height="512" loading="lazy" decoding="async">
                                    <img class="controller-product-image controller-product-edge" src="<?= e(asset_url('img/controller/controller-dualsense-edge-premium.png')); ?>" alt="" width="768" height="512" loading="lazy" decoding="async">
                                    <div class="controller-upgrade-hotspots" aria-hidden="true">
                                        <span class="controller-hotspot controller-hotspot-triggers" data-controller-zone="triggers"><i></i><b>Clicky Trigger</b></span>
                                        <span class="controller-hotspot controller-hotspot-dpad" data-controller-zone="dpad"><i></i><b>D-Pad</b></span>
                                        <span class="controller-hotspot controller-hotspot-buttons" data-controller-zone="buttons"><i></i><b>Clicky Buttons</b></span>
                                        <span class="controller-hotspot controller-hotspot-stick-left" data-controller-zone="stick-left"><i></i><b>Hall Effect</b></span>
                                        <span class="controller-hotspot controller-hotspot-stick-right" data-controller-zone="stick-right"><i></i></span>
                                        <span class="controller-hotspot controller-hotspot-battery" data-controller-zone="battery"><i></i><b>Akku</b></span>
                                        <span class="controller-hotspot controller-hotspot-paddles" data-controller-zone="back-paddles"><i></i><b>Back Paddles</b></span>
                                        <span class="controller-hotspot controller-hotspot-led" data-controller-zone="led"><i></i><b>LED Kit</b></span>
                                    </div>
                                </div>
                                <div class="controller-visual-status">
                                    <p class="controller-model-badge" data-model-badge>Noch kein Modell gewählt</p>
                                    <p class="controller-visual-caption" data-visual-caption>Modell auswählen und Upgrades live entdecken</p>
                                </div>
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
