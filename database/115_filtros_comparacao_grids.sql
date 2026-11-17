USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

DROP PROCEDURE IF EXISTS `sp_crud_select`;
DROP PROCEDURE IF EXISTS `sp_crud_count`;

DELIMITER $$
CREATE PROCEDURE `sp_crud_select`(IN p_tabela VARCHAR(128), IN p_filtros JSON, IN p_busca VARCHAR(255), IN p_limite INT, IN p_offset INT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_where LONGTEXT DEFAULT '1 = 1';
    DECLARE v_condition LONGTEXT;
    DECLARE v_sql LONGTEXT;
    SET SESSION group_concat_max_len = 1048576;
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela AND TABLE_TYPE IN ('BASE TABLE','VIEW')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tabela ou view nao autorizada ou inexistente';
    END IF;
    IF p_filtros IS NOT NULL AND JSON_LENGTH(p_filtros) > 0 THEN
        SELECT GROUP_CONCAT(CASE
            WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))) = 'OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.operator'))) IN ('lt','lte','gt','gte')
                THEN CONCAT('`', k.campo, '` ', CASE JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.operator'))) WHEN 'lt' THEN '<' WHEN 'lte' THEN '<=' WHEN 'gt' THEN '>' ELSE '>=' END, ' ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.value')))))
            WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))) = 'OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.operator'))) = 'not'
                THEN CONCAT('`', k.campo, '` <> ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.value')))))
            WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))) = 'OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.exact'))) = 'true'
                THEN CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.value')))))
            WHEN c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext')
                THEN CONCAT('`', k.campo, '` LIKE ', QUOTE(CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))), '%')))
            ELSE CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo))))))
        END ORDER BY k.campo SEPARATOR ' AND ') INTO v_condition
        FROM JSON_TABLE(JSON_KEYS(p_filtros), '$[*]' COLUMNS (campo VARCHAR(128) PATH '$')) k
        INNER JOIN INFORMATION_SCHEMA.COLUMNS c ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.COLUMN_NAME = k.campo;
        IF v_condition IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND ', v_condition); END IF;
    END IF;
    IF p_busca IS NOT NULL AND p_busca <> '' THEN
        SELECT GROUP_CONCAT(CONCAT('`', c.COLUMN_NAME, '` LIKE ', QUOTE(CONCAT('%', p_busca, '%'))) SEPARATOR ' OR ') INTO v_condition FROM INFORMATION_SCHEMA.COLUMNS c WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext');
        IF v_condition IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND (', v_condition, ')'); END IF;
    END IF;
    SET v_sql = CONCAT('SELECT * FROM `', REPLACE(p_tabela, '`', '``'), '` WHERE ', v_where, ' LIMIT ', LEAST(GREATEST(COALESCE(p_limite,50),1),1000), ' OFFSET ', GREATEST(COALESCE(p_offset,0),0));
    SET @crud_sql = v_sql; PREPARE stmt FROM @crud_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
END$$

CREATE PROCEDURE `sp_crud_count`(IN p_tabela VARCHAR(128), IN p_filtros JSON, IN p_busca VARCHAR(255))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_where LONGTEXT DEFAULT '1 = 1';
    DECLARE v_condition LONGTEXT;
    DECLARE v_sql LONGTEXT;
    SET SESSION group_concat_max_len = 1048576;
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela AND TABLE_TYPE IN ('BASE TABLE','VIEW')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tabela ou view nao autorizada ou inexistente';
    END IF;
    IF p_filtros IS NOT NULL AND JSON_LENGTH(p_filtros) > 0 THEN
        SELECT GROUP_CONCAT(CASE
            WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))) = 'OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.operator'))) IN ('lt','lte','gt','gte')
                THEN CONCAT('`', k.campo, '` ', CASE JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.operator'))) WHEN 'lt' THEN '<' WHEN 'lte' THEN '<=' WHEN 'gt' THEN '>' ELSE '>=' END, ' ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.value')))))
            WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))) = 'OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.operator'))) = 'not'
                THEN CONCAT('`', k.campo, '` <> ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.value')))))
            WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))) = 'OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.exact'))) = 'true'
                THEN CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.value')))))
            WHEN c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext')
                THEN CONCAT('`', k.campo, '` LIKE ', QUOTE(CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))), '%')))
            ELSE CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo))))))
        END ORDER BY k.campo SEPARATOR ' AND ') INTO v_condition
        FROM JSON_TABLE(JSON_KEYS(p_filtros), '$[*]' COLUMNS (campo VARCHAR(128) PATH '$')) k
        INNER JOIN INFORMATION_SCHEMA.COLUMNS c ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.COLUMN_NAME = k.campo;
        IF v_condition IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND ', v_condition); END IF;
    END IF;
    IF p_busca IS NOT NULL AND p_busca <> '' THEN
        SELECT GROUP_CONCAT(CONCAT('`', c.COLUMN_NAME, '` LIKE ', QUOTE(CONCAT('%', p_busca, '%'))) SEPARATOR ' OR ') INTO v_condition FROM INFORMATION_SCHEMA.COLUMNS c WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext');
        IF v_condition IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND (', v_condition, ')'); END IF;
    END IF;
    SET v_sql = CONCAT('SELECT COUNT(*) AS total_registros FROM `', REPLACE(p_tabela, '`', '``'), '` WHERE ', v_where);
    SET @crud_count_sql = v_sql; PREPARE stmt FROM @crud_count_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
END$$
DELIMITER ;
