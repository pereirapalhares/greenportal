-- Migração 383 — Legislação: filtros Empresa e Unidade corretos para cada empresa (17/11/2026).
-- As procedures antigas (sp_legislacao_select / sp_legislacao_count) liam o vínculo lei x empresa/unidade fixo do banco
-- gmodelo_dbp e conferiam empresa e unidade separadamente. As novas recebem a lista de leis (JSON [1,2,...]) já filtrada
-- pela tela no banco da empresa logada (legislacaoxempresa, empresa e unidade na MESMA linha); NULL = sem filtro.
-- Blocos separados por "-- @@". Banco: glegislacao_dbp.
-- @@
DROP PROCEDURE IF EXISTS `sp_legislacao_select_ids`
-- @@
CREATE PROCEDURE `sp_legislacao_select_ids`(
    IN p_ids JSON,
    IN p_periodo CHAR(7),
    IN p_padrao VARCHAR(255),
    IN p_busca VARCHAR(255),
    IN p_filtros JSON,
    IN p_limite INT,
    IN p_offset INT
)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_inicio DATETIME;
    DECLARE v_fim DATETIME;
    DECLARE v_limite INT;
    DECLARE v_offset INT;
    SET v_inicio = IF(p_periodo IS NULL OR p_periodo = '', NULL, STR_TO_DATE(CONCAT(p_periodo, '-01'), '%Y-%m-%d'));
    SET v_fim = IF(v_inicio IS NULL, NULL, DATE_ADD(v_inicio, INTERVAL 1 MONTH));
    SET v_limite = LEAST(GREATEST(COALESCE(p_limite, 25), 1), 1000);
    SET v_offset = GREATEST(COALESCE(p_offset, 0), 0);
    SELECT l.`CODLEGISLACAO`, l.`CODIGO`,
           (SELECT CONCAT('|', GROUP_CONCAT(DISTINCT DATE_FORMAT(a.`data_da_alteracao`, '%Y-%m') ORDER BY a.`data_da_alteracao` SEPARATOR '|'), '|') FROM `legislacaoxalteracao` a WHERE a.`codlegislacao` = l.`CODLEGISLACAO` AND a.`data_da_alteracao` IS NOT NULL) AS `MES_ANO_ATUALIZACAO`,
           l.`CODAMBITO`, l.`CODTIPO`, l.`NR`, l.`ANO`, l.`ARTIGOS`, l.`MEIO_AMBIENTE`, l.`SEGURANCA`, l.`QUALIDADE`, l.`RESPOSABILIDADE`, l.`PADRAO`, l.`data_atualizacao`, l.`situacao_da_lei`, l.`devido_da_lei`,
           (SELECT GROUP_CONCAT(s.`DSCASSUNTO` ORDER BY s.`DSCASSUNTO` SEPARATOR '; ') FROM `legislacaoxassunto` la INNER JOIN `assuntos` s ON s.`CODASSUNTO` = la.`CODASSUNTO` WHERE la.`CODLEGISLACAO` = l.`CODLEGISLACAO`) AS `ASSUNTOS`
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
     ORDER BY l.`CODLEGISLACAO`
     LIMIT v_limite OFFSET v_offset;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_legislacao_count_ids`
-- @@
CREATE PROCEDURE `sp_legislacao_count_ids`(
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
    SELECT COUNT(*) AS total_registros
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
       AND (p_busca IS NULL OR p_busca = '' OR CONCAT_WS(' ', l.`CODIGO`, l.`NR`, l.`ANO`, l.`ARTIGOS`, l.`MEIO_AMBIENTE`, l.`SEGURANCA`, l.`QUALIDADE`, l.`RESPOSABILIDADE`, l.`PADRAO`, l.`situacao_da_lei`, l.`devido_da_lei`) LIKE CONCAT('%', p_busca, '%') OR EXISTS (SELECT 1 FROM `legislacaoxassunto` la INNER JOIN `assuntos` s ON s.`CODASSUNTO` = la.`CODASSUNTO` WHERE la.`CODLEGISLACAO` = l.`CODLEGISLACAO` AND s.`DSCASSUNTO` LIKE CONCAT('%', p_busca, '%')));
END
