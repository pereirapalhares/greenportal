-- Green Portal - Modulo Legal / Correlacionamento Ocupacional.
-- Migra as relacoes ocupacionais ausentes no modelo dbp e cria a transacao unica.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

USE `gmodelo_dbp`;

CREATE TABLE IF NOT EXISTS `legislacaoxatividade` LIKE `green_modelo`.`legislacaoxatividade`;
INSERT IGNORE INTO `legislacaoxatividade` (`CODLEGISLACAO`,`CODATIVIDADE`,`codunidade`)
SELECT `CODLEGISLACAO`,`CODATIVIDADE`,`codunidade`
FROM `green_modelo`.`legislacaoxatividade`;

CREATE TABLE IF NOT EXISTS `legislacaoxrisco` LIKE `green_modelo`.`legislacaoxrisco`;
INSERT IGNORE INTO `legislacaoxrisco` (`CODLEGISLACAO`,`CODRISCO`,`codunidade`)
SELECT `CODLEGISLACAO`,`CODRISCO`,`codunidade`
FROM `green_modelo`.`legislacaoxrisco`;

CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_ocupacional_grid` AS
SELECT DISTINCT
       le.`CODLEGISLACAO`, le.`CODIGO`, le.`NR`, le.`ANO`, le.`ARTIGOS`,
       le.`MEIO_AMBIENTE`, le.`SEGURANCA`, le.`QUALIDADE`, le.`RESPOSABILIDADE`,
       ue.`CODUNIDADE`, u.`DSCUNIDADE`, lp.`PODER` AS `NIVEL_DE_CONTROLE`,
       tp.`DSCTIPO`, am.`DSCAMBITO`,
       GROUP_CONCAT(DISTINCT ass.`DSCASSUNTO` ORDER BY ass.`DSCASSUNTO` SEPARATOR ', ') AS `DSCASSUNTO`,
       (SELECT COUNT(*) FROM `legislacaoxperigo` xp WHERE xp.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND xp.`codunidade`=ue.`CODUNIDADE`) AS `QTD_PERIGOS`,
       (SELECT COUNT(*) FROM `legislacaoxatividade` xa WHERE xa.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND xa.`codunidade`=ue.`CODUNIDADE`) AS `QTD_ATIVIDADES`,
       (SELECT COUNT(*) FROM `legislacaoxrisco` xr WHERE xr.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND xr.`codunidade`=ue.`CODUNIDADE`) AS `QTD_RISCOS`,
       (SELECT COUNT(*) FROM `legislacaoxlicencas` xl WHERE xl.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND xl.`codunidade`=ue.`CODUNIDADE`) AS `QTD_LICENCAS`,
       (SELECT COUNT(*) FROM `legislacaoxdocumentos` xd WHERE xd.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND xd.`codunidade`=ue.`CODUNIDADE`) AS `QTD_DOCUMENTOS`
  FROM `glegislacao_dbp`.`legislacao` le
  INNER JOIN `legislacaoxempresa` ue ON ue.`CODLEGISLACAO`=le.`CODLEGISLACAO`
  LEFT JOIN `unidades` u ON u.`CODUNIDADE`=ue.`CODUNIDADE`
  LEFT JOIN `legislacaoxpoder` lp ON lp.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND lp.`codunidade`=ue.`CODUNIDADE`
  LEFT JOIN `glegislacao_dbp`.`tipos` tp ON tp.`CODTIPO`=le.`CODTIPO`
  LEFT JOIN `glegislacao_dbp`.`ambitos` am ON am.`CODAMBITO`=le.`CODAMBITO`
  LEFT JOIN `glegislacao_dbp`.`legislacaoxassunto` lxa ON lxa.`CODLEGISLACAO`=le.`CODLEGISLACAO`
  LEFT JOIN `glegislacao_dbp`.`assuntos` ass ON ass.`CODASSUNTO`=lxa.`CODASSUNTO`
 GROUP BY le.`CODLEGISLACAO`,le.`CODIGO`,le.`NR`,le.`ANO`,le.`ARTIGOS`,le.`MEIO_AMBIENTE`,le.`SEGURANCA`,le.`QUALIDADE`,le.`RESPOSABILIDADE`,ue.`CODUNIDADE`,u.`DSCUNIDADE`,lp.`PODER`,tp.`DSCTIPO`,am.`DSCAMBITO`
 ORDER BY le.`CODIGO`,ue.`CODUNIDADE`;

CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_ocupacional_perigos` AS
SELECT x.`CODPERIGO`, x.`CODLEGISLACAO`, x.`codunidade`, p.`DSCPERIGO`
FROM `legislacaoxperigo` x LEFT JOIN `perigos` p ON p.`CODPERIGO`=x.`CODPERIGO`;
CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_ocupacional_atividades` AS
SELECT x.`CODATIVIDADE`, x.`CODLEGISLACAO`, x.`codunidade`, a.`DSCATIVIDADE`
FROM `legislacaoxatividade` x LEFT JOIN `atividades` a ON a.`CODATIVIDADE`=x.`CODATIVIDADE`;
CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_ocupacional_riscos` AS
SELECT x.`CODRISCO`, x.`CODLEGISLACAO`, x.`codunidade`, r.`DSCRISCO`
FROM `legislacaoxrisco` x LEFT JOIN `riscos` r ON r.`CODRISCO`=x.`CODRISCO`;
CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_ocupacional_licencas` AS
SELECT x.`CODLICENCA`, x.`CODLEGISLACAO`, x.`codunidade`, l.`DSCLICENCA`, l.`DATA_VALIDADE`, l.`DATA_REACAO`
FROM `legislacaoxlicencas` x LEFT JOIN `li_licencas` l ON l.`CODLICENCA`=x.`CODLICENCA`;
CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_ocupacional_documentos` AS
SELECT x.`CODDOCUMENTO`, x.`CODLEGISLACAO`, x.`codunidade`, d.`CODIGO`, d.`TITULO`, d.`ARQUIVO`
FROM `legislacaoxdocumentos` x LEFT JOIN `documentos` d ON d.`CODDOCUMENTO`=x.`CODDOCUMENTO`;

