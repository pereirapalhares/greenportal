-- Migração dos cadastros de licenças do legado para o banco gmodelo_db.
-- Origem: green_modelo (latin1).
-- Destino: gmodelo_db (utf8mb4 / utf8mb4_0900_ai_ci).
-- As chaves CODLICENCA e CODCONDICIONANTE são preservadas. As tabelas de
-- relacionamento mantêm a chave composta lógica e os índices do legado.

CREATE DATABASE IF NOT EXISTS `gmodelo_db`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `gmodelo_db`.`li_licencas`
  LIKE `green_modelo`.`li_licencas`;
CREATE TABLE IF NOT EXISTS `gmodelo_db`.`li_condicionantes`
  LIKE `green_modelo`.`li_condicionantes`;
CREATE TABLE IF NOT EXISTS `gmodelo_db`.`legislacaoxlicencas`
  LIKE `green_modelo`.`legislacaoxlicencas`;
CREATE TABLE IF NOT EXISTS `gmodelo_db`.`legislacaoxperigo`
  LIKE `green_modelo`.`legislacaoxperigo`;
CREATE TABLE IF NOT EXISTS `gmodelo_db`.`legislacaoximpacto`
  LIKE `green_modelo`.`legislacaoximpacto`;

ALTER TABLE `gmodelo_db`.`li_licencas` ENGINE=InnoDB,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `gmodelo_db`.`li_condicionantes` ENGINE=InnoDB,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `gmodelo_db`.`legislacaoxlicencas` ENGINE=InnoDB,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `gmodelo_db`.`legislacaoxperigo` ENGINE=InnoDB,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `gmodelo_db`.`legislacaoximpacto` ENGINE=InnoDB,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

DELETE FROM `gmodelo_db`.`legislacaoxlicencas`;
DELETE FROM `gmodelo_db`.`legislacaoxperigo`;
DELETE FROM `gmodelo_db`.`legislacaoximpacto`;
DELETE FROM `gmodelo_db`.`li_condicionantes`;
DELETE FROM `gmodelo_db`.`li_licencas`;

INSERT INTO `gmodelo_db`.`li_licencas`
SELECT * FROM `green_modelo`.`li_licencas`;
INSERT INTO `gmodelo_db`.`li_condicionantes`
SELECT * FROM `green_modelo`.`li_condicionantes`;
INSERT INTO `gmodelo_db`.`legislacaoxlicencas`
SELECT * FROM `green_modelo`.`legislacaoxlicencas`;
INSERT INTO `gmodelo_db`.`legislacaoxperigo`
SELECT * FROM `green_modelo`.`legislacaoxperigo`;
INSERT INTO `gmodelo_db`.`legislacaoximpacto`
SELECT * FROM `green_modelo`.`legislacaoximpacto`;

CREATE TABLE IF NOT EXISTS `gmodelo_db`.`registros_migracao` (
  `codmigracao` BIGINT NOT NULL AUTO_INCREMENT,
  `codigo` VARCHAR(120) NOT NULL,
  `origem` VARCHAR(255) NOT NULL,
  `destino` VARCHAR(255) NOT NULL,
  `quantidade_origem` INT NOT NULL DEFAULT 0,
  `quantidade_destino` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(30) NOT NULL,
  `executado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`codmigracao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `gmodelo_db`.`registros_migracao`
  (`codigo`,`origem`,`destino`,`quantidade_origem`,`quantidade_destino`,`status`)
SELECT 'LICENCAS_MODELO_050',
       'green_modelo.li_licencas; green_modelo.li_condicionantes; green_modelo.legislacaoxlicencas; green_modelo.legislacaoxperigo; green_modelo.legislacaoximpacto',
       'gmodelo_db.li_licencas; gmodelo_db.li_condicionantes; gmodelo_db.legislacaoxlicencas; gmodelo_db.legislacaoxperigo; gmodelo_db.legislacaoximpacto',
       (SELECT COUNT(*) FROM `green_modelo`.`li_licencas`) +
       (SELECT COUNT(*) FROM `green_modelo`.`li_condicionantes`) +
       (SELECT COUNT(*) FROM `green_modelo`.`legislacaoxlicencas`) +
       (SELECT COUNT(*) FROM `green_modelo`.`legislacaoxperigo`) +
       (SELECT COUNT(*) FROM `green_modelo`.`legislacaoximpacto`),
       (SELECT COUNT(*) FROM `gmodelo_db`.`li_licencas`) +
       (SELECT COUNT(*) FROM `gmodelo_db`.`li_condicionantes`) +
       (SELECT COUNT(*) FROM `gmodelo_db`.`legislacaoxlicencas`) +
       (SELECT COUNT(*) FROM `gmodelo_db`.`legislacaoxperigo`) +
       (SELECT COUNT(*) FROM `gmodelo_db`.`legislacaoximpacto`),
       'CONCLUIDA';
