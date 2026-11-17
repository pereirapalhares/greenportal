-- Migração 245 — Homologação de Fornecedores › Critérios dos funcionários
-- Transação: homologacaofornecedores_criteriosfuncionarios (menu HOMOLOGACAO_CRITERIOS_FUNCIONARIOS)
-- Tabela: fo_criteriofuncionarios (legado: configuracoes/fo_criteriofuncionarios.php)
--
-- Objetos criados em cada banco de cliente (gmodelo_dbp, gatlas_dbp e, se existir, gmodelo2_dbp).
-- Toda leitura da tela é feita pelas views abaixo e toda gravação pelas procedures sp_crud_* (CRUD controlado)
-- e pelas procedures deste domínio. Executado por scripts/aplicar-migracao-245-criterios-funcionarios.php,
-- que separa os comandos pela linha "-- @@" e preenche:
--   marcador USO_EXPR    soma das referências ao critério nas tabelas que o usam (descobertas no próprio banco);
--   marcador VALIDACOES  uma verificação por tabela que usa o critério (procedure de exclusão).
-- Pode ser executado mais de uma vez.

-- @@
-- Grid da transação: critério, tipo de documento e em quantos lugares o critério é usado.
CREATE OR REPLACE VIEW `vw_homologacao_criterios_funcionarios_grid` AS
SELECT c.codcriteriofuncionario AS CODCRITERIOFUNCIONARIO,
       c.DSCRITERIO AS DSCRITERIO,
       c.TIPO_DE_DOCUMENTO AS TIPO_DE_DOCUMENTO,
       ({{USO_EXPR}}) AS QTD_EM_USO
  FROM fo_criteriofuncionarios c

