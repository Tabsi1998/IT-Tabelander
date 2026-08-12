<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/site-services.php';

$company = $siteConfig['company'];
$meta = page_meta('home');
$pageMeta = $meta;
$pageSchema = page_schema($pageMeta, $company);
$hero = $siteConfig['hero'];
$about = $siteConfig['about'];
$serviceBands = $siteConfig['serviceBands'];
$processSteps = $siteConfig['processSteps'];
$faq = $siteConfig['faq'];
$publicStats = load_public_stats();
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
<body data-page-key="home" data-contact-status="<?= e((string) $formStatus); ?>" data-reviews-url="<?= e(page_url('reviews.php')); ?>">
    <div class="site-shell">
        <?php $activePageKey = 'home'; require dirname(__DIR__) . '/partials/site-header.php'; ?>

        <main id="start">
            <section class="home-hero section" aria-labelledby="home-hero-title">
                <div class="home-hero-copy" data-reveal>
                    <p class="section-eyebrow"><?= e((string) $hero['eyebrow']); ?></p>
                    <h1 id="home-hero-title"><span><?= e((string) $hero['headlineAccent']); ?></span><?= e((string) $hero['headline']); ?></h1>
                    <p class="home-hero-lead"><?= e((string) $hero['lead']); ?></p>
                    <div class="hero-actions">
                        <a class="button button-primary" href="#kontakt" data-conversion="primary-cta" data-conversion-location="hero"><?= e((string) $hero['primaryCta']); ?> <span aria-hidden="true">↗</span></a>
                        <a class="home-phone-link" href="tel:<?= e(phone_href((string) $company['phone'])); ?>">
                            <span class="home-phone-icon" aria-hidden="true">↗</span>
                            <span><small>Lieber direkt sprechen?</small><strong><?= e((string) $company['phone']); ?></strong></span>
                        </a>
                    </div>
                    <ul class="home-trust-line" aria-label="Ihre Vorteile">
                        <?php foreach ($hero['highlights'] as $highlight): ?>
                            <li><?= e((string) $highlight); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="home-hero-visual" data-reveal>
                    <div class="home-hero-image">
                        <img src="<?= e(asset_url('img/hero-it-tabelander-768.webp')); ?>" srcset="<?= e(asset_url('img/hero-it-tabelander-768.webp')); ?> 768w, <?= e(asset_url('img/hero-it-tabelander-1440.webp')); ?> 1440w" sizes="(max-width: 980px) 100vw, 46vw" alt="Werkbank für Computer-, Laptop- und Controller-Reparaturen" width="1717" height="916" loading="eager" fetchpriority="high">
                    </div>
                    <div class="home-hero-stamp" aria-label="Persönliche Betreuung direkt durch mich">
                        <span>Direkt bei</span>
                        <strong>mir</strong>
                    </div>
                    <div class="home-hero-location">
                        <span class="status-dot" aria-hidden="true"></span>
                        <p><small>Hilfe aus Telfs</small><strong>Vor Ort · Fernwartung · Geräteübergabe</strong></p>
                    </div>
                </div>
            </section>

            <a class="problem-jump" href="#hilfe" data-reveal>
                <span>Was macht Probleme?</span>
                <strong>PC & Laptop</strong>
                <strong>WLAN zu Hause</strong>
                <strong>Einrichten & Aufrüsten</strong>
                <strong>Sicherheit</strong>
                <span class="problem-jump-arrow" aria-hidden="true">↓</span>
            </a>

            <section class="problem-finder section" id="hilfe" aria-labelledby="problem-finder-title">
                <div class="problem-finder-heading" data-reveal>
                    <p class="section-eyebrow">Wobei kann ich helfen?</p>
                    <h2 id="problem-finder-title">Nicht jede Störung braucht gleich ein neues Gerät.</h2>
                    <p>Wählen Sie einfach den Bereich, der am besten zu Ihrem Problem passt. Gemeinsam klären wir, ob eine Reparatur, eine neue Einstellung oder ein Austausch sinnvoll ist.</p>
                </div>
                <div class="problem-finder-layout">
                    <div class="problem-list" data-service-accordion data-reveal>
                        <?php foreach ($serviceBands as $index => $band): ?>
                            <details class="problem-service" <?= $index === 0 ? 'open' : ''; ?> data-service-detail data-image-src="<?= e(asset_url('img/services/' . $band['image'] . '-1200.webp')); ?>" data-image-srcset="<?= e(asset_url('img/services/' . $band['image'] . '-640.webp')); ?> 640w, <?= e(asset_url('img/services/' . $band['image'] . '-1200.webp')); ?> 1200w" data-image-alt="<?= e((string) $band['imageAlt']); ?>">
                                <summary>
                                    <span class="problem-index"><?= e(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></span>
                                    <span class="problem-title"><strong><?= e((string) $band['title']); ?></strong><small><?= e((string) $band['audience']); ?></small></span>
                                    <span class="problem-toggle" aria-hidden="true"></span>
                                </summary>
                                <div class="problem-service-body">
                                    <p><?= e((string) $band['intro']); ?></p>
                                    <ul>
                                        <?php foreach ($band['items'] as $item): ?><li><?= e((string) $item); ?></li><?php endforeach; ?>
                                    </ul>
                                    <a href="#kontakt">Dieses Problem schildern <span aria-hidden="true">↗</span></a>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                    <figure class="problem-visual" data-service-visual data-reveal>
                        <?php $firstService = $serviceBands[0]; ?>
                        <img src="<?= e(asset_url('img/services/' . $firstService['image'] . '-1200.webp')); ?>" srcset="<?= e(asset_url('img/services/' . $firstService['image'] . '-640.webp')); ?> 640w, <?= e(asset_url('img/services/' . $firstService['image'] . '-1200.webp')); ?> 1200w" sizes="(max-width: 980px) 100vw, 42vw" alt="<?= e((string) $firstService['imageAlt']); ?>" width="<?= e((string) ($firstService['imageWidth'] ?? 1536)); ?>" height="<?= e((string) ($firstService['imageHeight'] ?? 1024)); ?>" loading="lazy" decoding="async">
                        <figcaption><span aria-hidden="true">●</span> Erst prüfen. Dann ehrlich empfehlen.</figcaption>
                    </figure>
                </div>
            </section>

            <section class="home-process section" id="ablauf" aria-labelledby="home-process-title">
                <div class="home-process-intro" data-reveal>
                    <p class="section-eyebrow">So läuft es</p>
                    <h2 id="home-process-title">Von „geht nicht“ zu „läuft wieder“.</h2>
                    <p>Sie müssen das Problem nicht technisch erklären können. Beschreiben Sie einfach, was Sie sehen oder hören.</p>
                    <a class="text-link" href="#kontakt">Jetzt kurz schildern <span aria-hidden="true">↗</span></a>
                </div>
                <ol class="home-process-list">
                    <?php foreach ($processSteps as $index => $step): ?>
                        <li data-reveal>
                            <span><?= e(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></span>
                            <div><h3><?= e((string) $step['title']); ?></h3><p><?= e((string) $step['text']); ?></p></div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <?php if ($publicStats !== []): ?>
            <section class="home-stats section" aria-label="Belegte Kennzahlen">
                <?php foreach ($publicStats as $stat): ?>
                    <p data-reveal><strong data-count-up="<?= e((string) $stat['value']); ?>">0</strong><span class="stat-suffix"><?= e((string) $stat['suffix']); ?></span><small><?= e((string) $stat['label']); ?></small></p>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <section class="home-about section" id="ueber-mich" aria-labelledby="home-about-title">
                <div class="home-about-mark" data-reveal aria-hidden="true"><span>FT</span><small>Telfs · Tirol</small></div>
                <div class="home-about-copy" data-reveal>
                    <p class="section-eyebrow"><?= e((string) $about['eyebrow']); ?></p>
                    <h2 id="home-about-title"><?= e((string) $about['headline']); ?></h2>
                    <p class="home-about-lead"><?= e((string) $about['copy']); ?></p>
                    <ul>
                        <?php foreach ($about['principles'] as $principle): ?><li><?= e((string) $principle); ?></li><?php endforeach; ?>
                    </ul>
                    <div class="hero-actions">
                        <a class="button button-dark" href="#kontakt">Mich kontaktieren <span aria-hidden="true">↗</span></a>
                        <a class="text-link text-link-dark" href="tel:<?= e(phone_href((string) $company['phone'])); ?>"><?= e((string) $company['phone']); ?></a>
                    </div>
                </div>
            </section>

            <?php if ($hasPublishedReviews): ?>
            <section class="home-reviews section" id="bewertungen" aria-labelledby="home-reviews-title">
                <div class="home-reviews-head" data-reveal>
                    <div><p class="section-eyebrow">Echte Rückmeldungen</p><h2 id="home-reviews-title">Was Kundinnen und Kunden sagen.</h2></div>
                    <div class="reviews-controls">
                        <button class="slider-button" type="button" data-slide="prev" aria-label="Vorherige Bewertung">←</button>
                        <button class="slider-button" type="button" data-slide="next" aria-label="Nächste Bewertung">→</button>
                    </div>
                </div>
                <div class="reviews-slider" data-reveal aria-live="polite">
                    <div class="reviews-track" id="reviews-track">
                        <?php foreach ($initialReviews['reviews'] as $review): ?>
                            <article class="review-slide">
                                <p class="review-rating"><?= e((string) ($review['rating'] ?? '')); ?><?= !empty($review['rating']) ? ' / 5' : 'Kundenstimme'; ?></p>
                                <blockquote>„<?= e((string) ($review['text'] ?? '')); ?>“</blockquote>
                                <div class="review-meta"><strong><?= e((string) ($review['author'] ?? 'Kundenrezension')); ?></strong><span><?= e((string) ($review['relativeTime'] ?? '')); ?></span><?php if (!empty($review['url'])): ?><a href="<?= e((string) $review['url']); ?>" target="_blank" rel="noreferrer">Original ansehen</a><?php endif; ?></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <section class="home-faq section" id="faq" aria-labelledby="home-faq-title">
                <div class="home-faq-intro" data-reveal>
                    <p class="section-eyebrow">Kurz beantwortet</p>
                    <h2 id="home-faq-title">Häufig gestellte Fragen.</h2>
                    <p>Noch unsicher? Rufen Sie mich einfach an. Vieles lässt sich bereits in einem kurzen Gespräch klären.</p>
                </div>
                <div class="faq-list">
                    <?php foreach ($faq as $entry): ?>
                        <details class="faq-item" data-reveal>
                            <summary><span><?= e((string) $entry['question']); ?></span><span class="faq-icon" aria-hidden="true"></span></summary>
                            <div class="faq-answer"><p><?= e((string) $entry['answer']); ?></p></div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php require dirname(__DIR__) . '/partials/home-contact.php'; ?>
        </main>

        <?php require dirname(__DIR__) . '/partials/site-footer.php'; ?>
        <?= cookie_notice_markup(); ?>
    </div>
    <?php $contactHref = '#kontakt'; require dirname(__DIR__) . '/partials/mobile-contact.php'; ?>
    <script src="<?= e(asset_url('js/main.js')); ?>" type="module"></script>
</body>
</html>
