# HF122 – Relation Key / Lookup Designer

HF122 erweitert den DataForm-Beziehungsdesigner um eine explizite Schlüsselzuordnung für n:1-/Lookup-Beziehungen.

## Neuer Vertrag

Für eine n:1-Beziehung werden jetzt drei voneinander getrennte Felder konfiguriert:

1. **Abhängiges Feld im Ausgangs-/Termin-DataForm** – speichert den Referenzschlüssel, z. B. `events → location`.
2. **Schlüsselfeld im Lookup-/Referenz-DataForm** – liefert den tatsächlich zu speichernden Schlüssel, z. B. `places → id`.
3. **Anzeigefeld im Lookup-/Referenz-DataForm** – liefert die sichtbare Beschriftung, z. B. `places → name`.

Die technische Tabellen-ID `id` ist im Designer ausdrücklich auswählbar. Alternativ kann ein anderes reales, speicherbares Feld als Zielschlüssel verwendet werden.

Zusätzlich kann pro n:1-Beziehung festgelegt werden, ob das abhängige Feld im Ausgangs-/Terminformular als Lookup dargestellt wird. Ist dies aktiviert, speichert DataForm weiterhin nur den Schlüsselwert, zeigt dem Anwender aber den konfigurierten Anzeigewert.

## Calendar-Beispiel

Für Orte ist die fachlich richtige Zuordnung:

`events → location` → `places → id`, Anzeige `places → name`.

Damit wird `events.location` im Terminformular als Orts-Lookup dargestellt, während in der Tabelle weiterhin die technische Orts-ID gespeichert wird.

## Kompatibilität

Bestehende n:1-Beziehungen ohne explizites Zielschlüsselfeld verwenden weiterhin die technische DataForm-Datensatz-ID. Bestehende Beziehungen werden standardmäßig weiterhin als Lookup dargestellt.
