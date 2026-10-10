# HF102 Calendar Datenbearbeitung

Behoben:
- "Neuer Datensatz" lädt die Spaltenmetadaten selbst und funktioniert ohne vorherigen Klick auf "Daten laden".
- Tabellenwechsel lädt Datensätze und Metadaten automatisch.
- Datensätze werden mit einem echten Editor für Text, Datum, Zeit und Datum/Zeit geöffnet.
- Bearbeiten/Löschen werden über DOM-Handler statt fehleranfälligem JSON-in-HTML angebunden.
- HTML-Ausgabe von Datenwerten wird escaped.
- datetime-local Werte werden serverseitig in SQL/CSV-kompatible Werte normalisiert.
- Calendar-Setup verwendet die vorhandene Enterprise-UI-Funktion render_page() statt der nicht vorhandenen render_layout().
