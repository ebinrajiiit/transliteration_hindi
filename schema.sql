-- Schema. See CLAUDE.md sections 4 and 6.
--
-- Every layer declares utf8mb4 explicitly: database, table, and each text
-- column. VARCHAR(191) keeps indexed columns under the 767-byte InnoDB prefix
-- limit on MySQL < 5.7 (191 x 4 = 764); ROW_FORMAT=DYNAMIC covers the rest.
--
-- Restore with:  mysql --default-character-set=utf8mb4 < schema.sql
-- Dump with:     mysqldump --default-character-set=utf8mb4 ...

CREATE DATABASE IF NOT EXISTS `translit_demo`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `translit_demo`;

CREATE TABLE IF NOT EXISTS `persons` (
  `id`      INT AUTO_INCREMENT PRIMARY KEY,
  `name_en` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_hi` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_name_en` (`name_en`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  ROW_FORMAT=DYNAMIC;

-- Cache for the upstream transliteration endpoint. Purely an optimisation:
-- a miss or an error here must never surface to the user.
-- word_en is the PRIMARY KEY, hence VARCHAR(191) rather than (255).
CREATE TABLE IF NOT EXISTS `translit_cache` (
  `word_en`         VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `candidates_json` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`word_en`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  ROW_FORMAT=DYNAMIC;
