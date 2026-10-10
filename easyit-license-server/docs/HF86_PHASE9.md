# HF86 Phase 9 – Kundeninventar für Installationen und DataForms

Phase 9 erweitert ausschließlich den lesenden Kundenbereich. `POST /api/v1/account/profile` liefert für die aktuell gebundene Lizenz zusätzlich die registrierten Installationen, die Installationsbelegung sowie die zentral registrierten DataForms.

Installationen werden mit Installation-ID, Produktcode, Produktversion, Status, Aktivierungszeit und letztem Kontakt ausgegeben. Geheimnisse wie Installationsschlüssel, Public Keys und Environment-Hash gehören nicht zum Account-Profil. Die Belegung wird als `used_installations`, `max_installations` und `available_installations` ausgewiesen.

DataForms werden mit UUID, Projekt-ID, lokaler DataForm-ID, Name, Registry-Status, Installation, Registrierungs-/Kontaktzeit, Lizenzplatzstatus und einem abgeleiteten Tokenstatus ausgegeben. Der eigentliche signierte DataForm-Token wird niemals in der Kontoübersicht übertragen. `token_status=valid` bedeutet, dass Registrierung, Lizenzplatz und gebundene Installation aktuell einen gültigen beziehungsweise erneut ausstellbaren Token zulassen; der Server behauptet damit nicht, dass eine bestimmte lokale Tokenkopie noch vorhanden ist.

Die in HF86 Phase 4 eingeführten Ressourcenzuordnungen bleiben wirksam. Uneingeschränkte Benutzer sehen den vollständigen DataForm-Bestand der Lizenz. Beschränkte Benutzer sehen nur DataForms aus vollständig zugeordneten Projekten oder explizit freigegebene DataForm-UUIDs. Installationen bleiben reine Lizenz-/Kontoinformation und sind in Phase 9 nicht bearbeitbar.

Der Enterprise-Manager zeigt die Informationen unter `Mein Konto`. Phase 9 führt ausdrücklich keine Stilllegung, Ersetzung oder Freigabe von Installationen ein; dieser Lebenszyklus bleibt HF86 Phase 10.
