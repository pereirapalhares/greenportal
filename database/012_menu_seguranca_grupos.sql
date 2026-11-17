USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO `menus` (`modulo`, `codigo`, `label`, `label_pt`, `label_en`, `label_es`, `url`, `nivel`, `ordem`, `ativo`)
VALUES ('SEGURANCA', 'SEGURANCA_GRUPOS', 'Grupos', 'Grupos', 'Groups', 'Grupos', '/principal/seguranca_grupos.php', 2, 1, 1)
ON DUPLICATE KEY UPDATE `url` = VALUES(`url`), `ativo` = 1, `label` = VALUES(`label`), `label_pt` = VALUES(`label_pt`), `label_en` = VALUES(`label_en`), `label_es` = VALUES(`label_es`);

UPDATE `menus` child JOIN `menus` parent ON parent.`codigo` = 'SEGURANCA'
   SET child.`codmenu_pai` = parent.`codmenu`
 WHERE child.`codigo` = 'SEGURANCA_GRUPOS';
