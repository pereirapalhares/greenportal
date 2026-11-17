USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

SET @add_filtros = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='exportacoes_grid' AND COLUMN_NAME='filtros')=0,
    'ALTER TABLE `exportacoes_grid` ADD COLUMN `filtros` JSON NULL AFTER `transacao`', 'SELECT 1');
PREPARE stmt FROM @add_filtros; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @add_busca = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='exportacoes_grid' AND COLUMN_NAME='busca')=0,
    'ALTER TABLE `exportacoes_grid` ADD COLUMN `busca` VARCHAR(255) NULL AFTER `filtros`', 'SELECT 1');
PREPARE stmt FROM @add_busca; EXECUTE stmt; DEALLOCATE PREPARE stmt;
