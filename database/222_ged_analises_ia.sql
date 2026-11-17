-- Migração 222 — Histórico das análises de documentos por IA da Gestão de Arquivos (todos os tipos:
-- licenças, controles, infraestrutura, treinamentos e funcionários). Alimenta a aba "Análises de IA"
-- de gestao_arquivos.php e os alertas de início e resultado de cada análise.
-- Aplicada em todos os bancos de clientes. Pode ser executada mais de uma vez.

CREATE TABLE IF NOT EXISTS `ged_analises_ia` (
    `codanalise` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tipo` VARCHAR(30) NOT NULL COMMENT 'licencas, controles, infraestrutura, treinamentos, funcionarios',
    `chave` VARCHAR(40) NOT NULL COMMENT 'Nome da chave do registro (CODLICENCA, CODFUNCIONARIO...)',
    `codigo_registro` BIGINT NOT NULL,
    `arquivo` VARCHAR(255) NOT NULL,
    `descricao` VARCHAR(255) NULL COMMENT 'Descrição do registro para os alertas (nome do funcionário, da licença...)',
    `status` VARCHAR(12) NOT NULL DEFAULT 'PROCESSANDO' COMMENT 'PROCESSANDO, CONCLUIDA, ERRO, CONFIRMADA, DESCARTADA',
    `resumo` VARCHAR(500) NULL,
    `dados_ia` JSON NULL,
    `confianca` DECIMAL(5,2) NULL,
    `fonte_texto` VARCHAR(30) NULL,
    `mensagem` TEXT NULL,
    `referencia` BIGINT NULL COMMENT 'Registro da análise na tabela específica do tipo (ex.: fo_funcionarios_documentos_ia)',
    `codusuario` BIGINT NULL,
    `codusuario_confirmacao` BIGINT NULL,
    `solicitado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `concluido_em` DATETIME NULL,
    `confirmado_em` DATETIME NULL,
    PRIMARY KEY (`codanalise`),
    KEY `idx_ged_analises_ia_registro` (`tipo`, `codigo_registro`, `solicitado_em`),
    KEY `idx_ged_analises_ia_referencia` (`tipo`, `referencia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
