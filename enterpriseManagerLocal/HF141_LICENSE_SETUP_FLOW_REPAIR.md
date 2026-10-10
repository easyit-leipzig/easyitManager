# HF141 – License setup flow repair

- Lizenzserver-Basis-URLs werden aus Schema, Host, Port und Pfad kanonisch neu aufgebaut.
- Backslashes, maskierte `\/`-Sequenzen und `%5C` aus älteren Konfigurationen werden entfernt.
- Der Health-Endpunkt wird ausschließlich aus der kanonischen Basis-URL gebildet.
- Bei `SETUP_REQUIRED` zeigt der Manager den nächsten Schritt ausdrücklich an und öffnet über `setup.php` die Ein-Seiten-Einrichtung des Lizenzservers.
- Nach Rückkehr kann der READY-Status direkt neu geprüft werden.
- Der eigentliche Manager-Abschluss bleibt bis `READY` gesperrt, damit kein halbfertiger Installationszustand erzeugt wird.
