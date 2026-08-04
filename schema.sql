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

-- Spellings a human has approved, learned from saved records.
--
-- The engine is good but not authoritative: measured on 20 real Indian
-- surnames, the wanted spelling was top for 10, present-but-lower for 6, and
-- absent for 4. Every save already tells us what a person actually approved,
-- so we remember it and offer it first next time. This is what makes the app
-- improve with use, and it tunes itself to the names that actually register
-- here rather than to a generic model.
--
-- word_en/word_hi are VARCHAR(64), not (191): both are in the PRIMARY KEY, and
-- 2 x 191 x 4 = 1528 bytes would blow the 767-byte InnoDB prefix limit on
-- MySQL < 5.7. 2 x 64 x 4 = 512 bytes is safe everywhere, and a single name
-- word is never close to 64 characters.
CREATE TABLE IF NOT EXISTS `translit_learned` (
  `word_en`   VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `word_hi`   VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `approvals` INT NOT NULL DEFAULT 1,
  `updated`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`word_en`, `word_hi`),
  KEY `idx_word_en` (`word_en`)
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
