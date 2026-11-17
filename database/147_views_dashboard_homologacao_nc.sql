USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_dashboard_homologacao` AS
SELECT f.`CODFORNECEDOR`, f.`CODUNIDADE`, f.`CODUSUARIO_RESPONSAVEL_FORNECEDOR`,
       f.`DATA_DA_PROXIMA_AVALIACAO`
  FROM `fo_fornecedores` f;

CREATE OR REPLACE VIEW `vw_dashboard_nao_conformidades` AS
SELECT v.`CODOCORRENCIA`, v.`NRANO`, v.`ETAPA`,
       v.`SUMARIO_DA_OCORRENCIA` AS `IDENTIFICACAO`,
       v.`CODUSUARIO_ENVOLVIDO`
  FROM `vminhasocorrenciasnc` v
 WHERE COALESCE(v.`ETAPA`, '') <> 'Finalizado';
