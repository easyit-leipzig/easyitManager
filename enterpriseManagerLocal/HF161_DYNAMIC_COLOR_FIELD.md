# HF161 – Dynamische Color-Felder

Neue Calendar-Felder können beim Anlegen als `COLOR / Farbe` typisiert werden. Physisch wird dafür ein `VARCHAR(7)` angelegt; bei gebundenen DataForms wird die Feldmetadaten-Definition automatisch als `field_type=color` gespeichert.

Die Datenpflege entscheidet primär anhand des semantischen Feldtyps `color`, ob ein Color-Picker gerendert wird. Die bisherige Namensregel (`color`, `colour`, `farbe`) bleibt nur als Rückwärtskompatibilitäts-Fallback erhalten. Dadurch funktioniert der Color-Picker auch bei frei benannten neuen Feldern wie `akzent`, `marker` oder `rahmen`, sofern deren Feldtyp Color ist.
