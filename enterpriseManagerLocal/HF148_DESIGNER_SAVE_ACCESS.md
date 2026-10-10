# HF148 – DataForm Designer: Speichern im hohen Feldinspektor

Problem: Nach Erweiterung um die Lookup-Konfiguration kann der rechte Feldinspektor höher als das Browserfenster werden. Da der Inspektor sticky ist, lag die Schaltfläche „Eigenschaften speichern“ außerhalb des erreichbaren sichtbaren Bereichs.

Änderung:
- rechter Feldinspektor erhält eine viewport-bezogene Maximalhöhe,
- der Inhalt des Inspektors ist vertikal scrollbar,
- „Eigenschaften speichern“ bleibt als Sticky-Aktion am unteren Rand des Inspektors sichtbar,
- die Feldreihenfolge-Pfeile bleiben eigenständig und unverändert.
