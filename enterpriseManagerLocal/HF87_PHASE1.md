# HF87 Phase 1 – Enterprise-Manager Selbstregistrierung und Demo

Basis: `RC1.8-FC1-HF86-PHASE12-FINAL-RC`.

## Anmeldung

`/enterpriseManager/login.php` enthält jetzt zwei gleichwertige Wege:

1. Anmeldung mit Kunden-E-Mail und Kennwort.
2. Selbstregistrierung mit Kunden-E-Mail als eindeutigem Kundenschlüssel.

Die Registrierung meldet den neuen Kunden unmittelbar an. Ist noch keine gültige Lizenz vorhanden, wird der normale Enterprise-Manager mit `manager_mode=demo` geöffnet.

## Demo-Abschirmung

Demo ist kein rein optischer Zustand. Der gemeinsame Auth-Guard erlaubt Demo-Konten ausschließlich:

- `/app/dashboard.php`
- `/app/account.php`
- Abmeldung über den normalen Logout-Endpunkt

Alle anderen authentifizierten Managerpfade werden vor dem Laden produktiver Daten mit HTTP 403 blockiert. Zusätzlich werden im Demo-Kontext keine Module, produktiven Capabilities, Projekte oder DataForm-Ressourcen freigegeben.

## Demo-Oberfläche

Das Dashboard zeigt den Kundenschlüssel, den Demo-Status und die gesperrten produktiven Bereiche. `Mein Konto` bleibt für Stammdaten und Kennwort verfügbar. Installationen, DataForms sowie Runtime-Domain-/IP-Verwaltung werden im Demo-Modus nicht angeboten.

## Automatische Umschaltung

Der bestehende zentrale Profilabgleich läuft auf jedem authentifizierten Managerrequest. Sobald der Lizenzserver eine gültige Lizenz meldet, wird die Session auf `manager_mode=licensed` aktualisiert und der bisherige HF86-Lizenz-/Rollen-/Ressourcenfluss verwendet. Eine erneute Registrierung ist nicht erforderlich.
