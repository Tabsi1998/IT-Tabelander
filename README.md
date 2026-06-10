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
- `private/logs/` speichert Formularanfragen zusätzlich lokal als Fallback-Log.

## Lokale Vorschau

```powershell
php -S localhost:8000
```

Danach im Browser `http://localhost:8000` öffnen.

## Apache-Empfehlung

Die Seite kann jetzt direkt mit dem Projekt-Root als `DocumentRoot` betrieben werden, weil `index.php` im Root liegt. Wichtig ist dabei, dass die mitgelieferte Root-`.htaccess` aktiv ist, damit `private/` nicht öffentlich erreichbar ist. Zusätzlich liegt in `private/.htaccess` noch einmal eine direkte Zugriffssperre als zweite Schutzschicht.

## Vor Livegang anpassen

1. `private/site-config.php` mit echten Unternehmensdaten füllen.
2. Telefonnummer, E-Mail, Anschrift, Aufsichtsbehörde, Kammer und Berufsbezeichnung vervollständigen.
3. Google Place ID, Google API Key und SMTP-Zugangsdaten in `private/site-config.php` oder als Umgebungsvariablen eintragen.
4. Rechtstexte mit den realen technischen Abläufen und gegebenenfalls juristisch prüfen lassen.

## Kontaktformular

Das Formular versendet Mails per SMTP und ist auf zwei Nachrichten vorbereitet:

- Eigentümer-Benachrichtigung an `office@tabelander.co.at` beziehungsweise an die konfigurierte Empfängeradresse
- automatische Eingangsbestätigung an den Absender

Zusätzlich wird jede Anfrage in `private/logs/contact-submissions.log` protokolliert. SMTP-Fehler werden in `private/logs/mail.log` erfasst.

Wichtige Konfigurationswerte in `private/site-config.php`:

- `mail.recipient`
- `mail.fromEmail`
- `mail.replyToEmail`
- `mail.smtp.host`
- `mail.smtp.port`
- `mail.smtp.encryption`
- `mail.smtp.username`
- `mail.smtp.password`

Alternativ können diese Umgebungsvariablen gesetzt werden:

- `CONTACT_RECIPIENT` überschreibt die Empfängeradresse aus `site-config.php`.
- `SMTP_HOST`
- `SMTP_PORT`
- `SMTP_ENCRYPTION`
- `SMTP_USERNAME`
- `SMTP_PASSWORD`
- `SMTP_EHLO_DOMAIN`

Wichtiger Hinweis zu SMTP-Passwörtern:

- Ein SMTP-Server akzeptiert bei Standard-Authentifizierung kein gehashtes Passwort, sondern das echte Passwort oder ein separates App-Passwort.
- Das Passwort sollte daher nicht im Klartext in `site-config.php` hinterlegt werden.
- Empfohlen ist `SMTP_PASSWORD` als Umgebungsvariable oder `SMTP_PASSWORD_FILE` mit einem Dateipfad außerhalb des Webroots.
- Der Versand erfolgt verschlüsselt über TLS oder SSL, wenn dies im Mailserver so konfiguriert ist.

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

## Google-Bewertungen

Der Bewertungsbereich ist auf serverseitigen Abruf vorbereitet. Dadurch bleibt der API-Key außerhalb des Browsers. Die Werte können direkt in `private/site-config.php` eingetragen oder per Umgebungsvariable gesetzt werden.

Benötigte Umgebungsvariablen:

- `GOOGLE_PLACE_ID`
- `GOOGLE_PLACES_API_KEY`
- `GOOGLE_PLACES_API_KEY_FILE`

Die Reviews werden in `private/cache/google-reviews.json` zwischengespeichert.

Wenn keine Google-Anbindung gewünscht ist, können in `private/site-config.php` unter `manualTestimonials` manuelle Referenzen eingetragen werden.

## AGB / Nutzungsbedingungen

Die Website enthält Nutzungsbedingungen für den Webauftritt. Wenn Sie regelmäßig standardisierte B2B- oder Serviceverträge abschließen, sollten zusätzlich eigene AGB verwendet und vor Vertragsabschluss wirksam einbezogen werden.
