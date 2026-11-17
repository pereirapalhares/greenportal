-- Migração das tabelas li_ e relações diretas para o banco da empresa Modelo.
-- Origem: green_modelo (latin1).
-- Destino: gmodelo_dbp (utf8mb4_0900_ai_ci).
-- As chaves primárias e os valores dos códigos legados são preservados.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `li_classificacao` LIKE `green_modelo`.`li_classificacao`;
CREATE TABLE IF NOT EXISTS `li_condicionantes` LIKE `green_modelo`.`li_condicionantes`;
CREATE TABLE IF NOT EXISTS `li_frequencia` LIKE `green_modelo`.`li_frequencia`;
CREATE TABLE IF NOT EXISTS `li_licencas` LIKE `green_modelo`.`li_licencas`;
CREATE TABLE IF NOT EXISTS `li_licencasxgrupos` LIKE `green_modelo`.`li_licencasxgrupos`;
CREATE TABLE IF NOT EXISTS `li_licencasxpalavraschave` LIKE `green_modelo`.`li_licencasxpalavraschave`;
CREATE TABLE IF NOT EXISTS `li_planodeacao` LIKE `green_modelo`.`li_planodeacao`;
CREATE TABLE IF NOT EXISTS `li_status_protocolo` LIKE `green_modelo`.`li_status_protocolo`;
CREATE TABLE IF NOT EXISTS `legislacaoxlicencas` LIKE `green_modelo`.`legislacaoxlicencas`;
CREATE TABLE IF NOT EXISTS `legislacaoxperigo` LIKE `green_modelo`.`legislacaoxperigo`;
CREATE TABLE IF NOT EXISTS `legislacaoximpacto` LIKE `green_modelo`.`legislacaoximpacto`;

ALTER TABLE `li_classificacao` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `li_condicionantes` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `li_frequencia` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `li_licencas` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `li_licencasxgrupos` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `li_licencasxpalavraschave` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `li_planodeacao` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `li_status_protocolo` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `legislacaoxlicencas` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `legislacaoxperigo` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `legislacaoximpacto` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

DELETE FROM `legislacaoxlicencas`;
DELETE FROM `legislacaoxperigo`;
DELETE FROM `legislacaoximpacto`;
DELETE FROM `li_licencasxpalavraschave`;
DELETE FROM `li_licencasxgrupos`;
DELETE FROM `li_planodeacao`;
DELETE FROM `li_condicionantes`;
DELETE FROM `li_licencas`;
DELETE FROM `li_classificacao`;
DELETE FROM `li_frequencia`;
DELETE FROM `li_status_protocolo`;

INSERT INTO `li_classificacao` SELECT * FROM `green_modelo`.`li_classificacao`;
INSERT INTO `li_condicionantes` SELECT * FROM `green_modelo`.`li_condicionantes`;
INSERT INTO `li_frequencia` SELECT * FROM `green_modelo`.`li_frequencia`;
INSERT INTO `li_licencas` SELECT * FROM `green_modelo`.`li_licencas`;
INSERT INTO `li_licencasxgrupos` SELECT * FROM `green_modelo`.`li_licencasxgrupos`;
INSERT INTO `li_licencasxpalavraschave` SELECT * FROM `green_modelo`.`li_licencasxpalavraschave`;
INSERT INTO `li_planodeacao` SELECT * FROM `green_modelo`.`li_planodeacao`;
INSERT INTO `li_status_protocolo` SELECT * FROM `green_modelo`.`li_status_protocolo`;
INSERT INTO `legislacaoxlicencas` SELECT * FROM `green_modelo`.`legislacaoxlicencas`;
INSERT INTO `legislacaoxperigo` SELECT * FROM `green_modelo`.`legislacaoxperigo`;
INSERT INTO `legislacaoximpacto` SELECT * FROM `green_modelo`.`legislacaoximpacto`;

INSERT INTO `registros_migracao`
  (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,
   `quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
  ('LI_MODELO_052',
   'green_modelo.li_*; green_modelo.legislacaoxlicencas; green_modelo.legislacaoxperigo; green_modelo.legislacaoximpacto',
   'gmodelo_dbp.li_*; gmodelo_dbp.legislacaoxlicencas; gmodelo_dbp.legislacaoxperigo; gmodelo_dbp.legislacaoximpacto',
   'DADOS','1.0.0','CONCLUIDA',
   (SELECT COUNT(*) FROM `li_classificacao`) +
   (SELECT COUNT(*) FROM `li_condicionantes`) +
   (SELECT COUNT(*) FROM `li_frequencia`) +
   (SELECT COUNT(*) FROM `li_licencas`) +
   (SELECT COUNT(*) FROM `li_licencasxgrupos`) +
   (SELECT COUNT(*) FROM `li_licencasxpalavraschave`) +
   (SELECT COUNT(*) FROM `li_planodeacao`) +
   (SELECT COUNT(*) FROM `li_status_protocolo`) +
   (SELECT COUNT(*) FROM `legislacaoxlicencas`) +
   (SELECT COUNT(*) FROM `legislacaoxperigo`) +
   (SELECT COUNT(*) FROM `legislacaoximpacto`),
   CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
   JSON_OBJECT('origem_charset','latin1','destino_collation','utf8mb4_0900_ai_ci',
               'chaves_preservadas',TRUE,
               'legislacaoxsituacao_preservada',TRUE))
ON DUPLICATE KEY UPDATE
  `status`=VALUES(`status`),
  `quantidade_registros`=VALUES(`quantidade_registros`),
  `concluida_em`=VALUES(`concluida_em`),
  `detalhes`=VALUES(`detalhes`);
