-- HF153: optionale Personenzuordnung für Zeitblockierungen
-- NULL = Blockierung gilt für alle Personen.
SET @db := DATABASE();
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='blocked_ranges' AND COLUMN_NAME='person_id');
SET @sql := IF(@has_col=0, 'ALTER TABLE `blocked_ranges` ADD COLUMN `person_id` BIGINT NULL AFTER `id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
