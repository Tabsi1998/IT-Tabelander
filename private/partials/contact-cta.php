<?php
declare(strict_types=1);
?>
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
