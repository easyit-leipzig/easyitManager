# Modul-Lifecycle

Neu erzeugte Module besitzen Hooks für:

- Install
- Update
- Enable
- Disable
- Uninstall

Die Klassen liegen unter `src/Lifecycle/`. Lifecycle-Code muss wiederholbare und
kontrollierte Zustandsübergänge unterstützen. Persistente Änderungen gehören in
versionierte Migrationen; Secrets gehören nicht in das Modulmanifest.
