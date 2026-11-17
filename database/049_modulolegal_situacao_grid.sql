USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_modulolegal_situacao_grid` AS
SELECT s.`CODSITUACAO`, s.`CODLEGISLACAO`, s.`codunidade`, s.`CODUSUARIO`,
       u.`nome` AS `RESPONSAVEL`, s.`dscsituacao`, s.`atendido`, s.`OBS`,
       s.`data_de_avaliacao`, s.`data_de_vencimento_avaliacao`, s.`CODLEGISLACAOCODUNIDADE`
FROM `legislacaoxsituacao` s
LEFT JOIN `usuarios` u ON u.`codusuario` = s.`CODUSUARIO`;

CREATE OR REPLACE VIEW `vw_modulolegal_situacao_proximo` AS
SELECT COALESCE(MAX(`CODSITUACAO`), 0) + 1 AS `PROXIMO_CODSITUACAO`
FROM `legislacaoxsituacao`;
