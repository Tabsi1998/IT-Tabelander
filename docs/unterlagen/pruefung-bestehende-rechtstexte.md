# Prüfung der veröffentlichten Rechtstexte

Geprüft am 26.11.2026: die drei freigegebenen Artikel in Dolibarr (Stand
24.11.2026, gelesen über die öffentliche Website-API `/api/legal/<art>`)
gegen den heutigen Stand der Website und die neuen Unterlagen in diesem
Ordner. Die Änderungen machst du in Dolibarr; danach „Stand“ anpassen und
die Kopie in `backend/app/legal_drafts/` bei Gelegenheit nachziehen.

> Entwurf – vor Verwendung vom WKO-Rechtsservice prüfen lassen.

## Ergänzung zum Impressum (Artikel 1)

1. **Widerspruch Firmenbuch.** Oben steht „Firmenbuchgericht: Innsbruck“, in
   der Ergänzung „nicht im Firmenbuch eingetragen“. Die Zeile kommt aus dem
   Feld **Prof. Id 2** in Dolibarr (Einstellungen → Unternehmen/Institution).
   Vorschlag: das Feld leeren.
2. **Grundlegende Richtung (Mediengesetz)** nennt nur Reparatur, Aufrüstung,
   PC-Bau und Beratung. Vorschlag:
   > Grundlegende Richtung: Information über die Leistungen von IT-Tabelander
   > (Reparatur, Aufrüstung und Bau von PCs, Notebooks, Konsolen und
   > Controllern, Webseiten, Softwareentwicklung, Hosting, Wartung und
   > Beratung) und die Möglichkeit, Kontakt aufzunehmen.
3. Kleinigkeiten: Land „AT“ statt „Österreich“ und die Telefonnummer ohne
   Leerzeichen kommen aus den Firmendaten. Vorschlag: in Dolibarr
   „+43 676 6039945“ eintragen.

## Datenschutzerklärung (Artikel 2)

