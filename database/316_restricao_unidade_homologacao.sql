-- Migração 316 — Homologação de Fornecedores: restrição por unidade do usuário interno (19/10/2026).
-- Legado: configuracoes/fo_fornecedor.php (Gestão de Fornecedores), fo_fornecedores_tree.php (Gestão de Funcionários) e a
-- Portaria filtram CODUNIDADE in (select codunidade from <banco>_seguranca.usuariosxunidades where codusuario = logado)
-- para quem não é administrador.
-- Decisões do usuário (19/10/2026):
--   * ADMINISTRADOR e ADM_DA_EMPRESA veem todos os fornecedores; os demais perfis internos, só os das suas unidades;
--   * usuário interno sem unidade não vê nenhum fornecedor (como no legado);
--   * o responsável continua vendo os fornecedores pelos quais responde, mesmo de outra unidade (documento, critério ou
--     treinamento de funcionário, plano de ação, responsável pelo fornecedor/integração, responsabilidade atribuída) —
--     senão as pendências da Home abririam telas vazias.
-- Fornecedor externo (FORNECEDOR / FORNECEDOR_GESTOR) continua com a regra da migração 296 (Fornecedor x Usuários).
--
-- Aplicado por scripts/aplicar-migracao-316-restricao-unidade-homologacao.php em cada banco de cliente, que antes:
--   1. cria `usuariosxunidades` no greenportal (quando não existe) e copia a do legado (<legado>_seguranca.usuariosxunidades);
--   2. reaplica a migração 260 (vw_portaria_usuario_unidades passa a ler usuariosxunidades);
--   e troca {{RESPONSABILIDADES}} pela parte da responsabilidade atribuída (vw_homologacao_responsabilidade_fornecedor, 312).
-- Depois recria os procedimentos do Painel (298) e dos Relatórios (314), que aceitam p_externo = 2 (interno restrito).
-- Comandos separados por "-- @@". Pode ser reaplicado. Só views e procedimento (os dados não mudam).

