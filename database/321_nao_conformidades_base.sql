-- Migração 321 — Não Conformidades: base do fluxo (etapa 1 da reconstrução, 20/10/2026).
-- Regra do projeto: as telas leem por views e gravam por procedures. Regras do legado (formulario/nc_s.php):
--   Em criação / Recusado pelo Gestor (emissor) → Com o Gestor
--   Com o Gestor (gestor): enviar ao responsável (exige responsável, ação imediata e abrangência) → Em Analise da Causa Raiz;
--     recusar (exige motivo) → Recusado pelo Gestor; improcedente → Finalizado
--   Em Analise da Causa Raiz / Plano de Ação / Recusado pela Avaliacao da Eficacia (responsável): causas e plano de ação;
--     enviar (≥1 causa, ≥1 plano por causa, getStatusPlanoAcaoNC ≠ 'Não') → Avaliação da Eficacia
--   Avaliação da Eficacia (gestor): EFICAZ / ENCERRADO → Finalizado; NÃO EFICAZ ou recusa → Recusado pela Avaliacao da Eficacia
-- Histórico em nc_workflow_historico gravado só pelas procedures (o gatilho tr_nc_indicacao_ocorrencia_workflow_audit sai:
-- duplicava os registros). p_admin = 1 para ADMINISTRADOR / ADM_DA_EMPRESA (decidido na tela).
-- Alertas (21/10/2026): cada passagem de etapa avisa os envolvidos em Home › Alertas (sp_nc_alertar, chamada por
-- sp_nc_historico_registrar), com os mesmos destinatários dos e-mails do legado. Quem está na lista de distribuição passa a
-- ver a ocorrência (vw_nc_envolvidos), como no legado, que mandava a ocorrência inteira no e-mail.
-- Fornecedor (21/10/2026, etapa 3 — "325"): listas do tipo Fornecedor (classificação do fornecedor, fornecedores ativos, listas de
-- distribuição da categoria Fornecedores) em vw_nc_opcoes; fornecedor obrigatório no envio; avisos "Sr. Fornecedor, …" para usuários de
-- fornecedor; e, como no legado, incluir causa ou gravar ação passa a ocorrência para "Plano de Ação" (sp_nc_etapa_plano).
-- Planejamento (21/10/2026, etapa 3 — "326", decisões do usuário): enviar leva direto ao Plano de Ação (sem causas); o gestor monta
-- as ações e o responsável de cada ação atualiza status/observação; com todas a 100% avança sozinha (eficácia ou finalizada "Sem
-- verificação da eficácia", conforme pass_pela_nanalise_eficacia); não eficaz volta ao Plano de Ação.
-- Assistência Técnica (22/10/2026, etapa 3 — "328", decisões do usuário): o emitente (pode ser fornecedor externo) monta as ações Em criação e
-- envia direto ao Plano de Ação (≥1 ação, todas com descrição e responsável; avisa cada responsável); no Plano de Ação emitente e gestor
-- incluem/alteram ações e o responsável de cada ação atualiza status; com todas a 100% avança como no Planejamento; não eficaz volta ao Plano.
-- Acidente/Incidente (22/10/2026, "331", decisão do usuário): tipo 4 com o fluxo da Empresa (sem uso no legado da Crown: 0 ocorrências).
-- Etapa com acentuação quebrada pelo legado (ex.: "Plano de AÃ§Ã£o", 7 NCs abertas na Crown): corrigida só na leitura (fn_nc_etapa, nas views
-- e nos avisos); o valor gravado no legado não é alterado.
-- Blocos separados por "-- @@" (scripts/aplicar-migracao-321-nao-conformidades-base.php).

-- @@
DROP TRIGGER IF EXISTS `tr_nc_indicacao_ocorrencia_workflow_audit`
-- @@
DROP FUNCTION IF EXISTS `fn_nc_txt`
-- @@
CREATE FUNCTION `fn_nc_txt`(p JSON, k VARCHAR(80)) RETURNS TEXT DETERMINISTIC
RETURN NULLIF(TRIM(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p, CONCAT('$.', k))), 'null')), '')
-- @@
DROP FUNCTION IF EXISTS `fn_nc_faixa`
-- @@
-- Migração 322 (21/10/2026, decisão do usuário): o que é criado no greenportal fica numa faixa de códigos própria, que o
-- sincronismo (legado manda até a virada) não apaga nem altera — ver SincronismoLegado::FAIXAS_NOVO. Folga calculada pelo
-- diagnostico-nc-codigos.php: Crown cria ~1.444 ocorrências e ~2.757 ações por ano (máx. 19.561 / 15.546 / 45.842).
CREATE FUNCTION `fn_nc_faixa`(p VARCHAR(20)) RETURNS INT DETERMINISTIC
RETURN CASE p WHEN 'ocorrencia' THEN 50001 WHEN 'causa' THEN 50001 WHEN 'plano' THEN 500001 ELSE NULL END
-- @@
DROP FUNCTION IF EXISTS `fn_nc_tem`
-- @@
CREATE FUNCTION `fn_nc_tem`(p JSON, k VARCHAR(80)) RETURNS TINYINT DETERMINISTIC
RETURN IFNULL(JSON_CONTAINS_PATH(p, 'one', CONCAT('$.', k)), 0)
-- @@
DROP FUNCTION IF EXISTS `fn_nc_plano_ocorrencia`
-- @@
-- Ocorrência da ação. No legado (Empresa, Fornecedor, Cliente) a ação só aponta para a causa (nc_planodeacao.CODOCORRENCIA
-- fica NULL: Crown 16.466 ações); no Planejamento e no greenportal ela também aponta para a ocorrência.
CREATE FUNCTION `fn_nc_plano_ocorrencia`(p_codplano INT) RETURNS INT READS SQL DATA
RETURN (SELECT COALESCE(p.CODOCORRENCIA, c.CODOCORRENCIA) FROM nc_planodeacao p LEFT JOIN nc_causas c ON c.CODCAUSA = p.CODCAUSA WHERE p.CODPLANOACAO = p_codplano)
-- @@
DROP FUNCTION IF EXISTS `fn_nc_etapa`
-- @@
-- Etapa como deve aparecer: desfaz a acentuação quebrada (UTF-8 lido como Latin-1, ex.: "Plano de AÃ§Ã£o" → "Plano de Ação").
-- Só leitura: o valor gravado não muda. Se a conversão perder caractere ("?"), devolve o valor original.
CREATE FUNCTION `fn_nc_etapa`(p VARCHAR(500)) RETURNS VARCHAR(500) DETERMINISTIC NO SQL
RETURN IF(p IS NULL OR p NOT LIKE '%Ã%' COLLATE utf8mb4_bin, p,
  COALESCE(NULLIF(IF(LOCATE('?', CONVERT(CAST(CONVERT(p USING latin1) AS BINARY) USING utf8mb4)) > LOCATE('?', p), NULL,
    CONVERT(CAST(CONVERT(p USING latin1) AS BINARY) USING utf8mb4)), ''), p))
-- @@
-- Usuários de fornecedor (responsável da ação escolhido pelo fornecedor externo: só os do próprio fornecedor; c519)
CREATE OR REPLACE VIEW `vw_nc_usuarios_fornecedor` AS
SELECT DISTINCT fu.CODFORNECEDOR, fu.CODUSUARIO FROM fo_fornecedorxusuario fu JOIN usuarios u ON u.codusuario = fu.CODUSUARIO
-- @@
CREATE OR REPLACE VIEW `vw_nc_usuarios` AS
SELECT u.codusuario AS CODUSUARIO, u.nome AS NOME, u.ativo AS ATIVO FROM usuarios u WHERE TRIM(COALESCE(u.nome, '')) <> ''
-- @@
CREATE OR REPLACE VIEW `vw_nc_ocorrencia` AS
SELECT o.*, t.DSCTIPONAOCONFORMIDADE,
       ue.nome AS EMISSOR, ug.nome AS GESTOR, ur.nome AS RESPONSAVEL, up.nome AS RESPONSAVEL_PRAZO_ANALISE,
       un.DSCUNIDADE, ar.DSCAREA, IF(o.CODTIPONAOCONFORMIDADE = 1, cf.dscclassificacao, tpo.DSCTIPOOCORRENCIA) AS DSCTIPOOCORRENCIA, ta.DSCTIPOACAO, oa.DSCORIGEMACAO, sg.DSCSIGLAGESTAO,
       ld.DSCLISTA, st.dscsetor AS DSCSETOR, fo.RAZAOSOCIAL,
       TRIM(REPLACE(REPLACE(ucl.dscunidade, CHAR(13), ''), CHAR(10), '')) AS DSCUNIDADE_CLIENTE, TRIM(REPLACE(REPLACE(prd.dscproduto, CHAR(13), ''), CHAR(10), '')) AS DSCPRODUTO, TRIM(REPLACE(REPLACE(tdc.dsctipodedocumento, CHAR(13), ''), CHAR(10), '')) AS DSCTIPODEDOCUMENTO,
       TRIM(REPLACE(REPLACE(tmp.dsctipodetampa, CHAR(13), ''), CHAR(10), '')) AS DSCTIPODETAMPA, TRIM(REPLACE(REPLACE(lat.dsctamanhodalata, CHAR(13), ''), CHAR(10), '')) AS DSCTAMANHODALATA, TRIM(REPLACE(REPLACE(rcl.dsctiporeclamacao, CHAR(13), ''), CHAR(10), '')) AS DSCTIPORECLAMACAO
  FROM nc_indicacao_ocorrencia o
  LEFT JOIN nc_tipo_de_naoconformidade t ON t.CODTIPONAOCONFORMIDADE = o.CODTIPONAOCONFORMIDADE
  LEFT JOIN usuarios ue ON ue.codusuario = o.CODUSUARIO_EMISSOR
  LEFT JOIN usuarios ug ON ug.codusuario = o.CODUSUARIO_GESTOR
  LEFT JOIN usuarios ur ON ur.codusuario = o.CODUSUARIO_RESPONSAVEL
  LEFT JOIN usuarios up ON up.codusuario = o.CODUSUARIO_PRAZO_ANALISE
  LEFT JOIN unidades un ON un.CODUNIDADE = o.codunidade
  LEFT JOIN areas ar ON ar.CODAREA = o.CODAREA
  LEFT JOIN nc_tipoocorrencia tpo ON tpo.CODTIPOOCORRENCIA = o.CODTIPOOCORRENCIA
  LEFT JOIN nc_tipoacao ta ON ta.CODTIPOACAO = o.CODTIPOACAO
  LEFT JOIN nc_origemacao oa ON oa.CODORIGEMACAO = o.CODORIGEMACAO
  LEFT JOIN nc_siglagestao sg ON sg.CODSIGLAGESTAO = o.CODSIGLAGESTAO
  LEFT JOIN nc_listadistribuicao ld ON o.listadedistribuicao REGEXP '^[0-9]+$' AND ld.CODLISTA = o.listadedistribuicao
  LEFT JOIN nc_setores st ON st.codsetor = o.codsetor
  LEFT JOIN fo_fornecedores fo ON fo.CODFORNECEDOR = o.CODFORNECEDOR
  LEFT JOIN nc_classificacao_fornecedor cf ON o.CODTIPONAOCONFORMIDADE = 1 AND cf.codclassificacao = o.CODTIPOOCORRENCIA
  LEFT JOIN nc_unidadesdocliente ucl ON ucl.codunidade = o.codunidade_cliente
  LEFT JOIN nc_produtos prd ON o.CODIGODOPRODUTO REGEXP '^[0-9]+$' AND prd.codproduto = CAST(o.CODIGODOPRODUTO AS UNSIGNED)
  LEFT JOIN nc_tipodedocumento tdc ON tdc.codtipodedocumento = o.codtipodedocumento
  LEFT JOIN nc_tipo_de_tampa tmp ON tmp.codtipodetampa = o.codtipodetampa
  LEFT JOIN nc_tamanho_da_lata lat ON lat.codtamanhodalata = o.codtamanhodalata
  LEFT JOIN nc_tiporeclamacao rcl ON rcl.codtiporeclamacao = o.codtipodereclamacao
