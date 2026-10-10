# HF123 – Calendar Dialog Close & Lookup Synchronization

## Ziel
HF123 behebt zwei konkrete Fehler des Calendar/DataForm-Dialogs:

1. Der X-Schalter im Calendar-Termindialog reagierte nicht, während Escape den nativen HTML-Dialog schließen konnte.
2. Calendar-Zuordnungen vom Typ `lookup` wurden nicht zuverlässig als Auswahlfelder im eingebetteten Termin-DataForm sichtbar.

## Ursache X-Schalter
`calendar.js` wird vom Bootstrap nach dem asynchronen Laden von `init.json` dynamisch eingebunden. War `DOMContentLoaded` zu diesem Zeitpunkt bereits ausgelöst, wurde der ausschließlich an dieses Ereignis gebundene Click-Handler für `#closeDataFormLesson` nie registriert.

HF123 bindet den Handler deshalb sofort, wenn das DOM bereits bereit ist, und ansonsten einmalig über `DOMContentLoaded`. Zusätzlich existiert ein delegierter Fallback-Handler. Beim Schließen wird das iframe auf `about:blank` gesetzt, damit kein alter Datensatz im Hintergrund aktiv bleibt.

## Lookup-Vertrag
Die dynamische Calendar-Zuordnung ist nun die maßgebliche Quelle für Termin-Lookups. Für jedes Mapping mit `kind=lookup` wird eine aktive n:1-Relation synchronisiert.

Beispiel:

`events.location -> places.id`, Anzeige `places.name`

Im DataForm-Dialog wird `events.location` dadurch automatisch als `<select>` gerendert. Gleiches gilt für Ressource, Kategorie und zusätzliche benutzerdefinierte Calendar-Lookups.

Wenn eine Ziel-Tabelle ein gebundenes DataForm besitzt, wird eine DataForm-n:1-Relation erzeugt bzw. aktualisiert. Existiert kein passendes DataForm oder wird ein physisches Schlüsselfeld verwendet, das nicht als DataForm-Feld modelliert ist, wird eine Base-Table-Lookup-Relation verwendet.

## Bestandsmigration
Bereits vorhandene Calendar-Relationen werden beim normalen Calendar-Start neu synchronisiert. Insbesondere wird `render_as_lookup=false` für Calendar-gesteuerte Lookups auf die gewünschte Lookup-Darstellung zurückgeführt.

Beim Speichern der Calendar-Zuordnungen erfolgt die Synchronisierung sofort; ein erneutes Projektsetup ist nicht zwingend erforderlich.
