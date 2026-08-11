# IT-Tabelander Website

Wartbare One-Page-Website auf Basis von PHP, HTML, CSS und etwas JavaScript. Die Seite ist für Apache mit PHP gedacht und braucht keinen Build-Schritt.

## Struktur

- `index.php` im Projekt-Root ist die öffentliche Startseite.
- `public/assets/` enthält CSS, JavaScript, Schriften und Bilder.
- `private/site-config.php` bündelt Inhalte, Kontaktdaten, SMTP- und Review-Konfiguration.
- `private/site-services.php` enthält Formular-, Mail- und Review-Helfer.
- `private/pages/` enthält die internen Seitentemplates.
- `private/actions/` enthält Formular- und JSON-Endpunkte.
- `private/cache/` speichert den serverseitigen Google-Review-Cache.
- `private/logs/` enthält kurzlebige, datensparsame Versandstatus-Logs und wird nie versioniert.

## Lokale Vorschau

```powershell
php -S localhost:8000
```

Danach im Browser `http://localhost:8000` öffnen.

## Tests

Die Tests benötigen PHP 8.2 oder neuer und kommen ohne Composer-Abhängigkeiten aus:

```powershell
php tests/run.php
```

Die Suite prüft Kontaktvalidierung, zentrale URL-/Telefon-Helfer, sichere SMTP-Konfiguration, den Ausschluss privater Routen sowie das Rendering der Startseite, Landingpages und Sitemap. Ein Syntaxcheck aller PHP-Dateien kann zusätzlich mit `php -l <datei>` ausgeführt werden und läuft in der CI automatisch über alle versionierten PHP-Dateien.

## Apache-Empfehlung

Die Seite kann jetzt direkt mit dem Projekt-Root als `DocumentRoot` betrieben werden, weil `index.php` im Root liegt. Wichtig ist dabei, dass die mitgelieferte Root-`.htaccess` aktiv ist, damit `private/` nicht öffentlich erreichbar ist. Zusätzlich liegt in `private/.htaccess` noch einmal eine direkte Zugriffssperre als zweite Schutzschicht.

PHP setzt eine restriktive Content-Security-Policy mit einem zufälligen Nonce je Anfrage und ohne `unsafe-eval` oder `unsafe-inline`. Erlaubt sind lokale Assets sowie die erst nach Einwilligung geladenen Google-Analytics-Skript- und Messendpunkte. Die Nonce wird nur für das zentrale Head-Skript und das JSON-LD verwendet. HSTS wird durch Apache nur gesetzt, wenn eine HTTPS-Anfrage für `it.tabelander.co.at` verarbeitet wird; lokale HTTP-Entwicklung erhält den Header nicht. Wird die Produktionsdomain geändert, muss die Bedingung `IT_TABELANDER_PRODUCTION_HTTPS` entsprechend angepasst und erst nach geprüftem HTTPS-Rollout aktiviert werden.

## Seiten und Sitemap

Öffentliche Seiten werden zentral in `private/page-registry.php` registriert. Dort stehen Pfad, Meta-Daten, Indexierbarkeit, Sitemap-Priorität und die zugehörige Quelldatei. Eine neue öffentliche Seite wird in dieser Registry ergänzt und verwendet im Template `page_meta()` sowie `private/partials/head.php`.

Die Route `/sitemap.xml` wird durch Apache auf `sitemap.php` abgebildet. Sie enthält ausschließlich als `indexable` markierte Registry-Einträge, deren Quelldatei tatsächlich existiert. `lastmod` basiert auf dem Änderungszeitpunkt der jeweiligen Quelldatei; Kontakt-Endpunkte und private Pfade können dadurch nicht versehentlich aufgenommen werden.

## Vor Livegang anpassen

1. `private/site-config.php` mit echten Unternehmensdaten füllen.
2. Telefonnummer, E-Mail, Anschrift, Aufsichtsbehörde, Kammer und Berufsbezeichnung vervollständigen.
3. Google Place ID, Google API Key und SMTP-Zugangsdaten ausschließlich als Umgebungsvariablen beziehungsweise Secret-Dateien bereitstellen.
4. Rechtstexte mit den realen technischen Abläufen und gegebenenfalls juristisch prüfen lassen.

## Kontaktformular

Das Formular versendet Mails per SMTP und ist auf zwei Nachrichten vorbereitet:

