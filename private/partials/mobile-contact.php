<?php
declare(strict_types=1);

$contactHref = (string) ($contactHref ?? page_url() . '#kontakt');
?>
<nav class="mobile-action-bar" aria-label="Schnellkontakt">
    <a href="tel:<?= e(phone_href((string) $company['phone'])); ?>">Anrufen</a>
    <a href="<?= e($contactHref); ?>">Anfrage senden</a>
</nav>
