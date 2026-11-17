USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `permissoes` (
    `codpermissao` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codperfil` BIGINT UNSIGNED NOT NULL,
    `codmenu` BIGINT UNSIGNED NOT NULL,
    `pode_incluir` TINYINT(1) NOT NULL DEFAULT 0,
    `pode_visualizar` TINYINT(1) NOT NULL DEFAULT 0,
    `pode_editar` TINYINT(1) NOT NULL DEFAULT 0,
    `pode_excluir` TINYINT(1) NOT NULL DEFAULT 0,
    `pode_exportar_excel` TINYINT(1) NOT NULL DEFAULT 0,
    `pode_gerar_pdf` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`codpermissao`),
    UNIQUE KEY `uk_permissoes_perfil_menu` (`codperfil`, `codmenu`),
    CONSTRAINT `fk_permissoes_perfil` FOREIGN KEY (`codperfil`) REFERENCES `perfis` (`codperfil`),
    CONSTRAINT `fk_permissoes_menu` FOREIGN KEY (`codmenu`) REFERENCES `menus` (`codmenu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `permissoes` (`codperfil`, `codmenu`, `pode_incluir`, `pode_visualizar`, `pode_editar`, `pode_excluir`, `pode_exportar_excel`, `pode_gerar_pdf`)
SELECT pm.`codperfil`, pm.`codmenu`, pm.`pode_incluir`, pm.`pode_visualizar`, pm.`pode_editar`, pm.`pode_excluir`, pm.`pode_exportar_excel`, pm.`pode_gerar_pdf`
  FROM `perfil_menus` pm
 WHERE NOT EXISTS (SELECT 1 FROM `permissoes` p WHERE p.`codperfil`=pm.`codperfil` AND p.`codmenu`=pm.`codmenu`);

CREATE OR REPLACE VIEW `vw_permissoes_grid` AS
SELECT p.`codpermissao`, p.`codperfil`, pf.`codigo` AS `perfil_codigo`, p.`codmenu`,
       m.`codigo` AS `menu_codigo`, m.`label_pt` AS `menu_label`, p.`pode_incluir`, p.`pode_visualizar`,
       p.`pode_editar`, p.`pode_excluir`, p.`pode_exportar_excel`, p.`pode_gerar_pdf`
  FROM `permissoes` p
  JOIN `perfis` pf ON pf.`codperfil`=p.`codperfil`
  JOIN `menus` m ON m.`codmenu`=p.`codmenu`;
