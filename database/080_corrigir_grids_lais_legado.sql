-- LAIS: views dos tres grids contextuais conforme lais_c.php e vlais.php.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lais_requisitos_legais_grid` AS
SELECT DISTINCT r.`CODLEGISLACAO`,
       CONCAT(COALESCE(l.`CODIGO`, ''), '/', COALESCE(l.`NR`, ''), '/', COALESCE(l.`ANO`, '')) AS `LEGISLACAO`,
       l.`CODIGO` AS `ARQUIVO`, l.`CODIGO` AS `CODIGO`, r.`CODASPECTOSOCIAL`, NULL AS `CODIMPACTOSOCIAL`,
       r.`codunidade`
  FROM `gmodelo_dbp`.`lais_legislacaoxaspectossociais` r
  LEFT JOIN `glegislacao_dbp`.`legislacao` l ON l.`CODLEGISLACAO`=r.`CODLEGISLACAO`
 UNION
SELECT DISTINCT r.`CODLEGISLACAO`,
       CONCAT(COALESCE(l.`CODIGO`, ''), '/', COALESCE(l.`NR`, ''), '/', COALESCE(l.`ANO`, '')) AS `LEGISLACAO`,
       l.`CODIGO` AS `ARQUIVO`, l.`CODIGO` AS `CODIGO`, NULL AS `CODASPECTOSOCIAL`, r.`CODIMPACTOSOCIAL`,
       r.`codunidade`
  FROM `gmodelo_dbp`.`lais_legislacaoximpactossociais` r
  LEFT JOIN `glegislacao_dbp`.`legislacao` l ON l.`CODLEGISLACAO`=r.`CODLEGISLACAO`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lais_controles_documentos_grid` AS
SELECT DISTINCT d.`CODDOCUMENTO`, d.`CODIGO`, d.`TITULO`, d.`ARQUIVO`,
       r.`CODASPECTOSOCIAL`, NULL AS `CODIMPACTOSOCIAL`, u.`CODUNIDADE` AS `codunidade`
  FROM `gmodelo_dbp`.`lais_documentosxaspectos` r
  INNER JOIN `gmodelo_dbp`.`documentos` d ON d.`CODDOCUMENTO`=r.`CODDOCUMENTO`
  INNER JOIN `gmodelo_dbp`.`documentosxunidades` u ON u.`CODDOCUMENTO`=d.`CODDOCUMENTO`
 UNION
SELECT DISTINCT d.`CODDOCUMENTO`, d.`CODIGO`, d.`TITULO`, d.`ARQUIVO`,
       NULL AS `CODASPECTOSOCIAL`, r.`CODIMPACTOSOCIAL`, u.`CODUNIDADE` AS `codunidade`
  FROM `gmodelo_dbp`.`lais_documentosximpactos` r
  INNER JOIN `gmodelo_dbp`.`documentos` d ON d.`CODDOCUMENTO`=r.`CODDOCUMENTO`
  INNER JOIN `gmodelo_dbp`.`documentosxunidades` u ON u.`CODDOCUMENTO`=d.`CODDOCUMENTO`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lais_objetivos_metas_grid` AS
SELECT DISTINCT o.`CODOBJETIVO`, o.`CODIGO` AS `codigo`,
       o.`ITEM_ATENDIDO_DA_POLITICA` AS `item_atendido_da_politica`,
       o.`OBJETIVO` AS `objetivo`, o.`META` AS `meta`, o.`INDICADOR` AS `indicador`,
       o.`LIDER` AS `lider`, o.`TIPO_ESTRATEGICO_TATICO` AS `tipo_estrategico_tatico`,
       x.`CODASPECTOSOCIAL`, o.`codunidade`
  FROM `gmodelo_dbp`.`objetivosmetas` o
  INNER JOIN `gmodelo_dbp`.`objetivosmetasxaspectossociais` x ON x.`CODOBJETIVO`=o.`CODOBJETIVO`;

INSERT INTO `gmodelo_dbp`.`registros_migracao`
 (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('LAIS_GRIDS_LEGADO_080','E:/wamp/www/cadastros/lais_c.php; E:/wamp/www/configuracoes/vlais.php','gmodelo_dbp.vw_lais_*_grid','CORRECAO','1.1.0','CONCLUIDA',
 (SELECT COUNT(*) FROM `gmodelo_dbp`.`vw_lais_requisitos_legais_grid`)+(SELECT COUNT(*) FROM `gmodelo_dbp`.`vw_lais_controles_documentos_grid`)+(SELECT COUNT(*) FROM `gmodelo_dbp`.`vw_lais_objetivos_metas_grid`),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
 JSON_OBJECT('filtros_contextuais',TRUE,'requisitos_por_aspecto_ou_impacto',TRUE,'documentos_por_aspecto_ou_impacto',TRUE,'transacao','modulolegal_lais'))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
