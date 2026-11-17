-- GREEN PORTAL - MIGRACAO DO MODULO MM
-- Origem: green_modelo (legado latin1)
-- Destino: gmodelo_dbp (tenant Modelo)
-- Estrategia: carga AS-IS, preservando chaves e referencias orfas.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`mm_controle_calibracao`
  LIKE `green_modelo`.`mm_controle_calibracao`;
ALTER TABLE `gmodelo_dbp`.`mm_controle_calibracao`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`mm_controle_calibracao`
  SELECT * FROM `green_modelo`.`mm_controle_calibracao`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`mm_controleequipamento`
  LIKE `green_modelo`.`mm_controleequipamento`;
ALTER TABLE `gmodelo_dbp`.`mm_controleequipamento`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`mm_controleequipamento`
  SELECT * FROM `green_modelo`.`mm_controleequipamento`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`mm_empresa`
  LIKE `green_modelo`.`mm_empresa`;
ALTER TABLE `gmodelo_dbp`.`mm_empresa`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`mm_empresa`
  SELECT * FROM `green_modelo`.`mm_empresa`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`mm_equipamento`
  LIKE `green_modelo`.`mm_equipamento`;
ALTER TABLE `gmodelo_dbp`.`mm_equipamento`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`mm_equipamento`
  SELECT * FROM `green_modelo`.`mm_equipamento`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`mm_laudo`
  LIKE `green_modelo`.`mm_laudo`;
ALTER TABLE `gmodelo_dbp`.`mm_laudo`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`mm_laudo`
  SELECT * FROM `green_modelo`.`mm_laudo`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`mm_medicao`
  LIKE `green_modelo`.`mm_medicao`;
ALTER TABLE `gmodelo_dbp`.`mm_medicao`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`mm_medicao`
  SELECT * FROM `green_modelo`.`mm_medicao`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`mm_medicaoxlaudo`
  LIKE `green_modelo`.`mm_medicaoxlaudo`;
ALTER TABLE `gmodelo_dbp`.`mm_medicaoxlaudo`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`mm_medicaoxlaudo`
  SELECT * FROM `green_modelo`.`mm_medicaoxlaudo`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`mm_medicaoxlegislacao`
  LIKE `green_modelo`.`mm_medicaoxlegislacao`;
ALTER TABLE `gmodelo_dbp`.`mm_medicaoxlegislacao`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`mm_medicaoxlegislacao`
  SELECT * FROM `green_modelo`.`mm_medicaoxlegislacao`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`mm_medicaoxpalavraschave`
  LIKE `green_modelo`.`mm_medicaoxpalavraschave`;
ALTER TABLE `gmodelo_dbp`.`mm_medicaoxpalavraschave`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`mm_medicaoxpalavraschave`
  SELECT * FROM `green_modelo`.`mm_medicaoxpalavraschave`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`mm_monitoramento`
  LIKE `green_modelo`.`mm_monitoramento`;
ALTER TABLE `gmodelo_dbp`.`mm_monitoramento`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`mm_monitoramento`
  SELECT * FROM `green_modelo`.`mm_monitoramento`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`mm_monitoramentoxlegislacao`
  LIKE `green_modelo`.`mm_monitoramentoxlegislacao`;
ALTER TABLE `gmodelo_dbp`.`mm_monitoramentoxlegislacao`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`mm_monitoramentoxlegislacao`
  SELECT * FROM `green_modelo`.`mm_monitoramentoxlegislacao`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`mm_planodeacao`
  LIKE `green_modelo`.`mm_planodeacao`;
ALTER TABLE `gmodelo_dbp`.`mm_planodeacao`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`mm_planodeacao`
  SELECT * FROM `green_modelo`.`mm_planodeacao`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`mm_tipoitem`
  LIKE `green_modelo`.`mm_tipoitem`;
ALTER TABLE `gmodelo_dbp`.`mm_tipoitem`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`mm_tipoitem`
  SELECT * FROM `green_modelo`.`mm_tipoitem`;

INSERT INTO `gmodelo_dbp`.`registros_migracao`
  (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`,
   `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
  ('MM_MODELO_054',
   'green_modelo.mm_*',
   'gmodelo_dbp.mm_*',
   'DADOS',
   '1.0.0',
   'CONCLUIDA',
   (SELECT SUM(TABLE_ROWS) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = 'green_modelo' AND TABLE_NAME LIKE 'mm\\_%'),
   CURRENT_TIMESTAMP,
   CURRENT_TIMESTAMP,
   JSON_OBJECT(
     'estrategia', 'AS-IS',
     'chaves_preservadas', TRUE,
     'referencias_orfas_preservadas', TRUE,
     'charset_origem', 'latin1',
     'charset_destino', 'utf8mb4',
     'collation_destino', 'utf8mb4_0900_ai_ci',
     'engine_destino', 'InnoDB',
     'tabelas', JSON_OBJECT(
       'mm_controle_calibracao', 1,
       'mm_controleequipamento', 0,
       'mm_empresa', 0,
       'mm_equipamento', 4,
       'mm_laudo', 35,
       'mm_medicao', 35,
       'mm_medicaoxlaudo', 32,
       'mm_medicaoxlegislacao', 44,
       'mm_medicaoxpalavraschave', 0,
       'mm_monitoramento', 8,
       'mm_monitoramentoxlegislacao', 25,
       'mm_planodeacao', 1,
       'mm_tipoitem', 0
     ),
     'total_registros', 185,
     'referencias_orfas', JSON_OBJECT(
       'linhas', 32,
       'valores_distintos', 12,
       'mm_medicaoxlegislacao', JSON_OBJECT('linhas', 29, 'valores_distintos', 10),
       'mm_monitoramentoxlegislacao', JSON_OBJECT('linhas', 3, 'valores_distintos', 2)
     )
   ))
ON DUPLICATE KEY UPDATE
  `status` = VALUES(`status`),
  `quantidade_registros` = VALUES(`quantidade_registros`),
  `concluida_em` = VALUES(`concluida_em`),
  `detalhes` = VALUES(`detalhes`);

SET FOREIGN_KEY_CHECKS = 1;
