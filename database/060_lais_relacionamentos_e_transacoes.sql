-- Green Portal - Relacionamentos e transacoes filhas do LAIS.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`lais_legislacaoxaspectossociais` LIKE `green_modelo`.`lais_legislacaoxaspectossociais`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`lais_legislacaoximpactossociais` LIKE `green_modelo`.`lais_legislacaoximpactossociais`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`lais_documentosxaspectos` LIKE `green_modelo`.`lais_documentosxaspectos`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`lais_documentosximpactos` LIKE `green_modelo`.`lais_documentosximpactos`;
ALTER TABLE `gmodelo_dbp`.`lais_legislacaoxaspectossociais` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
ALTER TABLE `gmodelo_dbp`.`lais_legislacaoximpactossociais` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
ALTER TABLE `gmodelo_dbp`.`lais_documentosxaspectos` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
ALTER TABLE `gmodelo_dbp`.`lais_documentosximpactos` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`lais_legislacaoxaspectossociais` SELECT * FROM `green_modelo`.`lais_legislacaoxaspectossociais`;
INSERT IGNORE INTO `gmodelo_dbp`.`lais_legislacaoximpactossociais` SELECT * FROM `green_modelo`.`lais_legislacaoximpactossociais`;
INSERT IGNORE INTO `gmodelo_dbp`.`lais_documentosxaspectos` SELECT * FROM `green_modelo`.`lais_documentosxaspectos`;
INSERT IGNORE INTO `gmodelo_dbp`.`lais_documentosximpactos` SELECT * FROM `green_modelo`.`lais_documentosximpactos`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`objetivosmetas` LIKE `green_modelo`.`objetivosmetas`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`objetivosmetasxaspectossociais` LIKE `green_modelo`.`objetivosmetasxaspectossociais`;
ALTER TABLE `gmodelo_dbp`.`objetivosmetas` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
ALTER TABLE `gmodelo_dbp`.`objetivosmetasxaspectossociais` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`objetivosmetas` SELECT * FROM `green_modelo`.`objetivosmetas`;
INSERT IGNORE INTO `gmodelo_dbp`.`objetivosmetasxaspectossociais` SELECT * FROM `green_modelo`.`objetivosmetasxaspectossociais`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lais_requisitos_legais_grid` AS
SELECT r.`CODLEGISLACAO`, l.`CODIGO`, r.`CODASPECTOSOCIAL`, NULL AS `CODIMPACTOSOCIAL`,
       NULL AS `CODPARTE`, r.`codunidade`, a.`DSCASPECTOSOCIAL`
  FROM `gmodelo_dbp`.`lais_legislacaoxaspectossociais` r
  LEFT JOIN `glegislacao_dbp`.`legislacao` l ON l.`CODLEGISLACAO`=r.`CODLEGISLACAO`
  LEFT JOIN `gmodelo_dbp`.`lais_aspectossociais` a ON a.`CODASPECTOSOCIAL`=r.`CODASPECTOSOCIAL`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lais_controles_documentos_grid` AS
SELECT d.`CODDOCUMENTO`, d.`CODASPECTOSOCIAL`, NULL AS `CODPARTE`, d.`codunidade`, a.`DSCASPECTOSOCIAL`,
       NULL AS `CODIMPACTOSOCIAL`, NULL AS `DSCIMPACTOSOCIAL`
  FROM `gmodelo_dbp`.`lais_documentosxaspectos` d
  LEFT JOIN `gmodelo_dbp`.`lais_aspectossociais` a ON a.`CODASPECTOSOCIAL`=d.`CODASPECTOSOCIAL`
 UNION ALL
SELECT d.`CODDOCUMENTO`, NULL, NULL AS `CODPARTE`, d.`codunidade`, NULL,
       d.`CODIMPACTOSOCIAL`, i.`DSCIMPACTOSOCIAL`
  FROM `gmodelo_dbp`.`lais_documentosximpactos` d
  LEFT JOIN `gmodelo_dbp`.`lais_impactossociais` i ON i.`CODIMPACTOSOCIAL`=d.`CODIMPACTOSOCIAL`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lais_objetivos_metas_grid` AS
SELECT o.`CODOBJETIVO`, o.`CODIGO`, o.`ITEM_ATENDIDO_DA_POLITICA`, o.`OBJETIVO`,
       o.`META`, o.`INDICADOR`, o.`LIDER`, o.`TIPO_ESTRATEGICO_TATICO`,
       x.`CODASPECTOSOCIAL`, NULL AS `CODIMPACTOSOCIAL`, NULL AS `CODPARTE`, o.`codunidade`
  FROM `gmodelo_dbp`.`objetivosmetas` o
  JOIN `gmodelo_dbp`.`objetivosmetasxaspectossociais` x ON x.`CODOBJETIVO`=o.`CODOBJETIVO`;

INSERT INTO `gmodelo_dbp`.`menus`
 (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT p.`codmenu`,'MODULO_LEGAL',x.`codigo`,x.`label_pt`,x.`label_pt`,x.`label_en`,x.`label_es`,x.`url`,2,x.`ordem`,1
 FROM `gmodelo_dbp`.`menus` p CROSS JOIN (
 SELECT 'LAIS_REQUISITOS_LEGAIS' codigo,'Requisitos legais' label_pt,'Legal requirements' label_en,'Requisitos legales' label_es,'/principal/moduloleglais_requisitoslegais.php' url,26 ordem
 UNION ALL SELECT 'LAIS_CONTROLES_DOCUMENTOS','Controles e documentos','Controls and documents','Controles y documentos','/principal/moduloleglais_controlesdocumentos.php',27
 UNION ALL SELECT 'LAIS_OBJETIVOS_METAS','Objetivos e metas','Objectives and targets','Objetivos y metas','/principal/moduloleglais_objetivosmetas.php',28
 ) x WHERE p.`codigo`='MODULO_LEGAL' AND NOT EXISTS (SELECT 1 FROM `gmodelo_dbp`.`menus` e WHERE e.`codigo`=x.`codigo`);

INSERT INTO `gmodelo_dbp`.`permissoes`
 (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`,m.`codmenu`,0,IF(p.`codigo`='ADMINISTRADOR',1,0),0,0,IF(p.`codigo`='ADMINISTRADOR',1,0),0
 FROM `gmodelo_dbp`.`perfis` p CROSS JOIN `gmodelo_dbp`.`menus` m
 WHERE p.`ativo`=1 AND m.`codigo` IN ('LAIS_REQUISITOS_LEGAIS','LAIS_CONTROLES_DOCUMENTOS','LAIS_OBJETIVOS_METAS')
 AND NOT EXISTS (SELECT 1 FROM `gmodelo_dbp`.`permissoes` e WHERE e.`codperfil`=p.`codperfil` AND e.`codmenu`=m.`codmenu`);

INSERT INTO `gmodelo_dbp`.`registros_migracao`
 (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('LAIS_RELACIONAMENTOS_060','green_modelo.lais_*','gmodelo_dbp.lais_*','DADOS','1.0.0','CONCLUIDA',
 (SELECT COUNT(*) FROM gmodelo_dbp.lais_legislacaoxaspectossociais)+(SELECT COUNT(*) FROM gmodelo_dbp.lais_legislacaoximpactossociais)+(SELECT COUNT(*) FROM gmodelo_dbp.lais_documentosxaspectos)+(SELECT COUNT(*) FROM gmodelo_dbp.lais_documentosximpactos),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
 JSON_OBJECT('transacoes',3,'filtros_contextuais',TRUE,'relacoes_preservadas',TRUE))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
SET FOREIGN_KEY_CHECKS=1;
