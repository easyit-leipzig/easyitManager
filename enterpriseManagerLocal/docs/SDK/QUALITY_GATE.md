# SDK Quality Gate

`module:validate` prüft unter anderem:

- Verzeichnis und `module.json`
- Manifest und SemVer
- Bootstrap
- Entry-Klasse und `ModuleInterface`
- Capability-Notation
- Routennamen und doppelte Routen
- Route-zu-Capability-Zuordnung
- Lifecycle-Klassen

`quality:center` führt die projektweite Regression gruppiert aus. Ein FAIL liefert
Exit-Code 1 und kann deshalb in CI/CD als Release-Gate verwendet werden.
