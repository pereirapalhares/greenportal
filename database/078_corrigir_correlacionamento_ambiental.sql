-- Correção do Módulo Legal / Correlacionamento Ambiental conforme o legado.
-- Restaura os cinco relacionamentos contextuais e corrige a contagem de documentos.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `gmodelo_dbp`;

CREATE TABLE IF NOT EXISTS `legislacaoxaspecto` LIKE `green_modelo`.`legislacaoxaspecto`;
INSERT IGNORE INTO `legislacaoxaspecto` (`CODLEGISLACAO`,`CODASPECTO`,`codunidade`)
SELECT `CODLEGISLACAO`,`CODASPECTO`,`codunidade` FROM `green_modelo`.`legislacaoxaspecto`;

CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_ambiental_grid` AS
SELECT DISTINCT le.`CODLEGISLACAO`,le.`CODIGO`,le.`NR`,le.`ANO`,le.`ARTIGOS`,le.`MEIO_AMBIENTE`,le.`SEGURANCA`,le.`QUALIDADE`,le.`RESPOSABILIDADE`,ue.`CODUNIDADE`,u.`DSCUNIDADE`,lp.`PODER` AS `NIVEL_DE_CONTROLE`,tp.`DSCTIPO`,am.`DSCAMBITO`,GROUP_CONCAT(DISTINCT ass.`DSCASSUNTO` ORDER BY ass.`DSCASSUNTO` SEPARATOR ', ') AS `DSCASSUNTO`,
       (SELECT COUNT(*) FROM `legislacaoxaspecto` xa WHERE xa.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND xa.`codunidade`=ue.`CODUNIDADE`) AS `QTD_ASPECTOS`,
       (SELECT COUNT(*) FROM `legislacaoxatividade` xv WHERE xv.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND xv.`codunidade`=ue.`CODUNIDADE`) AS `QTD_ATIVIDADES`,
       (SELECT COUNT(*) FROM `legislacaoximpacto` li WHERE li.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND li.`codunidade`=ue.`CODUNIDADE`) AS `QTD_IMPACTOS`,
       (SELECT COUNT(*) FROM `legislacaoxlicencas` ll WHERE ll.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND ll.`codunidade`=ue.`CODUNIDADE`) AS `QTD_LICENCAS`,
       (SELECT COUNT(*) FROM `legislacaoxdocumentos` xd WHERE xd.`codlegislacao`=le.`CODLEGISLACAO` AND xd.`codunidade`=ue.`CODUNIDADE`) AS `QTD_DOCUMENTOS`
  FROM `glegislacao_dbp`.`legislacao` le INNER JOIN `legislacaoxempresa` ue ON ue.`CODLEGISLACAO`=le.`CODLEGISLACAO`
  LEFT JOIN `unidades` u ON u.`CODUNIDADE`=ue.`CODUNIDADE`
  LEFT JOIN `legislacaoxpoder` lp ON lp.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND lp.`codunidade`=ue.`CODUNIDADE`
  LEFT JOIN `glegislacao_dbp`.`tipos` tp ON tp.`CODTIPO`=le.`CODTIPO` LEFT JOIN `glegislacao_dbp`.`ambitos` am ON am.`CODAMBITO`=le.`CODAMBITO`
  LEFT JOIN `glegislacao_dbp`.`legislacaoxassunto` lxa ON lxa.`CODLEGISLACAO`=le.`CODLEGISLACAO` LEFT JOIN `glegislacao_dbp`.`assuntos` ass ON ass.`CODASSUNTO`=lxa.`CODASSUNTO`
 WHERE LOWER(TRIM(COALESCE(le.`MEIO_AMBIENTE`,''))) IN ('sim','1','true','s')
 GROUP BY le.`CODLEGISLACAO`,le.`CODIGO`,le.`NR`,le.`ANO`,le.`ARTIGOS`,le.`MEIO_AMBIENTE`,le.`SEGURANCA`,le.`QUALIDADE`,le.`RESPOSABILIDADE`,ue.`CODUNIDADE`,u.`DSCUNIDADE`,lp.`PODER`,tp.`DSCTIPO`,am.`DSCAMBITO`
 ORDER BY le.`CODIGO`,ue.`CODUNIDADE`;

CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_ambiental_aspectos` AS SELECT x.`CODASPECTO`,x.`CODLEGISLACAO`,x.`codunidade`,a.`DSCASPECTO` FROM `legislacaoxaspecto` x LEFT JOIN `aspectos` a ON a.`CODASPECTO`=x.`CODASPECTO`;
CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_ambiental_atividades` AS SELECT x.`CODATIVIDADE`,x.`CODLEGISLACAO`,x.`codunidade`,a.`DSCATIVIDADE` FROM `legislacaoxatividade` x LEFT JOIN `atividades` a ON a.`CODATIVIDADE`=x.`CODATIVIDADE`;
CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_ambiental_impactos` AS SELECT x.`CODIMPACTO`,x.`CODLEGISLACAO`,x.`codunidade`,i.`DSCIMPACTO` FROM `legislacaoximpacto` x LEFT JOIN `impactos` i ON i.`CODIMPACTO`=x.`CODIMPACTO`;
CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_ambiental_licencas` AS SELECT x.`CODLICENCA`,x.`CODLEGISLACAO`,x.`codunidade`,l.`DSCLICENCA`,l.`DATA_VALIDADE`,l.`DATA_REACAO` FROM `legislacaoxlicencas` x LEFT JOIN `li_licencas` l ON l.`CODLICENCA`=x.`CODLICENCA`;
CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_ambiental_documentos` AS SELECT x.`CODDOCUMENTO`,x.`CODLEGISLACAO`,x.`codunidade`,d.`CODIGO`,d.`TITULO`,d.`ARQUIVO` FROM `legislacaoxdocumentos` x LEFT JOIN `documentos` d ON d.`CODDOCUMENTO`=x.`CODDOCUMENTO`;

INSERT INTO `registros_migracao` (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('MODULO_LEGAL_CORRELACIONAMENTO_AMBIENTAL_078','green_modelo.legislacaoxaspecto; green_modelo.legislacaoxatividade; green_modelo.legislacaoximpacto; green_modelo.legislacaoxlicencas; green_modelo.legislacaoxdocumentos','gmodelo_dbp.vw_modulolegal_correlacionamento_ambiental_*','CORRECAO','1.1.0','CONCLUIDA',(SELECT COUNT(*) FROM `vw_modulolegal_correlacionamento_ambiental_grid`),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,JSON_OBJECT('relacionamentos',JSON_ARRAY('aspectos','atividades','impactos','licencas','documentos'),'documentos_restritos_por_legislacao',TRUE))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