-- @@
-- Lista do campo Tipo de documento: os dois tipos do legado e qualquer outro valor já gravado.
CREATE OR REPLACE VIEW `vw_homologacao_criterios_funcionarios_tipos_documento` AS
SELECT CONVERT('Competência' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS TIPO_DE_DOCUMENTO
UNION
SELECT CONVERT('Regularização de Ordem Legal' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci
UNION
SELECT CONVERT(c.TIPO_DE_DOCUMENTO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci
  FROM fo_criteriofuncionarios c
 WHERE TRIM(COALESCE(c.TIPO_DE_DOCUMENTO, '')) <> ''

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_criteriofuncionario_validar_exclusao`

-- @@
-- Regra de exclusão: critério em uso não pode ser excluído (mensagem 1644 exibida na tela).
CREATE PROCEDURE `sp_fo_criteriofuncionario_validar_exclusao`(IN p_codcriterio BIGINT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_qtd BIGINT DEFAULT 0;
    DECLARE v_onde TEXT DEFAULT '';
    DECLARE v_msg VARCHAR(255);
{{VALIDACOES}}
    IF v_onde <> '' THEN
        SET v_msg = LEFT(CONCAT('Critério em uso, não pode ser excluído: ', v_onde, '.'), 255);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
END

-- @@
DROP TRIGGER IF EXISTS `trg_fo_criteriofuncionarios_antes_excluir`

-- @@
-- Qualquer exclusão (sp_crud_delete da tela ou outra rotina) passa pela regra acima.
CREATE TRIGGER `trg_fo_criteriofuncionarios_antes_excluir`
BEFORE DELETE ON `fo_criteriofuncionarios`
FOR EACH ROW CALL sp_fo_criteriofuncionario_validar_exclusao(OLD.codcriteriofuncionario)

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_criteriofuncionarios_comparar`

-- @@
-- Comparação origem (legado) x destino (este banco), pela chave. Devolve uma linha por diferença:
-- SO_NA_ORIGEM (falta no destino), SO_NO_DESTINO (criado no sistema novo ou excluído no legado) e
-- DIFERENTE (descrição ou tipo diferente; pode ser a correção de acentuação feita no sistema novo).
CREATE PROCEDURE `sp_fo_criteriofuncionarios_comparar`(IN p_origem VARCHAR(64))
SQL SECURITY INVOKER
BEGIN
    IF p_origem NOT REGEXP '^[A-Za-z0-9_]+$' OR NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = p_origem AND TABLE_NAME = 'fo_criteriofuncionarios'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Banco de origem sem a tabela fo_criteriofuncionarios';
    END IF;
    SET @sql_comparar = CONCAT(
        'SELECT ''SO_NA_ORIGEM'' AS situacao, o.codcriteriofuncionario AS codigo, ',
        '       CONVERT(o.DSCRITERIO USING utf8mb4) AS descricao_origem, NULL AS descricao_destino, ',
        '       CONVERT(o.TIPO_DE_DOCUMENTO USING utf8mb4) AS tipo_origem, NULL AS tipo_destino ',
        '  FROM `', p_origem, '`.fo_criteriofuncionarios o ',
        ' WHERE NOT EXISTS (SELECT 1 FROM fo_criteriofuncionarios d WHERE d.codcriteriofuncionario = o.codcriteriofuncionario) ',
        'UNION ALL ',
        'SELECT ''SO_NO_DESTINO'', d.codcriteriofuncionario, NULL, CONVERT(d.DSCRITERIO USING utf8mb4), NULL, CONVERT(d.TIPO_DE_DOCUMENTO USING utf8mb4) ',
        '  FROM fo_criteriofuncionarios d ',
        ' WHERE NOT EXISTS (SELECT 1 FROM `', p_origem, '`.fo_criteriofuncionarios o WHERE o.codcriteriofuncionario = d.codcriteriofuncionario) ',
        'UNION ALL ',
        'SELECT ''DIFERENTE'', d.codcriteriofuncionario, CONVERT(o.DSCRITERIO USING utf8mb4), CONVERT(d.DSCRITERIO USING utf8mb4), ',
        '       CONVERT(o.TIPO_DE_DOCUMENTO USING utf8mb4), CONVERT(d.TIPO_DE_DOCUMENTO USING utf8mb4) ',
        '  FROM fo_criteriofuncionarios d JOIN `', p_origem, '`.fo_criteriofuncionarios o ON o.codcriteriofuncionario = d.codcriteriofuncionario ',
        ' WHERE NOT (CONVERT(o.DSCRITERIO USING utf8mb4) COLLATE utf8mb4_bin <=> CONVERT(d.DSCRITERIO USING utf8mb4) COLLATE utf8mb4_bin) ',
        '    OR NOT (CONVERT(o.TIPO_DE_DOCUMENTO USING utf8mb4) COLLATE utf8mb4_bin <=> CONVERT(d.TIPO_DE_DOCUMENTO USING utf8mb4) COLLATE utf8mb4_bin) ',
        'ORDER BY 1, 2');
    PREPARE stmt_comparar FROM @sql_comparar;
    EXECUTE stmt_comparar;
    DEALLOCATE PREPARE stmt_comparar;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_criteriofuncionarios_migrar`

-- @@
-- Migração origem -> destino: inclui no destino os critérios que só existem na origem (mesma chave),
-- nas colunas comuns às duas tabelas. Não altera nem exclui nada que já exista no destino.
CREATE PROCEDURE `sp_fo_criteriofuncionarios_migrar`(IN p_origem VARCHAR(64))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_colunas TEXT;
    IF p_origem NOT REGEXP '^[A-Za-z0-9_]+$' OR NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = p_origem AND TABLE_NAME = 'fo_criteriofuncionarios'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Banco de origem sem a tabela fo_criteriofuncionarios';
    END IF;
    SELECT GROUP_CONCAT(CONCAT('`', d.COLUMN_NAME, '`') ORDER BY d.ORDINAL_POSITION SEPARATOR ', ') INTO v_colunas
      FROM information_schema.COLUMNS d
      JOIN information_schema.COLUMNS o ON o.TABLE_SCHEMA = p_origem AND o.TABLE_NAME = d.TABLE_NAME AND LOWER(o.COLUMN_NAME) = LOWER(d.COLUMN_NAME)
     WHERE d.TABLE_SCHEMA = DATABASE() AND d.TABLE_NAME = 'fo_criteriofuncionarios' AND d.EXTRA NOT LIKE '%GENERATED%';
    SET @sql_migrar = CONCAT(
        'INSERT INTO fo_criteriofuncionarios (', v_colunas, ') ',
        'SELECT ', v_colunas, ' FROM `', p_origem, '`.fo_criteriofuncionarios o ',
        ' WHERE NOT EXISTS (SELECT 1 FROM fo_criteriofuncionarios d WHERE d.codcriteriofuncionario = o.codcriteriofuncionario)');
    PREPARE stmt_migrar FROM @sql_migrar;
    EXECUTE stmt_migrar;
    SELECT ROW_COUNT() AS incluidos;
    DEALLOCATE PREPARE stmt_migrar;
END
