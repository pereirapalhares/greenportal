USE `gmodelo_dbp`;

SET @add_iniciada = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='exportacoes_grid' AND COLUMN_NAME='iniciada_em')=0,
    'ALTER TABLE `exportacoes_grid` ADD COLUMN `iniciada_em` DATETIME NULL AFTER `solicitado_em`', 'SELECT 1');
PREPARE stmt FROM @add_iniciada; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('EXPORTACAO_ALERTAS_INICIO_FIM_134','gmodelo_dbp.exportacoes_grid; gmodelo_dbp.alertas','iniciada_em; alertas de início e fim','ESTRUTURA','1.0','CONCLUIDA',0,NOW(),NOW(),JSON_OBJECT('status_inicio','PROCESSANDO','alerta_final_com_link',true));