INSERT INTO `menus` (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT p.`codmenu`,'MODULO_LEGAL','CORRELACIONAMENTO_OCUPACIONAL','Correlacionamento Ocupacional','Correlacionamento Ocupacional','Occupational Correlation','Correlacionamiento ocupacional','/principal/modulolegal_correlacionamentoocupacional.php',2,34,1
FROM `menus` p WHERE p.`codigo`='MODULO_LEGAL'
  AND NOT EXISTS (SELECT 1 FROM `menus` m WHERE m.`codigo`='CORRELACIONAMENTO_OCUPACIONAL');

INSERT INTO `permissoes` (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`,m.`codmenu`,0,IF(p.`codigo`='ADMINISTRADOR',1,0),0,0,IF(p.`codigo`='ADMINISTRADOR',1,0),0
FROM `perfis` p CROSS JOIN `menus` m
WHERE p.`ativo`=1 AND m.`codigo`='CORRELACIONAMENTO_OCUPACIONAL'
  AND NOT EXISTS (SELECT 1 FROM `permissoes` x WHERE x.`codperfil`=p.`codperfil` AND x.`codmenu`=m.`codmenu`);

INSERT INTO `guniversal_dbp`.`ajuda_transacoes` (`transacao`,`titulo_pt`,`titulo_en`,`titulo_es`,`texto_pt`,`texto_en`,`texto_es`,`ativo`)
VALUES ('modulolegal_correlacionamentoocupacional','Ajuda - Correlacionamento Ocupacional','Help - Occupational correlation','Ayuda - Correlacionamiento ocupacional','Consulte as legislações relacionadas a perigos, atividades, riscos, licenças e documentos ocupacionais. Selecione uma linha para habilitar os botões contextuais.','View legislation related to occupational hazards, activities, risks, licenses and documents. Select a row to enable the contextual buttons.','Consulte las legislaciones relacionadas con peligros, actividades, riesgos, licencias y documentos ocupacionales. Seleccione una fila para habilitar los botones contextuales.',1)
ON DUPLICATE KEY UPDATE titulo_pt=VALUES(titulo_pt),titulo_en=VALUES(titulo_en),titulo_es=VALUES(titulo_es),texto_pt=VALUES(texto_pt),texto_en=VALUES(texto_en),texto_es=VALUES(texto_es),ativo=VALUES(ativo);

INSERT INTO `registros_migracao` (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('MODULO_LEGAL_CORRELACIONAMENTO_OCUPACIONAL_076','green_modelo.legislacaoxatividade; green_modelo.legislacaoxrisco; green_modelo.legislacaoxperigo; green_modelo.legislacaoxlicencas; green_modelo.legislacaoxdocumentos','gmodelo_dbp.vw_modulolegal_correlacionamento_ocupacional_grid; gmodelo_dbp.menus; gmodelo_dbp.permissoes','ESTRUTURA','1.0.0','CONCLUIDA',(SELECT COUNT(*) FROM `vw_modulolegal_correlacionamento_ocupacional_grid`),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,JSON_OBJECT('transacao_legada','legislacaoxocupacional','arquivo_legado','E:/wamp/www/cadastros/legislacaoxocupacional_c.php','chaves_relacionamento',JSON_ARRAY('CODLEGISLACAO','CODUNIDADE'),'menu_unico',TRUE))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
