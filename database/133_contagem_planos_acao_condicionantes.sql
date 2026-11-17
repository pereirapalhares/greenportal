USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_licencas_condicionantes_grid` AS
SELECT c.`CODCONDICIONANTE`, c.`CODLICENCA`, c.`DSCCONDICIONANTE`,
       c.`FREQUENCIA`, c.`ATENDIDO`, c.`CODUSUARIO`,
       COALESCE(u.`nome`, '') AS `RESPONSAVEL`, c.`OBS`,
       (SELECT COUNT(*) FROM `li_planodeacao` p
         WHERE p.`CODCONDICIONANTE` = c.`CODCONDICIONANTE`) AS `QTD_PLANOS_ACAO`
  FROM `li_condicionantes` c
  LEFT JOIN `usuarios` u ON u.`codusuario` = c.`CODUSUARIO`;
