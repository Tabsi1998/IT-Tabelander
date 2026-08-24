# Controller-Visuals

Die ursprünglichen vier transparenten Produktvisuals wurden am 21. August 2026 mit dem integrierten OpenAI-Bildgenerator für IT-Tabelander erstellt und auf 768 × 512 Pixel verkleinert.

Sie sind eigenständige, nicht gebrandete Darstellungen für den Website-Konfigurator. Es wurden keine Sony-/PlayStation-Bilddateien kopiert oder eingebettet. Die offiziellen DualSense- und DualSense-Edge-Produktansichten dienten ausschließlich als Referenz für Geräteklasse, frontale Perspektive und Unterscheidbarkeit der Modelle.

- `controller-dualsense-premium.png`: weiß-schwarzer Standard-Controller, frontale Studioansicht.
- `controller-dualsense-edge-premium.png`: sichtbare Premium-Unterscheidung durch schwarze Touchfläche, metallische Stickringe und zusätzliche Funktionstasten.
- `controller-dualsense-back.png`: saubere Rückseite des Standard-Controllers ohne Umbau.
- `controller-dualsense-edge-back.png`: Rückseite des Premium-Controllers mit dezenten werkseitigen Rücktasten.

Prompt-Kern Front: hochwertiges fotorealistisches 3D-Produktbild, transparenter Hintergrund, frontale symmetrische Ansicht, keine Logos, Markennamen, Tastensymbole oder Wasserzeichen. Prompt-Kern Rückseite: dieselbe Geräteklasse und Materialanmutung in symmetrischer Rear-View-Perspektive, Standardgehäuse ohne Markenkennzeichen, sauber freigestellt auf transparentem Alphakanal.

## Reale Produktansichten

Für die für Kunden entscheidenden Edge- und Upgrade-Zustände werden bewusst reale Produktabbildungen verwendet:

- `controller-dualsense-edge-midnight-front.png` und `controller-dualsense-edge-midnight-back.png`: zusammengehörige reale Midnight-Black-Produktansichten mit echtem Alphakanal, auf 1.000 × 1.000 Pixel optimiert.
- `upgrade-rise4.jpg`, `upgrade-spark-oled.jpg` und `upgrade-beyond-edge.jpg`: unveränderte reale eXtremeRate-Quellabbildungen.
- Die gleichnamigen Dateien mit dem Suffix `-cutout.png` enthalten ausschließlich den montierten Controllerzustand. Platinen, Kabel, Beschriftungen und weißer Hintergrund wurden deterministisch entfernt; die Produktpixel werden nicht neu generiert.
- `private/tools/controller-cutouts.py` erzeugt diese transparenten Ebenen reproduzierbar aus den unveränderten Quellen.
- Weitere Shell-Designs werden serverseitig aus den öffentlichen eXtremeRate-Katalogen geladen und über einen streng auf `cdn.shopify.com/s/files/` begrenzten Bild-Proxy ausgeliefert. So zeigt die Galerie die jeweils echte montierte Produktansicht und bleibt mit der Content-Security-Policy kompatibel.

PlayStation, DualSense und DualSense Edge sind Marken von Sony Interactive Entertainment. eXtremeRate ist eine Marke des jeweiligen Rechteinhabers. Die Abbildungen dienen ausschließlich der konkreten Produkt- und Umbauvorschau; Verfügbarkeit und Kompatibilität werden vor Angebotserstellung geprüft.
