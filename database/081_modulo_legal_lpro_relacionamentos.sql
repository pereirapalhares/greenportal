-- LPRO: tabelas e views dos seis correlacionamentos legados.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`infraestrutura` LIKE `green_modelo`.`infraestrutura`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`infraestruturaxunidades` LIKE `green_modelo`.`infraestruturaxunidades`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`atividadesxinfraestrutura` LIKE `green_modelo`.`atividadesxinfraestrutura`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`controleadmin` LIKE `green_modelo`.`controleadmin`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`controleadminxunidades` LIKE `green_modelo`.`controleadminxunidades`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`atividadesxcontroleadmin` LIKE `green_modelo`.`atividadesxcontroleadmin`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`epis` LIKE `green_modelo`.`epis`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`episxunidades` LIKE `green_modelo`.`episxunidades`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`atividadesxepis` LIKE `green_modelo`.`atividadesxepis`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`documentosxperigos` LIKE `green_modelo`.`documentosxperigos`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`documentosxriscos` LIKE `green_modelo`.`documentosxriscos`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`objetivosmetasxperigos` LIKE `green_modelo`.`objetivosmetasxperigos`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`objetivosmetasxriscos` LIKE `green_modelo`.`objetivosmetasxriscos`;

INSERT IGNORE INTO `gmodelo_dbp`.`infraestrutura` SELECT * FROM `green_modelo`.`infraestrutura`;
INSERT IGNORE INTO `gmodelo_dbp`.`infraestruturaxunidades` SELECT * FROM `green_modelo`.`infraestruturaxunidades`;
INSERT IGNORE INTO `gmodelo_dbp`.`atividadesxinfraestrutura` SELECT * FROM `green_modelo`.`atividadesxinfraestrutura`;
INSERT IGNORE INTO `gmodelo_dbp`.`controleadmin` SELECT * FROM `green_modelo`.`controleadmin`;
INSERT IGNORE INTO `gmodelo_dbp`.`controleadminxunidades` SELECT * FROM `green_modelo`.`controleadminxunidades`;
INSERT IGNORE INTO `gmodelo_dbp`.`atividadesxcontroleadmin` SELECT * FROM `green_modelo`.`atividadesxcontroleadmin`;
INSERT IGNORE INTO `gmodelo_dbp`.`epis` SELECT * FROM `green_modelo`.`epis`;
INSERT IGNORE INTO `gmodelo_dbp`.`episxunidades` SELECT * FROM `green_modelo`.`episxunidades`;
INSERT IGNORE INTO `gmodelo_dbp`.`atividadesxepis` SELECT * FROM `green_modelo`.`atividadesxepis`;
INSERT IGNORE INTO `gmodelo_dbp`.`documentosxperigos` SELECT * FROM `green_modelo`.`documentosxperigos`;
INSERT IGNORE INTO `gmodelo_dbp`.`documentosxriscos` SELECT * FROM `green_modelo`.`documentosxriscos`;
INSERT IGNORE INTO `gmodelo_dbp`.`objetivosmetasxperigos` SELECT * FROM `green_modelo`.`objetivosmetasxperigos`;
INSERT IGNORE INTO `gmodelo_dbp`.`objetivosmetasxriscos` SELECT * FROM `green_modelo`.`objetivosmetasxriscos`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lpro_requisitos_legais_grid` AS
SELECT DISTINCT l.`CODLEGISLACAO`, CONCAT(COALESCE(l.`CODIGO`,''),'/',COALESCE(l.`NR`,''),'/',COALESCE(l.`ANO`,'')) AS `LEGISLACAO`, l.`CODIGO` AS `ARQUIVO`, p.`CODPERIGO`, NULL AS `CODRISCO`, e.`CODUNIDADE`
  FROM `gmodelo_dbp`.`legislacaoxperigo` p JOIN `gmodelo_dbp`.`legislacaoxempresa` e ON e.`CODLEGISLACAO`=p.`CODLEGISLACAO` JOIN `glegislacao_dbp`.`legislacao` l ON l.`CODLEGISLACAO`=p.`CODLEGISLACAO`
 UNION
SELECT DISTINCT l.`CODLEGISLACAO`, CONCAT(COALESCE(l.`CODIGO`,''),'/',COALESCE(l.`NR`,''),'/',COALESCE(l.`ANO`,'')), l.`CODIGO`, NULL, r.`CODRISCO`, e.`CODUNIDADE`
  FROM `gmodelo_dbp`.`legislacaoxrisco` r JOIN `gmodelo_dbp`.`legislacaoxempresa` e ON e.`CODLEGISLACAO`=r.`CODLEGISLACAO` JOIN `glegislacao_dbp`.`legislacao` l ON l.`CODLEGISLACAO`=r.`CODLEGISLACAO`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lpro_controles_documentos_grid` AS