1. **Status-Seite gibt es nicht mehr** (seit #122). Den Abschnitt „Status-Seite
   und Etikett“ ersetzen durch den Abschnitt „Etikett“ aus
   `backend/app/legal_drafts/datenschutz.html` (QR-Code führt in den
   Kundenbereich, Anfrage erst nach Anmeldung sichtbar).
2. **Kundenbereich** beschreibt noch nicht, dass man dort antworten, Fotos und
   PDFs schicken und neue Anfragen stellen kann, und dass Fotos ohne
   Kamera-Zusatzdaten gespeichert werden. Text aus dem Entwurf übernehmen.
3. **Rückruf entfällt.** Drei Stellen streichen:
   - „Für eine Nachricht, eine Anfrage oder einen Rückruf-Wunsch …“ → „Für eine
     Nachricht oder eine Anfrage …“, und „deinen Wunschtermin für den Rückruf“ streichen.
   - „…, ein Rückruf-Wunsch steht als Termin in meinem Kalender“ streichen.
   - „…, für einen Rückruf auch die Telefonnummer“ streichen.
4. **Grammatik:** „(Dolibarr, betrieben bei auf meinem eigenen Server in
   Telfs, Österreich)“ → „(Dolibarr, auf meinem eigenen Server in Telfs,
   Österreich)“.
5. **Empfängerliste** liest sich holprig („der Anbieter …: niemand: …“).
   Vorschlag:
   > Website, Kundensystem und E-Mail laufen auf meinen eigenen Servern in
   > Telfs, Österreich. Deine Daten bekommen nur:
   > - bei Versand deines Geräts das Versandunternehmen (Name und Anschrift),
   > - meine Steuerberatung und Behörden, soweit das Gesetz es verlangt, etwa bei Rechnungen.
6. **Neu: Fernwartung und Daten auf Geräten.** Ein Absatz fehlt, z. B.:
   > **Fernwartung und Daten auf deinem Gerät.** Repariere oder warte ich ein
   > Gerät, auch per Fernwartung, sehe ich Daten darauf nur, soweit der Auftrag
   > es verlangt. Ich kopiere sie nur, wenn wir das vereinbaren (etwa für eine
   > Datenübernahme), und lösche die Kopie nach dem Auftrag. Eine
   > Fernwartung gibt es nur nach deiner Freigabe am Gerät; die Verbindung ist
   > durchgehend verschlüsselt. (Rechtsgrundlage: Vertrag, Art. 6 Abs. 1 lit.
   > b DSGVO)

   Läuft das Fernwartungsprogramm über Server des Herstellers, gehört der
   Hersteller in die Empfängerliste.
7. **Neu: Hosting für Kunden.** Ein Satz, dass ich bei Hosting und Wartung
   Daten im Auftrag meiner Kunden verarbeite und dafür eine eigene
   Vereinbarung gilt (`auftragsverarbeitung.html`). Für Besucher der
   Kunden-Webseiten ist der jeweilige Kunde verantwortlich.
8. **Zahlungen prüfen.** Laut Leistungskatalog bleibt Stripe in Dolibarr
   eingeschaltet. Gehen Zahlungslinks oder Online-Zahlungen über Stripe an
   Kunden, muss Stripe (mit Übermittlung in die USA) in die
   Empfängerliste. Fabian prüft, ob Stripe gegenüber Kunden genutzt wird.
   Die Bank für Überweisungen ebenfalls nennen.
9. Der Satz „auch nicht in Länder außerhalb der EU“ stimmt nur, solange
   Punkt 6 und 8 keinen Anbieter außerhalb der EU bringen.

## Nutzungsbedingungen (Artikel 3)

1. **Status-Seite** → „Ist dein Gerät fertig, sage ich dir Bescheid; im
   Kundenbereich steht dann ‚abholbereit‘.“
2. **Leerer Absatz** unter „Abholung“ (`<p></p>`, dort stand der Punkt zu nie
   abgeholten Geräten) entfernen oder nach der WKO-Antwort füllen.
3. **Doppelt:** „Kostet sie etwas, sage ich dir das, bevor ich anfange.“ ist
   seit dem festen Preis von 29 € überflüssig; streichen.
4. **Geltungsbereich zu eng.** Der erste Satz nennt nur Geräte, PC-Bau,
   Controller und Beratung. Privatkunden bestellen aber auch Webseiten,
   Hosting und Fernwartung. Vorschlag:
   > Diese Bedingungen gelten, wenn du mir ein Gerät zur Reparatur oder
   > Aufrüstung gibst, einen PC bauen oder einen Controller umbauen lässt,
   > eine Webseite oder Software bei mir bestellst, Hosting oder Wartung
   > nutzt, ich dir per Fernwartung helfe oder dich berate. Für Unternehmen
   > gelten meine AGB für Unternehmer.

   Hosting und Wartung gibt es auch für Privatkunden (Entscheidung
   26.11.2026). Vorschlag für einen eigenen Abschnitt:
   > **Hosting und Wartung.** Was im Monatspreis enthalten ist, wie schnell
   > ich reagiere und was bei Vertragsende mit deinen Daten passiert, steht in
   > der Leistungsbeschreibung Hosting und Wartung für Privatkunden; du
   > bekommst sie mit dem Angebot. Mindestlaufzeit und Kündigungsfrist stehen
   > im Vertrag. Läuft er unbefristet oder länger als ein Jahr, kannst du
   > trotzdem mit zwei Monaten Frist zum Ende des ersten Jahres und danach zum
   > Ende jedes halben Jahres kündigen (§ 15 KSchG).
5. **Rücktrittsrecht zu eng.** Es nennt nur Verträge ohne persönliches
   Treffen. Das gesetzliche Rücktrittsrecht gilt auch bei Verträgen, die
   außerhalb der Werkstatt geschlossen werden, etwa beim Kunden zuhause
   (§ 3 Z 1 FAGG). Und bei Waren beginnt die Frist erst mit dem Erhalt der
   Ware (§ 11 Abs. 2 FAGG), nicht mit der Zusage. Vorschlag: den Abschnitt
   durch einen kurzen Absatz mit Link ersetzen:
   > Nimmst du als Verbraucherin oder Verbraucher ein Angebot an, ohne dass
   > wir uns dabei in meiner Werkstatt sehen, kannst du innerhalb von 14
   > Tagen ohne Angabe von Gründen zurücktreten. Wie das geht, wann die
   > Frist beginnt und wann es kein Rücktrittsrecht gibt, steht in der
   > [Widerrufsbelehrung](/rechtliches/widerruf) mit dem Muster-Formular.

   Die heutige Ausnahme „bei einem nach deinen Wünschen zusammengestellten
   und gebauten PC“ fällt dabei weg: Ein PC aus handelsüblichen Teilen bleibt
   rücktrittsfähig, ausgenommen sind nur eigens angepasste oder angefertigte
   Teile (Begründung in der README, Abschnitt „Freigabefähig gemacht“).

   Seit 1.10.2026 braucht es für online geschlossene Verträge zusätzlich die
   Rücktrittsfunktion „Vertrag widerrufen“ (§ 13a FAGG); die Widerrufsbelehrung
   nennt sie, die Website baut sie in #142.

   Achtung: Die Belehrung muss dem Kunden vor der Zusage vorliegen (§ 4 FAGG).
   Steht sie nur noch auf einer eigenen Seite, muss die Bestätigungsmail
   nach der Online-Annahme sie weiter enthalten (das tut sie laut
   Datenschutzerklärung) oder verlinken.
6. **Fernwartung** fehlt: Satz mit Verweis auf die Einverständniserklärung.
7. **Webseiten und Software für Privatkunden:** Abnahme, Nutzungsrechte und
   Quellcode stehen nur in den AGB für Unternehmer. Wenn Privatkunden
   Webseiten bestellen, die Abschnitte 6 und 7 der AGB in Du-Form und ohne
   die B2B-Einschränkungen übernehmen (keine Abnahmefiktion durch Schweigen
   ohne Hinweis, Gewährleistung zwei Jahre, VGG-Updatepflicht bei digitalen
   Leistungen beachten).
8. **Online-Streitbeilegung:** Die EU-Plattform (OS-Plattform) ist seit
   20.07.2025 abgeschaltet. Kein Link darauf aufnehmen; ein alter Link
   müsste weg. Die Texte enthalten keinen, gut so.

## Zu den neuen Unterlagen passend

- Zahlungsziel 30 Tage, Diagnose 29 €, Abholung 4 Wochen und 2 €/Tag,
  unverbindlicher Kostenvoranschlag: überall gleich.
- Rücktrittsrecht: Nutzungsbedingungen, `widerruf.html` und
  Geräteannahmeschein sagen dasselbe, sobald Punkt 5 oben umgesetzt ist.
- Telefon: In der Widerrufsbelehrung steht die Nummer, weil das
  Muster-Formular nach dem Gesetz alle Kontaktwege nennen soll. Ein
  Rückruf-Angebot gibt es nirgends mehr.
