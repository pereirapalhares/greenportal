-- Matrizes completas: incluem leis com e sem relacionamento.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_legislacaoxempresa_matrix_all` AS
SELECT l.`CODLEGISLACAO`, l.`CODIGO`,
       (SELECT MIN(x.`CODEMPRESA`) FROM `legislacaoxempresa` x WHERE x.`CODLEGISLACAO`=l.`CODLEGISLACAO`) AS `CODEMPRESA`,
       DATE_FORMAT(l.`data_atualizacao`,'%Y-%m') AS `MES_ANO_ATUALIZACAO`,
       CASE WHEN EXISTS (SELECT 1 FROM `legislacaoxempresa` x WHERE x.`CODLEGISLACAO`=l.`CODLEGISLACAO`) THEN 'Sim' ELSE 'Não' END AS `CORRELACIONADO`
  FROM `glegislacao_dbp`.`legislacao` l;

CREATE OR REPLACE VIEW `vw_legislacaoxpoder_matrix_all` AS
SELECT l.`CODLEGISLACAO`, l.`CODIGO`,
       (SELECT MIN(x.`CODEMPRESA`) FROM `legislacaoxempresa` x WHERE x.`CODLEGISLACAO`=l.`CODLEGISLACAO`) AS `CODEMPRESA`,
       DATE_FORMAT(l.`data_atualizacao`,'%Y-%m') AS `MES_ANO_ATUALIZACAO`,
       CASE WHEN EXISTS (SELECT 1 FROM `legislacaoxpoder` x WHERE x.`CODLEGISLACAO`=l.`CODLEGISLACAO`) THEN 'Sim' ELSE 'Não' END AS `CORRELACIONADO`
  FROM `glegislacao_dbp`.`legislacao` l;
