USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `formularios_campos` (
    `codcampoformulario` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codmenu` BIGINT UNSIGNED NOT NULL,
    `codigo` VARCHAR(100) NOT NULL,
    `label_pt` VARCHAR(150) NOT NULL,
    `label_en` VARCHAR(150) NOT NULL,
    `label_es` VARCHAR(150) NOT NULL,
    `tipo` VARCHAR(30) NOT NULL DEFAULT 'text',
    `ordem` INT NOT NULL DEFAULT 0,
    `visivel` TINYINT(1) NOT NULL DEFAULT 1,
    `obrigatorio` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`codcampoformulario`),
    UNIQUE KEY `uk_formularios_campos_menu_codigo` (`codmenu`, `codigo`),
    CONSTRAINT `fk_formularios_campos_menu` FOREIGN KEY (`codmenu`) REFERENCES `menus` (`codmenu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `permissoes_campos` (
    `codpermissao_campo` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codcampoformulario` BIGINT UNSIGNED NOT NULL,
    `codperfil` BIGINT UNSIGNED NOT NULL,
    `pode_visualizar` TINYINT(1) NOT NULL DEFAULT 0,
    `pode_editar` TINYINT(1) NOT NULL DEFAULT 0,
    `pode_incluir` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`codpermissao_campo`),
    UNIQUE KEY `uk_permissoes_campos_campo_perfil` (`codcampoformulario`, `codperfil`),
    CONSTRAINT `fk_permissoes_campos_campo` FOREIGN KEY (`codcampoformulario`) REFERENCES `formularios_campos` (`codcampoformulario`),
    CONSTRAINT `fk_permissoes_campos_perfil` FOREIGN KEY (`codperfil`) REFERENCES `perfis` (`codperfil`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_formularios_campos_grid` AS
SELECT pc.`codpermissao_campo`, c.`codcampoformulario`, c.`codmenu`, m.`codigo` AS `menu_codigo`,
       c.`codigo` AS `campo_codigo`, c.`label_pt`, c.`label_en`, c.`label_es`, c.`tipo`, c.`ordem`,
       c.`visivel`, c.`obrigatorio`, pc.`codperfil`, p.`codigo` AS `perfil_codigo`,
       pc.`pode_visualizar`, pc.`pode_editar`, pc.`pode_incluir`
  FROM `permissoes_campos` pc
  JOIN `formularios_campos` c ON c.`codcampoformulario` = pc.`codcampoformulario`
  JOIN `menus` m ON m.`codmenu` = c.`codmenu`
  JOIN `perfis` p ON p.`codperfil` = pc.`codperfil`;

INSERT INTO `menus` (`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
VALUES ('SEGURANCA','SEGURANCA_FORMULARIO','Formulário','Formulário','Form','Formulario','/principal/seguranca_formulario.php',2,4,1)
ON DUPLICATE KEY UPDATE `url`=VALUES(`url`),`label_pt`=VALUES(`label_pt`),`label_en`=VALUES(`label_en`),`label_es`=VALUES(`label_es`),`ativo`=1;
UPDATE `menus` child JOIN `menus` parent ON parent.`codigo`='SEGURANCA'
   SET child.`codmenu_pai`=parent.`codmenu`,child.`nivel`=2
 WHERE child.`codigo`='SEGURANCA_FORMULARIO';
