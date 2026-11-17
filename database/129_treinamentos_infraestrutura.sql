-- Estrutura e migração inicial de Treinamentos e InfraEstrutura.

USE `gmodelo_dbp`;

CREATE TABLE IF NOT EXISTS `ml_treinamenos` (
    `codtreinamento` INT NOT NULL AUTO_INCREMENT,
    `dsctreinamento` VARCHAR(300) NULL,
    PRIMARY KEY (`codtreinamento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ml_treinamenosxpalavraschave` (
    `codtreinamentoxpalavraschave` INT NOT NULL AUTO_INCREMENT,
    `codtreinamento` INT NULL,
    `codpalavrachave` INT NULL,
    PRIMARY KEY (`codtreinamentoxpalavraschave`),
    KEY `ix_ml_trein_x_pal_treinamento` (`codtreinamento`),
    KEY `ix_ml_trein_x_pal_palavra` (`codpalavrachave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ml_infraestrutura` (
    `codinfraestrutura` INT NOT NULL AUTO_INCREMENT,
    `dscinfraestrutura` VARCHAR(300) NULL,
    PRIMARY KEY (`codinfraestrutura`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ml_infraestruturaxatributos` (
    `codatributo` INT NOT NULL AUTO_INCREMENT,
    `codinfraestrutura` INT NULL,
    `dscatributo` VARCHAR(200) NULL,
    `valoratributo` VARCHAR(500) NULL,
    PRIMARY KEY (`codatributo`),
    KEY `ix_ml_infra_atrib_infraestrutura` (`codinfraestrutura`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ml_infraestruturaxpalavraschave` (
    `codinfraestruturaxpalavraschave` INT NOT NULL AUTO_INCREMENT,
    `codinfraestrutura` INT NULL,
    `codpalavrachave` INT NULL,
    PRIMARY KEY (`codinfraestruturaxpalavraschave`),
    KEY `ix_ml_infra_pal_infraestrutura` (`codinfraestrutura`),
    KEY `ix_ml_infra_pal_palavra` (`codpalavrachave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ml_treinamenos` (`codtreinamento`,`dsctreinamento`)
SELECT `codtreinamento`,`dsctreinamento` FROM `green_modelo`.`ml_treinamenos`;
INSERT IGNORE INTO `ml_treinamenosxpalavraschave` (`codtreinamentoxpalavraschave`,`codtreinamento`,`codpalavrachave`)
SELECT `codtreinamentoxpalavraschave`,`codtreinamento`,`codpalavrachave` FROM `green_modelo`.`ml_treinamenosxpalavraschave`;
INSERT IGNORE INTO `ml_infraestrutura` (`codinfraestrutura`,`dscinfraestrutura`)
SELECT `codinfraestrutura`,`dscinfraestrutura` FROM `green_modelo`.`ml_infraestrutura`;
INSERT IGNORE INTO `ml_infraestruturaxatributos` (`codatributo`,`codinfraestrutura`,`dscatributo`,`valoratributo`)
SELECT `codatributo`,`codinfraestrutura`,`dscatributo`,`valoratributo` FROM `green_modelo`.`ml_infraestruturaxatributos`;
INSERT IGNORE INTO `ml_infraestruturaxpalavraschave` (`codinfraestruturaxpalavraschave`,`codinfraestrutura`,`codpalavrachave`)
SELECT `codinfraestruturaxpalavraschave`,`codinfraestrutura`,`codpalavrachave` FROM `green_modelo`.`ml_infraestruturaxpalavraschave`;

CREATE OR REPLACE VIEW `vw_modulolegal_treinamentos_grid` AS
SELECT t.`codtreinamento` AS `CODTREINAMENTO`, t.`dsctreinamento` AS `DSCTREINAMENTO`,
       (SELECT GROUP_CONCAT(DISTINCT p.`dscpalavrachave` ORDER BY p.`dscpalavrachave` SEPARATOR '; ')
          FROM `ml_treinamenosxpalavraschave` x
          JOIN `guniversal_dbp`.`palavras_chaves` p ON p.`codpalavrachave` = x.`codpalavrachave`
         WHERE x.`codtreinamento` = t.`codtreinamento`) AS `PALAVRAS_CHAVE`
FROM `ml_treinamenos` t;

CREATE OR REPLACE VIEW `vw_modulolegal_infraestrutura_grid` AS
SELECT i.`codinfraestrutura` AS `CODINFRAESTRUTURA`, i.`dscinfraestrutura` AS `DSCINFRAESTRUTURA`,
       (SELECT GROUP_CONCAT(CONCAT(a.`dscatributo`, ': ', a.`valoratributo`) ORDER BY a.`dscatributo` SEPARATOR '; ')
          FROM `ml_infraestruturaxatributos` a WHERE a.`codinfraestrutura` = i.`codinfraestrutura`) AS `ATRIBUTOS`,
       (SELECT GROUP_CONCAT(DISTINCT p.`dscpalavrachave` ORDER BY p.`dscpalavrachave` SEPARATOR '; ')
          FROM `ml_infraestruturaxpalavraschave` x
          JOIN `guniversal_dbp`.`palavras_chaves` p ON p.`codpalavrachave` = x.`codpalavrachave`
         WHERE x.`codinfraestrutura` = i.`codinfraestrutura`) AS `PALAVRAS_CHAVE`
FROM `ml_infraestrutura` i;

INSERT INTO `menus` (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT m.`codmenu`, 'MODULO_LEGAL', 'MODULOLEGAL_TREINAMENTOS', 'Treinamentos', 'Treinamentos', 'Training', 'Capacitaciones', '/principal/modulolegal_treinamentos.php', 2, 37, 1
FROM `menus` m WHERE m.`codigo`='MODULO_LEGAL' AND NOT EXISTS (SELECT 1 FROM `menus` x WHERE x.`codigo`='MODULOLEGAL_TREINAMENTOS');
INSERT INTO `menus` (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT m.`codmenu`, 'MODULO_LEGAL', 'MODULOLEGAL_INFRAESTRUTURA', 'InfraEstrutura', 'InfraEstrutura', 'Infrastructure', 'Infraestructura', '/principal/modulolegal_InfraEstrutura.php', 2, 38, 1
FROM `menus` m WHERE m.`codigo`='MODULO_LEGAL' AND NOT EXISTS (SELECT 1 FROM `menus` x WHERE x.`codigo`='MODULOLEGAL_INFRAESTRUTURA');

INSERT INTO `permissoes` (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`, IF(p.`codigo`='ADMINISTRADOR',1,0), IF(p.`codigo`='ADMINISTRADOR',1,0), IF(p.`codigo`='ADMINISTRADOR',1,0), IF(p.`codigo`='ADMINISTRADOR',1,0), IF(p.`codigo`='ADMINISTRADOR',1,0), 0
FROM `perfis` p CROSS JOIN `menus` m
WHERE m.`codigo` IN ('MODULOLEGAL_TREINAMENTOS','MODULOLEGAL_INFRAESTRUTURA')
  AND NOT EXISTS (SELECT 1 FROM `permissoes` z WHERE z.`codperfil`=p.`codperfil` AND z.`codmenu`=m.`codmenu`);

INSERT IGNORE INTO `registros_migracao` (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('TREINAMENTOS_INFRAESTRUTURA_129','green_modelo.ml_treinamenos; green_modelo.ml_infraestrutura; hpr_configuracoes.configuracoes_transacoes','gmodelo_dbp.ml_treinamenos; gmodelo_dbp.ml_infraestrutura; views; menus','ESTRUTURA','1.0','CONCLUIDA',17,NOW(),NOW(),JSON_OBJECT('transacoes',JSON_ARRAY('mlTreinamentos','mlInfraEstruturas'),'permissao_inicial','ADMINISTRADOR'));
