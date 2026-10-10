# HF130 – Kennwort-Mindestlänge 10 und Registrierungs-CAPTCHA

## Änderungen

- Kennwort-Mindestlänge im Enterprise-Manager konsistent von 12 auf 10 Zeichen reduziert.
- Anforderungen Großbuchstabe, Kleinbuchstabe und Ziffer bleiben bestehen.
- Installer, Superadmin, Kundenkonto, Team-Benutzer, Kennwortwechsel, Calendar-Runtime und weitere lokale Kennwortprüfungen wurden angeglichen.
- Die Neuregistrierung auf `login.php` besitzt jetzt eine serverseitig geprüfte CAPTCHA-Sicherheitsfrage.
- Das CAPTCHA wird in der Session gespeichert, nur einmal verwendet und bei einem Fehlversuch erneuert.
- Es ist keine externe CAPTCHA-/Cloud-Abhängigkeit erforderlich.

## Sicherheitsregel

Kennwort: mindestens 10 Zeichen, mindestens ein Großbuchstabe, mindestens ein Kleinbuchstabe und mindestens eine Ziffer.
