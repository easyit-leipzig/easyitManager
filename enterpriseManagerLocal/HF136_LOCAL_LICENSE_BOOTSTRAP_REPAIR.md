# HF136 – Lokale Lizenzserver-Erstkopplung repariert

## Fehlerbild

Beim Aufruf von `setup.php` über `localhost` konnte die Ein-Schritt-Installation mit der Meldung

`Die automatische Erstkopplung ist nur mit einem lokalen Lizenzserver zulässig.`

abbrechen, obwohl Manager und Lizenzserver lokal unter XAMPP installiert waren.

## Ursache

`setup_local_license_server_url()` übernahm eine bereits gespeicherte `server_url` ungeprüft. War dort noch eine frühere oder entfernte Serveradresse eingetragen, wurde diese für die Erstkopplung verwendet. Die anschließende Sicherheitsprüfung lehnte sie folgerichtig als nicht lokal ab.

## Änderung

- Eine bereits konfigurierte `server_url` wird bei der automatischen Erstkopplung nur noch wiederverwendet, wenn ihr Host `localhost`, `127.0.0.1` oder `::1` ist.
- Nichtlokale Altwerte werden beim Bootstrap ignoriert.
- In diesem Fall wird der lokale Schwesterpfad aus dem aktuellen Manager-Aufruf abgeleitet, z. B. `http://localhost/easyit-license-server`.
- Die Loopback-Sicherheitsprüfung in `setup_configure_local_license_server()` bleibt erhalten.
- Der Recovery-/Abschlussweg `finish_activation` verwendet dieselbe korrigierte Auflösung.

## Erwarteter Aufbau unter XAMPP

- `D:\xampp\htdocs\enterpriseManagerLocal`
- `D:\xampp\htdocs\easyit-license-server`

Der Manager kann danach über `http://localhost/enterpriseManagerLocal/setup.php` eingerichtet bzw. die angefangene Aktivierung abgeschlossen werden.
