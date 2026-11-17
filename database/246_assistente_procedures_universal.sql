-- Migração 246 — leituras do guniversal_dbp usadas pelo assistente de IA e pelas solicitações de melhoria,
-- somente por procedures (antes: SELECT escrito em AssistenteAplicacaoService e SolicitacaoMelhoriaService).
-- Executado por scripts/aplicar-migracao-246-assistente-procedures.php (comandos separados por "-- @@").

-- @@
DROP PROCEDURE IF EXISTS `sp_assistente_ajuda_transacoes`

-- @@
-- Ajuda das telas (a mesma do botão "?") no idioma pedido, com o português como reserva.
CREATE PROCEDURE `sp_assistente_ajuda_transacoes`(IN p_idioma CHAR(2))
SQL SECURITY INVOKER
BEGIN
    SELECT transacao, titulo_pt, texto_pt,
           CASE p_idioma WHEN 'en' THEN titulo_en WHEN 'es' THEN titulo_es ELSE titulo_pt END AS titulo,
           CASE p_idioma WHEN 'en' THEN texto_en WHEN 'es' THEN texto_es ELSE texto_pt END AS texto
      FROM ajuda_transacoes
     WHERE ativo = 1;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_assistente_menus_urls`

-- @@
-- Endereços das telas que são menu (para separar ajuda de telas de menu e de telas internas).
CREATE PROCEDURE `sp_assistente_menus_urls`()
SQL SECURITY INVOKER
BEGIN
    SELECT url FROM menus WHERE url IS NOT NULL AND url <> '';
END

-- @@
DROP PROCEDURE IF EXISTS `sp_guia_usuario_publicado_obter`

-- @@
CREATE PROCEDURE `sp_guia_usuario_publicado_obter`()
SQL SECURITY INVOKER
BEGIN
    SELECT conteudo FROM vw_guia_usuario_publicado LIMIT 1;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_menus_nomes_telas`

-- @@
-- Nome de cada tela com o menu pai ("Pai › Tela"), no idioma pedido.
CREATE PROCEDURE `sp_menus_nomes_telas`(IN p_idioma CHAR(2))
SQL SECURITY INVOKER
BEGIN
    SELECT m.codigo,
           COALESCE(NULLIF(CASE p_idioma WHEN 'en' THEN m.label_en WHEN 'es' THEN m.label_es ELSE m.label_pt END, ''), m.label_pt) AS nome,
           COALESCE(NULLIF(CASE p_idioma WHEN 'en' THEN p.label_en WHEN 'es' THEN p.label_es ELSE p.label_pt END, ''), p.label_pt) AS pai
      FROM menus m
      LEFT JOIN menus p ON p.codmenu = m.codmenu_pai;
END
