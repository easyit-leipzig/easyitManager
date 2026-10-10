# HF104 – Calendar-Termindialog auf DataForm

- Calendar Setup besitzt einen Bereich „Termindialog (DataForm)“.
- Ein vorhandenes DataForm kann als Termindialog ausgewählt werden.
- Feldreihenfolge, Labels, Pflicht/Read-only, Lookups, Relationen und Validierung werden im DataForm konfiguriert.
- Klick auf einen freien Slot öffnet das DataForm als eingebetteten Einzeldatensatz im Create-Modus und übergibt Datum/Beginn/Ende als Defaults.
- Klick auf einen Termin öffnet dasselbe DataForm für den vorhandenen Datensatz.
- Der bisherige hart codierte Calendar-Dialog bleibt nur als Fallback aktiv, solange kein Termindialog-DataForm ausgewählt wurde.
- DataForm records.php ist für Calendar-Projekte über die Calendar-Lizenz zugänglich.
