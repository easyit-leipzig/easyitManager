# HF124 – Calendar Standalone Export

HF124 behebt zwei zusammenhängende Fehler des Calendar-Anwenderpakets.

Erstens basierte das Export-Gate auf teilweise veralteten IDs aus `calendar-project.json`. Dadurch konnte ein real vollständig provisioniertes Calendar-Projekt mit `Calendar-Setup ist nicht READY` blockiert werden. Vor dem Export werden Schema, acht eingebettete Calendar-DataForms, Termindialog und Lookup-Relationen jetzt gegen den realen Projektspeicher synchronisiert. Der Readiness-Status wird anschließend aus den real vorhandenen Tabellen und Bindings bestimmt. Falls weiterhin etwas fehlt, nennt die Fehlermeldung die konkrete fehlende Prüfung.

Zweitens war das bisherige Paket nicht wirklich eigenständig: die exportierte `products/calendar/index.php` suchte über `dirname(__DIR__,2)` den Enterprise-Bootstrap außerhalb des Exportordners. HF124 exportiert deshalb eine separate Standalone-Runtime. Sie besitzt eigene Einstiegspunkte `index.php`, `api.php`, `dataforms/term.php` und eine eingebettete DataForm-Runtime. Der exportierte Einstieg enthält weder `enterprise_require_auth()` noch eine Abhängigkeit von `/system/app/bootstrap.php` des Enterprise-Managers.

Für CSV-Projekte wird der reale Projektordner nach `storage/csv` übernommen und `config.php` fertig erzeugt. Die Standalone-Runtime enthält den CSV-PDO-Adapter und dessen DataForm-Datenbankkern. SQLite wird ebenfalls mit lokaler Datenbank ausgeliefert. Bei externen SQL-Datenbanken werden aus Sicherheitsgründen keine Zugangsdaten exportiert; dort wird nur `config.php.example` ausgeliefert und die lokale `setup.php` dient zur Eingabe der Verbindungsdaten.

Der Termin-Dialog verwendet weiterhin das konfigurierte eingebettete Termin-DataForm. Die Standalone-DataForm-Runtime unterstützt die Calendar-n:1-Relationen als Lookup-Auswahlfelder, `runtime_view=form`, Calendar-Defaults für Beginn/Ende sowie das `postMessage`-Signal nach Speichern/Löschen, damit die Calendar-Ansicht aktualisiert werden kann.

Für einen bestehenden HF123-Stand genügt das HF124-Update. Danach das Calendar-Projekt nicht neu anlegen, sondern den Anwenderpaket-Export erneut starten. Ein manuelles Setup-READY ist nicht mehr nötig, sofern Schema und Bindings real vollständig provisionierbar sind.
