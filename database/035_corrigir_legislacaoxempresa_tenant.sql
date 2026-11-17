-- Correção: legislacaoxempresa pertence ao banco da empresa (tenant).
-- No ambiente Modelo, o destino correto é gmodelo_dbp.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`legislacaoxempresa` LIKE `green_modelo`.`legislacaoxempresa`;
ALTER TABLE `gmodelo_dbp`.`legislacaoxempresa` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
INSERT INTO `gmodelo_dbp`.`legislacaoxempresa`
    (`CODLEGISLACAXEMPRESA`, `CODLEGISLACAO`, `CODEMPRESA`, `codunidade`, `atendido`, `tipo_de_lei`, `codlegislacaocodunidade`)
SELECT `CODLEGISLACAXEMPRESA`, `CODLEGISLACAO`, `CODEMPRESA`, `codunidade`, `atendido`, `tipo_de_lei`, `codlegislacaocodunidade`
  FROM `green_modelo`.`legislacaoxempresa`
ON DUPLICATE KEY UPDATE
    `CODLEGISLACAO` = VALUES(`CODLEGISLACAO`),
    `CODEMPRESA` = VALUES(`CODEMPRESA`),
    `codunidade` = VALUES(`codunidade`),
    `atendido` = VALUES(`atendido`),
    `tipo_de_lei` = VALUES(`tipo_de_lei`),
    `codlegislacaocodunidade` = VALUES(`codlegislacaocodunidade`);

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

DROP TABLE IF EXISTS `glegislacao_dbp`.`legislacaoxempresa`;

INSERT INTO `gmodelo_dbp`.`registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('LEGISLACAO_EMPRESA_UNIDADE_TENANT', 'green_modelo.legislacaoxempresa', 'gmodelo_dbp.legislacaoxempresa', 'DADOS', '1.0.1', 'CONCLUIDA', (SELECT COUNT(*) FROM `gmodelo_dbp`.`legislacaoxempresa`), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, JSON_OBJECT('tabela_empresa', TRUE, 'relacionamento', 'CODLEGISLACAO/CODEMPRESA/codunidade', 'banco_legislacao_nao_possui_copia', TRUE))
ON DUPLICATE KEY UPDATE quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), status = VALUES(status), detalhes = VALUES(detalhes);

SET FOREIGN_KEY_CHECKS = 1;
