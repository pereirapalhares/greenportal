-- Migração 293 — Homologação de Fornecedores, etapa 2: aba Documentos do fornecedor completa.
-- Legado: configuracoes/fo_auditoriacriterios.php + cadastros/fo_auditoriacriterios_s.php (+ _copiar_colar_s, _historico).
-- Aplicado por scripts/aplicar-migracao-293-documentos-fornecedor.php em cada banco de cliente. Comandos separados por "-- @@".
--
-- Regras (iguais ao legado, com as correções combinadas):
--  * Documentos = um registro por fornecedor x tipo do fornecedor x critério do tipo (fo_fornecedorxcriterios).
--    Gerados para TODOS os tipos do fornecedor (o legado gerava só do primeiro) por procedure e por gatilhos
--    (novo tipo no fornecedor, novo critério no tipo) — nunca ao abrir a tela.
--  * Critério retirado do tipo ou tipo retirado do fornecedor: o documento e o histórico são preservados (o legado
--    apagava), só deixam de aparecer na aba. Voltando o vínculo, o documento reaparece com os dados.
--  * Situações: Aprovado, Aprovado sem validade, Ciente da regra, Reprovado, Pendente com a empresa,
--    Pendente com o fornecedor, Não enviar ao fornecedor, NA (Não se aplica). Reprovado exige observação da empresa.
--  * Em protocolo: S (Sim), N (Não), R (Renovar). R grava S e a cobrança passa a hoje + 30 dias; S sem cobrança grava
--    hoje + 30 dias. Não / vazio limpa a cobrança.
--  * Cada gravação deixa uma cópia completa em fo_fornecedorxcriterios_historico (quem e quando).
--  * Cor: NEUTRO (NA / Não enviar), VERMELHO (Reprovado, vencido, Pendente com o fornecedor), AMARELO (Pendente com a
--    empresa ou vence dentro dos dias de reação — 30 se não informado), VERDE (Aprovado, Aprovado sem validade, Ciente
--    da regra), BRANCO (sem situação).

-- @@
-- Usuários que respondem pelo fornecedor (Fornecedor x Usuários): lista do "Responsável do fornecedor".
CREATE OR REPLACE VIEW `vw_homologacao_fornecedor_usuarios` AS
SELECT fu.CODFORNECEDOR AS CODFORNECEDOR, u.codusuario AS CODUSUARIO, u.nome AS NOME, u.ativo AS ATIVO
  FROM `fo_fornecedorxusuario` fu
  JOIN `usuarios` u ON u.codusuario = fu.CODUSUARIO

