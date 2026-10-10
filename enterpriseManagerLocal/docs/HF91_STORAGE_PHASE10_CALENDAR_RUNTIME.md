# HF91 – Storage Phase 10: Calendar Runtime

Die easyITCalendar-Runtime verwendet keine eigene fest codierte MariaDB-Verbindung mehr. Der Projektkontext wird aus der authentifizierten Enterprise-Sitzung übernommen und die Verbindung über `enterprise_project_store_for_project()` geöffnet.

Das Calendar-Setup kann die benötigten fachlichen Tabellen/CSV-Collections initialisieren und deren Zustand prüfen. Die logischen Objekte `events`, `participants`, `persons`, `resources`, `categories` und `attachments` folgen den im Calendar-Projekt konfigurierten Zuordnungen. CSV wird über die Enterprise CSV-Engine betrieben; SQLite, MySQL/MariaDB, PostgreSQL sowie die vorhandenen Enterprise-Treiber werden über die zentrale Projekt-DB-Schicht angebunden.

Das Runtime-Gate verhindert insbesondere die Wiedereinführung einer fest codierten `mysql:host`-Verbindung in `products/calendar/api/db.php`.
