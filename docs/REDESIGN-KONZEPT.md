# Redesign-Konzept: Privatkunden zuerst

## Ziel

Die Website soll nicht wie ein Leistungskatalog für Firmen wirken. Der erste Gedanke eines privaten Besuchers ist meist nicht „Ich brauche Infrastrukturleistungen“, sondern „Mein Laptop startet nicht“ oder „Das WLAN reicht nicht ins Schlafzimmer“. Deshalb führt die neue Startseite über erkennbare Probleme, einen einfachen Ablauf und den direkten Kontakt zu Fabian.

Firmeninhalte wurden aus Navigation, Startseite und Sitemap entfernt. Die bisherige Firmen-Landingpage bleibt nur als archivierter Code erhalten und wird nicht mehr ausgeliefert.

## Betrachtete Referenzen

Für den lokalen Markt wurden unter anderem folgende Tiroler beziehungsweise österreichische Angebote betrachtet:

- [Kurzmann IT Works – PC-Reparatur](https://kurzmann.at/pc-reparatur)
- [PCprivat Innsbruck](https://www.pcprivat.at/)
- [noxi-IT – Computerhilfe mit Hausbesuch](https://www.noxi-it.at/)
- [telesupport.at](https://telesupport.at/)
- [SPQRK IT-Dienstleistungen](https://spqrk.at/)
- [Digitalhilfe Zuhause](https://www.digitalhilfe-zuhause.de/)
- [du-IT – PC-Doktor für Private](https://du-it.ch/)

Als größere, stark auf private Technikprobleme ausgerichtete Benchmarks dienten:

- [HelloTech](https://www.hellotech.com/)
- [Geeks2U](https://www.geeks2u.com.au/)
- [uBreakiFix](https://www.ubreakifix.com/)

Die Seiten sind keine visuellen Vorlagen zum Kopieren. Wiederkehrende, nützliche Muster sind die sofortige Frage nach dem Problem, sichtbare persönliche beziehungsweise lokale Sicherheit, wenige klare nächste Schritte sowie nachvollziehbare Bewertungen und Kennzahlen.

## Umgesetzte Richtung

- Helle, warme „persönliche Werkstatt“-Optik statt dunklem IT-Dashboard.
- Große, klare Einstiegsbotschaft: Problem, Ansprechpartner und Ort sind ohne Scrollen verständlich.
- Fünf aufklappbare Problemwege anstelle einer großen Karten- und Filterwand.
- Kompaktes Menü mit `Hilfe finden`, `So läuft’s`, `Über mich`, `FAQ` und einer dominanten Kontaktaktion.
- Dreistufiger Ablauf ohne technische Voraussetzungen auf Kundenseite.
- Persönlicher Inhaberbereich statt abstrakter Firmenargumente.
- Bewertungen erscheinen nur, wenn echte Einträge vorhanden sind.
- Hochzählende Zahlen erscheinen nur, wenn die Funktion bewusst aktiviert und mit positiven Werten gepflegt wurde.

## Bewegung und Bedienbarkeit

Animation unterstützt nur Orientierung und Feedback: Inhalte werden beim Eintreten in den sichtbaren Bereich eingeblendet, das Bild im Problemfinder wechselt passend zur Auswahl und belegte Kennzahlen zählen einmal hoch. Es gibt keine endlosen, automatisch laufenden Dekorationen. `prefers-reduced-motion` schaltet nicht notwendige Bewegung ab. Die Problemzeilen basieren auf nativen `details`-Elementen und bleiben damit auch ohne JavaScript lesbar.

Als technische Leitlinien wurden unter anderem [W3C zu Animation aus Interaktionen](https://www.w3.org/WAI/WCAG21/Understanding/animation-from-interactions), [web.dev zu Bewegung und Accessibility](https://web.dev/learn/accessibility/motion) sowie die Hinweise des [GOV.UK Design Systems zu Akkordeons](https://design-system.service.gov.uk/components/accordion/) berücksichtigt.

## Sinnvolle nächste Inhalte

Der größte weitere Vertrauensgewinn käme nicht durch noch mehr Effekte, sondern durch echte Inhalte:

1. Ein gutes, authentisches Foto von Fabian bei der Arbeit.
2. Drei bis sechs freigegebene Kundenbewertungen.
3. Belegbare Auftrags- oder Kundenzahlen, sofern sie aus Rechnungen oder einer gepflegten Liste nachvollziehbar sind.
4. Eine klare Information zu Diagnose beziehungsweise Mindestaufwand, sobald die tatsächliche Preislogik feststeht.

Die Pflege von Bewertungen und Zahlen ist in [`INHALTE-PFLEGEN.md`](INHALTE-PFLEGEN.md) beschrieben.
