HF86 Phase 1 – Live License Surface
===================================

Ziel
----
Die Enterprise-Kundenoberfläche wird bei jedem authentifizierten Aufruf aus der aktuell zentral vergebenen Lizenz neu abgeleitet. Eine Superadmin-Änderung an Lizenzstatus, Modulen oder Capabilities benötigt keine erneute Kundenanmeldung.

Umgesetzt
---------
- Live account/profile-Synchronisation im zentralen Authentifizierungsweg.
- Sofortiger Logout/Fail-closed bei gesperrter, abgelaufener oder widerrufener Lizenz bzw. ungültiger Kundensitzung.
- Kundenberechtigungen werden aus Modulen und Capabilities neu aufgebaut.
- DataForm-Projektoberflächen und Direkt-URLs verlangen das DataForm-Modul.
- CRUD, Suche, Filter, Pagination, Relationen, Events, Custom CSS/JS, Designer, Export und API werden serverseitig und in der Oberfläche berücksichtigt.
- Nicht lizenzierte DataForm-Einstellungen werden bei vorübergehendem Lizenzentzug nicht gelöscht, sondern nur deaktiviert/ausgeblendet.
- REST API Runtime prüft pro Request DF-Token, api.access und die zur Operation passende CRUD-Capability.
- CSV-Import prüft DF-Token und CRUD-Capabilities.
- Visual Queries und Report-Datenquellen prüfen die individuelle DF-Lizenz.
- Application-Build und Projektpaket-Export/-Download sind an Exportmodul + export.execute gebunden.
- relation.createChild und dataform.custom_javascript werden auch im Record-Runtimepfad erzwungen.

Nicht Bestandteil dieser Phase
------------------------------
- Feingranulare kundenindividuelle Rollen innerhalb eines Mandanten.
- Offline-Lease-/Grace-Strategie für die Manager-Policy-Synchronisation.
- Erweiterte Lizenzänderungs-Historie/Benachrichtigung in der Kundenoberfläche.
