USE `gmodelo_dbp`;

SET @add_link_status = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='alertas' AND COLUMN_NAME='link_status')=0,
    'ALTER TABLE `alertas` ADD COLUMN `link_status` VARCHAR(500) NULL AFTER `link`', 'SELECT 1');
PREPARE stmt FROM @add_link_status; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE OR REPLACE VIEW `vw_alertas_grid` AS
SELECT `codalerta`, `codusuario`, `titulo`, `mensagem`, `link`, `link_status`, `lido`, `criado_em`
  FROM `alertas`;

INSERT IGNORE INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('ALERTA_STATUS_EXPORTACAO_136','gmodelo_dbp.alertas','link_status; vw_alertas_grid','ESTRUTURA','1.0','CONCLUIDA',0,NOW(),NOW(),JSON_OBJECT('popup_status',true));
