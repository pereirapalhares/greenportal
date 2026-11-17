USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_dashboard_situacoes` AS
SELECT CODSITUACAO,CODLEGISLACAO,codunidade,CODUSUARIO,dscsituacao,atendido,
       data_de_avaliacao,data_de_vencimento_avaliacao
FROM legislacaoxsituacao;

CREATE OR REPLACE VIEW `vw_dashboard_planos_acao` AS
SELECT CODPLANOACAO,CODCONDICIONANTE,CODUSUARIO,ACAO,DATAINICIAL,DATAFINAL,
       RECURSOS,STATUS
FROM li_planodeacao;

CREATE OR REPLACE VIEW `vw_dashboard_licencas` AS
SELECT CODLICENCA,DSCLICENCA,DATA_VALIDADE,DATA_REACAO,CODUSUARIO,codunidade
FROM li_licencas;
