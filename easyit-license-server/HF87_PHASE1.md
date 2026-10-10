# HF87 Phase 1 – Selbstregistrierung, E-Mail-Kundenschlüssel und Demo-Modus

Basis: `RC1.8-FC1-HF86-PHASE12-FINAL-RC`.

## Verbindliches Verhalten

- Der fachliche Kundenschlüssel ist die normalisierte Kunden-E-Mail-Adresse (`lower(trim(email))`).
- Interne `customer_id`-Werte bleiben technische Primär-/Fremdschlüssel.
- `customer_number` bleibt nur als technisches Legacy-Kompatibilitätsfeld bestehen und wird für neue Kunden mit der technischen `customer_id` belegt.
- `POST /api/v1/account/register` registriert ein aktives Kundenkonto unmittelbar. Eine Lizenz wird nicht automatisch erzeugt.
- `POST /api/v1/account/request` bleibt als Kompatibilitätsalias erhalten und führt ebenfalls die Selbstregistrierung aus.
- Anmeldung ohne gültige Lizenz ist zulässig und liefert `manager_mode=demo`.
- Anmeldung mit gültiger Lizenz liefert `manager_mode=licensed`.
- Bei jedem Profilabruf wird die aktuell gültige Kundenlizenz erneut aufgelöst. Eine nachträglich erteilte Lizenz schaltet deshalb ohne Neuregistrierung auf `licensed` um.
- Abgelaufene, gesperrte oder widerrufene Lizenzen führen nicht zum Verlust des Kundenlogins; ohne weitere gültige Lizenz wird auf Demo zurückgefallen.
- Lizenzpflichtige Kundenaktionen (Domains/IPs für Runtime, Installationen, Team/Rollen) verlangen weiterhin eine gültige Lizenz.
- Der erste selbstregistrierte Benutzer wird als `customer_admin` angelegt.

## Datenmigration

`MigrationRunner` ergänzt `customers.customer_key`, befüllt den Wert aus der vorhandenen Kunden-E-Mail bzw. primären Kundenbenutzer-E-Mail, normalisiert ihn und erzeugt den eindeutigen Index `ux_customers_customer_key`. Bei vorhandenen Dubletten wird die Migration mit `CUSTOMER_KEY_DUPLICATE` abgebrochen, damit die Dublette vor der Freischaltung bereinigt werden kann.

## Lizenzfreischaltung

Legt der Superadmin für einen Demo-Kunden eine gültige Lizenz an, werden bisher nicht gebundene Kundenbenutzer direkt an diese Lizenz gebunden. Zusätzlich löst der Account-Service bei jedem Login/Profilabruf automatisch eine gültige Kundenlizenz auf. Damit werden auch Benutzer mit einer alten/abgelaufenen `license_id` auf eine neue gültige Lizenz umgestellt.
