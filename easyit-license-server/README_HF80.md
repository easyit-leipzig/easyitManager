# HF80 – Zentrale Web-Lizenzverwaltung

Stand: `RC1.8-FC1-HF80-LICENSE-WEB-ADMIN`

## Update von HF79

Den bestehenden Serverordner **nicht löschen**. Das HF80-Paket über den vorhandenen `easyit-license-server` kopieren/entpacken. Dadurch bleiben `config/app.php` sowie vorhandene Schlüssel unter `secure/keys/` erhalten.

Neu ist die zentrale Webverwaltung unter:

`https://<host>/<pfad>/easyit-license-server/admin/`

Beim ersten Aufruf wird die Ersteinrichtung angezeigt. Den Token aus `secure/admin-setup.token` eingeben, einen Superadmin anlegen und anmelden. Nach erfolgreicher Einrichtung löscht der Server die Setup-Token-Datei automatisch.

## Lizenzmaske

Pro Lizenz können u. a. gesetzt werden:

- Status und Ablaufdatum
- maximale Installationen
- maximale Anzahl verwendbarer DataForms (`max_dataforms`)
- Lease- und Grace-Zeit
- Module (`dataform`, `designer`, `export`, `api`, `themes`)
- CRUD (`dataset.read`, `dataset.create`, `dataset.update`, `dataset.delete`)
- Suche, Filter, Paginierung
- Relationen
- Events, Custom CSS, Custom JavaScript
- Designer-Validierung, Kompilierung und Veröffentlichung
- Export und API

Bereits belegte DataForms werden pro Lizenz angezeigt. Für jedes belegte DataForm können Funktionen weiter eingeschränkt werden. Eine DataForm-spezifische Freigabe kann eine global gesperrte Capability nicht wieder aktivieren.

## Sicherheit

- Admin-Login ist vollständig von der Runtime-API getrennt.
- CSRF-Schutz für alle POST-Aktionen einschließlich Login/Setup.
- `HttpOnly`, `SameSite=Strict`, Session-ID-Rotation.
- Fehlanmeldungen führen temporär zur Kontosperre.
- Aktivierungsschlüssel werden nur einmal vollständig angezeigt.
- Der Setup-Token liegt im per `.htaccess` geschützten `secure/`-Verzeichnis und wird nach der Ersteinrichtung gelöscht.
- Der private Server-Signaturschlüssel ist nicht Bestandteil des Pakets.
