# Kategorisierte Blockierungen

Blockierungen sind eigenständige Calendar-Datensätze und keine Termine. Sie werden projektweit in `blocked_ranges` gespeichert.

## Bedienung

1. In Tages- oder Wochenansicht **Zeit blockieren** aktivieren.
2. Einen freien Zeitraum mit der linken Maustaste markieren und über die gewünschten Slots ziehen.
3. Beim Loslassen öffnet sich der Blockierungsdialog.
4. Kategorie auswählen, optional Grund/Bemerkung erfassen und speichern.
5. Eine vorhandene Blockierung im Blockierungsmodus anklicken, um Kategorie/Grund zu ändern oder sie zu löschen.

Die Kategorien stammen aus den normalen Calendar-Stammdaten `categories`. Dadurch können dieselben Kategorien in der Enterprise-Verwaltung und im exportierten Standalone-Calendar gepflegt werden.

## Datenmodell

`blocked_ranges`: `id`, `start`, `end`, `category_id`, `reason`, `activ`, `created_by`, `created_at`, `updated_at`.

`category_id` verweist als n:1-Lookup auf `categories.id`; angezeigt wird `categories.name`.

Blockierungen dürfen sich nicht mit aktiven Terminen oder anderen aktiven Blockierungen überschneiden. Termin-Neuanlage und Terminverschiebung in blockierte Bereiche werden serverseitig abgewiesen.

## Rollen im Standalone-Paket

- `admin`: Kalender, Blockierungen, Stammdaten und Benutzer/Rollen.
- `editor`: Kalender, Blockierungen und Stammdaten.
- `viewer`: ausschließlich lesender Kalenderzugriff.

Die frühere ausschließlich lokale Speicherung in `localStorage` ist nicht mehr der fachliche Speicher für Blockierungen.
