-- Green Portal - migracao do cadastro de empresas legado.
-- Origem: green_seguranca.empresas (latin1, chave CODEMPRESA).
-- Destino: gmodelo_dbp.empresa (utf8mb4 / utf8mb4_0900_ai_ci).

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

ALTER TABLE `empresa`
    ADD COLUMN `codigo_legado` BIGINT NULL AFTER `codempresa`;

ALTER TABLE `empresa`
    ADD UNIQUE KEY `uk_empresa_codigo_legado` (`codigo_legado`);

-- O registro Modelo ja criado no template passa a preservar a chave CODEMPRESA
-- original e continua apontando para o banco criado do novo portal.
UPDATE `empresa`
   SET `codempresa` = 4,
       `codigo_legado` = 4,
       `codigo` = 'MODELO',
       `nome` = 'Modelo',
       `banco_dados` = 'gmodelo_dbp',
       `status` = 'ATIVA'
 WHERE `codigo` = 'MODELO';

INSERT INTO `empresa`
    (`codempresa`, `codigo_legado`, `codigo`, `nome`, `banco_dados`, `status`)
SELECT origem.`CODEMPRESA`, origem.`CODEMPRESA`,
       CASE WHEN origem.`CODEMPRESA` = 4 THEN 'MODELO'
            ELSE UPPER(CONVERT(origem.`DESCRICAO` USING utf8mb4)) END,
       CONVERT(origem.`DESCRICAO` USING utf8mb4),
       CONVERT(origem.`BANCO_DE_DADOS` USING utf8mb4),
       CASE WHEN origem.`bloqueada` = 'S' THEN 'BLOQUEADA' ELSE 'ATIVA' END
  FROM `green_seguranca`.`empresas` origem
 WHERE NOT EXISTS (
       SELECT 1 FROM `empresa` destino
        WHERE destino.`codigo_legado` = origem.`CODEMPRESA`
   );

UPDATE `empresa`
   SET `banco_dados` = 'gmodelo_dbp'
 WHERE `codigo_legado` = 4;

-- Somente a empresa Modelo fica disponivel no ambiente inicial. As demais
-- permanecem cadastradas para provisionamento futuro, mas nao podem autenticar.
UPDATE `empresa`
   SET `status` = CASE WHEN `codigo_legado` = 4 THEN 'ATIVA' ELSE 'INATIVA' END;

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
SELECT 'EMPRESAS_LEGADO_INICIAL', 'green_seguranca.empresas', 'gmodelo_dbp.empresa', 'DADOS', '1.0.0',
       'CONCLUIDA', COUNT(*), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
       JSON_OBJECT('chave_origem', 'CODEMPRESA', 'campo_banco', 'BANCO_DE_DADOS')
  FROM `green_seguranca`.`empresas`
ON DUPLICATE KEY UPDATE `status` = 'CONCLUIDA', `quantidade_registros` = VALUES(`quantidade_registros`), `concluida_em` = CURRENT_TIMESTAMP;
