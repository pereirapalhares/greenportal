-- Migração 337 — Não Conformidades: avaliação da eficácia com IA (23/10/2026, escolha do usuário).
-- Na aba "Verificação da eficácia", o gestor pede o parecer da IA: o greenportal monta o contexto (ocorrência, análise da causa,
-- causas, ações com status/prazos/observações e os arquivos de evidência anexados, e as NCs parecidas abertas DEPOIS desta, que
-- indicam recorrência) e põe na fila de IA (serviço "ia" do Agendador) com o prompt NC_EFICACIA (Setup › Prompts de IA).
-- A resposta chega nos Alertas. A IA só dá o parecer: o gestor revisa, pode levar o texto para a verificação da eficácia e o
-- resultado (eficaz / não eficaz) continua sendo aplicado por ele no botão "Aplicar o resultado".
-- Usa a tabela nc_ia_analises da migração 336 (TIPO = 'EFICACIA'); o runner da 337 grava também database/336_nc_analise_ia.sql.
-- Blocos separados por "-- @@" (scripts/aplicar-migracao-337-nc-eficacia-ia.php).

-- @@
DROP PROCEDURE IF EXISTS `sp_nc_ia_recorrencias`
-- @@
-- NCs parecidas abertas depois desta (possível recorrência): mesmos termos do sumário/descrição (p_termos = JSON) ou mesma
-- classificação + fornecedor/setor/área. Pontos como em sp_nc_ia_semelhantes; DEPOIS_DO_PLANO = 1 quando foi aberta depois do envio
-- desta NC para a avaliação da eficácia (indício mais forte de que as ações não resolveram). Leitura apenas.
CREATE PROCEDURE `sp_nc_ia_recorrencias`(IN p_codigo INT, IN p_termos JSON, IN p_limite INT)
BEGIN
  DECLARE v_forn INT; DECLARE v_setor INT; DECLARE v_area INT; DECLARE v_classif INT; DECLARE v_tipo INT; DECLARE v_data DATETIME; DECLARE v_envio DATETIME;
  SELECT CODFORNECEDOR, codsetor, CODAREA, CODTIPOOCORRENCIA, CODTIPONAOCONFORMIDADE, COALESCE(DATA_OCORRENCIA, data_de_criacao), data_de_envia_analise_eficacia
    INTO v_forn, v_setor, v_area, v_classif, v_tipo, v_data, v_envio
    FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
  SELECT x.CODOCORRENCIA, x.NRANO, x.IDENTIFICACAO, x.DESCRICAO, x.DATA, x.ETAPA, x.SITUACAO, x.DEPOIS_DO_PLANO, x.PONTOS_TEXTO, x.PONTOS_TEXTO + x.PONTOS_CADASTRO AS PONTOS
    FROM (
      SELECT o.CODOCORRENCIA, o.NRANO, LEFT(o.IDENTIFICACAO, 300) AS IDENTIFICACAO, LEFT(o.DESCRICAO, 500) AS DESCRICAO, COALESCE(o.DATA_OCORRENCIA, o.data_de_criacao) AS DATA,
             fn_nc_etapa(o.ETAPA) AS ETAPA, o.SITUACAO, IF(v_envio IS NOT NULL AND COALESCE(o.DATA_OCORRENCIA, o.data_de_criacao) >= v_envio, 1, 0) AS DEPOIS_DO_PLANO,
             (SELECT COALESCE(SUM(
                  IF(CONVERT(COALESCE(o.IDENTIFICACAO, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE CONCAT('%', t.termo, '%'), 2, 0)
                + IF(CONVERT(COALESCE(o.DESCRICAO, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE CONCAT('%', t.termo, '%'), 1, 0)), 0)
                FROM JSON_TABLE(p_termos, '$[*]' COLUMNS (termo VARCHAR(60) PATH '$')) t WHERE CHAR_LENGTH(COALESCE(t.termo, '')) >= 3) AS PONTOS_TEXTO,
             IF(v_classif > 0 AND o.CODTIPOOCORRENCIA = v_classif AND o.CODTIPONAOCONFORMIDADE = v_tipo, 2, 0)
             + IF(v_forn > 0 AND o.CODFORNECEDOR = v_forn, 3, 0) + IF(v_setor > 0 AND o.codsetor = v_setor, 1, 0) + IF(v_area > 0 AND o.CODAREA = v_area, 1, 0) AS PONTOS_CADASTRO
        FROM nc_indicacao_ocorrencia o
       WHERE o.CODOCORRENCIA <> p_codigo AND v_data IS NOT NULL AND COALESCE(o.DATA_OCORRENCIA, o.data_de_criacao) > v_data
    ) x
   WHERE x.PONTOS_TEXTO >= 2 OR (x.PONTOS_TEXTO > 0 AND x.PONTOS_CADASTRO >= 3)
   ORDER BY x.DEPOIS_DO_PLANO DESC, PONTOS DESC, x.CODOCORRENCIA DESC
   LIMIT p_limite;
END
