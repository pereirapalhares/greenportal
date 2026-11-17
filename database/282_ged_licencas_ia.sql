-- Migração 282 — Etapa 2 do levantamento de SQL direto: GED, licenças e IA (banco de cada empresa).
-- Aplicada por scripts/aplicar-migracao-282-ged-licencas-ia.php, que só cria cada view quando as tabelas dela existem
-- no banco (marcador "-- requer: tabela1, tabela2"). Pode ser executada mais de uma vez.
-- ================================================================ views (leitura)
-- @@
-- requer: ged_analises_ia
CREATE OR REPLACE VIEW `vw_ged_analises_ia` AS SELECT * FROM `ged_analises_ia`
-- @@
-- requer: usuarios
CREATE OR REPLACE VIEW `vw_usuarios_nome` AS SELECT `codusuario`, COALESCE(NULLIF(`nome`, ''), `userid`) AS `nome_exibicao` FROM `usuarios`
-- @@
-- requer: li_licencas
CREATE OR REPLACE VIEW `vw_li_licencas_completo` AS SELECT * FROM `li_licencas`
-- @@
-- requer: green_ocr_jobs
CREATE OR REPLACE VIEW `vw_green_ocr_jobs` AS SELECT * FROM `green_ocr_jobs`
-- @@
-- requer: fo_funcionarios_documentos_ia
CREATE OR REPLACE VIEW `vw_fo_funcionarios_documentos_ia` AS SELECT * FROM `fo_funcionarios_documentos_ia`
-- @@
-- requer: fo_funcionarios, fo_tipofuncionarios, fo_fornecedores
CREATE OR REPLACE VIEW `vw_fo_funcionario_ia` AS
SELECT f.CODFUNCIONARIO, f.NOME, f.NRIDENTIFICACAO, f.CODTIPOFUNCIONARIO, f.CODFORNECEDOR, t.DSCTIPO, p.RAZAOSOCIAL
  FROM fo_funcionarios f
  LEFT JOIN fo_tipofuncionarios t ON t.codtipofuncionario = f.CODTIPOFUNCIONARIO
  LEFT JOIN fo_fornecedores p ON p.codfornecedor = f.CODFORNECEDOR
-- @@
-- requer: fo_funcionarioxtiposxcriterios, fo_criteriofuncionarios
CREATE OR REPLACE VIEW `vw_fo_funcionario_criterios_destino` AS
SELECT r.codigo, r.codfuncionario, COALESCE(c.DSCRITERIO, CONCAT('#', r.codcriteriofuncionario)) AS descricao, r.codtipofuncionario,
       r.datadevalidade AS validade, r.atendeaocriterio AS situacao
  FROM fo_funcionarioxtiposxcriterios r
  LEFT JOIN fo_criteriofuncionarios c ON c.codcriteriofuncionario = r.codcriteriofuncionario
-- @@
-- requer: fo_funcionarioxtiposxtreinamentos, fo_tipotreinamentos
CREATE OR REPLACE VIEW `vw_fo_funcionario_treinamentos_destino` AS
SELECT r.codigo, r.codfuncionario, COALESCE(tt.DSCTIPO, CONCAT('#', r.codtipotreinamento)) AS descricao, r.codtipofuncionario,
       r.datadevalidadetreinamento AS validade, r.treinamentorealizado AS situacao
  FROM fo_funcionarioxtiposxtreinamentos r
  LEFT JOIN fo_tipotreinamentos tt ON tt.codtipotreinamento = r.codtipotreinamento
-- @@
-- requer: ml_potenciais_solicitacoes
CREATE OR REPLACE VIEW `vw_ml_potenciais_solicitacoes` AS SELECT * FROM `ml_potenciais_solicitacoes`
-- @@
-- requer: mm_empresa
CREATE OR REPLACE VIEW `vw_mm_empresa_nome` AS SELECT `codempresa`, `dscempresa` FROM `mm_empresa`
-- @@
-- requer: legislacaoxlicencas, content_document_links, {{LEGISLACAO}}
CREATE OR REPLACE VIEW `vw_licenca_requisitos_resumo` AS
SELECT r.CODLICENCA, r.CODLEGISLACAO, r.codunidade, COALESCE(l.CODIGO, CONCAT('Requisito ', r.CODLEGISLACAO)) AS codigo,
       COALESCE(l.NR, '') AS nr, COALESCE(l.ANO, '') AS ano, COUNT(DISTINCT x.coddocumento) AS documentos
  FROM legislacaoxlicencas r
  LEFT JOIN {{LEGISLACAO}} l ON l.CODLEGISLACAO = r.CODLEGISLACAO
  LEFT JOIN content_document_links x ON x.tipo_entidade = 'LICENCA_REQUISITO' AND x.entidade_id = r.CODLICENCA
       AND x.codrequisitolegal = r.CODLEGISLACAO AND x.codunidade = r.codunidade AND x.status = 'ATIVO'
 GROUP BY r.CODLICENCA, r.CODLEGISLACAO, r.codunidade, l.CODIGO, l.NR, l.ANO
