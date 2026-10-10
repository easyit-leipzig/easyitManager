-- HF145: Schülerbezogene Terminfarbe
-- Bestehende MySQL/MariaDB-Calendar-Datenbank auf V4 erweitern.
ALTER TABLE persons
    ADD COLUMN IF NOT EXISTS calendar_color CHAR(7) NULL
    COMMENT 'Terminfarbe des Schülers im Format #RRGGBB'
    AFTER notes;
