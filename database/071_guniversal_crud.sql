-- Procedures CRUD universais do banco central guniversal_dbp.
-- Necessarias para que a Ajuda das Transacoes e o login nao executem SQL direto no PHP.
USE `guniversal_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
DELIMITER $$

DROP PROCEDURE IF EXISTS `sp_crud_count`$$
CREATE PROCEDURE `sp_crud_count`(IN p_tabela VARCHAR(128), IN p_filtros JSON, IN p_busca VARCHAR(255))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_where LONGTEXT DEFAULT '1 = 1';
    DECLARE v_part LONGTEXT;
    DECLARE v_sql LONGTEXT;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela AND TABLE_TYPE IN ('BASE TABLE','VIEW')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tabela ou view nao autorizada ou inexistente';
    END IF;
    IF p_filtros IS NOT NULL AND JSON_LENGTH(p_filtros) > 0 THEN
        SELECT GROUP_CONCAT(CONCAT('`', REPLACE(k.campo,'`','``'), '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.', JSON_QUOTE(k.campo)))))) SEPARATOR ' AND ')
          INTO v_part
          FROM JSON_TABLE(JSON_KEYS(p_filtros), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) k
         WHERE EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.COLUMN_NAME=k.campo);
        IF v_part IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND ', v_part); END IF;
    END IF;
    IF COALESCE(p_busca,'') <> '' THEN
        SELECT GROUP_CONCAT(CONCAT('`', REPLACE(c.COLUMN_NAME,'`','``'), '` LIKE ', QUOTE(CONCAT('%',p_busca,'%'))) SEPARATOR ' OR ')
          INTO v_part
          FROM information_schema.COLUMNS c
         WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext');
        IF v_part IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND (', v_part, ')'); END IF;
    END IF;
    SET v_sql = CONCAT('SELECT COUNT(*) AS total_registros FROM `', DATABASE(), '`.`', REPLACE(p_tabela,'`','``'), '` WHERE ', v_where);
    SET @green_count_sql = v_sql; PREPARE s FROM @green_count_sql; EXECUTE s; DEALLOCATE PREPARE s;
END$$

DROP PROCEDURE IF EXISTS `sp_crud_update`$$
CREATE PROCEDURE `sp_crud_update`(IN p_tabela VARCHAR(128), IN p_chave JSON, IN p_dados JSON)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_set LONGTEXT;
    DECLARE v_where LONGTEXT;
    DECLARE v_sql LONGTEXT;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_tabela AND TABLE_TYPE='BASE TABLE') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tabela nao autorizada ou inexistente';
    END IF;
    SELECT GROUP_CONCAT(CONCAT('`', REPLACE(k.campo,'`','``'), '` = ', CASE WHEN JSON_TYPE(JSON_EXTRACT(p_dados, CONCAT('$.',JSON_QUOTE(k.campo))))='NULL' THEN 'NULL' ELSE QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, CONCAT('$.',JSON_QUOTE(k.campo))))) END) ORDER BY k.campo SEPARATOR ', ')
      INTO v_set
      FROM JSON_TABLE(JSON_KEYS(p_dados), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) k
     WHERE NOT EXISTS (SELECT 1 FROM JSON_TABLE(JSON_KEYS(p_chave), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) x WHERE x.campo=k.campo);
    SELECT GROUP_CONCAT(CONCAT('`', REPLACE(k.campo,'`','``'), '` = ', CASE WHEN JSON_TYPE(JSON_EXTRACT(p_chave, CONCAT('$.',JSON_QUOTE(k.campo))))='NULL' THEN 'NULL' ELSE QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_chave, CONCAT('$.',JSON_QUOTE(k.campo))))) END) ORDER BY k.campo SEPARATOR ' AND ')
      INTO v_where
      FROM JSON_TABLE(JSON_KEYS(p_chave), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) k;
    IF v_set IS NULL OR v_where IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dados e chave sao obrigatorios para alteracao'; END IF;
    SET v_sql = CONCAT('UPDATE `', DATABASE(), '`.`', REPLACE(p_tabela,'`','``'), '` SET ', v_set, ' WHERE ', v_where);
    SET @green_update_sql = v_sql; PREPARE s FROM @green_update_sql; EXECUTE s; DEALLOCATE PREPARE s;
    SELECT ROW_COUNT() AS registros_afetados;
END$$

DROP PROCEDURE IF EXISTS `sp_crud_select`$$
CREATE PROCEDURE `sp_crud_select`(IN p_tabela VARCHAR(128), IN p_filtros JSON, IN p_busca VARCHAR(255), IN p_limite INT, IN p_offset INT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_where LONGTEXT DEFAULT '1 = 1';
    DECLARE v_part LONGTEXT;
    DECLARE v_sql LONGTEXT;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_tabela AND TABLE_TYPE IN ('BASE TABLE','VIEW')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tabela ou view nao autorizada ou inexistente';
    END IF;
    IF p_filtros IS NOT NULL AND JSON_LENGTH(p_filtros) > 0 THEN
        SELECT GROUP_CONCAT(CONCAT('`', REPLACE(k.campo,'`','``'), '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.',JSON_QUOTE(k.campo)))))) SEPARATOR ' AND ')
          INTO v_part
          FROM JSON_TABLE(JSON_KEYS(p_filtros), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) k
         WHERE EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.COLUMN_NAME=k.campo);
        IF v_part IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND ', v_part); END IF;
    END IF;
    IF COALESCE(p_busca,'') <> '' THEN
        SELECT GROUP_CONCAT(CONCAT('`', REPLACE(c.COLUMN_NAME,'`','``'), '` LIKE ', QUOTE(CONCAT('%',p_busca,'%'))) SEPARATOR ' OR ')
          INTO v_part
          FROM information_schema.COLUMNS c
         WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=p_tabela AND c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext');
        IF v_part IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND (', v_part, ')'); END IF;
    END IF;
    SET v_sql = CONCAT('SELECT * FROM `', DATABASE(), '`.`', REPLACE(p_tabela,'`','``'), '` WHERE ', v_where, ' LIMIT ', LEAST(GREATEST(COALESCE(p_limite,25),1),1000), ' OFFSET ', GREATEST(COALESCE(p_offset,0),0));
    SET @green_select_sql = v_sql; PREPARE s FROM @green_select_sql; EXECUTE s; DEALLOCATE PREPARE s;
END$$

DELIMITER ;
