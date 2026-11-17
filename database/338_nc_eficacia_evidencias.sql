-- Migração 338 — Não Conformidades: a IA da eficácia lê o conteúdo das evidências (23/10/2026, escolha do usuário: "B").
-- Ao pedir o parecer, os arquivos de evidência anexados (GED: evidências da análise e arquivos de cada ação; PDF, imagem e Word)
-- vão para a fila de leitura de texto (OCR, green_ocr_jobs, serviço "ocr" do Agendador; o mesmo arquivo já lido é reaproveitado).
-- O pedido fica EXTRAINDO, com o contexto e o prompt guardados; o serviço "ia" (NaoConformidadeEficaciaIa::prepararPendentes),
-- quando todas as leituras terminam (ou depois de 2 horas), junta o texto lido ao contexto e põe o pedido na fila de IA.
-- A resposta continua chegando nos Alertas. Colunas novas de nc_ia_analises e a view: database/336_nc_analise_ia.sql.
-- Blocos separados por "-- @@" (scripts/aplicar-migracao-338-nc-eficacia-evidencias.php).

-- @@
DROP PROCEDURE IF EXISTS `sp_nc_ia_analise_extrair`
-- @@
-- Pedido que espera a leitura das evidências: guarda contexto, prompt, idioma e a lista de leituras (JSON [{codjob, arquivo, origem}]).
CREATE PROCEDURE `sp_nc_ia_analise_extrair`(IN p_codigo INT, IN p_tipo VARCHAR(20), IN p_usuario INT, IN p_hash CHAR(64), IN p_semelhantes TEXT,
  IN p_contexto LONGTEXT, IN p_prompt LONGTEXT, IN p_idioma VARCHAR(5), IN p_evidencias TEXT)
BEGIN
  DECLARE v_cod BIGINT;
  IF NOT EXISTS (SELECT 1 FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ocorrência não encontrada.'; END IF;
  IF p_tipo NOT IN ('CAUSA', 'EFICACIA') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipo de análise de IA inválido.'; END IF;
  UPDATE nc_ia_analises SET STATUS = 'SUBSTITUIDA', ENCERRADO_EM = NOW()
   WHERE CODOCORRENCIA = p_codigo AND TIPO = p_tipo AND CODUSUARIO = p_usuario AND STATUS IN ('SOLICITADA', 'SUGERIDA', 'EXTRAINDO');
  INSERT INTO nc_ia_analises (CODOCORRENCIA, TIPO, CODJOB, STATUS, HASH_CONTEXTO, SEMELHANTES, CONTEXTO, PROMPT, IDIOMA, EVIDENCIAS, CODUSUARIO)
  VALUES (p_codigo, p_tipo, NULL, 'EXTRAINDO', p_hash, p_semelhantes, p_contexto, p_prompt, IF(p_idioma IN ('pt', 'en', 'es'), p_idioma, 'pt'), p_evidencias, p_usuario);
  SET v_cod = LAST_INSERT_ID();
  SELECT v_cod AS CODANALISE;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_ia_analise_iniciar`
-- @@
-- Leituras terminadas: o pedido (já na fila de IA, p_codjob) passa a SOLICITADA; o contexto guardado é limpo.
CREATE PROCEDURE `sp_nc_ia_analise_iniciar`(IN p_codanalise BIGINT, IN p_codjob BIGINT)
BEGIN
  UPDATE nc_ia_analises SET CODJOB = p_codjob, STATUS = 'SOLICITADA', CONTEXTO = NULL, PROMPT = NULL
   WHERE CODANALISE = p_codanalise AND STATUS = 'EXTRAINDO';
  SELECT CODANALISE, STATUS FROM nc_ia_analises WHERE CODANALISE = p_codanalise;
END
