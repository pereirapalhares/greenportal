-- Migração 391 — Legislação › Palavras-chave: lista "Sugeridas" (17/11/2026). Banco: glegislacao_dbp.
-- Teste nos vínculos existentes (scripts/teste-sugestao-catalogo-391.php, "deixa um de fora", 14.291 vínculos):
-- palavras de leis do MESMO TIPO E ÂMBITO acham 79%, do MESMO ASSUNTO 41%; combinadas, 73% das palavras certas na 1ª página
-- (25) contra 47% da lista "mais usadas no geral". Família (pai/filhas) quase não tem palavras, mas entra (barata).
-- Devolve, por palavra, quantas leis de cada critério a usam; a tela soma os pontos, tira as já vinculadas e busca o texto
-- no catálogo (guniversal_dbp.palavras_chaves). Blocos separados por "-- @@".
-- @@
DROP PROCEDURE IF EXISTS `sp_legislacao_sugestao_palavras`
-- @@
CREATE PROCEDURE `sp_legislacao_sugestao_palavras`(IN p_lei INT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_tipo INT DEFAULT NULL;
    DECLARE v_amb INT DEFAULT NULL;
    SELECT l.`CODTIPO`, l.`CODAMBITO` INTO v_tipo, v_amb FROM `legislacao` l WHERE l.`CODLEGISLACAO` = p_lei LIMIT 1;
    SELECT x.`codpalavrachave` AS `CODPALAVRACHAVE`, SUM(x.`fam`) AS `FAMILIA`, SUM(x.`ass`) AS `ASSUNTO`, SUM(x.`grp`) AS `TIPO_AMBITO`
      FROM (
            SELECT lp.`codpalavrachave`, 1 AS `fam`, 0 AS `ass`, 0 AS `grp`
              FROM `legislacaopaixlegislacaofilha` r
              JOIN `legislacaoxpalavraschave` lp ON lp.`codlegislacao` = IF(r.`CODLEGISLACAO_PAI` = p_lei, r.`CODLEGISLACAO_FILHA`, r.`CODLEGISLACAO_PAI`)
             WHERE (r.`CODLEGISLACAO_PAI` = p_lei OR r.`CODLEGISLACAO_FILHA` = p_lei) AND r.`CODLEGISLACAO_PAI` <> r.`CODLEGISLACAO_FILHA`
            UNION ALL
            SELECT lp.`codpalavrachave`, 0, 1, 0
              FROM `legislacaoxassunto` a1
              JOIN `legislacaoxassunto` a2 ON a2.`CODASSUNTO` = a1.`CODASSUNTO` AND a2.`CODLEGISLACAO` <> p_lei
              JOIN `legislacaoxpalavraschave` lp ON lp.`codlegislacao` = a2.`CODLEGISLACAO`
             WHERE a1.`CODLEGISLACAO` = p_lei
            UNION ALL
            SELECT lp.`codpalavrachave`, 0, 0, 1
              FROM `legislacao` l
              JOIN `legislacaoxpalavraschave` lp ON lp.`codlegislacao` = l.`CODLEGISLACAO`
             WHERE l.`CODAMBITO` <=> v_amb AND l.`CODTIPO` <=> v_tipo AND l.`CODLEGISLACAO` <> p_lei
           ) x
     GROUP BY x.`codpalavrachave`;
END
