HF86 Phase 2 – DataForm Capability Overrides
============================================

Ziel
----
Jedes zentral freigegebene DataForm kann innerhalb der Kundenlizenz individuell weiter eingeschränkt werden. Die wirksame Berechtigung ist immer die Schnittmenge aus globaler Kundenlizenz und DataForm-Regel. Eine DataForm-Regel darf eine global gesperrte Funktion niemals wieder aktivieren.

Server
------
- Der Superadmin erreicht bei jedem approved DataForm den Bereich „DF-Regeln“.
- DataForm-Regeln können nur für eine HF85-Registry-Identität mit Status approved gespeichert werden.
- Die bestehende Tabelle license_dataform_capabilities wird als Reduktionsschicht weiterverwendet.
- Änderungen erhöhen die Lizenzgeneration und werden auditiert.
- POST /api/v1/dataforms/policy liefert die aktuell wirksame Capability-Menge.
- Der Policy-Endpunkt verlangt Installation, Surface Policy und den signierten individuellen DF-Token.
- Ein globales Verbot kann durch einen DataForm-Override nicht erweitert werden.

Enterprise-Manager
------------------
- EnterpriseDataFormPolicyBridge lädt die zentrale Policy des ausgewählten approved DataForms mit dessen DF-Token.
- CRUD, Suche, Filter, Pagination, Relationen, Events, Custom CSS/JS, Designer, Export und API werden aus der individuellen DF-Policy abgeleitet.
- Beim Wechsel des DataForms werden die sichtbaren Workspace-Funktionen entsprechend neu aufgebaut.
- DataForms ohne dataset.read zeigen keine Datensatz-/Realvorschau-Zugänge.
- Designer-Zugriffe werden pro DataForm geprüft.
- API-Endpunkte berücksichtigen api.access und die zur Operation gehörende CRUD-Capability des gewählten DataForms.
- Relationsänderungen verlangen relation.attach bzw. relation.detach für alle beteiligten DataForms.
- Report-Export verlangt export.execute für das DataForm der Berichtsquelle.
- Projektpaket-Export verlangt export.execute für jedes ausgewählte DataForm.
- Application-Build wird blockiert, wenn ein enthaltenes DataForm nicht approved ist oder export.execute nicht besitzt.

Vererbungsregel
---------------
Keine DF-Regel vorhanden:
    wirksam = Kundenlizenz

DF-Regel vorhanden:
    wirksam = Kundenlizenz ∩ DataForm-Regel

Globale Sperre:
    bleibt immer gesperrt

Nicht Bestandteil dieser Phase
------------------------------
- Rollen-/Benutzerrechte innerhalb desselben Kundenkontos.
- Zeitlich befristete Capability-Overrides pro DataForm.
- Kontingente einzelner Funktionen pro DataForm (z. B. Exportanzahl/API-Requests).
