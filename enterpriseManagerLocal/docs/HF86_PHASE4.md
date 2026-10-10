# HF86 Phase 4 – Projekt- und DataForm-Zuordnungen

Die zentrale Kunden-RBAC aus HF86 Phase 3 wird um einen Ressourcenumfang erweitert. Rollen können auf vollständige Projekte und/oder einzelne freigegebene DataForms begrenzt werden. Benutzer können den aus ihren Rollen entstehenden Umfang zusätzlich einschränken, aber niemals erweitern.

Wirksam ist damit:

`Kundenlizenz ∩ DataForm-Regel ∩ Benutzerrolle ∩ Ressourcenzuordnung`

Bestehende Rollen bleiben nach dem Update zunächst uneingeschränkt; bestehende Benutzer übernehmen ihre Rollenzuordnung. Erst eine explizite Umstellung auf „nur ausgewählte Ressourcen“ schränkt den Zugriff ein.

Der Enterprise-Manager filtert Projekt- und DataForm-Listen, prüft direkte URLs und schützt Runtime/Designer sowie API-, Report-, Query- und Relationswege. Eine einzelne DataForm-Zuordnung macht das zugehörige Projekt nur als Container sichtbar. Projektweite Änderungen wie Tabellen, Datenquellen, Projektbearbeitung, Export, Application Builder und Projektpakete benötigen weiterhin die vollständige Projektzuordnung.
