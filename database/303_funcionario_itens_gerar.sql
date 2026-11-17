-- Migração 303 — Homologação de Fornecedores: critérios (documentos) e treinamentos do funcionário gerados pelo tipo de funcionário.
-- Legado: configuracoes/fo_funcionarioxtiposxcriterios.php mostra os critérios do tipo do funcionário e grava ao salvar;
-- configuracoes/fo_funcionarioxtiposxtreinamentos.php insere os treinamentos do tipo ao abrir a aba.
-- Greenportal (mesmo resultado, sem depender de abrir a tela): gatilhos na inclusão do funcionário, na troca do tipo e na inclusão
-- de critério/treinamento no tipo; a procedure também é chamada ao abrir as telas de Critérios e Treinamentos do funcionário
-- (classes/HomologacaoFornecedorFuncionarioRelacionamentoGrid.php). Nada é apagado: itens de um tipo antigo continuam guardados.
-- Só gera itens cujo critério/treinamento existe no cadastro (o tipo pode ter vínculos órfãos vindos da carga do legado).
-- Aplicado por scripts/aplicar-migracao-303-funcionario-itens.php. Comandos separados por "-- @@".

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_funcionario_itens_gerar`

-- @@
-- p_codfuncionario: um funcionário; NULL ou 0 = todos os funcionários ativos. p_aplicar = 0 só conta.
-- Retorna CRITERIOS e TREINAMENTOS (quantos foram criados ou seriam criados).
CREATE PROCEDURE `sp_fo_funcionario_itens_gerar`(IN p_codfuncionario BIGINT, IN p_aplicar TINYINT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_crit INT DEFAULT 0;
    DECLARE v_trei INT DEFAULT 0;
    DECLARE v_todos TINYINT DEFAULT COALESCE(p_codfuncionario, 0) = 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    SELECT COUNT(*) INTO v_crit
      FROM `fo_funcionarios` f
      JOIN `fo_tipofuncionariosxcriteriofuncionarios` tc ON tc.codtipofuncionario = f.CODTIPOFUNCIONARIO
      JOIN `fo_criteriofuncionarios` cc ON cc.codcriteriofuncionario = tc.codcriteriofuncionario
     WHERE (v_todos = 0 AND f.CODFUNCIONARIO = p_codfuncionario
            OR v_todos = 1 AND UPPER(TRIM(COALESCE(f.ativo, 'S'))) NOT IN ('N', 'NAO', 'NÃO', '0'))
       AND NOT EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxcriterios` x
                        WHERE x.codfuncionario = f.CODFUNCIONARIO AND x.codtipofuncionario = tc.codtipofuncionario AND x.codcriteriofuncionario = tc.codcriteriofuncionario);
    SELECT COUNT(*) INTO v_trei
      FROM `fo_funcionarios` f
      JOIN `fo_tipofuncionariosxtipotreinamentos` tt ON tt.codtipofuncionario = f.CODTIPOFUNCIONARIO
      JOIN `fo_tipotreinamentos` ct ON ct.codtipotreinamento = tt.codtipotreinamento
     WHERE (v_todos = 0 AND f.CODFUNCIONARIO = p_codfuncionario
            OR v_todos = 1 AND UPPER(TRIM(COALESCE(f.ativo, 'S'))) NOT IN ('N', 'NAO', 'NÃO', '0'))
       AND NOT EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxtreinamentos` x
                        WHERE x.codfuncionario = f.CODFUNCIONARIO AND x.codtipofuncionario = tt.codtipofuncionario AND x.codtipotreinamento = tt.codtipotreinamento);

    IF COALESCE(p_aplicar, 0) = 1 AND (v_crit > 0 OR v_trei > 0) THEN
        START TRANSACTION;
        INSERT INTO `fo_funcionarioxtiposxcriterios` (codfuncionario, codtipofuncionario, codcriteriofuncionario)
        SELECT DISTINCT f.CODFUNCIONARIO, tc.codtipofuncionario, tc.codcriteriofuncionario
          FROM `fo_funcionarios` f
          JOIN `fo_tipofuncionariosxcriteriofuncionarios` tc ON tc.codtipofuncionario = f.CODTIPOFUNCIONARIO
          JOIN `fo_criteriofuncionarios` cc ON cc.codcriteriofuncionario = tc.codcriteriofuncionario
         WHERE (v_todos = 0 AND f.CODFUNCIONARIO = p_codfuncionario
                OR v_todos = 1 AND UPPER(TRIM(COALESCE(f.ativo, 'S'))) NOT IN ('N', 'NAO', 'NÃO', '0'))
           AND NOT EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxcriterios` x
                            WHERE x.codfuncionario = f.CODFUNCIONARIO AND x.codtipofuncionario = tc.codtipofuncionario AND x.codcriteriofuncionario = tc.codcriteriofuncionario);
        INSERT INTO `fo_funcionarioxtiposxtreinamentos` (codfuncionario, codtipofuncionario, codtipotreinamento)
        SELECT DISTINCT f.CODFUNCIONARIO, tt.codtipofuncionario, tt.codtipotreinamento
          FROM `fo_funcionarios` f
          JOIN `fo_tipofuncionariosxtipotreinamentos` tt ON tt.codtipofuncionario = f.CODTIPOFUNCIONARIO
          JOIN `fo_tipotreinamentos` ct ON ct.codtipotreinamento = tt.codtipotreinamento
         WHERE (v_todos = 0 AND f.CODFUNCIONARIO = p_codfuncionario
                OR v_todos = 1 AND UPPER(TRIM(COALESCE(f.ativo, 'S'))) NOT IN ('N', 'NAO', 'NÃO', '0'))
           AND NOT EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxtreinamentos` x
                            WHERE x.codfuncionario = f.CODFUNCIONARIO AND x.codtipofuncionario = tt.codtipofuncionario AND x.codtipotreinamento = tt.codtipotreinamento);
        COMMIT;
    END IF;
    SELECT v_crit AS CRITERIOS, v_trei AS TREINAMENTOS;
END

-- @@
DROP TRIGGER IF EXISTS `trg_fo_funcionarios_itens_ai`

-- @@
-- Funcionário novo: critérios e treinamentos do tipo dele.
CREATE TRIGGER `trg_fo_funcionarios_itens_ai` AFTER INSERT ON `fo_funcionarios`
FOR EACH ROW
BEGIN
    IF COALESCE(NEW.CODTIPOFUNCIONARIO, 0) > 0 AND @gp_sinc_legado IS NULL THEN  -- sincronismo com o legado: itens vêm prontos
        INSERT INTO `fo_funcionarioxtiposxcriterios` (codfuncionario, codtipofuncionario, codcriteriofuncionario)
        SELECT DISTINCT NEW.CODFUNCIONARIO, tc.codtipofuncionario, tc.codcriteriofuncionario
          FROM `fo_tipofuncionariosxcriteriofuncionarios` tc
          JOIN `fo_criteriofuncionarios` cc ON cc.codcriteriofuncionario = tc.codcriteriofuncionario
         WHERE tc.codtipofuncionario = NEW.CODTIPOFUNCIONARIO
           AND NOT EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxcriterios` x WHERE x.codfuncionario = NEW.CODFUNCIONARIO
                            AND x.codtipofuncionario = tc.codtipofuncionario AND x.codcriteriofuncionario = tc.codcriteriofuncionario);
        INSERT INTO `fo_funcionarioxtiposxtreinamentos` (codfuncionario, codtipofuncionario, codtipotreinamento)
        SELECT DISTINCT NEW.CODFUNCIONARIO, tt.codtipofuncionario, tt.codtipotreinamento
          FROM `fo_tipofuncionariosxtipotreinamentos` tt
          JOIN `fo_tipotreinamentos` ct ON ct.codtipotreinamento = tt.codtipotreinamento
         WHERE tt.codtipofuncionario = NEW.CODTIPOFUNCIONARIO
           AND NOT EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxtreinamentos` x WHERE x.codfuncionario = NEW.CODFUNCIONARIO
                            AND x.codtipofuncionario = tt.codtipofuncionario AND x.codtipotreinamento = tt.codtipotreinamento);
    END IF;
END

-- @@
DROP TRIGGER IF EXISTS `trg_fo_funcionarios_itens_au`

-- @@
-- Troca de tipo (função): acrescenta os itens do tipo novo (os do tipo antigo ficam guardados).
CREATE TRIGGER `trg_fo_funcionarios_itens_au` AFTER UPDATE ON `fo_funcionarios`
FOR EACH ROW
BEGIN
    IF COALESCE(NEW.CODTIPOFUNCIONARIO, 0) > 0 AND NOT (NEW.CODTIPOFUNCIONARIO <=> OLD.CODTIPOFUNCIONARIO) AND @gp_sinc_legado IS NULL THEN  -- sincronismo com o legado: itens vêm prontos
        INSERT INTO `fo_funcionarioxtiposxcriterios` (codfuncionario, codtipofuncionario, codcriteriofuncionario)
        SELECT DISTINCT NEW.CODFUNCIONARIO, tc.codtipofuncionario, tc.codcriteriofuncionario
          FROM `fo_tipofuncionariosxcriteriofuncionarios` tc
          JOIN `fo_criteriofuncionarios` cc ON cc.codcriteriofuncionario = tc.codcriteriofuncionario
         WHERE tc.codtipofuncionario = NEW.CODTIPOFUNCIONARIO
           AND NOT EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxcriterios` x WHERE x.codfuncionario = NEW.CODFUNCIONARIO
                            AND x.codtipofuncionario = tc.codtipofuncionario AND x.codcriteriofuncionario = tc.codcriteriofuncionario);
        INSERT INTO `fo_funcionarioxtiposxtreinamentos` (codfuncionario, codtipofuncionario, codtipotreinamento)
        SELECT DISTINCT NEW.CODFUNCIONARIO, tt.codtipofuncionario, tt.codtipotreinamento
          FROM `fo_tipofuncionariosxtipotreinamentos` tt
          JOIN `fo_tipotreinamentos` ct ON ct.codtipotreinamento = tt.codtipotreinamento
         WHERE tt.codtipofuncionario = NEW.CODTIPOFUNCIONARIO
           AND NOT EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxtreinamentos` x WHERE x.codfuncionario = NEW.CODFUNCIONARIO
                            AND x.codtipofuncionario = tt.codtipofuncionario AND x.codtipotreinamento = tt.codtipotreinamento);
    END IF;
END

-- @@
DROP TRIGGER IF EXISTS `trg_fo_tipofuncxcriterio_itens_ai`

-- @@
-- Critério novo no tipo: cria o item nos funcionários ativos desse tipo.
CREATE TRIGGER `trg_fo_tipofuncxcriterio_itens_ai` AFTER INSERT ON `fo_tipofuncionariosxcriteriofuncionarios`
FOR EACH ROW
INSERT INTO `fo_funcionarioxtiposxcriterios` (codfuncionario, codtipofuncionario, codcriteriofuncionario)
SELECT DISTINCT f.CODFUNCIONARIO, NEW.codtipofuncionario, NEW.codcriteriofuncionario
  FROM `fo_funcionarios` f
 WHERE f.CODTIPOFUNCIONARIO = NEW.codtipofuncionario
   AND UPPER(TRIM(COALESCE(f.ativo, 'S'))) NOT IN ('N', 'NAO', 'NÃO', '0')
   AND EXISTS (SELECT 1 FROM `fo_criteriofuncionarios` cc WHERE cc.codcriteriofuncionario = NEW.codcriteriofuncionario)
   AND NOT EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxcriterios` x WHERE x.codfuncionario = f.CODFUNCIONARIO
                    AND x.codtipofuncionario = NEW.codtipofuncionario AND x.codcriteriofuncionario = NEW.codcriteriofuncionario)
   AND @gp_sinc_legado IS NULL  -- sincronismo com o legado: itens vêm prontos

