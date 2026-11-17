-- Green Portal - migracao do modulo de Legislacao.
-- Origem: green_legislacao (legado, predominantemente latin1)
-- Destino: glegislacao_dbp (utf8mb4_0900_ai_ci)
-- As chaves primarias e os nomes dos campos do legado sao preservados.
-- A carga inicial foi executada no novo banco. Em nova execucao, validar antes
-- se o destino possui dados alterados pela aplicacao, especialmente nas tabelas
-- de relacionamento que nao possuem chave primaria.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `glegislacao_dbp`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;

ALTER DATABASE `glegislacao_dbp`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;

USE `glegislacao_dbp`;

CREATE TABLE IF NOT EXISTS `registros_migracao` (
    `codregistromigracao` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo_migracao` VARCHAR(150) NOT NULL,
    `origem` VARCHAR(255) NOT NULL,
    `destino` VARCHAR(255) NOT NULL,
    `tipo` VARCHAR(30) NOT NULL,
    `versao` VARCHAR(30) NOT NULL,
    `status` VARCHAR(30) NOT NULL,
    `quantidade_origem` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `quantidade_destino` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `iniciada_em` DATETIME NOT NULL,
    `concluida_em` DATETIME NULL,
    `detalhes` JSON NULL,
    PRIMARY KEY (`codregistromigracao`),
    UNIQUE KEY `uk_registros_migracao_codigo` (`codigo_migracao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `registros_migracao`
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- Cada tabela e criada a partir da estrutura original, mantendo a chave:
-- assuntos.CODASSUNTO
-- legislacao.CODLEGISLACAO
-- legislacao_categoria.CODCATEGORIALEGISLACAO
-- legislacaopaixlegislacaofilha (sem chave primaria no legado)
-- legislacaoxalteracao.codalteracao
-- legislacaoxassunto (sem chave primaria no legado)
-- legislacaoxcategoria.CODLEGISLACAOXCATEGORIA
-- legislacaoxmes.CODLEGISLACAOXMES
-- legislacaoxpalavraschave (sem chave primaria no legado)
-- legislacaoxpergunta (sem chave primaria no legado)
-- perguntas.CODPERGUNTA
-- tipo_de_alteracao.CODTIPODEALTERACAO

CREATE TABLE IF NOT EXISTS `assuntos` LIKE `green_legislacao`.`assuntos`;
CREATE TABLE IF NOT EXISTS `legislacao` LIKE `green_legislacao`.`legislacao`;
CREATE TABLE IF NOT EXISTS `legislacao_categoria` LIKE `green_legislacao`.`legislacao_categoria`;
CREATE TABLE IF NOT EXISTS `legislacaopaixlegislacaofilha` LIKE `green_legislacao`.`legislacaopaixlegislacaofilha`;
CREATE TABLE IF NOT EXISTS `legislacaoxalteracao` LIKE `green_legislacao`.`legislacaoxalteracao`;
CREATE TABLE IF NOT EXISTS `legislacaoxassunto` LIKE `green_legislacao`.`legislacaoxassunto`;
CREATE TABLE IF NOT EXISTS `legislacaoxcategoria` LIKE `green_legislacao`.`legislacaoxcategoria`;
CREATE TABLE IF NOT EXISTS `legislacaoxmes` LIKE `green_legislacao`.`legislacaoxmes`;
CREATE TABLE IF NOT EXISTS `legislacaoxpalavraschave` LIKE `green_legislacao`.`legislacaoxpalavraschave`;
CREATE TABLE IF NOT EXISTS `legislacaoxpergunta` LIKE `green_legislacao`.`legislacaoxpergunta`;
CREATE TABLE IF NOT EXISTS `perguntas` LIKE `green_legislacao`.`perguntas`;
CREATE TABLE IF NOT EXISTS `tipo_de_alteracao` LIKE `green_legislacao`.`tipo_de_alteracao`;

ALTER TABLE `assuntos` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `legislacao` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `legislacao_categoria` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `legislacaopaixlegislacaofilha` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `legislacaoxalteracao` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `legislacaoxassunto` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `legislacaoxcategoria` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `legislacaoxmes` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `legislacaoxpalavraschave` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `legislacaoxpergunta` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `perguntas` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `tipo_de_alteracao` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT IGNORE INTO `assuntos` SELECT * FROM `green_legislacao`.`assuntos`;
INSERT IGNORE INTO `legislacao` SELECT * FROM `green_legislacao`.`legislacao`;
INSERT IGNORE INTO `legislacao_categoria` SELECT * FROM `green_legislacao`.`legislacao_categoria`;
INSERT IGNORE INTO `legislacaopaixlegislacaofilha` SELECT * FROM `green_legislacao`.`legislacaopaixlegislacaofilha`;
INSERT IGNORE INTO `legislacaoxalteracao` SELECT * FROM `green_legislacao`.`legislacaoxalteracao`;
INSERT IGNORE INTO `legislacaoxassunto` SELECT * FROM `green_legislacao`.`legislacaoxassunto`;
INSERT IGNORE INTO `legislacaoxcategoria` SELECT * FROM `green_legislacao`.`legislacaoxcategoria`;
INSERT IGNORE INTO `legislacaoxmes` SELECT * FROM `green_legislacao`.`legislacaoxmes`;
INSERT IGNORE INTO `legislacaoxpalavraschave` SELECT * FROM `green_legislacao`.`legislacaoxpalavraschave`;
INSERT IGNORE INTO `legislacaoxpergunta` SELECT * FROM `green_legislacao`.`legislacaoxpergunta`;
INSERT IGNORE INTO `perguntas` SELECT * FROM `green_legislacao`.`perguntas`;
INSERT IGNORE INTO `tipo_de_alteracao` SELECT * FROM `green_legislacao`.`tipo_de_alteracao`;

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`,
     `quantidade_origem`, `quantidade_destino`, `iniciada_em`, `concluida_em`, `detalhes`)
SELECT 'LEGISLACAO_ASSUNTOS', 'green_legislacao.assuntos', 'glegislacao_dbp.assuntos', 'DADOS', '1.0.0', 'CONCLUIDA',
       (SELECT COUNT(*) FROM `green_legislacao`.`assuntos`), COUNT(*), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
       JSON_OBJECT('chave_primaria', 'CODASSUNTO', 'charset_origem', 'latin1', 'collation_destino', 'utf8mb4_0900_ai_ci') FROM `assuntos`
ON DUPLICATE KEY UPDATE quantidade_origem = VALUES(quantidade_origem), quantidade_destino = VALUES(quantidade_destino), concluida_em = VALUES(concluida_em), status = VALUES(status), detalhes = VALUES(detalhes);

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_origem`, `quantidade_destino`, `iniciada_em`, `concluida_em`, `detalhes`)
SELECT codigo_migracao, origem, destino, 'DADOS', '1.0.0', 'CONCLUIDA', quantidade_origem, quantidade_destino,
       CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, JSON_OBJECT('chave_primaria', chave_primaria, 'charset_origem', 'latin1', 'collation_destino', 'utf8mb4_0900_ai_ci')
  FROM (
    SELECT 'LEGISLACAO_LEGISLACAO', 'green_legislacao.legislacao', 'glegislacao_dbp.legislacao', (SELECT COUNT(*) FROM `green_legislacao`.`legislacao`), (SELECT COUNT(*) FROM `legislacao`), 'CODLEGISLACAO'
    UNION ALL SELECT 'LEGISLACAO_CATEGORIA', 'green_legislacao.legislacao_categoria', 'glegislacao_dbp.legislacao_categoria', (SELECT COUNT(*) FROM `green_legislacao`.`legislacao_categoria`), (SELECT COUNT(*) FROM `legislacao_categoria`), 'CODCATEGORIALEGISLACAO'
    UNION ALL SELECT 'LEGISLACAO_PAI_FILHA', 'green_legislacao.legislacaopaixlegislacaofilha', 'glegislacao_dbp.legislacaopaixlegislacaofilha', (SELECT COUNT(*) FROM `green_legislacao`.`legislacaopaixlegislacaofilha`), (SELECT COUNT(*) FROM `legislacaopaixlegislacaofilha`), 'SEM_CHAVE_PRIMARIA'
    UNION ALL SELECT 'LEGISLACAO_ALTERACAO', 'green_legislacao.legislacaoxalteracao', 'glegislacao_dbp.legislacaoxalteracao', (SELECT COUNT(*) FROM `green_legislacao`.`legislacaoxalteracao`), (SELECT COUNT(*) FROM `legislacaoxalteracao`), 'codalteracao'
    UNION ALL SELECT 'LEGISLACAO_ASSUNTO', 'green_legislacao.legislacaoxassunto', 'glegislacao_dbp.legislacaoxassunto', (SELECT COUNT(*) FROM `green_legislacao`.`legislacaoxassunto`), (SELECT COUNT(*) FROM `legislacaoxassunto`), 'SEM_CHAVE_PRIMARIA'
    UNION ALL SELECT 'LEGISLACAO_CATEGORIA_VINCULO', 'green_legislacao.legislacaoxcategoria', 'glegislacao_dbp.legislacaoxcategoria', (SELECT COUNT(*) FROM `green_legislacao`.`legislacaoxcategoria`), (SELECT COUNT(*) FROM `legislacaoxcategoria`), 'CODLEGISLACAOXCATEGORIA'
    UNION ALL SELECT 'LEGISLACAO_MES', 'green_legislacao.legislacaoxmes', 'glegislacao_dbp.legislacaoxmes', (SELECT COUNT(*) FROM `green_legislacao`.`legislacaoxmes`), (SELECT COUNT(*) FROM `legislacaoxmes`), 'CODLEGISLACAOXMES'
    UNION ALL SELECT 'LEGISLACAO_PALAVRA_CHAVE', 'green_legislacao.legislacaoxpalavraschave', 'glegislacao_dbp.legislacaoxpalavraschave', (SELECT COUNT(*) FROM `green_legislacao`.`legislacaoxpalavraschave`), (SELECT COUNT(*) FROM `legislacaoxpalavraschave`), 'SEM_CHAVE_PRIMARIA'
    UNION ALL SELECT 'LEGISLACAO_PERGUNTA_VINCULO', 'green_legislacao.legislacaoxpergunta', 'glegislacao_dbp.legislacaoxpergunta', (SELECT COUNT(*) FROM `green_legislacao`.`legislacaoxpergunta`), (SELECT COUNT(*) FROM `legislacaoxpergunta`), 'SEM_CHAVE_PRIMARIA'
    UNION ALL SELECT 'LEGISLACAO_PERGUNTAS', 'green_legislacao.perguntas', 'glegislacao_dbp.perguntas', (SELECT COUNT(*) FROM `green_legislacao`.`perguntas`), (SELECT COUNT(*) FROM `perguntas`), 'CODPERGUNTA'
    UNION ALL SELECT 'LEGISLACAO_TIPO_ALTERACAO', 'green_legislacao.tipo_de_alteracao', 'glegislacao_dbp.tipo_de_alteracao', (SELECT COUNT(*) FROM `green_legislacao`.`tipo_de_alteracao`), (SELECT COUNT(*) FROM `tipo_de_alteracao`), 'CODTIPODEALTERACAO'
  ) AS migracao(codigo_migracao, origem, destino, quantidade_origem, quantidade_destino, chave_primaria)
ON DUPLICATE KEY UPDATE quantidade_origem = VALUES(quantidade_origem), quantidade_destino = VALUES(quantidade_destino), concluida_em = VALUES(concluida_em), status = VALUES(status), detalhes = VALUES(detalhes);

INSERT INTO `registros_migracao` (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_origem`, `quantidade_destino`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
 ('LEGISLACAO_ESTRUTURA', 'green_legislacao', 'glegislacao_dbp', 'ESTRUTURA', '1.0.0', 'CONCLUIDA', 12, 12, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
  JSON_OBJECT('tabelas', JSON_ARRAY('assuntos','legislacao','legislacao_categoria','legislacaopaixlegislacaofilha','legislacaoxalteracao','legislacaoxassunto','legislacaoxcategoria','legislacaoxmes','legislacaoxpalavraschave','legislacaoxpergunta','perguntas','tipo_de_alteracao'), 'preservar_chaves_legado', TRUE, 'conversao', 'latin1 para utf8mb4_0900_ai_ci'))
ON DUPLICATE KEY UPDATE concluida_em = VALUES(concluida_em), status = VALUES(status), detalhes = VALUES(detalhes);

SET FOREIGN_KEY_CHECKS = 1;
