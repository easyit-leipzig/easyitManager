# HF116 – Bootstrap-Aktivierung ohne Kundenkonto

Der Superadmin ist ein technischer Administrator und benötigt kein Kundenkonto, um eine neue Enterprise-Manager-Installation erstmals zu registrieren.

## Ablauf

1. Lizenzserver einrichten und Superadmin anlegen.
2. Im Lizenzserver-Dashboard `Bootstrap-Aktivierungsschlüssel erzeugen` wählen.
3. Der Schlüssel wird genau einmal angezeigt und nach erfolgreicher Aktivierung automatisch entwertet.
4. Der Enterprise-Manager registriert damit nur seine Installation und sein Ed25519-Installationsschlüsselpaar.
5. Es werden noch keine Kunden-, Produkt-, Modul- oder DataForm-Rechte vergeben.
6. Meldet sich später ein Kunde mit einer aktiven Lizenz an, wird eine noch bootstrap-gebundene Installation automatisch an genau diese Kundenlizenz gebunden, sofern dort ein Installationsplatz frei ist.
7. Eine Installation, die bereits an eine echte andere Kundenlizenz gebunden ist, wird niemals automatisch umgebunden.

Damit entfällt der bisherige Zirkelschluss `Kundenkonto/Lizenz -> Aktivierungsschlüssel -> Manager`, ohne die Trennung zwischen Superadmin und Kundenkonto aufzugeben.
