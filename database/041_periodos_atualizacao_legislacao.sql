-- Green Portal - periodos de atualizacao da legislacao.
USE `glegislacao_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_legislacao_atualizacao_periodos` AS
SELECT DISTINCT DATE_FORMAT(`data_atualizacao`, '%Y-%m') AS `periodo`
  FROM `legislacao`
 WHERE `data_atualizacao` IS NOT NULL
 ORDER BY `periodo` DESC;
