-- Migração 366 — Dashboards pessoal e da empresa: Não Conformidades e Fornecedores (16/11/2026).
-- Só views (leitura por sp_crud_select). Blocos separados por "-- @@"; cada bloco tem a marca [NC] ou [FO] na primeira linha:
-- o script só roda [NC] onde existem nc_indicacao_ocorrencia e fn_nc_etapa (migração 321) e [FO] onde existe
-- vw_homologacao_painel_itens (migração 298). Pode ser reaplicada.
--
-- Quem age em cada etapa (regras do fluxo da 321):
--   Em criação / Recusado pelo Gestor ........................ emitente
--   Com o Gestor / Avaliação da Eficacia ...................... gestor
--   Em Analise da Causa Raiz / Plano de Ação / Recusado pela Avaliacao da Eficacia
--       ...................................................... responsável (Planejamento e Assistência Técnica: o gestor; sem gestor, o emitente)
-- A etapa é lida com LIKE porque o legado gravou parte com a acentuação quebrada (fn_nc_etapa corrige só a exibição).

-- @@ [NC]
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `vw_dash_nc_abertas` AS
SELECT x.CODOCORRENCIA, x.NUMERO, x.SUMARIO, x.CODTIPO, x.TIPO, x.ETAPA, x.FASE, x.CODUNIDADE, x.DSCUNIDADE, x.CODFORNECEDOR, x.RAZAOSOCIAL,
       x.CODUSUARIO_EMISSOR, x.CODUSUARIO_GESTOR, x.CODUSUARIO_RESPONSAVEL, x.CODUSUARIO_ACAO,
       CONVERT(COALESCE(NULLIF(TRIM(ua.nome), ''), IF(x.CODUSUARIO_ACAO IS NULL, '', CONCAT('Usuário ', x.CODUSUARIO_ACAO))) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS USUARIO_ACAO,
       CAST(COALESCE(ua.ativo, 0) AS SIGNED) AS USUARIO_ACAO_ATIVO,
       x.CRIADA_EM, DATEDIFF(CURDATE(), x.CRIADA_EM) AS IDADE_DIAS,
       x.PREVISTA, IF(x.PREVISTA IS NOT NULL AND x.PREVISTA < CURDATE(), 1, 0) AS ATRASADA,
       DATEDIFF(CURDATE(), GREATEST(COALESCE(h.ULTIMA, '1900-01-01'), COALESCE(x.CRIADA_EM, '1900-01-01'))) AS DIAS_PARADA
  FROM (
    SELECT o.CODOCORRENCIA,
           CONVERT(COALESCE(NULLIF(TRIM(o.NRANO), ''), o.CODOCORRENCIA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NUMERO,
           CONVERT(LEFT(TRIM(REPLACE(REPLACE(COALESCE(o.IDENTIFICACAO, ''), CHAR(13), ' '), CHAR(10), ' ')), 200) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS SUMARIO,
           o.CODTIPONAOCONFORMIDADE AS CODTIPO,
           CONVERT(COALESCE(t.DSCTIPONAOCONFORMIDADE, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS TIPO,
           CONVERT(fn_nc_etapa(o.ETAPA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS ETAPA,
           CONVERT(CASE WHEN o.ETAPA LIKE 'Em cria%' OR o.ETAPA LIKE 'Recusado pelo Gestor%' THEN 'CRIACAO'
                        WHEN o.ETAPA LIKE 'Com o Gestor%' THEN 'GESTOR'
                        WHEN o.ETAPA LIKE 'Avalia%Efic%' THEN 'EFICACIA'
                        WHEN o.ETAPA LIKE 'Em Anal%' OR o.ETAPA LIKE 'Plano de A%' OR o.ETAPA LIKE 'Recusado pela Avalia%' THEN 'ANALISE'
                        ELSE 'OUTRA' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS FASE,
           o.codunidade AS CODUNIDADE,
           CONVERT(COALESCE(un.DSCUNIDADE, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS DSCUNIDADE,
           NULLIF(o.CODFORNECEDOR, 0) AS CODFORNECEDOR,
           CONVERT(COALESCE(fo.RAZAOSOCIAL, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS RAZAOSOCIAL,
           NULLIF(o.CODUSUARIO_EMISSOR, 0) AS CODUSUARIO_EMISSOR, NULLIF(o.CODUSUARIO_GESTOR, 0) AS CODUSUARIO_GESTOR,
           NULLIF(o.CODUSUARIO_RESPONSAVEL, 0) AS CODUSUARIO_RESPONSAVEL,
           CASE WHEN o.ETAPA LIKE 'Em cria%' OR o.ETAPA LIKE 'Recusado pelo Gestor%' THEN NULLIF(o.CODUSUARIO_EMISSOR, 0)
                WHEN o.ETAPA LIKE 'Com o Gestor%' OR o.ETAPA LIKE 'Avalia%Efic%' THEN NULLIF(o.CODUSUARIO_GESTOR, 0)
                WHEN o.CODTIPONAOCONFORMIDADE IN (5, 6) THEN COALESCE(NULLIF(o.CODUSUARIO_GESTOR, 0), NULLIF(o.CODUSUARIO_EMISSOR, 0))
                ELSE NULLIF(o.CODUSUARIO_RESPONSAVEL, 0) END AS CODUSUARIO_ACAO,
           CAST(COALESCE(o.data_de_criacao, o.DATA_OCORRENCIA) AS DATE) AS CRIADA_EM,
           IF(o.DATA_DE_CONCLUSAO_PREVISTA > '1900-01-01', CAST(o.DATA_DE_CONCLUSAO_PREVISTA AS DATE), NULL) AS PREVISTA
      FROM nc_indicacao_ocorrencia o
      LEFT JOIN nc_tipo_de_naoconformidade t ON t.CODTIPONAOCONFORMIDADE = o.CODTIPONAOCONFORMIDADE
      LEFT JOIN unidades un ON un.CODUNIDADE = o.codunidade
      LEFT JOIN fo_fornecedores fo ON fo.CODFORNECEDOR = o.CODFORNECEDOR
     WHERE COALESCE(o.ETAPA, '') <> 'Finalizado'
  ) x
  LEFT JOIN usuarios ua ON ua.codusuario = x.CODUSUARIO_ACAO
  LEFT JOIN (SELECT CODOCORRENCIA, MAX(CRIADO_EM) AS ULTIMA FROM nc_workflow_historico GROUP BY CODOCORRENCIA) h ON h.CODOCORRENCIA = x.CODOCORRENCIA

-- @@ [NC]
-- Abertas (criadas) e finalizadas por mês, nos últimos 12 meses (o mês atual incluído).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `vw_dash_nc_mes` AS
SELECT m.MES,
       (SELECT COUNT(*) FROM nc_indicacao_ocorrencia o
         WHERE COALESCE(o.data_de_criacao, o.DATA_OCORRENCIA) >= m.INICIO AND COALESCE(o.data_de_criacao, o.DATA_OCORRENCIA) < m.FIM) AS ABERTAS,
       (SELECT COUNT(*) FROM nc_indicacao_ocorrencia o
         WHERE COALESCE(o.ETAPA, '') = 'Finalizado' AND o.data_de_finalizacao >= m.INICIO AND o.data_de_finalizacao < m.FIM) AS FINALIZADAS
  FROM (SELECT CONVERT(DATE_FORMAT(d.INICIO, '%Y-%m') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS MES, d.INICIO, DATE_ADD(d.INICIO, INTERVAL 1 MONTH) AS FIM
          FROM (SELECT DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL n.N MONTH) AS INICIO
                  FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
                        UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11) n) d) m

-- @@ [FO]
-- Um registro por fornecedor ativo: responsável, próxima avaliação e itens do Painel de Homologação por cor (regra única da 298),
-- e as NCs abertas do fornecedor.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `vw_dash_fornecedores` AS
SELECT f.codfornecedor AS CODFORNECEDOR,
       CONVERT(COALESCE(NULLIF(TRIM(f.razaosocial), ''), CONCAT('Fornecedor ', f.codfornecedor)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS RAZAOSOCIAL,
       f.codunidade AS CODUNIDADE,
       CONVERT(COALESCE(un.DSCUNIDADE, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS DSCUNIDADE,
       NULLIF(f.codusuario_responsavel_fornecedor, 0) AS CODUSUARIO_RESPONSAVEL,
       CONVERT(COALESCE(NULLIF(TRIM(ur.nome), ''), '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS RESPONSAVEL,
       CAST(COALESCE(ur.ativo, 0) AS SIGNED) AS RESPONSAVEL_ATIVO,
       IF(f.data_da_proxima_avaliacao > '1900-01-01', CAST(f.data_da_proxima_avaliacao AS DATE), NULL) AS PROXIMA_AVALIACAO,
       CONVERT(CASE WHEN NOT (f.data_da_proxima_avaliacao > '1900-01-01') OR f.data_da_proxima_avaliacao IS NULL THEN 'SEM_DATA'
                    WHEN f.data_da_proxima_avaliacao < CURDATE() THEN 'VENCIDA'
                    WHEN f.data_da_proxima_avaliacao <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'VENCE_30'
                    ELSE 'EM_DIA' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS AVALIACAO,
       COALESCE(i.DOC_VERMELHO, 0) AS DOC_VERMELHO, COALESCE(i.DOC_AMARELO, 0) AS DOC_AMARELO, COALESCE(i.DOC_BRANCO, 0) AS DOC_BRANCO,
       COALESCE(i.DOC_VERDE, 0) AS DOC_VERDE, COALESCE(i.DOC_TOTAL, 0) AS DOC_TOTAL,
       COALESCE(i.FUNC_VERMELHO, 0) AS FUNC_VERMELHO, COALESCE(i.FUNC_AMARELO, 0) AS FUNC_AMARELO,
       COALESCE(i.AUD_VERMELHO, 0) AS AUD_VERMELHO,
       COALESCE(n.NC_ABERTAS, 0) AS NC_ABERTAS
  FROM fo_fornecedores f
  LEFT JOIN unidades un ON un.CODUNIDADE = f.codunidade
  LEFT JOIN usuarios ur ON ur.codusuario = f.codusuario_responsavel_fornecedor
  LEFT JOIN (SELECT p.CODFORNECEDOR,
                    SUM(p.CATEGORIA = 'DOCUMENTO' AND p.COR = 'VERMELHO') AS DOC_VERMELHO,
                    SUM(p.CATEGORIA = 'DOCUMENTO' AND p.COR = 'AMARELO') AS DOC_AMARELO,
                    SUM(p.CATEGORIA = 'DOCUMENTO' AND p.COR = 'BRANCO') AS DOC_BRANCO,
                    SUM(p.CATEGORIA = 'DOCUMENTO' AND p.COR = 'VERDE') AS DOC_VERDE,
                    SUM(p.CATEGORIA = 'DOCUMENTO' AND p.COR <> 'NEUTRO') AS DOC_TOTAL,
                    SUM(p.CATEGORIA IN ('DOCFUNC', 'INTEGRACAO', 'TREINAMENTO') AND p.COR = 'VERMELHO') AS FUNC_VERMELHO,
                    SUM(p.CATEGORIA IN ('DOCFUNC', 'INTEGRACAO', 'TREINAMENTO') AND p.COR = 'AMARELO') AS FUNC_AMARELO,
                    SUM(p.CATEGORIA = 'AUDITORIA' AND p.COR = 'VERMELHO') AS AUD_VERMELHO
               FROM vw_homologacao_painel_itens p GROUP BY p.CODFORNECEDOR) i ON i.CODFORNECEDOR = f.codfornecedor
  LEFT JOIN (SELECT o.CODFORNECEDOR, COUNT(*) AS NC_ABERTAS FROM nc_indicacao_ocorrencia o
              WHERE o.CODFORNECEDOR > 0 AND COALESCE(o.ETAPA, '') <> 'Finalizado' GROUP BY o.CODFORNECEDOR) n ON n.CODFORNECEDOR = f.codfornecedor
 WHERE LOWER(TRIM(COALESCE(f.ativadofor, ''))) IN ('sim', 's', '1')

-- @@ [FO]
-- Itens do Painel de Homologação (fornecedores ativos) por categoria e cor.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `vw_dash_fornecedor_itens` AS
SELECT p.CATEGORIA, p.COR, COUNT(*) AS TOTAL, COUNT(DISTINCT p.CODFORNECEDOR) AS FORNECEDORES
  FROM vw_homologacao_painel_itens p
  JOIN fo_fornecedores f ON f.codfornecedor = p.CODFORNECEDOR AND LOWER(TRIM(COALESCE(f.ativadofor, ''))) IN ('sim', 's', '1')
 GROUP BY p.CATEGORIA, p.COR

-- @@ [NC]
-- Resumo leve para a Visão geral do Dashboard da empresa (uma linha, sem junções).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `vw_dash_nc_resumo` AS
SELECT COUNT(*) AS ABERTAS,
       COALESCE(SUM(o.ETAPA LIKE 'Em cria%' OR o.ETAPA LIKE 'Recusado pelo Gestor%'), 0) AS CRIACAO,
       COALESCE(SUM(o.ETAPA LIKE 'Com o Gestor%'), 0) AS GESTOR,
       COALESCE(SUM(o.ETAPA LIKE 'Em Anal%' OR o.ETAPA LIKE 'Plano de A%' OR o.ETAPA LIKE 'Recusado pela Avalia%'), 0) AS ANALISE,
       COALESCE(SUM(o.ETAPA LIKE 'Avalia%Efic%'), 0) AS EFICACIA,
       COALESCE(SUM(COALESCE(o.data_de_criacao, o.DATA_OCORRENCIA) < DATE_SUB(CURDATE(), INTERVAL 1 YEAR)), 0) AS MAIS_1_ANO,
       COALESCE(SUM(o.DATA_DE_CONCLUSAO_PREVISTA > '1900-01-01' AND o.DATA_DE_CONCLUSAO_PREVISTA < CURDATE()), 0) AS ATRASADAS,
       COALESCE(SUM(COALESCE(o.data_de_criacao, o.DATA_OCORRENCIA) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)), 0) AS NOVAS_30
  FROM nc_indicacao_ocorrencia o
 WHERE COALESCE(o.ETAPA, '') <> 'Finalizado'

-- @@ [FO]
-- Resumo leve dos fornecedores ativos para a Visão geral (fo_fornecedores, responsável e as NCs abertas). SEM_RESPONSAVEL = sem responsável
-- ou com responsável inativo (mesma regra da aba Fornecedores).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `vw_dash_fornecedor_resumo` AS
SELECT COUNT(*) AS ATIVOS,
       COALESCE(SUM(COALESCE(f.codusuario_responsavel_fornecedor, 0) = 0 OR COALESCE(ur.ativo, 0) <> 1), 0) AS SEM_RESPONSAVEL,
       COALESCE(SUM(f.data_da_proxima_avaliacao > '1900-01-01' AND f.data_da_proxima_avaliacao < CURDATE()), 0) AS AVALIACAO_VENCIDA,
       COALESCE(SUM(f.data_da_proxima_avaliacao > '1900-01-01' AND f.data_da_proxima_avaliacao >= CURDATE() AND f.data_da_proxima_avaliacao <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)), 0) AS AVALIACAO_30,
       COALESCE(SUM(f.data_da_proxima_avaliacao IS NULL OR NOT (f.data_da_proxima_avaliacao > '1900-01-01')), 0) AS SEM_DATA_AVALIACAO,
       (SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE o.CODFORNECEDOR > 0 AND COALESCE(o.ETAPA, '') <> 'Finalizado') AS NC_ABERTAS
  FROM fo_fornecedores f
  LEFT JOIN usuarios ur ON ur.codusuario = f.codusuario_responsavel_fornecedor
 WHERE LOWER(TRIM(COALESCE(f.ativadofor, ''))) IN ('sim', 's', '1')
