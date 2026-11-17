-- Green Portal - migracao do cadastro central de empresas.
-- Origem: gmodelo_dbp.empresa.
-- Destino: guniversal_dbp.empresa.
-- A chave codempresa e todos os dados cadastrais sao preservados.

CREATE DATABASE IF NOT EXISTS `guniversal_dbp`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;

USE `guniversal_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `empresa` (
    `codempresa` BIGINT UNSIGNED NOT NULL,
    `codigo_legado` BIGINT NULL,
    `codigo` VARCHAR(50) NOT NULL,
    `nome` VARCHAR(150) NOT NULL,
    `banco_dados` VARCHAR(128) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'ATIVA',
    `criada_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `endereco` VARCHAR(100) NULL,
    `bairro` VARCHAR(100) NULL,
    `cidade` VARCHAR(100) NULL,
    `cep` VARCHAR(100) NULL,
    `email` VARCHAR(500) NULL,
    `cnpj` VARCHAR(20) NULL,
    `contatos` VARCHAR(500) NULL,
    `telefones` VARCHAR(100) NULL,
    `observacoes` VARCHAR(500) NULL,
    `ged` VARCHAR(100) NULL,
    `ips` VARCHAR(500) NULL,
    `multempresa` VARCHAR(100) NULL,
    `logotipo` VARCHAR(100) NULL,
    `bloqueada` VARCHAR(1) NULL,
    PRIMARY KEY (`codempresa`),
    UNIQUE KEY `uk_empresa_codigo` (`codigo`),
    UNIQUE KEY `uk_empresa_banco` (`banco_dados`),
    UNIQUE KEY `uk_empresa_codigo_legado` (`codigo_legado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `guniversal_dbp`.`empresa` (
    `codempresa`, `codigo_legado`, `codigo`, `nome`, `banco_dados`, `status`,
    `criada_em`, `endereco`, `bairro`, `cidade`, `cep`, `email`, `cnpj`,
    `contatos`, `telefones`, `observacoes`, `ged`, `ips`, `multempresa`,
    `logotipo`, `bloqueada`
)
SELECT
    `codempresa`, `codigo_legado`, `codigo`, `nome`, `banco_dados`, `status`,
    `criada_em`, `endereco`, `bairro`, `cidade`, `cep`, `email`, `cnpj`,
    `contatos`, `telefones`, `observacoes`, `ged`, `ips`, `multempresa`,
    `logotipo`, `bloqueada`
FROM `gmodelo_dbp`.`empresa`
ON DUPLICATE KEY UPDATE
    `codigo_legado` = VALUES(`codigo_legado`),
    `codigo` = VALUES(`codigo`),
    `nome` = VALUES(`nome`),
    `banco_dados` = VALUES(`banco_dados`),
    `status` = VALUES(`status`),
    `criada_em` = VALUES(`criada_em`),
    `endereco` = VALUES(`endereco`),
    `bairro` = VALUES(`bairro`),
    `cidade` = VALUES(`cidade`),
    `cep` = VALUES(`cep`),
    `email` = VALUES(`email`),
    `cnpj` = VALUES(`cnpj`),
    `contatos` = VALUES(`contatos`),
    `telefones` = VALUES(`telefones`),
    `observacoes` = VALUES(`observacoes`),
    `ged` = VALUES(`ged`),
    `ips` = VALUES(`ips`),
    `multempresa` = VALUES(`multempresa`),
    `logotipo` = VALUES(`logotipo`),
    `bloqueada` = VALUES(`bloqueada`);

ALTER TABLE `guniversal_dbp`.`empresa` AUTO_INCREMENT = 149;

CREATE TABLE IF NOT EXISTS `guniversal_dbp`.`setup_empresa` (
    `codsetupempresa` BIGINT UNSIGNED NOT NULL,
    `chave` VARCHAR(100) NOT NULL,
    `valor` TEXT NULL,
    `idioma` CHAR(2) NOT NULL DEFAULT 'pt',
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`codsetupempresa`),
    UNIQUE KEY `uk_setup_chave_idioma` (`chave`, `idioma`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `guniversal_dbp`.`setup_empresa`
    (`codsetupempresa`, `chave`, `valor`, `idioma`, `atualizado_em`)
SELECT `codsetupempresa`, `chave`, `valor`, `idioma`, `atualizado_em`
  FROM `gmodelo_dbp`.`setup_empresa`
ON DUPLICATE KEY UPDATE
    `codsetupempresa` = VALUES(`codsetupempresa`),
    `valor` = VALUES(`valor`),
    `atualizado_em` = VALUES(`atualizado_em`);

ALTER TABLE `guniversal_dbp`.`setup_empresa` AUTO_INCREMENT = 44;

USE `guniversal_dbp`;

CREATE OR REPLACE VIEW `vw_empresa_grid` AS
SELECT `codempresa`, `codigo`, `nome`, `banco_dados`, `status`, `criada_em`
  FROM `empresa`;

CREATE OR REPLACE VIEW `vw_setup_empresa_grid` AS
SELECT `codsetupempresa`, `chave`, `valor`, `idioma`, `atualizado_em`
  FROM `setup_empresa`;

-- Registro da migracao no historico da empresa Modelo.
USE `gmodelo_dbp`;
INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`,
     `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
SELECT
    'EMPRESA_UNIVERSAL', 'gmodelo_dbp.empresa', 'guniversal_dbp.empresa',
    'DADOS', '1.0.0', 'CONCLUIDA', COUNT(*), CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP,
    JSON_OBJECT('script', 'database/032_migrar_empresa_universal.sql',
                'empresa_registros', COUNT(*),
                'setup_empresa_registros', (SELECT COUNT(*) FROM `setup_empresa`),
                'chave_primaria', 'codempresa',
                'collation', 'utf8mb4_0900_ai_ci')
FROM `empresa`
ON DUPLICATE KEY UPDATE
    `status` = 'CONCLUIDA',
    `quantidade_registros` = VALUES(`quantidade_registros`),
    `concluida_em` = CURRENT_TIMESTAMP,
    `detalhes` = VALUES(`detalhes`);
