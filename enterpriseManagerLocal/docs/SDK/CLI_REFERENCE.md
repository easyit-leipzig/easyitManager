# SDK CLI Reference

## Modul und Artefakte

```bash
php easyit make:module NAME --namespace=NAMESPACE
php easyit make:provider NAME --module=SLUG
php easyit make:event NAME --module=SLUG
php easyit make:listener NAME --module=SLUG
php easyit make:migration NAME --module=SLUG
php easyit make:model NAME --module=SLUG
php easyit make:controller NAME --module=SLUG
php easyit make:view NAME --module=SLUG
php easyit make:api NAME --module=SLUG
php easyit make:theme NAME --module=SLUG
php easyit make:job NAME --module=SLUG
php easyit make:command NAME --module=SLUG
php easyit make:test NAME --module=SLUG
php easyit make:crud ENTITY --module=SLUG
```

## Abnahme

```bash
php easyit module:validate SLUG
php easyit module:validate SLUG --json
php easyit quality:center --group=sdk
php easyit quality:center --json
```

## Paketierung

```bash
php easyit module:package SLUG
php easyit module:package SLUG --output=VERZEICHNIS
```

Generatoren überschreiben vorhandene Zieldateien nicht.
