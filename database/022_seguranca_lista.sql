USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `formulario_lista` (
    `codformulariolista` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo` VARCHAR(100) NOT NULL,
    `label_pt` VARCHAR(150) NOT NULL,
    `label_en` VARCHAR(150) NOT NULL,
    `label_es` VARCHAR(150) NOT NULL,
    `descricao` VARCHAR(255) NULL,
    `origem` ENUM('FIXA','BANCO') NOT NULL DEFAULT 'FIXA',
    `fonte_codigo` VARCHAR(100) NULL,
    `campo_valor` VARCHAR(100) NULL,
    `campo_label` VARCHAR(100) NULL,
    `filtro_json` JSON NULL,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`codformulariolista`),
    UNIQUE KEY `uk_formulario_lista_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `formulario_lista_itens` (
    `codformulariolistaitem` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codformulariolista` BIGINT UNSIGNED NOT NULL,
    `valor` VARCHAR(150) NOT NULL,
    `label_pt` VARCHAR(150) NOT NULL,
    `label_en` VARCHAR(150) NOT NULL,
    `label_es` VARCHAR(150) NOT NULL,
    `ordem` INT NOT NULL DEFAULT 0,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`codformulariolistaitem`),
    UNIQUE KEY `uk_formulario_lista_item` (`codformulariolista`,`valor`),
    CONSTRAINT `fk_formulario_lista_itens_lista` FOREIGN KEY (`codformulariolista`) REFERENCES `formulario_lista` (`codformulariolista`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_formulario_lista_grid` AS
SELECT `codformulariolista`, `codigo`, `label_pt`, `label_en`, `label_es`,
       `descricao`, `origem`, `fonte_codigo`, `campo_valor`, `campo_label`,
       `filtro_json`, `ativo`
  FROM `formulario_lista`;

INSERT INTO `menus` (`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT 'SEGURANCA','SEGURANCA_LISTA','Lista','Lista','Lists','Listas','/principal/seguranca_lista.php',2,5,1
 WHERE NOT EXISTS (SELECT 1 FROM `menus` WHERE `codigo`='SEGURANCA_LISTA');
UPDATE `menus` child JOIN `menus` parent ON parent.`codigo`='SEGURANCA'
   SET child.`codmenu_pai`=parent.`codmenu`, child.`nivel`=2,
       child.`url`='/principal/seguranca_lista.php', child.`label_pt`='Lista',
       child.`label_en`='Lists', child.`label_es`='Listas', child.`ativo`=1
 WHERE child.`codigo`='SEGURANCA_LISTA';
