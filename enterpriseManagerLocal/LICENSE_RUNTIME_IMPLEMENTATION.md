# DataForm5 – servergebundene Lizenz-/Runtime-Integration

Stand: 2026-09-16 · HF78

## Kundenpaket

Der Enterprise-Stand enthält nur den lokalen Lizenz-/Runtime-Client unter `system/licensing/`.
Der geschützte Lizenz-/DataForm-Server wird separat betrieben und darf nicht in das Kundenpaket kopiert werden.

## Aktivierung

1. Lizenzserver separat installieren und Server-Schlüsselpaar erzeugen.
2. Im Enterprise-Superadmin **Lizenzen → DataForm-Lizenzserver / Aktivierung** öffnen.
3. Server-URL, Server-Key-ID und öffentlichen Ed25519-Schlüssel eintragen.
4. Aktivierungsschlüssel eingeben und Installation aktivieren.
5. Im DataForm-Designer die gewünschte DataForm öffnen und **Server-Revision veröffentlichen** ausführen.
6. Danach kann `records.php` eine signierte Runtime starten.

## HF78: Lizenzumfang

Eine zentrale Lizenz kann jetzt zusätzlich festlegen:

- maximale Anzahl aktiv verwendbarer DataForms (`max_dataforms`),
- CRUD einzeln: `dataset.read`, `dataset.create`, `dataset.update`, `dataset.delete`,
- Volltextsuche und Filter,
- Paginierung,
- Beziehungen und Kinddatensätze,
- DataForm-/Datensatz-Events,
- zusätzliches CSS/JavaScript,
- Designer-Validierung, -Kompilierung und -Publish,
- Export und API als vorbereitete Capabilities.

Ein DataForm-Slot wird pro Kombination **Projekt + DataForm** beim ersten Publish bzw. Runtime-Start reserviert. Mehrere Starts desselben DataForms verbrauchen keinen zusätzlichen Slot. Ein Slot kann auf dem Lizenzserver explizit freigegeben werden.

Die Serverlizenz ist immer die Obergrenze. Ein lokal gesetztes `allow_delete=1` kann ein lizenzseitig gesperrtes `dataset.delete` nicht wieder aktivieren. Das Runtime-Manifest enthält nur die Schnittmenge aus DataForm-Definition und Lizenz-Capabilities.

## Durchgesetzte Schutzstellen

- `products/dataform/records.php` startet nur mit signiertem Runtime-Manifest.
- UI-Funktionen für Create/Update/Delete, Suche, Filter und Paginierung werden mit den lizenzierten Runtime-Capabilities geschnitten.
- Relationendarstellung wird ohne `relation.read` nicht geladen.
- DataForm-/DS-Events werden ohne `dataform.events` nicht geladen.
- zusätzliches CSS wird ohne `dataform.custom_css` nicht geladen.
- `dataset.create`, `dataset.update`, `dataset.delete` benötigen eine Server-ActionDecision.
- Mehrfachlöschung bindet die tatsächlichen `record_ids[]` an die Serverentscheidung.
- Nicht serverseitig freigegebene Formularfelder werden vor der lokalen Persistierung aus dem Request entfernt.
- Erfolgreiche/fehlgeschlagene lokale CRUD-Aktionen werden als Action-Ergebnis zurückgemeldet.
- Designer-Publish reserviert einen DataForm-Slot und benötigt `designer.publish`.
- Serverantworten werden gegen einen lokal gepinnten Ed25519-Public-Key verifiziert.
- Installationen besitzen ein eigenes lokales Ed25519-Schlüsselpaar; nur der Public Key wird zur Aktivierung übertragen.
- Offlinebetrieb ist auf signierte Lease-/Grace-Artefakte begrenzt; Grace ist read-only.

## Sicherheitsgrenze

Der lokale DataForm-Bestand enthält weiterhin Darstellungs-, Datenbankadapter- und historische Designer-Hilfslogik. Der geschützte Server enthält jedoch den produktiven Runtime-Compiler, ActionResolver, Lizenz-Capability-Auflösung und Publish-/Revisionsdienst. Das Kundenpaket enthält diese Serverklassen nicht.

Ein Administrator der Kundendatenbank kann seine eigene Datenbank außerhalb von DataForm verändern. Der Lizenzschutz schützt die vollständige DataForm-Produktlogik und die autorisierte DataForm-Runtime, nicht den direkten Zugriff eines Datenbankadministrators auf dessen Datenbank.
