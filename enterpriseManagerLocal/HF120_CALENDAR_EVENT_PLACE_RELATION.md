# HF120 – Calendar events.location → places

HF120 normalisiert den Terminort. `events.location` ist kein freies Textfeld mehr, sondern ein n:1-Lookup auf `places.id`. Die Tabelle `places` wird in jeder unterstützten Projektdatenquelle provisioniert und als eingebettetes Calendar-DataForm **Calendar – Orte** angelegt. Im Termin-DataForm wird das Feld **Ort** dadurch als Lookup-Auswahl dargestellt; angezeigt wird `places.name`.

Bestehende Calendar-Projekte werden beim nächsten Calendar/DataForm-Ensure automatisch migriert. Vorhandene nichtleere Freitexte in `events.location` werden als Orte in `places` übernommen (identische Namen werden wiederverwendet) und anschließend durch die jeweilige Orts-ID ersetzt. Bereits gültige Orts-IDs bleiben unverändert.

Die Relation heißt `calendar_event_place`. Calendar besitzt damit acht eingebettete DataForms: Termine, Teilnehmer, Personen, Orte, Ressourcen, Kategorien, Anhänge und Rollen. Die Orts-DataForm belastet keinen separaten DataForm-Lizenzplatz.
