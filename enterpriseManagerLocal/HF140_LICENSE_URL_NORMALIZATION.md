# HF140 – Lizenzserver-URL normalisieren

Der Enterprise Manager normalisiert die eingegebene bzw. gespeicherte Lizenzserver-URL vor jeder READY-Prüfung. Literal escapte Pfadtrenner wie `\/` sowie Backslashes werden in normale `/` umgewandelt. Damit wird aus `http://localhost\/easyit-license-server` zuverlässig `http://localhost/easyit-license-server`.

Betroffen sind die Anzeige der Lizenzserver-URL, der Health-Endpunkt, die Loopback-Prüfung sowie die anschließend gespeicherte Serveradresse.