-- @@
-- Documentos do fornecedor com descrição, nível, responsáveis, dias até o vencimento e cor.
CREATE OR REPLACE VIEW `vw_homologacao_fornecedor_documentos` AS
SELECT d.*,
       CASE
         WHEN d.SITUACAO IN ('NA', 'Não enviar ao fornecedor') THEN 'NEUTRO'
         WHEN d.SITUACAO = 'Reprovado' THEN 'VERMELHO'
         WHEN d.QTDDIAS IS NOT NULL AND d.QTDDIAS < 0 THEN 'VERMELHO'
         WHEN d.SITUACAO = 'Pendente com o fornecedor' THEN 'VERMELHO'
         WHEN d.SITUACAO = 'Pendente com a empresa' THEN 'AMARELO'
         WHEN d.QTDDIAS IS NOT NULL AND d.QTDDIAS <= COALESCE(NULLIF(d.DATADEREACAO, 0), 30) THEN 'AMARELO'
         WHEN d.SITUACAO IN ('Aprovado', 'Aprovado sem validade', 'Ciente da regra') THEN 'VERDE'
         ELSE 'BRANCO'
       END AS COR
  FROM (
    SELECT x.codfornecedorxcriterios AS CODFORNECEDORXCRITERIOS, x.codfornecedor AS CODFORNECEDOR,
           x.codtipo AS CODTIPO, t.DSCTIPO AS DSCTIPO, x.codcriterio AS CODCRITERIO, c.DSCRITERIO AS DSCRITERIO,
           c.DSCRITERIO_EN AS DSCRITERIO_EN, COALESCE(tc.nivel_de_exigencia, '') AS NIVEL_DE_EXIGENCIA,
           COALESCE(x.enviadoodocumento, '') AS ENVIADOODOCUMENTO,
           x.codusuario AS CODUSUARIO, u1.nome AS RESPONSAVEL,
           x.codusuario_fornecedor AS CODUSUARIO_FORNECEDOR, u2.nome AS RESPONSAVEL_FORNECEDOR,
           IF(x.datadevalidade IS NULL OR x.datadevalidade < '1900-01-01', NULL, x.datadevalidade) AS DATADEVALIDADE,
           IF(x.datadevalidade IS NULL OR x.datadevalidade < '1900-01-01', NULL, DATEDIFF(x.datadevalidade, CURDATE())) AS QTDDIAS,
           COALESCE(x.emprotocolo, '') AS EMPROTOCOLO, x.datacobranca_emprotocolo AS DATACOBRANCA_EMPROTOCOLO,
           x.datadereacao AS DATADEREACAO, COALESCE(x.situacao, '') AS SITUACAO,
           COALESCE(x.incluidocontrato, '') AS INCLUIDOCONTRATO,
           x.observacao AS OBSERVACAO, x.observacao_do_fornecedor AS OBSERVACAO_DO_FORNECEDOR,
           f.RAZAOSOCIAL AS RAZAOSOCIAL, f.CODUNIDADE AS CODUNIDADE, f.ATIVADOFOR AS ATIVADOFOR
      FROM `fo_fornecedorxcriterios` x
      JOIN `fo_fornecedores` f ON f.codfornecedor = x.codfornecedor
      JOIN `fo_tipofornecedores` t ON t.codtipofornecedor = x.codtipo
      JOIN `fo_criteriofornecedores` c ON c.codcriteriofornecedor = x.codcriterio
      JOIN `fo_tipofornecedoresxcriteriofornecedores` tc ON tc.codtipofornecedor = x.codtipo AND tc.codcriteriofornecedor = x.codcriterio
      LEFT JOIN `usuarios` u1 ON u1.codusuario = x.codusuario
      LEFT JOIN `usuarios` u2 ON u2.codusuario = x.codusuario_fornecedor
     WHERE EXISTS (SELECT 1 FROM `fo_fornecedoresxtipofornecedores` ft WHERE ft.codfornecedor = x.codfornecedor AND ft.codtipofornecedor = x.codtipo)
  ) d