-- @@
DROP TRIGGER IF EXISTS `trg_fo_tipofuncxtreinamento_itens_ai`

-- @@
-- Treinamento novo no tipo: cria o item nos funcionários ativos desse tipo.
CREATE TRIGGER `trg_fo_tipofuncxtreinamento_itens_ai` AFTER INSERT ON `fo_tipofuncionariosxtipotreinamentos`
FOR EACH ROW
INSERT INTO `fo_funcionarioxtiposxtreinamentos` (codfuncionario, codtipofuncionario, codtipotreinamento)
SELECT DISTINCT f.CODFUNCIONARIO, NEW.codtipofuncionario, NEW.codtipotreinamento
  FROM `fo_funcionarios` f
 WHERE f.CODTIPOFUNCIONARIO = NEW.codtipofuncionario
   AND UPPER(TRIM(COALESCE(f.ativo, 'S'))) NOT IN ('N', 'NAO', 'NÃO', '0')
   AND EXISTS (SELECT 1 FROM `fo_tipotreinamentos` ct WHERE ct.codtipotreinamento = NEW.codtipotreinamento)
   AND NOT EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxtreinamentos` x WHERE x.codfuncionario = f.CODFUNCIONARIO
                    AND x.codtipofuncionario = NEW.codtipofuncionario AND x.codtipotreinamento = NEW.codtipotreinamento)
   AND @gp_sinc_legado IS NULL  -- sincronismo com o legado: itens vêm prontos

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_funcionario_itens_limpar`

