-- HF150: Kategoriefarbe für den linken Balken am Termin
ALTER TABLE categories
    ADD COLUMN IF NOT EXISTS category_color CHAR(7) NULL
    COMMENT 'Farbe des linken Terminbalkens im Format #RRGGBB'
    AFTER name;
