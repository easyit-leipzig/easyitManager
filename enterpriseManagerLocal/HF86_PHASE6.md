# HF86 Phase 6 – Kennwort-Lebenszyklus im Enterprise-Manager

- „Mein Konto“ besitzt einen zentralen Kennwortwechsel.
- Das aktuelle Kennwort wird serverseitig geprüft; neue Kennwörter müssen mindestens 10 Zeichen, Groß-/Kleinbuchstaben und eine Ziffer enthalten.
- Nach erfolgreichem Eigenwechsel werden alle zentralen Account-Sitzungen widerrufen und der Enterprise-Manager verlangt eine neue Anmeldung.
- Benutzer mit `customer.users.manage` können für andere Kundenbenutzer ein neues Kennwort setzen.
- Der Reset ist serverseitig mandantengebunden und beendet bestehende Sitzungen des Zielbenutzers.
- Selbst-Reset über die Teamverwaltung ist nicht zulässig; dafür dient „Mein Konto“.

Die Phase verändert keine Lizenz-, DataForm-, Rollen- oder Ressourcenzuordnungen.