-- @@
-- Histórico dos documentos (também a tela Histórico de alterações de documentos, migração 295). A mais recente primeiro:
-- sp_crud_select não ordena, então a ordem da view vale para as páginas do grid.
CREATE OR REPLACE VIEW `vw_homologacao_fornecedor_documentos_historico` AS
SELECT h.CODHISTORICO, h.codfornecedorxcriterios AS CODFORNECEDORXCRITERIOS, h.codfornecedor AS CODFORNECEDOR,
       f.RAZAOSOCIAL AS RAZAOSOCIAL, f.CODUNIDADE AS CODUNIDADE, un.DSCUNIDADE AS DSCUNIDADE,
       h.DATAALTERACAO, h.CODUSUARIOALTERACAO, COALESCE(ua.nome, IF(h.CODUSUARIOALTERACAO = 0, 'Migração', NULL)) AS ALTERADO_POR,
       t.DSCTIPO, c.DSCRITERIO, h.enviadoodocumento AS ENVIADOODOCUMENTO,
       IF(h.datadevalidade IS NULL OR h.datadevalidade < '1900-01-01', NULL, h.datadevalidade) AS DATADEVALIDADE,
       h.datadereacao AS DATADEREACAO, h.situacao AS SITUACAO, h.incluidocontrato AS INCLUIDOCONTRATO,
       u1.nome AS RESPONSAVEL, u2.nome AS RESPONSAVEL_FORNECEDOR, h.observacao AS OBSERVACAO,
       h.observacao_do_fornecedor AS OBSERVACAO_DO_FORNECEDOR, h.emprotocolo AS EMPROTOCOLO,
       h.datacobranca_emprotocolo AS DATACOBRANCA_EMPROTOCOLO
  FROM `fo_fornecedorxcriterios_historico` h
  LEFT JOIN `usuarios` ua ON ua.codusuario = h.CODUSUARIOALTERACAO
  LEFT JOIN `usuarios` u1 ON u1.codusuario = h.codusuario
  LEFT JOIN `usuarios` u2 ON u2.codusuario = h.codusuario_fornecedor
  LEFT JOIN `fo_tipofornecedores` t ON t.codtipofornecedor = h.codtipo
  LEFT JOIN `fo_criteriofornecedores` c ON c.codcriteriofornecedor = h.codcriterio
  LEFT JOIN `fo_fornecedores` f ON f.codfornecedor = h.codfornecedor
  LEFT JOIN `unidades` un ON un.CODUNIDADE = f.CODUNIDADE
 ORDER BY h.DATAALTERACAO DESC, h.CODHISTORICO DESC

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_fornecedor_documentos_gerar`

-- @@
-- Cria os documentos que faltam (fornecedor x tipo x critério do tipo). p_codfornecedor NULL ou 0 = todos.
CREATE PROCEDURE `sp_fo_fornecedor_documentos_gerar`(IN p_codfornecedor BIGINT)
SQL SECURITY INVOKER
BEGIN
    INSERT INTO `fo_fornecedorxcriterios` (codfornecedor, codtipo, codcriterio)
    SELECT DISTINCT ft.codfornecedor, ft.codtipofornecedor, tc.codcriteriofornecedor
      FROM `fo_fornecedoresxtipofornecedores` ft
      JOIN `fo_tipofornecedoresxcriteriofornecedores` tc ON tc.codtipofornecedor = ft.codtipofornecedor
      JOIN `fo_fornecedores` f ON f.codfornecedor = ft.codfornecedor
     WHERE (p_codfornecedor IS NULL OR p_codfornecedor = 0 OR ft.codfornecedor = p_codfornecedor)
       AND NOT EXISTS (SELECT 1 FROM `fo_fornecedorxcriterios` x
                        WHERE x.codfornecedor = ft.codfornecedor AND x.codtipo = ft.codtipofornecedor AND x.codcriterio = tc.codcriteriofornecedor);
    SELECT ROW_COUNT() AS gerados;
END

-- @@
DROP TRIGGER IF EXISTS `trg_fo_fornecedoresxtipo_docs_ai`

-- @@
-- Novo tipo no fornecedor: cria os documentos dos critérios do tipo.
CREATE TRIGGER `trg_fo_fornecedoresxtipo_docs_ai` AFTER INSERT ON `fo_fornecedoresxtipofornecedores`
FOR EACH ROW
INSERT INTO `fo_fornecedorxcriterios` (codfornecedor, codtipo, codcriterio)
SELECT NEW.codfornecedor, NEW.codtipofornecedor, tc.codcriteriofornecedor
  FROM `fo_tipofornecedoresxcriteriofornecedores` tc
 WHERE tc.codtipofornecedor = NEW.codtipofornecedor
   AND NOT EXISTS (SELECT 1 FROM `fo_fornecedorxcriterios` x WHERE x.codfornecedor = NEW.codfornecedor AND x.codtipo = NEW.codtipofornecedor AND x.codcriterio = tc.codcriteriofornecedor)
   AND @gp_sinc_legado IS NULL  -- sincronismo com o legado: o documento vem pronto do legado

-- @@
DROP TRIGGER IF EXISTS `trg_fo_tipoxcriterio_docs_ai`

-- @@
-- Novo critério no tipo: cria o documento em todos os fornecedores do tipo.
CREATE TRIGGER `trg_fo_tipoxcriterio_docs_ai` AFTER INSERT ON `fo_tipofornecedoresxcriteriofornecedores`
FOR EACH ROW
INSERT INTO `fo_fornecedorxcriterios` (codfornecedor, codtipo, codcriterio)
SELECT DISTINCT ft.codfornecedor, NEW.codtipofornecedor, NEW.codcriteriofornecedor
  FROM `fo_fornecedoresxtipofornecedores` ft
 WHERE ft.codtipofornecedor = NEW.codtipofornecedor
   AND EXISTS (SELECT 1 FROM `fo_fornecedores` f WHERE f.codfornecedor = ft.codfornecedor)
   AND NOT EXISTS (SELECT 1 FROM `fo_fornecedorxcriterios` x WHERE x.codfornecedor = ft.codfornecedor AND x.codtipo = NEW.codtipofornecedor AND x.codcriterio = NEW.codcriteriofornecedor)
   AND @gp_sinc_legado IS NULL  -- sincronismo com o legado: o documento vem pronto do legado

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_fornecedor_documento_historico_gravar`

