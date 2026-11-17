-- Green Portal - leitura generica do cadastro central de empresas.
USE `guniversal_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
DELIMITER $$
DROP PROCEDURE IF EXISTS `sp_crud_select`$$
CREATE PROCEDURE `sp_crud_select`(
    IN p_tabela VARCHAR(128), IN p_filtros JSON, IN p_busca VARCHAR(255),
    IN p_limite INT, IN p_offset INT
)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_where LONGTEXT DEFAULT '1 = 1';
    DECLARE v_conditions LONGTEXT;
    DECLARE v_sql LONGTEXT;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela AND TABLE_TYPE IN ('BASE TABLE','VIEW')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tabela nao autorizada ou inexistente';
    END IF;
    IF p_filtros IS NOT NULL AND JSON_LENGTH(p_filtros) > 0 THEN
        SELECT GROUP_CONCAT(CASE WHEN c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext')
            THEN CONCAT('`', REPLACE(j.campo, '`', '``'), '` LIKE ', QUOTE(CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(j.campo)))), '%')))
            ELSE CONCAT('`', REPLACE(j.campo, '`', '``'), '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(j.campo))))) ) END SEPARATOR ' AND ')
          INTO v_conditions
          FROM JSON_TABLE(JSON_KEYS(p_filtros), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) j
          INNER JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.COLUMN_NAME = j.campo;
        IF v_conditions IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND ', v_conditions); END IF;
    END IF;
    IF p_busca IS NOT NULL AND p_busca <> '' THEN
        SELECT GROUP_CONCAT(CONCAT('`', REPLACE(c.COLUMN_NAME, '`', '``'), '` LIKE ', QUOTE(CONCAT('%', p_busca, '%'))) SEPARATOR ' OR ')
          INTO v_conditions FROM information_schema.COLUMNS c
         WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela
           AND c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext');
        IF v_conditions IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND (', v_conditions, ')'); END IF;
    END IF;
    SET v_sql = CONCAT('SELECT * FROM `', DATABASE(), '`.`', REPLACE(p_tabela, '`', '``'), '` WHERE ', v_where,
                       ' LIMIT ', LEAST(GREATEST(COALESCE(p_limite, 25), 1), 1000),
                       ' OFFSET ', GREATEST(COALESCE(p_offset, 0), 0));
    SET @crud_sql = v_sql; PREPARE stmt FROM @crud_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
END$$
DELIMITER ;
