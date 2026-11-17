-- =============================================================================
-- SCRIPT DE CORRECAO - gmodelo_dbp
-- Baseado em: relatorio_performance_bancos_20260901.txt (secao 2.2)
-- Gerado em: 01/09/2026
--
-- COMO USAR:
--   1. Faca backup do banco gmodelo_dbp antes de rodar qualquer coisa aqui
--      (mysqldump gmodelo_dbp > backup_gmodelo_dbp_antes_correcoes.sql,
--      ou "Exportar" pelo proprio phpMyAdmin).
--   2. Rode primeiro a PARTE 0 (verificacao) e confira que todas as
--      contagens de duplicadas/nulos retornam 0. Se algo vier diferente de
--      0, NAO prossiga para a PARTE 1 daquela tabela especifica sem tratar
--      os dados primeiro.
--   3. Rode a PARTE 1, 2 e 3 tabela por tabela (ou em bloco, se preferir),
--      idealmente primeiro em homologacao.
--   4. Rode a PARTE 4 (verificacao final) para confirmar que tudo mudou
--      como esperado.
--
-- Este script foi escrito para ser executado manualmente, revisando cada
-- bloco. Nao ha nenhum comando de exclusao de dados (DROP/DELETE) em
-- nenhum ponto deste script.
-- =============================================================================

USE gmodelo_dbp;


-- =============================================================================
-- PARTE 0 - VERIFICACAO PRE-EXECUCAO (rode antes de tudo, deve retornar 0
-- em "duplicadas" e "nulos" para todas as 8 linhas; ja foi conferido em
-- 01/09/2026 e todas vieram 0, mas repita a checagem no momento em que for
-- executar, pois a base pode ter mudado)
-- =============================================================================

SELECT 'atividadesxcontroleadmin' t, COUNT(*) linhas,
       COUNT(*)-COUNT(DISTINCT codatividade,codcontroleadmin) duplicadas,
       SUM(codatividade IS NULL OR codcontroleadmin IS NULL) nulos
  FROM atividadesxcontroleadmin
UNION ALL
SELECT 'atividadesxepis', COUNT(*),
       COUNT(*)-COUNT(DISTINCT codatividade,codepis),
       SUM(codatividade IS NULL OR codepis IS NULL)
  FROM atividadesxepis
UNION ALL
SELECT 'atividadesxinfraestrutura', COUNT(*),
       COUNT(*)-COUNT(DISTINCT codatividade,codinfraestrutura),
       SUM(codatividade IS NULL OR codinfraestrutura IS NULL)
  FROM atividadesxinfraestrutura
UNION ALL
SELECT 'controleadminxunidades', COUNT(*),
       COUNT(*)-COUNT(DISTINCT CODCONTROLEADMIN,CODUNIDADE),
       SUM(CODCONTROLEADMIN IS NULL OR CODUNIDADE IS NULL)
  FROM controleadminxunidades
UNION ALL
SELECT 'episxunidades', COUNT(*),
       COUNT(*)-COUNT(DISTINCT CODEPIS,CODUNIDADE),
       SUM(CODEPIS IS NULL OR CODUNIDADE IS NULL)
  FROM episxunidades
UNION ALL
SELECT 'infraestruturaxunidades', COUNT(*),
       COUNT(*)-COUNT(DISTINCT CODINFRAESTRUTURA,CODUNIDADE),
       SUM(CODINFRAESTRUTURA IS NULL OR CODUNIDADE IS NULL)
  FROM infraestruturaxunidades
UNION ALL
SELECT 'objetivosmetasxperigos', COUNT(*),
       COUNT(*)-COUNT(DISTINCT CODOBJETIVO,CODPERIGO),
       SUM(CODOBJETIVO IS NULL OR CODPERIGO IS NULL)
  FROM objetivosmetasxperigos
UNION ALL
SELECT 'objetivosmetasxriscos', COUNT(*),
       COUNT(*)-COUNT(DISTINCT CODOBJETIVO,CODRISCO),
       SUM(CODOBJETIVO IS NULL OR CODRISCO IS NULL)
  FROM objetivosmetasxriscos;

-- Situacao em 01/09/2026 (para referencia): todas as 8 tabelas vieram com
-- 0 linhas duplicadas e 0 linhas com valor nulo nas colunas de relacionamento.
-- Isso significa que adicionar PRIMARY KEY composta e seguro sem qualquer
-- limpeza previa de dados, DESDE QUE a situacao continue a mesma no momento
-- da execucao.


-- =============================================================================
-- PARTE 1 - ADICIONAR PRIMARY KEY (8 tabelas sem NENHUM indice)
-- Todas sao tabelas de relacionamento N:N com apenas 2 colunas inteiras.
-- As colunas sao promovidas para NOT NULL (exigido por PRIMARY KEY) antes
-- de criar a chave composta.
-- =============================================================================

ALTER TABLE atividadesxcontroleadmin
  MODIFY codatividade INT NOT NULL,
  MODIFY codcontroleadmin INT NOT NULL,
  ADD PRIMARY KEY (codatividade, codcontroleadmin);

ALTER TABLE atividadesxepis
  MODIFY codatividade INT NOT NULL,
  MODIFY codepis INT NOT NULL,
  ADD PRIMARY KEY (codatividade, codepis);

