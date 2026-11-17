-- Migração 358 — Minhas atividades (GER-5.1 / GER-5.2 do 01_Mudancas_Gerais; decisões do usuário de 12/11/2026):
-- uma tela com abas, cada aba é a tela do módulo filtrada para o usuário ("meu" = responsável direto ou por grupo/atribuição,
-- vw_responsabilidade_efetiva), salvar sim / incluir e excluir não; "Minhas Ações" junta os planos de ação.
-- Blocos separados por "-- @@"; aplicados por scripts/aplicar-migracao-358-minhas-atividades.php em cada empresa.
-- As views de fornecedores e funcionários (colunas da view da tela + CODUSUARIO_ACESSO) e o RESPONSAVEL_IDS dos requisitos
-- e a vw_minhas_acoes (só com os módulos que a empresa tem) são montados pelo script, a partir da definição atual do banco.

-- @@
-- Requisitos legais: quem responde por requisito + unidade (responsável da situação no legado ou atribuição, inclusive por grupo).
CREATE OR REPLACE VIEW `vw_ml_requisito_responsaveis` AS
SELECT s.`CODLEGISLACAO`, s.`codunidade` AS `CODUNIDADE`, s.`CODUSUARIO`
  FROM `legislacaoxsituacao` s
 WHERE COALESCE(s.`CODUSUARIO`, 0) > 0
UNION
SELECT s.`CODLEGISLACAO`, s.`codunidade`, e.`CODUSUARIO_DESTINO`
  FROM `vw_responsabilidade_efetiva` e
  JOIN `legislacaoxsituacao` s ON s.`CODSITUACAO` = CAST(e.`REGISTRO_ID` AS UNSIGNED)
 WHERE e.`ENTIDADE` = 'legislacaoxsituacao' AND COALESCE(e.`CODUSUARIO_DESTINO`, 0) > 0

-- @@
-- Minhas condicionantes: condicionantes das licenças ativas em que o usuário é o responsável (CODUSUARIO_ESCOPO = quem vê).
CREATE OR REPLACE VIEW `vw_minhas_condicionantes_grid` AS
SELECT c.`CODCONDICIONANTE`, c.`CODLICENCA`, l.`DSCLICENCA`, un.`DSCUNIDADE`, c.`DSCCONDICIONANTE`, c.`FREQUENCIA`, c.`ATENDIDO`,
       c.`CODUSUARIO`, COALESCE(u.`nome`, '') AS `RESPONSAVEL`, c.`OBS`,
       (SELECT COUNT(*) FROM `li_planodeacao` p WHERE p.`CODCONDICIONANTE` = c.`CODCONDICIONANTE`) AS `QTD_PLANOS_ACAO`,
       r.`CODUSUARIO_ESCOPO`
  FROM (SELECT `CODCONDICIONANTE`, `CODUSUARIO` AS `CODUSUARIO_ESCOPO` FROM `li_condicionantes` WHERE COALESCE(`CODUSUARIO`, 0) > 0
        UNION
        SELECT CAST(e.`REGISTRO_ID` AS UNSIGNED), e.`CODUSUARIO_DESTINO` FROM `vw_responsabilidade_efetiva` e
         WHERE e.`ENTIDADE` = 'li_condicionantes' AND COALESCE(e.`CODUSUARIO_DESTINO`, 0) > 0) r
  JOIN `li_condicionantes` c ON c.`CODCONDICIONANTE` = r.`CODCONDICIONANTE`
  JOIN `li_licencas` l ON l.`CODLICENCA` = c.`CODLICENCA`
  LEFT JOIN `unidades` un ON un.`CODUNIDADE` = l.`CODUNIDADE`
  LEFT JOIN `usuarios` u ON u.`codusuario` = c.`CODUSUARIO`
 WHERE COALESCE(l.`STATUS_VERSAO`, 'ATIVA' COLLATE utf8mb4_0900_ai_ci) = 'ATIVA' COLLATE utf8mb4_0900_ai_ci

-- @@
-- Meus fornecedores: responsável pelo fornecedor, responsável pela integração ou atribuição (vw_homologacao_responsabilidade_fornecedor).
CREATE OR REPLACE VIEW `vw_meus_fornecedores_resp` AS
SELECT `codfornecedor` AS `CODFORNECEDOR`, `codusuario_responsavel_fornecedor` AS `CODUSUARIO` FROM `fo_fornecedores` WHERE COALESCE(`codusuario_responsavel_fornecedor`, 0) > 0
UNION
SELECT `codfornecedor`, `codusuario_responsavel_integracao` FROM `fo_fornecedores` WHERE COALESCE(`codusuario_responsavel_integracao`, 0) > 0
UNION
SELECT `CODFORNECEDOR`, `CODUSUARIO` FROM `vw_homologacao_responsabilidade_fornecedor`

-- @@
-- Meus funcionários de fornecedores: os funcionários dos meus fornecedores e os atribuídos a mim.
CREATE OR REPLACE VIEW `vw_meus_funcionarios_resp` AS
SELECT fu.`CODFUNCIONARIO`, r.`CODUSUARIO`
  FROM `fo_funcionarios` fu JOIN `vw_meus_fornecedores_resp` r ON r.`CODFORNECEDOR` = fu.`CODFORNECEDOR`
UNION
SELECT CAST(e.`REGISTRO_ID` AS UNSIGNED), e.`CODUSUARIO_DESTINO` FROM `vw_responsabilidade_efetiva` e
 WHERE e.`ENTIDADE` = 'fo_funcionarios' AND COALESCE(e.`CODUSUARIO_DESTINO`, 0) > 0
