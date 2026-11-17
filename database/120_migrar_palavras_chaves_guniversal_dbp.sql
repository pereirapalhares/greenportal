USE `guniversal_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `palavras_chaves` LIKE `green_universal`.`palavras_chaves`;
ALTER TABLE `palavras_chaves` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
DELETE FROM `palavras_chaves`;
INSERT INTO `palavras_chaves` (`codpalavrachave`, `dscpalavrachave`)
SELECT `codpalavrachave`, CONVERT(`dscpalavrachave` USING utf8mb4)
  FROM `green_universal`.`palavras_chaves`;

INSERT INTO `gmodelo_dbp`.`registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,
     `quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('UNIVERSAL_PALAVRAS_CHAVES_120','green_universal.palavras_chaves','guniversal_dbp.palavras_chaves',
     'DADOS','1.0.0','CONCLUIDA',(SELECT COUNT(*) FROM `palavras_chaves`),
     CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
     JSON_OBJECT('origem_charset','latin1','destino_collation','utf8mb4_0900_ai_ci','chave_preservada',TRUE))
ON DUPLICATE KEY UPDATE `status`=VALUES(`status`),`quantidade_registros`=VALUES(`quantidade_registros`),`concluida_em`=VALUES(`concluida_em`),`detalhes`=VALUES(`detalhes`);

USE `gmodelo_dbp`;
CREATE OR REPLACE VIEW `vw_licencas_palavras_chaves_grid` AS
SELECT x.`codlicencaxpalavrachave`, x.`codlicenca`, x.`codpalavrachave`,
       COALESCE(p.`dscpalavrachave`, '') AS `DSCPALAVRACHAVE`
  FROM `li_licencasxpalavraschave` x
  LEFT JOIN `guniversal_dbp`.`palavras_chaves` p
    ON p.`codpalavrachave` = x.`codpalavrachave`;
