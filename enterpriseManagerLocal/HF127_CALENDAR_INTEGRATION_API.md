# HF127 – easyIT Calendar JavaScript- und PHP/REST-API

Stand: 2026-09-28

HF127 ergänzt den exportierten easyIT Calendar um eine von der Benutzeroberfläche unabhängige Integrationsschicht. Die REST/PHP-API liegt unter `api-v1.php`; der Browser-Client unter `assets/js/easyit-calendar-api.js`. Fremdanwendungen greifen damit nicht mehr auf interne Tabellen oder UI-Handler zu.

Die Authentifizierung erfolgt über widerrufbare Bearer-Tokens mit den Rollen viewer/editor/admin. Token werden im Standalone-Calendar durch einen Administrator über `api-tokens.php` erzeugt. Im Speicher liegt nur SHA-256 des Tokens. Die API verwendet die vorhandenen Calendar-DataForms und damit dieselbe Datenquelle und Feldzuordnung wie die Calendar-Runtime.

Abgedeckt sind Metadaten, Event CRUD, Zeitblockierungs-CRUD, Blockierungskategorien und Places. Terminänderungen prüfen Zeitblockierungen. Die bestehende Session-/CSRF-API für die Calendar-Oberfläche bleibt unverändert; die neue API ist ausschließlich für externe Integrationen vorgesehen.

Der Standalone-Exporter wurde erweitert, sodass `api-v1.php`, `api-tokens.php`, die JavaScript-Clientbibliothek und die Runtime-Assets mit ausgeliefert werden.
