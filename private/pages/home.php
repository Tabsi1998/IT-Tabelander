<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/site-services.php';

$company = $siteConfig['company'];
$meta = page_meta('home');
$pageMeta = $meta;
$pageSchema = page_schema($pageMeta, $company);
$hero = $siteConfig['hero'];
$trustSignals = $siteConfig['trustSignals'];
$about = $siteConfig['about'];
$audiences = $siteConfig['audiences'];
$serviceBands = $siteConfig['serviceBands'];
$processSteps = $siteConfig['processSteps'];
$faq = $siteConfig['faq'];
$contactForm = build_contact_form_view_model($siteConfig);
$contactFlash = consume_contact_form_flash();
$formValues = is_array($contactFlash['values'] ?? null) ? $contactFlash['values'] : [];
$formErrors = is_array($contactFlash['errors'] ?? null) ? $contactFlash['errors'] : [];
$formMeta = is_array($contactFlash['meta'] ?? null) ? $contactFlash['meta'] : [];
$formValue = static fn (string $field): string => (string) ($formValues[$field] ?? '');
$formHasError = static fn (string $field): bool => in_array($field, $formErrors, true);
$formErrorAttributes = static fn (string $field): string => $formHasError($field)
    ? 'aria-invalid="true" aria-describedby="contact-error-' . $field . '"'
    : '';
$initialReviews = manual_reviews_payload($company);
$hasPublishedReviews = !empty($initialReviews['reviews']) && is_array($initialReviews['reviews']);
$mailErrorReference = trim((string) ($formMeta['requestId'] ?? ''));

$formStatus = $_GET['contact'] ?? '';
$formMessage = match ($formStatus) {
    'success' => 'Ihre Anfrage wurde gesendet. Ich melde mich so bald wie möglich zurück.',
    'partial' => 'Ihre Anfrage wurde übermittelt. Die automatische Bestätigungs-E-Mail konnte jedoch nicht zugestellt werden.',
    'mail_error' => 'Die Formularangaben wurden angenommen, aber der Mailserver konnte die Anfrage nicht versenden. Bitte versuchen Sie es später erneut oder schreiben Sie direkt an office@tabelander.co.at.'
        . ($mailErrorReference !== '' ? ' Referenz: ' . $mailErrorReference . '.' : ''),
    'error' => contact_error_message($formErrors),
    default => '',
};
?>
<!DOCTYPE html>
<html lang="<?= e($meta['language']); ?>">
<head>
    <?php require dirname(__DIR__) . '/partials/head.php'; ?>
