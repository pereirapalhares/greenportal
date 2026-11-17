-- Green Portal - Migracao de legislacaoxpoder.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `legislacaoxpoder` (
    `CODLEGISLACAO` int DEFAULT NULL,
    `poder` varchar(100) DEFAULT NULL,
    `codunidade` int DEFAULT NULL,
    UNIQUE KEY `icodlegislacaopoderunidade` (`CODLEGISLACAO`,`poder`,`codunidade`),
    KEY `ix_lxp_legislacao_unidade` (`CODLEGISLACAO`,`codunidade`),
    KEY `ix_lxp_unidade` (`codunidade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELETE FROM `legislacaoxpoder`;

INSERT INTO `legislacaoxpoder` (`CODLEGISLACAO`, `poder`, `codunidade`)
SELECT `CODLEGISLACAO`, CONVERT(`poder` USING utf8mb4), `codunidade`
  FROM `green_modelo`.`legislacaoxpoder`
 GROUP BY `CODLEGISLACAO`, `poder`, `codunidade`;

CREATE OR REPLACE VIEW `vw_legislacaoxpoder_grid` AS
SELECT x.`CODLEGISLACAO`, x.`poder`, x.`codunidade`, l.`CODIGO`, l.`data_atualizacao`,
       (SELECT MIN(e2.`CODEMPRESA`) FROM `legislacaoxempresa` e2 WHERE e2.`CODLEGISLACAO`=x.`CODLEGISLACAO`) AS `CODEMPRESA`,
       DATE_FORMAT(l.`data_atualizacao`, '%Y-%m') AS `MES_ANO_ATUALIZACAO`,
       CASE WHEN EXISTS (SELECT 1 FROM `legislacaoxempresa` e WHERE e.`CODLEGISLACAO`=x.`CODLEGISLACAO`) THEN 'Sim' ELSE 'Não' END AS `CORRELACIONADO`,
       u.`DSCUNIDADE`
  FROM `legislacaoxpoder` x
  LEFT JOIN `glegislacao_dbp`.`legislacao` l
    ON l.`CODLEGISLACAO` = x.`CODLEGISLACAO`
  LEFT JOIN (SELECT `CODUNIDADE`, MAX(`DSCUNIDADE`) AS `DSCUNIDADE`
               FROM `unidades`
              GROUP BY `CODUNIDADE`) u
    ON u.`CODUNIDADE` = x.`codunidade`;

CREATE OR REPLACE VIEW `vw_legislacaoxpoder_matrix` AS
SELECT MIN(x.`CODLEGISLACAO`) AS `CODLEGISLACAO`, l.`CODIGO`,
       MAX((SELECT MIN(e2.`CODEMPRESA`) FROM `legislacaoxempresa` e2 WHERE e2.`CODLEGISLACAO`=x.`CODLEGISLACAO`)) AS `CODEMPRESA`,
       DATE_FORMAT(l.`data_atualizacao`, '%Y-%m') AS `MES_ANO_ATUALIZACAO`,
       CASE WHEN EXISTS (SELECT 1 FROM `legislacaoxempresa` e WHERE e.`CODLEGISLACAO`=x.`CODLEGISLACAO`) THEN 'Sim' ELSE 'Não' END AS `CORRELACIONADO`
  FROM `legislacaoxpoder` x
  INNER JOIN `glegislacao_dbp`.`legislacao` l
    ON l.`CODLEGISLACAO` = x.`CODLEGISLACAO`
 GROUP BY x.`CODLEGISLACAO`, l.`CODIGO`, l.`data_atualizacao`;

INSERT INTO `menus`
    (`codmenu_pai`, `modulo`, `codigo`, `label`, `label_pt`, `label_en`, `label_es`, `url`, `nivel`, `ordem`, `ativo`)
SELECT m.`codmenu`, 'MANUTENCAO_LEGAL', 'LEGISLACAO_X_PODER', 'X Poder', 'X Poder', 'X Power', 'X Poder',
       '/principal/manutencaolegal_legislacaoxpoder.php', 2, 12, 1
  FROM `menus` m
 WHERE m.`codigo` = 'MANUTENCAO_LEGAL'
   AND NOT EXISTS (SELECT 1 FROM `menus` e WHERE e.`codigo` = 'LEGISLACAO_X_PODER');

INSERT INTO `permissoes`
    (`codperfil`, `codmenu`, `pode_incluir`, `pode_visualizar`, `pode_editar`, `pode_excluir`, `pode_exportar_excel`, `pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END
  FROM `perfis` p CROSS JOIN `menus` m
 WHERE p.`ativo` = 1 AND m.`codigo` = 'LEGISLACAO_X_PODER'
   AND NOT EXISTS (SELECT 1 FROM `permissoes` e WHERE e.`codperfil` = p.`codperfil` AND e.`codmenu` = m.`codmenu`);