ALTER TABLE atividadesxinfraestrutura
  MODIFY codatividade INT NOT NULL,
  MODIFY codinfraestrutura INT NOT NULL,
  ADD PRIMARY KEY (codatividade, codinfraestrutura);

ALTER TABLE controleadminxunidades
  MODIFY CODCONTROLEADMIN INT NOT NULL,
  MODIFY CODUNIDADE INT NOT NULL,
  ADD PRIMARY KEY (CODCONTROLEADMIN, CODUNIDADE);

ALTER TABLE episxunidades
  MODIFY CODEPIS INT NOT NULL,
  MODIFY CODUNIDADE INT NOT NULL,
  ADD PRIMARY KEY (CODEPIS, CODUNIDADE);

ALTER TABLE infraestruturaxunidades
  MODIFY CODINFRAESTRUTURA INT NOT NULL,
  MODIFY CODUNIDADE INT NOT NULL,
  ADD PRIMARY KEY (CODINFRAESTRUTURA, CODUNIDADE);

ALTER TABLE objetivosmetasxperigos
  MODIFY CODOBJETIVO INT NOT NULL,
  MODIFY CODPERIGO INT NOT NULL,
  ADD PRIMARY KEY (CODOBJETIVO, CODPERIGO);

ALTER TABLE objetivosmetasxriscos
  MODIFY CODOBJETIVO INT NOT NULL,
  MODIFY CODRISCO INT NOT NULL,
  ADD PRIMARY KEY (CODOBJETIVO, CODRISCO);


-- =============================================================================
-- PARTE 2 - CONVERTER ENGINE PARA InnoDB (10 tabelas MyISAM)
-- Nenhuma dessas tabelas tem FOREIGN KEY hoje (MyISAM nao suporta), entao a
-- ordem de execucao nao importa. Pode rodar em qualquer ordem, inclusive
-- depois da PARTE 1 (recomendado, ja com a PRIMARY KEY criada).
-- =============================================================================

ALTER TABLE atividadesxcontroleadmin   ENGINE = InnoDB;
ALTER TABLE atividadesxepis            ENGINE = InnoDB;
ALTER TABLE atividadesxinfraestrutura  ENGINE = InnoDB;
ALTER TABLE controleadmin              ENGINE = InnoDB;
ALTER TABLE controleadminxunidades     ENGINE = InnoDB;
ALTER TABLE epis                       ENGINE = InnoDB;
ALTER TABLE episxunidades              ENGINE = InnoDB;
ALTER TABLE infraestrutura             ENGINE = InnoDB;
ALTER TABLE infraestruturaxunidades    ENGINE = InnoDB;
ALTER TABLE levantamentoocupacionalxrevisoes ENGINE = InnoDB;


-- =============================================================================
-- PARTE 3 - CONVERTER COLACAO PARA utf8mb4_0900_ai_ci (16 tabelas)
-- CONVERT TO CHARACTER SET reescreve os dados de texto da tabela inteira;
-- em tabelas com poucas linhas (como estas, todas com menos de 1.000
-- linhas) isso e rapido, mas mantenha o backup da PARTE 0 por precaucao,
-- pois strings ja gravadas incorretamente em latin1 podem ficar com
-- acentuacao/caracteres especiais incorretos apos a conversao (o comando
-- converte a codificacao declarada da coluna, nao "traduz" bytes ja
-- salvos incorretamente - revise o conteudo das colunas de texto dessas
-- tabelas apos a conversao).
--
-- As duas tabelas zz_backup_153_* foram deixadas de fora deste bloco de
-- proposito, por serem backups congelados - avalie se faz sentido
-- converte-las tambem ou preferir mante-las como estavam no momento do
-- backup original. Descomente as duas ultimas linhas se quiser incluir.
-- =============================================================================

ALTER TABLE atividadesxcontroleadmin   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE atividadesxepis            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE atividadesxinfraestrutura  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE controleadmin              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE controleadminxunidades     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE epis                       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE episxunidades              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE infraestrutura             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE infraestruturaxunidades    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE legislacaoxaspecto         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE legislacaoxatividade       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE legislacaoxrisco           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE objetivosmetasxperigos     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
ALTER TABLE objetivosmetasxriscos      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- Backups (opcional, ver observacao acima) - descomente para incluir:
-- ALTER TABLE zz_backup_153_legislacaoxatividade CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
-- ALTER TABLE zz_backup_153_legislacaoxrisco     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;


-- =============================================================================
-- PARTE 4 - VERIFICACAO POS-EXECUCAO
-- Deve retornar ZERO linhas se tudo correu bem (nenhuma tabela problematica
-- restante em gmodelo_dbp).
-- =============================================================================

SELECT table_name, engine, table_collation,
       (SELECT COUNT(*) FROM information_schema.statistics s
         WHERE s.table_schema='gmodelo_dbp' AND s.table_name=t.table_name) AS qtd_indices
  FROM information_schema.tables t
 WHERE table_schema = 'gmodelo_dbp'
   AND table_type = 'BASE TABLE'
   AND table_name NOT LIKE 'zz\_backup%'
   AND (engine != 'InnoDB'
        OR table_collation NOT LIKE 'utf8mb4%'
        OR (SELECT COUNT(*) FROM information_schema.statistics s
             WHERE s.table_schema='gmodelo_dbp' AND s.table_name=t.table_name) = 0);

-- =============================================================================
-- FIM DO SCRIPT
-- =============================================================================
