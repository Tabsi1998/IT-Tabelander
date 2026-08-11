<?php
declare(strict_types=1);
?>
<footer class="site-footer">
    <div class="footer-brand">
        <p class="brand-name">IT-Tabelander</p>
        <p>Persönliche Computerhilfe für private Haushalte in Telfs und Umgebung.</p>
    </div>
    <nav class="footer-service-links" aria-label="Services">
        <a href="<?= e(page_url('pc-reparatur-telfs')); ?>">PC- & Laptop-Reparatur</a>
        <a href="<?= e(page_url('wlan-netzwerk-telfs')); ?>">WLAN zuhause</a>
        <a href="<?= e(page_url()); ?>#kontakt">Problem schildern</a>
    </nav>
    <nav class="footer-links" aria-label="Rechtliches">
        <a href="<?= e(page_url('impressum.php')); ?>">Impressum</a>
        <a href="<?= e(page_url('datenschutz.php')); ?>">Datenschutz</a>
        <a href="<?= e(page_url('nutzungsbedingungen.php')); ?>">Nutzungsbedingungen</a>
    </nav>
    <p class="footer-note">© <?= e(date('Y')); ?> <?= e((string) $company['name']); ?> · <?= e((string) $company['city']); ?>, <?= e((string) $company['country']); ?></p>
</footer>
