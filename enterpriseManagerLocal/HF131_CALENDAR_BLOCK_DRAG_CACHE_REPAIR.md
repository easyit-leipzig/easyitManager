# HF131 – Calendar Block Drag + Cache Repair

Stand: 2026-10-03

Die Blockierung frei markierbarer Terminfenster wurde erneut gehärtet. Der Start der Auswahl wird jetzt nativ im Capture-Mode am Calendar-Grid erkannt und über die Zeigerkoordinaten auf den zugrunde liegenden Zeitslot abgebildet. Die Slot-Ermittlung verwendet `elementsFromPoint()` und zusätzlich einen geometrischen Fallback über die Slot-Rechtecke. Dadurch bleibt die Auswahl auch bei überlagerten Calendar-Elementen stabil.

Der Drag läuft über Pointer-Events auf Dokumentebene. Während des Blockierungsmodus werden Browser-Gesten und Textselektion im Grid unterdrückt. Die Auswahlvorschau wird für alle berührten Slots aktualisiert; nach dem Loslassen öffnet sich der Blockierungsdialog für den gesamten markierten Bereich.

Zusätzlich wurden die Browser-Cache-Versionen von `bootstrap.js`, `calendar.js`, `calendar.css` und den noquery-Dateien angehoben. Damit kann ein zuvor gecachtes Calendar-Bootstrap die neue Drag-Logik nicht mehr verdecken.

Der Standalone-Export übernimmt dieselben Produktassets. Sein Runtime-Index wurde ebenfalls auf die neue Cache-Version umgestellt; das Exportmanifest meldet Runtime HF131.
