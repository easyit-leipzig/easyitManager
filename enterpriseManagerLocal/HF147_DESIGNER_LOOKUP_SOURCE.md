# HF147 – Datensatz-Auswahl direkt im DataForm-Designer konfigurieren

Der Feldtyp `lookup` / „Datensatz-Auswahl“ besitzt jetzt direkt im Feldinspektor eine vollständige Lookup-Konfiguration:

- Auswahltabelle / Kind-DataForm
- Schlüsselfeld (wahlweise technische Datensatz-ID)
- Anzeigefeld (wahlweise technische Datensatz-ID)

Beim Speichern wird automatisch eine echte `n:1`-Beziehung für das gewählte Feld angelegt oder eine bestehende Lookup-Beziehung aktualisiert. Damit nutzt die Datensatz-Runtime dieselbe Relation wie die zentrale Beziehungsverwaltung; es entsteht keine zweite, abweichende Lookup-Logik.

Beispiel Calendar: `events.category_id -> categories.id`, Anzeige über `categories.name`.

Regression: HF105, HF121 und HF122 bleiben PASS.
