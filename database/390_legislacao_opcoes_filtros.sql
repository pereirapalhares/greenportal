-- Migração 390 — Legislação: filtros das colunas com lista (Âmbito, Tipo, Meio ambiente, Segurança, Qualidade,
-- Responsabilidade, Situação e Devido da lei) mostram só os valores das leis que passam nos OUTROS filtros (17/11/2026).
-- sp_legislacao_opcoes_ids: mesmo WHERE da sp_legislacao_count_ids (migração 383); devolve as combinações distintas dessas
-- 8 colunas. A tela tira de p_filtros as chaves dessas colunas e decide em PHP, para cada coluna, quais valores aparecem
-- considerando os filtros das outras colunas (como o filtro automático do Excel). Banco: glegislacao_dbp.
-- @@
DROP PROCEDURE IF EXISTS `sp_legislacao_opcoes_ids`
-- @@
CREATE PROCEDURE `sp_legislacao_opcoes_ids`(
    IN p_ids JSON,
    IN p_periodo CHAR(7),
    IN p_padrao VARCHAR(255),
    IN p_busca VARCHAR(255),
    IN p_filtros JSON
)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_inicio DATETIME;
    DECLARE v_fim DATETIME;
    SET v_inicio = IF(p_periodo IS NULL OR p_periodo = '', NULL, STR_TO_DATE(CONCAT(p_periodo, '-01'), '%Y-%m-%d'));
    SET v_fim = IF(v_inicio IS NULL, NULL, DATE_ADD(v_inicio, INTERVAL 1 MONTH));
    SELECT l.`CODAMBITO`, l.`CODTIPO`, l.`MEIO_AMBIENTE`, l.`SEGURANCA`, l.`QUALIDADE`, l.`RESPOSABILIDADE`, l.`situacao_da_lei`, l.`devido_da_lei`, COUNT(*) AS `QTD`
      FROM `legislacao` l
     WHERE (p_ids IS NULL OR l.`CODLEGISLACAO` IN (SELECT jt.`id` FROM JSON_TABLE(p_ids, '$[*]' COLUMNS (`id` INT PATH '$')) jt))
       AND (v_inicio IS NULL OR EXISTS (SELECT 1 FROM `legislacaoxalteracao` a WHERE a.`codlegislacao` = l.`CODLEGISLACAO` AND a.`data_da_alteracao` >= v_inicio AND a.`data_da_alteracao` < v_fim))
       AND (p_padrao IS NULL OR p_padrao = '' OR l.`PADRAO` LIKE CONCAT('%', p_padrao, '%'))
       AND (JSON_EXTRACT(p_filtros, '$.CODAMBITO') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.CODAMBITO')) = '' OR l.`CODAMBITO` = CAST(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.CODAMBITO')) AS UNSIGNED))
       AND (JSON_EXTRACT(p_filtros, '$.CODTIPO') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.CODTIPO')) = '' OR l.`CODTIPO` = CAST(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.CODTIPO')) AS UNSIGNED))
       AND (JSON_EXTRACT(p_filtros, '$.MEIO_AMBIENTE') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.MEIO_AMBIENTE')) = '' OR l.`MEIO_AMBIENTE` = JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.MEIO_AMBIENTE')))
       AND (JSON_EXTRACT(p_filtros, '$.SEGURANCA') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.SEGURANCA')) = '' OR l.`SEGURANCA` = JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.SEGURANCA')))
       AND (JSON_EXTRACT(p_filtros, '$.QUALIDADE') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.QUALIDADE')) = '' OR l.`QUALIDADE` = JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.QUALIDADE')))
       AND (JSON_EXTRACT(p_filtros, '$.RESPOSABILIDADE') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.RESPOSABILIDADE')) = '' OR l.`RESPOSABILIDADE` = JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.RESPOSABILIDADE')))
       AND (JSON_EXTRACT(p_filtros, '$.situacao_da_lei') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.situacao_da_lei')) = '' OR l.`situacao_da_lei` = JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.situacao_da_lei')))
       AND (JSON_EXTRACT(p_filtros, '$.devido_da_lei') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.devido_da_lei')) = '' OR l.`devido_da_lei` = JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.devido_da_lei')))
       AND (JSON_EXTRACT(p_filtros, '$.CODIGO') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.CODIGO')) = '' OR l.`CODIGO` LIKE CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.CODIGO')), '%'))
       AND (JSON_EXTRACT(p_filtros, '$.NR') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.NR')) = '' OR l.`NR` LIKE CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.NR')), '%'))
       AND (JSON_EXTRACT(p_filtros, '$.ANO') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.ANO')) = '' OR l.`ANO` LIKE CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.ANO')), '%'))
       AND (JSON_EXTRACT(p_filtros, '$.ARTIGOS') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.ARTIGOS')) = '' OR l.`ARTIGOS` LIKE CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.ARTIGOS')), '%'))
       AND (JSON_EXTRACT(p_filtros, '$.PADRAO') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.PADRAO')) = '' OR l.`PADRAO` LIKE CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.PADRAO')), '%'))
       AND (JSON_EXTRACT(p_filtros, '$.ASSUNTOS') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.ASSUNTOS')) = '' OR EXISTS (SELECT 1 FROM `legislacaoxassunto` la INNER JOIN `assuntos` s ON s.`CODASSUNTO` = la.`CODASSUNTO` WHERE la.`CODLEGISLACAO` = l.`CODLEGISLACAO` AND s.`DSCASSUNTO` LIKE CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, '$.ASSUNTOS')), '%')))
       AND (p_busca IS NULL OR p_busca = '' OR CONCAT_WS(' ', l.`CODIGO`, l.`NR`, l.`ANO`, l.`ARTIGOS`, l.`MEIO_AMBIENTE`, l.`SEGURANCA`, l.`QUALIDADE`, l.`RESPOSABILIDADE`, l.`PADRAO`, l.`situacao_da_lei`, l.`devido_da_lei`) LIKE CONCAT('%', p_busca, '%') OR EXISTS (SELECT 1 FROM `legislacaoxassunto` la INNER JOIN `assuntos` s ON s.`CODASSUNTO` = la.`CODASSUNTO` WHERE la.`CODLEGISLACAO` = l.`CODLEGISLACAO` AND s.`DSCASSUNTO` LIKE CONCAT('%', p_busca, '%')))
     GROUP BY l.`CODAMBITO`, l.`CODTIPO`, l.`MEIO_AMBIENTE`, l.`SEGURANCA`, l.`QUALIDADE`, l.`RESPOSABILIDADE`, l.`situacao_da_lei`, l.`devido_da_lei`;
END
