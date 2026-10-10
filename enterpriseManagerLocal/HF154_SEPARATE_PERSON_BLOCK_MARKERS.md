# HF154 – Gleichzeitige personenbezogene Blockierungen getrennt kennzeichnen

## Ziel
Wenn mehrere Blockierungen denselben Zeitraum betreffen, wird jede Blockierung separat sichtbar. Dies ist notwendig, weil eine Blockierung entweder global (Alle Personen) oder einer konkreten Person zugeordnet sein kann.

## Darstellung
- Jeder im Slot wirksame Blockierungsdatensatz erhält eine eigene farbige Markierung.
- Personenbezogene Markierungen zeigen die Initialen der Person.
- Eine globale Blockierung zeigt `A` für „Alle Personen“.
- Die Farbe der Markierung entspricht weiterhin der Blockierungskategorie.
- Mehrere Blockierungen im gleichen 30-Minuten-Slot werden nebeneinander angezeigt und nicht mehr auf den ersten Datensatz reduziert.
- Tooltip und ARIA-Beschriftung nennen Person, Kategorie und optional den Grund.

## Bedienung
Im Blockierungsmodus kann jede einzelne Markierung angeklickt werden. Dadurch wird exakt der zugehörige Blockierungsdatensatz zum Bearbeiten oder Löschen geöffnet. Außerhalb des Blockierungsmodus zeigt ein Klick auf die Markierung die Informationen zu genau dieser Blockierung im Statusbereich an.

## Datenmodell
Keine Schemaänderung gegenüber HF153. Verwendet werden `blocked_ranges.person_id`, Kategorie und vorhandene Blockierungs-ID.