- Eigentümer-Benachrichtigung an `office@tabelander.co.at` beziehungsweise an die konfigurierte Empfängeradresse
- automatische Eingangsbestätigung an den Absender

Das Anwendungslog enthält ausschließlich Zeitstempel, zufällige Request-ID und Versandstatus. Name, E-Mail-Adresse, Telefonnummer, Nachricht und IP-Adresse werden dort nicht gespeichert. SMTP-Diagnosen enthalten keine Empfänger, Betreffzeilen, Zugangsdaten oder vollständigen Serverantworten.

`RUNTIME_LOG_RETENTION_DAYS` steuert die Aufbewahrung (Standard: `30`). Abgelaufene JSONL-Einträge werden bei jedem neuen Logeintrag entfernt. Der Wert `0` deaktiviert persistente Anwendungslogs und leert vorhandene Runtime-Logs beim nächsten Schreibversuch.

SMTP ist in einem frischen Checkout deaktiviert. Für die lokale Entwicklung kann das Formular damit gefahrlos bis zur kontrollierten Fehlermeldung getestet werden, ohne einen Mailserver anzusprechen. Für echten Versand müssen `SMTP_ENABLED=true` und alle Pflichtwerte gesetzt sein:

- `CONTACT_RECIPIENT` überschreibt die Empfängeradresse aus `site-config.php`.
- `SMTP_ENABLED` aktiviert den Versand ausdrücklich (`false` als sicherer Standard).
- `SMTP_HOST`
- `SMTP_PORT`
- `SMTP_ENCRYPTION`
- `SMTP_USERNAME`
- `SMTP_PASSWORD`
- `SMTP_ALLOW_SELF_SIGNED`
- `SMTP_VERIFY_PEER`
- `SMTP_VERIFY_PEER_NAME`
- `SMTP_EHLO_DOMAIN`

Als Vorlage ohne echte Zugangsdaten dient `.env.example`; PHP lädt diese Datei nicht automatisch. Die Werte müssen durch Apache/PHP-FPM, den Prozessmanager oder die Deployment-Umgebung gesetzt werden.

Wichtige Hinweise zu SMTP-Passwörtern und TLS:

- Ein SMTP-Server akzeptiert bei Standard-Authentifizierung kein gehashtes Passwort, sondern das echte Passwort oder ein separates App-Passwort.
- Das Passwort sollte daher nicht im Klartext in `site-config.php` hinterlegt werden.
- Empfohlen ist `SMTP_PASSWORD` als Umgebungsvariable oder `SMTP_PASSWORD_FILE` mit einem Dateipfad außerhalb des Webroots.
- Alternativ zu `SMTP_PASSWORD_FILE` wird eine Datei `it-tabelander-secrets/smtp-password.txt` als Geschwisterpfad des Projektordners unterstützt.
- Die Passwortdatei sollte nur das SMTP-Passwort enthalten und nicht im Git-Repository liegen. Sinnvolle Rechte sind z.B. `chmod 640 smtp-password.txt` und ein Owner bzw. eine Gruppe, die der Webserver lesen darf.
- Der Versand erfolgt verschlüsselt über TLS oder SSL, wenn dies im Mailserver so konfiguriert ist.
- Sichere Standards sind Zertifikatsprüfung und Hostnamenprüfung aktiviert sowie selbstsignierte Zertifikate abgelehnt. Eine unsichere lokale Testumgebung muss Abweichungen ausdrücklich über die drei `SMTP_*`-Schalter setzen und darf diese Werte nicht in Produktion übernehmen.
- Fehlen Pflichtwerte, bleibt SMTP deaktiviert. Das Formular liefert kontrolliert einen Fehler mit Request-ID; die Diagnose protokolliert nur gesetzte/fehlende Felder und niemals das Passwort.

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

## Bewertungen

Der Bewertungsbereich ist auf serverseitigen Abruf vorbereitet. Dadurch bleibt der API-Key außerhalb des Browsers. Die Werte können direkt in `private/site-config.php` eingetragen oder per Umgebungsvariable gesetzt werden.

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

## AGB / Nutzungsbedingungen

Die Website enthält Nutzungsbedingungen für den Webauftritt. Wenn Sie regelmäßig standardisierte B2B- oder Serviceverträge abschließen, sollten zusätzlich eigene AGB verwendet und vor Vertragsabschluss wirksam einbezogen werden.
