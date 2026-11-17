-- Green Portal - estruturas de empresa, unidade e atualizacao da legislacao.
-- Origem: green_modelo / green_legislacao (latin1)
-- Destinos: gmodelo_dbp e glegislacao_dbp (utf8mb4_0900_ai_ci)
-- As chaves legadas sao preservadas.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`unidades` LIKE `green_modelo`.`unidades`;
ALTER TABLE `gmodelo_dbp`.`unidades` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
INSERT INTO `gmodelo_dbp`.`unidades` (`CODUNIDADE`, `DSCUNIDADE`, `usado_em_manutencao_legal`, `tipo_de_rcl`)
SELECT `CODUNIDADE`, `DSCUNIDADE`, `usado_em_manutencao_legal`, `tipo_de_rcl`
  FROM `green_modelo`.`unidades` origem
ON DUPLICATE KEY UPDATE
    `DSCUNIDADE` = VALUES(`DSCUNIDADE`),
    `usado_em_manutencao_legal` = VALUES(`usado_em_manutencao_legal`),
    `tipo_de_rcl` = VALUES(`tipo_de_rcl`);

CREATE TABLE IF NOT EXISTS `glegislacao_dbp`.`unidades` LIKE `green_modelo`.`unidades`;
ALTER TABLE `glegislacao_dbp`.`unidades` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
INSERT INTO `glegislacao_dbp`.`unidades` (`CODUNIDADE`, `DSCUNIDADE`, `usado_em_manutencao_legal`, `tipo_de_rcl`)
SELECT `CODUNIDADE`, `DSCUNIDADE`, `usado_em_manutencao_legal`, `tipo_de_rcl`
  FROM `green_modelo`.`unidades` origem
ON DUPLICATE KEY UPDATE
    `DSCUNIDADE` = VALUES(`DSCUNIDADE`),
    `usado_em_manutencao_legal` = VALUES(`usado_em_manutencao_legal`),
    `tipo_de_rcl` = VALUES(`tipo_de_rcl`);

INSERT INTO `glegislacao_dbp`.`legislacaoxalteracao`
    (`codalteracao`, `codlegislacao`, `tipo`, `descricao`, `data_da_alteracao`)
SELECT `codalteracao`, `codlegislacao`, `tipo`, `descricao`, `data_da_alteracao`
  FROM `green_legislacao`.`legislacaoxalteracao` origem
ON DUPLICATE KEY UPDATE
    `codlegislacao` = VALUES(`codlegislacao`),
    `tipo` = VALUES(`tipo`),
    `descricao` = VALUES(`descricao`),
    `data_da_alteracao` = VALUES(`data_da_alteracao`);

DROP VIEW IF EXISTS `glegislacao_dbp`.`vw_legislacao_grid`;
CREATE VIEW `glegislacao_dbp`.`vw_legislacao_grid` AS
SELECT l.`CODLEGISLACAO`, l.`CODIGO`,
       rel.`CODEMPRESA`, rel.`CODUNIDADE`,
       alt.`MES_ANO_ATUALIZACAO`,
       l.`CODAMBITO`, l.`CODTIPO`, l.`NR`, l.`ANO`, l.`ARTIGOS`,
       l.`MEIO_AMBIENTE`, l.`SEGURANCA`, l.`QUALIDADE`, l.`RESPOSABILIDADE`, l.`PADRAO`,
       l.`data_atualizacao`, l.`situacao_da_lei`, l.`devido_da_lei`,
       (SELECT GROUP_CONCAT(a.`DSCASSUNTO` ORDER BY a.`DSCASSUNTO` SEPARATOR '; ')
          FROM `glegislacao_dbp`.`legislacaoxassunto` la
          INNER JOIN `glegislacao_dbp`.`assuntos` a ON a.`CODASSUNTO` = la.`CODASSUNTO`
         WHERE la.`CODLEGISLACAO` = l.`CODLEGISLACAO`) AS `ASSUNTOS`
  FROM `glegislacao_dbp`.`legislacao` l
  LEFT JOIN (
      SELECT `CODLEGISLACAO`,
             CONCAT('|', GROUP_CONCAT(DISTINCT `CODEMPRESA` ORDER BY `CODEMPRESA` SEPARATOR '|'), '|') AS `CODEMPRESA`,
             CONCAT('|', GROUP_CONCAT(DISTINCT `codunidade` ORDER BY `codunidade` SEPARATOR '|'), '|') AS `CODUNIDADE`
        FROM `gmodelo_dbp`.`legislacaoxempresa`
       GROUP BY `CODLEGISLACAO`
  ) rel ON rel.`CODLEGISLACAO` = l.`CODLEGISLACAO`
  LEFT JOIN (
      SELECT `codlegislacao`,
             CONCAT('|', GROUP_CONCAT(DISTINCT DATE_FORMAT(`data_da_alteracao`, '%Y-%m') ORDER BY `data_da_alteracao` SEPARATOR '|'), '|') AS `MES_ANO_ATUALIZACAO`
        FROM `glegislacao_dbp`.`legislacaoxalteracao`
       WHERE `data_da_alteracao` IS NOT NULL
       GROUP BY `codlegislacao`
  ) alt ON alt.`codlegislacao` = l.`CODLEGISLACAO`;

INSERT INTO `glegislacao_dbp`.`registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_origem`, `quantidade_destino`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('LEGISLACAO_UNIDADES_FILTROS', 'green_modelo.unidades', 'gmodelo_dbp.unidades/glegislacao_dbp.unidades', 'DADOS', '1.0.0', 'CONCLUIDA', (SELECT COUNT(*) FROM `green_modelo`.`unidades`), (SELECT COUNT(*) FROM `glegislacao_dbp`.`unidades`), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, JSON_OBJECT('chave_primaria', 'CODUNIDADE', 'charset_origem', 'latin1', 'collation_destino', 'utf8mb4_0900_ai_ci'))
ON DUPLICATE KEY UPDATE quantidade_origem = VALUES(quantidade_origem), quantidade_destino = VALUES(quantidade_destino), concluida_em = VALUES(concluida_em), status = VALUES(status), detalhes = VALUES(detalhes);

INSERT INTO `glegislacao_dbp`.`registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_origem`, `quantidade_destino`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('LEGISLACAO_ALTERACAO_ATUALIZADA', 'green_legislacao.legislacaoxalteracao', 'glegislacao_dbp.legislacaoxalteracao', 'DADOS', '1.0.0', 'CONCLUIDA', (SELECT COUNT(*) FROM `green_legislacao`.`legislacaoxalteracao`), (SELECT COUNT(*) FROM `glegislacao_dbp`.`legislacaoxalteracao`), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, JSON_OBJECT('chave_primaria', 'codalteracao', 'relacionamento', 'codlegislacao', 'charset_origem', 'latin1', 'collation_destino', 'utf8mb4_0900_ai_ci'))
ON DUPLICATE KEY UPDATE quantidade_origem = VALUES(quantidade_origem), quantidade_destino = VALUES(quantidade_destino), concluida_em = VALUES(concluida_em), status = VALUES(status), detalhes = VALUES(detalhes);

SET FOREIGN_KEY_CHECKS = 1;
