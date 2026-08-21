# Produktiv-Deployment auf `it.tabelander.co.at`

Der Apache-404 für `/controller-service-telfs` entsteht vor PHP. Im Repository existieren sowohl `controller-service-telfs.php` als auch die Rewrite-Regel. Ein 404 auf dem Server bedeutet deshalb: Der aktuelle Stand liegt noch nicht im ausgelieferten DocumentRoot oder Apache wertet die Root-`.htaccess` nicht aus.

## Server prüfen

```bash
apachectl -S
grep -R "DocumentRoot\|AllowOverride" /etc/apache2/sites-enabled /etc/apache2/apache2.conf
```

Der VirtualHost für `it.tabelander.co.at` sollte auf `/var/www/it-tabelander` zeigen. Wenn absichtlich `/var/www/html` ausgeliefert wird, muss stattdessen der vollständige Release inklusive `.htaccess` dorthin deployt werden. Nicht nur einzelne PHP-Dateien kopieren.

Beispiel für den relevanten Apache-Teil:

```apache
DocumentRoot /var/www/it-tabelander

<Directory /var/www/it-tabelander>
    Options -Indexes
    AllowOverride FileInfo Options
    Require all granted
</Directory>
```

Danach Module und Konfiguration prüfen:

```bash
a2enmod rewrite headers setenvif
apachectl configtest
systemctl reload apache2
```

## Release und Routen testen

Nach Merge bzw. Pull des freigegebenen Branches im korrekten Verzeichnis:

```bash
test -f /var/www/it-tabelander/controller-service-telfs.php
test -f /var/www/it-tabelander/.htaccess
curl -I https://it.tabelander.co.at/controller-service-telfs
curl -I https://it.tabelander.co.at/controller-service-telfs.php
```

Die saubere URL muss `200` liefern. Die `.php`-URL soll mit `301` auf `/controller-service-telfs` umleiten.

## Dolibarr auf demselben Server

Dass Dolibarr unter `/var/www/dolibarr` auf demselben Server liegt, ändert die Integration nicht: Die Website ruft weiterhin `https://erp.tabelander.co.at/api/index.php` über HTTPS auf. In Dolibarr das REST-API-Modul aktivieren und für einen eigenen API-Benutzer nur die benötigten Rechte vergeben. Der Schlüssel bleibt in `/var/www/it-tabelander-secrets/dolibarr-api-key.txt`, außerhalb beider Webroots.

Erforderliche Website-Umgebung:

```text
DOLIBARR_ENABLED=true
DOLIBARR_BASE_URL=https://erp.tabelander.co.at
DOLIBARR_API_KEY_FILE=/var/www/it-tabelander-secrets/dolibarr-api-key.txt
DOLIBARR_ENTITY=0
DOLIBARR_VAT_RATE=0
DOLIBARR_COUNTRY_CODE=AT
```

`DOLIBARR_VAT_RATE=0` ist hier nur passend, wenn die gezeigte 0-%-Konfiguration steuerlich tatsächlich korrekt ist. Sonst den richtigen Satz setzen. Der API-Benutzer benötigt Rechte für Geschäftspartner/Interessenten, Tickets und Angebote. Zum Abschluss je eine normale Anfrage und eine Controller-Konfiguration testen: Erstere muss als Ticket, letztere als Angebotsentwurf erscheinen.
