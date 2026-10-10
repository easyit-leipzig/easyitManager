# HF125 – Unified Local Admin Login

## Fehlerbild
Die öffentliche Startseite `login.php` verlangte für das einzige sichtbare Anmeldefeld HTML-seitig zwingend `type="email"`. Ein im Installer angelegter lokaler Superadministrator mit Benutzername `admin` konnte diese Maske deshalb nicht absenden. Außerdem wurde der Lizenzserver bereits vor der Unterscheidung zwischen Kunden- und lokaler Administratoranmeldung vorausgesetzt.

## Korrektur
Die Hauptanmeldung akzeptiert nun zwei eindeutig getrennte Identitäten:

- Kunden: Kunden-E-Mail-Adresse. Authentifizierung ausschließlich über den Lizenzserver.
- Lokale Administratoren/Superadministratoren: lokaler Benutzername. Authentifizierung direkt gegen die lokale Admin-Control-Plane (`users`, `roles`, `user_roles`).

Ein Eingabewert, der keine gültige E-Mail-Adresse ist, wird als lokaler Admin-Benutzername behandelt. Dadurch kann der frisch angelegte Superadmin beispielsweise mit `admin` angemeldet werden, auch bevor eine Kundenlizenz existiert.

Die separate Seite `admin-login.php` bleibt als direkter technischer Einstieg erhalten und unterstützt weiterhin Benutzername oder lokale Admin-E-Mail.

## Sicherheitsvertrag
Eine Kunden-E-Mail wird niemals gegen lokale Admin-Konten geprüft. Ein nicht als E-Mail interpretierter Bezeichner wird niemals an den Lizenzserver gesendet. Lokale Konten benötigen die Rolle `admin` oder `superadmin`.
