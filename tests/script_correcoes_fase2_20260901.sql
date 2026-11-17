-- =====================================================================
-- Green Portal - Script de correções FASE 2 (performance/tuning)
-- Gerado em: 01/09/2026
-- Bases afetadas: gmodelo_dbp (indices) + servidor (redo log, global)
-- Pré-requisito: script_correcoes_gmodelo_dbp_20260901.sql já aplicado
--                 (confirmado via reavaliação em 01/09/2026 - OK)
--
-- Este script é aditivo (CREATE INDEX / SET PERSIST). Nenhuma tabela
-- tem linhas removidas ou alteradas. Todas as ALTER TABLE abaixo foram
-- avaliadas em tabelas pequenas (< 11 mil linhas, < 3 MB) - execução
-- deve ser praticamente instantânea.
--
-- Evidência usada para cada mudança: performance_schema.
-- events_statements_summary_by_digest (janela de coleta: 26/08 a
-- 01/09/2026, ~6 dias de tráfego real).
-- =====================================================================


-- =====================================================================
-- PARTE 1: Índice para acelerar a subquery EXISTS usada no contador
--          "minhas ocorrências pendentes" (nc_indicacao_ocorrencia).
--
-- Query real observada (290 execuções em 6 dias, 100% sem uso de
-- índice, tempo total 5.42s):
--   SELECT COUNT(*) AS total FROM nc_indicacao_ocorrencia o
--    WHERE (o.CODUSUARIO_EMISSOR = ? OR o.CODUSUARIO_GESTOR = ?
--           OR o.CODUSUARIO_RESPONSAVEL = ?
--           OR EXISTS (SELECT 1 FROM nc_planodeacao p
--                       WHERE p.CODOCORRENCIA = o.CODOCORRENCIA
--                         AND p.CODUSUARIO = ?))
--      AND COALESCE(o.ETAPA, ?) != ?
--
-- Causa raiz: nc_indicacao_ocorrencia JÁ TEM índices individuais em
-- CODUSUARIO_EMISSOR, CODUSUARIO_GESTOR e CODUSUARIO_RESPONSAVEL, mas
-- o otimizador não usa index_merge/union porque a condição está
-- misturada com "OR EXISTS (subquery)" - isso é uma limitação
-- conhecida do MySQL, não falta de índice. A correção completa exige
-- reescrever a query na aplicação (ver recomendação no relatório).
--
-- O que este índice faz: acelera a subquery EXISTS em si (o ramo que
-- MySQL sempre precisa avaliar linha a linha quando cai em full scan),
-- cobrindo CODOCORRENCIA + CODUSUARIO em uma única busca ao invés de
-- usar o índice de CODOCORRENCIA e depois filtrar CODUSUARIO à parte.
-- =====================================================================
USE gmodelo_dbp;

ALTER TABLE nc_planodeacao
  ADD INDEX ix_planodeacao_ocorrencia_usuario (CODOCORRENCIA, CODUSUARIO);


-- =====================================================================
-- PARTE 2: Índice para os contadores de "situação de legislação por
--          usuário" (legislacaoxsituacao).
--
-- Duas queries reais observadas (289 execuções cada em 6 dias, 100%
-- sem uso de índice):
--   SELECT COUNT(*) AS total FROM legislacaoxsituacao
--    WHERE CODUSUARIO = ? AND COALESCE(atendido, ?) ...
--      AND data_de_vencimento_avaliacao BETWEEN CURDATE() AND ...
--   SELECT COUNT(*) AS total FROM legislacaoxsituacao
--    WHERE CODUSUARIO = ? AND COALESCE(atendido, ?) ...
--      AND DATE(data_de_vencimento_avaliacao) = CURDATE()
--
-- Causa raiz: os 3 índices existentes nesta tabela começam com
-- CODLEGISLACAO (CODLEGISLACAO / CODLEGISLACAO+codunidade /
-- CODLEGISLACAO+codunidade+CODUSUARIO) - nenhum deles é utilizável
-- quando a query filtra por CODUSUARIO sozinho (regra do prefixo mais
-- à esquerda). Resultado: full scan da tabela toda vez que o badge de
-- "pendências do usuário" é calculado.
-- =====================================================================
ALTER TABLE legislacaoxsituacao
  ADD INDEX ix_lxsituacao_usuario_atendido (CODUSUARIO, atendido);


-- =====================================================================
-- PARTE 3: Redo log capacity (innodb_redo_log_capacity)
--
-- Valor atual: 104.857.600 bytes (100 MB) - já foi elevado de 20MB
-- para 100MB entre os relatórios de 19/08 e 01/09, mas ainda abaixo
-- da faixa recomendada de 256-512MB para o volume de escrita observado.
--
-- innodb_redo_log_capacity é DINÂMICA desde o MySQL 8.0.30 (este
-- servidor roda 8.4.7) - não exige restart do serviço. SET PERSIST
-- grava o valor em mysqld-auto.cnf, então sobrevive a um restart
-- futuro também.
--
-- ATENÇÃO (verificar antes de rodar, não é possível confirmar via SQL):
--   - O redimensionamento do redo log é feito de forma incremental
--     pelo próprio MySQL, mas é prudente ter ao menos ~1 GB livre em
--     e:\wamp\bin\mysql\mysql8.4.7\data\ como margem de segurança
--     durante a transição.
--   - Escolha UMA das linhas abaixo (512MB é o valor recomendado no
--     relatório; 256MB é a opção mais conservadora).
-- =====================================================================
SET PERSIST innodb_redo_log_capacity = 536870912;   -- 512 MB (recomendado)
-- SET PERSIST innodb_redo_log_capacity = 268435456; -- 256 MB (alternativa mais conservadora)


-- =====================================================================
-- PARTE 4 (OPCIONAL, NÃO OBRIGATÓRIA): limpeza de índices duplicados
-- em nc_planodeacao, encontrados durante a investigação. Não afeta
-- performance de leitura (o otimizador ignora o duplicado), mas cada
-- índice extra custa espaço em disco e overhead em todo INSERT/UPDATE/
-- DELETE na tabela. Deixado comentado por padrão - descomente se
-- quiser aplicar a limpeza. Nenhuma linha de dado é afetada.
--
--   ICODOCORRENCIA        -> CODOCORRENCIA   (mantém este)
--   inc_planodeacao       -> CODOCORRENCIA   (duplicado do acima)
--   ICODCAUSA              -> CODCAUSA        (mantém este)
--   inc_planodeacao3       -> CODCAUSA        (duplicado do acima)
-- =====================================================================
-- ALTER TABLE nc_planodeacao DROP INDEX inc_planodeacao;
-- ALTER TABLE nc_planodeacao DROP INDEX inc_planodeacao3;


-- =====================================================================
-- PARTE 5: Verificação pós-execução
-- =====================================================================

-- 5.1 Confirma que os 2 novos índices existem
SELECT table_schema, table_name, index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) cols
  FROM information_schema.statistics
 WHERE (table_schema='gmodelo_dbp' AND table_name='nc_planodeacao' AND index_name='ix_planodeacao_ocorrencia_usuario')
    OR (table_schema='gmodelo_dbp' AND table_name='legislacaoxsituacao' AND index_name='ix_lxsituacao_usuario_atendido')
 GROUP BY table_schema, table_name, index_name;
-- Esperado: 2 linhas.

-- 5.2 Confirma o novo valor de innodb_redo_log_capacity
SELECT variable_name, variable_value
  FROM performance_schema.global_variables
 WHERE variable_name = 'innodb_redo_log_capacity';
-- Esperado: 536870912 (ou 268435456 se escolheu a alternativa de 256MB).
