-- Green Portal - Transações LAIA conforme LAIAXLegislacao, LAIAXDocumentos e LAIAXObjetivosMetas.
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`vlaiaxlegislacao` AS SELECT * FROM `green_modelo`.`vlaiaxlegislacao` WHERE 1=0;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`documentos` LIKE `green_modelo`.`documentos`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`documentosxaspectos` LIKE `green_modelo`.`documentosxaspectos`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`documentosximpactos` LIKE `green_modelo`.`documentosximpactos`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`documentosxatividades` LIKE `green_modelo`.`documentosxatividades`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`documentosxunidades` LIKE `green_modelo`.`documentosxunidades`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`objetivosmetasxaspectos` LIKE `green_modelo`.`objetivosmetasxaspectos`;

INSERT IGNORE INTO `gmodelo_dbp`.`vlaiaxlegislacao` SELECT * FROM `green_modelo`.`vlaiaxlegislacao`;
INSERT IGNORE INTO `gmodelo_dbp`.`documentos` SELECT * FROM `green_modelo`.`documentos`;
INSERT IGNORE INTO `gmodelo_dbp`.`documentosxaspectos` SELECT * FROM `green_modelo`.`documentosxaspectos`;
INSERT IGNORE INTO `gmodelo_dbp`.`documentosximpactos` SELECT * FROM `green_modelo`.`documentosximpactos`;
INSERT IGNORE INTO `gmodelo_dbp`.`documentosxatividades` SELECT * FROM `green_modelo`.`documentosxatividades`;
INSERT IGNORE INTO `gmodelo_dbp`.`documentosxunidades` SELECT * FROM `green_modelo`.`documentosxunidades`;
INSERT IGNORE INTO `gmodelo_dbp`.`objetivosmetasxaspectos` SELECT * FROM `green_modelo`.`objetivosmetasxaspectos`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_laia_requisitos_legais_grid` AS
SELECT DISTINCT v.`CODLEGISLACAO`, v.`LEGISLACAO`, v.`CODIGO`, v.`ARQUIVO`,
       v.`CODASPECTO`, v.`CODIMPACTO`, v.`CODATIVIDADE`, v.`CODUNIDADE`
  FROM `gmodelo_dbp`.`vlaiaxlegislacao` v;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_laia_controles_documentos_grid` AS
