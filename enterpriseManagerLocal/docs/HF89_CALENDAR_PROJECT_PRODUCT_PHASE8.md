# HF89 Phase 8 – easyITCalendar als Projektprodukt

- easyITCalendar ist im lokalen Produktregister als `calendar` verfügbar.
- Die Projektanlage und Registrierung verwenden keine fest codierte DataForm-Auswahl mehr.
- Angeboten werden lokal installierte, verfügbare und für den Benutzer freigegebene Projektprodukte.
- Bestehende DataForm-Projekte bleiben unverändert.
- Der zentrale Produktloader kann Calendar-Projekte öffnen und prüft Projekt-Scope sowie Produktfreigabe.
- Das Calendar-Paket liegt unter `products/calendar/`; `.git` wird nicht ausgeliefert.
- Die Calendar-Datenpersistenz wird in der nächsten Integrationsphase vollständig auf den projektbezogenen DB-/Storage-Adapter umgestellt; Phase 8 beseitigt zunächst die fehlerhafte Produkt-/Projekt-Hardcodierung.
