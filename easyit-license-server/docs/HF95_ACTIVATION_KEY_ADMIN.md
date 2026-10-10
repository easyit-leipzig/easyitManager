# HF95 – Aktivierungsschlüssel im Lizenzserver-Admin

Die Lizenzverwaltung stellt die Erzeugung eines Aktivierungsschlüssels jetzt unmittelbar in der Lizenzliste und auf der Lizenzdetailseite bereit.

- Der Schlüssel besteht weiterhin aus `Lizenznummer.Geheimnis`.
- Serverseitig wird ausschließlich der Hash des geheimen Anteils gespeichert.
- Der Klartext wird nur unmittelbar nach der Erzeugung einmal angezeigt.
- Eine Neuerzeugung invalidiert den zuvor ausgegebenen Aktivierungsschlüssel.
- Die Aktion ist CSRF-geschützt und nur im authentifizierten Adminbereich verfügbar.
- Vor der Neuerzeugung wird eine Bestätigung verlangt.

Ablauf: Admin > Lizenzen > Schlüssel erzeugen > einmalig angezeigten Schlüssel kopieren > im Enterprise-Manager unter „Installation aktivieren“ einfügen.
