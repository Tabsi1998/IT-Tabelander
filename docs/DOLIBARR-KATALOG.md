# Dolibarr-Katalog für Website und Controller-Upgrades

## Empfohlener Ablauf

- Eine neue Person aus dem Website-Formular wird zunächst als **Interessent** angelegt. Erst bei Auftrag wird sie in Dolibarr zum Kunden.
- Eine normale Reparatur-, PC-, WLAN- oder sonstige Anfrage wird als **Ticket** übernommen. Diagnose und Preis bleiben individuell.
- Der Controller-Konfigurator enthält ausschließlich standardisierbare **Upgrades**. Daraus wird ein unverbindlicher **Angebotsentwurf**; IT-Tabelander prüft Kompatibilität, Verfügbarkeit und Preis vor dem Versand.
- BDM-Revisionen und konkrete Herstellerkits sind interne Beschaffungs- und Kompatibilitätsdetails. Kunden müssen sie weder kennen noch auswählen.

## Controller-Leistungen

Diese Einträge als Typ **Leistung**, im Verkauf aktiv und im Einkauf inaktiv anlegen. Die Bruttopreise entsprechen dem aktuellen Website-Katalog.

| Referenz | Bezeichnung | Brutto | Dauer als Richtwert |
|---|---|---:|---:|
| `DL-PS5-HALL-080` | Hall-Effect-Umbau PS5 DualSense – 80 gf, 2 Sticks inkl. Kalibrierung | 99,90 € | 90 min |
| `DL-PS5-HALL-120` | Hall-Effect-Umbau PS5 DualSense – 120 gf, 2 Sticks inkl. Kalibrierung | 99,90 € | 90 min |
| `DL-PS5-AKKU` | Akku-Upgrade PS5 DualSense – 2.600 mAh inkl. Funktionstest | 54,90 € | 30 min |
| `DL-PS5-CLICKY-FACE` | Clicky-Tasten PS5 DualSense – D-Pad & Aktionstasten | 49,90 € | 60 min |
| `DL-PS5-CLICKY-TRIGGER` | Clicky-Trigger PS5 DualSense – L1/L2 & R1/R2 | 54,90 € | 60 min |
| `DL-PS5-CLICKY-FULL` | Full-Clicky-Paket PS5 DualSense | 79,90 € | 90 min |
| `DL-PS5-PADDLE` | Back-Paddle-Umbau PS5 DualSense | 99,90 € | 90 min |
| `DL-PS5-PADDLE-METAL` | Back-Paddle Pro PS5 DualSense – Metalltasten | 119,90 € | 105 min |
| `DL-PS5-PADDLE-OLED` | Back-Paddle OLED-Paket PS5 DualSense | 129,90 € | 120 min |
| `DL-PS5-LED` | LED-Beleuchtungs-Umbau PS5 DualSense | 99,90 € | 90 min |
| `DL-PS5-EDGE-CLICKY` | Clicky-Tasten PS5 DualSense Edge | 59,90 € | 60 min |
| `DL-PS5-EDGE-PADDLE-OLED` | Back-Paddle OLED-Paket PS5 DualSense Edge | 119,90 € | 90 min |

## Controller und Gehäuseoptionen

Wenn IT-Tabelander den Controller beschafft, wird er als eigene Produktposition vor den Umbauleistungen angeboten. Die am 21.08.2026 geprüften offiziellen PlayStation-Direct-Preise für Österreich sind:

| Referenz | Bezeichnung | Website-Preis |
|---|---|---:|
| `MAT-PS5-DUALSENSE-WHITE` | Neuer PS5 DualSense Wireless-Controller, Weiß | 74,99 € |
| `MAT-PS5-DUALSENSE-EDGE` | Neuer PS5 DualSense Edge Wireless-Controller | 219,99 € |

