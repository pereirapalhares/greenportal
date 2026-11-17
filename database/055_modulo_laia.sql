-- Green Portal - Modulo LAIA
-- Migra as tabelas do legado e cadastra as quatro transacoes do modulo.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`laia`
  LIKE `green_modelo`.`laia`;
ALTER TABLE `gmodelo_dbp`.`laia`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`laia`
  SELECT * FROM `green_modelo`.`laia`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`destinos`
  LIKE `green_modelo`.`destinos`;
ALTER TABLE `gmodelo_dbp`.`destinos`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`destinos`
  SELECT * FROM `green_modelo`.`destinos`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`lais_revisao`
  LIKE `green_modelo`.`lais_revisao`;
ALTER TABLE `gmodelo_dbp`.`lais_revisao`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`lais_revisao`
  SELECT * FROM `green_modelo`.`lais_revisao`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_laia_grid` AS
SELECT l.`CODLAIA`, l.`CODUNIDADE`, u.`DSCUNIDADE`, l.`CODAREA`, ar.`DSCAREA`,
       l.`CODATIVIDADE`, atv.`DSCATIVIDADE`, l.`detalhamento`,
       l.`CODASPECTO`, asp.`DSCASPECTO`, l.`SITUACAO`, l.`TEMPORALIDADE`,
       l.`INFLUENCIA`, l.`CLASSIFICACAO`, l.`CODDESTINO`, dst.`DSCDESTINO`,
       l.`CODIMPACTO`, imp.`DSCIMPACTO`, l.`ESCALA`, l.`SEVERIDADE`,
       l.`PROBABILIDADE_FREQUENCIA`,
       (COALESCE(l.`ESCALA`, 0) + COALESCE(l.`SEVERIDADE`, 0) +
        COALESCE(l.`PROBABILIDADE_FREQUENCIA`, 0)) AS `RESULTADO`,
       CASE WHEN (COALESCE(l.`ESCALA`, 0) + COALESCE(l.`SEVERIDADE`, 0) +
                       COALESCE(l.`PROBABILIDADE_FREQUENCIA`, 0)) > 7
            THEN 'X' ELSE '' END AS `FILTROB`,
       l.`FILTROC`, l.`FILTROD`, l.`DISPOSICAOATENUANTE`,
       l.`data_trilha`, l.`userid_trilha`
  FROM `gmodelo_dbp`.`laia` l
  LEFT JOIN `gmodelo_dbp`.`unidades` u ON u.`CODUNIDADE` = l.`CODUNIDADE`
  LEFT JOIN `gmodelo_dbp`.`areas` ar ON ar.`CODAREA` = l.`CODAREA`
  LEFT JOIN `gmodelo_dbp`.`atividades` atv ON atv.`CODATIVIDADE` = l.`CODATIVIDADE`
  LEFT JOIN `gmodelo_dbp`.`aspectos` asp ON asp.`CODASPECTO` = l.`CODASPECTO`
  LEFT JOIN `gmodelo_dbp`.`destinos` dst ON dst.`CODDESTINO` = l.`CODDESTINO`
  LEFT JOIN `gmodelo_dbp`.`impactos` imp ON imp.`CODIMPACTO` = l.`CODIMPACTO`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_destinos_grid` AS
SELECT `CODDESTINO`, `DSCDESTINO`
  FROM `gmodelo_dbp`.`destinos`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lais_revisao_grid` AS
SELECT `CODREVISAO`, `DSCREVISAO`, `DATA`
  FROM `gmodelo_dbp`.`lais_revisao`;

INSERT INTO `gmodelo_dbp`.`menus`
    (`codmenu_pai`, `modulo`, `codigo`, `label`, `label_pt`, `label_en`,
     `label_es`, `url`, `nivel`, `ordem`, `ativo`)
SELECT p.`codmenu`, 'MODULO_LEGAL', x.`codigo`, x.`label_pt`, x.`label_pt`,
       x.`label_en`, x.`label_es`, x.`url`, 2, x.`ordem`, 1
  FROM `gmodelo_dbp`.`menus` p
 CROSS JOIN (
    SELECT 'LAIA' codigo, 'LAIA' label_pt, 'LAIA' label_en, 'LAIA' label_es,
           '/principal/modulolegal_laia.php' url, 20 ordem
    UNION ALL SELECT 'LAIA_ATIVIDADES', 'Atividades', 'Activities', 'Actividades',
           '/principal/manutencaolegal_atividades.php', 21
    UNION ALL SELECT 'LAIA_DESTINOS', 'Destinos', 'Destinations', 'Destinos',
           '/principal/manutencaolegal_destinos.php', 22
    UNION ALL SELECT 'LAIA_REVISAO', 'Revisão do LAIA', 'LAIA Review', 'Revisión del LAIA',
           '/principal/manutencaolegal_revisaolaia.php', 23
 ) x
 WHERE p.`codigo` = 'MODULO_LEGAL'
   AND NOT EXISTS (SELECT 1 FROM `gmodelo_dbp`.`menus` e WHERE e.`codigo` = x.`codigo`);

INSERT INTO `gmodelo_dbp`.`permissoes`
    (`codperfil`, `codmenu`, `pode_incluir`, `pode_visualizar`, `pode_editar`,
     `pode_excluir`, `pode_exportar_excel`, `pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`,
       IF(p.`codigo` = 'ADMINISTRADOR', 1, 0),
       IF(p.`codigo` = 'ADMINISTRADOR', 1, 0),
       IF(p.`codigo` = 'ADMINISTRADOR', 1, 0),
       IF(p.`codigo` = 'ADMINISTRADOR', 1, 0),
       IF(p.`codigo` = 'ADMINISTRADOR', 1, 0), 0
  FROM `gmodelo_dbp`.`perfis` p
 CROSS JOIN `gmodelo_dbp`.`menus` m
 WHERE p.`ativo` = 1
   AND m.`codigo` IN ('LAIA', 'LAIA_ATIVIDADES', 'LAIA_DESTINOS', 'LAIA_REVISAO')
   AND NOT EXISTS (
       SELECT 1 FROM `gmodelo_dbp`.`permissoes` e
        WHERE e.`codperfil` = p.`codperfil` AND e.`codmenu` = m.`codmenu`
   );

INSERT INTO `gmodelo_dbp`.`registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`,
     `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODULO_LAIA_055',
     'green_modelo.laia; green_modelo.destinos; green_modelo.lais_revisao',
     'gmodelo_dbp.laia; gmodelo_dbp.destinos; gmodelo_dbp.lais_revisao',
     'DADOS', '1.0.0', 'CONCLUIDA',
     (SELECT COUNT(*) FROM `gmodelo_dbp`.`laia`) +
     (SELECT COUNT(*) FROM `gmodelo_dbp`.`destinos`) +
     (SELECT COUNT(*) FROM `gmodelo_dbp`.`lais_revisao`),
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
     JSON_OBJECT('charset_origem', 'latin1',
                 'collation_destino', 'utf8mb4_0900_ai_ci',
                 'engine_destino', 'InnoDB',
                 'chaves_preservadas', TRUE,
                 'menus', 5,
                 'transacoes', 4))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`),
    `quantidade_registros` = VALUES(`quantidade_registros`),
    `concluida_em` = VALUES(`concluida_em`),
    `detalhes` = VALUES(`detalhes`);

SET FOREIGN_KEY_CHECKS = 1;
