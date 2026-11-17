-- Migração 281 — Etapa 1 do levantamento de SQL direto: auditoria e alertas por procedure (banco de cada empresa).
-- Aplicada por scripts/aplicar-migracao-281-auditoria-alertas.php. Pode ser executada mais de uma vez.
-- Textos comparados com COLLATE explícito nos dois lados: as tabelas não têm a mesma collation em todos os bancos.
-- @@
DROP PROCEDURE IF EXISTS `sp_auditoria_registrar`
-- @@
CREATE PROCEDURE `sp_auditoria_registrar`(IN p_codusuario BIGINT, IN p_transacao VARCHAR(150), IN p_acao VARCHAR(30), IN p_entidade VARCHAR(100),
    IN p_registro VARCHAR(100), IN p_resultado VARCHAR(20), IN p_detalhes JSON)
SQL SECURITY INVOKER
BEGIN
    -- Trilha de auditoria de todas as telas e serviços (mesma definição da migração 246)
    INSERT INTO logs_auditoria (codusuario, transacao, acao, entidade, registro_id, resultado, detalhes)
    VALUES (NULLIF(p_codusuario, 0), LEFT(p_transacao, 150), LEFT(p_acao, 30), LEFT(p_entidade, 100), LEFT(p_registro, 100), LEFT(p_resultado, 20), p_detalhes);
    SELECT LAST_INSERT_ID() AS codauditoria;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_alerta_criar`
-- @@
CREATE PROCEDURE `sp_alerta_criar`(IN p_codusuario BIGINT, IN p_titulo VARCHAR(180), IN p_mensagem TEXT, IN p_link VARCHAR(500), IN p_unico VARCHAR(20))
BEGIN
    -- Cria um alerta para o usuário. p_unico: NAO = sempre cria; USUARIO_LINK = não repete o mesmo link para o mesmo usuário;
    -- LINK = não repete o link para ninguém (chave de idempotência, ex.: TASK:123). Devolve codalerta (0 = já existia).
    DECLARE v_existe INT DEFAULT 0;
    IF COALESCE(p_unico, 'NAO') = 'USUARIO_LINK' THEN
        SELECT COUNT(*) INTO v_existe FROM alertas
         WHERE codusuario = p_codusuario AND link COLLATE utf8mb4_unicode_ci = p_link COLLATE utf8mb4_unicode_ci;
    ELSEIF p_unico = 'LINK' THEN
        SELECT COUNT(*) INTO v_existe FROM alertas WHERE link COLLATE utf8mb4_unicode_ci = p_link COLLATE utf8mb4_unicode_ci;
    END IF;
    IF v_existe > 0 OR COALESCE(p_codusuario, 0) < 1 THEN
        SELECT 0 AS codalerta;
    ELSE
        INSERT INTO alertas (codusuario, titulo, mensagem, link, lido) VALUES (p_codusuario, LEFT(p_titulo, 180), p_mensagem, LEFT(p_link, 500), 0);
        SELECT LAST_INSERT_ID() AS codalerta;
    END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_alertas_retencao`
-- @@
CREATE PROCEDURE `sp_alertas_retencao`(IN p_corte DATETIME, IN p_executar TINYINT)
BEGIN
    -- Alertas lidos criados antes do corte: conta e, com p_executar = 1, exclui
    DECLARE v_total INT DEFAULT 0;
    SELECT COUNT(*) INTO v_total FROM alertas WHERE lido = 1 AND criado_em < p_corte;
    IF p_executar = 1 THEN DELETE FROM alertas WHERE lido = 1 AND criado_em < p_corte; END IF;
    SELECT v_total AS elegiveis, IF(p_executar = 1, v_total, 0) AS excluidos;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_exportacoes_retencao_listar`
-- @@
CREATE PROCEDURE `sp_exportacoes_retencao_listar`(IN p_corte DATETIME)
BEGIN
    -- Exportações concluídas ou com erro anteriores ao corte (o arquivo é apagado pelo script; o registro por sp_crud_delete)
    SELECT codexportacaogrid, arquivo, status, concluido_em, solicitado_em
      FROM exportacoes_grid
     WHERE status IN ('CONCLUIDA', 'ERRO') AND COALESCE(concluido_em, solicitado_em) < p_corte
     ORDER BY codexportacaogrid;
END
-- @@
CREATE OR REPLACE VIEW `vw_alertas_resumo` AS
SELECT COUNT(*) AS total, COALESCE(SUM(COALESCE(lido, 0) = 0), 0) AS nao_lidos FROM alertas
