-- Green Portal - Módulo Controle baseado em documentos.
-- Origem: green_modelo
-- Destino: gmodelo_dbp
-- Escopo: documentos e seus relacionamentos diretos usados pelo Controle.
-- Esta migration é idempotente e preserva CODDOCUMENTO e as chaves legadas.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET FOREIGN_KEY_CHECKS=0;
USE `gmodelo_dbp`;

-- Tabela principal e relacionamentos do cadastro Controle.
CREATE TABLE IF NOT EXISTS `documentos` LIKE `green_modelo`.`documentos`;
CREATE TABLE IF NOT EXISTS `documentosxaspectos` LIKE `green_modelo`.`documentosxaspectos`;
CREATE TABLE IF NOT EXISTS `documentosxatividades` LIKE `green_modelo`.`documentosxatividades`;
CREATE TABLE IF NOT EXISTS `documentosximpactos` LIKE `green_modelo`.`documentosximpactos`;
CREATE TABLE IF NOT EXISTS `documentosxpalavraschave` LIKE `green_modelo`.`documentosxpalavraschave`;
CREATE TABLE IF NOT EXISTS `documentosxperigos` LIKE `green_modelo`.`documentosxperigos`;
CREATE TABLE IF NOT EXISTS `documentosxriscos` LIKE `green_modelo`.`documentosxriscos`;
CREATE TABLE IF NOT EXISTS `documentosxunidades` LIKE `green_modelo`.`documentosxunidades`;

-- Relação do Controle com Requisitos Legais. A tabela já foi migrada por
-- 065_modulo_legal_requisitos_popups.sql, mas permanece sincronizada aqui.
CREATE TABLE IF NOT EXISTS `legislacaoxdocumentos` LIKE `green_modelo`.`legislacaoxdocumentos`;

-- Conversão das tabelas do escopo para o padrão do tenant.
ALTER TABLE `documentos` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `documentosxaspectos` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `documentosxatividades` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `documentosximpactos` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `documentosxpalavraschave` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `documentosxperigos` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `documentosxriscos` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `documentosxunidades` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `legislacaoxdocumentos` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- Tabela principal: preserva a chave legada.
INSERT INTO `documentos` (`CODDOCUMENTO`,`CODIGO`,`TITULO`,`ARQUIVO`)
SELECT s.`CODDOCUMENTO`,s.`CODIGO`,s.`TITULO`,s.`ARQUIVO`
  FROM `green_modelo`.`documentos` s
 WHERE NOT EXISTS (
       SELECT 1 FROM `documentos` d
        WHERE d.`CODDOCUMENTO`=s.`CODDOCUMENTO`
 );

-- Relacionamentos: não usar SELECT * porque o destino possui chaves técnicas
-- auxiliares em documentosxaspectos e documentosximpactos.
INSERT INTO `documentosxaspectos` (`CODDOCUMENTO`,`CODASPECTO`,`codunidade`)
SELECT s.`CODDOCUMENTO`,s.`CODASPECTO`,s.`codunidade`
  FROM `green_modelo`.`documentosxaspectos` s
 WHERE NOT EXISTS (
       SELECT 1 FROM `documentosxaspectos` d
        WHERE d.`CODDOCUMENTO` <=> s.`CODDOCUMENTO`
          AND d.`CODASPECTO` <=> s.`CODASPECTO`
          AND d.`codunidade` <=> s.`codunidade`
 );

INSERT INTO `documentosxatividades` (`CODDOCUMENTO`,`CODATIVIDADE`,`codunidade`)
SELECT s.`CODDOCUMENTO`,s.`CODATIVIDADE`,s.`codunidade`
  FROM `green_modelo`.`documentosxatividades` s
 WHERE NOT EXISTS (
       SELECT 1 FROM `documentosxatividades` d
        WHERE d.`CODDOCUMENTO` <=> s.`CODDOCUMENTO`
          AND d.`CODATIVIDADE` <=> s.`CODATIVIDADE`
          AND d.`codunidade` <=> s.`codunidade`
 );

INSERT INTO `documentosximpactos` (`CODDOCUMENTO`,`CODIMPACTO`,`codunidade`)
SELECT s.`CODDOCUMENTO`,s.`CODIMPACTO`,s.`codunidade`
  FROM `green_modelo`.`documentosximpactos` s
 WHERE NOT EXISTS (
       SELECT 1 FROM `documentosximpactos` d
        WHERE d.`CODDOCUMENTO` <=> s.`CODDOCUMENTO`
          AND d.`CODIMPACTO` <=> s.`CODIMPACTO`
          AND d.`codunidade` <=> s.`codunidade`
 );

