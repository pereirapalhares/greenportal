-- Migração 261 — Análise por IA das evidências da Portaria › Documentação de Fornecedor.
-- Aplicado por scripts/aplicar-migracao-261-portaria-documentacao-fornecedor-ia.php em cada banco de cliente,
-- que troca o marcador CNPJ (coluna do CNPJ em fo_fornecedores, ou NULL).
-- Leitura e gravação só por procedures (principal/api/fornecedorcriterios_ia.php):
--   sp_docfornecedor_ia_registro   documento exigido + fornecedor (para o prompt e para a revisão)
--   sp_docfornecedor_ia_gravar     grava a análise PENDENTE e devolve codanalise
--   sp_docfornecedor_ia_obter      uma análise do documento
--   sp_docfornecedor_ia_descartar  marca a análise como DESCARTADA
--   sp_docfornecedor_ia_confirmar  grava os dados revisados no documento (fo_fornecedorxcriterios), protege os campos
--                                  contra o sincronismo legado -> novo (fo_sinc_protecoes) e marca a análise CONFIRMADA
-- Comandos separados por "-- @@".

-- @@
CREATE TABLE IF NOT EXISTS `fo_fornecedorxcriterios_documentos_ia` (
    `codanalise` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codfornecedorxcriterios` INT NOT NULL,
    `arquivo` VARCHAR(255) NOT NULL,
    `tipo_documento` VARCHAR(120) NULL,
    `fonte_texto` VARCHAR(20) NULL,
    `confianca_texto` DECIMAL(5,2) NULL,
    `dados_ia` JSON NULL,
    `confianca` DECIMAL(5,2) NULL,
    `dados_confirmados` JSON NULL,
    `campos_aplicados` JSON NULL,
    `status` VARCHAR(12) NOT NULL DEFAULT 'PENDENTE' COMMENT 'PENDENTE, CONFIRMADA, DESCARTADA',
    `codusuario_analise` BIGINT NULL,
    `codusuario_confirmacao` BIGINT NULL,
    `analisado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `confirmado_em` DATETIME NULL,
    PRIMARY KEY (`codanalise`),
    KEY `idx_fo_fxc_doc_ia_registro` (`codfornecedorxcriterios`, `analisado_em`),
    KEY `idx_fo_fxc_doc_ia_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
CREATE TABLE IF NOT EXISTS `fo_sinc_protecoes` (
    `codprotecao` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tabela` VARCHAR(64) NOT NULL,
    `chave` VARCHAR(190) NOT NULL,
    `coluna` VARCHAR(64) NOT NULL,
    `origem` VARCHAR(30) NOT NULL DEFAULT 'IA_DOCUMENTO',
    `referencia` BIGINT NULL,
    `codusuario` BIGINT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codprotecao`),
    UNIQUE KEY `uk_fo_sinc_protecoes` (`tabela`, `chave`, `coluna`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
DROP PROCEDURE IF EXISTS `sp_docfornecedor_ia_registro`

-- @@
CREATE PROCEDURE `sp_docfornecedor_ia_registro`(IN p_codigo INT)
SELECT g.CODFORNECEDORXCRITERIOS, g.CODFORNECEDOR, g.RAZAOSOCIAL, {{CNPJ}} AS CNPJ, g.UNIDADEFORNECEDOR, g.DSCRITERIO,
       g.SITUACAO, g.DATADEVALIDADE, g.ENVIADOODOCUMENTO, g.OBSERVACAO, g.OBSERVACAO_DO_FORNECEDOR
  FROM `vw_portaria_documentacao_fornecedor_grid` g
  JOIN `fo_fornecedores` f ON f.codfornecedor = g.CODFORNECEDOR
 WHERE g.CODFORNECEDORXCRITERIOS = p_codigo

-- @@
DROP PROCEDURE IF EXISTS `sp_docfornecedor_ia_gravar`

-- @@
CREATE PROCEDURE `sp_docfornecedor_ia_gravar`(IN p_codigo INT, IN p_arquivo VARCHAR(255), IN p_tipo VARCHAR(120), IN p_fonte VARCHAR(20),
    IN p_confianca_texto DECIMAL(5,2), IN p_dados JSON, IN p_confianca DECIMAL(5,2), IN p_usuario BIGINT)
BEGIN
    INSERT INTO `fo_fornecedorxcriterios_documentos_ia` (codfornecedorxcriterios, arquivo, tipo_documento, fonte_texto, confianca_texto, dados_ia, confianca, status, codusuario_analise)
    VALUES (p_codigo, p_arquivo, LEFT(p_tipo, 120), p_fonte, p_confianca_texto, p_dados, p_confianca, 'PENDENTE', NULLIF(p_usuario, 0));
    SELECT LAST_INSERT_ID() AS codanalise;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_docfornecedor_ia_obter`

-- @@
CREATE PROCEDURE `sp_docfornecedor_ia_obter`(IN p_codanalise BIGINT, IN p_codigo INT)
SELECT codanalise, codfornecedorxcriterios, arquivo, tipo_documento, fonte_texto, dados_ia, confianca, status, analisado_em
  FROM `fo_fornecedorxcriterios_documentos_ia`
 WHERE codanalise = p_codanalise AND codfornecedorxcriterios = p_codigo

-- @@
DROP PROCEDURE IF EXISTS `sp_docfornecedor_ia_descartar`

-- @@
CREATE PROCEDURE `sp_docfornecedor_ia_descartar`(IN p_codanalise BIGINT, IN p_usuario BIGINT)
BEGIN
    UPDATE `fo_fornecedorxcriterios_documentos_ia`
       SET status = 'DESCARTADA', codusuario_confirmacao = NULLIF(p_usuario, 0), confirmado_em = NOW()
     WHERE codanalise = p_codanalise AND status = 'PENDENTE';
    SELECT ROW_COUNT() AS alterados;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_docfornecedor_ia_confirmar`

-- @@
-- Campos NULL não mudam. p_situacao precisa ser uma situação conhecida do legado.
CREATE PROCEDURE `sp_docfornecedor_ia_confirmar`(IN p_codanalise BIGINT, IN p_codigo INT, IN p_validade DATE, IN p_situacao VARCHAR(60),
    IN p_enviado VARCHAR(3), IN p_observacao TEXT, IN p_dados JSON, IN p_usuario BIGINT)
BEGIN
    DECLARE v_status VARCHAR(12);
    DECLARE v_aplicados JSON DEFAULT JSON_OBJECT();
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    SELECT status INTO v_status FROM `fo_fornecedorxcriterios_documentos_ia`
     WHERE codanalise = p_codanalise AND codfornecedorxcriterios = p_codigo;
    IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ANALISE_NAO_LOCALIZADA'; END IF;
    IF v_status <> 'PENDENTE' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ANALISE_ENCERRADA'; END IF;
    IF p_situacao IS NOT NULL AND p_situacao NOT IN ('Aprovado', 'Aprovado sem validade', 'Pendente com o fornecedor', 'Pendente com a empresa', 'Reprovado', 'NA', 'Não enviar ao fornecedor', 'Ciente da regra') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SITUACAO_INVALIDA';
    END IF;
    IF p_enviado IS NOT NULL AND p_enviado NOT IN ('Sim', 'Não') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ENVIADO_INVALIDO'; END IF;

    START TRANSACTION;
    IF p_validade IS NOT NULL THEN
        UPDATE `fo_fornecedorxcriterios` SET datadevalidade = p_validade WHERE codfornecedorxcriterios = p_codigo;
        SET v_aplicados = JSON_SET(v_aplicados, '$.datadevalidade', DATE_FORMAT(p_validade, '%Y-%m-%d'));
    END IF;
    IF p_situacao IS NOT NULL THEN
        UPDATE `fo_fornecedorxcriterios` SET situacao = p_situacao WHERE codfornecedorxcriterios = p_codigo;
        SET v_aplicados = JSON_SET(v_aplicados, '$.situacao', p_situacao);
    END IF;
    IF p_enviado IS NOT NULL THEN
        UPDATE `fo_fornecedorxcriterios` SET enviadoodocumento = p_enviado WHERE codfornecedorxcriterios = p_codigo;
        SET v_aplicados = JSON_SET(v_aplicados, '$.enviadoodocumento', p_enviado);
    END IF;
    IF p_observacao IS NOT NULL THEN
        UPDATE `fo_fornecedorxcriterios` SET observacao = p_observacao WHERE codfornecedorxcriterios = p_codigo;
        SET v_aplicados = JSON_SET(v_aplicados, '$.observacao', p_observacao);
    END IF;
    -- O sincronismo legado -> novo não sobrescreve os campos confirmados aqui.
    INSERT INTO `fo_sinc_protecoes` (tabela, chave, coluna, origem, referencia, codusuario)
    SELECT 'fo_fornecedorxcriterios', CAST(p_codigo AS CHAR), k.coluna, 'IA_DOCUMENTO', p_codanalise, NULLIF(p_usuario, 0)
      FROM JSON_TABLE(JSON_KEYS(v_aplicados), '$[*]' COLUMNS (coluna VARCHAR(64) PATH '$')) k
    ON DUPLICATE KEY UPDATE referencia = VALUES(referencia), codusuario = VALUES(codusuario), criado_em = CURRENT_TIMESTAMP;
    UPDATE `fo_fornecedorxcriterios_documentos_ia`
       SET status = 'CONFIRMADA', dados_confirmados = p_dados, campos_aplicados = v_aplicados,
           codusuario_confirmacao = NULLIF(p_usuario, 0), confirmado_em = NOW()
     WHERE codanalise = p_codanalise;
    COMMIT;
    SELECT v_aplicados AS aplicados;
END
