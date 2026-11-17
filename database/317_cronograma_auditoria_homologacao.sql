-- Migração 317 — Homologação de Fornecedores: cronograma da auditoria de homologação (20/10/2026).
-- Legado: aba Auditoria (configuracoes/fo_auditoriatipos.php) — link "Cronograma" só nos tipos com Necessita auditoria = Sim —
-- abre cadastros/ev_schedule_c.php?codauditoria=<codfornecedorxtipofornecedores>; os eventos ficam em ev_eventos com
-- codauditoria = fornecedor × tipo, codcategoria 2 (Auditoria) e os campos da auditoria: facilidades necessárias
-- (auditoria_facilidades_necessarias), auditores (codusuarios_auditores, códigos separados por vírgula) e check list
-- (codchecklists → pa_checklist). Unidade do evento = unidade do fornecedor.
-- Greenportal: principal/eventos.php?codauditoria=N (a mesma página de eventos do monitoramento), lendo e gravando só por
-- estes procedimentos. Aplicado por scripts/aplicar-migracao-317-cronograma-auditoria-homologacao.php, que antes cria
-- ev_eventos onde não existe (estrutura do legado) e a view de check lists quando o banco tem pa_checklist.
-- Comandos separados por "-- @@". Pode ser reaplicado.

-- @@
-- Eventos do cronograma de cada auditoria (fornecedor × tipo), como o legado (ev_load.php: where codauditoria = N).
CREATE OR REPLACE VIEW `vw_fo_auditoria_cronograma` AS
SELECT e.codevento AS CODEVENTO, e.codauditoria AS CODAUDITORIA, ft.codfornecedor AS CODFORNECEDOR, ft.codtipofornecedor AS CODTIPOFORNECEDOR,
       e.codcategoria AS CODCATEGORIA, e.codusuario AS CODUSUARIO, e.dscevento AS DSCEVENTO, e.dsclocalisacao AS DSCLOCALISACAO,
       e.lembrar AS LEMBRAR, e.enviaremail AS ENVIAREMAIL, e.datainicial AS DATAINICIAL, e.datafim AS DATAFIM, e.codunidade AS CODUNIDADE,
       e.codusuario_envolvidos AS CODUSUARIO_ENVOLVIDOS, e.auditoria_facilidades_necessarias AS FACILIDADES,
       e.codusuarios_auditores AS AUDITORES, e.codchecklists AS CHECKLISTS,
       (e.datafim >= CURRENT_TIMESTAMP) AS NAO_VENCIDO
  FROM `ev_eventos` e
  JOIN `fo_fornecedoresxtipofornecedores` ft ON ft.codfornecedorxtipofornecedores = e.codauditoria
 WHERE e.codauditoria > 0

-- @@
-- Nº de eventos ainda não vencidos por auditoria (coluna Cronograma da aba Auditoria).
CREATE OR REPLACE VIEW `vw_fo_auditoria_cronograma_contagem` AS
SELECT c.CODAUDITORIA, SUM(c.NAO_VENCIDO) AS PENDENTES, COUNT(*) AS TOTAL
  FROM `vw_fo_auditoria_cronograma` c
 GROUP BY c.CODAUDITORIA

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_cronograma_contexto`

-- @@
-- Dados da auditoria (fornecedor × tipo) para o cabeçalho e a checagem de acesso do cronograma.
CREATE PROCEDURE `sp_fo_cronograma_contexto`(IN p_codauditoria BIGINT)
SQL SECURITY INVOKER
SELECT ft.codfornecedorxtipofornecedores AS CODAUDITORIA, ft.codfornecedor AS CODFORNECEDOR, ft.codtipofornecedor AS CODTIPOFORNECEDOR,
       COALESCE(NULLIF(TRIM(f.razaosocial), ''), CONCAT('Fornecedor ', ft.codfornecedor)) AS RAZAOSOCIAL,
       t.dsctipo AS DSCTIPO, COALESCE(t.necessita_auditoria, '') AS NECESSITA_AUDITORIA, f.codunidade AS CODUNIDADE
  FROM `fo_fornecedoresxtipofornecedores` ft
  JOIN `fo_fornecedores` f ON f.codfornecedor = ft.codfornecedor
  LEFT JOIN `fo_tipofornecedores` t ON t.codtipofornecedor = ft.codtipofornecedor
 WHERE ft.codfornecedorxtipofornecedores = p_codauditoria
 LIMIT 1

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_cronograma_listar`

