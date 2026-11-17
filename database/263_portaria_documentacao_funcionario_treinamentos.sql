-- Migração 263 — Portaria › Documentação de Funcionário › Treinamentos (botão de associação).
-- Legado: aba "Treinamento" (transação foFuncionariosxTreinamentos, hpr_configuracoes codtransacao 196): treinamentos do
-- funcionário da linha selecionada (fo_funcionarioxtiposxtreinamentos), somente consulta, com evidências no GED
-- (E:\projetoGreen\GED\green_<empresa>\fo_funcionarioxtiposxtreinamentos\<codigo>).
-- Pré-requisito: migração 260 (vw_portaria_usuario_unidades). Aplicado por
-- scripts/aplicar-migracao-263-portaria-documentacao-funcionario-treinamentos.php. Comandos separados por "-- @@".

-- @@
-- Treinamentos de todos os funcionários (ADMINISTRADOR); a tela filtra CODFUNCIONARIO da linha escolhida.
-- SITUACAO_COR: Realizado = Sim → vencido / a-vencer (até 30 dias) / aprovado; Realizado = Não → reprovado; resto sem-situacao.
CREATE OR REPLACE VIEW `vw_portaria_documentacao_treinamento_grid` AS
SELECT g.*,
       CASE g.SITUACAO_COR
         WHEN 'reprovado' THEN 'Não realizado'
         WHEN 'vencido' THEN 'Vencido'
         WHEN 'a-vencer' THEN 'Vence em até 30 dias'
         WHEN 'aprovado' THEN 'Realizado'
         WHEN 'sem-situacao' THEN 'Sem informação'
         ELSE NULL
       END AS SITUACAO_DESCRICAO
  FROM (
SELECT r.codigo AS CODIGO,
       r.codfuncionario AS CODFUNCIONARIO,
       f.NOME AS FUNCIONARIO,
       u.codunidade AS CODUNIDADE,
       r.codtipofuncionario AS CODTIPOFUNCIONARIO,
       tf.DSCTIPO AS DSCTIPO,
       r.codtipotreinamento AS CODTIPOTREINAMENTO,
       tt.DSCTIPO AS DSCTIPOTREINAMENTO,
       COALESCE(NULLIF(TRIM(us.nome), ''), IF(r.codusuario > 0, CAST(r.codusuario AS CHAR), NULL)) AS RESPONSAVEL,
       NULLIF(TRIM(r.treinamentorealizado), '') AS TREINAMENTOREALIZADO,
       NULLIF(TRIM(r.responsaveltreinamento), '') AS RESPONSAVELTREINAMENTO,
       CASE WHEN r.datadevalidadetreinamento > '1900-01-01' THEN r.datadevalidadetreinamento END AS DATADEVALIDADETREINAMENTO,
       CASE WHEN r.datadevalidadetreinamento > '1900-01-01' THEN DATEDIFF(r.datadevalidadetreinamento, CURDATE()) END AS QTDDIAS,
       r.datadereacaotreinamneto AS DATADEREACAO,
       '' AS ARQUIVOS,
       CASE
         WHEN r.treinamentorealizado = 'Sim' THEN
           CASE WHEN r.datadevalidadetreinamento IS NULL OR NOT (r.datadevalidadetreinamento > '1900-01-01') THEN 'aprovado'
                WHEN r.datadevalidadetreinamento < CURDATE() THEN 'vencido'
                WHEN r.datadevalidadetreinamento <= CURDATE() + INTERVAL 30 DAY THEN 'a-vencer'
                ELSE 'aprovado' END
         WHEN r.treinamentorealizado LIKE 'N%o' THEN 'reprovado'
         ELSE 'sem-situacao'
       END AS SITUACAO_COR
  FROM `fo_funcionarioxtiposxtreinamentos` r
  JOIN `fo_funcionarios` f ON f.CODFUNCIONARIO = r.codfuncionario
  LEFT JOIN `fo_fornecedores` p ON p.codfornecedor = f.CODFORNECEDOR
  LEFT JOIN `unidades` u ON u.codunidade = p.codunidade
  LEFT JOIN `fo_tipofuncionarios` tf ON tf.codtipofuncionario = r.codtipofuncionario
  LEFT JOIN `fo_tipotreinamentos` tt ON tt.codtipotreinamento = r.codtipotreinamento
  LEFT JOIN `usuarios` us ON us.codusuario = r.codusuario
) g

-- @@
-- Restrito por unidade (demais perfis): a tela filtra CODUSUARIO_ACESSO = usuário logado.
CREATE OR REPLACE VIEW `vw_portaria_documentacao_treinamento_acesso` AS
SELECT g.*, uu.CODUSUARIO AS CODUSUARIO_ACESSO
  FROM `vw_portaria_documentacao_treinamento_grid` g
  JOIN `vw_portaria_usuario_unidades` uu ON uu.CODUNIDADE = g.CODUNIDADE

-- @@
DROP PROCEDURE IF EXISTS `sp_portaria_doctreinamento_permitido`

-- @@
CREATE PROCEDURE `sp_portaria_doctreinamento_permitido`(IN p_codigo INT, IN p_codusuario INT)
SELECT COUNT(*) AS PERMITIDO
  FROM `vw_portaria_documentacao_treinamento_acesso`
 WHERE CODIGO = p_codigo AND CODUSUARIO_ACESSO = p_codusuario
