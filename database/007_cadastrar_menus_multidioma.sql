-- Green Portal - cadastro inicial dos menus da empresa Modelo.
-- Os menus ficam na tabela menus e possuem labels PT, EN e ES.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

ALTER TABLE `menus`
    ADD COLUMN `label_pt` VARCHAR(150) NULL AFTER `label`,
    ADD COLUMN `label_en` VARCHAR(150) NULL AFTER `label_pt`,
    ADD COLUMN `label_es` VARCHAR(150) NULL AFTER `label_en`;

UPDATE `menus`
   SET `label_pt` = COALESCE(NULLIF(`label_pt`, ''), `label`),
       `label_en` = COALESCE(NULLIF(`label_en`, ''), `label`),
       `label_es` = COALESCE(NULLIF(`label_es`, ''), `label`);

INSERT INTO `menus`
    (`modulo`, `codigo`, `label`, `label_pt`, `label_en`, `label_es`, `url`, `nivel`, `ordem`, `ativo`)
VALUES
    ('PRINCIPAL', 'DASHBOARD', 'Dashboard', 'Dashboard', 'Dashboard', 'Panel', '/principal/dashboard.php', 1, 1, 1),
    ('SEGURANCA', 'SETUP', 'Setup', 'Setup', 'Setup', 'Configuración', NULL, 1, 2, 1),
    ('SEGURANCA', 'SETUP_LOGIN', 'Login', 'Login', 'Login', 'Inicio de sesión', '/principal/setup_login.php', 2, 1, 1),
    ('SEGURANCA', 'SETUP_MAIN', 'Main', 'Main', 'Main', 'Principal', '/principal/setup_main.php', 2, 2, 1),
    ('SEGURANCA', 'SETUP_GRID', 'Grid', 'Grid', 'Grid', 'Cuadrícula', NULL, 2, 3, 1),
    ('SEGURANCA', 'SETUP_FORMULARIO', 'Formulario', 'Formulário', 'Form', 'Formulario', NULL, 2, 4, 1),
    ('SEGURANCA', 'SEGURANCA', 'Segurança', 'Segurança', 'Security', 'Seguridad', NULL, 1, 10, 1),
    ('MANUTENCAO_LEGAL', 'MANUTENCAO_LEGAL', 'Manutenção Legal', 'Manutenção Legal', 'Legal Maintenance', 'Mantenimiento Legal', NULL, 1, 20, 1),
    ('MODULO_LEGAL', 'MODULO_LEGAL', 'Módulo Legal', 'Módulo Legal', 'Legal Module', 'Módulo Legal', NULL, 1, 30, 1),
    ('LICENCAS', 'LICENCAS', 'Licenças', 'Licenças', 'Licenses', 'Licencias', NULL, 1, 40, 1)
ON DUPLICATE KEY UPDATE
    `label` = VALUES(`label`),
    `label_pt` = VALUES(`label_pt`),
    `label_en` = VALUES(`label_en`),
    `label_es` = VALUES(`label_es`),
    `url` = VALUES(`url`),
    `nivel` = VALUES(`nivel`),
    `ordem` = VALUES(`ordem`),
    `ativo` = 1;

UPDATE `menus` child
JOIN `menus` parent ON parent.`codigo` = 'SETUP'
   SET child.`codmenu_pai` = parent.`codmenu`
 WHERE child.`codigo` IN ('SETUP_LOGIN', 'SETUP_MAIN', 'SETUP_GRID', 'SETUP_FORMULARIO');

CREATE OR REPLACE VIEW `vw_menus_grid` AS
SELECT m.`codmenu`, m.`codmenu_pai`, p.`label_pt` AS `menu_pai`,
       m.`modulo`, m.`codigo`, m.`label`, m.`label_pt`, m.`label_en`, m.`label_es`,
       m.`url`, m.`nivel`, m.`ordem`, m.`ativo`
  FROM `menus` m
  LEFT JOIN `menus` p ON p.`codmenu` = m.`codmenu_pai`;
