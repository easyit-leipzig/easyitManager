# HF162 – Kategoriefarbe bei Blockierungen rendern

Blockierungen übernehmen jetzt die in der zugeordneten Calendar-Kategorie gepflegte `category_color`. Die API liefert die Farbe pro Blockierung aus; die Kalender-Runtime normalisiert sie und verwendet sie sowohl für die Slot-Fläche als auch für die Personen-/Global-Markierungen. Nur wenn keine gültige Kategorie-Farbe vorhanden ist, wird weiterhin die interne Ersatzpalette verwendet.

Dies gilt für Enterprise-Calendar und Standalone-/HTML-Export.
