# HF150 – Kategoriefarbe am Termin

Jede Calendar-Kategorie besitzt nun `category_color` als optionalen Color-Picker. Die Farbe wird beim Rendern eines Termins als linker, 5 px breiter Balken verwendet. Die Schülerfarbe aus HF145 bleibt unverändert als Dreieck in der rechten unteren Ecke sichtbar.

Bestehende Projekte werden durch `easyit_calendar_schema_install()` automatisch erweitert. Für MariaDB/MySQL steht zusätzlich `products/calendar/sql/migration_v4_to_v5_category_color.sql` bereit. Der Standalone-/HTML-Export übernimmt dieselbe API-, JavaScript- und CSS-Logik.
