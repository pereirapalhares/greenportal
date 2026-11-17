-- Migração 349 — Homologação de Fornecedores: análise de adulteração dos documentos (Onda 3 da apresentação Atlas Schindler 2026,
-- "IA aplicada aos documentos — 3. Análise de adulteração").
--
-- Cada arquivo novo de documento de fornecedor ou de funcionário é analisado pelo serviço "ia" do Agendador (classes/HomologacaoDocumentoAdulteracao.php):
--   - metadados do PDF (programa que gerou/editou, datas de criação e modificação, PDF salvo por cima), de imagens (EXIF) e do Word;
--   - assinatura digital do PDF (íntegra? o arquivo foi alterado depois de assinado?);
--   - imagem: regiões com compressão diferente do resto (possível colagem);
--   - conteúdo: CNPJ/CPF com dígito inválido, datas impossíveis, emissão no futuro ou depois da criação do arquivo, e a leitura da IA
--     (prompt DOC_ADULTERACAO), que aponta incoerências do texto.
-- Resultado: risco SEM_INDICIOS, BAIXO, MEDIO ou ALTO, com os indícios. A análise só aponta: a decisão é de uma pessoa.
-- Risco ALTO sem ciência: o documento não pode ser aprovado (fornecedor: Aprovado / Aprovado sem validade; funcionário: Atende = Sim)
-- até alguém registrar a ciência com o motivo (gatilhos abaixo; o sincronismo do legado, @gp_sinc_legado = 1, não é bloqueado).
-- As regras automáticas da 301 (FORN_ADULTERACAO / FUNC_ADULTERACAO) levam esses documentos para "Pendente com a empresa".
-- Banco: tabelas, procedures e views; a aplicação lê só por views e grava só por procedures. Comandos separados por "-- @@".

