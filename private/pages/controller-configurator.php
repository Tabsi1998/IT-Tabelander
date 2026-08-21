<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/site-services.php';

$company = $siteConfig['company'];
$pageMeta = page_meta('controller-service-telfs');
$pageSchema = page_schema($pageMeta, $company);
$catalog = controller_catalog();
$contactForm = build_contact_form_view_model($siteConfig);
$controllerFlash = consume_controller_form_flash();
$controllerValues = is_array($controllerFlash['values'] ?? null) ? $controllerFlash['values'] : [];
$controllerErrors = is_array($controllerFlash['errors'] ?? null) ? $controllerFlash['errors'] : [];
$controllerMeta = is_array($controllerFlash['meta'] ?? null) ? $controllerFlash['meta'] : [];
$controllerValue = static fn (string $field, string $default = ''): string => (string) ($controllerValues[$field] ?? $default);
$controllerList = static fn (string $field): array => is_array($controllerValues[$field] ?? null) ? $controllerValues[$field] : [];
$controllerHasError = static fn (string $field): bool => in_array($field, $controllerErrors, true);
$controllerChecked = static function (string $field, string $value, bool $default = false) use ($controllerValues): string {
    if (!array_key_exists($field, $controllerValues)) {
        return $default ? 'checked' : '';
    }

    $current = $controllerValues[$field];
    $matches = is_array($current) ? in_array($value, $current, true) : (string) $current === $value;

    return $matches ? 'checked' : '';
};
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

                <?php if ($configStatus === 'success'): ?>
                    <p class="controller-config-feedback is-success" role="status">Ihre Konfiguration wurde als eigener Interessent samt unverbindlichem Angebotsentwurf in Dolibarr angelegt. Ich prüfe die Auswahl und melde mich persönlich bei Ihnen.</p>
                <?php elseif ($configStatus === 'erp_error'): ?>
                    <p class="controller-config-feedback is-error" role="alert">Der Angebotsentwurf konnte gerade nicht in Dolibarr angelegt werden. Ihre Eingaben bleiben erhalten; bitte versuchen Sie es später erneut.<?= !empty($controllerMeta['requestId']) ? ' Referenz: ' . e((string) $controllerMeta['requestId']) . '.' : ''; ?></p>
                <?php elseif (in_array($configStatus, ['invalid', 'incomplete'], true)): ?>
                    <p class="controller-config-feedback is-error" role="alert">Bitte prüfen Sie die markierten Pflichtfelder, wählen Sie die Bereitstellung sowie mindestens ein Upgrade und senden Sie die Anfrage erneut.</p>
                <?php endif; ?>

                <form class="controller-config-form" action="<?= e(page_url('controller-request.php')); ?>" method="post" data-controller-form novalidate>
                    <input type="hidden" name="website" value="">
                    <input type="hidden" name="form_rendered_at" value="<?= e((string) $contactForm['renderedAt']); ?>">
                    <input type="hidden" name="form_token" value="<?= e((string) $contactForm['formToken']); ?>">

                    <div class="controller-config-workspace">
                        <div class="controller-config-controls">
                            <fieldset class="config-step" data-config-step="model">
                                <legend><span>01</span><strong>Welcher Controller ist es?</strong></legend>
                                <div class="controller-model-options">
                                    <?php foreach ($catalog['models'] as $modelId => $model): ?>
                                        <label class="controller-choice controller-model-choice">
                                            <input type="radio" name="model" value="<?= e((string) $modelId); ?>" data-label="<?= e((string) $model['shortLabel']); ?>" <?= $controllerChecked('model', (string) $modelId); ?> required>
                                            <span><strong><?= e((string) $model['label']); ?></strong><small><?= e((string) $model['description']); ?></small></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>

                            <fieldset class="config-step" data-config-step="source">
                                <legend><span>02</span><strong>Woher kommt der Controller?</strong></legend>
                                <div class="controller-source-options">
                                    <?php foreach ($catalog['sources'] as $sourceId => $source): ?>
                                        <?php $sourcePrices = is_array($source['priceCents'] ?? null) ? $source['priceCents'] : []; ?>
                                        <label class="controller-choice controller-source-choice">
                                            <input type="radio" name="source" value="<?= e((string) $sourceId); ?>"
                                                data-label="<?= e((string) $source['shortLabel']); ?>"
                                                data-price-dualsense="<?= e((string) ($sourcePrices['dualsense'] ?? 0)); ?>"
                                                data-price-dualsense-edge="<?= e((string) ($sourcePrices['dualsense-edge'] ?? 0)); ?>"
                                                <?= $controllerChecked('source', (string) $sourceId); ?> required>
                                            <span>
                                                <span class="controller-source-copy"><strong><?= e((string) $source['label']); ?></strong><small><?= e((string) $source['description']); ?></small></span>
                                                <?php if (max(array_map('intval', $sourcePrices)) > 0): ?>
                                                    <em class="controller-source-price">DualSense <?= e(controller_price((int) ($sourcePrices['dualsense'] ?? 0))); ?><small>Edge <?= e(controller_price((int) ($sourcePrices['dualsense-edge'] ?? 0))); ?></small></em>
                                                <?php else: ?>
                                                    <em class="controller-source-price">vorhanden<small>Versand ggf. extra</small></em>
                                                <?php endif; ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($controllerHasError('source')): ?><p class="controller-field-error">Bitte wählen Sie Übergabe, Versand oder einen neuen Controller.</p><?php endif; ?>
                            </fieldset>

                            <fieldset class="config-step" data-config-step="shell">
                                <legend><span>03</span><strong>Welche Optik darf es sein?</strong><small>Optionales Gehäuse</small></legend>
                                <p class="controller-offer-placeholder" data-shell-placeholder>Nach der Modellwahl erscheinen nur passende Gehäusevarianten.</p>
                                <input type="hidden" name="shell_design" value="<?= e($controllerValue('shellDesign')); ?>" data-shell-design>
                                <div class="controller-shell-options">
                                    <?php foreach ($catalog['shells'] as $shellId => $shell): ?>
                                        <?php $shellModels = is_array($shell['models'] ?? null) ? $shell['models'] : []; ?>
                                        <label class="controller-choice controller-shell-choice<?= (string) ($shell['visual'] ?? '') === 'catalog' ? ' is-catalog-placeholder' : ''; ?>" data-shell-card data-models="<?= e(implode(' ', $shellModels)); ?>">
                                            <input type="radio" name="shell" value="<?= e((string) $shellId); ?>"
                                                data-label="<?= e((string) $shell['shortLabel']); ?>"
                                                data-price-cents="<?= e((string) $shell['priceCents']); ?>"
                                                data-shell-color="<?= e((string) $shell['color']); ?>"
                                                data-shell-visual="<?= e((string) $shell['visual']); ?>"
                                                <?= $controllerChecked('shell', (string) $shellId, (string) $shellId === 'original'); ?> required>
                                            <span class="controller-shell-choice-content">
                                                <i class="controller-color-swatch" style="--swatch: <?= e((string) $shell['color']); ?>" aria-hidden="true"></i>
                                                <span><strong><?= e((string) $shell['label']); ?></strong><small><?= e((string) $shell['description']); ?></small></span>
                                                <em><?= (int) $shell['priceCents'] > 0 ? '+' . e(controller_price((int) $shell['priceCents'])) : 'ohne Aufpreis'; ?></em>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="controller-shell-catalog" data-shell-catalog hidden>
                                    <div class="controller-shell-catalog-head">
                                        <div><strong>Echte Design-Vorschauen</strong><small>Aktuelle Front- und Full-Shells aus dem eXtremeRate-Katalog</small></div>
                                        <label><span class="visually-hidden">Design suchen</span><input type="search" data-shell-search placeholder="Farbe oder Motiv suchen …" autocomplete="off"></label>
                                    </div>
                                    <p class="controller-shell-catalog-status" data-shell-catalog-status>Designs werden geladen …</p>
                                    <div class="controller-shell-gallery" data-shell-gallery></div>
                                    <p class="controller-shell-source-note">Produktabbildungen: eXtremeRate. Die endgültige Verfügbarkeit und technische Passform prüfe ich vor dem Angebot.</p>
                                </div>
                                <?php if ($controllerHasError('shell')): ?><p class="controller-field-error">Bitte wählen Sie eine zum Controller passende Gehäuseoption.</p><?php endif; ?>
                            </fieldset>

                            <fieldset class="config-step config-step-offers" data-config-step="offers">
                                <legend><span>04</span><strong>Welche Upgrades möchten Sie?</strong><small>Faire Gesamtpreise</small></legend>
                                <p class="controller-offer-intro">Alle angezeigten Preise verstehen sich als Gesamtpreis für das genannte Material, den Einbau und den abschließenden Funktionstest.</p>
                                <p class="controller-offer-placeholder" data-offer-placeholder>Wählen Sie zuerst DualSense oder DualSense Edge. Danach erscheinen nur passende Pakete.</p>
                                <div class="controller-offer-options" data-offer-list>
                                    <?php foreach ($catalog['offers'] as $offerId => $offer): ?>
                                        <?php $offerModels = is_array($offer['models'] ?? null) ? $offer['models'] : []; ?>
                                        <label class="controller-choice controller-offer-choice" data-offer-card data-models="<?= e(implode(' ', $offerModels)); ?>">
                                            <input type="checkbox" name="offers[]" value="<?= e((string) $offerId); ?>" data-label="<?= e((string) $offer['shortLabel']); ?>" data-price-cents="<?= e((string) $offer['priceCents']); ?>" data-zone="<?= e((string) $offer['zone']); ?>" data-exclusive-group="<?= e((string) $offer['group']); ?>" data-upgrade-preview="<?= e(match ((string) $offerId) { 'back-paddles-oled' => 'spark', 'edge-beyond-paddles' => 'beyond', 'back-paddles', 'back-paddles-metal' => 'rise4', default => '' }); ?>" <?= in_array((string) $offerId, $controllerList('offers'), true) ? 'checked' : ''; ?>>
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
                                <legend><span>05</span><strong>Was soll ich zusätzlich wissen?</strong><small>Optional</small></legend>
                                <div class="controller-extra-options">
                                    <?php foreach ($catalog['extras'] as $extraId => $extra): ?>
                                        <label class="controller-choice controller-extra-choice">
                                            <input type="checkbox" name="extras[]" value="<?= e((string) $extraId); ?>" data-label="<?= e((string) $extra['shortLabel']); ?>" <?= in_array((string) $extraId, $controllerList('extras'), true) ? 'checked' : ''; ?>>
                                            <span><strong><?= e((string) $extra['label']); ?></strong></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <label class="controller-notes">
                                    <span>Kurze Ergänzung <small>(optional)</small></span>
                                    <textarea name="notes" rows="4" maxlength="500" placeholder="Zum Beispiel: Spielstil, besondere Wünsche oder Rückfragen …"><?= e($controllerValue('notes')); ?></textarea>
                                </label>
                            </fieldset>

                            <fieldset class="config-step controller-contact-step" data-config-step="contact">
                                <legend><span>06</span><strong>Wohin darf das Angebot?</strong><small>Eigene Controller-Anfrage</small></legend>
                                <p class="controller-offer-intro">Diese Angaben gehören ausschließlich zur Controller-Konfiguration. Daraus werden in Dolibarr ein Interessent und ein unverbindlicher Angebotsentwurf angelegt – keine Website-E-Mail.</p>
                                <div class="controller-contact-grid">
                                    <label>
                                        <span>Vorname *</span>
                                        <input type="text" name="first_name" value="<?= e($controllerValue('firstName')); ?>" autocomplete="given-name" <?= $controllerHasError('firstName') || $controllerHasError('name') ? 'aria-invalid="true"' : ''; ?> required>
                                    </label>
                                    <label>
                                        <span>Nachname *</span>
                                        <input type="text" name="last_name" value="<?= e($controllerValue('lastName')); ?>" autocomplete="family-name" <?= $controllerHasError('lastName') || $controllerHasError('name') ? 'aria-invalid="true"' : ''; ?> required>
                                    </label>
                                    <label>
                                        <span>E-Mail *</span>
                                        <input type="email" name="email" value="<?= e($controllerValue('email')); ?>" autocomplete="email" <?= $controllerHasError('email') ? 'aria-invalid="true"' : ''; ?> required>
                                    </label>
                                    <label>
                                        <span>Telefon <small>(optional)</small></span>
                                        <input type="tel" name="phone" value="<?= e($controllerValue('phone')); ?>" autocomplete="tel">
                                    </label>
                                    <label class="controller-contact-wide">
                                        <span>Straße und Hausnummer <small>(optional)</small></span>
                                        <input type="text" name="address" value="<?= e($controllerValue('address')); ?>" autocomplete="street-address">
                                    </label>
                                    <label>
                                        <span>PLZ <small>(optional)</small></span>
                                        <input type="text" name="postal_code" value="<?= e($controllerValue('postalCode')); ?>" autocomplete="postal-code" inputmode="numeric">
                                    </label>
                                    <label>
                                        <span>Ort <small>(optional)</small></span>
                                        <input type="text" name="city" value="<?= e($controllerValue('city')); ?>" autocomplete="address-level2">
                                    </label>
                                </div>
                                <fieldset class="controller-contact-preference">
                                    <legend>Bevorzugte Rückmeldung</legend>
                                    <label><input type="radio" name="preferred_contact" value="email" <?= $controllerChecked('preferredContact', 'email', true); ?>><span>E-Mail</span></label>
                                    <label><input type="radio" name="preferred_contact" value="phone" <?= $controllerChecked('preferredContact', 'phone'); ?>><span>Telefon</span></label>
                                </fieldset>
                                <?php if ($contactForm['captchaEnabled']): ?>
                                    <div class="controller-captcha-row">
                                        <label>
                                            <span><?= e((string) $contactForm['captchaLabel']); ?> *</span>
                                            <input type="text" name="captcha_answer" inputmode="numeric" autocomplete="off" <?= $controllerHasError('captcha') ? 'aria-invalid="true"' : ''; ?> required>
                                        </label>
                                        <span class="controller-captcha-question"><?= e((string) $contactForm['captchaQuestion']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <label class="controller-consent">
                                    <input type="checkbox" name="privacy_confirmation" value="1" <?= $controllerChecked('privacyConfirmation', '1'); ?> <?= $controllerHasError('privacyConfirmation') ? 'aria-invalid="true"' : ''; ?> required>
                                    <span>Ich bestätige, dass meine Angaben zur Bearbeitung dieser Angebotsanfrage gemäß der <a href="<?= e(page_url('datenschutz.php')); ?>">Datenschutzerklärung</a> verarbeitet werden dürfen.</span>
                                </label>
                                <?php if ($controllerHasError('firstName') || $controllerHasError('lastName') || $controllerHasError('email') || $controllerHasError('privacyConfirmation') || $controllerHasError('captcha')): ?>
                                    <p class="controller-field-error">Bitte prüfen Sie Name, E-Mail, Datenschutzbestätigung und Sicherheitsfrage.</p>
                                <?php endif; ?>
                            </fieldset>
                        </div>

                        <aside class="controller-live-panel" aria-label="Vorschau und Zusammenfassung">
                            <div class="controller-live-stage" data-controller-stage data-controller-model="">
                                <p class="controller-live-kicker"><span></span> Live-Vorschau</p>
                                <div class="controller-product-visual" role="img" aria-label="Drehbare Live-Vorschau des gewählten Controllers mit dynamischen Upgrade-Bereichen">
                                    <div class="controller-visual-flipper" data-controller-flipper>
                                        <div class="controller-visual-face controller-visual-front">
                                            <img class="controller-product-image controller-product-dualsense" src="<?= e(asset_url('img/controller/controller-dualsense-premium.png')); ?>" alt="" width="768" height="512" loading="lazy" decoding="async">
                                            <img class="controller-product-image controller-product-edge" src="<?= e(asset_url('img/controller/controller-dualsense-edge-midnight-front.png')); ?>" alt="" width="1000" height="1000" loading="lazy" decoding="async">
                                            <img class="controller-catalog-preview" data-shell-preview src="" alt="" loading="lazy" decoding="async" hidden>
                                            <div class="controller-upgrade-hotspots" aria-hidden="true">
                                                <span class="controller-hotspot controller-hotspot-triggers" data-controller-zone="triggers"><i></i><b>Clicky Trigger</b></span>
                                                <span class="controller-hotspot controller-hotspot-dpad" data-controller-zone="dpad"><i></i><b>D-Pad</b></span>
                                                <span class="controller-hotspot controller-hotspot-buttons" data-controller-zone="buttons"><i></i><b>Clicky Buttons</b></span>
                                                <span class="controller-hotspot controller-hotspot-stick-left" data-controller-zone="stick-left"><i></i><b>Hall Effect</b></span>
                                                <span class="controller-hotspot controller-hotspot-stick-right" data-controller-zone="stick-right"><i></i></span>
                                                <span class="controller-hotspot controller-hotspot-battery" data-controller-zone="battery"><i></i><b>Akku</b></span>
                                                <span class="controller-hotspot controller-hotspot-led" data-controller-zone="led"><i></i><b>LED Kit</b></span>
                                            </div>
                                        </div>
                                        <div class="controller-visual-face controller-visual-back">
                                            <img class="controller-product-image controller-product-dualsense" src="<?= e(asset_url('img/controller/controller-dualsense-back.png')); ?>" alt="" width="768" height="512" loading="lazy" decoding="async">
                                            <img class="controller-product-image controller-product-edge" src="<?= e(asset_url('img/controller/controller-dualsense-edge-midnight-back.png')); ?>" alt="" width="1000" height="1000" loading="lazy" decoding="async">
                                            <img class="controller-upgrade-preview" data-upgrade-preview="rise4" src="<?= e(asset_url('img/controller/upgrade-rise4-cutout.png')); ?>" alt="Freigestellte reale Produktansicht eines montierten Vier-Tasten-Rückseiten-Kits" loading="lazy" decoding="async">
                                            <img class="controller-upgrade-preview" data-upgrade-preview="spark" src="<?= e(asset_url('img/controller/upgrade-spark-oled-cutout.png')); ?>" alt="Freigestellte reale Produktansicht des montierten SPARK OLED-Rückseiten-Kits" loading="lazy" decoding="async">
                                            <img class="controller-upgrade-preview" data-upgrade-preview="beyond" src="<?= e(asset_url('img/controller/upgrade-beyond-edge-cutout.png')); ?>" alt="Freigestellte reale Produktansicht des montierten BEYOND OLED-Rückseiten-Kits für Edge" loading="lazy" decoding="async">
                                        </div>
                                    </div>
                                </div>
                                <button class="controller-view-toggle" type="button" data-controller-view-toggle aria-pressed="false"><span data-controller-view-label>Rückseite ansehen</span><i aria-hidden="true">↻</i></button>
                                <div class="controller-visual-status">
                                    <p class="controller-model-badge" data-model-badge>Noch kein Modell gewählt</p>
                                    <p class="controller-visual-caption" data-visual-caption>Modell auswählen und Upgrades live entdecken</p>
                                </div>
                            </div>

                            <div class="controller-summary" aria-live="polite" aria-atomic="true">
                                <p class="controller-summary-eyebrow">Ihre Auswahl</p>
                                <dl>
                                    <div><dt>Modell</dt><dd data-summary-model>Bitte auswählen</dd></div>
                                    <div><dt>Controller</dt><dd data-summary-source>Bitte auswählen</dd></div>
                                    <div><dt>Optik</dt><dd data-summary-shell>Originalgehäuse</dd></div>
                                    <div><dt>Upgrades</dt><dd data-summary-offers>Noch kein Upgrade gewählt</dd></div>
                                    <div><dt>Zusatz</dt><dd data-summary-extras>Keine Zusatzangabe</dd></div>
                                </dl>
                                <div class="controller-assessment">
                                    <span data-price-kicker>Voraussichtliche Paketsumme</span>
                                    <strong data-summary-price>0,00 €</strong>
                                    <small data-price-note>Material, Einbau und Funktionstest laut gewählten Paketen.</small>
                                </div>
                                <button class="button button-primary controller-submit" type="submit">Unverbindliches Angebot anfragen <span aria-hidden="true">↗</span></button>
                                <p class="controller-submit-note">Direkt als Dolibarr-Angebotsentwurf · noch keine Beauftragung oder Bestellung</p>
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
                    <li data-reveal><span>01</span><div><h3>Konfiguration & Daten absenden</h3><p>Sie ergänzen Ihre Kontaktdaten direkt im Controller-Konfigurator. Das allgemeine Kontaktformular bleibt davon vollständig getrennt.</p></div></li>
                    <li data-reveal><span>02</span><div><h3>Kompatibilität intern prüfen</h3><p>Ich prüfe Controller-Ausführung, Kombination und Verfügbarkeit der gewählten Komponenten, ohne technische interne Details von Ihnen zu verlangen.</p></div></li>
                    <li data-reveal><span>03</span><div><h3>Angebot erhalten</h3><p>Dolibarr erstellt den Angebotsentwurf ohne Umsatzsteuer. Nach meiner Kontrolle wird das nachvollziehbare Angebot von dort versendet; erst nach Ihrer Freigabe werden Teile bestellt oder Arbeiten begonnen.</p></div></li>
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
