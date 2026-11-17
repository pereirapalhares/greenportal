-- Migração 249 — vínculos dos Tipos de Funcionários (abas do legado foTiposdeFuncionarios: Critérios, Treinamentos,
-- Palavras chave), mantidos na tela homologacaofornecedores_tiposfuncionarios em duas listas (disponíveis / vinculados).
-- Aplicado por scripts/aplicar-migracao-249-tipos-funcionarios.php em cada banco de cliente, que troca o marcador
-- PALAVRAS (tabela de palavras-chave do banco universal, ex.: guniversal_dbp.palavras_chaves).
-- Leitura: views + sp_fo_tipofuncionario_vinculos_listar. Gravação: sp_fo_tipofuncionario_vinculos_gravar.
-- Comandos separados por "-- @@".

-- @@
-- Opções de cada lista: relacao (CRITERIOS / TREINAMENTOS / PALAVRASCHAVE), codigo e descricao.
CREATE OR REPLACE VIEW `vw_homologacao_tipofuncionario_opcoes` AS
SELECT 'CRITERIOS' AS relacao, c.codcriteriofuncionario AS codigo,
       CONVERT(CONCAT_WS(' / ', NULLIF(TRIM(c.tipo_de_documento), ''), c.DSCRITERIO) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS descricao
  FROM `fo_criteriofuncionarios` c
UNION ALL
SELECT 'TREINAMENTOS', t.CODTIPOTREINAMENTO, CONVERT(t.DSCTIPO USING utf8mb4) COLLATE utf8mb4_0900_ai_ci
  FROM `fo_tipotreinamentos` t
UNION ALL
SELECT 'PALAVRASCHAVE', p.CODPALAVRACHAVE, CONVERT(p.DSCPALAVRACHAVE USING utf8mb4) COLLATE utf8mb4_0900_ai_ci
  FROM {{PALAVRAS}} p

-- @@
-- Vínculos gravados: relacao, codtipofuncionario e codigo do item vinculado.
CREATE OR REPLACE VIEW `vw_homologacao_tipofuncionario_vinculos` AS
SELECT 'CRITERIOS' AS relacao, v.codtipofuncionario AS codtipofuncionario, v.codcriteriofuncionario AS codigo
  FROM `fo_tipofuncionariosxcriteriofuncionarios` v
UNION ALL
SELECT 'TREINAMENTOS', v.codtipofuncionario, v.codtipotreinamento FROM `fo_tipofuncionariosxtipotreinamentos` v
UNION ALL
SELECT 'PALAVRASCHAVE', v.codtipofuncionario, v.codpalavrachave FROM `fo_tipofuncionariosxpalavraschave` v

-- @@
-- Quantidade de vínculos por tipo (mostrada na tela principal).
CREATE OR REPLACE VIEW `vw_homologacao_tipofuncionario_vinculos_resumo` AS
SELECT t.codtipofuncionario AS CODTIPOFUNCIONARIO,
       (SELECT COUNT(*) FROM `fo_tipofuncionariosxcriteriofuncionarios` v WHERE v.codtipofuncionario = t.codtipofuncionario) AS QTD_CRITERIOS,
       (SELECT COUNT(*) FROM `fo_tipofuncionariosxtipotreinamentos` v WHERE v.codtipofuncionario = t.codtipofuncionario) AS QTD_TREINAMENTOS,
       (SELECT COUNT(*) FROM `fo_tipofuncionariosxpalavraschave` v WHERE v.codtipofuncionario = t.codtipofuncionario) AS QTD_PALAVRASCHAVE
  FROM `fo_tipofuncionarios` t

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_tipofuncionario_vinculos_listar`

-- @@
-- Lista as opções de uma relação com a indicação de vinculado (1) ou disponível (0) para o tipo.
-- 1º resultado: o tipo (código e descrição). 2º resultado: codigo, descricao, vinculado.
CREATE PROCEDURE `sp_fo_tipofuncionario_vinculos_listar`(IN p_relacao VARCHAR(20), IN p_codtipo BIGINT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_relacao VARCHAR(20) DEFAULT UPPER(TRIM(COALESCE(p_relacao, '')));
    IF v_relacao NOT IN ('CRITERIOS', 'TREINAMENTOS', 'PALAVRASCHAVE') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Relação inválida.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM `fo_tipofuncionarios` WHERE codtipofuncionario = p_codtipo) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipo de funcionário não encontrado.';
    END IF;
    SELECT codtipofuncionario AS CODTIPOFUNCIONARIO, DSCTIPO FROM `fo_tipofuncionarios` WHERE codtipofuncionario = p_codtipo;
    SELECT o.codigo, o.descricao,
           EXISTS (SELECT 1 FROM `vw_homologacao_tipofuncionario_vinculos` v
                    WHERE v.relacao = v_relacao AND v.codtipofuncionario = p_codtipo AND v.codigo = o.codigo) AS vinculado
      FROM `vw_homologacao_tipofuncionario_opcoes` o
     WHERE o.relacao = v_relacao
     ORDER BY o.descricao, o.codigo;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_tipofuncionario_vinculos_gravar`

-- @@
-- Vincula (p_incluir) e desvincula (p_remover) itens de uma relação. Listas JSON de códigos, ex.: [1, 5, 9].
-- Ignora códigos já vinculados / não vinculados; recusa códigos que não existem nas opções. Retorna incluidos e removidos.
CREATE PROCEDURE `sp_fo_tipofuncionario_vinculos_gravar`(IN p_relacao VARCHAR(20), IN p_codtipo BIGINT, IN p_incluir JSON, IN p_remover JSON)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_relacao VARCHAR(20) DEFAULT UPPER(TRIM(COALESCE(p_relacao, '')));
    DECLARE v_incluidos INT DEFAULT 0;
    DECLARE v_removidos INT DEFAULT 0;
    DECLARE v_invalidos INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    IF v_relacao NOT IN ('CRITERIOS', 'TREINAMENTOS', 'PALAVRASCHAVE') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Relação inválida.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM `fo_tipofuncionarios` WHERE codtipofuncionario = p_codtipo) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipo de funcionário não encontrado.';
    END IF;
    IF (p_incluir IS NOT NULL AND JSON_TYPE(p_incluir) <> 'ARRAY') OR (p_remover IS NOT NULL AND JSON_TYPE(p_remover) <> 'ARRAY') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lista de itens inválida.';
    END IF;

    SELECT COUNT(*) INTO v_invalidos
      FROM JSON_TABLE(COALESCE(p_incluir, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j
     WHERE j.codigo IS NULL OR NOT EXISTS (SELECT 1 FROM `vw_homologacao_tipofuncionario_opcoes` o WHERE o.relacao = v_relacao AND o.codigo = j.codigo);
    IF v_invalidos > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Há itens que não existem mais no cadastro. Atualize a tela e tente novamente.';
    END IF;

    START TRANSACTION;
    IF v_relacao = 'CRITERIOS' THEN
        INSERT INTO `fo_tipofuncionariosxcriteriofuncionarios` (codtipofuncionario, codcriteriofuncionario)
        SELECT DISTINCT p_codtipo, j.codigo FROM JSON_TABLE(COALESCE(p_incluir, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j
         WHERE NOT EXISTS (SELECT 1 FROM `fo_tipofuncionariosxcriteriofuncionarios` v WHERE v.codtipofuncionario = p_codtipo AND v.codcriteriofuncionario = j.codigo);
        SET v_incluidos = ROW_COUNT();
        DELETE v FROM `fo_tipofuncionariosxcriteriofuncionarios` v
          JOIN JSON_TABLE(COALESCE(p_remover, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j ON j.codigo = v.codcriteriofuncionario
         WHERE v.codtipofuncionario = p_codtipo;
        SET v_removidos = ROW_COUNT();
    ELSEIF v_relacao = 'TREINAMENTOS' THEN
        INSERT INTO `fo_tipofuncionariosxtipotreinamentos` (codtipofuncionario, codtipotreinamento)
        SELECT DISTINCT p_codtipo, j.codigo FROM JSON_TABLE(COALESCE(p_incluir, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j
         WHERE NOT EXISTS (SELECT 1 FROM `fo_tipofuncionariosxtipotreinamentos` v WHERE v.codtipofuncionario = p_codtipo AND v.codtipotreinamento = j.codigo);
        SET v_incluidos = ROW_COUNT();
        DELETE v FROM `fo_tipofuncionariosxtipotreinamentos` v
          JOIN JSON_TABLE(COALESCE(p_remover, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j ON j.codigo = v.codtipotreinamento
         WHERE v.codtipofuncionario = p_codtipo;
        SET v_removidos = ROW_COUNT();
    ELSE
        INSERT INTO `fo_tipofuncionariosxpalavraschave` (codtipofuncionario, codpalavrachave)
        SELECT DISTINCT p_codtipo, j.codigo FROM JSON_TABLE(COALESCE(p_incluir, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j
         WHERE NOT EXISTS (SELECT 1 FROM `fo_tipofuncionariosxpalavraschave` v WHERE v.codtipofuncionario = p_codtipo AND v.codpalavrachave = j.codigo);
        SET v_incluidos = ROW_COUNT();
        DELETE v FROM `fo_tipofuncionariosxpalavraschave` v
          JOIN JSON_TABLE(COALESCE(p_remover, JSON_ARRAY()), '$[*]' COLUMNS (codigo BIGINT PATH '$')) j ON j.codigo = v.codpalavrachave
         WHERE v.codtipofuncionario = p_codtipo;
        SET v_removidos = ROW_COUNT();
    END IF;
    COMMIT;
    SELECT v_incluidos AS incluidos, v_removidos AS removidos;
END
