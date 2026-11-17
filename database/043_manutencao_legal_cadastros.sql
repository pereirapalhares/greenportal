-- Green Portal - Cadastros básicos da Manutenção Legal.
-- As tabelas legadas são latin1; os destinos são sempre utf8mb4.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

USE `gmodelo_dbp`;

CREATE TABLE IF NOT EXISTS `aspectos` (
    `CODASPECTO` int NOT NULL,
    `DSCASPECTO` varchar(5000) DEFAULT NULL,
    PRIMARY KEY (`CODASPECTO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `impactos` (
    `CODIMPACTO` int NOT NULL,
    `DSCIMPACTO` varchar(5000) DEFAULT NULL,
    PRIMARY KEY (`CODIMPACTO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `perigos` (
    `CODPERIGO` int NOT NULL,
    `DSCPERIGO` varchar(5000) DEFAULT NULL,
    PRIMARY KEY (`CODPERIGO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `riscos` (
    `CODRISCO` int NOT NULL,
    `DSCRISCO` varchar(5000) DEFAULT NULL,
    PRIMARY KEY (`CODRISCO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELETE FROM `aspectos`;
INSERT INTO `aspectos` (`CODASPECTO`, `DSCASPECTO`)
SELECT `CODASPECTO`, CONVERT(`DSCASPECTO` USING utf8mb4)
FROM `green_modelo`.`aspectos`;

DELETE FROM `impactos`;
INSERT INTO `impactos` (`CODIMPACTO`, `DSCIMPACTO`)
SELECT `CODIMPACTO`, CONVERT(`DSCIMPACTO` USING utf8mb4)
FROM `green_modelo`.`impactos`;

DELETE FROM `perigos`;
INSERT INTO `perigos` (`CODPERIGO`, `DSCPERIGO`)
SELECT `CODPERIGO`, CONVERT(`DSCPERIGO` USING utf8mb4)
FROM `green_modelo`.`perigos`;

DELETE FROM `riscos`;
INSERT INTO `riscos` (`CODRISCO`, `DSCRISCO`)
SELECT `CODRISCO`, CONVERT(`DSCRISCO` USING utf8mb4)
FROM `green_modelo`.`riscos`;

CREATE OR REPLACE VIEW `vw_aspectos_grid` AS
SELECT `CODASPECTO`, `DSCASPECTO` FROM `aspectos`;
CREATE OR REPLACE VIEW `vw_impactos_grid` AS
SELECT `CODIMPACTO`, `DSCIMPACTO` FROM `impactos`;
CREATE OR REPLACE VIEW `vw_perigos_grid` AS
SELECT `CODPERIGO`, `DSCPERIGO` FROM `perigos`;
CREATE OR REPLACE VIEW `vw_riscos_grid` AS
SELECT `CODRISCO`, `DSCRISCO` FROM `riscos`;
USE `glegislacao_dbp`;

CREATE OR REPLACE VIEW `vw_tipos_grid` AS
SELECT `CODTIPO`, `DSCTIPO` FROM `tipos`;
CREATE OR REPLACE VIEW `vw_ambitos_grid` AS
SELECT `CODAMBITO`, `DSCAMBITO` FROM `ambitos`;

USE `gmodelo_dbp`;

INSERT INTO `menus`
    (`codmenu_pai`, `modulo`, `codigo`, `label`, `label_pt`, `label_en`, `label_es`, `url`, `nivel`, `ordem`, `ativo`)
SELECT m.`codmenu`, 'MANUTENCAO_LEGAL', x.`codigo`, x.`label_pt`, x.`label_pt`, x.`label_en`, x.`label_es`, x.`url`, 2, x.`ordem`, 1
FROM `menus` m
JOIN (
    SELECT 'TIPOS' codigo, 'Tipos' label_pt, 'Types' label_en, 'Tipos' label_es, '/principal/manutencaolegal_tipos.php' url, 13 ordem
    UNION ALL SELECT 'AMBITOS', 'Âmbitos', 'Scopes', 'Ámbitos', '/principal/manutencaolegal_ambitos.php', 14
    UNION ALL SELECT 'ASPECTOS', 'Aspectos', 'Aspects', 'Aspectos', '/principal/manutencaolegal_aspectos.php', 15
    UNION ALL SELECT 'IMPACTOS', 'Impactos', 'Impacts', 'Impactos', '/principal/manutencaolegal_impactos.php', 16
    UNION ALL SELECT 'PERIGOS', 'Perigos', 'Hazards', 'Peligros', '/principal/manutencaolegal_perigos.php', 17
    UNION ALL SELECT 'RISCOS', 'Riscos', 'Risks', 'Riesgos', '/principal/manutencaolegal_riscos.php', 18
    UNION ALL SELECT 'ASSUNTOS', 'Assuntos', 'Subjects', 'Asuntos', '/principal/manutencaolegal_assuntos.php', 19
) x
WHERE m.`codigo` = 'MANUTENCAO_LEGAL'
  AND NOT EXISTS (SELECT 1 FROM `menus` e WHERE e.`codigo` = x.`codigo`);

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
WHERE p.`ativo` = 1
  AND m.`codigo` IN ('TIPOS','AMBITOS','ASPECTOS','IMPACTOS','PERIGOS','RISCOS','ASSUNTOS')
  AND NOT EXISTS (SELECT 1 FROM `permissoes` e WHERE e.`codperfil` = p.`codperfil` AND e.`codmenu` = m.`codmenu`);
