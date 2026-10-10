# HF93.1 – License configuration pipeline repair

Der Reparaturstand vereinheitlicht alle produktiven Leser der Lizenzserver-Konfiguration auf `EnterpriseLicenseConfigStore`. Login, Dashboard, Remote-Administration, DataForm-Bridges, DB-Engine-Export und Logout verwenden damit dieselbe persistente Quelle unter `storage/system/config/licensing.php`.

Eine vorhandene Legacy-Datei `config/licensing.php` wird weiterhin einmalig migriert. Fehlen sowohl persistente als auch Legacy-Konfiguration, wird absichtlich keine Serveradresse erfunden; die lokale Administration muss die Verbindung einmal speichern. Danach bleibt sie bei Programmupdates erhalten, sofern `storage/` beibehalten wird.
