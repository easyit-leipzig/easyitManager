# HF86 Phase 6 – Kundenkennwort und Sitzungswiderruf

Diese Phase schließt den Kennwort-Lebenszyklus für zentrale Kundenkonten.

- Eigener Kennwortwechsel über `/api/v1/account/password/change` mit Prüfung des aktuellen Kennworts.
- Administrativer Kennwort-Reset über `/api/v1/account/team/user/password-reset`, begrenzt durch `account.users.manage`.
- Superadmin-Reset in der Lizenzserver-Kundenverwaltung.
- Jeder Kennwortwechsel erhöht `customer_users.credential_version`.
- Alle aktiven `customer_account_sessions` des betroffenen Benutzers werden sofort auf `revoked` gesetzt.
- Die ältere `/customer/`-Session prüft `credential_version` und Benutzer-/Kundenstatus bei jedem Request. Dadurch verlieren parallele alte Portal-Sessions ihre Gültigkeit. Die Session, in der der Benutzer sein Kennwort selbst ändert, übernimmt die neue Credential-Version.
- Kennwortregeln bleiben mindestens 10 Zeichen, mindestens ein Kleinbuchstabe, ein Großbuchstabe und eine Ziffer.

Die Phase verändert keine Lizenz-, DataForm-, Rollen- oder Ressourcenzuordnungssemantik.
