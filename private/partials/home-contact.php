<?php
declare(strict_types=1);
?>
<section class="contact-section section" id="kontakt">
    <div class="contact-copy" data-reveal>
        <p class="section-eyebrow">Direkter Kontakt</p>
        <h2>Wobei brauchen Sie Hilfe?</h2>
        <p>Ein paar Stichworte reichen für den Anfang. Ich melde mich persönlich bei Ihnen und erkläre, welcher nächste Schritt sinnvoll ist.</p>
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
                <dd><?= e($company['postalCode'] . ' ' . $company['city']); ?></dd>
            </div>
            <div>
                <dt>Hilfe</dt>
                <dd>Vor Ort, per Fernwartung oder nach Geräteübergabe</dd>
            </div>
            <div>
                <dt>Termine</dt>
                <dd><?= e($company['businessHours']); ?></dd>
            </div>
        </dl>
    </div>
    <div class="contact-form-shell" data-reveal>
        <?php if (!empty($controllerReady)): ?>
            <p class="form-feedback is-success">Ihre Controller-Auswahl wurde übernommen. Ergänzen Sie jetzt bitte noch Ihre Kontaktdaten und senden Sie die unverbindliche Anfrage ab.</p>
        <?php endif; ?>
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
                    <input type="text" name="name" value="<?= e($formValue('name')); ?>" <?= $formErrorAttributes('name'); ?> autocomplete="name" required>
                    <?php if ($formHasError('name')): ?><small class="form-field-error" id="contact-error-name">Bitte geben Sie einen Namen ein.</small><?php endif; ?>
                </label>
                <label>
                    <span>E-Mail</span>
                    <input type="email" name="email" value="<?= e($formValue('email')); ?>" <?= $formErrorAttributes('email'); ?> autocomplete="email" required>
                    <?php if ($formHasError('email')): ?><small class="form-field-error" id="contact-error-email">Bitte geben Sie eine gültige E-Mail-Adresse ein.</small><?php endif; ?>
                </label>
            </div>
            <div class="form-row">
                <label>
                    <span>Telefon <small>(optional)</small></span>
                    <input type="tel" name="phone" value="<?= e($formValue('phone')); ?>" autocomplete="tel">
                </label>
                <label>
                    <span>Worum geht es?</span>
                    <select name="audience" <?= $formErrorAttributes('audience'); ?> required>
                        <option value="">Bitte wählen</option>
                        <option value="Reparatur und Diagnose" <?= $formValue('audience') === 'Reparatur und Diagnose' ? 'selected' : ''; ?>>PC oder Laptop</option>
                        <option value="Einrichtung und Systempflege" <?= $formValue('audience') === 'Einrichtung und Systempflege' ? 'selected' : ''; ?>>Einrichtung oder Aufrüstung</option>
                        <option value="Netzwerk und WLAN" <?= $formValue('audience') === 'Netzwerk und WLAN' ? 'selected' : ''; ?>>WLAN oder Heimnetz</option>
                        <option value="Sicherheit und Virenprüfung" <?= $formValue('audience') === 'Sicherheit und Virenprüfung' ? 'selected' : ''; ?>>Sicherheit oder Virenverdacht</option>
                        <option value="Gaming-Hardware" <?= $formValue('audience') === 'Gaming-Hardware' ? 'selected' : ''; ?>>Konsole oder Controller</option>
                        <option value="Sonstiges IT-Anliegen" <?= $formValue('audience') === 'Sonstiges IT-Anliegen' ? 'selected' : ''; ?>>Etwas anderes</option>
                    </select>
                    <?php if ($formHasError('audience')): ?><small class="form-field-error" id="contact-error-audience">Bitte wählen Sie ein Anliegen.</small><?php endif; ?>
                </label>
            </div>
            <label>
                <span>Passender Bereich</span>
                <select name="service" <?= $formErrorAttributes('service'); ?> required>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($serviceBands as $band): ?>
                        <?php $optionGroups = is_array($band['groups'] ?? null) ? $band['groups'] : []; ?>
                        <option value="<?= e($band['title']); ?>" data-service-groups="<?= e(implode(' ', array_unique($optionGroups))); ?>" <?= $formValue('service') === $band['title'] ? 'selected' : ''; ?>><?= e($band['title']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($formHasError('service')): ?><small class="form-field-error" id="contact-error-service">Bitte wählen Sie einen Bereich.</small><?php endif; ?>
            </label>
            <label>
                <span>Was ist passiert?</span>
                <textarea name="message" rows="6" placeholder="Zum Beispiel: Mein Laptop startet seit heute nicht mehr …" <?= $formErrorAttributes('message'); ?> required><?= e($formValue('message')); ?></textarea>
                <?php if ($formHasError('message')): ?><small class="form-field-error" id="contact-error-message">Bitte beschreiben Sie Ihr Anliegen etwas genauer.</small><?php endif; ?>
            </label>
            <?php if ($contactForm['captchaEnabled']): ?>
                <div class="form-row form-row-captcha">
                    <label>
                        <span><?= e($contactForm['captchaLabel']); ?></span>
                        <input type="text" name="captcha_answer" inputmode="numeric" autocomplete="off" aria-describedby="contact-captcha-question<?= $formHasError('captcha') ? ' contact-error-captcha' : ''; ?>" <?= $formHasError('captcha') ? 'aria-invalid="true"' : ''; ?> required>
                        <?php if ($formHasError('captcha')): ?><small class="form-field-error" id="contact-error-captcha">Bitte lösen Sie die Sicherheitsfrage erneut.</small><?php endif; ?>
                    </label>
                    <div class="captcha-question" id="contact-captcha-question">
                        <span><?= e($contactForm['captchaQuestion']); ?></span>
                    </div>
                </div>
            <?php endif; ?>
            <label class="consent-check">
                <input type="checkbox" name="privacy_confirmation" value="1" <?= $formValue('privacyConfirmation') === '1' ? 'checked' : ''; ?> <?= $formErrorAttributes('privacyConfirmation'); ?> required>
                <span>Ich bestätige, dass meine Angaben zur Bearbeitung meiner Anfrage gemäß der <a href="<?= e(page_url('datenschutz.php')); ?>">Datenschutzerklärung</a> verarbeitet werden dürfen.</span>
                <?php if ($formHasError('privacyConfirmation')): ?><small class="form-field-error" id="contact-error-privacyConfirmation">Bitte bestätigen Sie die Datenschutzerklärung.</small><?php endif; ?>
            </label>
            <p class="form-note">Ihre Angaben werden nur zur Bearbeitung der Anfrage verwendet. Weitere Details stehen in der <a href="<?= e(page_url('datenschutz.php')); ?>">Datenschutzerklärung</a>.</p>
            <button class="button button-primary" type="submit">Anfrage an mich senden <span aria-hidden="true">↗</span></button>
        </form>
    </div>
</section>
