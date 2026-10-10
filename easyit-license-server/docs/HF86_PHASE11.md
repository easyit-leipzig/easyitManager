# HF86 Phase 11 – Rotation des zentralen Ed25519-Server-Schlüssels

HF86 Phase 11 erweitert den bisherigen einzelnen Server-Signaturschlüssel um einen kontrollierten Keyring. Private Schlüssel bleiben ausschließlich im lokalen `secure/keys/`-Verzeichnis und sind niemals Bestandteil eines Release-Pakets.

Der sichere Ablauf ist vierstufig:

1. `php bin/server-key-rotation.php prepare --key-id=SERVER-YYYY-NN`
   Der bisherige Schlüssel A bleibt aktiv. Der neue Schlüssel B wird als `staged` erzeugt und über den mit A signierten `server_trust`-Block an Clients verteilt.
2. `php bin/server-key-rotation.php status`
   Der Status zeigt, wie viele aktive Installationen B noch nicht als vertrauenswürdig bestätigt haben. Solange aktive oder temporär gesperrte Installationen fehlen, verweigert `activate` den Wechsel standardmäßig.
3. `php bin/server-key-rotation.php activate --key-id=SERVER-YYYY-NN`
   B wird `active`, A wird `retiring`. Neue Antworten, Runtime-Manifeste und DFT1-Tokens werden mit B signiert. HTTP-Antworten erhalten während der Übergangsphase zusätzlich eine alternative A-Signatur. Historische DFT1-Tokens mit A bleiben während `retiring` gültig.
4. Nach Ablauf der Rotations-Gnadenzeit und nachdem alle aktiven DataForms mindestens einmal einen B-Token erhalten haben: `php bin/server-key-rotation.php revoke --key-id=OLD-KEY-ID`.
   A wird `revoked` und danach weder für HTTP-Antworten noch für DFT1-Verifikation akzeptiert.

`--force=1` existiert nur für bewusste Notfallmaßnahmen. Ohne `--force=1` verhindern die Gates sowohl eine Aktivierung ohne Trust-Bestätigung aller aktiven oder temporär gesperrten Installationen als auch einen Widerruf bei noch nicht migrierten aktiven DataForms oder vor Ablauf der Gnadenzeit.

Der Server führt die von Installationen gemeldeten vertrauenswürdigen Server-Key-IDs in `installation_server_key_trust`. `license_dataforms.token_last_issued_key_id` dokumentiert, unter welchem Server-Key zuletzt ein Token für die jeweilige DataForm ausgegeben wurde. Rotationseingriffe werden als Audit-Ereignisse `SERVER_KEY_ROTATION_PREPARED`, `SERVER_KEY_ROTATION_ACTIVATED` und `SERVER_KEY_ROTATION_REVOKED` protokolliert.

Der Keyring liegt standardmäßig unter `secure/keys/server-keyring.json`. Bestehende Installationen ohne Keyring starten weiterhin mit `server_key_id`, `server_private_key_file` und `server_public_key_file` aus der vorhandenen Konfiguration. Das Update ersetzt keine vorhandene `config/app.php`.
