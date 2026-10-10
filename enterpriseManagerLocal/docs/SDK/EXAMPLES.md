# Beispiel: Inventory-Modul

```bash
php easyit make:module Inventory --namespace=EasyIT\\Modules\\Inventory
php easyit make:crud Product --module=inventory
php easyit make:event ProductCreated --module=inventory
php easyit make:listener AuditProductCreated --module=inventory
php easyit make:test ProductWorkflow --module=inventory
php easyit module:validate inventory
php easyit quality:center --group=sdk
php easyit module:package inventory
```

Damit entsteht ein Modul, das vor der Auslieferung strukturell validiert,
regressionsgeprüft und mit Prüfsummen paketiert werden kann.