INSERT INTO `documentosxpalavraschave` (`coddocumento`,`codpalavrachave`)
SELECT s.`coddocumento`,s.`codpalavrachave`
  FROM `green_modelo`.`documentosxpalavraschave` s
 WHERE NOT EXISTS (
       SELECT 1 FROM `documentosxpalavraschave` d
        WHERE d.`coddocumento` <=> s.`coddocumento`
          AND d.`codpalavrachave` <=> s.`codpalavrachave`
 );

INSERT INTO `documentosxperigos` (`CODDOCUMENTO`,`CODPERIGO`,`codunidade`)
SELECT s.`CODDOCUMENTO`,s.`CODPERIGO`,s.`codunidade`
  FROM `green_modelo`.`documentosxperigos` s
 WHERE NOT EXISTS (
       SELECT 1 FROM `documentosxperigos` d
        WHERE d.`CODDOCUMENTO` <=> s.`CODDOCUMENTO`
          AND d.`CODPERIGO` <=> s.`CODPERIGO`
          AND d.`codunidade` <=> s.`codunidade`
 );

INSERT INTO `documentosxriscos` (`CODDOCUMENTO`,`CODRISCO`,`codunidade`)
SELECT s.`CODDOCUMENTO`,s.`CODRISCO`,s.`codunidade`
  FROM `green_modelo`.`documentosxriscos` s
 WHERE NOT EXISTS (
       SELECT 1 FROM `documentosxriscos` d
        WHERE d.`CODDOCUMENTO` <=> s.`CODDOCUMENTO`
          AND d.`CODRISCO` <=> s.`CODRISCO`
          AND d.`codunidade` <=> s.`codunidade`
 );

INSERT INTO `documentosxunidades` (`CODDOCUMENTO`,`CODUNIDADE`)
SELECT s.`CODDOCUMENTO`,s.`CODUNIDADE`
  FROM `green_modelo`.`documentosxunidades` s
 WHERE NOT EXISTS (
       SELECT 1 FROM `documentosxunidades` d
        WHERE d.`CODDOCUMENTO` <=> s.`CODDOCUMENTO`
          AND d.`CODUNIDADE` <=> s.`CODUNIDADE`
 );

-- Relação manual entre documento de Controle e requisito legal.
INSERT INTO `legislacaoxdocumentos` (`coddocumento`,`codlegislacao`,`codunidade`)
SELECT s.`coddocumento`,s.`codlegislacao`,s.`codunidade`
  FROM `green_modelo`.`legislacaoxdocumentos` s
 WHERE NOT EXISTS (
       SELECT 1 FROM `legislacaoxdocumentos` d
        WHERE d.`coddocumento` <=> s.`coddocumento`
          AND d.`codlegislacao` <=> s.`codlegislacao`
          AND d.`codunidade` <=> s.`codunidade`
 );

INSERT INTO `registros_migracao`
 (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,
  `quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
 ('CONTROLE_DOCUMENTOS_LEGADO_121',
  'green_modelo.documentos; green_modelo.documentosxaspectos; green_modelo.documentosxatividades; green_modelo.documentosximpactos; green_modelo.documentosxpalavraschave; green_modelo.documentosxperigos; green_modelo.documentosxriscos; green_modelo.documentosxunidades; green_modelo.legislacaoxdocumentos',
  'gmodelo_dbp.documentos; gmodelo_dbp.documentosx*; gmodelo_dbp.legislacaoxdocumentos',
  'DADOS','1.0.0','CONCLUIDA',
  (SELECT COUNT(*) FROM `green_modelo`.`documentos`)
  +(SELECT COUNT(*) FROM `green_modelo`.`documentosxaspectos`)
  +(SELECT COUNT(*) FROM `green_modelo`.`documentosxatividades`)
  +(SELECT COUNT(*) FROM `green_modelo`.`documentosximpactos`)
  +(SELECT COUNT(*) FROM `green_modelo`.`documentosxpalavraschave`)
  +(SELECT COUNT(*) FROM `green_modelo`.`documentosxperigos`)
  +(SELECT COUNT(*) FROM `green_modelo`.`documentosxriscos`)
  +(SELECT COUNT(*) FROM `green_modelo`.`documentosxunidades`)
  +(SELECT COUNT(*) FROM `green_modelo`.`legislacaoxdocumentos`),
  CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
  JSON_OBJECT('entidade_principal','documentos',
              'relacionamentos',JSON_ARRAY('aspectos','atividades','impactos','palavras_chave','perigos','riscos','unidades'),
              'relacao_requisitos_legais','legislacaoxdocumentos',
              'chave_principal','CODDOCUMENTO',
              'linhas_com_unidade_null_preservadas',TRUE,
              'ged_documentos_fora_do_escopo',TRUE,
              'documentos_indexados_fora_do_escopo',TRUE))
ON DUPLICATE KEY UPDATE
 status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),
 concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);

SET FOREIGN_KEY_CHECKS=1;
