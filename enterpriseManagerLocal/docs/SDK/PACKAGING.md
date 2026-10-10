# Modul-Paketformat

`module:package` akzeptiert nur ein erfolgreich validiertes Modul.

Das erzeugte Archiv beginnt bewusst mit:

```text
root/
```

Darunter befinden sich alle Moduldateien an ihren endgültigen relativen Pfaden.
`root/PACKAGE_MANIFEST.json` enthält Dateigröße und SHA-256 jeder Paketdatei.
Für das Gesamt-ZIP wird zusätzlich eine `.sha256`-Datei erzeugt.

Als ZIP-Backend wird `ZipArchive` bevorzugt. Ohne ext-zip steht `PharData` als
Fallback zur Verfügung.
