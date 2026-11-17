-- Green Portal - filtros de coluna da Manutencao Legal / Legislacao.
-- Aplicar no banco glegislacao_dbp.
USE `glegislacao_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

DROP PROCEDURE IF EXISTS `sp_legislacao_select`;
DROP PROCEDURE IF EXISTS `sp_legislacao_count`;

DELIMITER $$

CREATE PROCEDURE `sp_legislacao_select`(
    IN p_empresa BIGINT,
    IN p_unidade BIGINT,
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
           (SELECT CONCAT('|', GROUP_CONCAT(DISTINCT x.`CODEMPRESA` ORDER BY x.`CODEMPRESA` SEPARATOR '|'), '|') FROM `gmodelo_dbp`.`legislacaoxempresa` x WHERE x.`CODLEGISLACAO` = l.`CODLEGISLACAO`) AS `CODEMPRESA`,
           (SELECT CONCAT('|', GROUP_CONCAT(DISTINCT x.`codunidade` ORDER BY x.`codunidade` SEPARATOR '|'), '|') FROM `gmodelo_dbp`.`legislacaoxempresa` x WHERE x.`CODLEGISLACAO` = l.`CODLEGISLACAO`) AS `CODUNIDADE`,
           (SELECT CONCAT('|', GROUP_CONCAT(DISTINCT DATE_FORMAT(a.`data_da_alteracao`, '%Y-%m') ORDER BY a.`data_da_alteracao` SEPARATOR '|'), '|') FROM `legislacaoxalteracao` a WHERE a.`codlegislacao` = l.`CODLEGISLACAO` AND a.`data_da_alteracao` IS NOT NULL) AS `MES_ANO_ATUALIZACAO`,
           l.`CODAMBITO`, l.`CODTIPO`, l.`NR`, l.`ANO`, l.`ARTIGOS`, l.`MEIO_AMBIENTE`, l.`SEGURANCA`, l.`QUALIDADE`, l.`RESPOSABILIDADE`, l.`PADRAO`, l.`data_atualizacao`, l.`situacao_da_lei`, l.`devido_da_lei`,
           (SELECT GROUP_CONCAT(s.`DSCASSUNTO` ORDER BY s.`DSCASSUNTO` SEPARATOR '; ') FROM `legislacaoxassunto` la INNER JOIN `assuntos` s ON s.`CODASSUNTO` = la.`CODASSUNTO` WHERE la.`CODLEGISLACAO` = l.`CODLEGISLACAO`) AS `ASSUNTOS`
      FROM `legislacao` l
     WHERE (p_empresa IS NULL OR EXISTS (SELECT 1 FROM `gmodelo_dbp`.`legislacaoxempresa` x WHERE x.`CODLEGISLACAO` = l.`CODLEGISLACAO` AND x.`CODEMPRESA` = p_empresa))
       AND (p_unidade IS NULL OR EXISTS (SELECT 1 FROM `gmodelo_dbp`.`legislacaoxempresa` x WHERE x.`CODLEGISLACAO` = l.`CODLEGISLACAO` AND x.`codunidade` = p_unidade))
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
END$$

CREATE PROCEDURE `sp_legislacao_count`(
    IN p_empresa BIGINT,
    IN p_unidade BIGINT,
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
     WHERE (p_empresa IS NULL OR EXISTS (SELECT 1 FROM `gmodelo_dbp`.`legislacaoxempresa` x WHERE x.`CODLEGISLACAO` = l.`CODLEGISLACAO` AND x.`CODEMPRESA` = p_empresa))
       AND (p_unidade IS NULL OR EXISTS (SELECT 1 FROM `gmodelo_dbp`.`legislacaoxempresa` x WHERE x.`CODLEGISLACAO` = l.`CODLEGISLACAO` AND x.`codunidade` = p_unidade))
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
END$$

DELIMITER ;
