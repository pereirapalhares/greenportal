-- Green Portal - Modulo Legal / LPRO

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`lpro`
  LIKE `green_modelo`.`lpro`;
ALTER TABLE `gmodelo_dbp`.`lpro`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`lpro`
  SELECT * FROM `green_modelo`.`lpro`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`lpro_tipo_de_atividade`
  LIKE `green_universal`.`lpro_tipo_de_atividade`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`lpro_situacao`
  LIKE `green_universal`.`lpro_situacao`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`lpro_abrangencia`
  LIKE `green_universal`.`lpro_abrangencia`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`lpro_natureza_do_perigo`
  LIKE `green_universal`.`lpro_natureza_do_perigo`;
ALTER TABLE `gmodelo_dbp`.`lpro_tipo_de_atividade` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
ALTER TABLE `gmodelo_dbp`.`lpro_situacao` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
ALTER TABLE `gmodelo_dbp`.`lpro_abrangencia` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
ALTER TABLE `gmodelo_dbp`.`lpro_natureza_do_perigo` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`lpro_tipo_de_atividade` SELECT * FROM `green_universal`.`lpro_tipo_de_atividade`;
INSERT IGNORE INTO `gmodelo_dbp`.`lpro_situacao` SELECT * FROM `green_universal`.`lpro_situacao`;
INSERT IGNORE INTO `gmodelo_dbp`.`lpro_abrangencia` SELECT * FROM `green_universal`.`lpro_abrangencia`;
INSERT IGNORE INTO `gmodelo_dbp`.`lpro_natureza_do_perigo` SELECT * FROM `green_universal`.`lpro_natureza_do_perigo`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lpro_grid` AS
SELECT l.`CODLPRO`, l.`CODUNIDADE`, u.`DSCUNIDADE`, l.`CODAREA`, ar.`DSCAREA`,
       l.`CODATIVIDADE`, atv.`DSCATIVIDADE`, l.`detalhamento`, l.`CODPERIGO`,
       pg.`DSCPERIGO`, l.`TIPO_DE_ATIVIDADE`, ta.`DSCTIPO_DE_ATIVIDADE`,
       l.`SITUACAO`, sit.`DSCSITUACAO`, l.`ABRANGENCIA`, ab.`DSCABRANGENCIA`,
       l.`NATUREZA_DO_PERIGO`, np.`DSCNATUREZA_DO_PERIGO`, l.`CODRISCO`,
       r.`DSCRISCO`, l.`SEVERIDADE`, l.`OCORRENCIA`, l.`DETECCAO`,
       (COALESCE(l.`DETECCAO`, 0) * COALESCE(l.`SEVERIDADE`, 0) *
        COALESCE(l.`OCORRENCIA`, 0)) AS `TOTAL`,
       CASE
         WHEN (COALESCE(l.`DETECCAO`, 0) * COALESCE(l.`SEVERIDADE`, 0) * COALESCE(l.`OCORRENCIA`, 0)) = 27 THEN 'INTOLERÁVEL'
         WHEN (COALESCE(l.`DETECCAO`, 0) * COALESCE(l.`SEVERIDADE`, 0) * COALESCE(l.`OCORRENCIA`, 0)) = 8 THEN 'MODERADO'
         WHEN (COALESCE(l.`DETECCAO`, 0) * COALESCE(l.`SEVERIDADE`, 0) * COALESCE(l.`OCORRENCIA`, 0)) BETWEEN 1 AND 6 THEN 'TRIVIAL'
         WHEN (COALESCE(l.`DETECCAO`, 0) * COALESCE(l.`SEVERIDADE`, 0) * COALESCE(l.`OCORRENCIA`, 0)) BETWEEN 9 AND 18
              AND l.`SEVERIDADE` = 3 THEN 'SUBSTANCIAL'
         WHEN l.`SEVERIDADE` = 3 THEN 'SUBSTANCIAL'
         ELSE ''
       END AS `TOLERANCIA`,
       l.`ELIMINAR_RISCO`, l.`ANALISAR_SUBST_RISCO`, l.`DISPOSICAO_ATENUANTE`,
       l.`APLICAVEL_MEDIDAS_ENG`, l.`POSSUI_MEDIDAS_ENG`,
       l.`APLICAVEL_MEDIDAS_ADM`, l.`POSSUI_MEDIDAS_ADM`,
       l.`APLICAVEL_ADOCAO_EPIS`, l.`ADOTAR_EPIS`, l.`NECESSIDADE_NOVOS_CONTROLES`,
       l.`FILTROB`, l.`FILTROC`, l.`FILTROD`, l.`dano`, l.`data_trilha`,
       l.`userid_trilha`
  FROM `gmodelo_dbp`.`lpro` l
  LEFT JOIN `gmodelo_dbp`.`unidades` u ON u.`CODUNIDADE` = l.`CODUNIDADE`
  LEFT JOIN `gmodelo_dbp`.`areas` ar ON ar.`CODAREA` = l.`CODAREA`
  LEFT JOIN `gmodelo_dbp`.`atividades` atv ON atv.`CODATIVIDADE` = l.`CODATIVIDADE`
  LEFT JOIN `gmodelo_dbp`.`perigos` pg ON pg.`CODPERIGO` = l.`CODPERIGO`
  LEFT JOIN `gmodelo_dbp`.`riscos` r ON r.`CODRISCO` = l.`CODRISCO`
  LEFT JOIN `gmodelo_dbp`.`lpro_tipo_de_atividade` ta ON ta.`TIPO_DE_ATIVIDADE` = l.`TIPO_DE_ATIVIDADE`
  LEFT JOIN `gmodelo_dbp`.`lpro_situacao` sit ON sit.`SITUACAO` = l.`SITUACAO`
  LEFT JOIN `gmodelo_dbp`.`lpro_abrangencia` ab ON ab.`ABRANGENCIA` = l.`ABRANGENCIA`
  LEFT JOIN `gmodelo_dbp`.`lpro_natureza_do_perigo` np ON np.`NATUREZA_DO_PERIGO` = l.`NATUREZA_DO_PERIGO`;

INSERT INTO `gmodelo_dbp`.`menus`
    (`codmenu_pai`, `modulo`, `codigo`, `label`, `label_pt`, `label_en`, `label_es`, `url`, `nivel`, `ordem`, `ativo`)
SELECT p.`codmenu`, 'MODULO_LEGAL', 'LPRO', 'LPRO', 'LPRO', 'LPRO', 'LPRO', '/principal/modulolegal_lpro.php', 2, 24, 1
  FROM `gmodelo_dbp`.`menus` p
 WHERE p.`codigo` = 'MODULO_LEGAL'
   AND NOT EXISTS (SELECT 1 FROM `gmodelo_dbp`.`menus` e WHERE e.`codigo` = 'LPRO');

INSERT INTO `gmodelo_dbp`.`permissoes`
    (`codperfil`, `codmenu`, `pode_incluir`, `pode_visualizar`, `pode_editar`, `pode_excluir`, `pode_exportar_excel`, `pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`, IF(p.`codigo`='ADMINISTRADOR',1,0), IF(p.`codigo`='ADMINISTRADOR',1,0), IF(p.`codigo`='ADMINISTRADOR',1,0), IF(p.`codigo`='ADMINISTRADOR',1,0), IF(p.`codigo`='ADMINISTRADOR',1,0), 0
  FROM `gmodelo_dbp`.`perfis` p CROSS JOIN `gmodelo_dbp`.`menus` m
 WHERE p.`ativo`=1 AND m.`codigo`='LPRO'
   AND NOT EXISTS (SELECT 1 FROM `gmodelo_dbp`.`permissoes` e WHERE e.`codperfil`=p.`codperfil` AND e.`codmenu`=m.`codmenu`);

INSERT INTO `gmodelo_dbp`.`registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('MODULO_LEGAL_LPRO_058','green_modelo.lpro; green_universal.lpro_*','gmodelo_dbp.lpro; gmodelo_dbp.lpro_*','DADOS','1.0.0','CONCLUIDA',
        (SELECT COUNT(*) FROM `gmodelo_dbp`.`lpro`),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
        JSON_OBJECT('resultado_formula','DETECCAO * SEVERIDADE * OCORRENCIA','tolerancia_calculada',TRUE,'menus',1,'transacao','/principal/modulolegal_lpro.php'))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);

SET FOREIGN_KEY_CHECKS = 1;
