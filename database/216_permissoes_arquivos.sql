USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

ALTER TABLE `permissoes`
    ADD COLUMN `pode_importar_arquivos` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pode_gerar_pdf`,
    ADD COLUMN `pode_visualizar_arquivos` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pode_importar_arquivos`;

CREATE OR REPLACE VIEW `vw_permissoes_grid` AS
SELECT p.`codpermissao`, p.`codperfil`, pf.`codigo` AS `perfil_codigo`, p.`codmenu`,
       m.`codigo` AS `menu_codigo`, m.`label_pt` AS `menu_label`,
       p.`pode_incluir`, p.`pode_visualizar`, p.`pode_visualizar_total`,
       p.`pode_editar`, p.`pode_excluir`, p.`pode_exportar_excel`, p.`pode_gerar_pdf`,
       p.`pode_importar_arquivos`, p.`pode_visualizar_arquivos`
  FROM `permissoes` p
  JOIN `perfis` pf ON pf.`codperfil`=p.`codperfil`
  JOIN `menus` m ON m.`codmenu`=p.`codmenu`;
