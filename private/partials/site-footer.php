<?php
declare(strict_types=1);
?>
<footer class="site-footer">
    <div class="footer-brand">
        <p class="brand-name"><?= e((string) $company['name']); ?></p>
        <p>IT-Dienstleistungen für Reparatur, Systeme und Infrastruktur in Telfs und Tirol.</p>
    </div>
    <nav class="footer-links" aria-label="Rechtliches">
        <a href="<?= e(page_url('impressum.php')); ?>">Impressum</a>
        <a href="<?= e(page_url('datenschutz.php')); ?>">Datenschutz</a>
        <a href="<?= e(page_url('nutzungsbedingungen.php')); ?>">Nutzungsbedingungen</a>
    </nav>
    <p class="footer-note"><?= e((string) $company['name']); ?>, <?= e((string) $company['city']); ?>, <?= e((string) $company['country']); ?></p>
</footer>
