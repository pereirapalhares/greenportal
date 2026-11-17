-- Migração 260 — Portaria › Documentação de Fornecedor (principal/portaria_documentacaofornecedor.php).
-- Legado: transação foFornecedorxCriterios (hpr_configuracoes, codtransacao 194), somente consulta + evidências no GED.
-- Aplicado por scripts/aplicar-migracao-260-portaria-documentacao-fornecedor.php em cada banco de cliente, que troca:
--   {{DSCUNIDADE}}       descrição da unidade (coluna existente em unidades)
--   {{NIVEL}}            nível de exigência (coluna existente no vínculo tipo x critério ou no critério; senão NULL)
--   {{RESPONSAVEL}}      nome do usuário responsável (coluna existente em usuarios; senão o código)
--   {{USUARIO_UNIDADES}} consulta das unidades de cada usuário (usuariosxunidades, quando existir, + usuarios.codunidade)
-- Comandos separados por "-- @@".

-- @@
-- Unidades que cada usuário pode ver (uma linha por usuário x unidade).
CREATE OR REPLACE VIEW `vw_portaria_usuario_unidades` AS
{{USUARIO_UNIDADES}}

-- @@
-- SITUACAO_DESCRICAO: texto da situação (coluna Situação na tela e no Excel), igual ao da tela.
-- Grid completo (ADMINISTRADOR). Uma linha por documento exigido do fornecedor (fo_fornecedorxcriterios),
-- só quando o fornecedor tem o tipo e o tipo tem o critério (mesma regra da consulta do legado).
-- SITUACAO_COR liga a cor da linha à situação do documento. Migração 350 (decisão do usuário de 24/10/2026): mesma regra única
-- de cores do Painel (migração 293/298), com os dias de reação do documento (30 quando não informado):
--   fornecedor-bloqueado (fornecedor bloqueado no ranking: não liberado) | nao-aplica (NA, Não enviar ao fornecedor)
--   reprovado | vencido (validade passada) | pendente-fornecedor | pendente-empresa | a-vencer (vence nos dias de reação)
--   aprovado (Aprovado, Aprovado sem validade, Ciente da regra) | sem-situacao
CREATE OR REPLACE VIEW `vw_portaria_documentacao_fornecedor_grid` AS
SELECT g.*,
       CASE g.SITUACAO_COR
         WHEN 'reprovado' THEN 'Reprovado'
         WHEN 'vencido' THEN 'Vencido'
         WHEN 'a-vencer' THEN 'Vence nos dias de reação'
         WHEN 'fornecedor-bloqueado' THEN 'Fornecedor bloqueado (não liberado)'
         WHEN 'aprovado' THEN 'Aprovado'
         WHEN 'pendente-fornecedor' THEN 'Pendente com o fornecedor'
         WHEN 'pendente-empresa' THEN 'Pendente com a empresa'
         WHEN 'nao-aplica' THEN 'Não se aplica'
         WHEN 'sem-situacao' THEN 'Sem situação'
         ELSE NULL
       END AS SITUACAO_DESCRICAO
  FROM (
SELECT fc.codfornecedorxcriterios AS CODFORNECEDORXCRITERIOS,
       u.codunidade AS CODUNIDADE,
       {{DSCUNIDADE}} AS UNIDADEFORNECEDOR,
       f.codfornecedor AS CODFORNECEDOR,
       f.razaosocial AS RAZAOSOCIAL,
       CONCAT_WS('/', t.dsctipo, c.dscriterio) AS DSCRITERIO,
       {{NIVEL}} AS NIVEL_DE_EXIGENCIA,
       fc.enviadoodocumento AS ENVIADOODOCUMENTO,
       {{RESPONSAVEL}} AS RESPONSAVEL,
       '' AS ARQUIVOS,
       CASE WHEN fc.datadevalidade > '1900-01-01' THEN fc.datadevalidade END AS DATADEVALIDADE,
       IF(fc.emprotocolo = 'S', 'Sim', 'Não') AS EMPROTOCOLO,
       fc.situacao AS SITUACAO,
       fc.observacao AS OBSERVACAO,
       fc.observacao_do_fornecedor AS OBSERVACAO_DO_FORNECEDOR,
       CASE WHEN fc.datadevalidade > '1900-01-01' THEN DATEDIFF(fc.datadevalidade, CURDATE()) END AS QTDDIAS,
       CASE
         WHEN EXISTS (SELECT 1 FROM `fo_fornecedor_bloqueios` b WHERE b.codfornecedor = fc.codfornecedor) THEN 'fornecedor-bloqueado'
         WHEN fc.situacao IN ('NA', 'Não enviar ao fornecedor') THEN 'nao-aplica'
         WHEN fc.situacao LIKE 'Reprovad%' THEN 'reprovado'
         WHEN fc.datadevalidade > '1900-01-01' AND fc.datadevalidade < CURDATE() THEN 'vencido'
         WHEN fc.situacao LIKE 'Pendente com o fornecedor%' THEN 'pendente-fornecedor'
         WHEN fc.situacao LIKE 'Pendente com a empresa%' THEN 'pendente-empresa'
         WHEN fc.datadevalidade > '1900-01-01' AND fc.datadevalidade <= CURDATE() + INTERVAL COALESCE(NULLIF(fc.datadereacao, 0), 30) DAY THEN 'a-vencer'
         WHEN fc.situacao LIKE 'Aprovad%' OR fc.situacao = 'Ciente da regra' THEN 'aprovado'
         ELSE 'sem-situacao'
       END AS SITUACAO_COR
  FROM `fo_fornecedorxcriterios` fc
  JOIN `fo_fornecedores` f ON f.codfornecedor = fc.codfornecedor
  JOIN `unidades` u ON u.codunidade = f.codunidade
  JOIN `fo_tipofornecedores` t ON t.codtipofornecedor = fc.codtipo
  JOIN `fo_criteriofornecedores` c ON c.codcriteriofornecedor = fc.codcriterio
  {{JOIN_NIVEL}}
  {{JOIN_USUARIO}}
 WHERE EXISTS (SELECT 1 FROM `fo_fornecedoresxtipofornecedores` ft WHERE ft.codfornecedor = fc.codfornecedor AND ft.codtipofornecedor = fc.codtipo)
   AND EXISTS (SELECT 1 FROM `fo_tipofornecedoresxcriteriofornecedores` tc WHERE tc.codtipofornecedor = fc.codtipo AND tc.codcriteriofornecedor = fc.codcriterio)
) g

-- @@
-- Grid restrito por unidade (demais perfis): a tela filtra CODUSUARIO_ACESSO = usuário logado.
CREATE OR REPLACE VIEW `vw_portaria_documentacao_fornecedor_acesso` AS
SELECT g.*, uu.CODUSUARIO AS CODUSUARIO_ACESSO
  FROM `vw_portaria_documentacao_fornecedor_grid` g
  JOIN `vw_portaria_usuario_unidades` uu ON uu.CODUNIDADE = g.CODUNIDADE

-- @@
DROP PROCEDURE IF EXISTS `sp_portaria_docfornecedor_permitido`

-- @@
-- O usuário pode ver (e anexar evidências a) este documento? Usado pelo GED (classes/GedPermissaoTipo.php).
CREATE PROCEDURE `sp_portaria_docfornecedor_permitido`(IN p_codigo INT, IN p_codusuario INT)
SELECT COUNT(*) AS PERMITIDO
  FROM `vw_portaria_documentacao_fornecedor_acesso`
 WHERE CODFORNECEDORXCRITERIOS = p_codigo AND CODUSUARIO_ACESSO = p_codusuario
