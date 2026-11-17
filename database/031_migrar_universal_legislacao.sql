-- Green Portal - migracao das tabelas universais usadas por Legislacao.
-- Origem: green_universal (latin1)
-- Destino: glegislacao_dbp (utf8mb4_0900_ai_ci)
-- As chaves primarias do legado sao preservadas sem criacao de id artificial.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET FOREIGN_KEY_CHECKS = 0;
USE `glegislacao_dbp`;

CREATE TABLE IF NOT EXISTS `tipos` LIKE `green_universal`.`tipos`;
CREATE TABLE IF NOT EXISTS `tipos_palavras_chaves` LIKE `green_universal`.`tipos_palavras_chaves`;
CREATE TABLE IF NOT EXISTS `tiposxpalavras_chaves` LIKE `green_universal`.`tiposxpalavras_chaves`;
CREATE TABLE IF NOT EXISTS `ambitos` LIKE `green_universal`.`ambitos`;

ALTER TABLE `tipos` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `tipos_palavras_chaves` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `tiposxpalavras_chaves` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE `ambitos` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT IGNORE INTO `tipos` SELECT * FROM `green_universal`.`tipos`;
INSERT IGNORE INTO `tipos_palavras_chaves` SELECT * FROM `green_universal`.`tipos_palavras_chaves`;
INSERT IGNORE INTO `tiposxpalavras_chaves` SELECT * FROM `green_universal`.`tiposxpalavras_chaves`;
INSERT IGNORE INTO `ambitos` SELECT * FROM `green_universal`.`ambitos`;

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`,
     `quantidade_origem`, `quantidade_destino`, `iniciada_em`, `concluida_em`, `detalhes`)
SELECT codigo_migracao, origem, destino, 'DADOS', '1.0.0', 'CONCLUIDA', quantidade_origem, quantidade_destino,
       CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
       JSON_OBJECT('chave_primaria', chave_primaria, 'charset_origem', 'latin1', 'collation_destino', 'utf8mb4_0900_ai_ci')
  FROM (
    SELECT 'UNIVERSAL_TIPOS', 'green_universal.tipos', 'glegislacao_dbp.tipos', (SELECT COUNT(*) FROM `green_universal`.`tipos`), (SELECT COUNT(*) FROM `tipos`), 'CODTIPO'
    UNION ALL SELECT 'UNIVERSAL_TIPOS_PALAVRAS_CHAVES', 'green_universal.tipos_palavras_chaves', 'glegislacao_dbp.tipos_palavras_chaves', (SELECT COUNT(*) FROM `green_universal`.`tipos_palavras_chaves`), (SELECT COUNT(*) FROM `tipos_palavras_chaves`), 'codtipopalavrachave'
    UNION ALL SELECT 'UNIVERSAL_TIPOS_X_PALAVRAS_CHAVES', 'green_universal.tiposxpalavras_chaves', 'glegislacao_dbp.tiposxpalavras_chaves', (SELECT COUNT(*) FROM `green_universal`.`tiposxpalavras_chaves`), (SELECT COUNT(*) FROM `tiposxpalavras_chaves`), 'CODTIPOXPALAVRACHAVE'
    UNION ALL SELECT 'UNIVERSAL_AMBITOS', 'green_universal.ambitos', 'glegislacao_dbp.ambitos', (SELECT COUNT(*) FROM `green_universal`.`ambitos`), (SELECT COUNT(*) FROM `ambitos`), 'CODAMBITO'
  ) AS migracao(codigo_migracao, origem, destino, quantidade_origem, quantidade_destino, chave_primaria)
ON DUPLICATE KEY UPDATE quantidade_origem = VALUES(quantidade_origem), quantidade_destino = VALUES(quantidade_destino), concluida_em = VALUES(concluida_em), status = VALUES(status), detalhes = VALUES(detalhes);

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`,
     `quantidade_origem`, `quantidade_destino`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES ('UNIVERSAL_LEGISLACAO_ESTRUTURA', 'green_universal', 'glegislacao_dbp', 'ESTRUTURA', '1.0.0', 'CONCLUIDA', 4, 4, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
        JSON_OBJECT('tabelas', JSON_ARRAY('tipos','tipos_palavras_chaves','tiposxpalavras_chaves','ambitos'), 'preservar_chaves_legado', TRUE, 'conversao', 'latin1 para utf8mb4_0900_ai_ci'))
ON DUPLICATE KEY UPDATE concluida_em = VALUES(concluida_em), status = VALUES(status), detalhes = VALUES(detalhes);

SET FOREIGN_KEY_CHECKS = 1;
