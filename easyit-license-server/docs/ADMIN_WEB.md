# easyIT License/DataForm Server – Web-Lizenzverwaltung (HF80)

Die zentrale Lizenzverwaltung ist nach Installation unter folgendem Pfad erreichbar:

`https://<server>/<pfad>/easyit-license-server/admin/`

## Ersteinrichtung

Ab HF86 Phase 5 wird **kein Setup-Geheimnis mehr im ZIP ausgeliefert**. Existiert noch kein Administrator, erzeugt der Lizenzserver beim ersten Aufruf von `/admin/` lokal ein einmaliges 256-Bit-Setup-Token. Standardpfad ist `secure/admin-setup.token`. Der direkte HTTP-Zugriff auf `secure/` ist durch die Root-`.htaccess` gesperrt; zusätzlich legt der Bootstrap dort nach Möglichkeit eine lokale Deny-Regel an.

1. Serverpaket über den bestehenden Stand kopieren. `config/app.php` und vorhandene Server-Schlüssel nicht löschen.
2. `/admin/` einmal aufrufen. Dadurch wird das Token lokal erzeugt, sofern kein `admin.setup_token` explizit konfiguriert wurde.
3. `secure/admin-setup.token` **über das Server-Dateisystem bzw. den Hosting-Dateimanager** öffnen und den Token kopieren. Der Token wird nicht in der Webseite ausgegeben.
4. Token, Benutzername und starkes Kennwort eingeben.
5. Nach erfolgreicher Anlage des ersten Superadmins löscht der Server die Token-Datei automatisch.

Ist bereits eine Token-Datei vorhanden, aber ungültig bzw. kürzer als 32 Zeichen, wird sie aus Sicherheitsgründen nicht automatisch überschrieben. In diesem Fall muss sie administrativ entfernt oder korrigiert werden. Alternativ kann `admin.setup_token` bewusst außerhalb des Releasepakets konfiguriert werden.

## Funktionen

- Dashboard mit Kunden, aktiven Lizenzen, Installationen, belegten DataForms und aktiven Runtimes
- Kunden anlegen und bearbeiten
- Lizenzen anlegen und ändern
- einmalige Ausgabe des Aktivierungsschlüssels
- Aktivierungsschlüssel rotieren
- maximale Installationsanzahl
- maximale Anzahl verwendbarer DataForms (`max_dataforms`)
- Modulfreigaben
- CRUD- und weitere Capability-Freigaben
- DataForm-spezifische Einschränkungen, die globale Lizenzfreigaben nur reduzieren können
- belegte DataForm-Lizenzplätze anzeigen und freigeben
- Installationen aktivieren/sperren/stilllegen/ersetzen
- Audit der administrativen und sicherheitsrelevanten Vorgänge

## Sicherheitsregeln

- Admin-Sessions verwenden `HttpOnly`, `SameSite=Strict`, CSRF-Token und Session-ID-Rotation nach Login.
- Nach wiederholten Fehlanmeldungen wird das Administratorkonto zeitweise gesperrt.
- Kennwörter werden ausschließlich mit `password_hash()` gespeichert.
- Aktivierungsschlüssel werden nur einmal vollständig angezeigt; gespeichert wird lediglich der Hash des geheimen Anteils.
- Änderungen an Lizenz, Modulen, Capabilities, DataForm-Limits und Installationen erhöhen die relevante Generation bzw. werden beim nächsten Runtime-Renew wirksam.
- Die Webverwaltung liegt getrennt von `/api/v1/...`; Runtime-Credentials berechtigen nicht zum Admin-Login.
