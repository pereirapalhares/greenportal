-- Migração 292 — vínculos dos Tipos de Fornecedores (abas do legado foTiposdeFornecedor: 139 Critérios + 140 Nível de
-- exigência, 141 Palavras chave), mantidos na tela homologacaofornecedores_tiposfornecedores em duas listas
-- (disponíveis / vinculados), com o nível de exigência de cada critério vinculado.
-- Aplicado por scripts/aplicar-migracao-292-tipos-criterios-fornecedores.php em cada banco de cliente, que troca o marcador
-- PALAVRAS (tabela de palavras-chave do banco universal, ex.: guniversal_dbp.palavras_chaves).
-- Leitura: views + sp_fo_tipofornecedor_vinculos_listar. Gravação: sp_fo_tipofornecedor_vinculos_gravar e
-- sp_fo_tipofornecedor_nivel_gravar. Níveis: 'Obrigatório', 'Desejável' ou vazio (não informado, só de dados antigos).
-- Comandos separados por "-- @@".

-- @@
-- Opções de cada lista: relacao (CRITERIOS / PALAVRASCHAVE), codigo e descricao.
CREATE OR REPLACE VIEW `vw_homologacao_tipofornecedor_opcoes` AS
SELECT 'CRITERIOS' AS relacao, c.codcriteriofornecedor AS codigo,
       CONVERT(c.DSCRITERIO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS descricao
  FROM `fo_criteriofornecedores` c
 WHERE TRIM(COALESCE(c.DSCRITERIO, '')) <> ''
UNION ALL
SELECT 'PALAVRASCHAVE', p.CODPALAVRACHAVE, CONVERT(p.DSCPALAVRACHAVE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci
  FROM {{PALAVRAS}} p

-- @@
-- Vínculos gravados: relacao, codtipofornecedor, codigo do item e nível de exigência (só critérios).
CREATE OR REPLACE VIEW `vw_homologacao_tipofornecedor_vinculos` AS
SELECT 'CRITERIOS' AS relacao, v.codtipofornecedor AS codtipofornecedor, v.codcriteriofornecedor AS codigo,
       CONVERT(COALESCE(v.nivel_de_exigencia, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS nivel_de_exigencia
  FROM `fo_tipofornecedoresxcriteriofornecedores` v
UNION ALL
SELECT 'PALAVRASCHAVE', v.codtipofornecedor, v.codpalavrachave, CONVERT('' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci
  FROM `fo_tipofornecedoresxpalavraschave` v

-- @@
-- Critérios exigidos por tipo, com descrição e nível (base da geração dos documentos dos fornecedores e dos relatórios).
CREATE OR REPLACE VIEW `vw_homologacao_tipofornecedor_criterios` AS
SELECT v.codchave AS CODCHAVE, v.codtipofornecedor AS CODTIPOFORNECEDOR, t.DSCTIPO AS DSCTIPO,
       t.necessita_auditoria AS NECESSITA_AUDITORIA, v.codcriteriofornecedor AS CODCRITERIOFORNECEDOR,
       c.DSCRITERIO AS DSCRITERIO, c.DSCRITERIO_EN AS DSCRITERIO_EN, COALESCE(v.nivel_de_exigencia, '') AS NIVEL_DE_EXIGENCIA
  FROM `fo_tipofornecedoresxcriteriofornecedores` v
  JOIN `fo_tipofornecedores` t ON t.codtipofornecedor = v.codtipofornecedor
  JOIN `fo_criteriofornecedores` c ON c.codcriteriofornecedor = v.codcriteriofornecedor

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_tipofornecedor_vinculos_listar`

-- @@
-- Lista as opções de uma relação com a indicação de vinculado (1) ou disponível (0) para o tipo.
-- 1º resultado: o tipo (código, descrição, necessita auditoria). 2º resultado: codigo, descricao, vinculado, nivel_de_exigencia.
CREATE PROCEDURE `sp_fo_tipofornecedor_vinculos_listar`(IN p_relacao VARCHAR(20), IN p_codtipo BIGINT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_relacao VARCHAR(20) DEFAULT UPPER(TRIM(COALESCE(p_relacao, '')));
    IF v_relacao NOT IN ('CRITERIOS', 'PALAVRASCHAVE') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Relação inválida.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM `fo_tipofornecedores` WHERE codtipofornecedor = p_codtipo) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipo de fornecedor não encontrado.';
    END IF;
    SELECT codtipofornecedor AS CODTIPOFORNECEDOR, DSCTIPO, necessita_auditoria AS NECESSITA_AUDITORIA
      FROM `fo_tipofornecedores` WHERE codtipofornecedor = p_codtipo;
    SELECT o.codigo, o.descricao,
           (v.codigo IS NOT NULL) AS vinculado,
           COALESCE(v.nivel_de_exigencia, '') AS nivel_de_exigencia
      FROM `vw_homologacao_tipofornecedor_opcoes` o
      LEFT JOIN `vw_homologacao_tipofornecedor_vinculos` v
             ON v.relacao = o.relacao AND v.codtipofornecedor = p_codtipo AND v.codigo = o.codigo
     WHERE o.relacao = v_relacao
     ORDER BY o.descricao, o.codigo;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_tipofornecedor_vinculos_gravar`

-- @@
-- Vincula (p_incluir) e desvincula (p_remover) itens de uma relação. Listas JSON de códigos, ex.: [1, 5, 9].
-- Critérios novos entram com o nível p_nivel ('Obrigatório' ou 'Desejável'; vazio = Obrigatório).
-- Ignora códigos já vinculados / não vinculados; recusa códigos que não existem nas opções. Retorna incluidos e removidos.
CREATE PROCEDURE `sp_fo_tipofornecedor_vinculos_gravar`(IN p_relacao VARCHAR(20), IN p_codtipo BIGINT, IN p_incluir JSON, IN p_remover JSON, IN p_nivel VARCHAR(25))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_relacao VARCHAR(20) DEFAULT UPPER(TRIM(COALESCE(p_relacao, '')));
    DECLARE v_nivel VARCHAR(25) DEFAULT TRIM(COALESCE(p_nivel, ''));
    DECLARE v_incluidos INT DEFAULT 0;
    DECLARE v_removidos INT DEFAULT 0;
    DECLARE v_invalidos INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    IF v_relacao NOT IN ('CRITERIOS', 'PALAVRASCHAVE') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Relação inválida.';
    END IF;
    IF v_nivel = '' THEN SET v_nivel = 'Obrigatório'; END IF;
    IF v_nivel NOT IN ('Obrigatório', 'Desejável') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nível de exigência inválido.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM `fo_tipofornecedores` WHERE codtipofornecedor = p_codtipo) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipo de fornecedor não encontrado.';
    END IF;
    IF (p_incluir IS NOT NULL AND JSON_TYPE(p_incluir) <> 'ARRAY') OR (p_remover IS NOT NULL AND JSON_TYPE(p_remover) <> 'ARRAY') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lista de itens inválida.';
    END IF;

    SELECT COUNT(*) INTO v_invalidos
      FROM JSON_TABLE(COALESCE(p_incluir, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j
     WHERE j.codigo IS NULL OR NOT EXISTS (SELECT 1 FROM `vw_homologacao_tipofornecedor_opcoes` o WHERE o.relacao = v_relacao AND o.codigo = j.codigo);
    IF v_invalidos > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Há itens que não existem mais no cadastro. Atualize a tela e tente novamente.';
    END IF;

    START TRANSACTION;
    IF v_relacao = 'CRITERIOS' THEN
        INSERT INTO `fo_tipofornecedoresxcriteriofornecedores` (codtipofornecedor, codcriteriofornecedor, nivel_de_exigencia)
        SELECT DISTINCT p_codtipo, j.codigo, v_nivel FROM JSON_TABLE(COALESCE(p_incluir, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j
         WHERE NOT EXISTS (SELECT 1 FROM `fo_tipofornecedoresxcriteriofornecedores` v WHERE v.codtipofornecedor = p_codtipo AND v.codcriteriofornecedor = j.codigo);
        SET v_incluidos = ROW_COUNT();
        DELETE v FROM `fo_tipofornecedoresxcriteriofornecedores` v
          JOIN JSON_TABLE(COALESCE(p_remover, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j ON j.codigo = v.codcriteriofornecedor
         WHERE v.codtipofornecedor = p_codtipo;
        SET v_removidos = ROW_COUNT();
    ELSE
        INSERT INTO `fo_tipofornecedoresxpalavraschave` (codtipofornecedor, codpalavrachave)
        SELECT DISTINCT p_codtipo, j.codigo FROM JSON_TABLE(COALESCE(p_incluir, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j
         WHERE NOT EXISTS (SELECT 1 FROM `fo_tipofornecedoresxpalavraschave` v WHERE v.codtipofornecedor = p_codtipo AND v.codpalavrachave = j.codigo);
        SET v_incluidos = ROW_COUNT();
        DELETE v FROM `fo_tipofornecedoresxpalavraschave` v
          JOIN JSON_TABLE(COALESCE(p_remover, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j ON j.codigo = v.codpalavrachave
         WHERE v.codtipofornecedor = p_codtipo;
        SET v_removidos = ROW_COUNT();
    END IF;
    COMMIT;
    SELECT v_incluidos AS incluidos, v_removidos AS removidos;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_tipofornecedor_nivel_gravar`

-- @@
-- Altera o nível de exigência de critérios já vinculados ao tipo (lista JSON de códigos de critério).
CREATE PROCEDURE `sp_fo_tipofornecedor_nivel_gravar`(IN p_codtipo BIGINT, IN p_criterios JSON, IN p_nivel VARCHAR(25))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_nivel VARCHAR(25) DEFAULT TRIM(COALESCE(p_nivel, ''));
    IF v_nivel NOT IN ('Obrigatório', 'Desejável') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nível de exigência inválido.';
    END IF;
    IF p_criterios IS NULL OR JSON_TYPE(p_criterios) <> 'ARRAY' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lista de itens inválida.';
    END IF;
    UPDATE `fo_tipofornecedoresxcriteriofornecedores` v
      JOIN JSON_TABLE(p_criterios, '$[*]' COLUMNS (codigo BIGINT PATH '$')) j ON j.codigo = v.codcriteriofornecedor
       SET v.nivel_de_exigencia = v_nivel
     WHERE v.codtipofornecedor = p_codtipo;
    SELECT ROW_COUNT() AS alterados;
END
