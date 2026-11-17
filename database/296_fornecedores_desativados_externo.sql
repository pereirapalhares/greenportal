-- Migração 296 — Homologação de Fornecedores, etapa 4: fornecedores desativados e acesso do fornecedor externo.
-- Legado: configuracoes/fo_fornecedor.php (filtro ativadofor = 'Sim' / ?desativado=S), fo_fornecedorxusuario.php e as
-- variáveis de sessão FORNECEDOR / FORNECEDOR_GESTOR / codfornecedor1 (lista de fornecedores do usuário).
-- Aplicado por scripts/aplicar-migracao-296-fornecedores-desativados-externo.php em cada banco de cliente.
-- Comandos separados por "-- @@". Pode ser reaplicado. Depende das migrações 216/289 (vw_homologacao_fornecedores_grid)
-- e 293 (vw_homologacao_fornecedor_documentos). Se a view vw_homologacao_fornecedores_grid for recriada com outras
-- colunas, rode esta migração de novo (as views abaixo copiam a lista de colunas dela).
--
-- Regras:
--  * Gestão de Fornecedores mostra só os ativos (Ativado? = Sim); a tela "Fornecedores desativados" mostra os outros
--    (Não ou vazio). Fornecedor novo sem "Ativado?" entra como Sim.
--  * Perfis do fornecedor externo (tabela perfis, códigos FORNECEDOR e FORNECEDOR_GESTOR): o usuário vê só os
--    fornecedores ligados a ele em Fornecedor x Usuários (fo_fornecedorxusuario), e só os ativos.
--    FORNECEDOR edita nos documentos só validade, protocolo, dias de reação, observação do fornecedor e evidências;
--    FORNECEDOR_GESTOR edita os documentos como a empresa.

-- @@
-- Fornecedores ativos (Gestão de Fornecedores).
CREATE OR REPLACE VIEW `vw_homologacao_fornecedores_ativos_grid` AS
SELECT g.* FROM `vw_homologacao_fornecedores_grid` g WHERE g.ATIVADOFOR = 'Sim'

-- @@
-- Fornecedores desativados (Não, vazio ou outro valor).
CREATE OR REPLACE VIEW `vw_homologacao_fornecedores_desativados_grid` AS
SELECT g.* FROM `vw_homologacao_fornecedores_grid` g WHERE COALESCE(g.ATIVADOFOR, '') <> 'Sim'

-- @@
-- Fornecedores (que existem) ligados a cada usuário, sem repetição.
CREATE OR REPLACE VIEW `vw_homologacao_fornecedores_do_usuario` AS
SELECT DISTINCT fu.CODUSUARIO AS CODUSUARIO, fu.CODFORNECEDOR AS CODFORNECEDOR
  FROM `fo_fornecedorxusuario` fu
  JOIN `fo_fornecedores` f ON f.codfornecedor = fu.CODFORNECEDOR
 WHERE fu.CODUSUARIO IS NOT NULL AND fu.CODUSUARIO > 0

-- @@
-- Gestão de Fornecedores do fornecedor externo: um registro por fornecedor ativo x usuário ligado.
-- A tela filtra sempre CODUSUARIO_ACESSO = usuário logado (user_scope_field + force_user_scope).
CREATE OR REPLACE VIEW `vw_homologacao_fornecedores_usuario_grid` AS
SELECT g.*, a.CODUSUARIO AS CODUSUARIO_ACESSO
  FROM `vw_homologacao_fornecedores_ativos_grid` g
  JOIN `vw_homologacao_fornecedores_do_usuario` a ON a.CODFORNECEDOR = g.CODFORNECEDOR

-- @@
-- Pendências do fornecedor externo na Home: documentos em que ele é o responsável do fornecedor (e continua ligado ao
-- fornecedor), de fornecedor ativo, que pedem ação dele: vermelho, amarelo ou sem situação, menos "Pendente com a empresa".
CREATE OR REPLACE VIEW `vw_homologacao_pendencias_fornecedor_externo` AS
SELECT d.CODFORNECEDORXCRITERIOS, d.CODFORNECEDOR, d.CODUSUARIO_FORNECEDOR AS CODUSUARIO,
       COALESCE(NULLIF(TRIM(f.nomefantasia), ''), NULLIF(TRIM(f.razaosocial), ''), CONCAT('Fornecedor ', d.CODFORNECEDOR)) AS FORNECEDOR,
       d.DSCTIPO, d.DSCRITERIO, d.SITUACAO, d.DATADEVALIDADE, d.QTDDIAS, d.COR
  FROM `vw_homologacao_fornecedor_documentos` d
  JOIN `fo_fornecedores` f ON f.codfornecedor = d.CODFORNECEDOR
 WHERE d.CODUSUARIO_FORNECEDOR IS NOT NULL AND d.CODUSUARIO_FORNECEDOR NOT IN (0, 99999)
   AND d.COR IN ('VERMELHO', 'AMARELO', 'BRANCO')
   AND COALESCE(d.SITUACAO, '') <> 'Pendente com a empresa'
   AND LOWER(TRIM(COALESCE(f.ativadofor, ''))) IN ('sim', 's', '1')
   AND EXISTS (SELECT 1 FROM `fo_fornecedorxusuario` fu WHERE fu.CODFORNECEDOR = d.CODFORNECEDOR AND fu.CODUSUARIO = d.CODUSUARIO_FORNECEDOR)

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_acesso_registro`

-- @@
-- O usuário pode acessar o registro? Usado para os perfis do fornecedor externo (a empresa não passa por aqui).
-- p_tipo: fornecedor | documento (fo_fornecedorxcriterios) | funcionario | funcionariocriterio | funcionariotreinamento.
-- Retorna CODFORNECEDOR do registro e PERMITIDO (1 = o fornecedor é ativo e está ligado ao usuário).
CREATE PROCEDURE `sp_fo_acesso_registro`(IN p_usuario BIGINT, IN p_tipo VARCHAR(40), IN p_id BIGINT)
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
           (v_forn IS NOT NULL AND EXISTS (SELECT 1 FROM `vw_homologacao_fornecedores_usuario_grid` a WHERE a.CODFORNECEDOR = v_forn AND a.CODUSUARIO_ACESSO = p_usuario)) AS PERMITIDO;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_fornecedor_usuarios_listar`

