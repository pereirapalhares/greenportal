-- Migração 336 — Não Conformidades: análise da causa com IA (23/10/2026, pedido do usuário: "inicie pela análise da causa com IA,
-- lembrando que temos um cadastro de prompts e este tambem deve ser um prompt").
-- Na aba "Análise da causa e plano de ação", o responsável pede uma sugestão à IA: o greenportal monta o contexto (ocorrência,
-- análise e causas já cadastradas, tipos de causa da empresa e as NCs semelhantes já tratadas, com as causas e ações delas) e
-- põe na fila de IA (green_ia_document_jobs, serviço "ia" do Agendador) com o prompt NC_ANALISE_CAUSA (Setup › Prompts de IA).
-- A IA só sugere: nada é gravado na NC até o responsável revisar e aplicar o que marcou (causas, ações e o texto da análise),
-- e a gravação passa pelas mesmas procedures da tela (sp_nc_causa_salvar, sp_nc_plano_salvar, sp_nc_analise_salvar).
-- Tabela nova só do greenportal (não existe no legado; o legado não é alterado). Não cria índice em tabela do legado.
-- Blocos separados por "-- @@" (scripts/aplicar-migracao-336-nc-analise-ia.php).

-- @@
CREATE TABLE IF NOT EXISTS `nc_ia_analises` (
  `CODANALISE` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `CODOCORRENCIA` INT NOT NULL,
  `TIPO` VARCHAR(20) NOT NULL DEFAULT 'CAUSA',
  `CODJOB` BIGINT UNSIGNED NULL,
  `STATUS` VARCHAR(20) NOT NULL DEFAULT 'SOLICITADA',
  `HASH_CONTEXTO` CHAR(64) NULL,
  `SEMELHANTES` TEXT NULL,
  `SUGESTAO` LONGTEXT NULL,
  `CONTEXTO` LONGTEXT NULL,
  `PROMPT` LONGTEXT NULL,
  `IDIOMA` VARCHAR(5) NULL,
  `EVIDENCIAS` TEXT NULL,
  `APLICADO` LONGTEXT NULL,
  `ERRO` VARCHAR(2000) NULL,
  `CODUSUARIO` INT NOT NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ATUALIZADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ENCERRADO_EM` DATETIME NULL,
  PRIMARY KEY (`CODANALISE`),
  KEY `ix_nc_ia_analises_ocorrencia` (`CODOCORRENCIA`, `CODUSUARIO`, `STATUS`),
  KEY `ix_nc_ia_analises_job` (`CODJOB`),
  KEY `ix_nc_ia_analises_tipo` (`CODOCORRENCIA`, `TIPO`, `STATUS`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
-- Migração 337 (23/10/2026): TIPO da análise de IA — CAUSA (análise da causa, 336) ou EFICACIA (avaliação da eficácia, 337).
-- Bancos que já têm a tabela da 336 ganham a coluna aqui. Migração 338: CONTEXTO, PROMPT, IDIOMA e EVIDENCIAS (pedido que espera a
-- leitura (OCR) dos arquivos de evidência antes de ir para a IA; o serviço "ia" monta o texto final e põe na fila).
DROP PROCEDURE IF EXISTS `sp_nc_ia_tmp_coluna`
-- @@
CREATE PROCEDURE `sp_nc_ia_tmp_coluna`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nc_ia_analises' AND COLUMN_NAME = 'TIPO') THEN
    ALTER TABLE nc_ia_analises ADD COLUMN `TIPO` VARCHAR(20) NOT NULL DEFAULT 'CAUSA' AFTER `CODOCORRENCIA`, ADD KEY `ix_nc_ia_analises_tipo` (`CODOCORRENCIA`, `TIPO`, `STATUS`);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nc_ia_analises' AND COLUMN_NAME = 'EVIDENCIAS') THEN
    ALTER TABLE nc_ia_analises ADD COLUMN `CONTEXTO` LONGTEXT NULL AFTER `SUGESTAO`, ADD COLUMN `PROMPT` LONGTEXT NULL AFTER `CONTEXTO`,
      ADD COLUMN `IDIOMA` VARCHAR(5) NULL AFTER `PROMPT`, ADD COLUMN `EVIDENCIAS` TEXT NULL AFTER `IDIOMA`;
  END IF;
END
-- @@
CALL sp_nc_ia_tmp_coluna()
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_ia_tmp_coluna`
-- @@
-- Análises com a situação do job de IA (a tela consulta por aqui; resultado_json só quando concluído)
CREATE OR REPLACE VIEW `vw_nc_ia_analises` AS
SELECT a.CODANALISE, a.CODOCORRENCIA, a.TIPO, a.CODJOB, a.STATUS, a.HASH_CONTEXTO, a.SEMELHANTES, a.SUGESTAO, a.APLICADO, a.CONTEXTO, a.PROMPT, a.IDIOMA, a.EVIDENCIAS, a.ERRO, a.CODUSUARIO, u.nome AS USUARIO,
       a.CRIADO_EM, a.ATUALIZADO_EM, a.ENCERRADO_EM,
       j.status AS JOB_STATUS, j.erro AS JOB_ERRO, IF(j.status = 'CONCLUIDA', j.resultado_json, NULL) AS JOB_RESULTADO
  FROM nc_ia_analises a
  LEFT JOIN green_ia_document_jobs j ON j.codjob = a.CODJOB
  LEFT JOIN usuarios u ON u.codusuario = a.CODUSUARIO
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_ia_semelhantes`
-- @@
-- NCs semelhantes já tratadas (com pelo menos 1 causa): pontos pelos termos (p_termos = JSON ["vazamento","oleo",...]; 2 no
-- sumário, 1 na descrição/análise) e por mesma classificação, fornecedor, setor, área e tipo; bônus para as finalizadas eficazes.
-- Só entram as que somam algum ponto de texto. Leitura apenas.
CREATE PROCEDURE `sp_nc_ia_semelhantes`(IN p_codigo INT, IN p_termos JSON, IN p_limite INT)
BEGIN
  DECLARE v_forn INT; DECLARE v_setor INT; DECLARE v_area INT; DECLARE v_classif INT; DECLARE v_tipo INT;
  SELECT CODFORNECEDOR, codsetor, CODAREA, CODTIPOOCORRENCIA, CODTIPONAOCONFORMIDADE INTO v_forn, v_setor, v_area, v_classif, v_tipo
    FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
  SELECT x.CODOCORRENCIA, x.NRANO, x.CODTIPONAOCONFORMIDADE, x.IDENTIFICACAO, x.DESCRICAO, x.ANALISE_DA_CAUSA, x.ETAPA, x.SITUACAO,
         x.PONTOS_TEXTO, x.PONTOS_TEXTO + x.PONTOS_CADASTRO AS PONTOS
    FROM (
      SELECT o.CODOCORRENCIA, o.NRANO, o.CODTIPONAOCONFORMIDADE, LEFT(o.IDENTIFICACAO, 300) AS IDENTIFICACAO, LEFT(o.DESCRICAO, 700) AS DESCRICAO,
             LEFT(o.ANALISE_DA_CAUSA, 700) AS ANALISE_DA_CAUSA, fn_nc_etapa(o.ETAPA) AS ETAPA, o.SITUACAO,
             (SELECT COALESCE(SUM(
                  IF(CONVERT(COALESCE(o.IDENTIFICACAO, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE CONCAT('%', t.termo, '%'), 2, 0)
                + IF(CONVERT(COALESCE(o.DESCRICAO, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE CONCAT('%', t.termo, '%'), 1, 0)
                + IF(CONVERT(COALESCE(o.ANALISE_DA_CAUSA, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE CONCAT('%', t.termo, '%'), 1, 0)), 0)
                FROM JSON_TABLE(p_termos, '$[*]' COLUMNS (termo VARCHAR(60) PATH '$')) t WHERE CHAR_LENGTH(COALESCE(t.termo, '')) >= 3) AS PONTOS_TEXTO,
             IF(v_classif > 0 AND o.CODTIPOOCORRENCIA = v_classif AND o.CODTIPONAOCONFORMIDADE = v_tipo, 2, 0)
             + IF(v_forn > 0 AND o.CODFORNECEDOR = v_forn, 3, 0) + IF(v_setor > 0 AND o.codsetor = v_setor, 1, 0)
             + IF(v_area > 0 AND o.CODAREA = v_area, 1, 0) + IF(o.CODTIPONAOCONFORMIDADE = v_tipo, 1, 0)
             + IF(o.ETAPA = 'Finalizado' AND COALESCE(o.SITUACAO, '') LIKE 'Efica%', 2, 0) AS PONTOS_CADASTRO
        FROM nc_indicacao_ocorrencia o
       WHERE o.CODOCORRENCIA <> p_codigo AND EXISTS (SELECT 1 FROM nc_causas c WHERE c.CODOCORRENCIA = o.CODOCORRENCIA)
    ) x
   WHERE x.PONTOS_TEXTO > 0
   ORDER BY PONTOS DESC, x.CODOCORRENCIA DESC
   LIMIT p_limite;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_ia_analise_registrar`
-- @@
-- Nova análise pedida (o job já está na fila): as anteriores ainda abertas do mesmo usuário, nesta NC e do mesmo tipo, ficam SUBSTITUIDA.
-- p_tipo: CAUSA (análise da causa) ou EFICACIA (avaliação da eficácia, migração 337).
CREATE PROCEDURE `sp_nc_ia_analise_registrar`(IN p_codigo INT, IN p_tipo VARCHAR(20), IN p_codjob BIGINT, IN p_usuario INT, IN p_hash CHAR(64), IN p_semelhantes TEXT)
BEGIN
  DECLARE v_cod BIGINT;
  IF NOT EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ocorrência não encontrada.'; END IF;
  IF p_tipo NOT IN ('CAUSA', 'EFICACIA') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipo de análise de IA inválido.'; END IF;
  UPDATE nc_ia_analises SET STATUS = 'SUBSTITUIDA', ENCERRADO_EM = NOW()
   WHERE CODOCORRENCIA = p_codigo AND TIPO = p_tipo AND CODUSUARIO = p_usuario AND STATUS IN ('SOLICITADA', 'SUGERIDA', 'EXTRAINDO');
  INSERT INTO nc_ia_analises (CODOCORRENCIA, TIPO, CODJOB, STATUS, HASH_CONTEXTO, SEMELHANTES, CODUSUARIO) VALUES (p_codigo, p_tipo, p_codjob, 'SOLICITADA', p_hash, p_semelhantes, p_usuario);
  SET v_cod = LAST_INSERT_ID();
  SELECT v_cod AS CODANALISE;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_ia_analise_resultado`
-- @@
-- Resultado do job de IA lido pela tela: guarda a sugestão já conferida (SUGERIDA) ou o erro (ERRO).
CREATE PROCEDURE `sp_nc_ia_analise_resultado`(IN p_codanalise BIGINT, IN p_usuario INT, IN p_sugestao LONGTEXT, IN p_erro VARCHAR(2000))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM nc_ia_analises WHERE CODANALISE = p_codanalise AND CODUSUARIO = p_usuario) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Análise de IA não encontrada.'; END IF;
  UPDATE nc_ia_analises SET STATUS = IF(p_erro IS NULL OR p_erro = '', 'SUGERIDA', 'ERRO'), SUGESTAO = COALESCE(p_sugestao, SUGESTAO),
         ERRO = NULLIF(p_erro, ''), ENCERRADO_EM = IF(p_erro IS NULL OR p_erro = '', ENCERRADO_EM, NOW())
   WHERE CODANALISE = p_codanalise AND STATUS IN ('SOLICITADA', 'SUGERIDA');
  SELECT CODANALISE, STATUS FROM nc_ia_analises WHERE CODANALISE = p_codanalise;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_ia_analise_encerrar`
-- @@
-- Encerra a sugestão: APLICADA (com o que foi gravado) ou DESCARTADA; registra no histórico da NC.
CREATE PROCEDURE `sp_nc_ia_analise_encerrar`(IN p_codanalise BIGINT, IN p_codigo INT, IN p_status VARCHAR(20), IN p_aplicado LONGTEXT, IN p_resumo TEXT, IN p_usuario INT)
BEGIN
  IF p_status NOT IN ('APLICADA', 'DESCARTADA') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Situação inválida para a análise de IA.'; END IF;
  IF NOT EXISTS (SELECT 1 FROM nc_ia_analises WHERE CODANALISE = p_codanalise AND CODOCORRENCIA = p_codigo AND CODUSUARIO = p_usuario AND STATUS IN ('SOLICITADA', 'SUGERIDA')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta sugestão da IA já foi encerrada ou não é sua. Peça uma nova sugestão.';
  END IF;
  UPDATE nc_ia_analises SET STATUS = p_status, APLICADO = p_aplicado, ENCERRADO_EM = NOW() WHERE CODANALISE = p_codanalise;
  CALL sp_nc_historico_registrar(p_codigo, CONCAT(IF((SELECT TIPO FROM nc_ia_analises WHERE CODANALISE = p_codanalise) = 'EFICACIA', 'IA_EFICACIA_', 'IA_ANALISE_'), IF(p_status = 'APLICADA', 'APLICADA', 'DESCARTADA')), NULL, NULL, p_usuario, LEFT(p_resumo, 500));
  SELECT p_codanalise AS CODANALISE, p_status AS STATUS;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_ia_analise_concluida`
-- @@
-- Chamada pelo serviço "ia" do Agendador (DocumentAiQueueService) quando termina um pedido NC_ANALISE_CAUSA (23/10/2026, decisão
-- do usuário: o pedido roda em segundo plano e a resposta chega nos Alertas). Avisa quem pediu: pronta → o alerta abre a NC na aba
-- da análise com a revisão da IA aberta; erro → a análise fica ERRO e o alerta diz que pode pedir de novo. Erro aqui não para o serviço.
CREATE PROCEDURE `sp_nc_ia_analise_concluida`(IN p_codjob BIGINT)
BEGIN
  DECLARE v_cod BIGINT; DECLARE v_nc INT; DECLARE v_usuario INT; DECLARE v_status VARCHAR(20); DECLARE v_erro VARCHAR(2000);
  DECLARE v_numero, v_sumario, v_titulo VARCHAR(500); DECLARE v_tipo VARCHAR(20);
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
  SELECT a.CODANALISE, a.CODOCORRENCIA, a.CODUSUARIO, a.TIPO, j.status, j.erro INTO v_cod, v_nc, v_usuario, v_tipo, v_status, v_erro
    FROM nc_ia_analises a JOIN green_ia_document_jobs j ON j.codjob = a.CODJOB
   WHERE a.CODJOB = p_codjob AND a.STATUS = 'SOLICITADA' LIMIT 1;
  IF v_cod IS NOT NULL AND v_status IN ('CONCLUIDA', 'ERRO') THEN
    IF v_status = 'ERRO' THEN
      UPDATE nc_ia_analises SET STATUS = 'ERRO', ERRO = LEFT(COALESCE(NULLIF(v_erro, ''), 'Falha na análise da IA.'), 2000), ENCERRADO_EM = NOW() WHERE CODANALISE = v_cod;
    END IF;
    SELECT CONVERT(COALESCE(NULLIF(NRANO, ''), CODOCORRENCIA) USING utf8mb4), CONVERT(COALESCE(IDENTIFICACAO, '') USING utf8mb4)
      INTO v_numero, v_sumario FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = v_nc;
    SET v_titulo = IF(v_tipo = 'EFICACIA',
      IF(v_status = 'CONCLUIDA', 'o parecer da IA para a avaliação da eficácia está pronto', 'a IA não conseguiu avaliar a eficácia'),
      IF(v_status = 'CONCLUIDA', 'a sugestão da IA para a análise da causa está pronta', 'a IA não conseguiu fazer a análise da causa'));
    IF EXISTS (SELECT 1 FROM usuarios WHERE codusuario = v_usuario AND ativo = 1) THEN
      INSERT INTO alertas (codusuario, titulo, mensagem, link, lido)
      VALUES (v_usuario, LEFT(CONCAT('NC ', v_numero, ': ', IF(EXISTS (SELECT 1 FROM fo_fornecedorxusuario fu WHERE fu.CODUSUARIO = v_usuario), CONCAT('Sr. Fornecedor, ', v_titulo), v_titulo)), 180),
              LEFT(CONCAT('Não conformidade ', v_numero, IF(v_sumario = '', '', CONCAT(' — ', LEFT(v_sumario, 200))), '. ',
                IF(v_status = 'CONCLUIDA', IF(v_tipo = 'EFICACIA', 'Abra para revisar o parecer (nada foi gravado na NC; o resultado continua sendo decisão do gestor).', 'Abra para revisar a sugestão e aplicar o que quiser (nada foi gravado na NC).'),
                   CONCAT('Erro: ', TRIM(TRAILING '.' FROM LEFT(COALESCE(NULLIF(v_erro, ''), 'falha na análise'), 300)), '. Você pode pedir uma nova análise.'))), 1000),
              CONCAT('/principal/naoconformidades_ocorrencia.php?codigo=', v_nc, IF(v_tipo = 'EFICACIA', '&aba=eficacia', '&aba=analise'), IF(v_status = 'CONCLUIDA', CONCAT('&ia=', v_cod), '')), 0);
    END IF;
  END IF;
  SELECT v_cod AS CODANALISE;
END
