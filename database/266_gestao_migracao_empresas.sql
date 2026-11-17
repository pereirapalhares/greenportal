-- Green Portal - gestão de migração de empresas legadas.
-- Cadastro fonte: green_seguranca.empresas.
-- Catálogo central: guniversal_dbp.

USE `guniversal_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `empresa_migracao_config` (
    `codconfig` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codempresa` BIGINT UNSIGNED NOT NULL,
    `codigo_legado` BIGINT NULL,
    `banco_legado` VARCHAR(128) NOT NULL,
    `banco_legado_seguranca` VARCHAR(128) NULL,
    `banco_novo` VARCHAR(128) NOT NULL,
    `banco_novo_seguranca` VARCHAR(128) NULL,
    `origem_cadastro` VARCHAR(100) NOT NULL DEFAULT 'green_seguranca.empresas',
    `status_migracao` VARCHAR(30) NOT NULL DEFAULT 'PENDENTE_VALIDACAO',
    `sincronismo_ativo` TINYINT(1) NOT NULL DEFAULT 0,
    `ultima_validacao_em` DATETIME NULL,
    `ultima_migracao_em` DATETIME NULL,
    `ultimo_sincronismo_em` DATETIME NULL,
    `observacoes` VARCHAR(1000) NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`codconfig`),
    UNIQUE KEY `uk_empresa_migracao_empresa` (`codempresa`),
    UNIQUE KEY `uk_empresa_migracao_legado` (`codigo_legado`),
    KEY `ix_empresa_migracao_status` (`status_migracao`),
    CONSTRAINT `fk_empresa_migracao_empresa` FOREIGN KEY (`codempresa`) REFERENCES `empresa` (`codempresa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_empresa_migracao_grid` AS
SELECT
    e.`codempresa`, e.`codigo`, e.`nome`, e.`status`, e.`banco_dados`,
    e.`cnpj`, e.`cidade`, e.`bloqueada`,
    c.`codconfig`, c.`codigo_legado`, c.`banco_legado`,
    c.`banco_legado_seguranca`, c.`banco_novo`, c.`banco_novo_seguranca`,
    c.`status_migracao`, c.`sincronismo_ativo`, c.`ultima_validacao_em`,
    c.`ultima_migracao_em`, c.`ultimo_sincronismo_em`, c.`origem_cadastro`,
    c.`observacoes`, c.`atualizado_em`
FROM `empresa` e
LEFT JOIN `empresa_migracao_config` c ON c.`codempresa` = e.`codempresa`;
