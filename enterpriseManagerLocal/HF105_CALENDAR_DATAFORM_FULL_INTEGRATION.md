# HF105 – easyITCalendar vollständig mit DataForm kombiniert

## Ziel

easyITCalendar und DataForm verwenden ab HF105 keine parallelen Formular- und CRUD-Schichten mehr. Calendar übernimmt Kalenderdarstellung, Zeitraumlogik, Drag-and-drop und kalenderfachliche Interaktion. DataForm ist die verbindliche Daten-, Formular-, Feld-, Key-, Relations- und RecordSet-Schicht.

## Umgesetzte Integration

- Calendar-Projekte erhalten beim Anlegen automatisch das vollständige DataForm-Projektschema.
- Für `events`, `event_participants`, `persons`, `resources`, `categories`, `event_attachments` und `roles` werden gebundene DataForms provisioniert.
- Der Termin-DataForm wird automatisch als Dialogformular konfiguriert. Die Terminbasis enthält Beginn, Ende, Ganztägig, Ort, Ressource, Kategorie, Aktiv, Thema/Titel, Beschreibung und Status; Ressource/Kategorie werden als DataForm-Lookups angebunden.
- Bestehende Calendar-Projekte werden beim Setup bzw. ersten Öffnen idempotent auf die gemeinsame Struktur angehoben.
- Event → Teilnehmer, Event → Anhänge, Teilnehmer → Person und Teilnehmer → Rolle werden als DataForm-Relationen angelegt.
- Calendar-Setup delegiert Datenpflege, Tabellen-/Key-/Feldkonfiguration und Relationen an DataForm.
- Die frühere eigene Calendar-Stammdatenpflege wurde aus der aktiven Oberfläche entfernt; `maintenance.php` leitet auf das passende DataForm um.
- Der fest codierte Calendar-Termindialog wurde entfernt. Neu und Bearbeiten öffnen ausschließlich `products/dataform/records.php` im eingebetteten Einzeldatensatzmodus.
- Start-/Endwerte eines neu markierten Kalenderslots werden als echte `datetime`-Defaults an DataForm übergeben.
- Der Calendar verarbeitet den realen DataForm-Rückkanal `easyit-dataform-dialog-saved` und lädt danach die Kalenderdaten neu.
- Die Calendar-API arbeitet für Lesen/Ändern ebenfalls über `DataFormRecordStore`; parallele SQL-CRUD-Logik für Termine ist damit aus dem aktiven Pfad entfernt.
- DataForm-Records, DataForm-Konfiguration und DataForm-Relationen akzeptieren Calendar-Projekte als internen Bestandteil der Calendar-Lizenz. Eine zweite DataForm-Produktlizenz ist für die Calendar-interne Formularschicht nicht erforderlich.
- Feld-/Layoutkonfiguration, DataForm-Events, Custom CSS/JavaScript sowie Suche/Filter/Paginierung und CRUD stehen der Calendar-internen DataForm-Schicht zur Verfügung. Export und API bleiben weiterhin eigenständige lizenzierbare Module.
- Calendar-Projektkonfiguration wurde auf Version 2 erweitert und enthält verbindliche DataForm-IDs sowie das Termin-DataForm.
- Calendar-Auslieferungen enthalten die DataForm-Produktschicht automatisch als Pflichtabhängigkeit; bei CSV bleibt zusätzlich die CSV-Engine enthalten.

## Architektur ab HF105

`Calendar UI / Drag&Drop` → `Calendar Adapter` → `DataFormRecordStore / DataFormRecordSet` → `Projekt-Datenquelle`

`Calendar Setup` → `DataForm Tabellen + Felder + Keys + Relationen + Events`

`Termin Neu/Bearbeiten` → `DataForm Einzeldatensatzformular`

Es existiert damit nur noch eine produktive Daten- und Formularlogik.

## Gates

Neues Gate: `tools/hf105-calendar-dataform-full-integration-gate.php`.

Der historische HF91-Phase-10-Test erwartet ausdrücklich direkten Calendar-Tabellenzugriff im Handler und ist deshalb für HF105 fachlich überholt. HF105 prüft stattdessen, dass gerade **kein** eigener SQL-CRUD-Pfad mehr im Calendar-Handler existiert.
