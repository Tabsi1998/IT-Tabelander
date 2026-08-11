# Bewertungen und Zahlen selbst pflegen

Für Bewertungen und Kennzahlen ist kein Umbau der Website nötig. Beide Bereiche werden über kleine JSON-Dateien gepflegt. Wichtig: nur echte Rückmeldungen und belegbare Zahlen veröffentlichen.

## Eine Bewertung hinzufügen

1. `private/data/reviews.json` in einem Texteditor öffnen.
2. Innerhalb der Liste `reviews` einen neuen Block ergänzen.
3. Die Datei speichern und anschließend `php private/tools/content-check.php` ausführen.

Beispiel mit zwei Bewertungen:

```json
{
  "reviews": [
    {
      "author": "Maria K.",
      "rating": "5",
      "text": "Mein Laptop startet wieder und alles wurde verständlich erklärt.",
      "date": "August 2026",
      "url": "https://www.google.com/maps/..."
    },
    {
      "author": "Thomas S.",
      "rating": "5",
      "text": "Das WLAN funktioniert jetzt im ganzen Haus.",
      "date": "Juli 2026"
    }
  ]
}
```

`author` und `text` sind Pflicht. `rating`, `date` und `url` sind optional. Zwischen zwei Blöcken muss ein Komma stehen; hinter dem letzten Block darf keines stehen. Texte nur mit Zustimmung der betreffenden Person oder als Verweis auf eine bereits öffentlich sichtbare Bewertung übernehmen. Namen können wie im Beispiel abgekürzt werden.

Wenn keine Einträge vorhanden sind, wird der komplette Bewertungsbereich ausgeblendet. Dadurch erscheinen keine Platzhalter oder erfundenen Stimmen.

## Hochzählende Kennzahlen aktivieren

Die Datei `private/data/stats.json` enthält den Zahlenbereich. Im Auslieferungszustand steht `enabled` auf `false`; der Bereich ist damit unsichtbar.

1. Nur tatsächlich nachvollziehbare Zahlen eintragen – beispielsweise anhand von Rechnungen oder einer gepflegten Auftragsliste.
2. `value` muss eine ganze Zahl ohne Punkt oder Pluszeichen sein.
3. Das Pluszeichen gehört bei Bedarf in `suffix`.
4. Erst wenn alle Werte stimmen, `enabled` auf `true` setzen.
5. `php private/tools/content-check.php` ausführen.

Beispiel:

```json
{
  "enabled": true,
  "items": [
    {
      "value": 120,
      "suffix": "+",
      "label": "Privatkundinnen und Privatkunden unterstützt"
    },
    {
      "value": 85,
      "suffix": "+",
      "label": "Geräte wieder einsatzbereit gemacht"
    }
  ]
}
```

Die Zahlen zählen beim ersten Sichtbarwerden einmal hoch. Bei aktivierter Systemeinstellung „Bewegung reduzieren“ wird der Endwert sofort und ohne Animation angezeigt. Ungültige oder leere Kennzahlen werden nicht veröffentlicht.

## Vor dem Veröffentlichen prüfen

```powershell
php private/tools/content-check.php
php tests/run.php
```

Danach die Seite lokal mit `php -S localhost:8000` öffnen und Bewertung, Zahlenschreibweise sowie Mobilansicht kontrollieren.
