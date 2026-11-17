-- Green Portal - Administração: Unidades, Áreas e Atividades.
-- Textos legados latin1 convertidos para utf8mb4.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `gmodelo_dbp`;

-- A tabela de unidades existente possuía 16 registros duplicados exatamente.
-- A normalização preserva uma linha por CODUNIDADE para permitir CRUD seguro.
DROP VIEW IF EXISTS `vw_unidades_grid`;
DROP TABLE IF EXISTS `unidades_admin_tmp`;
CREATE TABLE `unidades_admin_tmp` (
    `CODUNIDADE` int NOT NULL,
    `DSCUNIDADE` varchar(500) DEFAULT NULL,
    `usado_em_manutencao_legal` varchar(3) DEFAULT NULL,
    `tipo_de_rcl` varchar(100) DEFAULT NULL,
    PRIMARY KEY (`CODUNIDADE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
INSERT INTO `unidades_admin_tmp` (`CODUNIDADE`, `DSCUNIDADE`, `usado_em_manutencao_legal`, `tipo_de_rcl`)
SELECT `CODUNIDADE`, MAX(CONVERT(`DSCUNIDADE` USING utf8mb4)), MAX(CONVERT(`usado_em_manutencao_legal` USING utf8mb4)), MAX(CONVERT(`tipo_de_rcl` USING utf8mb4))
FROM `unidades` GROUP BY `CODUNIDADE`;
DROP TABLE `unidades`;
RENAME TABLE `unidades_admin_tmp` TO `unidades`;

CREATE TABLE IF NOT EXISTS `areas` (
    `CODAREA` int NOT NULL,
    `DSCAREA` varchar(500) DEFAULT NULL,
    PRIMARY KEY (`CODAREA`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS `atividades` (
    `CODATIVIDADE` int NOT NULL,
    `DSCATIVIDADE` varchar(5000) DEFAULT NULL,
    PRIMARY KEY (`CODATIVIDADE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELETE FROM `areas`;
INSERT INTO `areas` (`CODAREA`, `DSCAREA`)
SELECT `CODAREA`, CONVERT(`DSCAREA` USING utf8mb4) FROM `green_modelo`.`areas`;
DELETE FROM `atividades`;
INSERT INTO `atividades` (`CODATIVIDADE`, `DSCATIVIDADE`)
SELECT `CODATIVIDADE`, CONVERT(`DSCATIVIDADE` USING utf8mb4) FROM `green_modelo`.`atividades`;

CREATE OR REPLACE VIEW `vw_unidades_grid` AS
SELECT `CODUNIDADE`, `DSCUNIDADE`, `usado_em_manutencao_legal`, `tipo_de_rcl` FROM `unidades`;
CREATE OR REPLACE VIEW `vw_areas_grid` AS
SELECT `CODAREA`, `DSCAREA` FROM `areas`;
CREATE OR REPLACE VIEW `vw_atividades_grid` AS
SELECT `CODATIVIDADE`, `DSCATIVIDADE` FROM `atividades`;

INSERT INTO `menus` (`codmenu_pai`, `modulo`, `codigo`, `label`, `label_pt`, `label_en`, `label_es`, `url`, `nivel`, `ordem`, `ativo`)
SELECT NULL, 'ADMINISTRACAO', 'ADMINISTRACAO', 'Administração', 'Administração', 'Administration', 'Administración', NULL, 1, 5, 1
WHERE NOT EXISTS (SELECT 1 FROM `menus` WHERE `codigo` = 'ADMINISTRACAO');

INSERT INTO `menus` (`codmenu_pai`, `modulo`, `codigo`, `label`, `label_pt`, `label_en`, `label_es`, `url`, `nivel`, `ordem`, `ativo`)
SELECT m.`codmenu`, 'ADMINISTRACAO', x.`codigo`, x.`label_pt`, x.`label_pt`, x.`label_en`, x.`label_es`, x.`url`, 2, x.`ordem`, 1
FROM `menus` m JOIN (
    SELECT 'ADMINISTRACAO_UNIDADES' codigo, 'Unidades' label_pt, 'Units' label_en, 'Unidades' label_es, '/principal/administracao_unidades.php' url, 1 ordem
    UNION ALL SELECT 'ADMINISTRACAO_AREAS', 'Áreas', 'Areas', 'Áreas', '/principal/administracao_areas.php', 2
    UNION ALL SELECT 'ADMINISTRACAO_ATIVIDADES', 'Atividades', 'Activities', 'Actividades', '/principal/administracao_atividades.php', 3
) x ON 1=1
WHERE m.`codigo` = 'ADMINISTRACAO'
  AND NOT EXISTS (SELECT 1 FROM `menus` e WHERE e.`codigo` = x.`codigo`);

INSERT INTO `permissoes` (`codperfil`, `codmenu`, `pode_incluir`, `pode_visualizar`, `pode_editar`, `pode_excluir`, `pode_exportar_excel`, `pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END,
       CASE WHEN p.`codigo` = 'ADMINISTRADOR' THEN 1 ELSE 0 END
FROM `perfis` p CROSS JOIN `menus` m
WHERE p.`ativo` = 1 AND m.`codigo` IN ('ADMINISTRACAO','ADMINISTRACAO_UNIDADES','ADMINISTRACAO_AREAS','ADMINISTRACAO_ATIVIDADES')
  AND NOT EXISTS (SELECT 1 FROM `permissoes` e WHERE e.`codperfil` = p.`codperfil` AND e.`codmenu` = m.`codmenu`);
