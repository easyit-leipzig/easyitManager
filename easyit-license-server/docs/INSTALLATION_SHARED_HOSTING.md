# Installation auf Shared Hosting / Unterordner (HF79)

Diese Variante ist für Webspaces gedacht, bei denen kein eigener Apache-DocumentRoot auf `public/` gesetzt werden kann.

## Verzeichnis

Den Inhalt des Ordners `easyit-license-server` vollständig z. B. hier ablegen:

`/enterpriseManager/easyit-license-server/`

Danach muss der Aufruf

`https://<host>/enterpriseManager/easyit-license-server/`

die Betriebsseite **easyIT License/DataForm Server – Web-Einstieg erreichbar** anzeigen.

Die Enterprise-Konfiguration verwendet genau diese URL **ohne** `/public` und **ohne** `/api/v1`:

`https://<host>/enterpriseManager/easyit-license-server`

Der Client ergänzt selbst `/api/v1/...`.

## Schutz

Die Root-`.htaccess` sperrt direkte HTTP-Zugriffe auf `src`, `config`, `bin`, `gates`, `docs`, `secure`, `storage`, `bootstrap.php` und die README-/Versionsdateien. API-Routen werden intern auf `index.php` umgeschrieben.

## Test

1. Root-Seite öffnen.
2. `.../api/v1/health` aufrufen.
3. Wenn `SERVER_NOT_CONFIGURED` erscheint, zuerst `config/app.example.php` nach `config/app.php` kopieren und DB-/Key-Einstellungen vornehmen.
4. Danach Migration und Key-Erzeugung über CLI ausführen, sofern SSH/CLI vorhanden ist.

Wenn der Hoster `.htaccess` oder URL-Rewriting verbietet, muss für den API-Betrieb ein Webroot/Alias eingerichtet werden; nur der Root-Aufruf reicht dann nicht aus.
