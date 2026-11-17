USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

DROP PROCEDURE IF EXISTS `sp_crud_context_select`;
DROP PROCEDURE IF EXISTS `sp_crud_context_count`;

DELIMITER $$
CREATE PROCEDURE `sp_crud_context_select`(IN p_tabela VARCHAR(128), IN p_filtros JSON, IN p_contexto JSON, IN p_busca VARCHAR(255), IN p_limite INT, IN p_offset INT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_where LONGTEXT DEFAULT '1 = 1';
    DECLARE v_part LONGTEXT;
    DECLARE v_sql LONGTEXT;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_tabela AND TABLE_TYPE IN ('BASE TABLE','VIEW')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tabela ou view nao autorizada ou inexistente'; END IF;
    SELECT GROUP_CONCAT(CASE
        WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))='OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.operator'))) IN ('lt','lte','gt','gte') THEN CONCAT('`', k.campo, '` ', CASE JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.operator'))) WHEN 'lt' THEN '<' WHEN 'lte' THEN '<=' WHEN 'gt' THEN '>' ELSE '>=' END, ' ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.value')))))
        WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))='OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.operator')))='not' THEN CONCAT('`', k.campo, '` <> ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.value')))))
        WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))='OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.exact')))='true' THEN CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.value')))))
        WHEN c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext') THEN CONCAT('`', k.campo, '` LIKE ', QUOTE(CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo)))), '%')))
        ELSE CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))))
    END ORDER BY k.campo SEPARATOR ' AND ') INTO v_part
    FROM JSON_TABLE(JSON_KEYS(p_filtros), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) k
    INNER JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.COLUMN_NAME=k.campo
    WHERE NOT EXISTS (SELECT 1 FROM JSON_TABLE(p_contexto, '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) c0 WHERE c0.campo=k.campo)
      AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.COLUMN_NAME=k.campo);
    IF v_part IS NOT NULL THEN SET v_where=CONCAT(v_where,' AND ',v_part); END IF;
    SELECT GROUP_CONCAT(CASE
        WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))='OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.operator'))) IN ('lt','lte','gt','gte') THEN CONCAT('`', k.campo, '` ', CASE JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.operator'))) WHEN 'lt' THEN '<' WHEN 'lte' THEN '<=' WHEN 'gt' THEN '>' ELSE '>=' END, ' ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.value')))))
        WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))='OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.operator')))='not' THEN CONCAT('`', k.campo, '` <> ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.value')))))
        WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))='OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.exact')))='true' THEN CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.value')))))
        WHEN c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext') THEN CONCAT('`', k.campo, '` LIKE ', QUOTE(CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo)))), '%')))
        ELSE CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))))
    END ORDER BY k.campo SEPARATOR ' OR ') INTO v_part
    FROM JSON_TABLE(JSON_KEYS(p_filtros), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) k
    INNER JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.COLUMN_NAME=k.campo
    WHERE EXISTS (SELECT 1 FROM JSON_TABLE(p_contexto, '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) c0 WHERE c0.campo=k.campo)
      AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.COLUMN_NAME=k.campo);
    IF v_part IS NOT NULL THEN SET v_where=CONCAT(v_where,' AND (',v_part,')'); END IF;
    IF COALESCE(p_busca,'')<>'' THEN SELECT GROUP_CONCAT(CONCAT('`',REPLACE(c.COLUMN_NAME,'`','``'),'` LIKE ',QUOTE(CONCAT('%',p_busca,'%'))) SEPARATOR ' OR ') INTO v_part FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext'); IF v_part IS NOT NULL THEN SET v_where=CONCAT(v_where,' AND (',v_part,')'); END IF; END IF;
    SET v_sql=CONCAT('SELECT * FROM `',DATABASE(),'`.`',REPLACE(p_tabela,'`','``'),'` WHERE ',v_where,' LIMIT ',LEAST(GREATEST(COALESCE(p_limite,25),1),1000),' OFFSET ',GREATEST(COALESCE(p_offset,0),0)); SET @green_context_select=v_sql; PREPARE s FROM @green_context_select; EXECUTE s; DEALLOCATE PREPARE s;
END$$

CREATE PROCEDURE `sp_crud_context_count`(IN p_tabela VARCHAR(128), IN p_filtros JSON, IN p_contexto JSON, IN p_busca VARCHAR(255))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_where LONGTEXT DEFAULT '1 = 1';
    DECLARE v_part LONGTEXT;
    DECLARE v_sql LONGTEXT;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_tabela AND TABLE_TYPE IN ('BASE TABLE','VIEW')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tabela ou view nao autorizada ou inexistente'; END IF;
    SELECT GROUP_CONCAT(CASE
        WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))='OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.operator'))) IN ('lt','lte','gt','gte') THEN CONCAT('`', k.campo, '` ', CASE JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.operator'))) WHEN 'lt' THEN '<' WHEN 'lte' THEN '<=' WHEN 'gt' THEN '>' ELSE '>=' END, ' ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.value')))))
        WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))='OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.operator')))='not' THEN CONCAT('`', k.campo, '` <> ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.value')))))
        WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))='OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.exact')))='true' THEN CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.value')))))
        WHEN c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext') THEN CONCAT('`', k.campo, '` LIKE ', QUOTE(CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo)))), '%')))
        ELSE CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))))
    END ORDER BY k.campo SEPARATOR ' AND ') INTO v_part
    FROM JSON_TABLE(JSON_KEYS(p_filtros), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) k
    INNER JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.COLUMN_NAME=k.campo
    WHERE NOT EXISTS (SELECT 1 FROM JSON_TABLE(p_contexto, '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) c0 WHERE c0.campo=k.campo)
      AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.COLUMN_NAME=k.campo);
    IF v_part IS NOT NULL THEN SET v_where=CONCAT(v_where,' AND ',v_part); END IF;
    SELECT GROUP_CONCAT(CASE
        WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))='OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.operator'))) IN ('lt','lte','gt','gte') THEN CONCAT('`', k.campo, '` ', CASE JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.operator'))) WHEN 'lt' THEN '<' WHEN 'lte' THEN '<=' WHEN 'gt' THEN '>' ELSE '>=' END, ' ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.value')))))
        WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))='OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.operator')))='not' THEN CONCAT('`', k.campo, '` <> ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.value')))))
        WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))='OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.exact')))='true' THEN CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo), '.value')))))
        WHEN c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext') THEN CONCAT('`', k.campo, '` LIKE ', QUOTE(CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo)))), '%')))
        ELSE CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo))))))
    END ORDER BY k.campo SEPARATOR ' OR ') INTO v_part
    FROM JSON_TABLE(JSON_KEYS(p_filtros), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) k
    INNER JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.COLUMN_NAME=k.campo
    WHERE EXISTS (SELECT 1 FROM JSON_TABLE(p_contexto, '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) c0 WHERE c0.campo=k.campo)
      AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.COLUMN_NAME=k.campo);
    IF v_part IS NOT NULL THEN SET v_where=CONCAT(v_where,' AND (',v_part,')'); END IF;
    IF COALESCE(p_busca,'')<>'' THEN SELECT GROUP_CONCAT(CONCAT('`',REPLACE(c.COLUMN_NAME,'`','``'),'` LIKE ',QUOTE(CONCAT('%',p_busca,'%'))) SEPARATOR ' OR ') INTO v_part FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext'); IF v_part IS NOT NULL THEN SET v_where=CONCAT(v_where,' AND (',v_part,')'); END IF; END IF;
    SET v_sql=CONCAT('SELECT COUNT(*) AS total_registros FROM `',DATABASE(),'`.`',REPLACE(p_tabela,'`','``'),'` WHERE ',v_where); SET @green_context_count=v_sql; PREPARE s FROM @green_context_count; EXECUTE s; DEALLOCATE PREPARE s;
END$$
DELIMITER ;
