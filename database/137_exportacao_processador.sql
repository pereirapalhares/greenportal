USE `gmodelo_dbp`;

SET @add_processador = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='exportacoes_grid' AND COLUMN_NAME='processador')=0,
    'ALTER TABLE `exportacoes_grid` ADD COLUMN `processador` VARCHAR(120) NULL AFTER `iniciada_em`', 'SELECT 1');
PREPARE stmt FROM @add_processador; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('EXPORTACAO_PROCESSADOR_137','gmodelo_dbp.exportacoes_grid','processador','ESTRUTURA','1.0','CONCLUIDA',0,NOW(),NOW(),JSON_OBJECT('servico','ExportacaoGridService'));