-- @@
-- Cópia completa do documento no histórico (quem e quando).
CREATE PROCEDURE `sp_fo_fornecedor_documento_historico_gravar`(IN p_cod BIGINT, IN p_usuario BIGINT)
SQL SECURITY INVOKER
BEGIN
    INSERT INTO `fo_fornecedorxcriterios_historico` (CODUSUARIOALTERACAO, DATAALTERACAO, codfornecedorxcriterios, codfornecedor, codtipo, codcriterio,
        enviadoodocumento, datadevalidade, datadereacao, situacao, incluidocontrato, codusuario, codusuario_fornecedor, observacao,
        observacao_do_fornecedor, emprotocolo, datacobranca_emprotocolo)
    SELECT p_usuario, NOW(), codfornecedorxcriterios, codfornecedor, codtipo, codcriterio, enviadoodocumento, datadevalidade, datadereacao,
           situacao, incluidocontrato, codusuario, codusuario_fornecedor, observacao, observacao_do_fornecedor, emprotocolo, datacobranca_emprotocolo
      FROM `fo_fornecedorxcriterios` WHERE codfornecedorxcriterios = p_cod;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_fornecedor_documento_salvar`

-- @@
-- Grava um documento. p_dados JSON com os campos (ausente = não altera; "" = limpa). p_escopo: EMPRESA (todos os campos)
-- ou FORNECEDOR (só validade, protocolo, dias de reação e observação do fornecedor, como no legado).
-- Campos: ENVIADOODOCUMENTO, CODUSUARIO, CODUSUARIO_FORNECEDOR, DATADEVALIDADE (AAAA-MM-DD), EMPROTOCOLO (S/N/R),
-- DATADEREACAO, SITUACAO, INCLUIDOCONTRATO, OBSERVACAO, OBSERVACAO_DO_FORNECEDOR.
CREATE PROCEDURE `sp_fo_fornecedor_documento_salvar`(IN p_cod BIGINT, IN p_dados JSON, IN p_usuario BIGINT, IN p_escopo VARCHAR(20))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_forn BIGINT;
    DECLARE v_env VARCHAR(3); DECLARE v_resp BIGINT; DECLARE v_respf BIGINT; DECLARE v_val DATE; DECLARE v_prot VARCHAR(1);
    DECLARE v_reac INT; DECLARE v_sit VARCHAR(100); DECLARE v_contr VARCHAR(3); DECLARE v_obs VARCHAR(500); DECLARE v_obsf VARCHAR(500);
    DECLARE v_cobr DATE; DECLARE v_txt TEXT;
    DECLARE v_emp TINYINT DEFAULT UPPER(COALESCE(p_escopo, 'EMPRESA')) <> 'FORNECEDOR';
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    SELECT codfornecedor, enviadoodocumento, codusuario, codusuario_fornecedor,
           IF(datadevalidade < '1900-01-01', NULL, datadevalidade), emprotocolo, datadereacao, situacao, incluidocontrato, observacao,
           observacao_do_fornecedor, datacobranca_emprotocolo
      INTO v_forn, v_env, v_resp, v_respf, v_val, v_prot, v_reac, v_sit, v_contr, v_obs, v_obsf, v_cobr
      FROM `fo_fornecedorxcriterios` WHERE codfornecedorxcriterios = p_cod;
    IF v_forn IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Documento não encontrado.'; END IF;
    IF p_dados IS NULL OR JSON_TYPE(p_dados) <> 'OBJECT' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dados inválidos.'; END IF;

    -- Campos do fornecedor (os dois perfis)
    IF JSON_CONTAINS_PATH(p_dados, 'one', '$.DATADEVALIDADE') THEN
        SET v_txt = TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.DATADEVALIDADE')), ''));
        IF v_txt IN ('', 'null') THEN SET v_val = NULL;
        ELSEIF v_txt REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' AND STR_TO_DATE(v_txt, '%Y-%m-%d') IS NOT NULL THEN SET v_val = STR_TO_DATE(v_txt, '%Y-%m-%d');
        ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data de validade inválida.'; END IF;
    END IF;
    IF JSON_CONTAINS_PATH(p_dados, 'one', '$.DATADEREACAO') THEN
        SET v_txt = TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.DATADEREACAO')), ''));
        IF v_txt IN ('', 'null') THEN SET v_reac = NULL;
        ELSEIF v_txt REGEXP '^[0-9]{1,4}$' THEN SET v_reac = CAST(v_txt AS UNSIGNED);
        ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dias de reação inválidos (use um número de 0 a 9999).'; END IF;
    END IF;
    IF JSON_CONTAINS_PATH(p_dados, 'one', '$.EMPROTOCOLO') THEN
        SET v_txt = UPPER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.EMPROTOCOLO')), '')));
        IF v_txt NOT IN ('', 'S', 'N', 'R') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Em protocolo: use Sim, Não ou Renovar.'; END IF;
        IF v_txt = 'R' THEN SET v_prot = 'S', v_cobr = DATE_ADD(CURDATE(), INTERVAL 30 DAY);
        ELSEIF v_txt = 'S' THEN SET v_prot = 'S', v_cobr = COALESCE(v_cobr, DATE_ADD(CURDATE(), INTERVAL 30 DAY));
        ELSE SET v_prot = NULLIF(v_txt, ''), v_cobr = NULL; END IF;
    END IF;
    IF JSON_CONTAINS_PATH(p_dados, 'one', '$.OBSERVACAO_DO_FORNECEDOR') THEN
        SET v_obsf = NULLIF(LEFT(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.OBSERVACAO_DO_FORNECEDOR')), '')), 500), '');
    END IF;

    -- Campos da empresa
    IF v_emp THEN
        IF JSON_CONTAINS_PATH(p_dados, 'one', '$.ENVIADOODOCUMENTO') THEN
            SET v_env = NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.ENVIADOODOCUMENTO')), '')), '');
            IF v_env IS NOT NULL AND v_env NOT IN ('Sim', 'Não') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Enviado o documento: use Sim ou Não.'; END IF;
        END IF;
        IF JSON_CONTAINS_PATH(p_dados, 'one', '$.INCLUIDOCONTRATO') THEN
            SET v_contr = NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.INCLUIDOCONTRATO')), '')), '');
            IF v_contr IS NOT NULL AND v_contr NOT IN ('Sim', 'Não') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Incluído no contrato: use Sim ou Não.'; END IF;
        END IF;
        IF JSON_CONTAINS_PATH(p_dados, 'one', '$.SITUACAO') THEN
            SET v_sit = NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.SITUACAO')), '')), '');
            IF v_sit IS NOT NULL AND v_sit NOT IN ('Aprovado', 'Aprovado sem validade', 'Ciente da regra', 'Reprovado', 'Pendente com a empresa',
                'Pendente com o fornecedor', 'Não enviar ao fornecedor', 'NA') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Situação inválida.';
            END IF;
        END IF;
        IF JSON_CONTAINS_PATH(p_dados, 'one', '$.OBSERVACAO') THEN
            SET v_obs = NULLIF(LEFT(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.OBSERVACAO')), '')), 500), '');
        END IF;
        IF JSON_CONTAINS_PATH(p_dados, 'one', '$.CODUSUARIO') THEN
            SET v_txt = TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.CODUSUARIO')), ''));
            SET v_resp = IF(v_txt REGEXP '^[0-9]+$' AND v_txt <> '0', CAST(v_txt AS UNSIGNED), NULL);
            IF v_resp IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `usuarios` WHERE codusuario = v_resp) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Responsável da empresa não encontrado.';
            END IF;
        END IF;
        IF JSON_CONTAINS_PATH(p_dados, 'one', '$.CODUSUARIO_FORNECEDOR') THEN
            SET v_txt = TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.CODUSUARIO_FORNECEDOR')), ''));
            SET v_respf = IF(v_txt REGEXP '^[0-9]+$' AND v_txt <> '0', CAST(v_txt AS UNSIGNED), NULL);
            IF v_respf IS NOT NULL AND v_respf <> 99999 AND NOT EXISTS (SELECT 1 FROM `fo_fornecedorxusuario` WHERE CODFORNECEDOR = v_forn AND CODUSUARIO = v_respf) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O responsável do fornecedor precisa estar ligado ao fornecedor (Fornecedor x Usuários).';
            END IF;
        END IF;
        IF v_sit = 'Reprovado' AND TRIM(COALESCE(v_obs, '')) = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe na observação da empresa o motivo da reprovação do documento.';
        END IF;
    END IF;

    START TRANSACTION;
    UPDATE `fo_fornecedorxcriterios`
       SET enviadoodocumento = v_env, codusuario = v_resp, codusuario_fornecedor = v_respf, datadevalidade = v_val, emprotocolo = v_prot,
           datacobranca_emprotocolo = v_cobr, datadereacao = v_reac, situacao = v_sit, incluidocontrato = v_contr, observacao = v_obs,
           observacao_do_fornecedor = v_obsf
     WHERE codfornecedorxcriterios = p_cod;
    CALL sp_fo_fornecedor_documento_historico_gravar(p_cod, p_usuario);
    COMMIT;
    SELECT * FROM `vw_homologacao_fornecedor_documentos` WHERE CODFORNECEDORXCRITERIOS = p_cod;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_fornecedor_documento_copiar`

