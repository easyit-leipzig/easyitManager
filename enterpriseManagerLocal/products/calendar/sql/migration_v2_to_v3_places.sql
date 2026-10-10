-- easyITCalendar v2 -> v3: places relation
-- MySQL/MariaDB reference migration. Runtime provisioning is driver-neutral.
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `places` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `street` VARCHAR(255) NULL,
  `postal_code` VARCHAR(32) NULL,
  `city` VARCHAR(190) NULL,
  `room` VARCHAR(120) NULL,
  `description` TEXT NULL,
  `activ` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_places_name` (`name`),
  KEY `idx_places_active` (`activ`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Free-text location values are migrated by CalendarDataFormBridge on the next
-- Calendar/DataForm initialization. It creates/reuses places and stores places.id
-- in events.location. Keeping the ALTER separate avoids losing legacy text.
SET FOREIGN_KEY_CHECKS=1;
