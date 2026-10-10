# HF126 – Kategorisierte Zeitblockierungen und Standalone-Rollen

## Ziel

HF126 erweitert easyITCalendar um persistente, kategorisierte Zeitblockierungen. Zusätzlich erhält das exportierte Standalone-Paket die bereits geforderte eigene Anmeldung und Stammdatenverwaltung.

## Blockierungen

In Tages- und Wochenansicht steht die Schaltfläche **Zeit blockieren** zur Verfügung. Nach Aktivierung wird ein freier Zeitraum mit der linken Maustaste markiert und über mehrere 30-Minuten-Slots gezogen. Beim Loslassen öffnet sich ein Dialog mit:

- Beginn und Ende des markierten Bereichs,
- Kategorie,
- optionalem Grund / Bemerkung.

Die Blockierung wird in `blocked_ranges` gespeichert. Sie ist damit projektweit persistent und nicht mehr nur browserlokal.

Datenfelder:

- `id`
- `start`
- `end`
- `category_id`
- `reason`
- `activ`
- `created_by`
- `created_at`
- `updated_at`

`category_id` ist als n:1-Lookup auf `categories.id` modelliert. Als Anzeige dient `categories.name`.

Bestehende Blockierungen können im Blockierungsmodus angeklickt, geändert oder gelöscht werden. Der Server verhindert Überschneidungen zwischen Blockierungen sowie das Blockieren bereits belegter Terminbereiche. Umgekehrt werden Neuanlage und Verschiebung eines Termins in blockierte Bereiche abgewiesen.

## DataForm-Integration

Calendar besitzt nun neun eingebettete DataForms:

1. Termine
2. Teilnehmer
3. Personen
4. Orte
5. Ressourcen
6. Kategorien
7. Anhänge
8. Blockierungen
9. Rollen

Für `Calendar – Blockierungen` wird automatisch die Relation `calendar_block_category` zur Kategorie-DataForm erzeugt.

Wenn ein Projekt noch keine Kategorie besitzt, wird einmalig die Kategorie **Blockiert** angelegt.

## Standalone-Anmeldung

Das exportierte Calendar-Paket besitzt eine eigene, lokale Anmeldung. Sie ist unabhängig vom Enterprise-Manager.

Rollen:

- `admin`: Kalender, Termine, Blockierungen, Stammdaten, Benutzerverwaltung
- `editor`: Kalender, Termine, Blockierungen und Stammdaten
- `viewer`: lesender Kalenderzugriff

Beim ersten Start wird über `setup.php` der erste lokale Administrator angelegt. Für externe SQL-Datenquellen wird zuvor wie bisher `config.php` erzeugt.

Benutzer werden im exportierten Paket unter `storage/auth/users.json` mit `password_hash()` gespeichert. Die Datei wird erst bei der Installation beim Anwender erzeugt und ist nicht Bestandteil des Export-ZIPs.

## Standalone-Setup / Stammdaten

Die geschützte Seite `admin.php` erlaubt `admin` und `editor` die Pflege von:

- Kategorien
- Orten
- Ressourcen
- Personen

`admin` kann zusätzlich lokale Calendar-Benutzer mit den Rollen `admin`, `editor` und `viewer` anlegen.

## Export

Der HF126-Exporter liefert zusätzlich aus:

- `auth.php`
- `login.php`
- `logout.php`
- `admin.php`

Das Manifest trägt `runtime = HF126`, `categorized_blocks = true` und die Rollen `admin/editor/viewer`.
