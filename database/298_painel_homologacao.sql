-- Migração 298 — Homologação de Fornecedores, etapa 5: Painel de Homologação (relatórios gerenciais, estatísticas e dashboard)
-- e regra única de cores. Aplicado por scripts/aplicar-migracao-298-painel-homologacao.php em cada banco de cliente.
-- Comandos separados por "-- @@". Pode ser reaplicado. Depende das migrações 293 (vw_homologacao_fornecedor_documentos)
-- e 296 (vw_homologacao_fornecedores_do_usuario).
--
-- Regra única de cores (a mesma da aba Documentos, da Home e do Painel):
--   VERMELHO  reprovado / não atende, vencido, pendente com o fornecedor (documentos)
--   AMARELO   pendente com a empresa, ou vence dentro dos dias de reação (30 quando não informado)
--   VERDE     aprovado, aprovado sem validade, ciente da regra / atende, dentro do prazo
--   NEUTRO    (cinza) não se aplica, não enviar ao fornecedor, funcionário desligado ou afastado
--   BRANCO    sem situação
-- "% atendido" = VERDE ÷ (total − NEUTRO).
--
-- Migração 350 (decisão do usuário de 24/10/2026): fo_funcionarios.DATA_VALIDADE é a DATA DE REALIZAÇÃO da integração (como o
-- legado rotula). A validade = realização + meses da configuração da integração do tipo de funcionário (fo_integracao_config,
-- migração 348; sem configuração, 12 meses), calculada em vw_fo_integracao_validade.