-- @@
-- Fornecedor x Usuários (duas listas): 1º conjunto = fornecedor; 2º = usuários ativos (e os inativos já ligados),
-- com o perfil na descrição e se estão ligados ao fornecedor.
CREATE PROCEDURE `sp_fo_fornecedor_usuarios_listar`(IN p_codfornecedor BIGINT)
SQL SECURITY INVOKER
BEGIN
    IF NOT EXISTS (SELECT 1 FROM `fo_fornecedores` WHERE codfornecedor = p_codfornecedor) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fornecedor não encontrado.';
    END IF;
    SELECT codfornecedor AS CODFORNECEDOR, RAZAOSOCIAL, NOMEFANTASIA FROM `fo_fornecedores` WHERE codfornecedor = p_codfornecedor;
    SELECT u.codusuario AS codigo,
           CONVERT(CONCAT(COALESCE(NULLIF(TRIM(u.nome), ''), u.userid), ' (', u.userid, ')',
                          IF(p.nome IS NULL OR TRIM(p.nome) = '', '', CONCAT(' — ', p.nome)),
                          IF(u.ativo = 1, '', ' — inativo')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS descricao,
           CONVERT(COALESCE(p.codigo, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS perfil,
           EXISTS (SELECT 1 FROM `fo_fornecedorxusuario` v WHERE v.CODFORNECEDOR = p_codfornecedor AND v.CODUSUARIO = u.codusuario) AS vinculado
      FROM `usuarios` u
      LEFT JOIN `perfis` p ON p.codperfil = u.codperfil
     WHERE u.ativo = 1 OR EXISTS (SELECT 1 FROM `fo_fornecedorxusuario` v WHERE v.CODFORNECEDOR = p_codfornecedor AND v.CODUSUARIO = u.codusuario)
     ORDER BY descricao, u.codusuario;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_fornecedor_usuarios_gravar`

-- @@
-- Liga / desliga usuários do fornecedor. Desligar não mexe nos documentos (o responsável do fornecedor continua
-- gravado, mas o usuário deixa de ver o fornecedor e as pendências dele).
CREATE PROCEDURE `sp_fo_fornecedor_usuarios_gravar`(IN p_codfornecedor BIGINT, IN p_incluir JSON, IN p_remover JSON)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_incluidos INT DEFAULT 0;
    DECLARE v_removidos INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF NOT EXISTS (SELECT 1 FROM `fo_fornecedores` WHERE codfornecedor = p_codfornecedor) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fornecedor não encontrado.';
    END IF;
    IF EXISTS (SELECT 1 FROM JSON_TABLE(COALESCE(p_incluir, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j
               WHERE NOT EXISTS (SELECT 1 FROM `usuarios` u WHERE u.codusuario = j.codigo)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Há usuários que não existem mais. Atualize a tela e tente novamente.';
    END IF;
    START TRANSACTION;
    INSERT INTO `fo_fornecedorxusuario` (CODFORNECEDOR, CODUSUARIO)
    SELECT DISTINCT p_codfornecedor, j.codigo FROM JSON_TABLE(COALESCE(p_incluir, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j
     WHERE NOT EXISTS (SELECT 1 FROM `fo_fornecedorxusuario` v WHERE v.CODFORNECEDOR = p_codfornecedor AND v.CODUSUARIO = j.codigo);
    SET v_incluidos = ROW_COUNT();
    DELETE v FROM `fo_fornecedorxusuario` v
      JOIN JSON_TABLE(COALESCE(p_remover, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j ON j.codigo = v.CODUSUARIO
     WHERE v.CODFORNECEDOR = p_codfornecedor;
    SET v_removidos = ROW_COUNT();
    COMMIT;
    SELECT v_incluidos AS incluidos, v_removidos AS removidos;
END

-- @@
DROP TRIGGER IF EXISTS `trg_fo_fornecedores_ativo_bi`

-- @@
-- Fornecedor incluído sem "Ativado?" entra ativo (senão sumiria da Gestão de Fornecedores).
CREATE TRIGGER `trg_fo_fornecedores_ativo_bi` BEFORE INSERT ON `fo_fornecedores` FOR EACH ROW
SET NEW.ativadofor = COALESCE(NULLIF(TRIM(NEW.ativadofor), ''), 'Sim')
