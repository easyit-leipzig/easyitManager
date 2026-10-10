# HF153 – Personenbezogene Zeitblockierung

Blockierte Zeitbereiche können optional genau einer Person zugeordnet werden. `person_id` leer/NULL/0 bedeutet: Die Blockierung gilt für alle Personen.

## Verhalten
- Blockierungsdialog: zusätzliche Auswahl **Person** mit Standard **Alle Personen**.
- Personenbezogene Sperren verhindern nur Termine, an denen diese Person beteiligt ist.
- Globale Sperren verhindern Termine für jede Person.
- Überlappende personenbezogene Sperren verschiedener Personen sind zulässig.
- Eine globale Sperre darf sich nicht mit einer anderen aktiven Sperre überschneiden.
- Personenbezogene Sperren werden im Kalender gelb/schraffiert dargestellt; globale Sperren behalten die bisherige rote Sperrdarstellung.
- Standalone-/HTML-Export und API übernehmen `person_id`.

Bestehende Projekte werden beim Calendar-Setup automatisch um `blocked_ranges.person_id` erweitert.
