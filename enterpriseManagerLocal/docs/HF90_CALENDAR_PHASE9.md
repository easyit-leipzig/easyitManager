# HF90 – Calendar Phase 9

Calendar-Projekte besitzen eine eigene Setup-Seite für zentrale Projektdatenquelle, Datenpflege, fachliche Zuordnungen, Kalenderparameter und Auslieferung. Die Konfiguration wird projektbezogen unter `storage/projects/<storage_key>/documents/calendar/calendar-project.json` gespeichert.

Bei CSV-Projekten ist `csv-engine` eine technische Pflichtabhängigkeit und wird zusammen mit dem projektbezogenen CSV-Storage in das Calendar-Auslieferungspaket aufgenommen. Bei anderen externen Datenquellen wird die DB-Engine als Laufzeitabhängigkeit paketiert. Das Setup besitzt einen READY/SETUP-Healthcheck.
