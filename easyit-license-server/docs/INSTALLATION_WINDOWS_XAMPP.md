# Installation unter Windows/XAMPP

## 1. Lizenz-/DataForm-Server

Kopiere `easyit-license-server` z. B. nach `D:\xampp\htdocs\easyit-license-server`. Für Produktion muss der Apache-Webroot direkt auf `easyit-license-server\public` zeigen. `secure` und `src` dürfen nicht direkt per HTTP erreichbar sein.

Kopiere `config\app.example.php` nach `config\app.php` und trage MariaDB/MySQL oder PostgreSQL ein. Danach:

```bat
cd /d D:\xampp\htdocs\easyit-license-server
REM Optional: php bin\generate-server-key.php  (sonst automatische Erzeugung beim ersten Serverstart)
php bin\migrate.php
```

### Produktive Lizenz anlegen

```bat
php bin\admin.php customer:create --name="Muster GmbH" --email="admin@example.invalid"
php bin\admin.php license:create --customer=CUS-... --modules=dataform,designer --max-installations=3 --days=365
```

Der vollständige `license_key` wird nur bei `license:create` ausgegeben. Der Server speichert nur den Hash des geheimen Anteils.

Weitere Beispiele:

```bat
php bin\admin.php license:list
php bin\admin.php installation:list --license=LIC-2026-...
php bin\admin.php license:status --license=LIC-2026-... --status=suspended
php bin\admin.php installation:status --installation=INST-... --status=blocked
php bin\admin.php module:set --license=LIC-2026-... --module=designer --enabled=0
```

Nur für einen lokalen Funktionstest existiert zusätzlich `php bin\seed-dev-license.php`.

## 2. Enterprise-Integration

```bat
php enterprise-integration\tools\apply_to_enterpriseManager.php --root=D:\xampp\htdocs\enterpriseManager
```

Der Installer legt vor Änderung von `products\dataform\records.php` automatisch eine `.pre-license-YYYYMMDD-HHMMSS.bak` an und kopiert den lokalen Lizenz-/Runtime-Client nach `system\licensing`.

Danach `D:\xampp\htdocs\enterpriseManager\config\licensing.php` bearbeiten:

- `server_url`
- `trusted_server_keys[SERVER-2026-01]`

Der Enterprise-Manager lädt den Public Key über **„Server prüfen & Schlüssel laden“** automatisch. Alternativ kann er weiterhin aus `secure\keys\server-public.key` übernommen werden.

Aktivierung:

```bat
php D:\xampp\htdocs\enterpriseManager\tools\activate_license.php --root=D:\xampp\htdocs\enterpriseManager --license=LIC-2026-....<secret>
```

## 3. DataForm-Revision veröffentlichen

Der zentrale Runtime-Server startet nur veröffentlichte Definitionen. Eine JSON-Definition kann mit dem mitinstallierten Tool validiert, kompiliert und veröffentlicht werden:

```bat
php D:\xampp\htdocs\enterpriseManager\tools\publish_dataform_definition.php ^
  --root=D:\xampp\htdocs\enterpriseManager ^
  --project=1 ^
  --dataform=1 ^
  --definition=D:\temp\dataform-1.json
```

Die Designer-API besitzt getrennte Schritte `validate`, `compile`, `publish` und führt serverseitig Revisionen.

## 4. Offline-/Ausfallverhalten

Nach erfolgreichem `runtime/start` wird ein signierter Runtime-Cache lokal gespeichert. Bei einem reinen Verbindungsfehler darf eine bereits signierte Runtime bis `lease_until` als `leased` und danach bis `grace_until` als `grace` geladen werden. Schreibaktionen benötigen dennoch den zentralen `runtime/action`-Endpunkt und werden bei Serverausfall nicht ungeprüft lokal freigegeben. Eine explizite signierte Sperr-/Lizenzentscheidung darf nicht durch einen alten Cache überschrieben werden.

## 5. Gates

```bat
php gates\PACKAGE_SELFTEST.php
php gates\RUNTIME_UNIT_GATE.php
php gates\DATAFORM_LICENSE_SECURITY_GATE.php --enterprise=D:\xampp\htdocs\enterpriseManager --server=D:\xampp\htdocs\easyit-license-server
```

## 6. Rollback des lokalen Patches

Die vom Patch-Installer erzeugte `.pre-license-...bak` kann zurückkopiert werden. Der Server-Core ist ein eigenständiges Projekt und wird nicht in das Kundenpaket aufgenommen.
