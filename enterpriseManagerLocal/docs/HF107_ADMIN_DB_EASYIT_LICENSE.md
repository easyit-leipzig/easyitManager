# HF107 – Administrationsdatenbank easyit_license

Der Enterprise-Manager verwendet ab HF107 verbindlich `easyit_license` als kanonische Administrationsdatenbank.

## Reparatur bestehender Installationen

Enthält eine bestehende `DataForm5-Core/.env` noch den historischen Wert `ADMIN_DB_DATABASE=easyit_admin`, wird ausschließlich diese eine Einstellung beim ersten Zugriff auf `easyit_license` migriert. Andere Datenbanknamen und alle Zugangsdaten bleiben unverändert.

## Neue Installationen

Setup, Datenbank-Assistent, Runtime-Fallbacks und das ausgelieferte Grundprofil verwenden `easyit_license`. PostgreSQL verwendet weiterhin ein eigenes Schema (standardmäßig `public`); Datenbankname und Schema werden nicht mehr miteinander verwechselt.

Der Lizenzserver verwendet bereits `easyit_license`; dort ist für HF107 keine Schemaänderung erforderlich.