-- @@
-- Copia os dados de um documento para outros do mesmo fornecedor, só nos campos VAZIOS do destino (como o legado).
-- p_destinos: lista JSON de códigos. p_escopo FORNECEDOR copia só os campos do fornecedor. Retorna os códigos alterados.
CREATE PROCEDURE `sp_fo_fornecedor_documento_copiar`(IN p_origem BIGINT, IN p_destinos JSON, IN p_usuario BIGINT, IN p_escopo VARCHAR(20))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_forn BIGINT;
    DECLARE v_emp TINYINT DEFAULT UPPER(COALESCE(p_escopo, 'EMPRESA')) <> 'FORNECEDOR';
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    SELECT codfornecedor INTO v_forn FROM `fo_fornecedorxcriterios` WHERE codfornecedorxcriterios = p_origem;
    IF v_forn IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Documento de origem não encontrado.'; END IF;
    IF p_destinos IS NULL OR JSON_TYPE(p_destinos) <> 'ARRAY' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lista de documentos inválida.'; END IF;
    IF EXISTS (SELECT 1 FROM JSON_TABLE(p_destinos, '$[*]' COLUMNS (codigo BIGINT PATH '$')) j
                LEFT JOIN `fo_fornecedorxcriterios` x ON x.codfornecedorxcriterios = j.codigo
               WHERE x.codfornecedorxcriterios IS NULL OR x.codfornecedor <> v_forn) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Só é possível colar em documentos do mesmo fornecedor.';
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_fo_doc_destinos;
    CREATE TEMPORARY TABLE tmp_fo_doc_destinos (codigo BIGINT PRIMARY KEY);
    INSERT IGNORE INTO tmp_fo_doc_destinos SELECT j.codigo FROM JSON_TABLE(p_destinos, '$[*]' COLUMNS (codigo BIGINT PATH '$')) j WHERE j.codigo <> p_origem;

    START TRANSACTION;
    UPDATE `fo_fornecedorxcriterios` d
      JOIN tmp_fo_doc_destinos t ON t.codigo = d.codfornecedorxcriterios
      JOIN `fo_fornecedorxcriterios` o ON o.codfornecedorxcriterios = p_origem
       SET d.datadevalidade = IF(d.datadevalidade IS NULL OR d.datadevalidade < '1900-01-01', o.datadevalidade, d.datadevalidade),
           d.datadereacao = COALESCE(d.datadereacao, o.datadereacao),
           d.emprotocolo = COALESCE(NULLIF(d.emprotocolo, ''), o.emprotocolo),
           d.datacobranca_emprotocolo = COALESCE(d.datacobranca_emprotocolo, o.datacobranca_emprotocolo),
           d.observacao_do_fornecedor = COALESCE(NULLIF(d.observacao_do_fornecedor, ''), o.observacao_do_fornecedor),
           d.enviadoodocumento = IF(v_emp, COALESCE(NULLIF(d.enviadoodocumento, ''), o.enviadoodocumento), d.enviadoodocumento),
           d.codusuario = IF(v_emp, COALESCE(NULLIF(d.codusuario, 0), o.codusuario), d.codusuario),
           d.codusuario_fornecedor = IF(v_emp, COALESCE(NULLIF(d.codusuario_fornecedor, 0), o.codusuario_fornecedor), d.codusuario_fornecedor),
           d.situacao = IF(v_emp, COALESCE(NULLIF(d.situacao, ''), o.situacao), d.situacao),
           d.incluidocontrato = IF(v_emp, COALESCE(NULLIF(d.incluidocontrato, ''), o.incluidocontrato), d.incluidocontrato),
           d.observacao = IF(v_emp, COALESCE(NULLIF(d.observacao, ''), o.observacao), d.observacao);
    INSERT INTO `fo_fornecedorxcriterios_historico` (CODUSUARIOALTERACAO, DATAALTERACAO, codfornecedorxcriterios, codfornecedor, codtipo, codcriterio,
        enviadoodocumento, datadevalidade, datadereacao, situacao, incluidocontrato, codusuario, codusuario_fornecedor, observacao,
        observacao_do_fornecedor, emprotocolo, datacobranca_emprotocolo)
    SELECT p_usuario, NOW(), x.codfornecedorxcriterios, x.codfornecedor, x.codtipo, x.codcriterio, x.enviadoodocumento, x.datadevalidade, x.datadereacao,
           x.situacao, x.incluidocontrato, x.codusuario, x.codusuario_fornecedor, x.observacao, x.observacao_do_fornecedor, x.emprotocolo, x.datacobranca_emprotocolo
      FROM `fo_fornecedorxcriterios` x JOIN tmp_fo_doc_destinos t ON t.codigo = x.codfornecedorxcriterios;
    COMMIT;
    SELECT codigo FROM tmp_fo_doc_destinos ORDER BY codigo;
    DROP TEMPORARY TABLE IF EXISTS tmp_fo_doc_destinos;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_fornecedor_documento_reiniciar`

-- @@
-- "Excluir" do legado: limpa os dados do documento (fica em branco, como recém-gerado). O histórico guarda o estado anterior.
CREATE PROCEDURE `sp_fo_fornecedor_documento_reiniciar`(IN p_cod BIGINT, IN p_usuario BIGINT)
SQL SECURITY INVOKER
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF NOT EXISTS (SELECT 1 FROM `fo_fornecedorxcriterios` WHERE codfornecedorxcriterios = p_cod) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Documento não encontrado.';
    END IF;
    START TRANSACTION;
    CALL sp_fo_fornecedor_documento_historico_gravar(p_cod, p_usuario);
    UPDATE `fo_fornecedorxcriterios`
       SET enviadoodocumento = NULL, codusuario = NULL, codusuario_fornecedor = NULL, datadevalidade = NULL, emprotocolo = NULL,
           datacobranca_emprotocolo = NULL, datadereacao = NULL, situacao = NULL, incluidocontrato = NULL, observacao = NULL,
           observacao_do_fornecedor = NULL
     WHERE codfornecedorxcriterios = p_cod;
    CALL sp_fo_fornecedor_documento_historico_gravar(p_cod, p_usuario);
    COMMIT;
    SELECT * FROM `vw_homologacao_fornecedor_documentos` WHERE CODFORNECEDORXCRITERIOS = p_cod;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_fornecedor_tipos_gravar`

