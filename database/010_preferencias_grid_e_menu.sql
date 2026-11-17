-- Preferencias de grid por usuario e cadastro da transacao Setup / Menu.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `preferencias_grid` (
    `codpreferenciagrid` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codusuario` BIGINT UNSIGNED NOT NULL,
    `transacao` VARCHAR(150) NOT NULL,
    `coluna` VARCHAR(150) NOT NULL,
    `ordem` INT NOT NULL DEFAULT 0,
    `visivel` TINYINT(1) NOT NULL DEFAULT 1,
    `largura` INT NOT NULL DEFAULT 120,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`codpreferenciagrid`),
    UNIQUE KEY `uk_preferencia_grid` (`codusuario`, `transacao`, `coluna`),
    CONSTRAINT `fk_preferencia_grid_usuario` FOREIGN KEY (`codusuario`) REFERENCES `usuarios` (`codusuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `menus` (`modulo`, `codigo`, `label`, `label_pt`, `label_en`, `label_es`, `url`, `nivel`, `ordem`, `ativo`)
VALUES ('SEGURANCA', 'SETUP_MENU', 'Menu', 'Menu', 'Menu', 'Menú', '/principal/setup_menu.php', 2, 3, 1)
ON DUPLICATE KEY UPDATE `url` = VALUES(`url`), `ativo` = 1,
    `label` = VALUES(`label`), `label_pt` = VALUES(`label_pt`),
    `label_en` = VALUES(`label_en`), `label_es` = VALUES(`label_es`);

UPDATE `menus` child
JOIN `menus` parent ON parent.`codigo` = 'SETUP'
   SET child.`codmenu_pai` = parent.`codmenu`
 WHERE child.`codigo` = 'SETUP_MENU';

CREATE OR REPLACE VIEW `vw_preferencias_grid` AS
SELECT `codpreferenciagrid`, `codusuario`, `transacao`, `coluna`, `ordem`, `visivel`, `largura`, `atualizado_em`
  FROM `preferencias_grid`;