</head>
<body data-reviews-url="<?= e(page_url('reviews.php')); ?>">
    <div class="site-shell">
        <header class="site-header">
            <a class="brand-lockup" href="#start" aria-label="Zur Startseite">
                <span class="brand-mobile-mark" aria-hidden="true">
                    <img class="brand-logo-image" src="<?= e(asset_url('img/logo/IT-Tabelander Logo Dunkel Transparent.png')); ?>" data-theme-logo data-logo-dark-src="<?= e(asset_url('img/logo/IT-Tabelander Logo Hell Transparent.png')); ?>" data-logo-light-src="<?= e(asset_url('img/logo/IT-Tabelander Logo Dunkel Transparent.png')); ?>" alt="" width="560" height="616" loading="eager">
                </span>
                <span class="brand-banner-shell" aria-hidden="true">
                    <img class="brand-banner-image" src="<?= e(asset_url('img/logo/IT-Tabelander Banner Dunkel Transparent.png')); ?>" data-theme-logo data-logo-dark-src="<?= e(asset_url('img/logo/IT-Tabelander Banner Hell Transparent.png')); ?>" data-logo-light-src="<?= e(asset_url('img/logo/IT-Tabelander Banner Dunkel Transparent.png')); ?>" alt="" width="1317" height="254" loading="eager">
                </span>
            </a>
            <div class="header-actions">
                <nav class="site-nav" id="site-navigation" aria-label="Hauptnavigation">
                    <a href="<?= e(page_url('pc-reparatur-telfs')); ?>">PC-Reparatur</a>
                    <a href="<?= e(page_url('it-betreuung-telfs')); ?>">Für Unternehmen</a>
                    <a href="<?= e(page_url('wlan-netzwerk-telfs')); ?>">WLAN</a>
                    <a href="#ablauf">Ablauf</a>
                    <?php if ($hasPublishedReviews): ?>
                        <a href="#bewertungen">Bewertungen</a>
                    <?php endif; ?>
                    <a href="#faq">FAQ</a>
                    <a href="#kontakt" class="nav-cta">Kontakt</a>
                </nav>
                <?= theme_toggle_markup(); ?>
                <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-navigation" aria-label="Navigation öffnen">
                    <span></span>
                    <span></span>
                </button>
            </div>
        </header>

        <main id="start">
            <section class="hero section">
                <div class="hero-copy" data-reveal>
                    <p class="section-eyebrow"><?= e($hero['eyebrow']); ?></p>
                    <h1><?= e($hero['headline']); ?></h1>
                    <p class="hero-lead"><?= e($hero['lead']); ?></p>
                    <div class="hero-actions">
                        <a class="button button-primary" href="#kontakt"><?= e($hero['primaryCta']); ?></a>
                        <a class="button button-secondary" href="tel:<?= e(phone_href($company['phone'])); ?>">Jetzt anrufen: <?= e($company['phone']); ?></a>
                    </div>
                    <ul class="hero-points" aria-label="Schwerpunkte">
                        <?php foreach ($hero['highlights'] as $highlight): ?>
                            <li><?= e($highlight); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="hero-visual" data-reveal>
                    <div class="hero-image-frame">
                        <img src="<?= e(asset_url('img/hero-it-tabelander-768.webp')); ?>" srcset="<?= e(asset_url('img/hero-it-tabelander-768.webp')); ?> 768w, <?= e(asset_url('img/hero-it-tabelander-1440.webp')); ?> 1440w" sizes="(max-width: 980px) 100vw, 48vw" alt="Werkbank mit offenem PC, Laptop und Controller-Reparatur" width="1717" height="916" loading="eager" fetchpriority="high">
                    </div>
                    <div class="hero-aside">
                        <p>Standort und Einsatzgebiet</p>
                        <strong><?= e($company['serviceArea']); ?></strong>
                    </div>
                </div>
            </section>

            <section class="signal-strip section">
                <?php foreach ($trustSignals as $signal): ?>
                    <article class="signal-item" data-reveal>
                        <h2><?= e($signal['title']); ?></h2>
                        <p><?= e($signal['text']); ?></p>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="local-services section" aria-labelledby="local-services-title">
                <div class="section-heading" data-reveal>
                    <p class="section-eyebrow">Direkt zum passenden Service</p>
                    <h2 id="local-services-title">IT-Hilfe in Telfs – klar nach Anliegen.</h2>
                    <p>Wählen Sie den Bereich, der zu Ihrem Problem oder Betrieb passt.</p>
                </div>
                <div class="local-services-grid">
                    <a class="local-service-link" href="<?= e(page_url('pc-reparatur-telfs')); ?>" data-reveal>
                        <span>Für Geräte</span>
                        <h3>PC- & Laptop-Reparatur</h3>
                        <p>Diagnose, Startprobleme, Überhitzung sowie SSD- und RAM-Upgrades.</p>
                        <strong>Reparatur in Telfs ansehen →</strong>
                    </a>
                    <a class="local-service-link" href="<?= e(page_url('it-betreuung-telfs')); ?>" data-reveal>
                        <span>Für Unternehmen</span>
                        <h3>Laufende IT-Betreuung</h3>
                        <p>Arbeitsplätze, Benutzer, Netzwerk, Server, Updates und Dokumentation.</p>
                        <strong>Firmenbetreuung ansehen →</strong>
                    </a>
                    <a class="local-service-link" href="<?= e(page_url('wlan-netzwerk-telfs')); ?>" data-reveal>
                        <span>Privat & Betrieb</span>
                        <h3>WLAN & Netzwerk</h3>
                        <p>Funklöcher messen, Access Points planen und Verbindungen stabilisieren.</p>
                        <strong>WLAN-Service ansehen →</strong>
                    </a>
                </div>
            </section>

            <section class="audience-section section">
                <div class="section-heading" data-reveal>
                    <p class="section-eyebrow">Leistungsfokus</p>
                    <h2>IT ohne unnötige Schubladen.</h2>
                    <p>Der Fokus liegt auf dem Problem und der passenden Lösung: Reparatur, Einrichtung, Netzwerk, WLAN, Server oder Sicherheit.</p>
                </div>
                <div class="audience-grid">
                    <?php foreach ($audiences as $audience): ?>
                        <article class="audience-panel" data-reveal>
                            <p class="audience-label"><?= e($audience['label']); ?></p>
                            <h3><?= e($audience['headline']); ?></h3>
                            <p><?= e($audience['copy']); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="about-section section" id="ueber-uns">
                <div class="about-copy" data-reveal>
                    <p class="section-eyebrow"><?= e((string) $about['eyebrow']); ?></p>
                    <h2><?= e((string) $about['headline']); ?></h2>
                    <p><?= e((string) $about['copy']); ?></p>
                    <div class="hero-actions">
                        <a class="button button-primary" href="#kontakt">Fabian Tabelander kontaktieren</a>
                        <a class="button button-secondary" href="tel:<?= e(phone_href($company['phone'])); ?>"><?= e($company['phone']); ?></a>
                    </div>
                </div>
                <aside class="about-facts" aria-label="Arbeitsweise" data-reveal>
                    <p><strong>Inhaber</strong><span><?= e($company['owner']); ?></span></p>
                    <p><strong>Standort</strong><span><?= e($company['postalCode'] . ' ' . $company['city']); ?></span></p>
                    <ul>
                        <?php foreach ($about['principles'] as $principle): ?>
                            <li><?= e((string) $principle); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </aside>
            </section>

            <section class="services-section section" id="leistungen">
                <div class="section-heading" data-reveal>
                    <p class="section-eyebrow">Leistungen</p>
                    <h2>Leistungen im Überblick.</h2>
                    <p>Von Endgeräten bis Infrastruktur klar gegliedert und technisch nachvollziehbar.</p>
                </div>
                <div class="services-carousel-shell" data-reveal>
                    <div class="services-carousel-head">
                        <div class="services-carousel-copy">
                            <p class="reviews-label">Ausgewählte Bereiche</p>
                            <p>Reparatur, Systempflege, Netzwerk, WLAN und Sicherheit nach Themen gebündelt.</p>
                        </div>
                        <div class="service-filter" aria-label="Leistungen filtern">
                            <button class="service-filter-button is-active" type="button" data-service-filter="all" aria-pressed="true">Alle</button>
                            <button class="service-filter-button" type="button" data-service-filter="reparatur" aria-pressed="false">Reparatur</button>
                            <button class="service-filter-button" type="button" data-service-filter="systeme" aria-pressed="false">Systeme</button>
                            <button class="service-filter-button" type="button" data-service-filter="netzwerk" aria-pressed="false">Netzwerk/WLAN</button>
                            <button class="service-filter-button" type="button" data-service-filter="sicherheit" aria-pressed="false">Sicherheit</button>
                        </div>
                        <div class="reviews-controls">
                            <button class="slider-button autoplay-button" type="button" data-service-autoplay aria-pressed="false">Pause</button>
                            <button class="slider-button" type="button" data-service-slide="prev" aria-label="Vorherige Leistung">&#8592;</button>
                            <button class="slider-button" type="button" data-service-slide="next" aria-label="Nächste Leistung">&#8594;</button>
                        </div>
                    </div>
                    <div class="services-carousel" data-service-carousel aria-live="polite">
                        <div class="services-track" data-service-track>
                        <?php foreach ($serviceBands as $band): ?>
                            <?php
                                $serviceGroups = array_merge(['all'], is_array($band['groups'] ?? null) ? $band['groups'] : []);
                            ?>
                            <article class="service-card" data-service-card data-service-groups="<?= e(implode(' ', array_unique($serviceGroups))); ?>" tabindex="0">
                                <?php if (!empty($band['image'])): ?>
                                    <div class="service-card-media">
                                        <?php $serviceImageBase = pathinfo((string) $band['image'], PATHINFO_FILENAME); ?>
                                        <img src="<?= e(asset_url('img/services/' . $serviceImageBase . '-640.webp')); ?>" srcset="<?= e(asset_url('img/services/' . $serviceImageBase . '-640.webp')); ?> 640w, <?= e(asset_url('img/services/' . $serviceImageBase . '-1200.webp')); ?> 1200w" sizes="(max-width: 640px) 92vw, (max-width: 1180px) 46vw, 31vw" alt="<?= e($band['title']); ?>" width="<?= e((string) ($band['imageWidth'] ?? 1536)); ?>" height="<?= e((string) ($band['imageHeight'] ?? 1024)); ?>" loading="lazy" decoding="async">
                                    </div>
                                <?php endif; ?>
                                <div class="service-card-body">
                                    <p class="service-audience"><?= e($band['audience']); ?></p>
                                    <h3><?= e($band['title']); ?></h3>
                                    <p class="service-intro"><?= e($band['intro']); ?></p>
                                    <div class="service-card-details">
                                        <p>Schwerpunkte</p>
                                        <ul class="service-list service-list-compact">
                                        <?php foreach ($band['items'] as $item): ?>
                                            <li><?= e($item); ?></li>
                                        <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="process-section section" id="ablauf">
                <div class="section-heading" data-reveal>
                    <p class="section-eyebrow">Ablauf</p>
                    <h2>Klar von Anfrage bis Übergabe.</h2>
                    <p>Jede Umsetzung soll verständlich bleiben: vom ersten Fehlerbild bis zur getesteten Übergabe.</p>
                </div>
                <div class="process-track">
                    <?php foreach ($processSteps as $index => $step): ?>
                        <article class="process-step" data-reveal>
                            <p class="process-number"><?= e(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></p>
                            <h3><?= e($step['title']); ?></h3>
                            <p><?= e($step['text']); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if ($hasPublishedReviews): ?>
            <section class="reviews-section section" id="bewertungen">
                <div class="section-heading" data-reveal>
                    <p class="section-eyebrow">Bewertungen</p>
                    <h2>Kundenstimmen.</h2>
                    <p>Rückmeldungen aus abgeschlossenen IT-Service-, Reparatur- und Betreuungsterminen.</p>
                </div>
                <div class="reviews-shell" data-reveal>
                    <div class="reviews-meta">
                        <p class="reviews-label">Kundenrezensionen</p>
                        <div class="reviews-controls">
                            <button class="slider-button autoplay-button" type="button" data-review-autoplay aria-pressed="false">Pause</button>
                            <button class="slider-button" type="button" data-slide="prev" aria-label="Vorherige Bewertung">&#8592;</button>
                            <button class="slider-button" type="button" data-slide="next" aria-label="Nächste Bewertung">&#8594;</button>
                        </div>
                    </div>
                    <div class="reviews-slider" aria-live="polite">
                        <div class="reviews-track" id="reviews-track">
                            <?php foreach ($initialReviews['reviews'] as $review): ?>
                                <article class="review-slide">
                                    <p class="review-rating"><?= e((string) ($review['rating'] ?? 'Bewertung')); ?><?= !empty($review['rating']) ? ' / 5' : ''; ?></p>
                                    <h3><?= e((string) ($review['author'] ?? 'Kundenrezension')); ?></h3>
                                    <p><?= e((string) ($review['text'] ?? '')); ?></p>
                                    <div class="review-meta">
                                        <span><?= e((string) ($review['relativeTime'] ?? 'Kundenrezension')); ?></span>
                                        <?php if (!empty($review['url'])): ?>
                                            <a href="<?= e((string) $review['url']); ?>" target="_blank" rel="noreferrer">Auf Google ansehen</a>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <p class="reviews-footnote" id="reviews-footnote">
                        <?= e((string) $initialReviews['message']); ?>
                    </p>
                </div>
            </section>
            <?php endif; ?>

            <section class="faq-section section" id="faq">
                <div class="section-heading" data-reveal>
                    <p class="section-eyebrow">FAQ</p>
                    <h2>Vorab geklärt.</h2>
                </div>
                <div class="faq-list">
                    <?php foreach ($faq as $entry): ?>
                        <details class="faq-item" data-reveal>
                            <summary>
                                <span><?= e($entry['question']); ?></span>
                                <span class="faq-icon" aria-hidden="true"></span>
                            </summary>
                            <div class="faq-answer">
                                <p><?= e($entry['answer']); ?></p>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="contact-section section" id="kontakt">
                <div class="contact-copy" data-reveal>
                    <p class="section-eyebrow">Kontakt</p>
                    <h2>Direkt anfragen.</h2>
                    <p>Beschreiben Sie kurz, worum es geht. Ich melde mich mit einer Einschätzung zum nächsten sinnvollen Schritt.</p>
                    <dl class="contact-facts">
                        <div>
                            <dt>Telefon</dt>
                            <dd><a href="tel:<?= e(phone_href($company['phone'])); ?>"><?= e($company['phone']); ?></a></dd>
                        </div>
                        <div>
                            <dt>E-Mail</dt>
                            <dd><a href="mailto:<?= e($company['email']); ?>"><?= e($company['email']); ?></a></dd>
                        </div>
                        <div>
                            <dt>Standort</dt>
                            <dd><?= e(company_address_inline($company)); ?></dd>
                        </div>
                        <div>
                            <dt>Einsatzgebiet</dt>
                            <dd><?= e($company['serviceArea']); ?></dd>
                        </div>
                        <div>
                            <dt>Termine</dt>
                            <dd><?= e($company['businessHours']); ?></dd>
                        </div>
                    </dl>
                </div>
                <div class="contact-form-shell" data-reveal>
                    <?php if ($formMessage !== ''): ?>
                        <p class="form-feedback <?= in_array($formStatus, ['success', 'partial'], true) ? 'is-success' : 'is-error'; ?>"><?= e($formMessage); ?></p>
                    <?php endif; ?>
                    <form class="contact-form" action="<?= e(page_url('contact.php')); ?>" method="post">
                        <input type="hidden" name="website" value="">
                        <input type="hidden" name="form_rendered_at" value="<?= e((string) $contactForm['renderedAt']); ?>">
                        <input type="hidden" name="form_token" value="<?= e($contactForm['formToken']); ?>">
                        <div class="form-row">
                            <label>
                                <span>Name</span>
                                <input type="text" name="name" value="<?= e($formValue('name')); ?>" <?= $formErrorAttributes('name'); ?> required>
                                <?php if ($formHasError('name')): ?><small class="form-field-error" id="contact-error-name">Bitte geben Sie einen Namen ein.</small><?php endif; ?>
                            </label>
                            <label>
                                <span>E-Mail</span>
                                <input type="email" name="email" value="<?= e($formValue('email')); ?>" <?= $formErrorAttributes('email'); ?> required>
                                <?php if ($formHasError('email')): ?><small class="form-field-error" id="contact-error-email">Bitte geben Sie eine gültige E-Mail-Adresse ein.</small><?php endif; ?>
                            </label>
                        </div>
                        <div class="form-row">
                            <label>
                                <span>Telefon</span>
                                <input type="text" name="phone" value="<?= e($formValue('phone')); ?>">
                            </label>
                            <label>
                                <span>Anliegen</span>
                                <select name="audience" <?= $formErrorAttributes('audience'); ?> required>
                                    <option value="">Bitte wählen</option>
                                    <option value="Reparatur und Diagnose" <?= $formValue('audience') === 'Reparatur und Diagnose' ? 'selected' : ''; ?>>Reparatur und Diagnose</option>
                                    <option value="Einrichtung und Systempflege" <?= $formValue('audience') === 'Einrichtung und Systempflege' ? 'selected' : ''; ?>>Einrichtung und Systempflege</option>
                                    <option value="Netzwerk und WLAN" <?= $formValue('audience') === 'Netzwerk und WLAN' ? 'selected' : ''; ?>>Netzwerk und WLAN</option>
                                    <option value="Sicherheit und Virenprüfung" <?= $formValue('audience') === 'Sicherheit und Virenprüfung' ? 'selected' : ''; ?>>Sicherheit und Virenprüfung</option>
                                    <option value="Server und Betreuung" <?= $formValue('audience') === 'Server und Betreuung' ? 'selected' : ''; ?>>Server und Betreuung</option>
                                    <option value="Sonstiges IT-Anliegen" <?= $formValue('audience') === 'Sonstiges IT-Anliegen' ? 'selected' : ''; ?>>Sonstiges IT-Anliegen</option>
                                </select>
                                <?php if ($formHasError('audience')): ?><small class="form-field-error" id="contact-error-audience">Bitte wählen Sie ein Anliegen.</small><?php endif; ?>
                            </label>
                        </div>
                        <label>
                            <span>Leistung</span>
                            <select name="service" <?= $formErrorAttributes('service'); ?> required>
                                <option value="">Bitte wählen</option>
                                <?php foreach ($serviceBands as $band): ?>
                                    <?php $optionGroups = is_array($band['groups'] ?? null) ? $band['groups'] : []; ?>
                                    <option value="<?= e($band['title']); ?>" data-service-groups="<?= e(implode(' ', array_unique($optionGroups))); ?>" <?= $formValue('service') === $band['title'] ? 'selected' : ''; ?>><?= e($band['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($formHasError('service')): ?><small class="form-field-error" id="contact-error-service">Bitte wählen Sie eine Leistung.</small><?php endif; ?>
                        </label>
                        <label>
                            <span>Nachricht</span>
                            <textarea name="message" rows="7" <?= $formErrorAttributes('message'); ?> required><?= e($formValue('message')); ?></textarea>
                            <?php if ($formHasError('message')): ?><small class="form-field-error" id="contact-error-message">Bitte beschreiben Sie Ihr Anliegen etwas genauer.</small><?php endif; ?>
                        </label>
                        <?php if ($contactForm['captchaEnabled']): ?>
                            <div class="form-row form-row-captcha">
                                <label>
                                    <span><?= e($contactForm['captchaLabel']); ?></span>
                                    <input type="text" name="captcha_answer" inputmode="numeric" autocomplete="off" <?= $formErrorAttributes('captcha'); ?> required>
                                    <?php if ($formHasError('captcha')): ?><small class="form-field-error" id="contact-error-captcha">Bitte lösen Sie die Sicherheitsfrage erneut.</small><?php endif; ?>
                                </label>
                                <div class="captcha-question" aria-hidden="true">
                                    <span><?= e($contactForm['captchaQuestion']); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                        <label class="consent-check">
                            <input type="checkbox" name="privacy_confirmation" value="1" <?= $formValue('privacyConfirmation') === '1' ? 'checked' : ''; ?> <?= $formErrorAttributes('privacyConfirmation'); ?> required>
                            <span>Ich bestätige, dass meine Angaben zur Bearbeitung meiner Anfrage gemäß der <a href="<?= e(page_url('datenschutz.php')); ?>">Datenschutzerklärung</a> verarbeitet werden dürfen.</span>
                            <?php if ($formHasError('privacyConfirmation')): ?><small class="form-field-error" id="contact-error-privacyConfirmation">Bitte bestätigen Sie die Datenschutzerklärung.</small><?php endif; ?>
                        </label>
                        <p class="form-note">Mit dem Absenden werden die Angaben zur Bearbeitung Ihrer Anfrage verarbeitet. Auf Wunsch kann zusätzlich eine automatische Eingangsbestätigung an die angegebene E-Mail-Adresse versendet werden. Details finden Sie in der <a href="<?= e(page_url('datenschutz.php')); ?>">Datenschutzerklärung</a>.</p>
                        <button class="button button-primary" type="submit">Anfrage absenden</button>
                    </form>
                </div>
            </section>
        </main>

        <footer class="site-footer">
            <div class="footer-brand">
                <p class="brand-name"><?= e($company['name']); ?></p>
                <p>IT-Dienstleistungen für Reparatur, Systeme und Infrastruktur in Tirol.</p>
            </div>
            <div class="footer-links">
                <a href="<?= e(page_url('impressum.php')); ?>">Impressum</a>
                <a href="<?= e(page_url('datenschutz.php')); ?>">Datenschutz</a>
                <a href="<?= e(page_url('nutzungsbedingungen.php')); ?>">Nutzungsbedingungen</a>
            </div>
            <p class="footer-note"><?= e($company['name']); ?>, <?= e($company['city']); ?>, <?= e($company['country']); ?></p>
        </footer>
        <?= cookie_notice_markup(); ?>
    </div>
    <nav class="mobile-action-bar" aria-label="Schnellkontakt">
        <a href="tel:<?= e(phone_href($company['phone'])); ?>">Anrufen</a>
        <a href="#kontakt">Anfrage senden</a>
    </nav>
    <script src="<?= e(asset_url('js/main.js')); ?>" defer></script>
</body>
</html>
