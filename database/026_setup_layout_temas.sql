USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO `setup_empresa` (`chave`, `valor`, `idioma`)
VALUES ('layout_tema', 'verde', 'pt')
ON DUPLICATE KEY UPDATE `valor` = `valor`;

INSERT INTO `menus` (`modulo`, `codigo`, `label`, `label_pt`, `label_en`, `label_es`, `url`, `nivel`, `ordem`, `ativo`)
SELECT 'SEGURANCA', 'SETUP_LAYOUT', 'Layout', 'Layout', 'Layout', 'Diseño', '/principal/setup_layout.php', 2, 3, 1
WHERE NOT EXISTS (SELECT 1 FROM `menus` WHERE `codigo` = 'SETUP_LAYOUT');

UPDATE `menus` child JOIN `menus` parent ON parent.`codigo` = 'SETUP'
   SET child.`codmenu_pai` = parent.`codmenu`
 WHERE child.`codigo` = 'SETUP_LAYOUT';