-- @@
-- Remove itens gerados e nunca preenchidos (sem atende/realizado, validade, responsáveis e observação) que não servem:
-- critério/treinamento inexistente no cadastro ou funcionário excluído. Itens com qualquer dado são mantidos.
-- p_aplicar = 0 só conta. Retorna CRITERIOS e TREINAMENTOS removidos (ou que seriam).
CREATE PROCEDURE `sp_fo_funcionario_itens_limpar`(IN p_aplicar TINYINT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_crit INT DEFAULT 0;
    DECLARE v_trei INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    DROP TEMPORARY TABLE IF EXISTS tmp_fo_fil_c;
    DROP TEMPORARY TABLE IF EXISTS tmp_fo_fil_t;
    CREATE TEMPORARY TABLE tmp_fo_fil_c (codigo BIGINT PRIMARY KEY);
    CREATE TEMPORARY TABLE tmp_fo_fil_t (codigo BIGINT PRIMARY KEY);
    INSERT INTO tmp_fo_fil_c (codigo)
    SELECT x.codigo FROM `fo_funcionarioxtiposxcriterios` x
     WHERE (NOT EXISTS (SELECT 1 FROM `fo_criteriofuncionarios` c WHERE c.codcriteriofuncionario = x.codcriteriofuncionario)
            OR NOT EXISTS (SELECT 1 FROM `fo_funcionarios` f WHERE f.CODFUNCIONARIO = x.codfuncionario))
       AND TRIM(COALESCE(x.atendeaocriterio, '')) = '' AND (x.datadevalidade IS NULL OR x.datadevalidade < '1900-01-01')
       AND COALESCE(x.codusuario, 0) = 0 AND COALESCE(x.codusuario_responsavel_fornecedor, 0) = 0 AND TRIM(COALESCE(x.observacao, '')) = '';
    INSERT INTO tmp_fo_fil_t (codigo)
    SELECT x.codigo FROM `fo_funcionarioxtiposxtreinamentos` x
     WHERE (NOT EXISTS (SELECT 1 FROM `fo_tipotreinamentos` t WHERE t.codtipotreinamento = x.codtipotreinamento)
            OR NOT EXISTS (SELECT 1 FROM `fo_funcionarios` f WHERE f.CODFUNCIONARIO = x.codfuncionario))
       AND TRIM(COALESCE(x.treinamentorealizado, '')) = '' AND (x.datadevalidadetreinamento IS NULL OR x.datadevalidadetreinamento < '1900-01-01')
       AND COALESCE(x.codusuario, 0) = 0;
    SELECT COUNT(*) INTO v_crit FROM tmp_fo_fil_c;
    SELECT COUNT(*) INTO v_trei FROM tmp_fo_fil_t;
    IF COALESCE(p_aplicar, 0) = 1 THEN
        START TRANSACTION;
        DELETE x FROM `fo_funcionarioxtiposxcriterios` x JOIN tmp_fo_fil_c t ON t.codigo = x.codigo;
        DELETE x FROM `fo_funcionarioxtiposxtreinamentos` x JOIN tmp_fo_fil_t t ON t.codigo = x.codigo;
        COMMIT;
    END IF;
    DROP TEMPORARY TABLE IF EXISTS tmp_fo_fil_c;
    DROP TEMPORARY TABLE IF EXISTS tmp_fo_fil_t;
    SELECT v_crit AS CRITERIOS, v_trei AS TREINAMENTOS;
END
