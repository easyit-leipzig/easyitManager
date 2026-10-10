# HF133 – Demo → Lizenzaktivierung vor Surface-Gate

Account-Authentifizierung und Lizenzbindung werden von der produktiven Surface-Policy getrennt.

- `/api/v1/account/login` erkennt eine nachträglich vergebene Lizenz und bindet die Installation auch dann, wenn die aktuelle Domain/IP noch nicht freigegeben ist.
- Login- und Profilantworten enthalten `surface_policy` mit `allowed` und `error_code`.
- Produktive API-Funktionen verwenden weiterhin `assertForLicense()` und bleiben damit durch Domain/IP geschützt.
- `domain/add` und `network/add` dürfen bei gültiger Kundensitzung und aktivierter Self-Service-Berechtigung eine fehlende Freigabe beheben; dadurch entsteht kein Lockout mehr.
