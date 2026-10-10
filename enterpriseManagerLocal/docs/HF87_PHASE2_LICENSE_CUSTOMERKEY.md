# HF87 Phase 2 – Lizenzzuordnung über Kunden-E-Mail / customer_key

## Ziel

Eine lokale Enterprise-Core-Lizenz darf fachlich nicht über den Firmen- oder Organisationsnamen einem Kunden zugeordnet werden. Namen sind nicht eindeutig. Der eindeutige fachliche Bezug ist der normalisierte `customer_key`, also die Kunden-E-Mail-Adresse.

## Regeln

- `customer_key = lower(trim(email))`
- Neue Lizenzen benötigen einen gültigen E-Mail-Kundenschlüssel.
- `holder` bleibt ausschließlich ein optionales Anzeige-/Stammdatenfeld (Firma/Organisation).
- Mehrere Kunden dürfen denselben Firmen-/Organisationsnamen besitzen.
- Mehrere Lizenzen dürfen demselben `customer_key` zugeordnet sein.
- `license_key` bleibt eindeutig.
- Bestehende Alt-Lizenzen werden nur dann automatisch migriert, wenn der frühere `holder` bereits eine syntaktisch gültige E-Mail-Adresse war.
- Bei Alt-Lizenzen mit reinem Firmen-/Personennamen wird keine Zuordnung geraten; sie werden als Altbestand ohne E-Mail-Zuordnung angezeigt.

## Datenmodell

`enterprise_licenses` erhält die zusätzliche Spalte:

```text
customer_key VARCHAR(320) NULL
```

sowie einen nicht-eindeutigen Index `idx_license_customer_key`, weil ein Kunde mehrere Lizenzen besitzen darf.

## Oberfläche

Die Maske `Enterprise → Lizenzverwaltung` verwendet jetzt:

- Lizenzschlüssel – Pflichtfeld
- Kunden-E-Mail / Kundenschlüssel – Pflichtfeld, E-Mail-Validierung
- Firma / Organisation (Anzeige) – optional

Der Kundenschlüssel wird in der Bestandsliste separat vom Anzeigenamen dargestellt.