-- @@
-- Configuração da integração (mesma definição da migração 348): existe aqui para a validade ser calculada mesmo antes da 348.
CREATE TABLE IF NOT EXISTS `fo_integracao_config` (
    `CODCONFIG` INT NOT NULL AUTO_INCREMENT,
    `CODTIPOFUNCIONARIO` INT NULL,
    `CHAVE_TIPO` INT AS (COALESCE(`CODTIPOFUNCIONARIO`, 0)) STORED,
    `CODIGO_QUESTIONARIO` VARCHAR(100) NOT NULL,
    `NOTA_MINIMA` DECIMAL(6,2) NULL,
    `VALIDADE_MESES` INT NOT NULL DEFAULT 12,
    `LINK_DIAS` INT NOT NULL DEFAULT 7,
    `ATIVO` TINYINT NOT NULL DEFAULT 1,
    `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `CODUSUARIO_ALTERACAO` BIGINT NULL,
    PRIMARY KEY (`CODCONFIG`),
    UNIQUE KEY `ux_fo_integracao_config_tipo` (`CHAVE_TIPO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
-- Integração de cada funcionário: data de realização (fo_funcionarios.DATA_VALIDADE), meses de validade do tipo (ou do padrão da
-- empresa, ou 12) e a validade calculada.
CREATE OR REPLACE VIEW `vw_fo_integracao_validade` AS
SELECT fu.`CODFUNCIONARIO`,
       IF(fu.`DATA_VALIDADE` IS NULL OR fu.`DATA_VALIDADE` < '1900-01-01', NULL, fu.`DATA_VALIDADE`) AS `DATA_REALIZACAO`,
       COALESCE(ct.`VALIDADE_MESES`, cp.`VALIDADE_MESES`, 12) AS `VALIDADE_MESES`,
       IF(fu.`DATA_VALIDADE` IS NULL OR fu.`DATA_VALIDADE` < '1900-01-01', NULL,
          DATE_ADD(fu.`DATA_VALIDADE`, INTERVAL COALESCE(ct.`VALIDADE_MESES`, cp.`VALIDADE_MESES`, 12) MONTH)) AS `DATA_VALIDADE_INTEGRACAO`
  FROM `fo_funcionarios` fu
  LEFT JOIN `fo_integracao_config` ct ON ct.`CODTIPOFUNCIONARIO` = fu.`CODTIPOFUNCIONARIO` AND ct.`ATIVO` = 1
  LEFT JOIN `fo_integracao_config` cp ON cp.`CODTIPOFUNCIONARIO` IS NULL AND cp.`ATIVO` = 1

-- @@
-- Todos os itens acompanhados pelo Painel, um registro por item, de fornecedores ATIVOS:
-- DOCUMENTO (documentos do fornecedor), AUDITORIA (tipos que exigem auditoria), NIVEL (nível de serviço),
-- INTEGRACAO (integração do funcionário), DOCFUNC (critérios do funcionário), TREINAMENTO, EQUIPAMENTO, QUIMICO, PLANO.
CREATE OR REPLACE VIEW `vw_homologacao_painel_itens` AS
SELECT x.CATEGORIA, x.CODREGISTRO, x.CODFORNECEDOR, x.CODFUNCIONARIO, x.ITEM, x.SITUACAO, x.VALIDADE, x.QTDDIAS, x.REACAO, x.COR,
       x.CODUSUARIO_EMPRESA, x.CODUSUARIO_FORNECEDOR,
       f.codunidade AS CODUNIDADE,
       CONVERT(COALESCE(un.DSCUNIDADE, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS DSCUNIDADE,
       CONVERT(COALESCE(NULLIF(TRIM(f.razaosocial), ''), CONCAT('Fornecedor ', f.codfornecedor)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS RAZAOSOCIAL,
       CONVERT(COALESCE(f.nomefantasia, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NOMEFANTASIA,
       CONVERT(COALESCE(f.cnpj_cpf, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS CNPJ_CPF,
       CONVERT(COALESCE(ue.nome, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS RESPONSAVEL_EMPRESA,
       CONVERT(COALESCE(uf.nome, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS RESPONSAVEL_FORNECEDOR
  FROM (
    -- Documentos do fornecedor (cor já calculada na migração 293)
    SELECT 'DOCUMENTO' AS CATEGORIA, d.CODFORNECEDORXCRITERIOS AS CODREGISTRO, d.CODFORNECEDOR, NULL AS CODFUNCIONARIO,
           CONVERT(CONCAT(COALESCE(d.DSCTIPO, ''), ' / ', COALESCE(d.DSCRITERIO, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS ITEM,
           CONVERT(COALESCE(d.SITUACAO, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS SITUACAO,
           d.DATADEVALIDADE AS VALIDADE, d.QTDDIAS, NULLIF(d.DATADEREACAO, 0) AS REACAO,
           CONVERT(d.COR USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS COR,
           NULLIF(d.CODUSUARIO, 0) AS CODUSUARIO_EMPRESA, NULLIF(d.CODUSUARIO_FORNECEDOR, 0) AS CODUSUARIO_FORNECEDOR
      FROM `vw_homologacao_fornecedor_documentos` d
    UNION ALL
    -- Auditoria de homologação dos tipos que exigem auditoria
    SELECT 'AUDITORIA', a.CODFORNECEDORXTIPOFORNECEDORES, a.CODFORNECEDOR, NULL,
           CONVERT(CONCAT('Auditoria: ', COALESCE(a.DSCTIPO, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           CONVERT(CASE WHEN a.APROVADO = 'Não' THEN 'Reprovado na auditoria' WHEN a.VAL IS NOT NULL AND a.DIAS < 0 THEN 'Auditoria vencida'
                        WHEN a.APROVADO = 'Sim' THEN 'Aprovado na auditoria' ELSE 'Auditoria pendente' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           a.VAL, a.DIAS, a.REAC,
           CONVERT(CASE WHEN a.APROVADO = 'Não' THEN 'VERMELHO' WHEN a.VAL IS NOT NULL AND a.DIAS < 0 THEN 'VERMELHO'
                        WHEN a.VAL IS NOT NULL AND a.DIAS <= COALESCE(a.REAC, 30) THEN 'AMARELO'
                        WHEN a.APROVADO = 'Sim' THEN 'VERDE' ELSE 'BRANCO' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           NULL, NULL
      FROM (SELECT fxt.codfornecedorxtipofornecedores AS CODFORNECEDORXTIPOFORNECEDORES, fxt.codfornecedor AS CODFORNECEDOR, t.DSCTIPO,
                   CASE WHEN LOWER(TRIM(COALESCE(fxt.fornecedoraprovadoauditoria, ''))) IN ('sim', 's') THEN 'Sim'
                        WHEN LOWER(TRIM(COALESCE(fxt.fornecedoraprovadoauditoria, ''))) IN ('não', 'nao', 'n') THEN 'Não' ELSE '' END AS APROVADO,
                   IF(fxt.datavalidade IS NULL OR fxt.datavalidade < '1900-01-01', NULL, fxt.datavalidade) AS VAL,
                   IF(fxt.datavalidade IS NULL OR fxt.datavalidade < '1900-01-01', NULL, DATEDIFF(fxt.datavalidade, CURDATE())) AS DIAS,
                   NULLIF(fxt.datareacao, 0) AS REAC
              FROM `fo_fornecedoresxtipofornecedores` fxt
              JOIN `fo_tipofornecedores` t ON t.codtipofornecedor = fxt.codtipofornecedor
             WHERE LOWER(TRIM(COALESCE(t.necessita_auditoria, ''))) IN ('sim', 's')) a
    UNION ALL
    -- Nível de serviço do fornecedor (última avaliação) e próxima avaliação
    SELECT 'NIVEL', n.codfornecedor, n.codfornecedor, NULL,
           CONVERT('Nível de serviço' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           CONVERT(CASE WHEN n.NIVEL = 'NS' THEN 'Não satisfatório' WHEN n.PROX IS NOT NULL AND n.DIAS < 0 THEN 'Avaliação atrasada'
                        WHEN n.NIVEL = 'S' THEN 'Satisfatório' ELSE 'Sem avaliação' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           n.PROX, n.DIAS, NULL,
           CONVERT(CASE WHEN n.NIVEL = 'NS' THEN 'VERMELHO' WHEN n.PROX IS NOT NULL AND n.DIAS < 0 THEN 'AMARELO'
                        WHEN n.NIVEL = 'S' THEN 'VERDE' ELSE 'BRANCO' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           NULLIF(n.codusuario_responsavel_fornecedor, 0), NULL
      FROM (SELECT f0.codfornecedor, f0.codusuario_responsavel_fornecedor,
                   CASE WHEN LOWER(TRIM(COALESCE(f0.nivel_de_servicao, ''))) LIKE 'n%o satisfat%' THEN 'NS'
                        WHEN LOWER(TRIM(COALESCE(f0.nivel_de_servicao, ''))) LIKE 'satisfat%' THEN 'S' ELSE '' END AS NIVEL,
                   IF(f0.data_da_proxima_avaliacao IS NULL OR f0.data_da_proxima_avaliacao < '1900-01-01', NULL, f0.data_da_proxima_avaliacao) AS PROX,
                   IF(f0.data_da_proxima_avaliacao IS NULL OR f0.data_da_proxima_avaliacao < '1900-01-01', NULL, DATEDIFF(f0.data_da_proxima_avaliacao, CURDATE())) AS DIAS
              FROM `fo_fornecedores` f0) n
     WHERE n.NIVEL <> '' OR n.PROX IS NOT NULL
    UNION ALL
    -- Integração do funcionário: validade = data de realização (fo_funcionarios.DATA_VALIDADE) + meses (migração 350)
    SELECT 'INTEGRACAO', i.CODFUNCIONARIO, i.CODFORNECEDOR, i.CODFUNCIONARIO,
           CONVERT(CONCAT(COALESCE(i.NOME, ''), ' — integração') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           CONVERT(CASE WHEN i.VAL IS NULL THEN 'Sem data de integração' WHEN i.DIAS < 0 THEN 'Integração vencida'
                        WHEN i.DIAS <= COALESCE(i.REAC, 30) THEN 'Integração a vencer' ELSE 'Integração em dia' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           i.VAL, i.DIAS, i.REAC,
           CONVERT(CASE WHEN i.VAL IS NULL THEN 'BRANCO' WHEN i.DIAS < 0 THEN 'VERMELHO' WHEN i.DIAS <= COALESCE(i.REAC, 30) THEN 'AMARELO' ELSE 'VERDE' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           NULL, NULL
      FROM (SELECT fu.CODFUNCIONARIO, fu.CODFORNECEDOR, fu.NOME, NULLIF(fu.NRDIAS_REACAO, 0) AS REAC,
                   iv.DATA_VALIDADE_INTEGRACAO AS VAL,
                   DATEDIFF(iv.DATA_VALIDADE_INTEGRACAO, CURDATE()) AS DIAS
              FROM `fo_funcionarios` fu
              JOIN `vw_fo_integracao_validade` iv ON iv.CODFUNCIONARIO = fu.CODFUNCIONARIO
             WHERE UPPER(TRIM(COALESCE(fu.ativo, 'S'))) NOT IN ('N', 'NAO', 'NÃO', '0')) i
    UNION ALL
    -- Critérios (documentos) do funcionário. Migração 315 (regra do relatório do legado fo_relatorio_documentacao_exigida_func,
    -- conferida na Crown em 19/10/2026): só do tipo atual do funcionário, critério exigido por esse tipo e existente no cadastro.
    SELECT 'DOCFUNC', c.codigo, c.CODFORNECEDOR, c.codfuncionario,
           CONVERT(CONCAT(COALESCE(c.NOME, ''), ' — ', COALESCE(c.DSCRITERIO, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           CONVERT(CASE WHEN c.AT IN ('NAP', 'DESLIGADO', 'AFASTADO') THEN c.atendeaocriterio WHEN c.AT IN ('NÃO', 'NAO') THEN 'Não atende'
                        WHEN c.VAL IS NOT NULL AND c.DIAS < 0 THEN 'Vencido' WHEN c.AT = 'SIM' THEN 'Atende' ELSE 'Sem situação' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           c.VAL, c.DIAS, c.REAC,
           CONVERT(CASE WHEN c.AT IN ('NAP', 'DESLIGADO', 'AFASTADO') THEN 'NEUTRO' WHEN c.AT IN ('NÃO', 'NAO') THEN 'VERMELHO'
                        WHEN c.VAL IS NOT NULL AND c.DIAS < 0 THEN 'VERMELHO' WHEN c.VAL IS NOT NULL AND c.DIAS <= COALESCE(c.REAC, 30) THEN 'AMARELO'
                        WHEN c.AT = 'SIM' THEN 'VERDE' ELSE 'BRANCO' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           NULLIF(c.codusuario, 0), NULLIF(c.codusuario_responsavel_fornecedor, 0)
      FROM (SELECT x.codigo, x.codfuncionario, fu.CODFORNECEDOR, fu.NOME, cf.DSCRITERIO, x.atendeaocriterio,
                   UPPER(TRIM(COALESCE(x.atendeaocriterio, ''))) AS AT, NULLIF(x.datadereacao, 0) AS REAC, x.codusuario, x.codusuario_responsavel_fornecedor,
                   IF(x.datadevalidade IS NULL OR x.datadevalidade < '1900-01-01', NULL, x.datadevalidade) AS VAL,
                   IF(x.datadevalidade IS NULL OR x.datadevalidade < '1900-01-01', NULL, DATEDIFF(x.datadevalidade, CURDATE())) AS DIAS
              FROM `fo_funcionarioxtiposxcriterios` x
              JOIN `fo_funcionarios` fu ON fu.CODFUNCIONARIO = x.codfuncionario AND x.codtipofuncionario = fu.CODTIPOFUNCIONARIO
              JOIN `fo_criteriofuncionarios` cf ON cf.codcriteriofuncionario = x.codcriteriofuncionario
             WHERE UPPER(TRIM(COALESCE(fu.ativo, 'S'))) NOT IN ('N', 'NAO', 'NÃO', '0')
               AND EXISTS (SELECT 1 FROM `fo_tipofuncionariosxcriteriofuncionarios` tcf
                            WHERE tcf.codtipofuncionario = fu.CODTIPOFUNCIONARIO AND tcf.codcriteriofuncionario = x.codcriteriofuncionario)) c
    UNION ALL
    -- Treinamentos do funcionário (o campo de reação do legado é sobrescrito: usa 30 dias)
    SELECT 'TREINAMENTO', t.codigo, t.CODFORNECEDOR, t.codfuncionario,
           CONVERT(CONCAT(COALESCE(t.NOME, ''), ' — ', COALESCE(t.DSCTIPO, '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           CONVERT(CASE WHEN t.REAL_ = 'NÃO' OR t.REAL_ = 'NAO' THEN 'Não realizado' WHEN t.VAL IS NOT NULL AND t.DIAS < 0 THEN 'Treinamento vencido'
                        WHEN t.VAL IS NOT NULL AND t.DIAS <= 30 THEN 'Treinamento a vencer' WHEN t.REAL_ = 'SIM' THEN 'Realizado' ELSE 'Sem situação' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           t.VAL, t.DIAS, 30,
           CONVERT(CASE WHEN t.REAL_ IN ('NÃO', 'NAO') THEN 'VERMELHO' WHEN t.VAL IS NOT NULL AND t.DIAS < 0 THEN 'VERMELHO'
                        WHEN t.VAL IS NOT NULL AND t.DIAS <= 30 THEN 'AMARELO' WHEN t.REAL_ = 'SIM' THEN 'VERDE' ELSE 'BRANCO' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           NULLIF(t.codusuario, 0), NULL
      FROM (SELECT x.codigo, x.codfuncionario, fu.CODFORNECEDOR, fu.NOME, tt.DSCTIPO, UPPER(TRIM(COALESCE(x.treinamentorealizado, ''))) AS REAL_, x.codusuario,
                   IF(x.datadevalidadetreinamento IS NULL OR x.datadevalidadetreinamento < '1900-01-01', NULL, x.datadevalidadetreinamento) AS VAL,
                   IF(x.datadevalidadetreinamento IS NULL OR x.datadevalidadetreinamento < '1900-01-01', NULL, DATEDIFF(x.datadevalidadetreinamento, CURDATE())) AS DIAS
              FROM `fo_funcionarioxtiposxtreinamentos` x
              JOIN `fo_funcionarios` fu ON fu.CODFUNCIONARIO = x.codfuncionario
              LEFT JOIN `fo_tipotreinamentos` tt ON tt.codtipotreinamento = x.codtipotreinamento
             WHERE UPPER(TRIM(COALESCE(fu.ativo, 'S'))) NOT IN ('N', 'NAO', 'NÃO', '0')) t
    UNION ALL
    -- Equipamentos
    SELECT 'EQUIPAMENTO', e.codequipamento, e.codfornecedor, NULL,
           CONVERT(CONCAT(COALESCE(e.DSCEQUIPAMENTO, ''), IF(COALESCE(e.NRIDENTIFICACAO, '') = '', '', CONCAT(' (', e.NRIDENTIFICACAO, ')'))) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           CONVERT(CASE WHEN e.AP = 'NÃO' OR e.AP = 'NAO' THEN 'Reprovado' WHEN e.VAL IS NOT NULL AND e.DIAS < 0 THEN 'Equipamento vencido'
                        WHEN e.VAL IS NOT NULL AND e.DIAS <= COALESCE(e.REAC, 30) THEN 'Equipamento a vencer' WHEN e.AP = 'SIM' THEN 'Aprovado' ELSE 'Sem situação' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           e.VAL, e.DIAS, e.REAC,
           CONVERT(CASE WHEN e.AP IN ('NÃO', 'NAO') THEN 'VERMELHO' WHEN e.VAL IS NOT NULL AND e.DIAS < 0 THEN 'VERMELHO'
                        WHEN e.VAL IS NOT NULL AND e.DIAS <= COALESCE(e.REAC, 30) THEN 'AMARELO' WHEN e.AP = 'SIM' THEN 'VERDE' ELSE 'BRANCO' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           NULL, NULL
      FROM (SELECT q.codequipamento, q.codfornecedor, q.DSCEQUIPAMENTO, q.NRIDENTIFICACAO, UPPER(TRIM(COALESCE(q.APROVADO, ''))) AS AP, NULLIF(q.NRDIAS_REACAO, 0) AS REAC,
                   IF(q.DATA_VALIDADE IS NULL OR q.DATA_VALIDADE < '1900-01-01', NULL, q.DATA_VALIDADE) AS VAL,
                   IF(q.DATA_VALIDADE IS NULL OR q.DATA_VALIDADE < '1900-01-01', NULL, DATEDIFF(q.DATA_VALIDADE, CURDATE())) AS DIAS
              FROM `fo_equipamentos` q) e
    UNION ALL
    -- Produtos químicos
    SELECT 'QUIMICO', p.codproduto, p.codfornecedor, NULL,
           CONVERT(CONCAT(COALESCE(p.DSCPRODUTO, ''), IF(COALESCE(p.NCAS, '') = '', '', CONCAT(' (CAS ', p.NCAS, ')'))) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           CONVERT(CASE WHEN p.AP = 'NÃO' OR p.AP = 'NAO' THEN 'Reprovado' WHEN p.VAL IS NOT NULL AND p.DIAS < 0 THEN 'Produto vencido'
                        WHEN p.VAL IS NOT NULL AND p.DIAS <= COALESCE(p.REAC, 30) THEN 'Produto a vencer' WHEN p.AP = 'SIM' THEN 'Aprovado' ELSE 'Sem situação' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           p.VAL, p.DIAS, p.REAC,
           CONVERT(CASE WHEN p.AP IN ('NÃO', 'NAO') THEN 'VERMELHO' WHEN p.VAL IS NOT NULL AND p.DIAS < 0 THEN 'VERMELHO'
                        WHEN p.VAL IS NOT NULL AND p.DIAS <= COALESCE(p.REAC, 30) THEN 'AMARELO' WHEN p.AP = 'SIM' THEN 'VERDE' ELSE 'BRANCO' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           NULL, NULL
      FROM (SELECT q.codproduto, q.codfornecedor, q.DSCPRODUTO, q.NCAS, UPPER(TRIM(COALESCE(q.APROVADO, ''))) AS AP, NULLIF(q.NRDIAS_REACAO, 0) AS REAC,
                   IF(q.DATA_VALIDADE IS NULL OR q.DATA_VALIDADE < '1900-01-01', NULL, q.DATA_VALIDADE) AS VAL,
                   IF(q.DATA_VALIDADE IS NULL OR q.DATA_VALIDADE < '1900-01-01', NULL, DATEDIFF(q.DATA_VALIDADE, CURDATE())) AS DIAS
              FROM `fo_produtoquimico` q) p
    UNION ALL
    -- Plano de ação (status em %; 100 = concluído)
    SELECT 'PLANO', pl.codplanoacao, pl.codfornecedor, NULL,
           CONVERT(LEFT(COALESCE(pl.acao, ''), 300) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           CONVERT(CASE WHEN pl.ST >= 100 THEN 'Concluído' WHEN pl.VAL IS NOT NULL AND pl.DIAS < 0 THEN CONCAT('Atrasado (', pl.ST, '%)')
                        ELSE CONCAT('Em aberto (', pl.ST, '%)') END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           pl.VAL, pl.DIAS, 30,
           CONVERT(CASE WHEN pl.ST >= 100 THEN 'VERDE' WHEN pl.VAL IS NOT NULL AND pl.DIAS < 0 THEN 'VERMELHO'
                        WHEN pl.VAL IS NOT NULL AND pl.DIAS <= 30 THEN 'AMARELO' ELSE 'BRANCO' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
           NULLIF(pl.codusuario, 0), NULL
      FROM (SELECT q.codplanoacao, q.codfornecedor, q.acao, q.codusuario, COALESCE(q.status, 0) AS ST,
                   IF(q.datafinal IS NULL OR q.datafinal < '1900-01-01', NULL, q.datafinal) AS VAL,
                   IF(q.datafinal IS NULL OR q.datafinal < '1900-01-01', NULL, DATEDIFF(q.datafinal, CURDATE())) AS DIAS
              FROM `fo_planodeacao` q) pl
  ) x
  JOIN `fo_fornecedores` f ON f.codfornecedor = x.CODFORNECEDOR
  LEFT JOIN `unidades` un ON un.codunidade = f.codunidade
  LEFT JOIN `usuarios` ue ON ue.codusuario = x.CODUSUARIO_EMPRESA
  LEFT JOIN `usuarios` uf ON uf.codusuario = x.CODUSUARIO_FORNECEDOR
 WHERE LOWER(TRIM(COALESCE(f.ativadofor, ''))) IN ('sim', 's', '1')

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_painel_itens`

-- @@
-- Itens do Painel com os filtros da tela. p_externo = 1: só fornecedores ligados ao usuário (perfis do fornecedor externo);
-- p_externo = 2 (migração 316): interno restrito — fornecedores das unidades dele ou pelos quais responde
-- (vw_homologacao_fornecedores_acesso_interno; a migração 316 tem de estar aplicada no banco).
-- p_responsavel: '' | 'EU' (empresa ou fornecedor = usuário) | 'SEM' (sem um dos responsáveis, só documentos).
-- p_vencimento: '' | 'VENCIDOS' | '30' | '90'. p_fornecedor: um fornecedor (Ficha) ou NULL.
CREATE PROCEDURE `sp_fo_painel_itens`(IN p_usuario BIGINT, IN p_externo TINYINT, IN p_unidade BIGINT, IN p_tipo BIGINT,
                                      IN p_busca VARCHAR(200), IN p_responsavel VARCHAR(10), IN p_vencimento VARCHAR(10), IN p_fornecedor BIGINT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_busca VARCHAR(200) DEFAULT TRIM(COALESCE(p_busca, ''));
    DECLARE v_digitos VARCHAR(200) DEFAULT REGEXP_REPLACE(TRIM(COALESCE(p_busca, '')), '[^0-9]', '');
    SELECT i.CATEGORIA, i.CODREGISTRO, i.CODFORNECEDOR, i.CODFUNCIONARIO, i.ITEM, i.SITUACAO, i.VALIDADE, i.QTDDIAS, i.REACAO, i.COR,
           i.CODUSUARIO_EMPRESA, i.CODUSUARIO_FORNECEDOR, i.CODUNIDADE, i.DSCUNIDADE, i.RAZAOSOCIAL, i.NOMEFANTASIA, i.CNPJ_CPF,
           i.RESPONSAVEL_EMPRESA, i.RESPONSAVEL_FORNECEDOR
      FROM `vw_homologacao_painel_itens` i
     WHERE (p_fornecedor IS NULL OR i.CODFORNECEDOR = p_fornecedor)
       AND (COALESCE(p_externo, 0) = 0
            OR (p_externo = 1 AND EXISTS (SELECT 1 FROM `vw_homologacao_fornecedores_do_usuario` a WHERE a.CODUSUARIO = p_usuario AND a.CODFORNECEDOR = i.CODFORNECEDOR))
            OR (p_externo = 2 AND EXISTS (SELECT 1 FROM `vw_homologacao_fornecedores_acesso_interno` a WHERE a.CODUSUARIO = p_usuario AND a.CODFORNECEDOR = i.CODFORNECEDOR)))
       AND (p_unidade IS NULL OR i.CODUNIDADE = p_unidade)
       AND (p_tipo IS NULL OR EXISTS (SELECT 1 FROM `fo_fornecedoresxtipofornecedores` ft WHERE ft.codfornecedor = i.CODFORNECEDOR AND ft.codtipofornecedor = p_tipo))
       AND (v_busca = '' OR i.RAZAOSOCIAL LIKE CONCAT('%', v_busca, '%') OR i.NOMEFANTASIA LIKE CONCAT('%', v_busca, '%')
            OR (CHAR_LENGTH(v_digitos) >= 3 AND REGEXP_REPLACE(i.CNPJ_CPF, '[^0-9]', '') LIKE CONCAT('%', v_digitos, '%')))
       AND (COALESCE(p_responsavel, '') = ''
            OR (p_responsavel = 'EU' AND (i.CODUSUARIO_EMPRESA = p_usuario OR i.CODUSUARIO_FORNECEDOR = p_usuario))
            OR (p_responsavel = 'SEM' AND i.CATEGORIA = 'DOCUMENTO' AND i.COR <> 'NEUTRO'
                AND (i.CODUSUARIO_EMPRESA IS NULL OR i.CODUSUARIO_FORNECEDOR IS NULL OR i.CODUSUARIO_FORNECEDOR = 99999)))
       AND (COALESCE(p_vencimento, '') = ''
            OR (p_vencimento = 'VENCIDOS' AND i.QTDDIAS < 0)
            OR (p_vencimento = '30' AND i.QTDDIAS BETWEEN 0 AND 30)
            OR (p_vencimento = '90' AND i.QTDDIAS BETWEEN 0 AND 90));
    -- Fornecedores ativos e desativados no mesmo recorte (unidade, tipo, busca, externo)
    SELECT SUM(LOWER(TRIM(COALESCE(f.ativadofor, ''))) IN ('sim', 's', '1')) AS ATIVOS,
           SUM(LOWER(TRIM(COALESCE(f.ativadofor, ''))) NOT IN ('sim', 's', '1')) AS DESATIVADOS
      FROM `fo_fornecedores` f
     WHERE (p_fornecedor IS NULL OR f.codfornecedor = p_fornecedor)
       AND (COALESCE(p_externo, 0) = 0
            OR (p_externo = 1 AND EXISTS (SELECT 1 FROM `vw_homologacao_fornecedores_do_usuario` a WHERE a.CODUSUARIO = p_usuario AND a.CODFORNECEDOR = f.codfornecedor))
            OR (p_externo = 2 AND EXISTS (SELECT 1 FROM `vw_homologacao_fornecedores_acesso_interno` a WHERE a.CODUSUARIO = p_usuario AND a.CODFORNECEDOR = f.codfornecedor)))
       AND (p_unidade IS NULL OR f.codunidade = p_unidade)
       AND (p_tipo IS NULL OR EXISTS (SELECT 1 FROM `fo_fornecedoresxtipofornecedores` ft WHERE ft.codfornecedor = f.codfornecedor AND ft.codtipofornecedor = p_tipo))
       AND (v_busca = '' OR f.razaosocial LIKE CONCAT('%', v_busca, '%') OR f.nomefantasia LIKE CONCAT('%', v_busca, '%')
            OR (CHAR_LENGTH(v_digitos) >= 3 AND REGEXP_REPLACE(COALESCE(f.cnpj_cpf, ''), '[^0-9]', '') LIKE CONCAT('%', v_digitos, '%')));
END

-- @@
-- Quem não quer o resumo semanal da homologação (Home › Alertas). Sem linha = recebe.
CREATE TABLE IF NOT EXISTS `fo_painel_resumo_preferencias` (
    `codusuario` BIGINT NOT NULL,
    `receber` TINYINT(1) NOT NULL DEFAULT 1,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`codusuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
-- Execuções do resumo semanal (uma por semana ISO).
CREATE TABLE IF NOT EXISTS `fo_painel_resumo_execucoes` (
    `semana` CHAR(7) NOT NULL,
    `executado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `usuarios` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`semana`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
CREATE OR REPLACE VIEW `vw_fo_painel_resumo_preferencias` AS
SELECT codusuario AS CODUSUARIO, receber AS RECEBER, atualizado_em AS ATUALIZADO_EM FROM `fo_painel_resumo_preferencias`

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_painel_resumo_preferencia`

-- @@
-- Liga/desliga o resumo semanal do usuário. Devolve a preferência gravada.
CREATE PROCEDURE `sp_fo_painel_resumo_preferencia`(IN p_usuario BIGINT, IN p_receber TINYINT)
SQL SECURITY INVOKER
BEGIN
    IF COALESCE(p_usuario, 0) < 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Usuário inválido.'; END IF;
    INSERT INTO `fo_painel_resumo_preferencias` (codusuario, receber) VALUES (p_usuario, IF(p_receber = 1, 1, 0))
        ON DUPLICATE KEY UPDATE receber = VALUES(receber);
    SELECT codusuario AS CODUSUARIO, receber AS RECEBER FROM `fo_painel_resumo_preferencias` WHERE codusuario = p_usuario;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_painel_resumo_semanal`

-- @@
-- Resumo semanal: por usuário responsável (empresa ou fornecedor) que não desligou o resumo, conta os itens
-- vermelhos e amarelos dele. Roda pelo serviço scripts/homologacao-resumo-worker.php (Agendador de Tarefas do Windows).
-- p_executar = 0 só devolve a lista; 1 também registra a semana (não repete).
CREATE PROCEDURE `sp_fo_painel_resumo_semanal`(IN p_semana CHAR(7), IN p_executar TINYINT)
SQL SECURITY INVOKER
BEGIN
    IF EXISTS (SELECT 1 FROM `fo_painel_resumo_execucoes` WHERE semana = p_semana) THEN
        SELECT NULL AS CODUSUARIO, 0 AS VERMELHOS, 0 AS AMARELOS, 0 AS VENCEM30, 'JA_EXECUTADA' AS STATUS LIMIT 0;
    ELSE
        SELECT r.CODUSUARIO, SUM(r.COR = 'VERMELHO') AS VERMELHOS, SUM(r.COR = 'AMARELO') AS AMARELOS,
               SUM(r.QTDDIAS BETWEEN 0 AND 30) AS VENCEM30, 'PENDENTE' AS STATUS
          FROM (SELECT i.CODUSUARIO_EMPRESA AS CODUSUARIO, i.COR, i.QTDDIAS FROM `vw_homologacao_painel_itens` i WHERE i.CODUSUARIO_EMPRESA IS NOT NULL AND i.COR IN ('VERMELHO', 'AMARELO')
                UNION ALL
                SELECT i.CODUSUARIO_FORNECEDOR, i.COR, i.QTDDIAS FROM `vw_homologacao_painel_itens` i
                 WHERE i.CODUSUARIO_FORNECEDOR IS NOT NULL AND i.CODUSUARIO_FORNECEDOR <> 99999 AND i.COR IN ('VERMELHO', 'AMARELO')
                   AND COALESCE(i.SITUACAO, '') <> 'Pendente com a empresa'
                   AND EXISTS (SELECT 1 FROM `fo_fornecedorxusuario` fu WHERE fu.CODFORNECEDOR = i.CODFORNECEDOR AND fu.CODUSUARIO = i.CODUSUARIO_FORNECEDOR)) r
          JOIN `usuarios` u ON u.codusuario = r.CODUSUARIO AND u.ativo = 1
         WHERE NOT EXISTS (SELECT 1 FROM `fo_painel_resumo_preferencias` p WHERE p.codusuario = r.CODUSUARIO AND p.receber = 0)
         GROUP BY r.CODUSUARIO;
        IF p_executar = 1 THEN
            INSERT IGNORE INTO `fo_painel_resumo_execucoes` (semana, usuarios) VALUES (p_semana, 0);
        END IF;
    END IF;
END
