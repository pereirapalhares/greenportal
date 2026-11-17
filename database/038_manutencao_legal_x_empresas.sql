-- Green Portal - Manutencao Legal / X Empresas.
-- View de apresentacao e cadastro do item de menu.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_legislacaoxempresa_grid` AS
SELECT x.`CODLEGISLACAXEMPRESA`, x.`CODLEGISLACAO`, x.`CODEMPRESA`,
       x.`codunidade`, x.`tipo_de_lei`, l.`CODIGO`, l.`data_atualizacao`,
       DATE_FORMAT(l.`data_atualizacao`, '%Y-%m') AS `MES_ANO_ATUALIZACAO`,
       u.`DSCUNIDADE`
  FROM `legislacaoxempresa` x
  LEFT JOIN `glegislacao_dbp`.`legislacao` l
    ON l.`CODLEGISLACAO` = x.`CODLEGISLACAO`
  LEFT JOIN (SELECT `CODUNIDADE`, MAX(`DSCUNIDADE`) AS `DSCUNIDADE`
               FROM `unidades`
              GROUP BY `CODUNIDADE`) u
    ON u.`CODUNIDADE` = x.`codunidade`;

CREATE OR REPLACE VIEW `vw_legislacaoxempresa_matrix` AS
SELECT MIN(x.`CODLEGISLACAXEMPRESA`) AS `CODLEGISLACAXEMPRESA`,
       x.`CODLEGISLACAO`, x.`CODEMPRESA`, l.`CODIGO`,
       DATE_FORMAT(l.`data_atualizacao`, '%Y-%m') AS `MES_ANO_ATUALIZACAO`,
       'Sim' AS `CORRELACIONADO`
  FROM `legislacaoxempresa` x
  INNER JOIN `glegislacao_dbp`.`legislacao` l
    ON l.`CODLEGISLACAO` = x.`CODLEGISLACAO`
 GROUP BY x.`CODLEGISLACAO`, x.`CODEMPRESA`, l.`CODIGO`, l.`data_atualizacao`;

INSERT INTO `menus`
    (`codmenu_pai`, `modulo`, `codigo`, `label`, `label_pt`, `label_en`,
     `label_es`, `url`, `nivel`, `ordem`, `ativo`)
SELECT m.`codmenu`, 'MANUTENCAO_LEGAL', 'LEGISLACAO_X_EMPRESAS',
       'X Empresas', 'X Empresas', 'X Companies', 'X Empresas',
       '/principal/manutencaolegal_legislacaoxempresa.php', 2, 11, 1
  FROM `menus` m
 WHERE m.`codigo` = 'MANUTENCAO_LEGAL'
   AND NOT EXISTS (
       SELECT 1 FROM `menus` existente
        WHERE existente.`codigo` = 'LEGISLACAO_X_EMPRESAS'
   );

INSERT INTO `permissoes`
    (`codperfil`, `codmenu`, `pode_incluir`, `pode_visualizar`, `pode_editar`,
     `pode_excluir`, `pode_exportar_excel`, `pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END
  FROM `perfis` p CROSS JOIN `menus` m
 WHERE p.`ativo` = 1 AND m.`codigo` = 'LEGISLACAO_X_EMPRESAS'
   AND NOT EXISTS (
       SELECT 1 FROM `permissoes` existente
        WHERE existente.`codperfil` = p.`codperfil`
          AND existente.`codmenu` = m.`codmenu`
   );
