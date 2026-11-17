-- Green Portal - procedures universais e view do grid de Legislacao.
-- Executar no banco glegislacao_dbp depois da migracao 028.

USE `glegislacao_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

DROP PROCEDURE IF EXISTS `sp_crud_insert`;
DROP PROCEDURE IF EXISTS `sp_crud_update`;
DROP PROCEDURE IF EXISTS `sp_crud_delete`;
DROP PROCEDURE IF EXISTS `sp_crud_select`;
DROP PROCEDURE IF EXISTS `sp_crud_count`;

DELIMITER $$

CREATE PROCEDURE `sp_crud_insert`(IN p_tabela VARCHAR(128), IN p_dados JSON)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_cols LONGTEXT;
    DECLARE v_vals LONGTEXT;
    DECLARE v_sql LONGTEXT;
    SET SESSION group_concat_max_len = 16777216;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela AND TABLE_TYPE = 'BASE TABLE') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tabela nao autorizada ou inexistente';
    END IF;
    DROP TEMPORARY TABLE IF EXISTS tmp_crud_dados;
    CREATE TEMPORARY TABLE tmp_crud_dados (campo VARCHAR(128) NOT NULL PRIMARY KEY, valor JSON NULL);
    INSERT INTO tmp_crud_dados SELECT j.campo, JSON_EXTRACT(p_dados, CONCAT('$.' , JSON_QUOTE(j.campo))) FROM JSON_TABLE(JSON_KEYS(p_dados), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) j;
    SELECT GROUP_CONCAT(CONCAT('`', REPLACE(d.campo, '`', '``'), '`') ORDER BY d.campo), GROUP_CONCAT(IF(d.valor IS NULL OR JSON_TYPE(d.valor) = 'NULL', 'NULL', QUOTE(JSON_UNQUOTE(d.valor))) ORDER BY d.campo) INTO v_cols, v_vals FROM tmp_crud_dados d WHERE EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.COLUMN_NAME = d.campo);
    IF v_cols IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nenhum campo autorizado para inclusao'; END IF;
    SET v_sql = CONCAT('INSERT INTO `', DATABASE(), '`.`', p_tabela, '` (', v_cols, ') VALUES (', v_vals, ')'); SET @crud_sql = v_sql; PREPARE stmt FROM @crud_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    SELECT ROW_COUNT() AS registros_afetados, LAST_INSERT_ID() AS chave_gerada;
    DROP TEMPORARY TABLE IF EXISTS tmp_crud_dados;
END$$

CREATE PROCEDURE `sp_crud_update`(IN p_tabela VARCHAR(128), IN p_chave JSON, IN p_dados JSON)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_set LONGTEXT;
    DECLARE v_where LONGTEXT;
    DECLARE v_sql LONGTEXT;
    SET SESSION group_concat_max_len = 16777216;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela AND TABLE_TYPE = 'BASE TABLE') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tabela nao autorizada ou inexistente';
    END IF;
    DROP TEMPORARY TABLE IF EXISTS tmp_crud_set;
    DROP TEMPORARY TABLE IF EXISTS tmp_crud_key;
    CREATE TEMPORARY TABLE tmp_crud_set (campo VARCHAR(128) NOT NULL PRIMARY KEY, valor JSON NULL);
    CREATE TEMPORARY TABLE tmp_crud_key (campo VARCHAR(128) NOT NULL PRIMARY KEY, valor JSON NULL);
    INSERT INTO tmp_crud_set SELECT j.campo, JSON_EXTRACT(p_dados, CONCAT('$.' , JSON_QUOTE(j.campo))) FROM JSON_TABLE(JSON_KEYS(p_dados), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) j WHERE EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.COLUMN_NAME = j.campo);
    INSERT INTO tmp_crud_key SELECT j.campo, JSON_EXTRACT(p_chave, CONCAT('$.' , JSON_QUOTE(j.campo))) FROM JSON_TABLE(JSON_KEYS(p_chave), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) j WHERE EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.COLUMN_NAME = j.campo);
    SELECT GROUP_CONCAT(CONCAT('`', REPLACE(campo, '`', '``'), '` = ', IF(valor IS NULL OR JSON_TYPE(valor) = 'NULL', 'NULL', QUOTE(JSON_UNQUOTE(valor)))) ORDER BY campo SEPARATOR ', ') INTO v_set FROM tmp_crud_set;
    SELECT GROUP_CONCAT(CONCAT('`', REPLACE(campo, '`', '``'), '` = ', IF(valor IS NULL OR JSON_TYPE(valor) = 'NULL', 'NULL', QUOTE(JSON_UNQUOTE(valor)))) ORDER BY campo SEPARATOR ' AND ') INTO v_where FROM tmp_crud_key;
    IF v_set IS NULL OR v_where IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dados insuficientes para alteracao'; END IF;
    SET v_sql = CONCAT('UPDATE `', DATABASE(), '`.`', p_tabela, '` SET ', v_set, ' WHERE ', v_where); SET @crud_sql = v_sql; PREPARE stmt FROM @crud_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    SELECT ROW_COUNT() AS registros_afetados;
    DROP TEMPORARY TABLE IF EXISTS tmp_crud_set;
    DROP TEMPORARY TABLE IF EXISTS tmp_crud_key;
END$$

