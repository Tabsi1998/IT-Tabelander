# IT-Tabelander Website

[![CI](https://github.com/Tabsi1998/IT-Tabelander/actions/workflows/ci.yml/badge.svg)](https://github.com/Tabsi1998/IT-Tabelander/actions/workflows/ci.yml)

Wartbare, auf private Haushalte ausgerichtete IT-Service-Website mit lokaler SEO-Landingpage-Struktur auf Basis von PHP, HTML, modularisiertem CSS und JavaScript. Die Seite ist für Apache mit PHP gedacht und braucht weder Composer noch einen Frontend-Build.

## Voraussetzungen

- PHP 8.2 bis 8.5; die CI prüft 8.2, 8.4 und 8.5.
- Apache 2.4 mit `mod_rewrite`, `mod_headers` und `mod_setenvif`.
- Für `.htaccess` muss mindestens `AllowOverride FileInfo Options` aktiv sein; der Webserver muss den Projekt-Root als `DocumentRoot` verwenden.
- PHP-Standarderweiterungen für Sessions, Filter, JSON und OpenSSL. `mbstring` verbessert die Unicode-Längenprüfung, ist aber nicht zwingend.
- Ausgehende HTTPS-Verbindungen und `allow_url_fopen`, wenn Google Places serverseitig abgerufen werden soll.

## Struktur

- `index.php` im Projekt-Root ist die öffentliche Startseite.
- `public/assets/css/` ist in Basis, Komponenten, Recht/Theme, Responsive und Landingpages gegliedert; `styles.css` bindet diese Schichten in stabiler Reihenfolge ein.
- `public/assets/js/main.js` lädt DOM-abhängig ES-Module für Theme, Navigation, Animationen, Carousels, Kontakt, Consent und Analytics.
- `private/site-config.php` bündelt Inhalte, Kontaktdaten, SMTP- und Review-Konfiguration.
- `private/site-services.php` ist der kompatible Loader für die Fachmodule in `private/services/`: Kontakt, Runtime-Logging, Mail/SMTP und Reviews.
- `private/page-registry.php` ist die zentrale Quelle für Metadaten, Canonicals, Schema und Sitemap-Einträge.
- `private/landing-pages.php` enthält die individuellen Inhalte der lokalen Landingpages.
- `private/controller-config.php` enthält die erweiterbare Modell-, Upgrade- und Zusatzoptionen-Liste für den PS5-Controller-Konfigurator.
- `private/pages/` enthält Seitentemplates; `private/partials/` enthält Head, Header, Footer und Kontakt-CTA-Komponenten.
- `private/actions/` enthält Formular- und JSON-Endpunkte.
- `private/cache/` speichert den serverseitigen Google-Review-Cache.
- `private/logs/` enthält kurzlebige, datensparsame Versandstatus-Logs und wird nie versioniert.

## Lokale Vorschau

Ein frischer Checkout benötigt keine Secrets. SMTP ist standardmäßig deaktiviert, Google Places bleibt ohne Zugangsdaten aus und Analytics lädt ohne Browser-Einwilligung nicht.

```powershell
php -S localhost:8000
```

Danach im Browser `http://localhost:8000` öffnen. Der PHP-Entwicklungsserver wertet `.htaccess` nicht aus und ist deshalb ausschließlich für lokale Entwicklung gedacht.

## Tests

Die Tests kommen ohne Composer-Abhängigkeiten aus:

```powershell
php tests/run.php
```

Die Suite prüft Kontaktvalidierung, zentrale URL-/Telefon-Helfer, sichere SMTP-Konfiguration, den Ausschluss privater Routen sowie das Rendering der Startseite, Landingpages und Sitemap. Die CI ergänzt PHP-Lint, JavaScript-Syntax, JSON/XML-Validierung, Runtime-Datei-Schutz und das 500-KB-Bildbudget.

```powershell
php tests/run.php
git ls-files '*.php' | ForEach-Object { php -l $_ }
node --check public/assets/js/main.js
```

## Apache-Empfehlung

Die Seite kann jetzt direkt mit dem Projekt-Root als `DocumentRoot` betrieben werden, weil `index.php` im Root liegt. Wichtig ist dabei, dass die mitgelieferte Root-`.htaccess` aktiv ist, damit `private/` nicht öffentlich erreichbar ist. Zusätzlich liegt in `private/.htaccess` noch einmal eine direkte Zugriffssperre als zweite Schutzschicht.

PHP setzt eine restriktive Content-Security-Policy mit einem zufälligen Nonce je Anfrage und ohne `unsafe-eval` oder `unsafe-inline`. Erlaubt sind lokale Assets sowie die erst nach Einwilligung geladenen Google-Analytics-Skript- und Messendpunkte. Die Nonce wird nur für das zentrale Head-Skript und das JSON-LD verwendet. HSTS wird durch Apache nur gesetzt, wenn eine HTTPS-Anfrage für `it.tabelander.co.at` verarbeitet wird; lokale HTTP-Entwicklung erhält den Header nicht. Wird die Produktionsdomain geändert, muss die Bedingung `IT_TABELANDER_PRODUCTION_HTTPS` entsprechend angepasst und erst nach geprüftem HTTPS-Rollout aktiviert werden.

## Seiten und Sitemap

Öffentliche Seiten werden zentral in `private/page-registry.php` registriert. Dort stehen Pfad, Meta-Daten, Indexierbarkeit, Sitemap-Priorität und die zugehörige Quelldatei. Für eine neue Landingpage:

1. Inhalte in `private/landing-pages.php` ergänzen.
2. Meta-, Schema- und Sitemap-Werte in `private/page-registry.php` ergänzen.
3. Einen kleinen öffentlichen Entrypoint im Projekt-Root anlegen, der `private/pages/service-landing.php` mit dem passenden Key lädt.
4. Sprechende URL und `.php`-Redirect in `.htaccess` ergänzen.
5. Die Seite intern verlinken und `php tests/run.php` ausführen.

Die Route `/sitemap.xml` wird durch Apache auf `sitemap.php` abgebildet. Sie enthält ausschließlich als `indexable` markierte Registry-Einträge, deren Quelldatei tatsächlich existiert. `lastmod` basiert auf dem Änderungszeitpunkt der jeweiligen Quelldatei; Kontakt-Endpunkte und private Pfade können dadurch nicht versehentlich aufgenommen werden.

## Environment-Variablen

`.env.example` dokumentiert sichere Beispielwerte, wird von PHP aber absichtlich nicht automatisch geladen. Konfiguration erfolgt über Apache/PHP-FPM, den Prozessmanager, Container-Secrets oder Secret-Dateien.

- Allgemein: `CANONICAL_BASE_URL`, `CONTACT_RECIPIENT`, `RUNTIME_LOG_RETENTION_DAYS`.
- SMTP: `SMTP_ENABLED`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_PASSWORD_FILE`, `SMTP_ALLOW_SELF_SIGNED`, `SMTP_VERIFY_PEER`, `SMTP_VERIFY_PEER_NAME`, `SMTP_EHLO_DOMAIN`.
- Analytics: `GOOGLE_ANALYTICS_MEASUREMENT_ID`.
- Bewertungen: `GOOGLE_PLACE_ID`, `GOOGLE_PLACES_API_KEY`, `GOOGLE_PLACES_API_KEY_FILE`.
- Dolibarr: `DOLIBARR_ENABLED`, `DOLIBARR_BASE_URL`, `DOLIBARR_API_KEY_FILE`, `DOLIBARR_ENTITY`, `DOLIBARR_COUNTRY_CODE`, `DOLIBARR_TIMEOUT_SECONDS`.

Leere Pflichtwerte deaktivieren die jeweilige externe Integration kontrolliert. Secret-Dateien müssen außerhalb des Webroots liegen und nur für den Webserver-Benutzer lesbar sein.

## Dolibarr-Anfragen und Angebotsentwürfe

Der PS5-Controller-Konfigurator ist öffentlich unter `/controller-service-telfs` erreichbar und direkt in der Hauptnavigation verlinkt. Allgemeine Kontaktanfragen werden ohne Website-Mail als Interessent plus internes Dolibarr-Ticket angelegt. Der Controller-Konfigurator besitzt ein eigenes Datenformular und erzeugt getrennt davon einen Interessenten plus Angebotsentwurf. Controller-Beschaffung, Gehäuse und jedes gewählte Pauschalpaket werden als eigene Positionen übernommen. Angebote werden bewusst weder automatisch validiert noch von der Website versendet; der spätere Versand erfolgt nach Prüfung in Dolibarr.

Die Integration sucht zuerst anhand der E-Mail-Adresse nach einem vorhandenen Dolibarr-Kontakt. Wird keiner gefunden, legt sie einen Privatkontakt als Interessent an. Vorhandene Kunden werden nicht zurückgestuft. Controller-Auswahl und Preise werden erneut aus dem serverseitigen Katalog aufgebaut; Browserwerte werden nicht als Angebotspreise übernommen. Neue Interessenten erhalten `tva_assuj=0`, und alle Controller-Angebotspositionen werden serverseitig unveränderbar mit `tva_tx=0.0` angelegt.

Für die beschriebene Serverstruktur wird als Basisadresse `https://erp.tabelander.co.at` verwendet. Der REST-Endpunkt `/api/index.php` wird automatisch ergänzt. Die Datei `/var/www/it-tabelander-secrets/dolibarr-api-key.txt` enthält ausschließlich den API-Schlüssel eines eigenen Dolibarr-Benutzers mit möglichst kleinen Rechten zum Lesen/Anlegen von Geschäftspartnern und Interessenten, Lesen/Anlegen von Tickets sowie Lesen/Anlegen von Angeboten. Beispielwerte:

```text
DOLIBARR_ENABLED=true
DOLIBARR_BASE_URL=https://erp.tabelander.co.at
DOLIBARR_API_KEY_FILE=/var/www/it-tabelander-secrets/dolibarr-api-key.txt
DOLIBARR_ENTITY=0
DOLIBARR_COUNTRY_CODE=AT
DOLIBARR_TIMEOUT_SECONDS=8
```

Die 0-%-USt-Regel ist wegen der bestätigten Kleinunternehmerregelung absichtlich fest im serverseitigen Code verankert und kann nicht durch eine fehlerhafte Environment-Variable überschrieben werden. Falls sich der steuerliche Status später ändert, müssen Dolibarr, Website-Code, Angebots-/Rechnungstexte und öffentliche Preise gemeinsam angepasst werden. Ohne API-Schlüssel bleibt die Anbindung kontrolliert deaktiviert. Das datensparsame `private/logs/dolibarr.log` enthält nur technische Statuswerte und Dolibarr-IDs, keine Kontaktdaten oder API-Antworten.

Die empfohlene Produkt-/Leistungsstruktur und alle Controller-Referenzen stehen in [`docs/DOLIBARR-KATALOG.md`](docs/DOLIBARR-KATALOG.md).

## Deployment-Checkliste

1. Zielbranch und grünen CI-Status prüfen; `php tests/run.php` zusätzlich im Deployment-Artefakt ausführen.
2. PHP-Version, benötigte Erweiterungen sowie Apache-Module prüfen und `.htaccess` tatsächlich aktivieren.
3. Projekt-Root als `DocumentRoot` konfigurieren; Zugriffe auf `/private`, `README.md`, Runtime-Verzeichnisse und Secret-Dateien mit HTTP-Requests verifizieren.
4. `CANONICAL_BASE_URL`, Unternehmensdaten und echte Produktionsdomain kontrollieren; bei Domainänderung HSTS-Hostbedingung in `.htaccess` anpassen.
5. SMTP- und Google-Secrets ausschließlich über Environment oder geschützte Dateien bereitstellen; niemals in `site-config.php` schreiben.
6. Schreibrechte nur für `private/logs/` und `private/cache/` gewähren. Die Verzeichnisse werden bei Bedarf automatisch angelegt; der übrige Code sollte für den Webserver schreibgeschützt sein.
7. HTTPS und Redirect auf HTTPS vollständig testen, bevor HSTS für eine neue Domain aktiviert wird. CSP, Kontaktformular, Consent und Analytics im Browser ohne Policy-Verstöße prüfen.
8. Startseite, zwei aktive Privatkunden-Landingpages, Rechtstexte, `/sitemap.xml`, `robots.txt` und Kontaktstatus als Smoke-Test aufrufen. Die frühere Firmen-Landingpage ist bewusst nicht indexierbar und liefert 404.
   Zusätzlich den Controller-Konfigurator mit DualSense und DualSense Edge, Vorder-/Rückansicht, Beschaffungsarten, Shell-Farben und direkter Dolibarr-Angebotserstellung prüfen.
9. Rechtstexte mit den realen technischen Abläufen abgleichen und bei Bedarf juristisch prüfen lassen.

Wenn `/controller-service-telfs` am Server ein Apache-404 statt der Website liefert, zeigt der VirtualHost sehr wahrscheinlich noch auf `/var/www/html` oder verwendet die Projekt-`.htaccess` nicht. Die konkrete Prüfung und Korrektur steht in [`docs/PRODUKTIV-DEPLOYMENT.md`](docs/PRODUKTIV-DEPLOYMENT.md).

## Niemals committen

- Dateien unter `private/logs/` und `private/cache/`.
- `.env`-Dateien mit echten Werten.
- SMTP-Passwörter, API-Keys, Tokens, Zertifikatsschlüssel oder Secret-Dateien.
- Exporte echter Kontaktanfragen, Server-Dumps oder produktionsbezogene Diagnoseausgaben.

Vor einem Commit helfen `git status`, `git diff --cached` und `git check-ignore -v <datei>`. Wurde ein Secret oder personenbezogener Inhalt bereits veröffentlicht, reicht Löschen im aktuellen Commit nicht: Git-Historie bereinigen und den betroffenen Wert beziehungsweise die Datenquelle rotieren.

## Kontakt- und Controller-Formulare

Öffentliche Formulare versenden keine Website-E-Mails. Das allgemeine Startseitenformular erzeugt in Dolibarr einen Interessenten und ein Ticket. Das davon getrennte Controller-Formular erzeugt einen Interessenten und einen unverbindlichen Angebotsentwurf. Rückmeldungen und Angebotsversand erfolgen anschließend direkt aus Dolibarr.

Das vorhandene SMTP-Modul bleibt als nicht aufgerufene Infrastruktur im Projekt, ist standardmäßig deaktiviert und darf ohne eine spätere bewusste Prozessänderung nicht an die beiden Formulare angeschlossen werden. `RUNTIME_LOG_RETENTION_DAYS` steuert die Aufbewahrung technischer Dolibarr-Statuslogs (Standard: `30`); der Wert `0` deaktiviert persistente Logs.

Zusätzliche Formular-Schutzmechanismen:

- Honeypot-Feld gegen einfache Bots
- Mindestzeit bis zum Absenden
- Datenschutz-Checkbox
- integrierte Sicherheitsfrage als CAPTCHA

## Cookies und Einwilligung

Die Website kann Google Analytics 4 verwenden. Das Analytics-Skript wird erst nach aktiver Zustimmung im Cookie-Hinweis geladen. Ohne Zustimmung werden keine Analytics-Aufrufe an Google ausgelöst.

Verwendet werden außerdem:

- technisch notwendige Sitzungs-Cookies für Formularschutz und Spam-Abwehr
- eine lokale Speicherung der Theme-Auswahl erst nach aktiver Benutzeraktion
- eine lokale Speicherung der Analytics-Zustimmung oder Ablehnung für 30 Tage

Die Google-Analytics-Mess-ID steht in `private/site-config.php` unter `analytics.googleMeasurementId` und kann alternativ per Umgebungsvariable gesetzt werden:

- `GOOGLE_ANALYTICS_MEASUREMENT_ID`

Die Auswahl kann in der Datenschutzerklärung über „Cookie-Einstellungen ändern“ zurückgesetzt werden. Wenn später Google Maps, Meta Pixel, YouTube-Einbettungen oder andere nicht technisch notwendige Dienste ergänzt werden, sollte die Consent-Verwaltung entsprechend erweitert werden.

Nach Analytics-Einwilligung werden vier zentral definierte Conversion-Events gesendet: `contact_form_success`, `phone_click`, `email_click` und `primary_cta_click`. Zulässige Parameter sind ausschließlich `page_type`, `location` und bei erfolgreichem Formularversand `contact_status`. Formularfelder, Namen, E-Mail-Adressen, Telefonnummern, Nachrichten und Linkziele werden nicht übertragen. Ohne aktive Einwilligung gibt `trackAnalyticsEvent()` sofort zurück und es wird kein Event an Google gesendet.

## Bewertungen

Der Bewertungsbereich ist auf serverseitigen Abruf vorbereitet. Dadurch bleibt der API-Key außerhalb des Browsers. Produktionswerte werden per Environment beziehungsweise Secret-Datei gesetzt.

Benötigte Umgebungsvariablen:

- `GOOGLE_PLACE_ID`
- `GOOGLE_PLACES_API_KEY`
- `GOOGLE_PLACES_API_KEY_FILE`

Die Reviews werden in `private/cache/google-reviews.json` zwischengespeichert.

Wenn keine Google-Anbindung gewünscht ist, können manuelle Rezensionen in `private/data/reviews.json` gepflegt werden. Wenn die Datei keine Einträge enthält, zeigt die Website „Noch keine Kundenrezensionen veröffentlicht.“.

Beispiel:

```json
{
  "reviews": [
    {
      "author": "Max Mustermann",
      "rating": "5",
      "text": "Sehr schnelle und saubere Hilfe beim Laptop. Vor der Reparatur war klar, welche Kosten entstehen.",
      "date": "Juni 2026"
    }
  ]
}
```

Benötigt werden nur `author` und `text`. `rating`, `date` und `url` sind optional.

Eine kurze Schritt-für-Schritt-Anleitung für Bewertungen und die bewusst zunächst deaktivierten, hochzählenden Kennzahlen steht in [`docs/INHALTE-PFLEGEN.md`](docs/INHALTE-PFLEGEN.md). Mit `php private/tools/content-check.php` lassen sich beide JSON-Dateien vor dem Veröffentlichen prüfen.

Die Referenzanalyse, Gestaltungsentscheidungen und empfohlenen nächsten echten Inhalte sind in [`docs/REDESIGN-KONZEPT.md`](docs/REDESIGN-KONZEPT.md) dokumentiert.

## AGB / Nutzungsbedingungen

Die Website enthält Nutzungsbedingungen für den Webauftritt. Wenn Sie regelmäßig standardisierte B2B- oder Serviceverträge abschließen, sollten zusätzlich eigene AGB verwendet und vor Vertragsabschluss wirksam einbezogen werden.
