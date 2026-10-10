# HF112 – Installer Admin-Schema Gate

HF112 repariert den Übergang von Setup-Schritt 6 zu Schritt 7.

## Fehlerbild

Schritt 7 konnte bisher geöffnet werden, obwohl die Tabelle `users` im Administrationsspeicher fehlte. Ein vorhandener Migrationseintrag bzw. eine gesetzte Session-Markierung war damit stärker als der reale Datenbankzustand.

## Neuer Vertrag

Nach der Schema-Installation prüft Schritt 6 zwingend die realen Pflichttabellen:

- `migrations`
- `roles`
- `users`
- `user_roles`
- `projects`
- `audit_log`
- `enterprise_licenses`

Fehlen Basistabellen, werden ausschließlich die idempotenten Basismigrationen 001–004 gezielt erneut ausgeführt. Anschließend wird erneut geprüft. Erst nach PASS kann der Installationsablauf fortgesetzt werden.

Schritt 7 besitzt dasselbe Gate. Wird die Seite aus einem alten oder abgebrochenen Installationsstand direkt geöffnet, versucht sie die Basisschicht einmal automatisch zu reparieren und bricht andernfalls mit einer konkreten Liste der fehlenden Tabellen ab.

Die Prüfung verwendet keine MySQL-spezifische `INFORMATION_SCHEMA ... DATABASE()`-Abfrage mehr, sondern funktioniert mit den vom Manager unterstützten Adminspeichern.
