-- Migração 347 — Validação dos documentos da homologação pelos modelos cadastrados (Onda 2 da apresentação Atlas Schindler 2026:
-- "Validação documental — conferência conforme modelos e templates previamente cadastrados").
--
-- Cada critério (documento exigido) de fornecedor ou de funcionário pode ter um modelo:
--   - lista de itens que o documento precisa ter (texto + obrigatório), a orientação geral para a IA;
--   - regras fixas: conferir com o cadastro (razão social/CNPJ ou nome/CPF), exigir assinatura/carimbo, exigir validade,
--     emitido há no máximo N dias, validade mínima de N dias;
--   - arquivo de exemplo (opcional): o texto lido (OCR) serve para a IA sugerir a lista de itens.
-- Validação automática: quando um documento com modelo fica "Pendente com a empresa" e tem arquivo novo, o serviço "ia" do
-- Agendador lê o arquivo (fila de OCR), manda à IA com o modelo (fila de IA) e grava o parecer item a item como uma análise de IA
-- pendente de revisão (Gestão de Arquivos › Análises de IA). A IA só sugere: aprovar, reprovar ou devolver é sempre de uma pessoa.
-- Banco: tabelas, procedures e views; a aplicação lê só por views e grava só por procedures.

CREATE TABLE IF NOT EXISTS `fo_documento_modelos` (
    `CODMODELO` INT NOT NULL AUTO_INCREMENT,
    `ESCOPO` VARCHAR(12) NOT NULL,
    `CODCRITERIO` INT NOT NULL,
    `ATIVO` TINYINT NOT NULL DEFAULT 1,
    `VALIDAR_AUTOMATICO` TINYINT NOT NULL DEFAULT 1,
    `VALIDAR_EXISTENTES` TINYINT NOT NULL DEFAULT 0,
    `ORIENTACAO` TEXT NULL,
    `ITENS` JSON NULL,
    `CONFERIR_CADASTRO` TINYINT NOT NULL DEFAULT 1,
    `EXIGE_ASSINATURA` TINYINT NOT NULL DEFAULT 0,
    `EXIGE_VALIDADE` TINYINT NOT NULL DEFAULT 0,
    `EMISSAO_MAX_DIAS` INT NULL,
    `VALIDADE_MIN_DIAS` INT NULL,
    `ARQUIVO_EXEMPLO` VARCHAR(255) NULL,
    `EXEMPLO_OCR_JOB` BIGINT UNSIGNED NULL,
    `TEXTO_EXEMPLO` MEDIUMTEXT NULL,
    `SUGESTAO_JOB` BIGINT UNSIGNED NULL,
    `ATIVADO_EM` DATETIME NULL,
    `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `CODUSUARIO_ALTERACAO` BIGINT NULL,
    PRIMARY KEY (`CODMODELO`),
    UNIQUE KEY `ux_fo_documento_modelos` (`ESCOPO`, `CODCRITERIO`),
    CONSTRAINT `ck_fo_documento_modelos_escopo` CHECK (`ESCOPO` IN ('FORNECEDOR', 'FUNCIONARIO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
CREATE TABLE IF NOT EXISTS `fo_documento_modelo_validacoes` (
    `CODVALIDACAO` BIGINT NOT NULL AUTO_INCREMENT,
    `ESCOPO` VARCHAR(12) NOT NULL,
    `CODIGO` INT NOT NULL,
    `CODMODELO` INT NOT NULL,
    `ARQUIVO` VARCHAR(255) NOT NULL,
    `HASH` CHAR(64) NOT NULL,
    `STATUS` VARCHAR(20) NOT NULL DEFAULT 'AGUARDANDO_TEXTO',
    `OCR_JOB` BIGINT UNSIGNED NULL,
    `IA_JOB` BIGINT UNSIGNED NULL,
    `CODANALISE_GED` BIGINT NULL,
    `CODANALISE` BIGINT NULL,
    `CONFORMES` INT NULL,
    `TOTAL` INT NULL,
    `FALTANDO` INT NULL,
    `RESULTADO` VARCHAR(15) NULL,
    `MENSAGEM` VARCHAR(1000) NULL,
    `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `CONCLUIDO_EM` DATETIME NULL,
    PRIMARY KEY (`CODVALIDACAO`),
    UNIQUE KEY `ux_fo_documento_modelo_validacoes` (`ESCOPO`, `CODIGO`, `HASH`),
    KEY `ix_fo_documento_modelo_validacoes_status` (`STATUS`, `CODVALIDACAO`),
    KEY `ix_fo_documento_modelo_validacoes_ia` (`IA_JOB`),
    KEY `ix_fo_documento_modelo_validacoes_modelo` (`CODMODELO`, `CODVALIDACAO`),
    CONSTRAINT `ck_fo_documento_modelo_validacoes_status` CHECK (`STATUS` IN ('AGUARDANDO_TEXTO', 'NA_IA', 'CONCLUIDA', 'ERRO', 'IGNORADA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
CREATE TABLE IF NOT EXISTS `fo_documento_modelo_controle` (
    `CHAVE` VARCHAR(40) NOT NULL,
    `VALOR` VARCHAR(255) NULL,
    `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`CHAVE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
DROP VIEW IF EXISTS `vw_doc_modelos`

-- @@
CREATE VIEW `vw_doc_modelos` AS
SELECT m.`CODMODELO`, m.`ESCOPO`, m.`CODCRITERIO`,
       COALESCE(IF(m.`ESCOPO` = 'FORNECEDOR', cf.`DSCRITERIO`, cu.`DSCRITERIO`), '') AS `CRITERIO`,
       m.`ATIVO`, m.`VALIDAR_AUTOMATICO`, m.`VALIDAR_EXISTENTES`, m.`ORIENTACAO`, m.`ITENS`, m.`CONFERIR_CADASTRO`, m.`EXIGE_ASSINATURA`,
       m.`EXIGE_VALIDADE`, m.`EMISSAO_MAX_DIAS`, m.`VALIDADE_MIN_DIAS`, m.`ARQUIVO_EXEMPLO`, m.`EXEMPLO_OCR_JOB`,
       CHAR_LENGTH(COALESCE(m.`TEXTO_EXEMPLO`, '')) AS `TEXTO_EXEMPLO_TAMANHO`, m.`SUGESTAO_JOB`, m.`ATIVADO_EM`, m.`CRIADO_EM`, m.`ALTERADO_EM`,
       m.`CODUSUARIO_ALTERACAO`, COALESCE(JSON_LENGTH(m.`ITENS`), 0) AS `QTD_ITENS`
  FROM `fo_documento_modelos` m
  LEFT JOIN `fo_criteriofornecedores` cf ON m.`ESCOPO` = 'FORNECEDOR' AND cf.`CODCRITERIOFORNECEDOR` = m.`CODCRITERIO`
  LEFT JOIN `fo_criteriofuncionarios` cu ON m.`ESCOPO` = 'FUNCIONARIO' AND cu.`CODCRITERIOFUNCIONARIO` = m.`CODCRITERIO`

-- @@
DROP VIEW IF EXISTS `vw_doc_modelo_texto_exemplo`

-- @@
CREATE VIEW `vw_doc_modelo_texto_exemplo` AS
SELECT `CODMODELO`, `ESCOPO`, `CODCRITERIO`, `TEXTO_EXEMPLO` FROM `fo_documento_modelos`

-- @@
DROP VIEW IF EXISTS `vw_doc_modelo_validacoes`

-- @@
CREATE VIEW `vw_doc_modelo_validacoes` AS
SELECT v.`CODVALIDACAO`, v.`ESCOPO`, v.`CODIGO`, v.`CODMODELO`, m.`CODCRITERIO`, v.`ARQUIVO`, v.`HASH`, v.`STATUS`, v.`OCR_JOB`, v.`IA_JOB`,
       v.`CODANALISE_GED`, v.`CODANALISE`, v.`CONFORMES`, v.`TOTAL`, v.`FALTANDO`, v.`RESULTADO`, v.`MENSAGEM`, v.`CRIADO_EM`, v.`CONCLUIDO_EM`,
       COALESCE(IF(v.`ESCOPO` = 'FORNECEDOR', f.`RAZAOSOCIAL`, CONCAT(COALESCE(fu.`NOME`, ''), ' / ', COALESCE(f2.`RAZAOSOCIAL`, ''))), '') AS `DOCUMENTO_DE`,
       IF(v.`ESCOPO` = 'FORNECEDOR', NULLIF(x.`codusuario`, 0), NULLIF(y.`codusuario`, 0)) AS `CODUSUARIO_EMPRESA`
  FROM `fo_documento_modelo_validacoes` v
  JOIN `fo_documento_modelos` m ON m.`CODMODELO` = v.`CODMODELO`
  LEFT JOIN `fo_fornecedorxcriterios` x ON v.`ESCOPO` = 'FORNECEDOR' AND x.`codfornecedorxcriterios` = v.`CODIGO`
  LEFT JOIN `fo_fornecedores` f ON f.`codfornecedor` = x.`codfornecedor`
  LEFT JOIN `fo_funcionarioxtiposxcriterios` y ON v.`ESCOPO` = 'FUNCIONARIO' AND y.`codigo` = v.`CODIGO`
  LEFT JOIN `fo_funcionarios` fu ON fu.`CODFUNCIONARIO` = y.`codfuncionario`
  LEFT JOIN `fo_fornecedores` f2 ON f2.`codfornecedor` = fu.`CODFORNECEDOR`

-- @@
DROP VIEW IF EXISTS `vw_doc_modelo_controle`

-- @@
CREATE VIEW `vw_doc_modelo_controle` AS SELECT `CHAVE`, `VALOR`, `ALTERADO_EM` FROM `fo_documento_modelo_controle`

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_modelo_salvar`

-- @@
CREATE PROCEDURE `sp_doc_modelo_salvar`(IN p_escopo VARCHAR(12), IN p_criterio INT, IN p_ativo TINYINT, IN p_automatico TINYINT, IN p_existentes TINYINT,
    IN p_orientacao TEXT, IN p_itens JSON, IN p_cadastro TINYINT, IN p_assinatura TINYINT, IN p_validade TINYINT, IN p_emissao INT, IN p_validade_min INT,
    IN p_usuario BIGINT)
BEGIN
    -- ATIVADO_EM marca a partir de quando os arquivos enviados são validados (muda quando o modelo passa a ativo ou a validação automática é ligada)
    DECLARE v_cod INT DEFAULT NULL;
    DECLARE v_ativo_ant TINYINT DEFAULT 0;
    DECLARE v_auto_ant TINYINT DEFAULT 0;
    IF p_escopo NOT IN ('FORNECEDOR', 'FUNCIONARIO') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ESCOPO_INVALIDO'; END IF;
    IF (p_escopo = 'FORNECEDOR' AND NOT EXISTS (SELECT 1 FROM `fo_criteriofornecedores` WHERE `CODCRITERIOFORNECEDOR` = p_criterio))
       OR (p_escopo = 'FUNCIONARIO' AND NOT EXISTS (SELECT 1 FROM `fo_criteriofuncionarios` WHERE `CODCRITERIOFUNCIONARIO` = p_criterio)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CRITERIO_INEXISTENTE';
    END IF;
    IF p_itens IS NOT NULL AND JSON_TYPE(p_itens) <> 'ARRAY' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ITENS_INVALIDOS'; END IF;
    IF (p_emissao IS NOT NULL AND (p_emissao < 1 OR p_emissao > 3650)) OR (p_validade_min IS NOT NULL AND (p_validade_min < 0 OR p_validade_min > 3650)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DIAS_INVALIDOS';
    END IF;
    SELECT `CODMODELO`, `ATIVO`, `VALIDAR_AUTOMATICO` INTO v_cod, v_ativo_ant, v_auto_ant FROM `fo_documento_modelos` WHERE `ESCOPO` = p_escopo AND `CODCRITERIO` = p_criterio;
    IF v_cod IS NULL THEN
        INSERT INTO `fo_documento_modelos` (`ESCOPO`, `CODCRITERIO`, `ATIVO`, `VALIDAR_AUTOMATICO`, `VALIDAR_EXISTENTES`, `ORIENTACAO`, `ITENS`, `CONFERIR_CADASTRO`,
            `EXIGE_ASSINATURA`, `EXIGE_VALIDADE`, `EMISSAO_MAX_DIAS`, `VALIDADE_MIN_DIAS`, `ATIVADO_EM`, `CODUSUARIO_ALTERACAO`)
        VALUES (p_escopo, p_criterio, COALESCE(p_ativo, 1), COALESCE(p_automatico, 1), COALESCE(p_existentes, 0), NULLIF(TRIM(p_orientacao), ''), COALESCE(p_itens, JSON_ARRAY()),
            COALESCE(p_cadastro, 1), COALESCE(p_assinatura, 0), COALESCE(p_validade, 0), p_emissao, p_validade_min,
            IF(COALESCE(p_ativo, 1) = 1 AND COALESCE(p_automatico, 1) = 1, NOW(), NULL), NULLIF(p_usuario, 0));
        SET v_cod = LAST_INSERT_ID();
    ELSE
        UPDATE `fo_documento_modelos`
           SET `ATIVO` = COALESCE(p_ativo, 1), `VALIDAR_AUTOMATICO` = COALESCE(p_automatico, 1), `VALIDAR_EXISTENTES` = COALESCE(p_existentes, 0),
               `ORIENTACAO` = NULLIF(TRIM(p_orientacao), ''), `ITENS` = COALESCE(p_itens, JSON_ARRAY()), `CONFERIR_CADASTRO` = COALESCE(p_cadastro, 1),
               `EXIGE_ASSINATURA` = COALESCE(p_assinatura, 0), `EXIGE_VALIDADE` = COALESCE(p_validade, 0), `EMISSAO_MAX_DIAS` = p_emissao,
               `VALIDADE_MIN_DIAS` = p_validade_min,
               `ATIVADO_EM` = IF(COALESCE(p_ativo, 1) = 1 AND COALESCE(p_automatico, 1) = 1,
                                 IF(v_ativo_ant = 1 AND v_auto_ant = 1 AND `ATIVADO_EM` IS NOT NULL, `ATIVADO_EM`, NOW()), `ATIVADO_EM`),
               `CODUSUARIO_ALTERACAO` = NULLIF(p_usuario, 0)
         WHERE `CODMODELO` = v_cod;
    END IF;
    SELECT v_cod AS CODMODELO;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_modelo_exemplo`

-- @@
CREATE PROCEDURE `sp_doc_modelo_exemplo`(IN p_codmodelo INT, IN p_arquivo VARCHAR(255), IN p_ocr_job BIGINT, IN p_usuario BIGINT)
BEGIN
    -- Novo arquivo de exemplo: o texto anterior é descartado; a leitura (OCR) fica com o job informado
    UPDATE `fo_documento_modelos`
       SET `ARQUIVO_EXEMPLO` = NULLIF(TRIM(p_arquivo), ''), `EXEMPLO_OCR_JOB` = NULLIF(p_ocr_job, 0), `TEXTO_EXEMPLO` = NULL, `SUGESTAO_JOB` = NULL,
           `CODUSUARIO_ALTERACAO` = NULLIF(p_usuario, 0)
     WHERE `CODMODELO` = p_codmodelo;
    SELECT ROW_COUNT() AS ALTERADOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_modelo_exemplo_texto`

-- @@
CREATE PROCEDURE `sp_doc_modelo_exemplo_texto`(IN p_codmodelo INT, IN p_ocr_job BIGINT, IN p_texto MEDIUMTEXT)
BEGIN
    UPDATE `fo_documento_modelos` SET `TEXTO_EXEMPLO` = p_texto WHERE `CODMODELO` = p_codmodelo AND `EXEMPLO_OCR_JOB` = p_ocr_job;
    SELECT ROW_COUNT() AS ALTERADOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_modelo_sugestao_job`

-- @@
CREATE PROCEDURE `sp_doc_modelo_sugestao_job`(IN p_codmodelo INT, IN p_job BIGINT)
BEGIN
    UPDATE `fo_documento_modelos` SET `SUGESTAO_JOB` = NULLIF(p_job, 0) WHERE `CODMODELO` = p_codmodelo;
    SELECT ROW_COUNT() AS ALTERADOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_modelo_do_documento`

-- @@
CREATE PROCEDURE `sp_doc_modelo_do_documento`(IN p_escopo VARCHAR(12), IN p_codigo INT)
BEGIN
    -- Modelo ativo do critério do documento (fornecedor: fo_fornecedorxcriterios.codcriterio; funcionário: codcriteriofuncionario)
    SELECT m.* FROM `vw_doc_modelos` m
     WHERE m.`ESCOPO` = p_escopo AND m.`ATIVO` = 1
       AND m.`CODCRITERIO` = IF(p_escopo = 'FORNECEDOR',
               (SELECT x.`codcriterio` FROM `fo_fornecedorxcriterios` x WHERE x.`codfornecedorxcriterios` = p_codigo),
               (SELECT y.`codcriteriofuncionario` FROM `fo_funcionarioxtiposxcriterios` y WHERE y.`codigo` = p_codigo))
     LIMIT 1;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_modelo_candidatos`

-- @@
CREATE PROCEDURE `sp_doc_modelo_candidatos`(IN p_escopo VARCHAR(12), IN p_limite INT)
BEGIN
    -- Documentos "Pendente com a empresa" cujo critério tem modelo ativo com validação automática (fornecedores ativos)
    DECLARE v_lim INT DEFAULT LEAST(GREATEST(COALESCE(p_limite, 500), 1), 5000);
    IF p_escopo = 'FORNECEDOR' THEN
        SELECT x.`codfornecedorxcriterios` AS CODIGO, m.`CODMODELO`, m.`ATIVADO_EM`, UNIX_TIMESTAMP(m.`ATIVADO_EM`) AS ATIVADO_TS, m.`VALIDAR_EXISTENTES`, NULLIF(x.`codusuario`, 0) AS CODUSUARIO_EMPRESA
          FROM `fo_fornecedorxcriterios` x
          JOIN `fo_documento_modelos` m ON m.`ESCOPO` = 'FORNECEDOR' AND m.`CODCRITERIO` = x.`codcriterio` AND m.`ATIVO` = 1 AND m.`VALIDAR_AUTOMATICO` = 1
          JOIN `fo_fornecedores` f ON f.`codfornecedor` = x.`codfornecedor` AND TRIM(COALESCE(f.`ATIVADOFOR`, '')) = 'Sim'
         WHERE TRIM(COALESCE(x.`situacao`, '')) = 'Pendente com a empresa'
         ORDER BY x.`codfornecedorxcriterios` DESC
         LIMIT v_lim;
    ELSE
        SELECT y.`codigo` AS CODIGO, m.`CODMODELO`, m.`ATIVADO_EM`, UNIX_TIMESTAMP(m.`ATIVADO_EM`) AS ATIVADO_TS, m.`VALIDAR_EXISTENTES`, NULLIF(y.`codusuario`, 0) AS CODUSUARIO_EMPRESA
          FROM `fo_funcionarioxtiposxcriterios` y
          JOIN `fo_documento_modelos` m ON m.`ESCOPO` = 'FUNCIONARIO' AND m.`CODCRITERIO` = y.`codcriteriofuncionario` AND m.`ATIVO` = 1 AND m.`VALIDAR_AUTOMATICO` = 1
          JOIN `fo_funcionarios` fu ON fu.`CODFUNCIONARIO` = y.`codfuncionario`
          JOIN `fo_fornecedores` f ON f.`codfornecedor` = fu.`CODFORNECEDOR` AND TRIM(COALESCE(f.`ATIVADOFOR`, '')) = 'Sim'
         WHERE TRIM(COALESCE(y.`situacao`, '')) = 'Pendente com a empresa'
         ORDER BY y.`codigo` DESC
         LIMIT v_lim;
    END IF;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_modelo_validacao_registrar`

-- @@
CREATE PROCEDURE `sp_doc_modelo_validacao_registrar`(IN p_escopo VARCHAR(12), IN p_codigo INT, IN p_codmodelo INT, IN p_arquivo VARCHAR(255), IN p_hash CHAR(64),
    IN p_status VARCHAR(20), IN p_ocr_job BIGINT, IN p_mensagem VARCHAR(1000))
BEGIN
    -- O mesmo conteúdo (hash) no mesmo documento é validado uma vez só: devolve 0 quando já existia
    INSERT IGNORE INTO `fo_documento_modelo_validacoes` (`ESCOPO`, `CODIGO`, `CODMODELO`, `ARQUIVO`, `HASH`, `STATUS`, `OCR_JOB`, `MENSAGEM`, `CONCLUIDO_EM`)
    VALUES (p_escopo, p_codigo, p_codmodelo, LEFT(p_arquivo, 255), p_hash, COALESCE(p_status, 'AGUARDANDO_TEXTO'), NULLIF(p_ocr_job, 0), LEFT(p_mensagem, 1000),
            IF(COALESCE(p_status, '') IN ('IGNORADA', 'ERRO'), NOW(), NULL));
    SELECT IF(ROW_COUNT() > 0, LAST_INSERT_ID(), 0) AS CODVALIDACAO;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_modelo_validacoes_hashes`

-- @@
CREATE PROCEDURE `sp_doc_modelo_validacoes_hashes`(IN p_escopo VARCHAR(12), IN p_codigos JSON)
BEGIN
    -- Arquivos (nome + hash) já registrados nos documentos informados: o serviço não recalcula nem repete
    SELECT v.`CODIGO`, v.`ARQUIVO`, v.`HASH`
      FROM `fo_documento_modelo_validacoes` v
      JOIN JSON_TABLE(COALESCE(p_codigos, JSON_ARRAY()), '$[*]' COLUMNS (c INT PATH '$')) j ON j.c = v.`CODIGO`
     WHERE v.`ESCOPO` = p_escopo;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_modelo_validacoes_aguardando`

-- @@
CREATE PROCEDURE `sp_doc_modelo_validacoes_aguardando`(IN p_limite INT)
BEGIN
    DECLARE v_lim INT DEFAULT LEAST(GREATEST(COALESCE(p_limite, 10), 1), 200);
    SELECT v.`CODVALIDACAO`, v.`ESCOPO`, v.`CODIGO`, v.`CODMODELO`, v.`ARQUIVO`, v.`HASH`, v.`OCR_JOB`, TIMESTAMPDIFF(MINUTE, v.`CRIADO_EM`, NOW()) AS IDADE_MIN,
           o.`status` AS OCR_STATUS, o.`erro` AS OCR_ERRO
      FROM `fo_documento_modelo_validacoes` v
      LEFT JOIN `green_ocr_jobs` o ON o.`codjob` = v.`OCR_JOB`
     WHERE v.`STATUS` = 'AGUARDANDO_TEXTO'
     ORDER BY v.`CODVALIDACAO`
     LIMIT v_lim;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_modelo_ocr_texto`

-- @@
CREATE PROCEDURE `sp_doc_modelo_ocr_texto`(IN p_ocr_job BIGINT)
SELECT `codjob`, `status`, `texto`, `confianca`, `erro` FROM `green_ocr_jobs` WHERE `codjob` = p_ocr_job

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_modelo_validacao_ia`

-- @@
CREATE PROCEDURE `sp_doc_modelo_validacao_ia`(IN p_codvalidacao BIGINT, IN p_ia_job BIGINT, IN p_codanalise_ged BIGINT)
BEGIN
    UPDATE `fo_documento_modelo_validacoes` SET `STATUS` = 'NA_IA', `IA_JOB` = p_ia_job, `CODANALISE_GED` = NULLIF(p_codanalise_ged, 0)
     WHERE `CODVALIDACAO` = p_codvalidacao AND `STATUS` = 'AGUARDANDO_TEXTO';
    SELECT ROW_COUNT() AS ALTERADOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_modelo_validacao_por_job`

-- @@
CREATE PROCEDURE `sp_doc_modelo_validacao_por_job`(IN p_ia_job BIGINT)
SELECT v.*, j.`status` AS IA_STATUS, j.`resultado_json` AS IA_RESULTADO, j.`erro` AS IA_ERRO
  FROM `fo_documento_modelo_validacoes` v
  JOIN `green_ia_document_jobs` j ON j.`codjob` = v.`IA_JOB`
 WHERE v.`IA_JOB` = p_ia_job AND v.`STATUS` = 'NA_IA'

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_modelo_validacao_concluir`

-- @@
CREATE PROCEDURE `sp_doc_modelo_validacao_concluir`(IN p_codvalidacao BIGINT, IN p_codanalise BIGINT, IN p_conformes INT, IN p_total INT, IN p_faltando INT,
    IN p_resultado VARCHAR(15), IN p_mensagem VARCHAR(1000))
BEGIN
    UPDATE `fo_documento_modelo_validacoes`
       SET `STATUS` = 'CONCLUIDA', `CODANALISE` = NULLIF(p_codanalise, 0), `CONFORMES` = p_conformes, `TOTAL` = p_total, `FALTANDO` = p_faltando,
           `RESULTADO` = p_resultado, `MENSAGEM` = LEFT(p_mensagem, 1000), `CONCLUIDO_EM` = NOW()
     WHERE `CODVALIDACAO` = p_codvalidacao;
    SELECT ROW_COUNT() AS ALTERADOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_modelo_validacao_erro`

-- @@
CREATE PROCEDURE `sp_doc_modelo_validacao_erro`(IN p_codvalidacao BIGINT, IN p_status VARCHAR(20), IN p_mensagem VARCHAR(1000))
BEGIN
    UPDATE `fo_documento_modelo_validacoes`
       SET `STATUS` = IF(p_status IN ('ERRO', 'IGNORADA'), p_status, 'ERRO'), `MENSAGEM` = LEFT(p_mensagem, 1000), `CONCLUIDO_EM` = NOW()
     WHERE `CODVALIDACAO` = p_codvalidacao;
    SELECT ROW_COUNT() AS ALTERADOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_modelo_controle`

-- @@
CREATE PROCEDURE `sp_doc_modelo_controle`(IN p_chave VARCHAR(40), IN p_valor VARCHAR(255))
BEGIN
    INSERT INTO `fo_documento_modelo_controle` (`CHAVE`, `VALOR`) VALUES (p_chave, p_valor) ON DUPLICATE KEY UPDATE `VALOR` = VALUES(`VALOR`);
    SELECT 1 AS OK;
END
