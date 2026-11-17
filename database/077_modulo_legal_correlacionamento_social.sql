-- Green Portal - Modulo Legal / Correlacionamento Social.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_social_grid` AS
SELECT DISTINCT le.`CODLEGISLACAO`,le.`CODIGO`,le.`NR`,le.`ANO`,le.`ARTIGOS`,le.`MEIO_AMBIENTE`,le.`SEGURANCA`,le.`QUALIDADE`,le.`RESPOSABILIDADE`,ue.`CODUNIDADE`,u.`DSCUNIDADE`,lp.`PODER` AS `NIVEL_DE_CONTROLE`,tp.`DSCTIPO`,am.`DSCAMBITO`,GROUP_CONCAT(DISTINCT ass.`DSCASSUNTO` ORDER BY ass.`DSCASSUNTO` SEPARATOR ', ') AS `DSCASSUNTO`,
       (SELECT COUNT(*) FROM `lais_legislacaoxaspectossociais` xa WHERE xa.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND xa.`codunidade`=ue.`CODUNIDADE`) AS `QTD_ASPECTOS_SOCIAIS`,
       (SELECT COUNT(*) FROM `lais_legislacaoximpactossociais` xi WHERE xi.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND xi.`codunidade`=ue.`CODUNIDADE`) AS `QTD_IMPACTOS_SOCIAIS`,
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

CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_social_aspectos` AS
SELECT x.`CODASPECTOSOCIAL`,x.`CODLEGISLACAO`,x.`codunidade`,a.`DSCASPECTOSOCIAL`
FROM `lais_legislacaoxaspectossociais` x LEFT JOIN `lais_aspectossociais` a ON a.`CODASPECTOSOCIAL`=x.`CODASPECTOSOCIAL`;
CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_social_impactos` AS
SELECT x.`CODIMPACTOSOCIAL`,x.`CODLEGISLACAO`,x.`codunidade`,i.`DSCIMPACTOSOCIAL`
FROM `lais_legislacaoximpactossociais` x LEFT JOIN `lais_impactossociais` i ON i.`CODIMPACTOSOCIAL`=x.`CODIMPACTOSOCIAL`;
CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_social_documentos` AS
SELECT x.`CODDOCUMENTO`,x.`CODLEGISLACAO`,x.`codunidade`,d.`CODIGO`,d.`TITULO`,d.`ARQUIVO`
FROM `legislacaoxdocumentos` x LEFT JOIN `documentos` d ON d.`CODDOCUMENTO`=x.`CODDOCUMENTO`;

INSERT INTO `menus` (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT p.`codmenu`,'MODULO_LEGAL','CORRELACIONAMENTO_SOCIAL','Correlacionamento Social','Correlacionamento Social','Social Correlation','Correlacionamiento social','/principal/modulolegal_correlacionamentosocial.php',2,35,1
FROM `menus` p WHERE p.`codigo`='MODULO_LEGAL' AND NOT EXISTS (SELECT 1 FROM `menus` m WHERE m.`codigo`='CORRELACIONAMENTO_SOCIAL');
INSERT INTO `permissoes` (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`,m.`codmenu`,0,IF(p.`codigo`='ADMINISTRADOR',1,0),0,0,IF(p.`codigo`='ADMINISTRADOR',1,0),0
FROM `perfis` p CROSS JOIN `menus` m WHERE p.`ativo`=1 AND m.`codigo`='CORRELACIONAMENTO_SOCIAL' AND NOT EXISTS (SELECT 1 FROM `permissoes` x WHERE x.`codperfil`=p.`codperfil` AND x.`codmenu`=m.`codmenu`);
INSERT INTO `guniversal_dbp`.`ajuda_transacoes` (`transacao`,`titulo_pt`,`titulo_en`,`titulo_es`,`texto_pt`,`texto_en`,`texto_es`,`ativo`)
VALUES ('modulolegal_correlacionamentosocial','Ajuda - Correlacionamento Social','Help - Social correlation','Ayuda - Correlacionamiento social','Consulte as legislações relacionadas a aspectos sociais, impactos sociais e documentos. Selecione uma linha para habilitar os botões contextuais.','View legislation related to social aspects, social impacts and documents. Select a row to enable the contextual buttons.','Consulte las legislaciones relacionadas con aspectos sociales, impactos sociales y documentos. Seleccione una fila para habilitar los botones contextuales.',1)
ON DUPLICATE KEY UPDATE titulo_pt=VALUES(titulo_pt),titulo_en=VALUES(titulo_en),titulo_es=VALUES(titulo_es),texto_pt=VALUES(texto_pt),texto_en=VALUES(texto_en),texto_es=VALUES(texto_es),ativo=VALUES(ativo);
INSERT INTO `registros_migracao` (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('MODULO_LEGAL_CORRELACIONAMENTO_SOCIAL_077','green_modelo.lais_legislacaoxaspectossociais; green_modelo.lais_legislacaoximpactossociais; green_modelo.legislacaoxdocumentos','gmodelo_dbp.vw_modulolegal_correlacionamento_social_grid; gmodelo_dbp.menus; gmodelo_dbp.permissoes','ESTRUTURA','1.0.0','CONCLUIDA',(SELECT COUNT(*) FROM `vw_modulolegal_correlacionamento_social_grid`),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,JSON_OBJECT('transacao_legada','lais_legislacaoxambiental','arquivo_legado','E:/wamp/www/cadastros/lais_legislacaoxambiental_c.php','chaves_relacionamento',JSON_ARRAY('CODLEGISLACAO','CODUNIDADE'),'menu_unico',TRUE))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
