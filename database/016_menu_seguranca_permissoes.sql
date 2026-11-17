USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
INSERT INTO `menus` (`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
VALUES ('SEGURANCA','SEGURANCA_PERMISSOES','Permissões','Permissões','Permissions','Permisos','/principal/seguranca_permissoes.php',2,3,1)
ON DUPLICATE KEY UPDATE `url`=VALUES(`url`),`ativo`=1,`label`=VALUES(`label`),`label_pt`=VALUES(`label_pt`),`label_en`=VALUES(`label_en`),`label_es`=VALUES(`label_es`);
UPDATE `menus` c JOIN `menus` p ON p.`codigo`='SEGURANCA' SET c.`codmenu_pai`=p.`codmenu` WHERE c.`codigo`='SEGURANCA_PERMISSOES';
