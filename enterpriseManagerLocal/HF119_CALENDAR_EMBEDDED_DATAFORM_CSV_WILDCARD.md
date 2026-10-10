# HF119 – Calendar Embedded DataForm / CSV Wildcard Repair

HF119 behebt zwei Fehler des kombinierten easyITCalendar/DataForm-Betriebs bei CSV-Projekten.

## 1. CSV-Wildcards und Binding-JOIN

Der CSV-PDO-Adapter behandelt `*` und qualifizierte Wildcards wie `b.*` bzw. `d.*` jetzt als Projektionen und nicht als SQL-Bezeichner. Zusätzlich wird der vom DataForm-Workspace verwendete Join aus `dataform_table_bindings`, `dataforms` und optional `data_sources` im CSV-Backend explizit emuliert. Dadurch funktionieren insbesondere die Binding-Kataloge des DataForm-Workspaces und `DataFormManager::tableBinding()` ohne `Ungültiger CSV-SQL-Bezeichner: *`.

## 2. Calendar-interne DataForms

Die automatisch erzeugten Calendar-DataForms sind Teil des lizenzierten Calendar-Produkts und belegen keinen separaten individuellen DataForm-Lizenzplatz. Sie erhalten den lokalen Status `embedded_calendar` und werden von der zentralen DataForm-Registrierung übersprungen. Frühere Zustände wie `unlicensed` oder `registration_error` werden beim Öffnen eines Calendar-Projekts automatisch korrigiert.

Der DataForm-Workspace zeigt dafür `Calendar-intern` an. Beim Löschen eines solchen Formulars wird kein zentraler DataForm-Lizenzplatz freigegeben, weil zuvor auch keiner belegt wurde.

## Regression

Die bestehenden Gates für Calendar/DataForm, CSV-Relations, Audit-Aktor, Installer-Schema, Lizenzserver-Trust und Admin-Datenbank bleiben grün.
