-- Completa a migracao cadastral de green_seguranca.empresas.
-- Origem latin1; destino utf8mb4.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

ALTER TABLE `empresa`
    ADD COLUMN `endereco` VARCHAR(100) NULL,
    ADD COLUMN `bairro` VARCHAR(100) NULL,
    ADD COLUMN `cidade` VARCHAR(100) NULL,
    ADD COLUMN `cep` VARCHAR(100) NULL,
    ADD COLUMN `email` VARCHAR(500) NULL,
    ADD COLUMN `cnpj` VARCHAR(20) NULL,
    ADD COLUMN `contatos` VARCHAR(500) NULL,
    ADD COLUMN `telefones` VARCHAR(100) NULL,
    ADD COLUMN `observacoes` VARCHAR(500) NULL,
    ADD COLUMN `ged` VARCHAR(100) NULL,
    ADD COLUMN `ips` VARCHAR(500) NULL,
    ADD COLUMN `multempresa` VARCHAR(100) NULL,
    ADD COLUMN `logotipo` VARCHAR(100) NULL,
    ADD COLUMN `bloqueada` VARCHAR(1) NULL;

UPDATE `empresa` destino
JOIN `green_seguranca`.`empresas` origem
  ON destino.`codigo_legado` = origem.`CODEMPRESA`
   SET destino.`endereco` = CONVERT(origem.`ENDERECO` USING utf8mb4),
       destino.`bairro` = CONVERT(origem.`BAIRRO` USING utf8mb4),
       destino.`cidade` = CONVERT(origem.`CIDADE` USING utf8mb4),
       destino.`cep` = CONVERT(origem.`CEP` USING utf8mb4),
       destino.`email` = CONVERT(origem.`EMAIL` USING utf8mb4),
       destino.`cnpj` = CONVERT(origem.`CNPJ` USING utf8mb4),
       destino.`contatos` = CONVERT(origem.`CONTATOS` USING utf8mb4),
       destino.`telefones` = CONVERT(origem.`TELEFONES` USING utf8mb4),
       destino.`observacoes` = CONVERT(origem.`OBSERVACOES` USING utf8mb4),
       destino.`ged` = CONVERT(origem.`GED` USING utf8mb4),
       destino.`ips` = CONVERT(origem.`ips` USING utf8mb4),
       destino.`multempresa` = CONVERT(origem.`multempresa` USING utf8mb4),
       destino.`logotipo` = CONVERT(origem.`logotipo` USING utf8mb4),
       destino.`bloqueada` = CONVERT(origem.`bloqueada` USING utf8mb4);

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
SELECT 'EMPRESAS_LEGADO_COMPLEMENTAR', 'green_seguranca.empresas', 'gmodelo_dbp.empresa', 'DADOS', '1.0.0',
       'CONCLUIDA', COUNT(*), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
       JSON_OBJECT('campos', 'cadastro completo', 'conversao', 'latin1 para utf8mb4')
  FROM `green_seguranca`.`empresas`
ON DUPLICATE KEY UPDATE `status` = 'CONCLUIDA', `quantidade_registros` = VALUES(`quantidade_registros`), `concluida_em` = CURRENT_TIMESTAMP;