SELECT DISTINCT d.`CODDOCUMENTO`, d.`CODIGO`, d.`TITULO`, d.`ARQUIVO`,
       a.`CODASPECTO`, i.`CODIMPACTO`, t.`CODATIVIDADE`, u.`CODUNIDADE`,
       asp.`DSCASPECTO`, imp.`DSCIMPACTO`, ati.`DSCATIVIDADE`
  FROM `gmodelo_dbp`.`documentos` d
  LEFT JOIN `gmodelo_dbp`.`documentosxaspectos` a ON a.`CODDOCUMENTO`=d.`CODDOCUMENTO`
  LEFT JOIN `gmodelo_dbp`.`documentosximpactos` i ON i.`CODDOCUMENTO`=d.`CODDOCUMENTO`
  LEFT JOIN `gmodelo_dbp`.`documentosxatividades` t ON t.`CODDOCUMENTO`=d.`CODDOCUMENTO`
  LEFT JOIN `gmodelo_dbp`.`documentosxunidades` u ON u.`CODDOCUMENTO`=d.`CODDOCUMENTO`
  LEFT JOIN `gmodelo_dbp`.`aspectos` asp ON asp.`CODASPECTO`=a.`CODASPECTO`
  LEFT JOIN `gmodelo_dbp`.`impactos` imp ON imp.`CODIMPACTO`=i.`CODIMPACTO`
  LEFT JOIN `gmodelo_dbp`.`atividades` ati ON ati.`CODATIVIDADE`=t.`CODATIVIDADE`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_laia_objetivos_metas_grid` AS
SELECT DISTINCT o.`CODOBJETIVO`, o.`CODIGO`, o.`ITEM_ATENDIDO_DA_POLITICA`, o.`OBJETIVO`,
       o.`META`, o.`INDICADOR`, o.`LIDER`, o.`TIPO_ESTRATEGICO_TATICO`,
       x.`CODASPECTO`, o.`codunidade` AS `CODUNIDADE`
  FROM `gmodelo_dbp`.`objetivosmetas` o
  LEFT JOIN `gmodelo_dbp`.`objetivosmetasxaspectos` x ON x.`CODOBJETIVO`=o.`CODOBJETIVO`;

INSERT INTO `gmodelo_dbp`.`menus`
 (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT p.`codmenu`,'MODULO_LEGAL',x.`codigo`,x.`label_pt`,x.`label_pt`,x.`label_en`,x.`label_es`,x.`url`,2,x.`ordem`,1
 FROM `gmodelo_dbp`.`menus` p CROSS JOIN (
 SELECT 'LAIA_REQUISITOS_LEGAIS' codigo,'LAIA - Requisitos legais' label_pt,'LAIA - Legal requirements' label_en,'LAIA - Requisitos legales' label_es,'/principal/modulolegal_requisitoslegais.php' url,29 ordem
 UNION ALL SELECT 'LAIA_CONTROLES_DOCUMENTOS','LAIA - Controles e documentos','LAIA - Controls and documents','LAIA - Controles y documentos','/principal/modulolegal_controles.php',30
 UNION ALL SELECT 'LAIA_OBJETIVOS_METAS','LAIA - Objetivos e metas','LAIA - Objectives and targets','LAIA - Objetivos y metas','/principal/modulolegal_objetivosmetas.php',31
 ) x WHERE p.`codigo`='MODULO_LEGAL' AND NOT EXISTS (SELECT 1 FROM `gmodelo_dbp`.`menus` e WHERE e.`codigo`=x.`codigo`);

INSERT INTO `gmodelo_dbp`.`permissoes`
 (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`,m.`codmenu`,0,IF(p.`codigo`='ADMINISTRADOR',1,0),0,0,IF(p.`codigo`='ADMINISTRADOR',1,0),0
 FROM `gmodelo_dbp`.`perfis` p CROSS JOIN `gmodelo_dbp`.`menus` m
 WHERE p.`ativo`=1 AND m.`codigo` IN ('LAIA_REQUISITOS_LEGAIS','LAIA_CONTROLES_DOCUMENTOS','LAIA_OBJETIVOS_METAS')
 AND NOT EXISTS (SELECT 1 FROM `gmodelo_dbp`.`permissoes` e WHERE e.`codperfil`=p.`codperfil` AND e.`codmenu`=m.`codmenu`);

INSERT INTO `gmodelo_dbp`.`registros_migracao`
 (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('LAIA_TRANSACOES_LEGADO_061','green_modelo.vlaiaxlegislacao; green_modelo.documentos*; green_modelo.objetivosmetasxaspectos','gmodelo_dbp.vlaiaxlegislacao; gmodelo_dbp.documentos*; gmodelo_dbp.objetivosmetasxaspectos','DADOS','1.0.0','CONCLUIDA',
 (SELECT COUNT(*) FROM `gmodelo_dbp`.`vlaiaxlegislacao`)+(SELECT COUNT(*) FROM `gmodelo_dbp`.`documentos`)+(SELECT COUNT(*) FROM `gmodelo_dbp`.`objetivosmetasxaspectos`),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
 JSON_OBJECT('transacoes',JSON_ARRAY('modulolegal_requisitoslegais.php','modulolegal_controles.php','modulolegal_objetivosmetas.php'),'filtro_legado','CODASPECTO_CODIMPACTO_CODATIVIDADE_CODUNIDADE'))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
SET FOREIGN_KEY_CHECKS=1;