-- @@
-- Eventos de uma auditoria, com o nome de quem abriu, na ordem do calendário.
CREATE PROCEDURE `sp_fo_cronograma_listar`(IN p_codauditoria BIGINT)
SQL SECURITY INVOKER
SELECT c.CODEVENTO AS codevento, c.CODCATEGORIA AS codcategoria, c.CODUSUARIO AS codusuario, c.DSCEVENTO AS dscevento,
       c.DSCLOCALISACAO AS dsclocalisacao, c.LEMBRAR AS lembrar, c.ENVIAREMAIL AS enviaremail, c.DATAINICIAL AS datainicial,
       c.DATAFIM AS datafim, c.CODUNIDADE AS codunidade, c.CODUSUARIO_ENVOLVIDOS AS codusuario_envolvidos,
       c.FACILIDADES AS auditoria_facilidades_necessarias, c.AUDITORES AS codusuarios_auditores, c.CHECKLISTS AS codchecklists,
       u.nome AS responsavel_nome
  FROM `vw_fo_auditoria_cronograma` c
  LEFT JOIN `usuarios` u ON u.codusuario = c.CODUSUARIO
 WHERE c.CODAUDITORIA = p_codauditoria
 ORDER BY c.DATAINICIAL, c.CODEVENTO

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_cronograma_gravar`

-- @@
-- Inclui (p_codevento = 0) ou altera um evento do cronograma. Categoria 2 (Auditoria) e unidade do fornecedor, como o legado.
-- Inclusão só em tipo com Necessita auditoria = Sim. Devolve CODEVENTO.
CREATE PROCEDURE `sp_fo_cronograma_gravar`(IN p_codevento BIGINT, IN p_codauditoria BIGINT, IN p_usuario BIGINT,
    IN p_descricao VARCHAR(1000), IN p_local VARCHAR(200), IN p_lembrar INT, IN p_email VARCHAR(1), IN p_inicio DATETIME, IN p_fim DATETIME,
    IN p_envolvidos VARCHAR(2000), IN p_facilidades TEXT, IN p_auditores VARCHAR(2000), IN p_checklists VARCHAR(2000))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_unidade BIGINT DEFAULT NULL;
    DECLARE v_exige VARCHAR(20) DEFAULT NULL;
    SELECT f.codunidade, COALESCE(t.necessita_auditoria, '') INTO v_unidade, v_exige
      FROM `fo_fornecedoresxtipofornecedores` ft
      JOIN `fo_fornecedores` f ON f.codfornecedor = ft.codfornecedor
      LEFT JOIN `fo_tipofornecedores` t ON t.codtipofornecedor = ft.codtipofornecedor
     WHERE ft.codfornecedorxtipofornecedores = p_codauditoria LIMIT 1;
    IF v_exige IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auditoria não encontrada (fornecedor × tipo).'; END IF;
    IF TRIM(COALESCE(p_descricao, '')) = '' OR p_inicio IS NULL OR p_fim IS NULL OR p_fim < p_inicio THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe descrição e um período válido.';
    END IF;
    IF COALESCE(p_codevento, 0) > 0 THEN
        IF NOT EXISTS (SELECT 1 FROM `ev_eventos` WHERE codevento = p_codevento AND codauditoria = p_codauditoria) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O evento não pertence a esta auditoria.';
        END IF;
        UPDATE `ev_eventos`
           SET dscevento = TRIM(p_descricao), dsclocalisacao = TRIM(COALESCE(p_local, '')), lembrar = COALESCE(p_lembrar, 15),
               enviaremail = IF(p_email = 'S', 'S', 'N'), datainicial = p_inicio, datafim = p_fim, codunidade = COALESCE(v_unidade, codunidade),
               codusuario_envolvidos = COALESCE(p_envolvidos, ''), auditoria_facilidades_necessarias = COALESCE(p_facilidades, ''),
               codusuarios_auditores = COALESCE(p_auditores, ''), codchecklists = COALESCE(p_checklists, '')
         WHERE codevento = p_codevento AND codauditoria = p_codauditoria;
        SELECT p_codevento AS CODEVENTO;
    ELSE
        IF v_exige <> 'Sim' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este tipo de fornecedor não exige auditoria: não há cronograma.'; END IF;
        INSERT INTO `ev_eventos` (codcategoria, codusuario, dscevento, dsclocalisacao, lembrar, enviaremail, datainicial, datafim, codunidade,
                                  codusuario_envolvidos, codauditoria, codmonitoramento, auditoria_facilidades_necessarias, codchecklists, codusuarios_auditores)
        VALUES (2, p_usuario, TRIM(p_descricao), TRIM(COALESCE(p_local, '')), COALESCE(p_lembrar, 15), IF(p_email = 'S', 'S', 'N'), p_inicio, p_fim,
                COALESCE(v_unidade, 0), COALESCE(p_envolvidos, ''), p_codauditoria, 0, COALESCE(p_facilidades, ''), COALESCE(p_checklists, ''), COALESCE(p_auditores, ''));
        SELECT LAST_INSERT_ID() AS CODEVENTO;
    END IF;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_cronograma_excluir`

-- @@
-- Exclui um evento do cronograma (só se for da auditoria informada). Devolve EXCLUIDOS.
CREATE PROCEDURE `sp_fo_cronograma_excluir`(IN p_codevento BIGINT, IN p_codauditoria BIGINT)
SQL SECURITY INVOKER
BEGIN
    DELETE FROM `ev_eventos` WHERE codevento = p_codevento AND codauditoria = p_codauditoria AND p_codauditoria > 0;
    SELECT ROW_COUNT() AS EXCLUIDOS;
END
