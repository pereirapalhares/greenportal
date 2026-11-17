-- Migração 262 — Portaria › Documentação de Funcionário (principal/portaria_documentacaofuncionario.php).
-- Legado: transação foFuncionariosXCriterios (hpr_configuracoes, codtransacao 195), somente consulta + evidências no GED
-- (E:\projetoGreen\GED\green_<empresa>\fo_funcionarioxtiposxcriterios\<codigo>) + análise por IA.
-- Pré-requisito: migração 260 (vw_portaria_usuario_unidades). Aplicado por
-- scripts/aplicar-migracao-262-portaria-documentacao-funcionario.php. Comandos separados por "-- @@".

-- @@
-- SITUACAO_DESCRICAO: texto da situação (coluna Situação na tela e no Excel), igual ao da tela.
-- Grid completo (ADMINISTRADOR): um critério exigido de um funcionário por linha (fo_funcionarioxtiposxcriterios).
-- Mesma regra do legado: funcionário com nome e fornecedor com unidade.
-- SITUACAO_COR (cor da linha). Migração 350 (decisão do usuário de 24/10/2026): mesma regra única de cores do Painel, com os dias
-- de reação do documento (30 quando não informado):
--   fornecedor-bloqueado  fornecedor bloqueado no ranking (funcionário não liberado)
--   nao-aplica          Atende = Desligado, Afastado ou NAP
--   reprovado           Atende = Não
--   vencido             validade passada
--   pendente-fornecedor / pendente-empresa   situação pendente
--   a-vencer            vence nos dias de reação
--   aprovado            Atende = Sim
--   sem-situacao        o resto
CREATE OR REPLACE VIEW `vw_portaria_documentacao_funcionario_grid` AS
SELECT g.*,
       CASE g.SITUACAO_COR
         WHEN 'reprovado' THEN 'Não atende'
         WHEN 'vencido' THEN 'Vencido'
         WHEN 'a-vencer' THEN 'Vence nos dias de reação'
         WHEN 'fornecedor-bloqueado' THEN 'Fornecedor bloqueado (não liberado)'
         WHEN 'aprovado' THEN 'Atende'
         WHEN 'pendente-fornecedor' THEN 'Pendente com o fornecedor'
         WHEN 'pendente-empresa' THEN 'Pendente com a empresa'
         WHEN 'nao-aplica' THEN 'Desligado / afastado / NAP'
         WHEN 'sem-situacao' THEN 'Sem informação'
         ELSE NULL
       END AS SITUACAO_DESCRICAO
  FROM (
SELECT r.codigo AS CODIGO,
       u.codunidade AS CODUNIDADE,
       u.DSCUNIDADE AS DSCUNIDADE,
       p.codfornecedor AS CODFORNECEDOR,
       p.razaosocial AS RAZAOSOCIAL,
       f.CODFUNCIONARIO AS CODFUNCIONARIO,
       f.NOME AS FUNCIONARIO,
       COALESCE(NULLIF(TRIM(us.nome), ''), IF(r.codusuario > 0, CAST(r.codusuario AS CHAR), NULL)) AS NOME,
       f.NRIDENTIFICACAO AS NRIDENTIFICACAO,
       t.DSCTIPO AS DSCTIPO,
       r.codcriteriofuncionario AS CODCRITERIOFUNCIONARIO,
       c.DSCRITERIO AS DSCRITERIO,
       r.atendeaocriterio AS ATENDEAOCRITERIO,
       r.situacao AS SITUACAO,
       r.responsaveldocumentacaofornecedor AS RESPONSAVELDOCUMENTACAOFORNECEDOR,
       CASE WHEN r.datadevalidade > '1900-01-01' THEN r.datadevalidade END AS DATADEVALIDADE,
       r.datadereacao AS DATADEREACAO,
       '' AS ARQUIVOS,
       r.observacao AS OBSERVACAO,
       -- Tem treinamento associado ao funcionário (botão Associações › Treinamentos)?
       IF(EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxtreinamentos` tr WHERE tr.codfuncionario = r.codfuncionario), 'Sim', 'Não') AS TEM_TREINAMENTO,
       CASE WHEN r.datadevalidade > '1900-01-01' THEN DATEDIFF(r.datadevalidade, CURDATE()) END AS QTDDIAS,
       CASE
         WHEN EXISTS (SELECT 1 FROM `fo_fornecedor_bloqueios` b WHERE b.codfornecedor = f.CODFORNECEDOR) THEN 'fornecedor-bloqueado'
         WHEN r.atendeaocriterio IN ('Desligado', 'Afastado', 'NAP') THEN 'nao-aplica'
         WHEN r.atendeaocriterio LIKE 'N%o' THEN 'reprovado'
         WHEN r.datadevalidade > '1900-01-01' AND r.datadevalidade < CURDATE() THEN 'vencido'
         WHEN r.situacao LIKE 'Pendente com o fornecedor%' THEN 'pendente-fornecedor'
         WHEN r.situacao LIKE 'Pendente com a empresa%' THEN 'pendente-empresa'
         WHEN r.datadevalidade > '1900-01-01' AND r.datadevalidade <= CURDATE() + INTERVAL COALESCE(NULLIF(r.datadereacao, 0), 30) DAY THEN 'a-vencer'
         WHEN r.atendeaocriterio = 'Sim' THEN 'aprovado'
         ELSE 'sem-situacao'
       END AS SITUACAO_COR
  FROM `fo_funcionarioxtiposxcriterios` r
  JOIN `fo_funcionarios` f ON f.CODFUNCIONARIO = r.codfuncionario AND f.NOME IS NOT NULL
  JOIN `fo_fornecedores` p ON p.codfornecedor = f.CODFORNECEDOR
  JOIN `unidades` u ON u.codunidade = p.codunidade AND u.DSCUNIDADE IS NOT NULL
  LEFT JOIN `fo_tipofuncionarios` t ON t.codtipofuncionario = r.codtipofuncionario
  LEFT JOIN `fo_criteriofuncionarios` c ON c.codcriteriofuncionario = r.codcriteriofuncionario
  LEFT JOIN `usuarios` us ON us.codusuario = r.codusuario
) g

-- @@
-- Grid restrito por unidade (demais perfis): a tela filtra CODUSUARIO_ACESSO = usuário logado.
CREATE OR REPLACE VIEW `vw_portaria_documentacao_funcionario_acesso` AS
SELECT g.*, uu.CODUSUARIO AS CODUSUARIO_ACESSO
  FROM `vw_portaria_documentacao_funcionario_grid` g
  JOIN `vw_portaria_usuario_unidades` uu ON uu.CODUNIDADE = g.CODUNIDADE

-- @@
DROP PROCEDURE IF EXISTS `sp_portaria_docfuncionario_permitido`

-- @@
CREATE PROCEDURE `sp_portaria_docfuncionario_permitido`(IN p_codigo INT, IN p_codusuario INT)
SELECT COUNT(*) AS PERMITIDO
  FROM `vw_portaria_documentacao_funcionario_acesso`
 WHERE CODIGO = p_codigo AND CODUSUARIO_ACESSO = p_codusuario

-- @@
-- Análises de IA das evidências (uma por arquivo analisado).
CREATE TABLE IF NOT EXISTS `fo_funcionarioxtiposxcriterios_documentos_ia` (
    `codanalise` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo` INT NOT NULL COMMENT 'fo_funcionarioxtiposxcriterios.codigo',
    `arquivo` VARCHAR(255) NOT NULL,
    `tipo_documento` VARCHAR(20) NULL COMMENT 'ASO, TREINAMENTO ou OUTRO',
    `fonte_texto` VARCHAR(20) NULL,
    `confianca_texto` DECIMAL(5,2) NULL,
    `linhas_omitidas` INT NOT NULL DEFAULT 0 COMMENT 'Linhas com dados de saúde removidas antes do envio à IA',
    `dados_ia` JSON NULL,
    `confianca` DECIMAL(5,2) NULL,
    `dados_confirmados` JSON NULL,
    `campos_aplicados` JSON NULL,
    `status` VARCHAR(12) NOT NULL DEFAULT 'PENDENTE' COMMENT 'PENDENTE, CONFIRMADA, DESCARTADA',
    `codusuario_analise` BIGINT NULL,
    `codusuario_confirmacao` BIGINT NULL,
    `analisado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `confirmado_em` DATETIME NULL,
    PRIMARY KEY (`codanalise`),
    KEY `idx_fo_ftc_doc_ia_registro` (`codigo`, `analisado_em`),
    KEY `idx_fo_ftc_doc_ia_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
CREATE TABLE IF NOT EXISTS `fo_sinc_protecoes` (
    `codprotecao` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tabela` VARCHAR(64) NOT NULL,
    `chave` VARCHAR(190) NOT NULL,
    `coluna` VARCHAR(64) NOT NULL,
    `origem` VARCHAR(30) NOT NULL DEFAULT 'IA_DOCUMENTO',
    `referencia` BIGINT NULL,
    `codusuario` BIGINT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codprotecao`),
    UNIQUE KEY `uk_fo_sinc_protecoes` (`tabela`, `chave`, `coluna`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
DROP PROCEDURE IF EXISTS `sp_docfuncionario_ia_registro`

-- @@
CREATE PROCEDURE `sp_docfuncionario_ia_registro`(IN p_codigo INT)
SELECT g.CODIGO, g.CODFUNCIONARIO, g.FUNCIONARIO, g.NRIDENTIFICACAO, g.DSCTIPO, g.RAZAOSOCIAL, g.DSCUNIDADE, g.DSCRITERIO,
       g.ATENDEAOCRITERIO, g.SITUACAO, g.DATADEVALIDADE, g.OBSERVACAO
  FROM `vw_portaria_documentacao_funcionario_grid` g
 WHERE g.CODIGO = p_codigo

-- @@
DROP PROCEDURE IF EXISTS `sp_docfuncionario_ia_gravar`

-- @@
CREATE PROCEDURE `sp_docfuncionario_ia_gravar`(IN p_codigo INT, IN p_arquivo VARCHAR(255), IN p_tipo VARCHAR(20), IN p_fonte VARCHAR(20),
    IN p_confianca_texto DECIMAL(5,2), IN p_omitidas INT, IN p_dados JSON, IN p_confianca DECIMAL(5,2), IN p_usuario BIGINT)
BEGIN
    INSERT INTO `fo_funcionarioxtiposxcriterios_documentos_ia` (codigo, arquivo, tipo_documento, fonte_texto, confianca_texto, linhas_omitidas, dados_ia, confianca, status, codusuario_analise)
    VALUES (p_codigo, p_arquivo, p_tipo, p_fonte, p_confianca_texto, p_omitidas, p_dados, p_confianca, 'PENDENTE', NULLIF(p_usuario, 0));
    SELECT LAST_INSERT_ID() AS codanalise;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_docfuncionario_ia_obter`

-- @@
CREATE PROCEDURE `sp_docfuncionario_ia_obter`(IN p_codanalise BIGINT, IN p_codigo INT)
SELECT codanalise, codigo, arquivo, tipo_documento, fonte_texto, linhas_omitidas, dados_ia, confianca, status, analisado_em
  FROM `fo_funcionarioxtiposxcriterios_documentos_ia`
 WHERE codanalise = p_codanalise AND codigo = p_codigo

-- @@
DROP PROCEDURE IF EXISTS `sp_docfuncionario_ia_descartar`

-- @@
CREATE PROCEDURE `sp_docfuncionario_ia_descartar`(IN p_codanalise BIGINT, IN p_usuario BIGINT)
BEGIN
    UPDATE `fo_funcionarioxtiposxcriterios_documentos_ia`
       SET status = 'DESCARTADA', codusuario_confirmacao = NULLIF(p_usuario, 0), confirmado_em = NOW()
     WHERE codanalise = p_codanalise AND status = 'PENDENTE';
    SELECT ROW_COUNT() AS alterados;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_docfuncionario_ia_confirmar`

-- @@
-- Campos NULL não mudam; p_situacao = '' limpa a situação (documento resolvido).
CREATE PROCEDURE `sp_docfuncionario_ia_confirmar`(IN p_codanalise BIGINT, IN p_codigo INT, IN p_validade DATE, IN p_atende VARCHAR(20),
    IN p_situacao VARCHAR(60), IN p_observacao VARCHAR(500), IN p_dados JSON, IN p_usuario BIGINT)
BEGIN
    DECLARE v_status VARCHAR(12);
    DECLARE v_aplicados JSON DEFAULT JSON_OBJECT();
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    SELECT status INTO v_status FROM `fo_funcionarioxtiposxcriterios_documentos_ia` WHERE codanalise = p_codanalise AND codigo = p_codigo;
    IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ANALISE_NAO_LOCALIZADA'; END IF;
    IF v_status <> 'PENDENTE' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ANALISE_ENCERRADA'; END IF;
    IF p_atende IS NOT NULL AND p_atende NOT IN ('Sim', 'Não', 'Desligado', 'NAP', 'Afastado') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ATENDE_INVALIDO'; END IF;
    IF p_situacao IS NOT NULL AND p_situacao NOT IN ('', 'Pendente com o fornecedor', 'Pendente com a empresa') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SITUACAO_INVALIDA'; END IF;

    START TRANSACTION;
    IF p_validade IS NOT NULL THEN
        UPDATE `fo_funcionarioxtiposxcriterios` SET datadevalidade = p_validade WHERE codigo = p_codigo;
        SET v_aplicados = JSON_SET(v_aplicados, '$.datadevalidade', DATE_FORMAT(p_validade, '%Y-%m-%d'));
    END IF;
    IF p_atende IS NOT NULL THEN
        UPDATE `fo_funcionarioxtiposxcriterios` SET atendeaocriterio = p_atende WHERE codigo = p_codigo;
        SET v_aplicados = JSON_SET(v_aplicados, '$.atendeaocriterio', p_atende);
    END IF;
    IF p_situacao IS NOT NULL THEN
        UPDATE `fo_funcionarioxtiposxcriterios` SET situacao = p_situacao WHERE codigo = p_codigo;
        SET v_aplicados = JSON_SET(v_aplicados, '$.situacao', p_situacao);
    END IF;
    IF p_observacao IS NOT NULL THEN
        UPDATE `fo_funcionarioxtiposxcriterios` SET observacao = p_observacao WHERE codigo = p_codigo;
        SET v_aplicados = JSON_SET(v_aplicados, '$.observacao', p_observacao);
    END IF;
    INSERT INTO `fo_sinc_protecoes` (tabela, chave, coluna, origem, referencia, codusuario)
    SELECT 'fo_funcionarioxtiposxcriterios', CAST(p_codigo AS CHAR), k.coluna, 'IA_DOCUMENTO', p_codanalise, NULLIF(p_usuario, 0)
      FROM JSON_TABLE(JSON_KEYS(v_aplicados), '$[*]' COLUMNS (coluna VARCHAR(64) PATH '$')) k
    ON DUPLICATE KEY UPDATE referencia = VALUES(referencia), codusuario = VALUES(codusuario), criado_em = CURRENT_TIMESTAMP;
    UPDATE `fo_funcionarioxtiposxcriterios_documentos_ia`
       SET status = 'CONFIRMADA', dados_confirmados = p_dados, campos_aplicados = v_aplicados,
           codusuario_confirmacao = NULLIF(p_usuario, 0), confirmado_em = NOW()
     WHERE codanalise = p_codanalise;
    COMMIT;
    SELECT v_aplicados AS aplicados;
END