-- @@
-- requer: content_document_links, content_documents, content_versions
CREATE OR REPLACE VIEW `vw_licenca_documentos_resumo` AS
SELECT x.entidade_id AS CODLICENCA, d.coddocumento, d.codigo, d.titulo, d.status, d.versao_atual, COUNT(v.codversaodocumento) AS versoes
  FROM content_document_links x
  JOIN content_documents d ON d.coddocumento = x.coddocumento
  LEFT JOIN content_versions v ON v.coddocumento = d.coddocumento
 WHERE x.tipo_entidade = 'LICENCA' AND x.status = 'ATIVO'
 GROUP BY x.entidade_id, d.coddocumento, d.codigo, d.titulo, d.status, d.versao_atual
-- ================================================================ procedures (gravação)
-- @@
DROP PROCEDURE IF EXISTS `sp_log_auditoria_registrar`
-- @@
DROP PROCEDURE IF EXISTS `sp_ged_analise_ia_iniciar`
-- @@
CREATE PROCEDURE `sp_ged_analise_ia_iniciar`(IN p_tipo VARCHAR(30), IN p_chave VARCHAR(40), IN p_codigo BIGINT, IN p_arquivo VARCHAR(255),
    IN p_descricao VARCHAR(255), IN p_codusuario BIGINT, IN p_hash VARCHAR(64), IN p_origem BIGINT)
BEGIN
    -- Registra a solicitação de análise de IA de um arquivo do GED (status PROCESSANDO) e devolve o codanalise
    INSERT INTO ged_analises_ia (tipo, chave, codigo_registro, arquivo, descricao, status, codusuario, hash_arquivo, analise_origem)
    VALUES (p_tipo, p_chave, p_codigo, p_arquivo, NULLIF(p_descricao, ''), 'PROCESSANDO', NULLIF(p_codusuario, 0), NULLIF(p_hash, ''), p_origem);
    SELECT LAST_INSERT_ID() AS codanalise;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ged_analise_ia_concluir`
-- @@
CREATE PROCEDURE `sp_ged_analise_ia_concluir`(IN p_codanalise BIGINT, IN p_resumo VARCHAR(500), IN p_dados LONGTEXT, IN p_confianca DECIMAL(5,2),
    IN p_fonte VARCHAR(30), IN p_referencia BIGINT)
BEGIN
    UPDATE ged_analises_ia SET status = 'CONCLUIDA', resumo = p_resumo, dados_ia = p_dados, confianca = p_confianca, fonte_texto = NULLIF(p_fonte, ''),
           referencia = COALESCE(p_referencia, referencia), concluido_em = NOW()
     WHERE codanalise = p_codanalise;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ged_analise_ia_falhar`
-- @@
CREATE PROCEDURE `sp_ged_analise_ia_falhar`(IN p_codanalise BIGINT, IN p_mensagem VARCHAR(1000))
BEGIN
    UPDATE ged_analises_ia SET status = 'ERRO', mensagem = p_mensagem, concluido_em = NOW() WHERE codanalise = p_codanalise;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ged_analise_ia_encerrar`
-- @@
CREATE PROCEDURE `sp_ged_analise_ia_encerrar`(IN p_codanalise BIGINT, IN p_status VARCHAR(12), IN p_codusuario BIGINT)
BEGIN
    -- Confirmação ou descarte da sugestão pelo usuário
    IF p_status IN ('CONFIRMADA', 'DESCARTADA') THEN
        UPDATE ged_analises_ia SET status = p_status, codusuario_confirmacao = NULLIF(p_codusuario, 0), confirmado_em = NOW() WHERE codanalise = p_codanalise;
    END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ged_licenca_vincular_documento`
-- @@
CREATE PROCEDURE `sp_ged_licenca_vincular_documento`(IN p_coddocumento BIGINT, IN p_codlicenca BIGINT, IN p_codusuario BIGINT)
BEGIN
    -- Arquivo enviado para a licença: vínculo do documento do GED com a licença (na unidade da licença)
    DECLARE v_unidade BIGINT DEFAULT 0;
    SELECT COALESCE(codunidade, 0) INTO v_unidade FROM li_licencas WHERE CODLICENCA = p_codlicenca LIMIT 1;
    INSERT IGNORE INTO content_document_links (coddocumento, tipo_entidade, entidade_id, codunidade, papel, origem_vinculo, status, codusuario)
    VALUES (p_coddocumento, 'LICENCA', p_codlicenca, v_unidade, 'ARQUIVO', 'USUARIO', 'ATIVO', p_codusuario);