CREATE PROCEDURE `sp_crud_delete`(IN p_tabela VARCHAR(128), IN p_chave JSON)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_where LONGTEXT;
    DECLARE v_sql LONGTEXT;
    SET SESSION group_concat_max_len = 16777216;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela AND TABLE_TYPE = 'BASE TABLE') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tabela nao autorizada ou inexistente'; END IF;
    SELECT GROUP_CONCAT(CONCAT('`', REPLACE(j.campo, '`', '``'), '` = ', IF(JSON_TYPE(JSON_EXTRACT(p_chave, CONCAT('$.' , JSON_QUOTE(j.campo)))) = 'NULL', 'NULL', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_chave, CONCAT('$.' , JSON_QUOTE(j.campo)))))) ) ORDER BY j.campo SEPARATOR ' AND ') INTO v_where FROM JSON_TABLE(JSON_KEYS(p_chave), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) j WHERE EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.COLUMN_NAME = j.campo);
    IF v_where IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Chave insuficiente para exclusao'; END IF;
    SET v_sql = CONCAT('DELETE FROM `', DATABASE(), '`.`', p_tabela, '` WHERE ', v_where); SET @crud_sql = v_sql; PREPARE stmt FROM @crud_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    SELECT ROW_COUNT() AS registros_afetados;
END$$

CREATE PROCEDURE `sp_crud_select`(IN p_tabela VARCHAR(128), IN p_filtros JSON, IN p_busca VARCHAR(255), IN p_limite INT, IN p_offset INT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_where LONGTEXT DEFAULT '1 = 1';
    DECLARE v_conditions LONGTEXT;
    DECLARE v_sql LONGTEXT;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela AND TABLE_TYPE IN ('BASE TABLE','VIEW')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tabela ou view nao autorizada ou inexistente'; END IF;
    IF p_filtros IS NOT NULL AND JSON_LENGTH(p_filtros) > 0 THEN
        SELECT GROUP_CONCAT(CASE WHEN c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext') THEN CONCAT('`', REPLACE(j.campo, '`', '``'), '` LIKE ', QUOTE(CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(j.campo)))), '%'))) ELSE CONCAT('`', REPLACE(j.campo, '`', '``'), '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(j.campo)))))) END SEPARATOR ' AND ') INTO v_conditions FROM JSON_TABLE(JSON_KEYS(p_filtros), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) j INNER JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.COLUMN_NAME = j.campo;
        IF v_conditions IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND ', v_conditions); END IF;
    END IF;
    IF p_busca IS NOT NULL AND p_busca <> '' THEN
        SELECT GROUP_CONCAT(CONCAT('`', REPLACE(c.COLUMN_NAME, '`', '``'), '` LIKE ', QUOTE(CONCAT('%', p_busca, '%'))) SEPARATOR ' OR ') INTO v_conditions FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext');
        IF v_conditions IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND (', v_conditions, ')'); END IF;
    END IF;
    SET v_sql = CONCAT('SELECT * FROM `', DATABASE(), '`.`', p_tabela, '` WHERE ', v_where, ' LIMIT ', LEAST(GREATEST(COALESCE(p_limite, 25), 1), 1000), ' OFFSET ', GREATEST(COALESCE(p_offset, 0), 0)); SET @crud_sql = v_sql; PREPARE stmt FROM @crud_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
END$$

CREATE PROCEDURE `sp_crud_count`(IN p_tabela VARCHAR(128), IN p_filtros JSON, IN p_busca VARCHAR(255))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_where LONGTEXT DEFAULT '1 = 1';
    DECLARE v_conditions LONGTEXT;
    DECLARE v_sql LONGTEXT;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela AND TABLE_TYPE IN ('BASE TABLE','VIEW')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tabela ou view nao autorizada ou inexistente'; END IF;
    IF p_filtros IS NOT NULL AND JSON_LENGTH(p_filtros) > 0 THEN SELECT GROUP_CONCAT(CASE WHEN c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext') THEN CONCAT('`', REPLACE(j.campo, '`', '``'), '` LIKE ', QUOTE(CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(j.campo)))), '%'))) ELSE CONCAT('`', REPLACE(j.campo, '`', '``'), '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(j.campo)))))) END SEPARATOR ' AND ') INTO v_conditions FROM JSON_TABLE(JSON_KEYS(p_filtros), '$[*]' COLUMNS(campo VARCHAR(128) PATH '$')) j INNER JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.COLUMN_NAME = j.campo; IF v_conditions IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND ', v_conditions); END IF; END IF;
    IF p_busca IS NOT NULL AND p_busca <> '' THEN SELECT GROUP_CONCAT(CONCAT('`', REPLACE(c.COLUMN_NAME, '`', '``'), '` LIKE ', QUOTE(CONCAT('%', p_busca, '%'))) SEPARATOR ' OR ') INTO v_conditions FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext'); IF v_conditions IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND (', v_conditions, ')'); END IF; END IF;
    SET v_sql = CONCAT('SELECT COUNT(*) AS total_registros FROM `', DATABASE(), '`.`', p_tabela, '` WHERE ', v_where); SET @crud_sql = v_sql; PREPARE stmt FROM @crud_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
END$$

DELIMITER ;

CREATE OR REPLACE VIEW `vw_legislacao_grid` AS
SELECT l.`CODLEGISLACAO`, l.`CODIGO`, l.`CODAMBITO`, l.`CODTIPO`, l.`NR`, l.`ANO`, l.`ARTIGOS`,
       l.`MEIO_AMBIENTE`, l.`SEGURANCA`, l.`QUALIDADE`, l.`RESPOSABILIDADE`, l.`PADRAO`,
       l.`data_atualizacao`, l.`situacao_da_lei`, l.`devido_da_lei`,
       (SELECT GROUP_CONCAT(a.`DSCASSUNTO` ORDER BY a.`DSCASSUNTO` SEPARATOR '; ')
          FROM `legislacaoxassunto` la
          INNER JOIN `assuntos` a ON a.`CODASSUNTO` = la.`CODASSUNTO`
         WHERE la.`CODLEGISLACAO` = l.`CODLEGISLACAO`) AS `ASSUNTOS`
  FROM `legislacao` l;
