-- Green Portal - Popups contextuais de Módulo Legal / Requisitos legais.
-- Migração AS-IS das relações usadas pelas abas do legado.

SET FOREIGN_KEY_CHECKS=0;
USE `gmodelo_dbp`;

CREATE TABLE IF NOT EXISTS `legislacaoxdocumentos` LIKE `green_modelo`.`legislacaoxdocumentos`;
CREATE TABLE IF NOT EXISTS `legislacaoxchecklist` LIKE `green_modelo`.`legislacaoxchecklist`;
CREATE TABLE IF NOT EXISTS `planodeacao` LIKE `green_modelo`.`planodeacao`;
INSERT IGNORE INTO `legislacaoxdocumentos` SELECT * FROM `green_modelo`.`legislacaoxdocumentos`;
INSERT IGNORE INTO `legislacaoxchecklist` SELECT * FROM `green_modelo`.`legislacaoxchecklist`;
INSERT IGNORE INTO `planodeacao` SELECT * FROM `green_modelo`.`planodeacao`;

CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_licencas_grid` AS
SELECT r.`CODLEGISLACAO`,r.`codunidade` AS `CODUNIDADE`,l.`CODLICENCA`,l.`DSCLICENCA`,
       l.`DATA_VALIDADE`,l.`DATA_REACAO`,l.`observacao`
  FROM `legislacaoxlicencas` r INNER JOIN `li_licencas` l ON l.`CODLICENCA`=r.`CODLICENCA`;

CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_controles_grid` AS
SELECT r.`CODLEGISLACAO`,r.`CODUNIDADE`,d.`CODDOCUMENTO`,d.`CODIGO`,d.`TITULO`,d.`ARQUIVO`
  FROM `legislacaoxdocumentos` r INNER JOIN `documentos` d ON d.`CODDOCUMENTO`=r.`CODDOCUMENTO`;

CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_medicao_grid` AS
SELECT r.`CODLEGISLACAO`,m.`codunidade` AS `CODUNIDADE`,m.`CODMONITORAMENTO`,m.`PARAMETROS`,
       m.`DATA_MEDICAO`,m.`DATA_REACAO`,m.`OBS`,m.`CODAREA`,m.`CODASPECTO`,m.`CODPERIGO`,m.`codmedicao`
  FROM `mm_monitoramentoxlegislacao` r INNER JOIN `mm_monitoramento` m ON m.`CODMONITORAMENTO`=r.`CODMONITORAMENTO`;

CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_checklist_grid` AS
SELECT c.`codlegislacao` AS `CODLEGISLACAO`,c.`codunidade` AS `CODUNIDADE`,c.`codlegislacaoxchecklist`,
       c.`codpergunta`,c.`ATENDE`,c.`obs`
  FROM `legislacaoxchecklist` c;

CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_alteracoes_grid` AS
SELECT a.`codalteracao`,a.`codlegislacao` AS `CODLEGISLACAO`,a.`tipo`,a.`descricao`,a.`data_da_alteracao`,
       t.`DSCTIPODEALTERACAO`
  FROM `glegislacao_dbp`.`legislacaoxalteracao` a
  LEFT JOIN `glegislacao_dbp`.`tipo_de_alteracao` t ON t.`DSCTIPODEALTERACAO`=a.`tipo`;

INSERT INTO `registros_migracao`
 (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('MODULO_LEGAL_REQUISITOS_POPUPS_065',
        'green_modelo.legislacaoxdocumentos; green_modelo.legislacaoxchecklist; green_modelo.planodeacao; green_modelo.legislacaoxlicencas; green_modelo.mm_monitoramentoxlegislacao; green_legislacao.legislacaoxalteracao',
        'gmodelo_dbp.legislacaoxdocumentos; gmodelo_dbp.legislacaoxchecklist; gmodelo_dbp.planodeacao; gmodelo_dbp.vw_modulolegal_requisitos_*',
        'DADOS','1.0.0','CONCLUIDA',
        (SELECT COUNT(*) FROM `legislacaoxdocumentos`)+(SELECT COUNT(*) FROM `legislacaoxchecklist`)+(SELECT COUNT(*) FROM `planodeacao`),
        CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
        JSON_OBJECT('chaves_contextuais',JSON_ARRAY('CODLEGISLACAO','CODUNIDADE'),'origem_abas_legado','requisitos_legais_porlei_c.php'))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);

SET FOREIGN_KEY_CHECKS=1;
