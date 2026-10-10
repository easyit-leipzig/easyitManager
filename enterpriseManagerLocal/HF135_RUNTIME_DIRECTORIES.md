# HF135 – Runtime-Verzeichnisse workspace/packages

## Fehlerbild

Auf `health.php` wurden `workspace` und `packages` mit **PRÜFEN** gemeldet, weil die Verzeichnisse nach dem Entpacken des Manager-ZIP nicht vorhanden waren. Leere Verzeichnisse werden in ZIP-Verteilungen nicht zuverlässig als Bestandteil des Pakets erhalten.

## Umsetzung

- `workspace/` und `packages/` sind jetzt durch Markerdateien fester Bestandteil des Manager-Pakets.
- `health.php` legt die Runtime-Verzeichnisse `storage`, `workspace` und `packages` bei Bedarf selbstständig an.
- Anschließend wird weiterhin der reale Schreib-/Löschtest ausgeführt.
- Ein Verzeichnis gilt nur dann als **OK**, wenn Anlegen und Schreibtest erfolgreich sind.

## Erwartetes Ergebnis

Nach Aktualisierung und erneutem Aufruf von `health.php` müssen `Schreibrecht: storage`, `Schreibrecht: workspace` und `Schreibrecht: packages` jeweils **OK** anzeigen.