-- @@
CREATE OR REPLACE VIEW `vw_nc_envolvidos` AS
SELECT CODOCORRENCIA, CODUSUARIO_EMISSOR AS CODUSUARIO FROM nc_indicacao_ocorrencia WHERE CODUSUARIO_EMISSOR > 0
UNION SELECT CODOCORRENCIA, CODUSUARIO_GESTOR FROM nc_indicacao_ocorrencia WHERE CODUSUARIO_GESTOR > 0
UNION SELECT CODOCORRENCIA, CODUSUARIO_RESPONSAVEL FROM nc_indicacao_ocorrencia WHERE CODUSUARIO_RESPONSAVEL > 0
UNION SELECT CODOCORRENCIA, CODUSUARIO_PRAZO_ANALISE FROM nc_indicacao_ocorrencia WHERE CODUSUARIO_PRAZO_ANALISE > 0
UNION SELECT COALESCE(p.CODOCORRENCIA, c.CODOCORRENCIA), p.CODUSUARIO FROM nc_planodeacao p LEFT JOIN nc_causas c ON c.CODCAUSA = p.CODCAUSA WHERE p.CODUSUARIO > 0
UNION SELECT o.CODOCORRENCIA, l.CODUSUARIO FROM nc_indicacao_ocorrencia o
  JOIN nc_listadistribuicaoxusuarios l ON o.listadedistribuicao REGEXP '^[0-9]+$' AND l.CODLISTA = CAST(o.listadedistribuicao AS UNSIGNED)
 WHERE l.CODUSUARIO > 0
-- @@
CREATE OR REPLACE VIEW `vw_nc_tipo_campos` AS
SELECT DISTINCT x.CODTIPONAOCONFORMIDADE, UPPER(TRIM(c.DSCCAMPO)) AS CAMPO
  FROM nc_tipo_de_naoconformidadexcampos x JOIN green_universal.md_tabelasxcampos c ON c.codcampo = x.CODCAMPO
