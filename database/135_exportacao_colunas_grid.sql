USE `gmodelo_dbp`;

SET @add_colunas = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='exportacoes_grid' AND COLUMN_NAME='colunas')=0,
    'ALTER TABLE `exportacoes_grid` ADD COLUMN `colunas` JSON NULL AFTER `busca`', 'SELECT 1');
PREPARE stmt FROM @add_colunas; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('EXPORTACAO_COLUNAS_GRID_135','gmodelo_dbp.exportacoes_grid','colunas visiveis do grid','ESTRUTURA','1.0','CONCLUIDA',0,NOW(),NOW(),JSON_OBJECT('cabecalho','verde pastel com fonte preta'));
