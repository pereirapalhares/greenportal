-- Migração 248 — modelo de objetos de banco para cadastros simples de Homologação de Fornecedores
-- (uma tabela fo_* com chave numérica e uma descrição), por exemplo Tipos de treinamento.
-- O script scripts/aplicar-migracao-248-cadastros-homologacao.php aplica este modelo para cada cadastro,
-- trocando os marcadores (sem chaves duplas no texto, para não serem confundidos com eles):
--   marcador TABELA      tabela (ex.: fo_tipotreinamentos)
--   marcador PK          coluna chave (ex.: CODTIPOTREINAMENTO)
--   marcador DSC         coluna descrição (ex.: DSCTIPO)
--   marcador VIEW        nome da view do grid
--   marcador USO_EXPR    soma das referências nas tabelas que usam o registro (descobertas no próprio banco)
--   marcador VALIDACOES  uma verificação por tabela que usa o registro
--   marcador ROTULO      nome do cadastro na mensagem ("Tipo de treinamento")
--   marcador EXTRAS      colunas a mais da view (migração 292), ex.: ", c.`necessita_auditoria` AS `NECESSITA_AUDITORIA`"; vazio se não houver
--   marcador FILHOS      exclusões em cascata das tabelas que pertencem ao registro (migração 249; vazio se não houver),
--                        por exemplo os critérios e treinamentos ligados a um tipo de funcionário
-- Leitura da tela: VIEW (sp_crud_select). Gravação: sp_crud_insert/update/delete. Exclusão de registro em uso:
-- bloqueada pelo gatilho, que chama a procedure de validação. Comandos separados por "-- @@".

-- @@
CREATE OR REPLACE VIEW `{{VIEW}}` AS
SELECT c.`{{PK}}` AS `{{PK}}`, c.`{{DSC}}` AS `{{DSC}}`{{EXTRAS}}, ({{USO_EXPR}}) AS QTD_EM_USO
  FROM `{{TABELA}}` c

-- @@
DROP PROCEDURE IF EXISTS `sp_{{TABELA}}_validar_exclusao`

-- @@
CREATE PROCEDURE `sp_{{TABELA}}_validar_exclusao`(IN p_codigo BIGINT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_qtd BIGINT DEFAULT 0;
    DECLARE v_onde TEXT DEFAULT '';
    DECLARE v_msg VARCHAR(255);
{{VALIDACOES}}
    IF v_onde <> '' THEN
        SET v_msg = LEFT(CONCAT('{{ROTULO}} em uso, não pode ser excluído: ', v_onde, '.'), 255);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
END

-- @@
DROP TRIGGER IF EXISTS `trg_{{TABELA}}_antes_excluir`

-- @@
CREATE TRIGGER `trg_{{TABELA}}_antes_excluir`
BEFORE DELETE ON `{{TABELA}}`
FOR EACH ROW
BEGIN
    CALL `sp_{{TABELA}}_validar_exclusao`(OLD.`{{PK}}`);
{{FILHOS}}
END

-- @@
DROP PROCEDURE IF EXISTS `sp_{{TABELA}}_comparar`

-- @@
-- Diferenças origem (legado) x destino pela chave: SO_NA_ORIGEM, SO_NO_DESTINO e DIFERENTE (descrição).
CREATE PROCEDURE `sp_{{TABELA}}_comparar`(IN p_origem VARCHAR(64))
SQL SECURITY INVOKER
BEGIN
    IF p_origem NOT REGEXP '^[A-Za-z0-9_]+$' OR NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = p_origem AND TABLE_NAME = '{{TABELA}}'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Banco de origem sem a tabela {{TABELA}}';
    END IF;
    SET @sql_comparar = CONCAT(
        'SELECT ''SO_NA_ORIGEM'' AS situacao, o.`{{PK}}` AS codigo, CONVERT(o.`{{DSC}}` USING utf8mb4) AS descricao_origem, NULL AS descricao_destino ',
        '  FROM `', p_origem, '`.`{{TABELA}}` o WHERE NOT EXISTS (SELECT 1 FROM `{{TABELA}}` d WHERE d.`{{PK}}` = o.`{{PK}}`) ',
        'UNION ALL ',
        'SELECT ''SO_NO_DESTINO'', d.`{{PK}}`, NULL, CONVERT(d.`{{DSC}}` USING utf8mb4) ',
        '  FROM `{{TABELA}}` d WHERE NOT EXISTS (SELECT 1 FROM `', p_origem, '`.`{{TABELA}}` o WHERE o.`{{PK}}` = d.`{{PK}}`) ',
        'UNION ALL ',
        'SELECT ''DIFERENTE'', d.`{{PK}}`, CONVERT(o.`{{DSC}}` USING utf8mb4), CONVERT(d.`{{DSC}}` USING utf8mb4) ',
        '  FROM `{{TABELA}}` d JOIN `', p_origem, '`.`{{TABELA}}` o ON o.`{{PK}}` = d.`{{PK}}` ',
        ' WHERE NOT (CONVERT(o.`{{DSC}}` USING utf8mb4) COLLATE utf8mb4_bin <=> CONVERT(d.`{{DSC}}` USING utf8mb4) COLLATE utf8mb4_bin) ',
        'ORDER BY 1, 2');
    PREPARE stmt_comparar FROM @sql_comparar;
    EXECUTE stmt_comparar;
    DEALLOCATE PREPARE stmt_comparar;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_{{TABELA}}_migrar`

-- @@
-- Inclui no destino os registros que só existem na origem (mesma chave), nas colunas comuns. Não altera nem exclui.
CREATE PROCEDURE `sp_{{TABELA}}_migrar`(IN p_origem VARCHAR(64))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_colunas TEXT;
    IF p_origem NOT REGEXP '^[A-Za-z0-9_]+$' OR NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = p_origem AND TABLE_NAME = '{{TABELA}}'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Banco de origem sem a tabela {{TABELA}}';
    END IF;
    SELECT GROUP_CONCAT(CONCAT('`', d.COLUMN_NAME, '`') ORDER BY d.ORDINAL_POSITION SEPARATOR ', ') INTO v_colunas
      FROM information_schema.COLUMNS d
      JOIN information_schema.COLUMNS o ON o.TABLE_SCHEMA = p_origem AND o.TABLE_NAME = d.TABLE_NAME AND LOWER(o.COLUMN_NAME) = LOWER(d.COLUMN_NAME)
     WHERE d.TABLE_SCHEMA = DATABASE() AND d.TABLE_NAME = '{{TABELA}}' AND d.EXTRA NOT LIKE '%GENERATED%';
    SET @sql_migrar = CONCAT('INSERT INTO `{{TABELA}}` (', v_colunas, ') SELECT ', v_colunas, ' FROM `', p_origem, '`.`{{TABELA}}` o ',
        'WHERE NOT EXISTS (SELECT 1 FROM `{{TABELA}}` d WHERE d.`{{PK}}` = o.`{{PK}}`)');
    PREPARE stmt_migrar FROM @sql_migrar;
    EXECUTE stmt_migrar;
    SELECT ROW_COUNT() AS incluidos;
    DEALLOCATE PREPARE stmt_migrar;
END
