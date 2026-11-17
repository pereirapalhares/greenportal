USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_licencas_condicionantes_planodeacao_grid` AS
SELECT p.`CODPLANOACAO`, p.`CODCONDICIONANTE`, c.`CODLICENCA`,
       p.`ACAO`, p.`CODUSUARIO`, p.`DATAINICIAL`, p.`DATAFINAL`,
       p.`RECURSOS`, p.`STATUS`, COALESCE(u.`nome`, '') AS `RESPONSAVEL`
  FROM `li_planodeacao` p
  LEFT JOIN `li_condicionantes` c ON c.`CODCONDICIONANTE` = p.`CODCONDICIONANTE`
  LEFT JOIN `usuarios` u ON u.`codusuario` = p.`CODUSUARIO`;
