# HF127 – Calendar Block Drag Pointer Repair

## Fehlerbild

Die Auswahl eines zu blockierenden Zeitbereichs per gedrückter linker Maustaste und Ziehen war nicht robust. Die bisherige Implementierung wertete während `mousemove` ausschließlich `document.elementFromPoint()` aus. Lag über einem Zeitslot ein anderes Element, insbesondere ein Termin, wurde der darunterliegende `.slot` nicht erkannt. Dadurch konnte die laufende Bereichsauswahl stehenbleiben bzw. den gewünschten Endslot nicht übernehmen.

## Reparatur

Die Blockauswahl verwendet jetzt Pointer Events (`pointerdown`, `pointermove`, `pointerup`, `pointercancel`) und funktioniert damit einheitlich für Maus, Stift und Touch. Die Trefferermittlung verwendet `document.elementsFromPoint()` und sucht im gesamten Elementstapel nach dem zum Kalender gehörenden `.slot`. Überlagernde Termine verhindern damit nicht mehr die Ermittlung des Zeitrasters.

Während der Auswahl wird die Pointer-ID gespeichert, damit nur die gestartete Zeigergeste die Auswahl verändert. Beim Loslassen wird der letzte gültige Slot nochmals aus der tatsächlichen Zeigerposition übernommen. Abbruch über `pointercancel` oder Fensterverlust räumt den Auswahlzustand vollständig auf.

Im aktiven Blockierungsmodus deaktiviert CSS Textauswahl und Browser-Touchgesten auf den Slots (`user-select:none`, `touch-action:none`). Die Cache-Version von `calendar.js` wurde auf `20261001-127` angehoben.

## Betroffene Dateien

- `products/calendar/assets/js/calendar.js`
- `products/calendar/assets/css/calendar.css`
- `products/calendar/assets/js/bootstrap.js`

Da der Standalone-Export den vollständigen Ordner `products/calendar/assets` übernimmt, ist die Reparatur automatisch Bestandteil neu erzeugter easyITCalendar-Anwenderpakete.
