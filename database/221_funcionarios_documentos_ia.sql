-- Migração 221 — Análise por IA dos documentos dos funcionários (Homologação de Fornecedores).
-- 1) fo_funcionarios_documentos_ia: cada análise feita pela IA (ASO, certificados de treinamento),
--    com os dados sugeridos, os dados confirmados pelo usuário e onde foram aplicados.
--    Fica PENDENTE até o usuário confirmar ou descartar.
-- 2) fo_sinc_protecoes: campos que o sincronismo legado -> novo NÃO deve sobrescrever
--    (preenchidos no sistema novo a partir de documentos confirmados).
-- O prompt FUNCIONARIO_DOCUMENTO (ia_prompts) é cadastrado pelo script da migração.
-- Aplicada em todos os bancos de clientes. Pode ser executada mais de uma vez.

CREATE TABLE IF NOT EXISTS `fo_funcionarios_documentos_ia` (
    `codanalise` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codfuncionario` INT NOT NULL,
    `arquivo` VARCHAR(255) NOT NULL,
    `tipo_documento` VARCHAR(20) NULL COMMENT 'ASO, TREINAMENTO ou OUTRO',
    `fonte_texto` VARCHAR(20) NULL COMMENT 'PDF_TEXTO, OCR_PDF, OCR_IMAGEM, DOCX_TEXTO',
    `confianca_texto` DECIMAL(5,2) NULL,
    `linhas_omitidas` INT NOT NULL DEFAULT 0 COMMENT 'Linhas com dados de saúde removidas antes do envio à IA',
    `dados_ia` JSON NULL,
    `confianca` DECIMAL(5,2) NULL,
    `dados_confirmados` JSON NULL,
    `status` VARCHAR(12) NOT NULL DEFAULT 'PENDENTE' COMMENT 'PENDENTE, CONFIRMADA, DESCARTADA',
    `destino_tabela` VARCHAR(64) NULL,
    `destino_codigo` BIGINT NULL,
    `codusuario_analise` BIGINT NULL,
    `codusuario_confirmacao` BIGINT NULL,
    `analisado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `confirmado_em` DATETIME NULL,
    PRIMARY KEY (`codanalise`),
    KEY `idx_fo_func_doc_ia_funcionario` (`codfuncionario`, `analisado_em`),
    KEY `idx_fo_func_doc_ia_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `fo_sinc_protecoes` (
    `codprotecao` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tabela` VARCHAR(64) NOT NULL,
    `chave` VARCHAR(190) NOT NULL COMMENT 'Valor da chave primária do registro (colunas separadas por |)',
    `coluna` VARCHAR(64) NOT NULL COMMENT 'Nome da coluna em minúsculas',
    `origem` VARCHAR(30) NOT NULL DEFAULT 'IA_DOCUMENTO',
    `referencia` BIGINT NULL COMMENT 'codanalise que originou a proteção',
    `codusuario` BIGINT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codprotecao`),
    UNIQUE KEY `uk_fo_sinc_protecoes` (`tabela`, `chave`, `coluna`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
