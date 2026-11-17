USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO `menus` (`modulo`, `codigo`, `label`, `label_pt`, `label_en`, `label_es`, `url`, `nivel`, `ordem`, `ativo`)
VALUES ('SEGURANCA', 'SEGURANCA_PERFIL', 'Perfis', 'Perfis', 'Profiles', 'Perfiles', '/principal/seguranca_perfil.php', 2, 2, 1)
ON DUPLICATE KEY UPDATE `url` = VALUES(`url`), `label_pt` = VALUES(`label_pt`), `label_en` = VALUES(`label_en`), `label_es` = VALUES(`label_es`), `ativo` = 1;

UPDATE `menus` child JOIN `menus` parent ON parent.`codigo` = 'SEGURANCA'
   SET child.`codmenu_pai` = parent.`codmenu`, child.`nivel` = 2
 WHERE child.`codigo` = 'SEGURANCA_PERFIL';
