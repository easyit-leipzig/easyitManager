# HF86 Phase 11 – Vertrauenswürdige Server-Key-Rotation

Der Enterprise-Manager kann neben den statisch in `config/licensing.php` gepinnten Server-Public-Keys weitere Server-Schlüssel aus einem bereits kryptografisch vertrauenswürdig signierten Server-Response übernehmen.

Jede Lizenzserver-Antwort enthält einen `server_trust`-Block. Dieser wird erst verarbeitet, nachdem die gesamte HTTP-Antwort mit einem bereits vertrauenswürdigen, nicht widerrufenen Schlüssel verifiziert wurde. Ein Public-Key darf unter einer bereits bekannten Key-ID niemals ausgetauscht werden (`SERVER_TRUST_KEY_CONFLICT`).

Während des Übergangs `A -> B` akzeptiert der Client sowohl die primäre Signatur als auch eine optionale Übergangssignatur des `retiring` Schlüssels. Dadurch kann B bereits primär signieren, während A vorübergehend noch als Brücke dient. Gelernte Schlüssel und zentral gemeldete Widerrufe werden in `storage/licensing/license_state.json` gespeichert; die installationsspezifische `config/licensing.php` muss für eine normale Rotation nicht neu geschrieben werden.

Bei jeder installationssignierten Anfrage meldet der Client über `X-EasyIT-Trusted-Server-Keys`, welche Server-Key-IDs er aktuell akzeptiert. Der Lizenzserver verwendet diese Rückmeldung als Aktivierungs-Gate.

Unter `Enterprise -> Lizenzverwaltung -> DataForm-Lizenzserver` werden der aktuell gelernte aktive Server-Key, die vertrauenswürdigen Key-IDs und zentral widerrufene Key-IDs angezeigt. Private Schlüssel oder DFT1-Tokens werden dort nicht dargestellt.