CREATE TABLE IF NOT EXISTS `fo_doc_adulteracao` (
    `CODANALISE` BIGINT NOT NULL AUTO_INCREMENT,
    `ESCOPO` VARCHAR(12) NOT NULL,
    `CODIGO` INT NOT NULL,
    `ARQUIVO` VARCHAR(255) NOT NULL,
    `HASH` CHAR(64) NOT NULL,
    `ORIGEM` VARCHAR(12) NOT NULL DEFAULT 'AUTOMATICA',
    `STATUS` VARCHAR(20) NOT NULL DEFAULT 'AGUARDANDO_TEXTO',
    `ARQUIVO_PRESENTE` TINYINT NOT NULL DEFAULT 1,
    `OCR_JOB` BIGINT UNSIGNED NULL,
    `IA_JOB` BIGINT UNSIGNED NULL,
    `LOCAIS` JSON NULL,
    `INDICIOS` JSON NULL,
    `RISCO` VARCHAR(12) NULL,
    `RESUMO` VARCHAR(1000) NULL,
    `IA_CONSULTADA` TINYINT NOT NULL DEFAULT 0,
    `MENSAGEM` VARCHAR(1000) NULL,
    `CODUSUARIO_PEDIDO` BIGINT NULL,
    `CIENTE_POR` BIGINT NULL,
    `CIENTE_EM` DATETIME NULL,
    `CIENTE_MOTIVO` VARCHAR(1000) NULL,
    `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `CONCLUIDO_EM` DATETIME NULL,
    PRIMARY KEY (`CODANALISE`),
    UNIQUE KEY `ux_fo_doc_adulteracao` (`ESCOPO`, `CODIGO`, `HASH`),
    KEY `ix_fo_doc_adulteracao_status` (`STATUS`, `CODANALISE`),
    KEY `ix_fo_doc_adulteracao_ia` (`IA_JOB`),
    KEY `ix_fo_doc_adulteracao_doc` (`ESCOPO`, `CODIGO`, `RISCO`, `CIENTE_EM`),
    CONSTRAINT `ck_fo_doc_adulteracao_escopo` CHECK (`ESCOPO` IN ('FORNECEDOR', 'FUNCIONARIO')),
    CONSTRAINT `ck_fo_doc_adulteracao_status` CHECK (`STATUS` IN ('AGUARDANDO_TEXTO', 'NA_IA', 'CONCLUIDA', 'ERRO')),
    CONSTRAINT `ck_fo_doc_adulteracao_risco` CHECK (`RISCO` IS NULL OR `RISCO` IN ('SEM_INDICIOS', 'BAIXO', 'MEDIO', 'ALTO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT 'Análise de adulteração dos arquivos dos documentos da homologação (migração 349)'

-- @@
CREATE TABLE IF NOT EXISTS `fo_doc_adulteracao_log` (
    `CODLOG` BIGINT NOT NULL AUTO_INCREMENT,
    `CODANALISE` BIGINT NOT NULL,
    `ACAO` VARCHAR(20) NOT NULL,
    `CODUSUARIO` BIGINT NULL,
    `MOTIVO` VARCHAR(1000) NULL,
    `EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`CODLOG`),
    KEY `ix_fo_doc_adulteracao_log` (`CODANALISE`, `CODLOG`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT 'Pedidos manuais e ciências da análise de adulteração (migração 349)'

-- @@
CREATE TABLE IF NOT EXISTS `fo_doc_adulteracao_controle` (
    `CHAVE` VARCHAR(40) NOT NULL,
    `VALOR` VARCHAR(255) NULL,
    `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`CHAVE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
-- Marca da ativação: só arquivos enviados a partir daqui são analisados sozinhos (os antigos, pelo botão "Analisar este arquivo").
INSERT IGNORE INTO `fo_doc_adulteracao_controle` (`CHAVE`, `VALOR`) VALUES ('ATIVADO_TS', CAST(UNIX_TIMESTAMP() AS CHAR))

-- @@
CREATE OR REPLACE VIEW `vw_doc_adult_controle` AS
SELECT `CHAVE`, `VALOR`, `ALTERADO_EM` FROM `fo_doc_adulteracao_controle`

-- @@
CREATE OR REPLACE VIEW `vw_doc_adulteracao` AS
SELECT a.`CODANALISE`, a.`ESCOPO`, a.`CODIGO`, a.`ARQUIVO`, a.`HASH`, a.`ORIGEM`, a.`STATUS`, a.`ARQUIVO_PRESENTE`, a.`OCR_JOB`, a.`IA_JOB`,
       a.`LOCAIS`, a.`INDICIOS`, a.`RISCO`, a.`RESUMO`, a.`IA_CONSULTADA`, a.`MENSAGEM`, a.`CODUSUARIO_PEDIDO`,
       a.`CIENTE_POR`, uc.`nome` AS CIENTE_NOME, a.`CIENTE_EM`, a.`CIENTE_MOTIVO`, a.`CRIADO_EM`, a.`CONCLUIDO_EM`,
       (a.`STATUS` = 'CONCLUIDA' AND a.`RISCO` = 'ALTO' AND a.`CIENTE_EM` IS NULL AND a.`ARQUIVO_PRESENTE` = 1) AS PENDENTE_CIENCIA,
       COALESCE(f1.`codfornecedor`, f2.`codfornecedor`) AS CODFORNECEDOR,
       COALESCE(f1.`RAZAOSOCIAL`, f2.`RAZAOSOCIAL`) AS RAZAOSOCIAL,
       fu.`NOME` AS FUNCIONARIO,
       COALESCE(c1.`DSCRITERIO`, c2.`DSCRITERIO`) AS DSCRITERIO,
       NULLIF(COALESCE(x.`codusuario`, y.`codusuario`), 0) AS CODUSUARIO_EMPRESA,
       COALESCE(x.`situacao`, y.`situacao`) AS SITUACAO_DOC,
       y.`atendeaocriterio` AS ATENDE_DOC
  FROM `fo_doc_adulteracao` a
  LEFT JOIN `fo_fornecedorxcriterios` x ON a.`ESCOPO` = 'FORNECEDOR' AND x.`codfornecedorxcriterios` = a.`CODIGO`
  LEFT JOIN `fo_fornecedores` f1 ON f1.`codfornecedor` = x.`codfornecedor`
  LEFT JOIN `fo_criteriofornecedores` c1 ON c1.`codcriteriofornecedor` = x.`codcriterio`
  LEFT JOIN `fo_funcionarioxtiposxcriterios` y ON a.`ESCOPO` = 'FUNCIONARIO' AND y.`codigo` = a.`CODIGO`
  LEFT JOIN `fo_funcionarios` fu ON fu.`CODFUNCIONARIO` = y.`codfuncionario`
  LEFT JOIN `fo_fornecedores` f2 ON f2.`codfornecedor` = fu.`CODFORNECEDOR`
  LEFT JOIN `fo_criteriofuncionarios` c2 ON c2.`codcriteriofuncionario` = y.`codcriteriofuncionario`
  LEFT JOIN `usuarios` uc ON uc.`codusuario` = a.`CIENTE_POR`

-- @@
-- Documentos que não podem ser aprovados: arquivo presente com risco ALTO, análise concluída e sem ciência.
CREATE OR REPLACE VIEW `vw_doc_adult_pendentes` AS
SELECT `ESCOPO`, `CODIGO`, COUNT(*) AS ARQUIVOS, MAX(`CODANALISE`) AS CODANALISE
  FROM `fo_doc_adulteracao`
 WHERE `STATUS` = 'CONCLUIDA' AND `RISCO` = 'ALTO' AND `CIENTE_EM` IS NULL AND `ARQUIVO_PRESENTE` = 1
 GROUP BY `ESCOPO`, `CODIGO`

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_adult_controle`

-- @@
CREATE PROCEDURE `sp_doc_adult_controle`(IN p_chave VARCHAR(40), IN p_valor VARCHAR(255))
INSERT INTO `fo_doc_adulteracao_controle` (`CHAVE`, `VALOR`) VALUES (p_chave, p_valor) ON DUPLICATE KEY UPDATE `VALOR` = VALUES(`VALOR`)

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_adult_candidatos`

-- @@
CREATE PROCEDURE `sp_doc_adult_candidatos`(IN p_escopo VARCHAR(12), IN p_limite INT)
BEGIN
    -- Documentos cujos arquivos o serviço examina: fornecedor ativo, documento que pode ser aprovado (menos NA e "Não enviar");
    -- funcionário ativo de fornecedor ativo, menos NAP/Afastado/Desligado. Mais novos primeiro.
    DECLARE v_lim INT DEFAULT LEAST(GREATEST(COALESCE(p_limite, 20000), 1), 100000);
    IF p_escopo = 'FORNECEDOR' THEN
        SELECT x.`codfornecedorxcriterios` AS CODIGO
          FROM `fo_fornecedorxcriterios` x
          JOIN `fo_fornecedores` f ON f.`codfornecedor` = x.`codfornecedor` AND TRIM(COALESCE(f.`ATIVADOFOR`, '')) = 'Sim'
         WHERE TRIM(COALESCE(x.`situacao`, '')) NOT IN ('NA', 'Não enviar ao fornecedor')
         ORDER BY x.`codfornecedorxcriterios` DESC
         LIMIT v_lim;
    ELSEIF p_escopo = 'FUNCIONARIO' THEN
        SELECT y.`codigo` AS CODIGO
          FROM `fo_funcionarioxtiposxcriterios` y
          JOIN `fo_funcionarios` fu ON fu.`CODFUNCIONARIO` = y.`codfuncionario`
          JOIN `fo_fornecedores` f ON f.`codfornecedor` = fu.`CODFORNECEDOR` AND TRIM(COALESCE(f.`ATIVADOFOR`, '')) = 'Sim'
         WHERE UPPER(TRIM(COALESCE(y.`atendeaocriterio`, ''))) NOT IN ('NAP', 'AFASTADO', 'DESLIGADO')
         ORDER BY y.`codigo` DESC
         LIMIT v_lim;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escopo inválido (use FORNECEDOR ou FUNCIONARIO).';
    END IF;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_adult_hashes`

-- @@
CREATE PROCEDURE `sp_doc_adult_hashes`(IN p_escopo VARCHAR(12), IN p_codigos JSON)
BEGIN
    -- Arquivos já registrados nos documentos informados: o serviço não recalcula nem repete
    SELECT a.`CODIGO`, a.`ARQUIVO`, a.`HASH`, a.`CODANALISE`, a.`ARQUIVO_PRESENTE`
      FROM `fo_doc_adulteracao` a
      JOIN JSON_TABLE(COALESCE(p_codigos, JSON_ARRAY()), '$[*]' COLUMNS (c INT PATH '$')) j ON j.c = a.`CODIGO`
     WHERE a.`ESCOPO` = p_escopo COLLATE utf8mb4_0900_ai_ci;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_adult_registrar`

-- @@
CREATE PROCEDURE `sp_doc_adult_registrar`(IN p_escopo VARCHAR(12), IN p_codigo INT, IN p_arquivo VARCHAR(255), IN p_hash CHAR(64),
    IN p_origem VARCHAR(12), IN p_usuario BIGINT, IN p_ocr_job BIGINT)
BEGIN
    -- O mesmo conteúdo (hash) no mesmo documento é analisado uma vez só. Pedido manual refaz só a análise que terminou em erro.
    DECLARE v_cod BIGINT DEFAULT NULL;
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_novo TINYINT DEFAULT 0;
    IF p_escopo NOT IN ('FORNECEDOR', 'FUNCIONARIO') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escopo inválido.'; END IF;
    IF COALESCE(p_codigo, 0) < 1 OR COALESCE(p_hash, '') NOT REGEXP '^[0-9a-f]{64}$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Documento ou arquivo inválido.'; END IF;
    SELECT `CODANALISE`, `STATUS` INTO v_cod, v_status FROM `fo_doc_adulteracao`
     WHERE `ESCOPO` = p_escopo COLLATE utf8mb4_0900_ai_ci AND `CODIGO` = p_codigo AND `HASH` = p_hash COLLATE utf8mb4_0900_ai_ci LIMIT 1;
    IF v_cod IS NULL THEN
        INSERT INTO `fo_doc_adulteracao` (`ESCOPO`, `CODIGO`, `ARQUIVO`, `HASH`, `ORIGEM`, `STATUS`, `OCR_JOB`, `CODUSUARIO_PEDIDO`)
        VALUES (p_escopo, p_codigo, LEFT(p_arquivo, 255), p_hash, IF(p_origem = 'MANUAL', 'MANUAL', 'AUTOMATICA'), 'AGUARDANDO_TEXTO', NULLIF(p_ocr_job, 0), NULLIF(p_usuario, 0));
        SET v_cod = LAST_INSERT_ID(), v_novo = 1, v_status = 'AGUARDANDO_TEXTO';
    ELSEIF v_status = 'ERRO' AND p_origem = 'MANUAL' THEN
        UPDATE `fo_doc_adulteracao`
           SET `STATUS` = 'AGUARDANDO_TEXTO', `ARQUIVO` = LEFT(p_arquivo, 255), `ARQUIVO_PRESENTE` = 1, `OCR_JOB` = NULLIF(p_ocr_job, 0), `IA_JOB` = NULL,
               `LOCAIS` = NULL, `INDICIOS` = NULL, `RISCO` = NULL, `RESUMO` = NULL, `IA_CONSULTADA` = 0, `MENSAGEM` = NULL,
               `ORIGEM` = 'MANUAL', `CODUSUARIO_PEDIDO` = NULLIF(p_usuario, 0), `CRIADO_EM` = NOW(), `CONCLUIDO_EM` = NULL
         WHERE `CODANALISE` = v_cod;
        SET v_novo = 1, v_status = 'AGUARDANDO_TEXTO';
    END IF;
    IF v_novo = 1 AND p_origem = 'MANUAL' THEN
        INSERT INTO `fo_doc_adulteracao_log` (`CODANALISE`, `ACAO`, `CODUSUARIO`) VALUES (v_cod, 'PEDIDO_MANUAL', NULLIF(p_usuario, 0));
    END IF;
    SELECT v_cod AS CODANALISE, v_novo AS NOVO, v_status AS STATUS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_adult_presenca`

-- @@
CREATE PROCEDURE `sp_doc_adult_presenca`(IN p_escopo VARCHAR(12), IN p_codigo INT, IN p_arquivos JSON)
BEGIN
    -- Arquivos que continuam no GED (lista de nomes): os que saíram deixam de contar para o bloqueio da aprovação
    UPDATE `fo_doc_adulteracao` a
       SET a.`ARQUIVO_PRESENTE` = IF(JSON_CONTAINS(COALESCE(p_arquivos, JSON_ARRAY()), JSON_QUOTE(a.`ARQUIVO`)), 1, 0)
     WHERE a.`ESCOPO` = p_escopo COLLATE utf8mb4_0900_ai_ci AND a.`CODIGO` = p_codigo;
    SELECT ROW_COUNT() AS ALTERADOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_adult_arquivo_excluido`

-- @@
CREATE PROCEDURE `sp_doc_adult_arquivo_excluido`(IN p_escopo VARCHAR(12), IN p_codigo INT, IN p_arquivo VARCHAR(255))
BEGIN
    UPDATE `fo_doc_adulteracao` SET `ARQUIVO_PRESENTE` = 0
     WHERE `ESCOPO` = p_escopo COLLATE utf8mb4_0900_ai_ci AND `CODIGO` = p_codigo AND `ARQUIVO` = p_arquivo COLLATE utf8mb4_0900_ai_ci;
    SELECT ROW_COUNT() AS ALTERADOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_adult_aguardando`

-- @@
CREATE PROCEDURE `sp_doc_adult_aguardando`(IN p_limite INT)
BEGIN
    DECLARE v_lim INT DEFAULT LEAST(GREATEST(COALESCE(p_limite, 10), 1), 200);
    SELECT a.`CODANALISE`, a.`ESCOPO`, a.`CODIGO`, a.`ARQUIVO`, a.`HASH`, a.`ORIGEM`, a.`OCR_JOB`, a.`CODUSUARIO_PEDIDO`,
           TIMESTAMPDIFF(MINUTE, a.`CRIADO_EM`, NOW()) AS IDADE_MIN, o.`status` AS OCR_STATUS, o.`erro` AS OCR_ERRO
      FROM `fo_doc_adulteracao` a
      LEFT JOIN `green_ocr_jobs` o ON o.`codjob` = a.`OCR_JOB`
     WHERE a.`STATUS` = 'AGUARDANDO_TEXTO'
     ORDER BY a.`ORIGEM` = 'MANUAL' DESC, a.`CODANALISE`
     LIMIT v_lim;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_adult_ocr_texto`

-- @@
CREATE PROCEDURE `sp_doc_adult_ocr_texto`(IN p_ocr_job BIGINT)
SELECT `codjob`, `status`, `texto`, `confianca`, `erro` FROM `green_ocr_jobs` WHERE `codjob` = p_ocr_job

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_adult_ia`

-- @@
CREATE PROCEDURE `sp_doc_adult_ia`(IN p_codanalise BIGINT, IN p_locais JSON, IN p_ia_job BIGINT)
BEGIN
    UPDATE `fo_doc_adulteracao` SET `STATUS` = 'NA_IA', `LOCAIS` = p_locais, `IA_JOB` = p_ia_job
     WHERE `CODANALISE` = p_codanalise AND `STATUS` = 'AGUARDANDO_TEXTO';
    SELECT ROW_COUNT() AS ALTERADOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_adult_por_job`

-- @@
CREATE PROCEDURE `sp_doc_adult_por_job`(IN p_ia_job BIGINT)
SELECT a.*, j.`status` AS IA_STATUS, j.`resultado_json` AS IA_RESULTADO, j.`erro` AS IA_ERRO
  FROM `fo_doc_adulteracao` a
  JOIN `green_ia_document_jobs` j ON j.`codjob` = a.`IA_JOB`
 WHERE a.`IA_JOB` = p_ia_job AND a.`STATUS` = 'NA_IA'

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_adult_concluir`

-- @@
CREATE PROCEDURE `sp_doc_adult_concluir`(IN p_codanalise BIGINT, IN p_risco VARCHAR(12), IN p_indicios JSON, IN p_locais JSON,
    IN p_resumo VARCHAR(1000), IN p_ia_consultada TINYINT, IN p_mensagem VARCHAR(1000))
BEGIN
    IF p_risco NOT IN ('SEM_INDICIOS', 'BAIXO', 'MEDIO', 'ALTO') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Risco inválido.'; END IF;
    UPDATE `fo_doc_adulteracao`
       SET `STATUS` = 'CONCLUIDA', `RISCO` = p_risco, `INDICIOS` = p_indicios, `LOCAIS` = COALESCE(p_locais, `LOCAIS`), `RESUMO` = LEFT(p_resumo, 1000),
           `IA_CONSULTADA` = COALESCE(p_ia_consultada, 0), `MENSAGEM` = LEFT(p_mensagem, 1000), `CONCLUIDO_EM` = NOW()
     WHERE `CODANALISE` = p_codanalise AND `STATUS` IN ('AGUARDANDO_TEXTO', 'NA_IA');
    SELECT v.* FROM `vw_doc_adulteracao` v WHERE v.`CODANALISE` = p_codanalise;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_adult_erro`

-- @@
CREATE PROCEDURE `sp_doc_adult_erro`(IN p_codanalise BIGINT, IN p_mensagem VARCHAR(1000))
BEGIN
    UPDATE `fo_doc_adulteracao` SET `STATUS` = 'ERRO', `MENSAGEM` = LEFT(p_mensagem, 1000), `CONCLUIDO_EM` = NOW()
     WHERE `CODANALISE` = p_codanalise AND `STATUS` IN ('AGUARDANDO_TEXTO', 'NA_IA');
    SELECT ROW_COUNT() AS ALTERADOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_adult_ciente`

-- @@
CREATE PROCEDURE `sp_doc_adult_ciente`(IN p_codanalise BIGINT, IN p_usuario BIGINT, IN p_motivo VARCHAR(1000))
BEGIN
    -- Ciência de quem revisou: libera a aprovação do documento apesar do risco. Motivo obrigatório (mínimo 10 caracteres).
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_risco VARCHAR(12) DEFAULT NULL;
    DECLARE v_ciente DATETIME DEFAULT NULL;
    SET p_motivo = TRIM(COALESCE(p_motivo, ''));
    IF CHAR_LENGTH(p_motivo) < 10 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escreva o motivo (pelo menos 10 caracteres): por que o documento pode ser aceito apesar dos indícios.'; END IF;
    IF COALESCE(p_usuario, 0) < 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Usuário inválido.'; END IF;
    SELECT `STATUS`, `RISCO`, `CIENTE_EM` INTO v_status, v_risco, v_ciente FROM `fo_doc_adulteracao` WHERE `CODANALISE` = p_codanalise;
    IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Análise não localizada.'; END IF;
    IF v_status <> 'CONCLUIDA' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A análise ainda não terminou.'; END IF;
    IF v_risco NOT IN ('MEDIO', 'ALTO') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Só análises de risco médio ou alto pedem ciência.'; END IF;
    IF v_ciente IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A ciência já foi registrada.'; END IF;
    UPDATE `fo_doc_adulteracao` SET `CIENTE_POR` = p_usuario, `CIENTE_EM` = NOW(), `CIENTE_MOTIVO` = LEFT(p_motivo, 1000) WHERE `CODANALISE` = p_codanalise;
    INSERT INTO `fo_doc_adulteracao_log` (`CODANALISE`, `ACAO`, `CODUSUARIO`, `MOTIVO`) VALUES (p_codanalise, 'CIENCIA', p_usuario, LEFT(p_motivo, 1000));
    SELECT v.* FROM `vw_doc_adulteracao` v WHERE v.`CODANALISE` = p_codanalise;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_doc_adult_listar`

-- @@
CREATE PROCEDURE `sp_doc_adult_listar`(IN p_escopo VARCHAR(12), IN p_codigo INT)
SELECT v.* FROM `vw_doc_adulteracao` v
 WHERE v.`ESCOPO` = p_escopo COLLATE utf8mb4_0900_ai_ci AND v.`CODIGO` = p_codigo
 ORDER BY v.`CODANALISE` DESC

-- @@
DROP TRIGGER IF EXISTS `trg_fo_doc_adult_forn_bu`

-- @@
-- Fornecedor: aprovar (Aprovado / Aprovado sem validade) um documento com arquivo de risco ALTO sem ciência é recusado.
-- O sincronismo do legado (@gp_sinc_legado = 1) não passa por esta regra: até a virada o legado prevalece.
CREATE TRIGGER `trg_fo_doc_adult_forn_bu` BEFORE UPDATE ON `fo_fornecedorxcriterios` FOR EACH ROW
BEGIN
    IF COALESCE(@gp_sinc_legado, 0) <> 1
       AND TRIM(COALESCE(NEW.`situacao`, '')) IN ('Aprovado', 'Aprovado sem validade')
       AND TRIM(COALESCE(OLD.`situacao`, '')) NOT IN ('Aprovado', 'Aprovado sem validade')
       AND EXISTS (SELECT 1 FROM `fo_doc_adulteracao` a WHERE a.`ESCOPO` = 'FORNECEDOR' AND a.`CODIGO` = NEW.`codfornecedorxcriterios`
                    AND a.`STATUS` = 'CONCLUIDA' AND a.`RISCO` = 'ALTO' AND a.`CIENTE_EM` IS NULL AND a.`ARQUIVO_PRESENTE` = 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Risco alto de adulteração: para aprovar, registre a ciência com o motivo nos Arquivos do documento (aba Adulteração).';
    END IF;
END

-- @@
DROP TRIGGER IF EXISTS `trg_fo_doc_adult_func_bu`

-- @@
-- Funcionário: "Atende ao critério = Sim" num documento com arquivo de risco ALTO sem ciência é recusado.
CREATE TRIGGER `trg_fo_doc_adult_func_bu` BEFORE UPDATE ON `fo_funcionarioxtiposxcriterios` FOR EACH ROW
BEGIN
    IF COALESCE(@gp_sinc_legado, 0) <> 1
       AND UPPER(TRIM(COALESCE(NEW.`atendeaocriterio`, ''))) = 'SIM'
       AND UPPER(TRIM(COALESCE(OLD.`atendeaocriterio`, ''))) <> 'SIM'
       AND EXISTS (SELECT 1 FROM `fo_doc_adulteracao` a WHERE a.`ESCOPO` = 'FUNCIONARIO' AND a.`CODIGO` = NEW.`codigo`
                    AND a.`STATUS` = 'CONCLUIDA' AND a.`RISCO` = 'ALTO' AND a.`CIENTE_EM` IS NULL AND a.`ARQUIVO_PRESENTE` = 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Risco alto de adulteração: para Atende = Sim, registre a ciência com o motivo nos Arquivos do documento (aba Adulteração).';
    END IF;
END
