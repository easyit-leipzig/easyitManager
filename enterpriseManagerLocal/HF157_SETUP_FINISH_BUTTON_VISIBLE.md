# HF157 – Setup-Abschlussbutton sichtbar und bedienbar

## Fehlerbild
Im Installer war die Aktion **Installation vollständig abschließen** zwar im HTML vorhanden, wurde aber durch die globale easyIT-Bildbutton-Registry als `bestaetigen`-Symbol dekoriert. Dadurch verschwand die Beschriftung; bei fehlender oder nicht sichtbarer Grafik erschien nur eine leere kleine Schaltfläche. Für den Benutzer war damit kein erkennbarer Abschluss-/Speichervorgang mehr vorhanden.

## Korrektur
- Der kritische Installationsabschluss erhält `data-button-skip` und wird nicht mehr durch die globale Bildbutton-Registry umgewandelt.
- Die Schaltfläche bleibt als klar beschrifteter Textbutton sichtbar.
- Auch der gesperrte Zustand bleibt vollständig lesbar.
- READY: **Installation vollständig abschließen** ist aktiv.
- Nicht READY: **Abschluss gesperrt – Lizenzserver meldet noch SETUP_REQUIRED** ist sichtbar und deaktiviert.
- Die POST-Logik (`setup_action=install`) bleibt unverändert.

## Kompatibilität
HF145 bis HF156 bleiben enthalten.