-- @@
-- Migração 332: coluna ATIVO (cadastros com Ativo no greenportal; os demais sempre 1). As telas oferecem só os ativos e mostram
-- o desativado quando ele já está na NC (NaoConformidadeWorkflow::opcoes).
CREATE OR REPLACE VIEW `vw_nc_opcoes` AS
SELECT 'UNIDADE' AS LISTA, CAST(CODUNIDADE AS CHAR) AS CODIGO, CONVERT(DSCUNIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS DESCRICAO, CAST(1 AS SIGNED) AS ATIVO FROM unidades
UNION ALL SELECT 'AREA', CAST(CODAREA AS CHAR), CONVERT(DSCAREA USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(1 AS SIGNED) FROM areas
UNION ALL SELECT 'SETOR', CAST(codsetor AS CHAR), CONVERT(dscsetor USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(ATIVO AS SIGNED) FROM nc_setores
UNION ALL SELECT 'CLASSIFICACAO', CAST(CODTIPOOCORRENCIA AS CHAR), CONVERT(DSCTIPOOCORRENCIA USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(ATIVO AS SIGNED) FROM nc_tipoocorrencia
UNION ALL SELECT 'TIPOACAO', CAST(CODTIPOACAO AS CHAR), CONVERT(DSCTIPOACAO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(ATIVO AS SIGNED) FROM nc_tipoacao
UNION ALL SELECT 'ORIGEM', CAST(CODORIGEMACAO AS CHAR), CONVERT(DSCORIGEMACAO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(ATIVO AS SIGNED) FROM nc_origemacao
UNION ALL SELECT 'SISTEMA', CAST(CODSIGLAGESTAO AS CHAR), CONVERT(DSCSIGLAGESTAO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(ATIVO AS SIGNED) FROM nc_siglagestao
UNION ALL SELECT 'LISTA', CAST(CODLISTA AS CHAR), CONVERT(DSCLISTA USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(1 AS SIGNED) FROM nc_listadistribuicao
UNION ALL SELECT 'TIPONC', CAST(CODTIPONAOCONFORMIDADE AS CHAR), CONVERT(DSCTIPONAOCONFORMIDADE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(1 AS SIGNED) FROM nc_tipo_de_naoconformidade
UNION ALL SELECT 'TIPOCAUSA', CAST(CODTIPOCAUSA AS CHAR), CONVERT(DSCTIPOCAUSA USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(ATIVO AS SIGNED) FROM nc_tipos_causas
UNION ALL SELECT 'CLASSIF_FORN', CAST(codclassificacao AS CHAR), CONVERT(dscclassificacao USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(ATIVO AS SIGNED) FROM nc_classificacao_fornecedor
UNION ALL SELECT 'FORNECEDOR', CAST(CODFORNECEDOR AS CHAR), CONVERT(RAZAOSOCIAL USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(1 AS SIGNED) FROM fo_fornecedores WHERE ativadofor = 'Sim'
UNION ALL SELECT DISTINCT 'LISTA_FORN', CAST(lc.CODLISTA AS CHAR), CONVERT(c.DSCCATEGORIA USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(1 AS SIGNED)
  FROM nc_listadistribuicaoxcategoria lc JOIN nc_categorias c ON c.CODCATEGORIA = lc.CODCATEGORIA WHERE c.DSCCATEGORIA = 'Fornecedores'
-- Cliente (21/10/2026, etapa 3 — "327"): listas do tipo Cliente (cadastros do legado, sem as quebras de linha do fim dos nomes).
-- O cliente é gravado pelo nome (DSCCLIENTE, como o legado); produto pelo código (CODIGODOPRODUTO = nc_produtos.codproduto).
UNION ALL SELECT 'CLIENTE', x.NOME, x.NOME, CAST(MAX(x.ATIVO) AS SIGNED) FROM (SELECT CONVERT(TRIM(REPLACE(REPLACE(dsccliente, CHAR(13), ''), CHAR(10), '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NOME, ATIVO FROM nc_clientes) x GROUP BY x.NOME
UNION ALL SELECT 'UNIDADE_CLIENTE', CAST(codunidade AS CHAR), CONVERT(TRIM(REPLACE(REPLACE(dscunidade, CHAR(13), ''), CHAR(10), '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(ATIVO AS SIGNED) FROM nc_unidadesdocliente
UNION ALL SELECT 'PRODUTO', CAST(codproduto AS CHAR), CONVERT(TRIM(REPLACE(REPLACE(dscproduto, CHAR(13), ''), CHAR(10), '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(ATIVO AS SIGNED) FROM nc_produtos
UNION ALL SELECT 'TIPODOC', CAST(codtipodedocumento AS CHAR), CONVERT(TRIM(REPLACE(REPLACE(dsctipodedocumento, CHAR(13), ''), CHAR(10), '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(ATIVO AS SIGNED) FROM nc_tipodedocumento
UNION ALL SELECT 'TAMPA', CAST(codtipodetampa AS CHAR), CONVERT(TRIM(REPLACE(REPLACE(dsctipodetampa, CHAR(13), ''), CHAR(10), '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(ATIVO AS SIGNED) FROM nc_tipo_de_tampa
UNION ALL SELECT 'LATA', CAST(codtamanhodalata AS CHAR), CONVERT(TRIM(REPLACE(REPLACE(dsctamanhodalata, CHAR(13), ''), CHAR(10), '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(ATIVO AS SIGNED) FROM nc_tamanho_da_lata
UNION ALL SELECT 'RECLAMACAO', CAST(codtiporeclamacao AS CHAR), CONVERT(TRIM(REPLACE(REPLACE(dsctiporeclamacao, CHAR(13), ''), CHAR(10), '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(ATIVO AS SIGNED) FROM nc_tiporeclamacao
-- @@
CREATE OR REPLACE VIEW `vw_nc_causas` AS
SELECT c.CODCAUSA, c.CODOCORRENCIA, c.CODTIPOCAUSA, t.DSCTIPOCAUSA, c.DSCAUSA,
       (SELECT COUNT(*) FROM nc_planodeacao p WHERE p.CODCAUSA = c.CODCAUSA) AS PLANOS
  FROM nc_causas c LEFT JOIN nc_tipos_causas t ON t.CODTIPOCAUSA = c.CODTIPOCAUSA
-- @@
CREATE OR REPLACE VIEW `vw_nc_planodeacao` AS
SELECT p.CODPLANOACAO, COALESCE(p.CODOCORRENCIA, c.CODOCORRENCIA) AS CODOCORRENCIA, p.CODCAUSA, c.DSCAUSA, p.ACAO, p.PRIORIDADE, p.CODUSUARIO, u.nome AS RESPONSAVEL,
       p.DATAINICIAL, p.DATAFINAL, p.RECURSOS, p.STATUS, p.obs AS OBS, p.data_de_criacao AS DATA_DE_CRIACAO,
       p.DATA_REPROGRAMACAO, p.MOTIVO_REPROGRAMACAO
  FROM nc_planodeacao p LEFT JOIN nc_causas c ON c.CODCAUSA = p.CODCAUSA LEFT JOIN usuarios u ON u.codusuario = p.CODUSUARIO
-- @@
CREATE OR REPLACE VIEW `vw_nc_historico` AS
SELECT h.CODHISTORICO, h.CODOCORRENCIA, h.ACAO, fn_nc_etapa(h.ETAPA_ANTERIOR) AS ETAPA_ANTERIOR, fn_nc_etapa(h.ETAPA_NOVA) AS ETAPA_NOVA, h.CODUSUARIO, u.nome AS USUARIO, h.MOTIVO, h.CRIADO_EM
  FROM nc_workflow_historico h LEFT JOIN usuarios u ON u.codusuario = h.CODUSUARIO
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_historico_registrar`
-- @@
CREATE PROCEDURE `sp_nc_historico_registrar`(IN p_codigo INT, IN p_acao VARCHAR(50), IN p_anterior VARCHAR(500), IN p_nova VARCHAR(500), IN p_usuario INT, IN p_motivo TEXT)
BEGIN
  INSERT INTO nc_workflow_historico (CODOCORRENCIA, ACAO, ETAPA_ANTERIOR, ETAPA_NOVA, CODUSUARIO, MOTIVO, CRIADO_EM)
  VALUES (p_codigo, p_acao, p_anterior, p_nova, p_usuario, NULLIF(TRIM(COALESCE(p_motivo, '')), ''), NOW());
  -- Passagem de etapa: avisa os envolvidos em Home › Alertas (no lugar dos e-mails do legado, enquanto não há SMTP)
  IF p_acao IN ('ENVIO_GESTOR', 'REENVIO_GESTOR', 'ENVIO_RESPONSAVEL', 'RECUSA_GESTOR', 'IMPROCEDENTE', 'ENVIO_EFICACIA', 'NAO_EFICAZ', 'RECUSA_EFICACIA', 'FINALIZADA_EFICAZ', 'FINALIZADA_SEM_EFICACIA') THEN
    CALL sp_nc_alertar(p_codigo, p_acao, p_usuario);
  END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_alertar`
-- @@
CREATE PROCEDURE `sp_nc_alertar`(IN p_codigo INT, IN p_acao VARCHAR(50), IN p_usuario INT)
BEGIN
  -- Destinatários como os e-mails do legado (formulario/nc_s.php):
  --   envio ao gestor → gestor e lista de distribuição (Planejamento vai direto à análise → responsável);
  --   envio ao responsável → responsável; recusa do gestor e improcedente → emitente; envio à eficácia → gestor;
  --   não eficaz / recusa na eficácia → responsável; finalizada eficaz → emitente, gestor e responsável.
  -- Quem fez a passagem não recebe o aviso. Só usuários ativos. Um erro aqui não impede a passagem de etapa.
  DECLARE v_emissor, v_gestor, v_resp INT DEFAULT 0;
  DECLARE v_lista VARCHAR(100);
  DECLARE v_etapa, v_sumario, v_numero VARCHAR(500);
  DECLARE v_titulo VARCHAR(180);
  DECLARE v_aba VARCHAR(20) DEFAULT 'ocorrencia';
  DECLARE v_tipo INT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
  SELECT CODTIPONAOCONFORMIDADE INTO v_tipo FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
  SELECT COALESCE(CODUSUARIO_EMISSOR, 0), COALESCE(CODUSUARIO_GESTOR, 0), COALESCE(CODUSUARIO_RESPONSAVEL, 0), listadedistribuicao,
         CONVERT(COALESCE(ETAPA, '') USING utf8mb4), CONVERT(COALESCE(IDENTIFICACAO, '') USING utf8mb4), CONVERT(COALESCE(NULLIF(NRANO, ''), CODOCORRENCIA) USING utf8mb4)
    INTO v_emissor, v_gestor, v_resp, v_lista, v_etapa, v_sumario, v_numero
    FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
  DROP TEMPORARY TABLE IF EXISTS tmp_nc_alerta;
  CREATE TEMPORARY TABLE tmp_nc_alerta (codusuario INT PRIMARY KEY, titulo VARCHAR(180));
  IF p_acao IN ('ENVIO_GESTOR', 'REENVIO_GESTOR') AND v_etapa LIKE 'Em Analise%' THEN
    SET v_aba = 'analise';
    INSERT IGNORE INTO tmp_nc_alerta VALUES (v_resp, 'Favor avaliar a causa raiz de não conformidade');
  ELSEIF p_acao IN ('ENVIO_GESTOR', 'REENVIO_GESTOR') AND v_etapa LIKE 'Plano de A%' AND v_tipo = 6 THEN
    -- Assistência Técnica (legado nc_assistenciatecnica/nc_s.php, "enviar_planodeacao"): avisa o responsável de cada ação
    SET v_aba = 'analise';
    INSERT IGNORE INTO tmp_nc_alerta SELECT DISTINCT CODUSUARIO, 'Favor avaliar a ação sob sua responsabilidade de uma não conformidade'
      FROM vw_nc_planodeacao WHERE CODOCORRENCIA = p_codigo AND CODUSUARIO > 0;
  ELSEIF p_acao IN ('ENVIO_GESTOR', 'REENVIO_GESTOR') AND v_etapa LIKE 'Plano de A%' THEN
    -- Planejamento (legado nc_planejamento/nc_s.php)
    SET v_aba = 'analise';
    INSERT IGNORE INTO tmp_nc_alerta VALUES (v_gestor, 'Favor preencher o plano de ação para esta não conformidade de Planejamento');
  ELSEIF p_acao IN ('ENVIO_GESTOR', 'REENVIO_GESTOR') THEN
    SET v_aba = 'gestor';
    INSERT IGNORE INTO tmp_nc_alerta VALUES (v_gestor, 'Favor avaliar a ocorrência de não conformidade');
    IF v_lista REGEXP '^[0-9]+$' THEN
      INSERT IGNORE INTO tmp_nc_alerta SELECT DISTINCT CODUSUARIO, 'Tomar conhecimento da criação da não conformidade'
        FROM nc_listadistribuicaoxusuarios WHERE CODLISTA = CAST(v_lista AS SIGNED);
      -- Migração 333: participantes externos da lista (sem login) recebem o mesmo aviso por e-mail (fila do serviço de e-mail).
      -- Sem a migração 333 a procedure não existe e o erro é ignorado pelo handler acima.
      CALL sp_nc_email_participantes(p_codigo, 'Tomar conhecimento da criação da não conformidade');
    END IF;
  ELSEIF p_acao = 'ENVIO_RESPONSAVEL' THEN
    SET v_aba = 'analise';
    INSERT IGNORE INTO tmp_nc_alerta VALUES (v_resp, 'Favor avaliar a causa raiz de não conformidade');
  ELSEIF p_acao = 'RECUSA_GESTOR' THEN
    INSERT IGNORE INTO tmp_nc_alerta VALUES (v_emissor, 'Não conformidade foi recusada pelo Gestor');
  ELSEIF p_acao = 'IMPROCEDENTE' THEN
    SET v_aba = 'gestor';
    INSERT IGNORE INTO tmp_nc_alerta VALUES (v_emissor, 'Não conformidade foi encerrada por improcedência');
  ELSEIF p_acao = 'ENVIO_EFICACIA' THEN
    SET v_aba = 'eficacia';
    INSERT IGNORE INTO tmp_nc_alerta VALUES (v_gestor, 'Favor fazer a análise da eficácia da não conformidade');
  ELSEIF p_acao IN ('NAO_EFICAZ', 'RECUSA_EFICACIA') THEN
    SET v_aba = 'analise';
    INSERT IGNORE INTO tmp_nc_alerta VALUES (v_resp, 'Favor avaliar a recusa da não conformidade pela Avaliação da Eficácia');
  ELSEIF p_acao = 'FINALIZADA_EFICAZ' THEN
    SET v_aba = 'eficacia';
    INSERT IGNORE INTO tmp_nc_alerta VALUES (v_emissor, 'Tomar conhecimento da finalização da não conformidade'),
      (v_gestor, 'Tomar conhecimento da finalização da não conformidade'), (v_resp, 'Tomar conhecimento da finalização da não conformidade');
  ELSEIF p_acao = 'FINALIZADA_SEM_EFICACIA' THEN
    SET v_aba = 'analise';
    INSERT IGNORE INTO tmp_nc_alerta VALUES (v_emissor, 'Tomar conhecimento da finalização da não conformidade'), (v_gestor, 'Tomar conhecimento da finalização da não conformidade');
  END IF;
  -- Planejamento e Assistência Técnica: os responsáveis das ações também são avisados da recusa na eficácia e da finalização
  IF v_tipo IN (5, 6) AND p_acao IN ('NAO_EFICAZ', 'RECUSA_EFICACIA', 'FINALIZADA_EFICAZ', 'FINALIZADA_SEM_EFICACIA') THEN
    INSERT IGNORE INTO tmp_nc_alerta SELECT DISTINCT CODUSUARIO, IF(p_acao LIKE 'FINALIZADA%', 'Tomar conhecimento da finalização da não conformidade', 'Favor avaliar a recusa da não conformidade pela Avaliação da Eficácia')
      FROM nc_planodeacao WHERE CODOCORRENCIA = p_codigo AND CODUSUARIO > 0;
  END IF;
  INSERT INTO alertas (codusuario, titulo, mensagem, link, lido)
  SELECT t.codusuario, LEFT(CONCAT('NC ', v_numero, ': ',
         IF(EXISTS (SELECT 1 FROM fo_fornecedorxusuario fu WHERE fu.CODUSUARIO = t.codusuario), CONCAT('Sr. Fornecedor, ', LOWER(LEFT(t.titulo, 1)), SUBSTRING(t.titulo, 2)), t.titulo)), 180),
         CONCAT('Não conformidade ', v_numero, IF(v_sumario = '', '', CONCAT(' — ', LEFT(v_sumario, 300))), '. Etapa: ', fn_nc_etapa(v_etapa), '.'),
         CONCAT('/principal/naoconformidades_ocorrencia.php?codigo=', p_codigo, '&aba=', v_aba), 0
    FROM tmp_nc_alerta t JOIN usuarios u ON u.codusuario = t.codusuario AND u.ativo = 1
   WHERE t.codusuario > 0 AND t.codusuario <> COALESCE(p_usuario, 0);
  DROP TEMPORARY TABLE IF EXISTS tmp_nc_alerta;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_ocorrencia_salvar`
-- @@
CREATE PROCEDURE `sp_nc_ocorrencia_salvar`(IN p_codigo INT, IN p_tipo INT, IN p_dados JSON, IN p_usuario INT, IN p_enviar TINYINT, IN p_admin TINYINT)
BEGIN
  DECLARE v_etapa VARCHAR(500); DECLARE v_emissor INT; DECLARE v_cod INT; DECLARE v_nova VARCHAR(500);
  DECLARE v_gestor INT DEFAULT CAST(fn_nc_txt(p_dados, 'CODUSUARIO_GESTOR') AS SIGNED);
  IF p_codigo IS NULL OR p_codigo = 0 THEN
    IF p_tipo IS NULL OR NOT EXISTS (SELECT 1 FROM nc_tipo_de_naoconformidade WHERE CODTIPONAOCONFORMIDADE = p_tipo) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipo de não conformidade inválido.';
    END IF;
    SELECT GREATEST(fn_nc_faixa('ocorrencia'), COALESCE(MAX(CODOCORRENCIA), 0) + 1) INTO v_cod FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA >= fn_nc_faixa('ocorrencia');
    INSERT INTO nc_indicacao_ocorrencia (CODOCORRENCIA, CODTIPONAOCONFORMIDADE, CODUSUARIO_EMISSOR, ETAPA, SITUACAO, data_de_criacao) VALUES (v_cod, p_tipo, p_usuario, 'Em criação', 'Em andamento', NOW());
    SET v_etapa = NULL;
    UPDATE nc_indicacao_ocorrencia SET NRANO = CONCAT(v_cod, '/', YEAR(CURDATE())) WHERE CODOCORRENCIA = v_cod;
    CALL sp_nc_historico_registrar(v_cod, 'CRIACAO', NULL, 'Em criação', p_usuario, NULL);
  ELSE
    SELECT ETAPA, CODUSUARIO_EMISSOR INTO v_etapa, v_emissor FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
    IF v_etapa IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ocorrência não encontrada.'; END IF;
    IF v_etapa NOT IN ('Em criação', 'Recusado pelo Gestor') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A ocorrência já foi enviada ao gestor e não pode mais ser alterada nesta aba.'; END IF;
    IF COALESCE(v_emissor, 0) <> p_usuario AND p_admin = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Só o emitente pode alterar a ocorrência.'; END IF;
    SET v_cod = p_codigo;
  END IF;
  UPDATE nc_indicacao_ocorrencia SET
    codunidade = IF(fn_nc_tem(p_dados, 'CODUNIDADE'), CAST(fn_nc_txt(p_dados, 'CODUNIDADE') AS SIGNED), codunidade),
    listadedistribuicao = IF(fn_nc_tem(p_dados, 'LISTADEDISTRIBUICAO'), fn_nc_txt(p_dados, 'LISTADEDISTRIBUICAO'), listadedistribuicao),
    CODUSUARIO_GESTOR = IF(fn_nc_tem(p_dados, 'CODUSUARIO_GESTOR'), v_gestor, CODUSUARIO_GESTOR),
    DATA_OCORRENCIA = IF(fn_nc_tem(p_dados, 'DATA_OCORRENCIA'), CAST(fn_nc_txt(p_dados, 'DATA_OCORRENCIA') AS DATETIME), DATA_OCORRENCIA),
    codsetor = IF(fn_nc_tem(p_dados, 'CODSETOR'), CAST(fn_nc_txt(p_dados, 'CODSETOR') AS SIGNED), codsetor),
    CODAREA = IF(fn_nc_tem(p_dados, 'CODAREA'), CAST(fn_nc_txt(p_dados, 'CODAREA') AS SIGNED), CODAREA),
    CODTIPOOCORRENCIA = IF(fn_nc_tem(p_dados, 'CODTIPOOCORRENCIA'), CAST(fn_nc_txt(p_dados, 'CODTIPOOCORRENCIA') AS SIGNED), CODTIPOOCORRENCIA),
    PARADANALINHA = IF(fn_nc_tem(p_dados, 'PARADANALINHA'), LEFT(fn_nc_txt(p_dados, 'PARADANALINHA'), 1), PARADANALINHA),
    houve_retencao_produto = IF(fn_nc_tem(p_dados, 'HOUVE_RETENCAO_PRODUTO'), LEFT(fn_nc_txt(p_dados, 'HOUVE_RETENCAO_PRODUTO'), 3), houve_retencao_produto),
    havera_devolucao_material = IF(fn_nc_tem(p_dados, 'HAVERA_DEVOLUCAO_MATERIAL'), LEFT(fn_nc_txt(p_dados, 'HAVERA_DEVOLUCAO_MATERIAL'), 3), havera_devolucao_material),
    codtipodedocumento = IF(fn_nc_tem(p_dados, 'CODTIPODEDOCUMENTO'), CAST(fn_nc_txt(p_dados, 'CODTIPODEDOCUMENTO') AS SIGNED), codtipodedocumento),
    nrndocumento = IF(fn_nc_tem(p_dados, 'NRNDOCUMENTO'), LEFT(fn_nc_txt(p_dados, 'NRNDOCUMENTO'), 200), nrndocumento),
    CODIGODOPRODUTO = IF(fn_nc_tem(p_dados, 'CODIGODOPRODUTO'), LEFT(fn_nc_txt(p_dados, 'CODIGODOPRODUTO'), 500), CODIGODOPRODUTO),
    DSCCLIENTE = IF(fn_nc_tem(p_dados, 'DSCCLIENTE'), LEFT(fn_nc_txt(p_dados, 'DSCCLIENTE'), 500), DSCCLIENTE),
    codunidade_cliente = IF(fn_nc_tem(p_dados, 'CODUNIDADE_CLIENTE'), CAST(fn_nc_txt(p_dados, 'CODUNIDADE_CLIENTE') AS SIGNED), codunidade_cliente),
    codtamanhodalata = IF(fn_nc_tem(p_dados, 'CODTAMANHODALATA'), CAST(fn_nc_txt(p_dados, 'CODTAMANHODALATA') AS SIGNED), codtamanhodalata),
    codtipodetampa = IF(fn_nc_tem(p_dados, 'CODTIPODETAMPA'), CAST(fn_nc_txt(p_dados, 'CODTIPODETAMPA') AS SIGNED), codtipodetampa),
    codtipodereclamacao = IF(fn_nc_tem(p_dados, 'CODTIPODERECLAMACAO'), CAST(fn_nc_txt(p_dados, 'CODTIPODERECLAMACAO') AS SIGNED), codtipodereclamacao),
    afetasegurancaalimentos = IF(fn_nc_tem(p_dados, 'AFETASEGURANCAALIMENTOS'), LEFT(fn_nc_txt(p_dados, 'AFETASEGURANCAALIMENTOS'), 3), afetasegurancaalimentos),
    procedencia_cliente = IF(fn_nc_tem(p_dados, 'PROCEDENCIA_CLIENTE'), LEFT(fn_nc_txt(p_dados, 'PROCEDENCIA_CLIENTE'), 100), procedencia_cliente),
    QTDENVOLVIDA = IF(fn_nc_tem(p_dados, 'QTDENVOLVIDA'), LEFT(fn_nc_txt(p_dados, 'QTDENVOLVIDA'), 100), QTDENVOLVIDA),
    QTDDEVOLVIDA = IF(fn_nc_tem(p_dados, 'QTDDEVOLVIDA'), LEFT(fn_nc_txt(p_dados, 'QTDDEVOLVIDA'), 100), QTDDEVOLVIDA),
    CODFORNECEDOR = IF(fn_nc_tem(p_dados, 'CODFORNECEDOR'), CAST(fn_nc_txt(p_dados, 'CODFORNECEDOR') AS SIGNED), CODFORNECEDOR),
    PRODUTO = IF(fn_nc_tem(p_dados, 'PRODUTO'), LEFT(fn_nc_txt(p_dados, 'PRODUTO'), 500), PRODUTO),
    TAMANHOLOTEENVOLVIDO = IF(fn_nc_tem(p_dados, 'TAMANHOLOTEENVOLVIDO'), LEFT(fn_nc_txt(p_dados, 'TAMANHOLOTEENVOLVIDO'), 100), TAMANHOLOTEENVOLVIDO),
    IDENTIFICACAO = IF(fn_nc_tem(p_dados, 'IDENTIFICACAO'), LEFT(fn_nc_txt(p_dados, 'IDENTIFICACAO'), 500), IDENTIFICACAO),
    DESCRICAO = IF(fn_nc_tem(p_dados, 'DESCRICAO'), fn_nc_txt(p_dados, 'DESCRICAO'), DESCRICAO),
    PROVIDENCIA = IF(fn_nc_tem(p_dados, 'PROVIDENCIA'), fn_nc_txt(p_dados, 'PROVIDENCIA'), PROVIDENCIA),
    CODTIPOACAO = IF(fn_nc_tem(p_dados, 'CODTIPOACAO'), CAST(fn_nc_txt(p_dados, 'CODTIPOACAO') AS SIGNED), CODTIPOACAO),
    CODORIGEMACAO = IF(fn_nc_tem(p_dados, 'CODORIGEMACAO'), CAST(fn_nc_txt(p_dados, 'CODORIGEMACAO') AS SIGNED), CODORIGEMACAO),
    CODSIGLAGESTAO = IF(fn_nc_tem(p_dados, 'CODSIGLAGESTAO'), CAST(fn_nc_txt(p_dados, 'CODSIGLAGESTAO') AS SIGNED), CODSIGLAGESTAO),
    codauditoria = IF(fn_nc_tem(p_dados, 'CODAUDITORIA'), CAST(fn_nc_txt(p_dados, 'CODAUDITORIA') AS SIGNED), codauditoria),
    pass_pela_nanalise_eficacia = IF(fn_nc_tem(p_dados, 'PASS_PELA_NANALISE_EFICACIA'), LEFT(fn_nc_txt(p_dados, 'PASS_PELA_NANALISE_EFICACIA'), 1), pass_pela_nanalise_eficacia)
   WHERE CODOCORRENCIA = v_cod;
  IF p_enviar = 1 THEN
    SELECT ETAPA, CODUSUARIO_GESTOR INTO v_etapa, v_gestor FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = v_cod;
    IF COALESCE(v_gestor, 0) = 0 OR NOT EXISTS (SELECT 1 FROM usuarios WHERE codusuario = v_gestor AND ativo = 1) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha um gestor ativo antes de enviar.';
    END IF;
    -- Sumário e descrição só quando o tipo usa o campo (o Cliente da Crown não tem sumário: nc_tipo_de_naoconformidadexcampos)
    IF EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia o WHERE o.CODOCORRENCIA = v_cod AND (COALESCE(o.codunidade, 0) = 0
        OR (TRIM(COALESCE(o.IDENTIFICACAO, '')) = '' AND EXISTS (SELECT 1 FROM vw_nc_tipo_campos t WHERE t.CODTIPONAOCONFORMIDADE = o.CODTIPONAOCONFORMIDADE AND t.CAMPO = 'IDENTIFICACAO'))
        OR (TRIM(COALESCE(o.DESCRICAO, '')) = '' AND EXISTS (SELECT 1 FROM vw_nc_tipo_campos t WHERE t.CODTIPONAOCONFORMIDADE = o.CODTIPONAOCONFORMIDADE AND t.CAMPO = 'DESCRICAO')))) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preencha unidade, sumário e descrição antes de enviar.';
    END IF;
    IF EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = v_cod AND CODTIPONAOCONFORMIDADE = 1 AND COALESCE(CODFORNECEDOR, 0) = 0) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha o fornecedor antes de enviar.';
    END IF;
    -- Cliente (decisão do usuário 21/10/2026): campos mais usados no legado obrigatórios no envio
    IF EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = v_cod AND CODTIPONAOCONFORMIDADE = 2 AND (TRIM(COALESCE(DSCCLIENTE, '')) = ''
        OR COALESCE(codunidade_cliente, 0) = 0 OR TRIM(COALESCE(CODIGODOPRODUTO, '')) IN ('', '0') OR COALESCE(codtipodedocumento, 0) = 0
        OR TRIM(COALESCE(nrndocumento, '')) = '' OR COALESCE(codtipodereclamacao, 0) = 0 OR TRIM(COALESCE(procedencia_cliente, '')) = ''
        OR TRIM(COALESCE(afetasegurancaalimentos, '')) = '')) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preencha cliente, unidade do cliente, produto, documento, reclamação, procedência e segurança de alimentos.';
    END IF;
    SET v_nova = 'Com o Gestor';
    -- Assistência Técnica (legado nc_assistenciatecnica/checa_nc_planodeacao.php): o emitente monta as ações antes de enviar
    IF EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = v_cod AND CODTIPONAOCONFORMIDADE = 6) THEN
      IF NOT EXISTS (SELECT 1 FROM nc_planodeacao WHERE CODOCORRENCIA = v_cod) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cadastre pelo menos uma ação antes de enviar o plano de ação.'; END IF;
      IF EXISTS (SELECT 1 FROM nc_planodeacao WHERE CODOCORRENCIA = v_cod AND (TRIM(COALESCE(ACAO, '')) = '' OR COALESCE(CODUSUARIO, 0) = 0)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Toda ação precisa de descrição e responsável.';
      END IF;
    END IF;
    -- Planejamento (legado nc_planejamento/nc_s.php, "enviar_para_gestor") e Assistência Técnica ("enviar_planodeacao"): vão direto ao Plano de Ação
    IF EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = v_cod AND CODTIPONAOCONFORMIDADE IN (5, 6)) THEN
      IF EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = v_cod AND COALESCE(pass_pela_nanalise_eficacia, '') NOT IN ('S', 'N')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe se a ocorrência vai passar pela análise da eficácia.';
      END IF;
      SET v_nova = 'Plano de Ação';
    END IF;
    UPDATE nc_indicacao_ocorrencia SET ETAPA = v_nova, data_de_conhecimento_fornecedor = IF(v_nova = 'Plano de Ação', NOW(), data_de_conhecimento_fornecedor) WHERE CODOCORRENCIA = v_cod;
    CALL sp_nc_historico_registrar(v_cod, IF(v_etapa = 'Recusado pelo Gestor', 'REENVIO_GESTOR', 'ENVIO_GESTOR'), v_etapa, v_nova, p_usuario, NULL);
    -- Assistência Técnica: ações já a 100% no envio → avança na hora (eficácia ou finalizada)
    IF v_nova = 'Plano de Ação' THEN CALL sp_nc_planejamento_avancar(v_cod, p_usuario); END IF;
  ELSEIF p_codigo IS NOT NULL AND p_codigo > 0 THEN
    CALL sp_nc_historico_registrar(v_cod, 'ALTERACAO', v_etapa, v_etapa, p_usuario, NULL);
  END IF;
  SELECT CODOCORRENCIA, NRANO, ETAPA FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = v_cod;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_gestor_salvar`
-- @@
CREATE PROCEDURE `sp_nc_gestor_salvar`(IN p_codigo INT, IN p_dados JSON, IN p_usuario INT, IN p_acao VARCHAR(20), IN p_admin TINYINT)
BEGIN
  DECLARE v_etapa VARCHAR(500); DECLARE v_gestor INT; DECLARE v_nova VARCHAR(500);
  SELECT ETAPA, CODUSUARIO_GESTOR INTO v_etapa, v_gestor FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
  IF v_etapa IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ocorrência não encontrada.'; END IF;
  IF v_etapa <> 'Com o Gestor' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A ocorrência não está aguardando a avaliação do gestor.'; END IF;
  IF COALESCE(v_gestor, 0) <> p_usuario AND p_admin = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Só o gestor da ocorrência pode avaliá-la.'; END IF;
  UPDATE nc_indicacao_ocorrencia SET
    ANALISE_DA_PROVIDENCIA = IF(fn_nc_tem(p_dados, 'ANALISE_DA_PROVIDENCIA'), fn_nc_txt(p_dados, 'ANALISE_DA_PROVIDENCIA'), ANALISE_DA_PROVIDENCIA),
    ANALISE_ABRANGENCIA = IF(fn_nc_tem(p_dados, 'ANALISE_ABRANGENCIA'), fn_nc_txt(p_dados, 'ANALISE_ABRANGENCIA'), ANALISE_ABRANGENCIA),
    atualizar_relacao_de_riscos_e_oportunidades = IF(fn_nc_tem(p_dados, 'ATUALIZAR_RISCOS'), LEFT(fn_nc_txt(p_dados, 'ATUALIZAR_RISCOS'), 1), atualizar_relacao_de_riscos_e_oportunidades),
    abrir_nova_avaliacao_de_mudanca = IF(fn_nc_tem(p_dados, 'ABRIR_MUDANCA'), LEFT(fn_nc_txt(p_dados, 'ABRIR_MUDANCA'), 1), abrir_nova_avaliacao_de_mudanca),
    improcedente = IF(fn_nc_tem(p_dados, 'IMPROCEDENTE'), LEFT(fn_nc_txt(p_dados, 'IMPROCEDENTE'), 3), improcedente),
    CODUSUARIO_RESPONSAVEL = IF(fn_nc_tem(p_dados, 'CODUSUARIO_RESPONSAVEL'), CAST(fn_nc_txt(p_dados, 'CODUSUARIO_RESPONSAVEL') AS SIGNED), CODUSUARIO_RESPONSAVEL),
    RECUSA_DO_GESTOR = IF(fn_nc_tem(p_dados, 'RECUSA_DO_GESTOR'), LEFT(fn_nc_txt(p_dados, 'RECUSA_DO_GESTOR'), 5000), RECUSA_DO_GESTOR)
   WHERE CODOCORRENCIA = p_codigo;
  IF p_acao = 'enviar' THEN
    IF EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo AND (COALESCE(CODUSUARIO_RESPONSAVEL, 0) = 0
               OR TRIM(COALESCE(ANALISE_DA_PROVIDENCIA, '')) = '' OR TRIM(COALESCE(ANALISE_ABRANGENCIA, '')) = '')) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Para enviar ao responsável, preencha a ação imediata, a análise da abrangência e escolha o responsável.';
    END IF;
    SET v_nova = 'Em Analise da Causa Raiz';
    UPDATE nc_indicacao_ocorrencia SET ETAPA = v_nova, data_de_conhecimento_fornecedor = NOW() WHERE CODOCORRENCIA = p_codigo;
    CALL sp_nc_historico_registrar(p_codigo, 'ENVIO_RESPONSAVEL', v_etapa, v_nova, p_usuario, NULL);
  ELSEIF p_acao = 'recusar' THEN
    IF EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo AND TRIM(COALESCE(RECUSA_DO_GESTOR, '')) = '') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o motivo da recusa.';
    END IF;
    SET v_nova = 'Recusado pelo Gestor';
    UPDATE nc_indicacao_ocorrencia SET ETAPA = v_nova WHERE CODOCORRENCIA = p_codigo;
    CALL sp_nc_historico_registrar(p_codigo, 'RECUSA_GESTOR', v_etapa, v_nova, p_usuario, (SELECT RECUSA_DO_GESTOR FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo));
  ELSEIF p_acao = 'improcedente' THEN
    SET v_nova = 'Finalizado';
    UPDATE nc_indicacao_ocorrencia SET improcedente = 'SIM', ETAPA = v_nova, data_de_finalizacao = NOW(), SITUACAO = 'Foi finalizado pelo gestor por ser improcedente!' WHERE CODOCORRENCIA = p_codigo;
    CALL sp_nc_historico_registrar(p_codigo, 'IMPROCEDENTE', v_etapa, v_nova, p_usuario, NULL);
  ELSE
    CALL sp_nc_historico_registrar(p_codigo, 'ALTERACAO_GESTOR', v_etapa, v_etapa, p_usuario, NULL);
  END IF;
  SELECT CODOCORRENCIA, NRANO, ETAPA FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_analise_validar_acesso`
-- @@
CREATE PROCEDURE `sp_nc_analise_validar_acesso`(IN p_codigo INT, IN p_usuario INT, IN p_admin TINYINT)
BEGIN
  DECLARE v_etapa VARCHAR(500); DECLARE v_resp INT;
  SELECT ETAPA, CODUSUARIO_RESPONSAVEL INTO v_etapa, v_resp FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
  IF v_etapa IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ocorrência não encontrada.'; END IF;
  IF v_etapa NOT IN ('Em Analise da Causa Raiz', 'Plano de Ação', 'Plano de Acao', 'Recusado pela Avaliacao da Eficacia') AND v_etapa NOT LIKE 'Plano de A%' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A ocorrência não está na etapa de análise da causa e plano de ação.';
  END IF;
  IF COALESCE(v_resp, 0) <> p_usuario AND p_admin = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Só o responsável pela análise pode alterar causas e plano de ação.'; END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_etapa_plano`
-- @@
CREATE PROCEDURE `sp_nc_etapa_plano`(IN p_codigo INT, IN p_usuario INT)
BEGIN
  -- Legado (cadastros/nc_causas_s.php e nc_planodeacao_s.php): incluir causa ou gravar ação passa a ocorrência para "Plano de Ação"
  DECLARE v_etapa VARCHAR(500);
  SELECT ETAPA INTO v_etapa FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
  -- "Plano de Ação" gravado com acentuação quebrada pelo legado já é Plano de Ação (não registra mudança de etapa)
  IF v_etapa IS NOT NULL AND v_etapa NOT LIKE 'Plano de A%' THEN
    UPDATE nc_indicacao_ocorrencia SET ETAPA = 'Plano de Ação' WHERE CODOCORRENCIA = p_codigo;
    CALL sp_nc_historico_registrar(p_codigo, 'PLANO_DE_ACAO', v_etapa, 'Plano de Ação', p_usuario, NULL);
  END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_planejamento_avancar`
-- @@
CREATE PROCEDURE `sp_nc_planejamento_avancar`(IN p_codigo INT, IN p_usuario INT)
BEGIN
  -- Planejamento (legado cadastros/pendencias_nc_planodeacao_update.php): com todas as ações a 100%, "Passar pela análise da
  -- eficácia" = S vai para a Avaliação da Eficácia do gestor; = N finaliza como ENCERRADO "Sem verificação da eficácia".
  DECLARE v_etapa VARCHAR(500); DECLARE v_passa CHAR(1);
  SELECT ETAPA, UPPER(COALESCE(pass_pela_nanalise_eficacia, 'S')) INTO v_etapa, v_passa FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo AND CODTIPONAOCONFORMIDADE IN (5, 6);
  IF v_etapa LIKE 'Plano de A%' AND EXISTS (SELECT 1 FROM nc_planodeacao WHERE CODOCORRENCIA = p_codigo)
     AND NOT EXISTS (SELECT 1 FROM nc_planodeacao WHERE CODOCORRENCIA = p_codigo AND COALESCE(STATUS, 0) < 100) THEN
    IF v_passa = 'N' THEN
      UPDATE nc_indicacao_ocorrencia SET ETAPA = 'Finalizado', SITUACAO = 'ENCERRADO', VERIFICACAO_DA_EFICACIA = 'Sem verificação da eficácia', data_de_finalizacao = NOW() WHERE CODOCORRENCIA = p_codigo;
      CALL sp_nc_historico_registrar(p_codigo, 'FINALIZADA_SEM_EFICACIA', v_etapa, 'Finalizado', p_usuario, NULL);
    ELSE
      UPDATE nc_indicacao_ocorrencia SET ETAPA = 'Avaliação da Eficacia', data_de_envia_analise_eficacia = NOW() WHERE CODOCORRENCIA = p_codigo;
      CALL sp_nc_historico_registrar(p_codigo, 'ENVIO_EFICACIA', v_etapa, 'Avaliação da Eficacia', p_usuario, NULL);
    END IF;
  END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_alertar_usuario`
-- @@
CREATE PROCEDURE `sp_nc_alertar_usuario`(IN p_codigo INT, IN p_destino INT, IN p_titulo VARCHAR(180), IN p_aba VARCHAR(20), IN p_usuario INT)
BEGIN
  -- Alerta de NC a um usuário (ex.: responsável de uma ação do Planejamento). Não avisa quem fez; só ativos; erro não interrompe.
  DECLARE v_numero, v_sumario, v_etapa VARCHAR(500);
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
  SELECT CONVERT(COALESCE(NULLIF(NRANO, ''), CODOCORRENCIA) USING utf8mb4), CONVERT(COALESCE(IDENTIFICACAO, '') USING utf8mb4), CONVERT(COALESCE(ETAPA, '') USING utf8mb4)
    INTO v_numero, v_sumario, v_etapa FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
  IF COALESCE(p_destino, 0) > 0 AND p_destino <> COALESCE(p_usuario, 0) AND EXISTS (SELECT 1 FROM usuarios WHERE codusuario = p_destino AND ativo = 1) THEN
    INSERT INTO alertas (codusuario, titulo, mensagem, link, lido)
    VALUES (p_destino, LEFT(CONCAT('NC ', v_numero, ': ', IF(EXISTS (SELECT 1 FROM fo_fornecedorxusuario fu WHERE fu.CODUSUARIO = p_destino), CONCAT('Sr. Fornecedor, ', LOWER(LEFT(p_titulo, 1)), SUBSTRING(p_titulo, 2)), p_titulo)), 180),
            CONCAT('Não conformidade ', v_numero, IF(v_sumario = '', '', CONCAT(' — ', LEFT(v_sumario, 300))), '. Etapa: ', fn_nc_etapa(v_etapa), '.'),
            CONCAT('/principal/naoconformidades_ocorrencia.php?codigo=', p_codigo, '&aba=', p_aba), 0);
  END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_causa_salvar`
-- @@
CREATE PROCEDURE `sp_nc_causa_salvar`(IN p_codcausa INT, IN p_codigo INT, IN p_codtipocausa INT, IN p_dscausa VARCHAR(500), IN p_usuario INT, IN p_admin TINYINT)
BEGIN
  DECLARE v_cod INT;
  CALL sp_nc_analise_validar_acesso(p_codigo, p_usuario, p_admin);
  IF TRIM(COALESCE(p_dscausa, '')) = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Descreva a causa.'; END IF;
  IF p_codcausa IS NULL OR p_codcausa = 0 THEN
    SELECT GREATEST(fn_nc_faixa('causa'), COALESCE(MAX(CODCAUSA), 0) + 1) INTO v_cod FROM nc_causas WHERE CODCAUSA >= fn_nc_faixa('causa');
    INSERT INTO nc_causas (CODCAUSA, CODOCORRENCIA, CODTIPOCAUSA, DSCAUSA) VALUES (v_cod, p_codigo, NULLIF(p_codtipocausa, 0), TRIM(p_dscausa));
    CALL sp_nc_historico_registrar(p_codigo, 'CAUSA_INCLUIDA', NULL, NULL, p_usuario, LEFT(TRIM(p_dscausa), 500));
    CALL sp_nc_etapa_plano(p_codigo, p_usuario);
  ELSE
    IF NOT EXISTS (SELECT 1 FROM nc_causas WHERE CODCAUSA = p_codcausa AND CODOCORRENCIA = p_codigo) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Causa não encontrada nesta ocorrência.'; END IF;
    UPDATE nc_causas SET CODTIPOCAUSA = NULLIF(p_codtipocausa, 0), DSCAUSA = TRIM(p_dscausa) WHERE CODCAUSA = p_codcausa;
    SET v_cod = p_codcausa;
    CALL sp_nc_historico_registrar(p_codigo, 'CAUSA_ALTERADA', NULL, NULL, p_usuario, LEFT(TRIM(p_dscausa), 500));
  END IF;
  SELECT v_cod AS CODCAUSA;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_causa_excluir`
-- @@
CREATE PROCEDURE `sp_nc_causa_excluir`(IN p_codcausa INT, IN p_codigo INT, IN p_usuario INT, IN p_admin TINYINT)
BEGIN
  DECLARE v_dsc VARCHAR(500);
  CALL sp_nc_analise_validar_acesso(p_codigo, p_usuario, p_admin);
  SELECT DSCAUSA INTO v_dsc FROM nc_causas WHERE CODCAUSA = p_codcausa AND CODOCORRENCIA = p_codigo;
  IF v_dsc IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Causa não encontrada nesta ocorrência.'; END IF;
  DELETE FROM nc_planodeacao WHERE CODCAUSA = p_codcausa;
  DELETE FROM nc_causas WHERE CODCAUSA = p_codcausa;
  CALL sp_nc_historico_registrar(p_codigo, 'CAUSA_EXCLUIDA', NULL, NULL, p_usuario, v_dsc);
  SELECT p_codcausa AS CODCAUSA;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_resposta_fornecedor`
-- @@
CREATE PROCEDURE `sp_nc_resposta_fornecedor`(IN p_codigo INT, IN p_usuario INT, IN p_sempre TINYINT)
BEGIN
  -- Data de resposta do fornecedor (decisão do usuário 22/10/2026): quando um usuário de fornecedor envia a análise (sempre) ou
  -- atualiza uma ação (só a primeira vez, se ainda não houver data).
  IF EXISTS (SELECT 1 FROM fo_fornecedorxusuario WHERE CODUSUARIO = p_usuario) THEN
    UPDATE nc_indicacao_ocorrencia SET data_de_reposta_do_fornecedor = IF(p_sempre = 1 OR data_de_reposta_do_fornecedor IS NULL, NOW(), data_de_reposta_do_fornecedor) WHERE CODOCORRENCIA = p_codigo;
  END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_plano_salvar`
-- @@
CREATE PROCEDURE `sp_nc_plano_salvar`(IN p_codplano INT, IN p_codigo INT, IN p_dados JSON, IN p_usuario INT, IN p_admin TINYINT)
BEGIN
  DECLARE v_cod INT; DECLARE v_causa INT DEFAULT CAST(fn_nc_txt(p_dados, 'CODCAUSA') AS SIGNED);
  DECLARE v_status INT DEFAULT COALESCE(CAST(fn_nc_txt(p_dados, 'STATUS') AS SIGNED), 0);
  DECLARE v_tipo INT; DECLARE v_etapa VARCHAR(500); DECLARE v_gestor INT; DECLARE v_resp_antes INT DEFAULT 0; DECLARE v_resp INT; DECLARE v_emissor INT;
  SELECT CODTIPONAOCONFORMIDADE, ETAPA, CODUSUARIO_GESTOR, CODUSUARIO_EMISSOR INTO v_tipo, v_etapa, v_gestor, v_emissor FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
  IF v_tipo IN (5, 6) THEN
    -- Planejamento: ações sem causa (como no legado); o gestor monta o plano, o responsável de cada ação atualiza status e observação.
    -- Assistência Técnica (decisões do usuário 22/10/2026): o emitente monta as ações ainda Em criação; no Plano de Ação emitente e
    -- gestor incluem/alteram; o responsável de cada ação atualiza status e observação.
    IF NOT (COALESCE(v_etapa, '') LIKE 'Plano de A%' OR (v_tipo = 6 AND v_etapa = 'Em criação')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A ocorrência não está na etapa de plano de ação.'; END IF;
    IF v_status NOT IN (0, 25, 50, 75, 100) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O status da ação deve ser 0, 25, 50, 75 ou 100%.'; END IF;
    IF COALESCE(v_gestor, 0) <> p_usuario AND p_admin = 0 AND NOT (v_tipo = 6 AND COALESCE(v_emissor, 0) = p_usuario) THEN
      IF p_codplano IS NULL OR p_codplano = 0 OR NOT EXISTS (SELECT 1 FROM nc_planodeacao WHERE CODPLANOACAO = p_codplano AND fn_nc_plano_ocorrencia(CODPLANOACAO) = p_codigo AND CODUSUARIO = p_usuario) THEN
        IF v_tipo = 6 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Só o emitente e o gestor montam o plano de ação; o responsável de cada ação atualiza o status dela.'; END IF;
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Só o gestor monta o plano de ação; o responsável de cada ação atualiza o status dela.';
      END IF;
      UPDATE nc_planodeacao SET STATUS = v_status, obs = IF(fn_nc_tem(p_dados, 'OBS'), fn_nc_txt(p_dados, 'OBS'), obs) WHERE CODPLANOACAO = p_codplano;
      CALL sp_nc_historico_registrar(p_codigo, 'PLANO_STATUS', NULL, NULL, p_usuario, CONCAT(v_status, '%'));
      CALL sp_nc_resposta_fornecedor(p_codigo, p_usuario, 0);
      CALL sp_nc_planejamento_avancar(p_codigo, p_usuario);
      SELECT p_codplano AS CODPLANOACAO;
    ELSE
      IF fn_nc_txt(p_dados, 'ACAO') IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Descreva a ação.'; END IF;
      SET v_resp = CAST(fn_nc_txt(p_dados, 'CODUSUARIO') AS SIGNED);
      IF COALESCE(v_resp, 0) = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha o responsável pela ação.'; END IF;
      IF p_codplano IS NULL OR p_codplano = 0 THEN
        SELECT GREATEST(fn_nc_faixa('plano'), COALESCE(MAX(CODPLANOACAO), 0) + 1) INTO v_cod FROM nc_planodeacao WHERE CODPLANOACAO >= fn_nc_faixa('plano');
        INSERT INTO nc_planodeacao (CODPLANOACAO, CODOCORRENCIA, CODCAUSA, ACAO, STATUS, data_de_criacao) VALUES (v_cod, p_codigo, NULL, fn_nc_txt(p_dados, 'ACAO'), v_status, NOW());
      ELSE
        SELECT COALESCE(CODUSUARIO, 0) INTO v_resp_antes FROM nc_planodeacao WHERE CODPLANOACAO = p_codplano AND fn_nc_plano_ocorrencia(CODPLANOACAO) = p_codigo;
        IF NOT EXISTS (SELECT 1 FROM nc_planodeacao WHERE CODPLANOACAO = p_codplano AND fn_nc_plano_ocorrencia(CODPLANOACAO) = p_codigo) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ação não encontrada nesta ocorrência.'; END IF;
        SET v_cod = p_codplano;
      END IF;
      UPDATE nc_planodeacao SET CODCAUSA = NULL, ACAO = fn_nc_txt(p_dados, 'ACAO'),
        PRIORIDADE = LEFT(fn_nc_txt(p_dados, 'PRIORIDADE'), 500), CODUSUARIO = v_resp,
        DATAINICIAL = CAST(fn_nc_txt(p_dados, 'DATAINICIAL') AS DATE), DATAFINAL = CAST(fn_nc_txt(p_dados, 'DATAFINAL') AS DATE),
        RECURSOS = fn_nc_txt(p_dados, 'RECURSOS'), STATUS = v_status, obs = fn_nc_txt(p_dados, 'OBS')
       WHERE CODPLANOACAO = v_cod;
      CALL sp_nc_historico_registrar(p_codigo, IF(p_codplano IS NULL OR p_codplano = 0, 'PLANO_INCLUIDO', 'PLANO_ALTERADO'), NULL, NULL, p_usuario, LEFT(fn_nc_txt(p_dados, 'ACAO'), 500));
      -- Legado: "Favor avaliar a ação sob sua responsabilidade" ao responsável da ação (quando ele muda)
      CALL sp_nc_resposta_fornecedor(p_codigo, p_usuario, 0);
      IF v_resp <> v_resp_antes AND v_etapa LIKE 'Plano de A%' THEN CALL sp_nc_alertar_usuario(p_codigo, v_resp, 'Favor avaliar a ação sob sua responsabilidade de uma não conformidade', 'analise', p_usuario); END IF;
      CALL sp_nc_planejamento_avancar(p_codigo, p_usuario);
      SELECT v_cod AS CODPLANOACAO;
    END IF;
  ELSE
  CALL sp_nc_analise_validar_acesso(p_codigo, p_usuario, p_admin);
  IF NOT EXISTS (SELECT 1 FROM nc_causas WHERE CODCAUSA = v_causa AND CODOCORRENCIA = p_codigo) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha a causa da ação.'; END IF;
  IF fn_nc_txt(p_dados, 'ACAO') IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Descreva a ação.'; END IF;
  IF v_status NOT IN (0, 25, 50, 75, 100) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O status da ação deve ser 0, 25, 50, 75 ou 100%.'; END IF;
  IF p_codplano IS NULL OR p_codplano = 0 THEN
    SELECT GREATEST(fn_nc_faixa('plano'), COALESCE(MAX(CODPLANOACAO), 0) + 1) INTO v_cod FROM nc_planodeacao WHERE CODPLANOACAO >= fn_nc_faixa('plano');
    INSERT INTO nc_planodeacao (CODPLANOACAO, CODOCORRENCIA, CODCAUSA, ACAO, STATUS, data_de_criacao) VALUES (v_cod, p_codigo, v_causa, fn_nc_txt(p_dados, 'ACAO'), v_status, NOW());
  ELSE
    IF NOT EXISTS (SELECT 1 FROM nc_planodeacao WHERE CODPLANOACAO = p_codplano AND fn_nc_plano_ocorrencia(CODPLANOACAO) = p_codigo) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ação não encontrada nesta ocorrência.'; END IF;
    SET v_cod = p_codplano;
  END IF;
  UPDATE nc_planodeacao SET CODCAUSA = v_causa, ACAO = fn_nc_txt(p_dados, 'ACAO'),
    PRIORIDADE = LEFT(fn_nc_txt(p_dados, 'PRIORIDADE'), 500), CODUSUARIO = CAST(fn_nc_txt(p_dados, 'CODUSUARIO') AS SIGNED),
    DATAINICIAL = CAST(fn_nc_txt(p_dados, 'DATAINICIAL') AS DATE), DATAFINAL = CAST(fn_nc_txt(p_dados, 'DATAFINAL') AS DATE),
    RECURSOS = fn_nc_txt(p_dados, 'RECURSOS'), STATUS = v_status, obs = fn_nc_txt(p_dados, 'OBS'),
    DATA_REPROGRAMACAO = IF(fn_nc_tem(p_dados, 'DATA_REPROGRAMACAO'), CAST(fn_nc_txt(p_dados, 'DATA_REPROGRAMACAO') AS DATETIME), DATA_REPROGRAMACAO),
    MOTIVO_REPROGRAMACAO = IF(fn_nc_tem(p_dados, 'MOTIVO_REPROGRAMACAO'), fn_nc_txt(p_dados, 'MOTIVO_REPROGRAMACAO'), MOTIVO_REPROGRAMACAO)
   WHERE CODPLANOACAO = v_cod;
  CALL sp_nc_historico_registrar(p_codigo, IF(p_codplano IS NULL OR p_codplano = 0, 'PLANO_INCLUIDO', 'PLANO_ALTERADO'), NULL, NULL, p_usuario, LEFT(fn_nc_txt(p_dados, 'ACAO'), 500));
  CALL sp_nc_resposta_fornecedor(p_codigo, p_usuario, 0);
  CALL sp_nc_etapa_plano(p_codigo, p_usuario);
  SELECT v_cod AS CODPLANOACAO;
  END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_plano_excluir`
-- @@
CREATE PROCEDURE `sp_nc_plano_excluir`(IN p_codplano INT, IN p_codigo INT, IN p_usuario INT, IN p_admin TINYINT)
BEGIN
  DECLARE v_acao MEDIUMTEXT;
  IF EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo AND CODTIPONAOCONFORMIDADE IN (5, 6)) THEN
    IF NOT EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo AND (ETAPA LIKE 'Plano de A%' OR (CODTIPONAOCONFORMIDADE = 6 AND ETAPA = 'Em criação'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A ocorrência não está na etapa de plano de ação.'; END IF;
    IF p_admin = 0 AND NOT EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo AND (CODUSUARIO_GESTOR = p_usuario OR (CODTIPONAOCONFORMIDADE = 6 AND CODUSUARIO_EMISSOR = p_usuario))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Só o gestor (e, na Assistência Técnica, o emitente) pode excluir ações do plano.'; END IF;
  ELSE
    CALL sp_nc_analise_validar_acesso(p_codigo, p_usuario, p_admin);
  END IF;
  SELECT ACAO INTO v_acao FROM nc_planodeacao WHERE CODPLANOACAO = p_codplano AND fn_nc_plano_ocorrencia(CODPLANOACAO) = p_codigo;
  IF v_acao IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ação não encontrada nesta ocorrência.'; END IF;
  DELETE FROM nc_planodeacao WHERE CODPLANOACAO = p_codplano;
  CALL sp_nc_historico_registrar(p_codigo, 'PLANO_EXCLUIDO', NULL, NULL, p_usuario, LEFT(v_acao, 500));
  SELECT p_codplano AS CODPLANOACAO;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_analise_salvar`
-- @@
CREATE PROCEDURE `sp_nc_analise_salvar`(IN p_codigo INT, IN p_dados JSON, IN p_usuario INT, IN p_enviar TINYINT, IN p_admin TINYINT)
BEGIN
  DECLARE v_etapa VARCHAR(500); DECLARE v_nova VARCHAR(500) DEFAULT 'Avaliação da Eficacia';
  CALL sp_nc_analise_validar_acesso(p_codigo, p_usuario, p_admin);
  SELECT ETAPA INTO v_etapa FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
  UPDATE nc_indicacao_ocorrencia SET
    ANALISE_DA_CAUSA = IF(fn_nc_tem(p_dados, 'ANALISE_DA_CAUSA'), fn_nc_txt(p_dados, 'ANALISE_DA_CAUSA'), ANALISE_DA_CAUSA),
    DATA_DE_CONCLUSAO_PREVISTA = IF(fn_nc_tem(p_dados, 'DATA_DE_CONCLUSAO_PREVISTA'), CAST(fn_nc_txt(p_dados, 'DATA_DE_CONCLUSAO_PREVISTA') AS DATETIME), DATA_DE_CONCLUSAO_PREVISTA)
   WHERE CODOCORRENCIA = p_codigo;
  IF p_enviar = 1 THEN
    IF EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo AND (TRIM(COALESCE(ANALISE_DA_CAUSA, '')) = '' OR DATA_DE_CONCLUSAO_PREVISTA IS NULL)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preencha a análise da causa e a data prevista de conclusão.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM nc_causas WHERE CODOCORRENCIA = p_codigo) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Obrigatório cadastrar pelo menos 1 causa.'; END IF;
    IF EXISTS (SELECT 1 FROM nc_causas c WHERE c.CODOCORRENCIA = p_codigo AND NOT EXISTS (SELECT 1 FROM nc_planodeacao p WHERE p.CODCAUSA = c.CODCAUSA)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Obrigatório cadastrar pelo menos 1 plano de ação para cada causa.';
    END IF;
    IF LEFT(COALESCE(getStatusPlanoAcaoNC(p_codigo), ''), 1) = 'N' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O plano de ação deve ser realizado antes de enviar para a análise da eficácia.'; END IF;
    UPDATE nc_indicacao_ocorrencia SET ETAPA = v_nova, data_de_envia_analise_eficacia = NOW() WHERE CODOCORRENCIA = p_codigo;
    CALL sp_nc_resposta_fornecedor(p_codigo, p_usuario, 1);
    CALL sp_nc_historico_registrar(p_codigo, 'ENVIO_EFICACIA', v_etapa, v_nova, p_usuario, NULL);
  ELSE
    CALL sp_nc_historico_registrar(p_codigo, 'ALTERACAO_ANALISE', v_etapa, v_etapa, p_usuario, NULL);
  END IF;
  SELECT CODOCORRENCIA, NRANO, ETAPA FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_eficacia_salvar`
-- @@
CREATE PROCEDURE `sp_nc_eficacia_salvar`(IN p_codigo INT, IN p_dados JSON, IN p_usuario INT, IN p_acao VARCHAR(20), IN p_exige_prazo TINYINT, IN p_admin TINYINT)
BEGIN
  DECLARE v_etapa VARCHAR(500); DECLARE v_gestor INT; DECLARE v_nova VARCHAR(500);
  -- RESULTADO: EFICAZ | ENCERRADO | NAO_EFICAZ (códigos sem acento vindos da tela; grava como o legado: 'NÃO EFICAZ')
  DECLARE v_resultado VARCHAR(500) DEFAULT UPPER(fn_nc_txt(p_dados, 'RESULTADO'));
  SELECT ETAPA, CODUSUARIO_GESTOR INTO v_etapa, v_gestor FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
  IF v_etapa IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ocorrência não encontrada.'; END IF;
  IF v_etapa NOT LIKE 'Avalia%Eficacia' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A ocorrência não está aguardando a avaliação da eficácia.'; END IF;
  IF COALESCE(v_gestor, 0) <> p_usuario AND p_admin = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Só o gestor da ocorrência pode avaliar a eficácia.'; END IF;
  UPDATE nc_indicacao_ocorrencia SET
    VERIFICACAO_DA_EFICACIA = IF(fn_nc_tem(p_dados, 'VERIFICACAO_DA_EFICACIA'), fn_nc_txt(p_dados, 'VERIFICACAO_DA_EFICACIA'), VERIFICACAO_DA_EFICACIA),
    PRAZO_ANALIZE = IF(fn_nc_tem(p_dados, 'PRAZO_ANALIZE'), CAST(fn_nc_txt(p_dados, 'PRAZO_ANALIZE') AS DATETIME), PRAZO_ANALIZE),
    CODUSUARIO_PRAZO_ANALISE = IF(fn_nc_tem(p_dados, 'CODUSUARIO_PRAZO_ANALISE'), CAST(fn_nc_txt(p_dados, 'CODUSUARIO_PRAZO_ANALISE') AS SIGNED), CODUSUARIO_PRAZO_ANALISE),
    RECUSA_DO_GESTOR = IF(fn_nc_tem(p_dados, 'RECUSA_DO_GESTOR'), LEFT(fn_nc_txt(p_dados, 'RECUSA_DO_GESTOR'), 5000), RECUSA_DO_GESTOR)
   WHERE CODOCORRENCIA = p_codigo;
  IF p_acao = 'finalizar' THEN
    IF EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo AND TRIM(COALESCE(VERIFICACAO_DA_EFICACIA, '')) = '') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Descreva a verificação da eficácia.';
    END IF;
    IF p_exige_prazo = 1 AND EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo AND (PRAZO_ANALIZE IS NULL OR COALESCE(CODUSUARIO_PRAZO_ANALISE, 0) = 0)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe a data da análise da eficácia e o responsável pela pendência.';
    END IF;
    IF v_resultado IN ('EFICAZ', 'ENCERRADO') THEN
      SET v_nova = 'Finalizado';
      UPDATE nc_indicacao_ocorrencia SET ETAPA = v_nova, data_de_finalizacao = NOW(), SITUACAO = v_resultado WHERE CODOCORRENCIA = p_codigo;
      CALL sp_nc_historico_registrar(p_codigo, IF(v_resultado = 'EFICAZ', 'FINALIZADA_EFICAZ', 'FINALIZADA_ENCERRADA'), v_etapa, v_nova, p_usuario, NULL);
    ELSEIF v_resultado IN ('NAO_EFICAZ', 'NÃO EFICAZ') THEN
      -- Planejamento (decisão do usuário 21/10/2026): não eficaz volta ao Plano de Ação; o gestor reabre ou inclui ações
      SET v_nova = IF((SELECT CODTIPONAOCONFORMIDADE FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo) IN (5, 6), 'Plano de Ação', 'Recusado pela Avaliacao da Eficacia');
      UPDATE nc_indicacao_ocorrencia SET ETAPA = v_nova, SITUACAO = 'Em andamento' WHERE CODOCORRENCIA = p_codigo;
      CALL sp_nc_historico_registrar(p_codigo, 'NAO_EFICAZ', v_etapa, v_nova, p_usuario, (SELECT RECUSA_DO_GESTOR FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo));
    ELSE
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha o resultado: eficaz, encerrada sem verificação ou não eficaz.';
    END IF;
  ELSEIF p_acao = 'recusar' THEN
    IF EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo AND TRIM(COALESCE(RECUSA_DO_GESTOR, '')) = '') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o motivo da recusa.';
    END IF;
    SET v_nova = IF((SELECT CODTIPONAOCONFORMIDADE FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo) IN (5, 6), 'Plano de Ação', 'Recusado pela Avaliacao da Eficacia');
    UPDATE nc_indicacao_ocorrencia SET ETAPA = v_nova, SITUACAO = 'Em andamento' WHERE CODOCORRENCIA = p_codigo;
    CALL sp_nc_historico_registrar(p_codigo, 'RECUSA_EFICACIA', v_etapa, v_nova, p_usuario, (SELECT RECUSA_DO_GESTOR FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo));
  ELSE
    CALL sp_nc_historico_registrar(p_codigo, 'ALTERACAO_EFICACIA', v_etapa, v_etapa, p_usuario, NULL);
  END IF;
  SELECT CODOCORRENCIA, NRANO, ETAPA FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
END
-- @@
-- c526: como as ações, o histórico do legado só aponta para a causa (CODOCORRENCIA NULL): a ocorrência vem pela causa; nome do responsável
CREATE OR REPLACE VIEW `vw_nc_planodeacao_historico` AS
SELECT h.CODPLANOACAOHISTORICO, h.CODPLANOACAO, h.CODCAUSA, h.ACAO, h.PRIORIDADE, h.CODUSUARIO, u.nome AS RESPONSAVEL, h.DATAINICIAL, h.DATAFINAL,
       h.RECURSOS, h.STATUS, h.obs, COALESCE(h.CODOCORRENCIA, c.CODOCORRENCIA) AS CODOCORRENCIA, h.tipo_de_update, h.data_do_update
  FROM nc_planodeacao_historico h LEFT JOIN nc_causas c ON c.CODCAUSA = h.CODCAUSA LEFT JOIN usuarios u ON u.codusuario = h.CODUSUARIO
