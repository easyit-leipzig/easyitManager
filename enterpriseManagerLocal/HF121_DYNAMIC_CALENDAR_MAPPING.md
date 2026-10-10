# HF121 – Dynamische Calendar-Zuordnungen

## Ziel

Die bisherige Registerkarte **Calendar-Zuordnungen** war auf sechs fest verdrahtete Felder beschränkt. Ab HF121 wird das Calendar-Mapping dynamisch verwaltet. Neben den vier Pflichtrollen `id`, `start`, `end` und `title` können beliebig viele weitere Zuordnungen angelegt und wieder entfernt werden.

## Mapping-Typen

Jede Zuordnung besitzt:

- logischen Calendar-Namen,
- sichtbare Bezeichnung,
- Feld der Quelltabelle,
- Typ `Feld` oder `Lookup / Relation`.

Ein Lookup besitzt zusätzlich:

- Zieltabelle,
- Zielschlüssel,
- Anzeigefeld.

Damit ist beispielsweise die Ortsbeziehung vollständig abbildbar:

`location -> events.location -> places.id -> places.name`

Voreingestellt werden außerdem `all_day`, `resource`, `category` und `status`. Eigene Zuordnungen können über **+ Zuordnung hinzufügen** ergänzt werden.

## Laufzeit

Die Zuordnungen werden nicht mehr nur in der Administrationsoberfläche gespeichert. Der Calendar-Handler liest die konfigurierten Feldnamen für Start, Ende, Titel, Beschreibung, Aktivstatus und Ort tatsächlich aus der Projektkonfiguration. Die gespeicherte Quelltabelle wird außerdem zur aktiven Event-Tabelle des Calendar/DataForm-Bridge.

## DataForm-Integration

Die Event-DataForm-Profile und die bestehenden Calendar-Relationen für Ort, Ressource und Kategorie verwenden die konfigurierten Event-Feldnamen. Damit bleiben die in HF120 eingeführten Relationen auch nach einer Feldumbenennung bzw. individuellen Feldzuordnung konsistent.

## Kompatibilität

Alte Projektkonfigurationen mit `id_field`, `start_field`, `end_field`, `title_field`, `description_field` und `active_field` werden beim Laden weiterhin verstanden und in den dynamischen Vertrag überführt. Entfernte optionale Zuordnungen werden nicht automatisch wiederhergestellt.

Die Calendar-Konfiguration wurde auf Version 4 angehoben.