-- @@
-- Tipos do fornecedor (antiga aba Tipos / correlacionamento fo_fornecedoresxtipofornecedores): vincula e desvincula.
-- Ao vincular, o gatilho cria os documentos; ao desvincular, os documentos ficam guardados (deixam de aparecer).
-- Desvincular apaga o registro do tipo no fornecedor, inclusive os dados da auditoria de homologação desse tipo.
CREATE PROCEDURE `sp_fo_fornecedor_tipos_gravar`(IN p_codfornecedor BIGINT, IN p_incluir JSON, IN p_remover JSON)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_incluidos INT DEFAULT 0;
    DECLARE v_removidos INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF NOT EXISTS (SELECT 1 FROM `fo_fornecedores` WHERE codfornecedor = p_codfornecedor) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fornecedor não encontrado.';
    END IF;
    IF EXISTS (SELECT 1 FROM JSON_TABLE(COALESCE(p_incluir, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j
               WHERE NOT EXISTS (SELECT 1 FROM `fo_tipofornecedores` t WHERE t.codtipofornecedor = j.codigo)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Há tipos que não existem mais no cadastro. Atualize a tela e tente novamente.';
    END IF;
    START TRANSACTION;
    INSERT INTO `fo_fornecedoresxtipofornecedores` (codfornecedor, codtipofornecedor)
    SELECT DISTINCT p_codfornecedor, j.codigo FROM JSON_TABLE(COALESCE(p_incluir, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j
     WHERE NOT EXISTS (SELECT 1 FROM `fo_fornecedoresxtipofornecedores` v WHERE v.codfornecedor = p_codfornecedor AND v.codtipofornecedor = j.codigo);
    SET v_incluidos = ROW_COUNT();
    DELETE v FROM `fo_fornecedoresxtipofornecedores` v
      JOIN JSON_TABLE(COALESCE(p_remover, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j ON j.codigo = v.codtipofornecedor
     WHERE v.codfornecedor = p_codfornecedor;
    SET v_removidos = ROW_COUNT();
    COMMIT;
    SELECT v_incluidos AS incluidos, v_removidos AS removidos;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_fornecedor_tipos_listar`

-- @@
-- Tipos de fornecedor com a indicação de vinculado ao fornecedor e a quantidade de critérios do tipo.
CREATE PROCEDURE `sp_fo_fornecedor_tipos_listar`(IN p_codfornecedor BIGINT)
SQL SECURITY INVOKER
BEGIN
    IF NOT EXISTS (SELECT 1 FROM `fo_fornecedores` WHERE codfornecedor = p_codfornecedor) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fornecedor não encontrado.';
    END IF;
    SELECT codfornecedor AS CODFORNECEDOR, RAZAOSOCIAL, NOMEFANTASIA FROM `fo_fornecedores` WHERE codfornecedor = p_codfornecedor;
    SELECT t.codtipofornecedor AS codigo,
           CONVERT(CONCAT(t.DSCTIPO, IF(t.necessita_auditoria = 'Sim', ' (auditoria)', '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS descricao,
           EXISTS (SELECT 1 FROM `fo_fornecedoresxtipofornecedores` v WHERE v.codfornecedor = p_codfornecedor AND v.codtipofornecedor = t.codtipofornecedor) AS vinculado,
           (SELECT COUNT(*) FROM `fo_tipofornecedoresxcriteriofornecedores` tc WHERE tc.codtipofornecedor = t.codtipofornecedor) AS criterios
      FROM `fo_tipofornecedores` t
     WHERE TRIM(COALESCE(t.DSCTIPO, '')) <> ''
     ORDER BY t.DSCTIPO, t.codtipofornecedor;
END
