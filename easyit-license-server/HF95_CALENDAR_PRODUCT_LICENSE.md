easyIT Calendar – produktive Lizenzierung

Umgesetzt:
- Produkt `calendar` / `easyIT Calendar` im Lizenzserver-Produktkatalog registriert.
- Laufzeitmodul `calendar` im Lizenzserver registriert.
- Calendar in der allgemeinen Lizenzanfrage als auswählbares Produkt ergänzt.
- Bei Auswahl von Calendar wird das Modul `calendar` automatisch in die Lizenzanforderung übernommen.
- DB-Engine ebenfalls in den vollständigen Produktkatalog der Lizenzanfrage aufgenommen.
- Enterprise-Manager behandelt `calendar` als projektbezogen lizenzpflichtiges Produkt.
- easyIT Calendar in der Setup-Produktauswahl ergänzt.
- Lizenzierungs-Hinweistext im Manager um Calendar ergänzt.

Prüfung:
- PHP-Syntax der geänderten Dateien: PASS.
- Bestehendes HF89 Phase 8 Calendar Gate im Enterprise-Manager: PASS.
- Vollständiger Lizenzserver-Static-Gate-Lauf wurde durch einen bereits im Ausgangspaket
  vorhandenen privaten Server-Key gestoppt (`kein privater Server-Key ausgeliefert`).
  Dieser bestehende Sicherheitsbefund wurde durch diese Änderung nicht erzeugt.

Nach Installation/Aktualisierung:
1. Lizenzserver-Migration erneut ausführen, damit `calendar` in bestehende Datenbanken
   nachgesät wird.
2. Danach Kunden-/Lizenzanfrage neu laden.
3. easyIT Calendar kann als Produkt gewählt und produktiv freigegeben werden.