-- @@
-- Modo de acesso de cada usuário à Homologação: 0 = vê tudo (ADMINISTRADOR, ADM_DA_EMPRESA), 1 = fornecedor externo,
-- 2 = interno restrito às suas unidades. Mesma regra de classes/HomologacaoFornecedorAcesso::modo() (usada sem sessão
-- pelo envio semanal dos relatórios).
CREATE OR REPLACE VIEW `vw_homologacao_usuario_acesso` AS
SELECT x.CODUSUARIO, x.PERFIL,
       -- FORNECEDORES (Crown) = FORNECEDOR (migração 319)
       CASE WHEN x.PERFIL IN ('FORNECEDOR', 'FORNECEDOR_GESTOR', 'FORNECEDORES') THEN 1
            WHEN x.PERFIL IN ('ADMINISTRADOR', 'ADM_DA_EMPRESA') THEN 0
            ELSE 2 END AS MODO
  FROM (SELECT u.codusuario AS CODUSUARIO,
               CONVERT(REGEXP_REPLACE(UPPER(TRIM(COALESCE(p.codigo, ''))), '[[:space:]-]+', '_') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS PERFIL
          FROM `usuarios` u LEFT JOIN `perfis` p ON p.codperfil = u.codperfil) x

-- @@
-- Fornecedores que cada usuário interno restrito pode ver: os das suas unidades e aqueles pelos quais ele responde.
CREATE OR REPLACE VIEW `vw_homologacao_fornecedores_acesso_interno` AS
SELECT uu.CODUSUARIO AS CODUSUARIO, f.codfornecedor AS CODFORNECEDOR
  FROM `fo_fornecedores` f JOIN `vw_portaria_usuario_unidades` uu ON uu.CODUNIDADE = f.codunidade
UNION SELECT f.CODUSUARIO_RESPONSAVEL_FORNECEDOR, f.codfornecedor FROM `fo_fornecedores` f WHERE f.CODUSUARIO_RESPONSAVEL_FORNECEDOR > 0
UNION SELECT f.CODUSUARIO_RESPONSAVEL_INTEGRACAO, f.codfornecedor FROM `fo_fornecedores` f WHERE f.CODUSUARIO_RESPONSAVEL_INTEGRACAO > 0
UNION SELECT d.codusuario, d.codfornecedor FROM `fo_fornecedorxcriterios` d WHERE d.codusuario > 0
UNION SELECT x.codusuario, fu.CODFORNECEDOR FROM `fo_funcionarioxtiposxcriterios` x JOIN `fo_funcionarios` fu ON fu.CODFUNCIONARIO = x.codfuncionario WHERE x.codusuario > 0
UNION SELECT t.codusuario, fu.CODFORNECEDOR FROM `fo_funcionarioxtiposxtreinamentos` t JOIN `fo_funcionarios` fu ON fu.CODFUNCIONARIO = t.codfuncionario WHERE t.codusuario > 0
UNION SELECT pa.codusuario, pa.codfornecedor FROM `fo_planodeacao` pa WHERE pa.codusuario > 0
{{RESPONSABILIDADES}}

-- @@
-- Gestão de Fornecedores do interno restrito (ativos): a tela filtra CODUSUARIO_ACESSO = usuário logado.
CREATE OR REPLACE VIEW `vw_homologacao_fornecedores_unidade_grid` AS
SELECT g.*, a.CODUSUARIO AS CODUSUARIO_ACESSO
  FROM `vw_homologacao_fornecedores_ativos_grid` g
  JOIN `vw_homologacao_fornecedores_acesso_interno` a ON a.CODFORNECEDOR = g.CODFORNECEDOR

-- @@
-- Fornecedores desativados do interno restrito.
CREATE OR REPLACE VIEW `vw_homologacao_fornecedores_desativados_unidade_grid` AS
SELECT g.*, a.CODUSUARIO AS CODUSUARIO_ACESSO
  FROM `vw_homologacao_fornecedores_desativados_grid` g
  JOIN `vw_homologacao_fornecedores_acesso_interno` a ON a.CODFORNECEDOR = g.CODFORNECEDOR

-- @@
-- Gestão de Funcionários do interno restrito (funcionários dos fornecedores que ele pode ver).
CREATE OR REPLACE VIEW `vw_homologacao_funcionarios_unidade_grid` AS
SELECT g.*, a.CODUSUARIO AS CODUSUARIO_ACESSO
  FROM `vw_homologacao_funcionarios_grid` g
  JOIN `vw_homologacao_fornecedores_acesso_interno` a ON a.CODFORNECEDOR = g.CODFORNECEDOR

-- @@
-- Unidades de cada usuário com a descrição (lista do campo Unidade na Gestão de Fornecedores e filtros do Painel e Relatórios).
CREATE OR REPLACE VIEW `vw_homologacao_unidades_usuario_grid` AS
SELECT u.CODUNIDADE AS CODUNIDADE, u.DSCUNIDADE AS DSCUNIDADE, uu.CODUSUARIO AS CODUSUARIO_ACESSO
  FROM `vw_unidades_grid` u
  JOIN `vw_portaria_usuario_unidades` uu ON uu.CODUNIDADE = u.CODUNIDADE

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_acesso_interno_registro`

-- @@
-- O usuário interno restrito pode acessar o registro? Mesmos tipos de sp_fo_acesso_registro (296).
-- Retorna CODFORNECEDOR do registro e PERMITIDO (1 = fornecedor da unidade dele ou pelo qual ele responde).
CREATE PROCEDURE `sp_fo_acesso_interno_registro`(IN p_usuario BIGINT, IN p_tipo VARCHAR(40), IN p_id BIGINT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_forn BIGINT DEFAULT NULL;
    DECLARE v_tipo VARCHAR(40) DEFAULT LOWER(TRIM(COALESCE(p_tipo, '')));
    IF v_tipo = 'fornecedor' THEN
        SELECT codfornecedor INTO v_forn FROM `fo_fornecedores` WHERE codfornecedor = p_id LIMIT 1;
    ELSEIF v_tipo IN ('documento', 'fornecedorcriterios') THEN
        SELECT codfornecedor INTO v_forn FROM `fo_fornecedorxcriterios` WHERE codfornecedorxcriterios = p_id LIMIT 1;
    ELSEIF v_tipo IN ('funcionario', 'funcionarios') THEN
        SELECT CODFORNECEDOR INTO v_forn FROM `fo_funcionarios` WHERE CODFUNCIONARIO = p_id LIMIT 1;
    ELSEIF v_tipo IN ('funcionariocriterio', 'funcionariocriterios') THEN
        SELECT f.CODFORNECEDOR INTO v_forn FROM `fo_funcionarioxtiposxcriterios` x JOIN `fo_funcionarios` f ON f.CODFUNCIONARIO = x.codfuncionario WHERE x.codigo = p_id LIMIT 1;
    ELSEIF v_tipo IN ('funcionariotreinamento', 'funcionariotreinamentos') THEN
        SELECT f.CODFORNECEDOR INTO v_forn FROM `fo_funcionarioxtiposxtreinamentos` x JOIN `fo_funcionarios` f ON f.CODFUNCIONARIO = x.codfuncionario WHERE x.codigo = p_id LIMIT 1;
    END IF;
    SELECT v_forn AS CODFORNECEDOR,
           (v_forn IS NOT NULL AND EXISTS (SELECT 1 FROM `vw_homologacao_fornecedores_acesso_interno` a WHERE a.CODFORNECEDOR = v_forn AND a.CODUSUARIO = p_usuario)) AS PERMITIDO;
END