Quelle: [PlayStation Direct DualSense Österreich](https://direct.playstation.com/de-at/buy-accessories/dualsense-wireless-controller-white-for-ps5-pc-mac-mobile) und [PlayStation Direct DualSense Edge Österreich](https://direct.playstation.com/de-at/buy-accessories/dualsense-edge-wireless-controller). Verfügbarkeit und Preis vor dem Versand des Angebots noch einmal prüfen.

Die folgenden Gehäusepakete sind Website-Pauschalen inklusive passendem Gehäusematerial und Einbau:

| Referenz | Bezeichnung | Website-Preis |
|---|---|---:|
| `DL-PS5-SHELL-BLACK` | DualSense Front-Shell Schwarz | 49,90 € |
| `DL-PS5-SHELL-GREEN` | DualSense Front-Shell Grün | 49,90 € |
| `DL-PS5-SHELL-CLEAR-BLUE` | DualSense Front-Shell Transparent Blau | 54,90 € |
| `DL-PS5-SHELL-CLEAR-BLACK` | DualSense Front-Shell Transparent Schwarz | 54,90 € |
| `DL-PS5-EDGE-SHELL-BLACK` | DualSense Edge Full-Shell Schwarz | 69,90 € |
| `DL-PS5-EDGE-SHELL-ORANGE` | DualSense Edge Full-Shell Orange | 69,90 € |
| `DL-PS5-EDGE-SHELL-CLEAR` | DualSense Edge Full-Shell Transparent | 69,90 € |

Die Varianten orientieren sich am am 21.08.2026 geprüften ExtremeRate-Sortiment für DualSense-Front-Shells und DualSense-Edge-Full-Shells. ExtremeRate ist dabei nur interne Beschaffungsinformation und wird im öffentlichen Angebot nicht als Hersteller versprochen. Ersatzfarbe und Lieferbarkeit werden vor Freigabe des Angebots bestätigt.

Die vorhandene Leistung `DL-STICK-HALL` mit 64,90 € kann als reine Einbauleistung bestehen bleiben. Für Website-Angebote sind `DL-PS5-HALL-080` und `DL-PS5-HALL-120` als vollständige Pauschalpreise inklusive Material eindeutiger. Dasselbe Prinzip gilt für `DL-PS5-AKKU`: vorhandene Material- und Arbeitspositionen dürfen intern getrennt bleiben, nach außen wird aber die Pauschale angeboten.

## Interne Materialien

Material als Typ **Produkt**, im Einkauf aktiv und nur dann im Verkauf aktiv anlegen, wenn es tatsächlich einzeln verkauft wird. Lagerbestand und Lieferantenpreis gehören nur auf diese Materialartikel.

| Referenz | Bezeichnung / Zweck |
|---|---|
| `MAT-PS5-AKKU-2600` | Akku PS5 DualSense – 2.600 mAh |
| `MAT-PS5-HALL-080` | Hall-Effect-Analogstick-Set PS5 – 80 gf |
| `MAT-PS5-HALL-120` | Hall-Effect-Analogstick-Set PS5 – 120 gf |
| `MAT-PS5-STICK-23K` | Analogstick-Modul PS5 – Standard 2,3 kΩ |
| `MAT-STICK-21K` | Analogstick-Modul PS5/PS4 – Standard 2,1 kΩ |
| `MAT-PS5-CLICKY-FACE` | Passendes Clicky-Kit für D-Pad und Aktionstasten |
| `MAT-PS5-CLICKY-TRIGGER` | Passendes Clicky-Trigger-Kit |
| `MAT-PS5-PADDLE` | Back-Paddle-Kit Standard |
| `MAT-PS5-PADDLE-METAL` | Back-Paddle-Kit mit Metalltasten |
| `MAT-PS5-PADDLE-OLED` | Back-Paddle-Kit mit OLED/Display |
| `MAT-PS5-LED` | LED-Kit PS5 DualSense |
| `MAT-PS5-EDGE-CLICKY` | Clicky-Kit PS5 DualSense Edge |
| `MAT-PS5-EDGE-PADDLE-OLED` | Back-Paddle-Kit PS5 DualSense Edge |

Im internen Beschreibungstext oder in Produktvarianten kann die kompatible BDM-Revision stehen. Sie gehört nicht in die öffentliche Bezeichnung und nicht in den Web-Konfigurator.

## Sinnvolle allgemeine Leistungen

Für spätere Angebote empfiehlt sich eine kleine, verständliche Basis statt vieler Mikroposten: `DL-DIAGNOSE`, `DL-WERKSTATT-STUNDE`, `DL-FERNWARTUNG`, `DL-VOR-ORT`, `DL-DATENUMZUG`, `DL-PC-REINIGUNG`, `DL-WINDOWS-EINRICHTUNG` und `DL-WLAN-CHECK`. Eine allgemeine Website-Anfrage verwendet diese Positionen noch nicht automatisch; zuerst entsteht ein Ticket, danach wird aus der tatsächlichen Lösung ein Angebot erstellt.

## Preis- und Steuereinstellungen

- Website-Pauschalen als **Bruttopreise** pflegen, damit der Betrag im Angebot dem Konfigurator entspricht.
- Für IT-Tabelander gilt nach Betreiberangabe die Kleinunternehmerregelung ohne Umsatzsteuerausweis. Deshalb werden Website und Dolibarr mit **0 % USt.** geführt.
- Der Code erzwingt `tva_tx=0.0` für jede Controller-Angebotsposition und `tva_assuj=0` für neue Website-Interessenten. Eine abweichende Servervariable kann diese Regel nicht überschreiben.
- Wenn sich dieser Status später ändert, müssen Dolibarr, Website-Konfiguration, Angebots-/Rechnungstexte und öffentliche Preise gemeinsam geprüft und angepasst werden.
- Einkaufspreise nur als reale Netto-Lieferantenpreise inklusive nachvollziehbarer Bezugsquelle pflegen.
- Mindestverkaufspreise als interne Untergrenze nutzen, nicht als zweiten öffentlichen Preis.
- Preise regelmäßig prüfen, besonders nach Lieferanten- oder Versandpreisänderungen. Die Website ist die serverseitige Preisquelle der aktuellen Integration; Änderungen daher in `private/controller-config.php` und Dolibarr gemeinsam nachziehen.