END
-- @@
DROP PROCEDURE IF EXISTS `sp_licenca_ativar_versao`
-- @@
CREATE PROCEDURE `sp_licenca_ativar_versao`(IN p_codlicenca BIGINT, IN p_base BIGINT, IN p_descricao VARCHAR(1000), IN p_validade DATE,
    IN p_atividade VARCHAR(500), IN p_orgao VARCHAR(255), IN p_numero VARCHAR(255), IN p_observacao TEXT, IN p_ia LONGTEXT, IN p_extracao LONGTEXT,
    IN p_confianca DECIMAL(5,2))
BEGIN
    -- Confirma a extração da IA e ativa a versão: as versões ativas anteriores da mesma pasta (CODLICENCA_BASE) ficam SUBSTITUIDA e
    -- os vínculos com requisitos legais e os vínculos do GED passam para a nova versão. Chamar dentro de uma transação.
    DECLARE v_anteriores TEXT DEFAULT NULL;
    SELECT GROUP_CONCAT(CODLICENCA) INTO v_anteriores FROM li_licencas
     WHERE CODLICENCA_BASE = p_base AND STATUS_VERSAO = 'ATIVA' AND CODLICENCA <> p_codlicenca FOR UPDATE;
    UPDATE li_licencas SET DSCLICENCA = p_descricao, DATA_VALIDADE = p_validade, atividade = p_atividade, ORGAO_EMISSOR = p_orgao,
           NUMERO_LICENCA = p_numero, observacao = p_observacao, DADOS_DOCUMENTO_IA = p_ia, DADOS_EXTRACAO_JSON = p_extracao,
           CONFIANCA_EXTRACAO = p_confianca, FONTE_EXTRACAO = 'IA', STATUS_EXTRACAO = 'CONCLUIDA', DATA_EXTRACAO = NOW(), STATUS_VERSAO = 'ATIVA'
     WHERE CODLICENCA = p_codlicenca;
    IF v_anteriores IS NOT NULL THEN
        UPDATE li_licencas SET STATUS_VERSAO = 'SUBSTITUIDA' WHERE FIND_IN_SET(CODLICENCA, v_anteriores);
        INSERT INTO legislacaoxlicencas (CODLEGISLACAO, CODLICENCA, codunidade)
        SELECT DISTINCT x.CODLEGISLACAO, p_codlicenca, x.codunidade FROM legislacaoxlicencas x
         WHERE FIND_IN_SET(x.CODLICENCA, v_anteriores)
           AND NOT EXISTS (SELECT 1 FROM legislacaoxlicencas y WHERE y.CODLEGISLACAO = x.CODLEGISLACAO AND y.CODLICENCA = p_codlicenca AND y.codunidade = x.codunidade);
        DELETE FROM legislacaoxlicencas WHERE FIND_IN_SET(CODLICENCA, v_anteriores);
        INSERT IGNORE INTO content_document_links (coddocumento, tipo_entidade, entidade_id, codrequisitolegal, codunidade, papel, origem_vinculo, status, observacao, codusuario)
        SELECT coddocumento, tipo_entidade, p_codlicenca, COALESCE(codrequisitolegal, 0), COALESCE(codunidade, 0), papel, 'VERSIONAMENTO', status, observacao, codusuario
          FROM content_document_links
         WHERE FIND_IN_SET(entidade_id, v_anteriores) AND tipo_entidade IN ('LICENCA', 'LICENCA_REQUISITO');
    END IF;
    SELECT COALESCE(v_anteriores, '') AS substituidas;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ocr_job_enfileirar`
-- @@
CREATE PROCEDURE `sp_ocr_job_enfileirar`(IN p_chave VARCHAR(191), IN p_tipo VARCHAR(50), IN p_entidade VARCHAR(100), IN p_registro VARCHAR(100),
    IN p_arquivo VARCHAR(255), IN p_caminho VARCHAR(1000), IN p_tamanho BIGINT, IN p_codusuario BIGINT)
BEGIN
    -- Coloca o arquivo na fila de OCR (idempotente pela chave) e devolve o job
    INSERT INTO green_ocr_jobs (chave_idempotencia, tipo, entidade, registro_id, arquivo, caminho_arquivo, tamanho_bytes, codusuario, status)
    VALUES (p_chave, p_tipo, p_entidade, p_registro, p_arquivo, p_caminho, p_tamanho, NULLIF(p_codusuario, 0), 'PENDENTE')
    ON DUPLICATE KEY UPDATE codjob = LAST_INSERT_ID(codjob), atualizado_em = CURRENT_TIMESTAMP;
    SELECT * FROM green_ocr_jobs WHERE codjob = LAST_INSERT_ID();
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ocr_job_reservar`
-- @@
CREATE PROCEDURE `sp_ocr_job_reservar`(IN p_processador VARCHAR(100))
BEGIN
    -- Reserva o próximo job pendente (chamar dentro de uma transação). Sem job: não devolve linhas.
    DECLARE v_job BIGINT DEFAULT NULL;
    SELECT codjob INTO v_job FROM green_ocr_jobs
     WHERE status = 'PENDENTE' AND disponivel_em <= CURRENT_TIMESTAMP
     ORDER BY criado_em, codjob LIMIT 1 FOR UPDATE SKIP LOCKED;
    IF v_job IS NOT NULL THEN
        UPDATE green_ocr_jobs SET status = 'PROCESSANDO', tentativas = tentativas + 1, iniciada_em = CURRENT_TIMESTAMP, processador = p_processador,
               erro = NULL, atualizado_em = CURRENT_TIMESTAMP
         WHERE codjob = v_job AND status = 'PENDENTE';
        SELECT * FROM green_ocr_jobs WHERE codjob = v_job;
    ELSE
        SELECT * FROM green_ocr_jobs WHERE 1 = 0;
    END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ocr_job_concluir`
-- @@
CREATE PROCEDURE `sp_ocr_job_concluir`(IN p_codjob BIGINT, IN p_texto LONGTEXT, IN p_paginas INT, IN p_confianca DECIMAL(6,2))
BEGIN
    UPDATE green_ocr_jobs SET status = 'CONCLUIDA', concluida_em = CURRENT_TIMESTAMP, texto = p_texto, paginas = p_paginas, confianca = p_confianca,
           erro = NULL, atualizado_em = CURRENT_TIMESTAMP
     WHERE codjob = p_codjob AND status = 'PROCESSANDO';
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ocr_job_falhar`
-- @@
CREATE PROCEDURE `sp_ocr_job_falhar`(IN p_codjob BIGINT, IN p_erro VARCHAR(2000))
BEGIN
    -- Até 3 tentativas: volta para PENDENTE daqui a 1 minuto; depois, ERRO
    UPDATE green_ocr_jobs SET status = IF(tentativas < 3, 'PENDENTE', 'ERRO'),
           disponivel_em = IF(tentativas < 3, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE), disponivel_em),
           concluida_em = IF(tentativas < 3, NULL, CURRENT_TIMESTAMP), erro = p_erro, atualizado_em = CURRENT_TIMESTAMP
     WHERE codjob = p_codjob AND status = 'PROCESSANDO';
