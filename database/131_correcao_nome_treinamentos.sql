-- Padronização do nome das tabelas de Treinamentos.

USE `gmodelo_dbp`;

CREATE TABLE IF NOT EXISTS `ml_treinamentos` (
    `codtreinamento` INT NOT NULL AUTO_INCREMENT,
    `dsctreinamento` VARCHAR(300) NULL,
    PRIMARY KEY (`codtreinamento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ml_treinamentosxpalavraschave` (
    `codtreinamentoxpalavraschave` INT NOT NULL AUTO_INCREMENT,
    `codtreinamento` INT NULL,
    `codpalavrachave` INT NULL,
    PRIMARY KEY (`codtreinamentoxpalavraschave`),
    KEY `ix_ml_treinamentos_pal_treinamento` (`codtreinamento`),
    KEY `ix_ml_treinamentos_pal_palavra` (`codpalavrachave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ml_treinamentos` (`codtreinamento`,`dsctreinamento`)
SELECT `codtreinamento`,`dsctreinamento` FROM `ml_treinamenos`;
INSERT IGNORE INTO `ml_treinamentosxpalavraschave` (`codtreinamentoxpalavraschave`,`codtreinamento`,`codpalavrachave`)
SELECT `codtreinamentoxpalavraschave`,`codtreinamento`,`codpalavrachave` FROM `ml_treinamenosxpalavraschave`;

CREATE OR REPLACE VIEW `vw_modulolegal_treinamentos_grid` AS
SELECT t.`codtreinamento` AS `CODTREINAMENTO`, t.`dsctreinamento` AS `DSCTREINAMENTO`, NULL AS `ARQUIVO`,
       (SELECT GROUP_CONCAT(DISTINCT p.`dscpalavrachave` ORDER BY p.`dscpalavrachave` SEPARATOR '; ')
          FROM `ml_treinamentosxpalavraschave` x
          JOIN `guniversal_dbp`.`palavras_chaves` p ON p.`codpalavrachave` = x.`codpalavrachave`
         WHERE x.`codtreinamento` = t.`codtreinamento`) AS `PALAVRAS_CHAVE`
FROM `ml_treinamentos` t;

INSERT IGNORE INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('CORRECAO_NOME_TREINAMENTOS_131','gmodelo_dbp.ml_treinamenos; gmodelo_dbp.ml_treinamenosxpalavraschave','gmodelo_dbp.ml_treinamentos; gmodelo_dbp.ml_treinamentosxpalavraschave','CORRECAO','1.0','CONCLUIDA',7,NOW(),NOW(),JSON_OBJECT('nome_principal','ml_treinamentos','nome_relacionamento','ml_treinamentosxpalavraschave'));