SELECT DISTINCT d.`CODDOCUMENTO`, d.`CODIGO`, d.`TITULO`, d.`ARQUIVO`, p.`CODPERIGO`, r.`CODRISCO`, u.`CODUNIDADE`, pg.`DSCPERIGO`, rs.`DSCRISCO`
  FROM `gmodelo_dbp`.`documentos` d LEFT JOIN `gmodelo_dbp`.`documentosxperigos` p ON p.`CODDOCUMENTO`=d.`CODDOCUMENTO` LEFT JOIN `gmodelo_dbp`.`documentosxriscos` r ON r.`CODDOCUMENTO`=d.`CODDOCUMENTO` INNER JOIN `gmodelo_dbp`.`documentosxunidades` u ON u.`CODDOCUMENTO`=d.`CODDOCUMENTO` LEFT JOIN `gmodelo_dbp`.`perigos` pg ON pg.`CODPERIGO`=p.`CODPERIGO` LEFT JOIN `gmodelo_dbp`.`riscos` rs ON rs.`CODRISCO`=r.`CODRISCO`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lpro_objetivos_metas_grid` AS
SELECT DISTINCT o.`CODOBJETIVO`, o.`CODIGO`, o.`ITEM_ATENDIDO_DA_POLITICA`, o.`OBJETIVO`, o.`META`, o.`INDICADOR`, o.`LIDER`, o.`TIPO_ESTRATEGICO_TATICO`, p.`CODPERIGO`, r.`CODRISCO`, o.`CODUNIDADE`
  FROM `gmodelo_dbp`.`objetivosmetas` o LEFT JOIN `gmodelo_dbp`.`objetivosmetasxperigos` p ON p.`CODOBJETIVO`=o.`CODOBJETIVO` LEFT JOIN `gmodelo_dbp`.`objetivosmetasxriscos` r ON r.`CODOBJETIVO`=o.`CODOBJETIVO`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lpro_infraestrutura_grid` AS
SELECT DISTINCT i.`CODINFRAESTRUTURA`, i.`DSCINFRAESTRUTURA`, a.`CODATIVIDADE`, u.`CODUNIDADE`
  FROM `gmodelo_dbp`.`infraestrutura` i JOIN `gmodelo_dbp`.`atividadesxinfraestrutura` a ON a.`CODINFRAESTRUTURA`=i.`CODINFRAESTRUTURA` JOIN `gmodelo_dbp`.`infraestruturaxunidades` u ON u.`CODINFRAESTRUTURA`=i.`CODINFRAESTRUTURA`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lpro_controles_administrativos_grid` AS
SELECT DISTINCT c.`CODCONTROLEADMIN`, c.`DSCCONTROLEADMIN`, a.`CODATIVIDADE`, u.`CODUNIDADE`
  FROM `gmodelo_dbp`.`controleadmin` c JOIN `gmodelo_dbp`.`atividadesxcontroleadmin` a ON a.`CODCONTROLEADMIN`=c.`CODCONTROLEADMIN` JOIN `gmodelo_dbp`.`controleadminxunidades` u ON u.`CODCONTROLEADMIN`=c.`CODCONTROLEADMIN`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lpro_epis_grid` AS
SELECT DISTINCT e.`CODEPIS`, e.`DSCEPIS`, a.`CODATIVIDADE`, u.`CODUNIDADE`
  FROM `gmodelo_dbp`.`epis` e JOIN `gmodelo_dbp`.`atividadesxepis` a ON a.`CODEPIS`=e.`CODEPIS` JOIN `gmodelo_dbp`.`episxunidades` u ON u.`CODEPIS`=e.`CODEPIS`;

INSERT INTO `gmodelo_dbp`.`registros_migracao` (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('LPRO_RELACIONAMENTOS_LEGADO_081','green_modelo.lpro_relacionamentos','gmodelo_dbp.vw_lpro_*_grid','DADOS','1.0.0','CONCLUIDA',0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,JSON_OBJECT('transacoes',6,'filtros_contextuais',TRUE))
ON DUPLICATE KEY UPDATE status=VALUES(status),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
SET FOREIGN_KEY_CHECKS=1;