END
-- @@
DROP PROCEDURE IF EXISTS `sp_fo_documento_ia_descartar`
-- @@
CREATE PROCEDURE `sp_fo_documento_ia_descartar`(IN p_codanalise BIGINT, IN p_codusuario BIGINT)
BEGIN
    UPDATE fo_funcionarios_documentos_ia SET status = 'DESCARTADA', codusuario_confirmacao = NULLIF(p_codusuario, 0), confirmado_em = NOW()
     WHERE codanalise = p_codanalise;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_fo_documento_ia_confirmar`
-- @@
CREATE PROCEDURE `sp_fo_documento_ia_confirmar`(IN p_codanalise BIGINT, IN p_tipo VARCHAR(20), IN p_dados LONGTEXT, IN p_destino_tabela VARCHAR(64),
    IN p_destino_codigo BIGINT, IN p_codusuario BIGINT)
BEGIN
    UPDATE fo_funcionarios_documentos_ia SET status = 'CONFIRMADA', tipo_documento = p_tipo, dados_confirmados = p_dados, destino_tabela = p_destino_tabela,
           destino_codigo = p_destino_codigo, codusuario_confirmacao = NULLIF(p_codusuario, 0), confirmado_em = NOW()
     WHERE codanalise = p_codanalise;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_fo_sinc_protecao_registrar`
-- @@
CREATE PROCEDURE `sp_fo_sinc_protecao_registrar`(IN p_tabela VARCHAR(64), IN p_chave VARCHAR(100), IN p_coluna VARCHAR(64), IN p_referencia BIGINT,
    IN p_codusuario BIGINT)
BEGIN
    -- O sincronismo legado -> novo não sobrescreve os campos confirmados a partir de documento analisado pela IA
    INSERT INTO fo_sinc_protecoes (tabela, chave, coluna, origem, referencia, codusuario)
    VALUES (p_tabela, p_chave, LOWER(p_coluna), 'IA_DOCUMENTO', p_referencia, NULLIF(p_codusuario, 0))
    ON DUPLICATE KEY UPDATE referencia = VALUES(referencia), codusuario = VALUES(codusuario), criado_em = CURRENT_TIMESTAMP;
END
