# HF145 – Schülerfarbe an Terminen

## Ziel
Jeder Schüler kann in `Calendar – Personen` eine optionale `Terminfarbe` erhalten. Die Farbe wird nicht als Terminfarbe gespeichert, sondern aus der Personenzuordnung des Termins gelesen.

## Darstellung
Die Farbe markiert ausschließlich ein 18×18-Pixel-Dreieck in der rechten unteren Ecke der Terminkachel. Die vorhandene Hintergrund-, Kategorie-, Status- und Blockierungsdarstellung bleibt unverändert. Ohne gültige Farbe (`#RRGGBB`) bleibt die Ecke transparent.

## Datenmodell
`persons.calendar_color` (`CHAR/VARCHAR(7)`, optional). Bestehende Projekt-Datenbanken werden beim Calendar/DataForm-Ensure automatisch erweitert. CSV-Projekte erhalten die zusätzliche Spalte über `addDataColumn`.

## Manager und Export
`calendar_handler.php` liefert `student_color` aus der Farbe des zugeordneten Schülers. Der Standalone-Runtime löst zusätzlich `participants` und `persons` auf, sodass dieselbe Darstellung auch im HTML-/Standalone-Export funktioniert.
