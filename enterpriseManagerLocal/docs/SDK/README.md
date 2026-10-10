# easyIT Enterprise SDK

Die SDK-Dokumentation ist der zentrale Einstieg für eigene Enterprise-Module.

## Schnellstart

```bash
php easyit make:module Inventory --namespace=EasyIT\\Modules\\Inventory
php easyit make:crud Product --module=inventory
php easyit module:validate inventory
php easyit module:package inventory
```

## Entwicklungszyklus

1. Modul erzeugen.
2. Provider, Events, Listener, Jobs, Commands, APIs oder CRUD-Artefakte ergänzen.
3. Modul mit `module:validate` prüfen.
4. Gesamttests mit `quality:center --group=sdk` ausführen.
5. Installationspaket mit `module:package` erzeugen.

## Dokumentation

- `MODULE_ANATOMY.md` – verbindliche Modulstruktur
- `CLI_REFERENCE.md` – SDK-Kommandos
- `LIFECYCLE.md` – Install/Update/Enable/Disable/Uninstall
- `QUALITY_GATE.md` – Validierung und Tests
- `PACKAGING.md` – Paketformat und Prüfsummen
- `EXAMPLES.md` – vollständiger Arbeitsablauf
