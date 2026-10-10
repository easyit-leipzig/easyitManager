# easyIT License/DataForm Server – HF78

Dieser Server ist der geschützte Gegenpart zu `enterpriseManager RC1.8-FC1-HF78-LICENSE-POLICY` und darf **nicht** in das Kunden-/Enterprise-ZIP kopiert werden.

## Neu in HF78: DataForm-Anzahl und Funktionsumfang pro Lizenz

Jede Lizenz kann eine maximale Zahl aktiv verwendbarer DataForms und eine Capability-Whitelist erhalten. DataForm-Slots werden pro Kombination aus `project_id` + `dataform_id` gezählt. Wiederholte Runtime-Starts desselben DataForms benötigen keinen weiteren Slot.

Beispiel: Lizenz für maximal 10 DataForms, Lesen/Anlegen/Bearbeiten, Suche und Filter – aber kein Löschen:

```bash
php bin/admin.php license:create \
  --customer=CUS-... \
  --max-dataforms=10 \
  --modules=dataform,designer \
  --capabilities=dataset.read,dataset.create,dataset.update,dataform.search,dataform.filter,dataform.pagination,designer.validate,designer.compile,designer.publish
```

Bestehende Lizenz ändern:

```bash
php bin/admin.php license:policy --license=LIC-2026-... --max-dataforms=25 \
  --capabilities=dataset.read,dataset.create,dataset.update,dataset.delete,dataform.search,dataform.filter,dataform.pagination,relation.read,relation.createChild
```

Einzelne Funktion sperren/freigeben:

```bash
php bin/admin.php capability:set --license=LIC-2026-... --capability=dataset.delete --enabled=0
```

DataForm-spezifisch weiter einschränken:

```bash
php bin/admin.php dataform:capability:set --license=LIC-2026-... --project=17 --dataform=27 --capability=dataset.delete --enabled=0
```

Eine DataForm-spezifische Freigabe kann eine global verbotene Capability **nicht** erweitern.

Verwendete DataForms anzeigen bzw. Slot freigeben:

```bash
php bin/admin.php dataform:list --license=LIC-2026-...
php bin/admin.php dataform:release --license=LIC-2026-... --project=17 --dataform=27
```

Lizenzpolicy anzeigen:

```bash
php bin/admin.php license:policy:show --license=LIC-2026-...
```

## Capability-Gruppen

- CRUD: `dataset.read`, `dataset.create`, `dataset.update`, `dataset.delete`
- DataForm: `dataform.search`, `dataform.filter`, `dataform.pagination`
- Relationen: `relation.read`, `relation.createChild`, `relation.attach`, `relation.detach`
- Scripting: `dataform.events`, `dataform.custom_css`, `dataform.custom_javascript`
- Designer: `designer.validate`, `designer.compile`, `designer.publish`
- Module: `export.execute`, `api.access`

Die Lizenz ist immer eine Obergrenze. Lokale DataForm-Rechte können nur zusätzlich einschränken, niemals die Lizenz erweitern.

## Installation

1. `config/app.example.php` nach `config/app.php` kopieren und DB-DSN/Benutzer/Kennwort setzen.
2. Sodium aktivieren. Der Server erzeugt das Ed25519-Schlüsselpaar beim ersten Start automatisch. `php bin/generate-server-key.php` ist nur noch optional für eine explizite Vorab-Erzeugung.
3. `php bin/migrate.php` ausführen.
4. Mit `php bin/admin.php ...` Kunde/Lizenz/Module/Policy anlegen.
5. Webroot des virtuellen Hosts ausschließlich auf `public/` setzen.
6. Öffentlichen Server-Key und Key-ID im Enterprise-Superadmin unter **Lizenzen → DataForm-Lizenzserver / Aktivierung** hinterlegen.

MySQL/MariaDB-Verbindungen setzen `utf8mb4_unicode_ci` für die Connection.

## HF79: Shared-Hosting-/Unterordner-Betrieb

Ab HF79 besitzt der Server zusätzlich einen geschützten Root-Front-Controller. Eine Installation unter z. B. `/enterpriseManager/easyit-license-server/` funktioniert damit ohne separaten DocumentRoot auf `/public`, sofern Apache `.htaccess`/mod_rewrite zulässt. Der API-Pfad wird intern auf `/api/v1/...` normalisiert, sodass die Ed25519-Request-Signatur des Enterprise-Clients auch bei Unterordner-URLs korrekt bleibt. Details: `docs/INSTALLATION_SHARED_HOSTING.md`.

## HF80 – Zentrale Web-Lizenzverwaltung

Die zentrale Verwaltung ist unter `/admin/` erreichbar. Beim ersten Aufruf wird ein Superadmin eingerichtet; der dafür benötigte einmalige Token liegt in `secure/admin-setup.token` und wird nach erfolgreicher Einrichtung automatisch gelöscht.

Verwaltet werden Kunden, Lizenzen, maximale Installationen, maximale Anzahl verwendbarer DataForms, Module, CRUD-/DataForm-/Designer-Capabilities, DataForm-spezifische Einschränkungen, Installationen, belegte DataForm-Lizenzplätze und Audit-Ereignisse. Siehe `docs/ADMIN_WEB.md`.


## HF113 – vollständige Schema-Provisionierung

Der Setup-Assistent provisioniert nun den vollständigen Lizenzserver-Tabellenvertrag und setzt die Setup-Sperre erst nach einem erfolgreichen 100%-Schema-Gate. Auf lokalen Installationen kann das Schema über `/setup/` idempotent geprüft und repariert werden. Details: `docs/HF113_FULL_SCHEMA_SETUP.md`.
