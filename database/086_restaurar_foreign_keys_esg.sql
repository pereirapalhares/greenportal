-- Green Portal
-- Restauração das foreign keys ESG existentes no legado green_modelo.
-- O CREATE TABLE LIKE não copia constraints referenciais.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET SESSION group_concat_max_len = 65535;

DROP PROCEDURE IF EXISTS `sp_restaurar_foreign_keys_esg`;

DELIMITER $$

CREATE PROCEDURE `sp_restaurar_foreign_keys_esg`()
BEGIN
    DECLARE v_fim TINYINT DEFAULT 0;
    DECLARE v_constraint VARCHAR(128);
    DECLARE v_tabela VARCHAR(128);
    DECLARE v_colunas LONGTEXT;
    DECLARE v_tabela_referenciada VARCHAR(128);
    DECLARE v_colunas_referenciadas LONGTEXT;
    DECLARE v_update_rule VARCHAR(30);
    DECLARE v_delete_rule VARCHAR(30);
    DECLARE v_sql LONGTEXT;

    DECLARE c_fks CURSOR FOR
        SELECT k.CONSTRAINT_NAME,
               k.TABLE_NAME,
               GROUP_CONCAT(CONCAT('`', REPLACE(k.COLUMN_NAME, '`', '``'), '`')
                            ORDER BY k.ORDINAL_POSITION SEPARATOR ', '),
               k.REFERENCED_TABLE_NAME,
               GROUP_CONCAT(CONCAT('`', REPLACE(k.REFERENCED_COLUMN_NAME, '`', '``'), '`')
                            ORDER BY k.ORDINAL_POSITION SEPARATOR ', '),
               r.UPDATE_RULE,
               r.DELETE_RULE
          FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
          INNER JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
                  ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                 AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                 AND r.TABLE_NAME = k.TABLE_NAME
         WHERE k.CONSTRAINT_SCHEMA = 'green_modelo'
           AND k.TABLE_SCHEMA = 'green_modelo'
           AND k.TABLE_NAME LIKE 'esg\\_%' ESCAPE '\\'
           AND k.CONSTRAINT_NAME LIKE 'FK\\_esg\\_%' ESCAPE '\\'
           AND k.REFERENCED_TABLE_NAME IS NOT NULL
         GROUP BY k.CONSTRAINT_NAME, k.TABLE_NAME,
                  k.REFERENCED_TABLE_NAME, r.UPDATE_RULE, r.DELETE_RULE
         ORDER BY k.CONSTRAINT_NAME;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim = 1;

    OPEN c_fks;

    ciclo: LOOP
        FETCH c_fks INTO v_constraint, v_tabela, v_colunas,
                         v_tabela_referenciada, v_colunas_referenciadas,
                         v_update_rule, v_delete_rule;
        IF v_fim = 1 THEN
            LEAVE ciclo;
        END IF;

        IF NOT EXISTS (
            SELECT 1
              FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = 'gmodelo_dbp'
               AND CONSTRAINT_NAME = v_constraint
        ) THEN
            SET v_sql = CONCAT(
                'ALTER TABLE `gmodelo_dbp`.`', REPLACE(v_tabela, '`', '``'), '` ',
                'ADD CONSTRAINT `', REPLACE(v_constraint, '`', '``'), '` ',
                'FOREIGN KEY (', v_colunas, ') ',
                'REFERENCES `gmodelo_dbp`.`', REPLACE(v_tabela_referenciada, '`', '``'), '` (',
                v_colunas_referenciadas, ') ',
                'ON DELETE ', v_delete_rule, ' ON UPDATE ', v_update_rule
            );
            SET @sql_esg_fk = v_sql;
            PREPARE stmt_esg_fk FROM @sql_esg_fk;
            EXECUTE stmt_esg_fk;
            DEALLOCATE PREPARE stmt_esg_fk;
        END IF;
    END LOOP;

    CLOSE c_fks;
END$$

DELIMITER ;

CALL `gmodelo_dbp`.`sp_restaurar_foreign_keys_esg`();
DROP PROCEDURE `sp_restaurar_foreign_keys_esg`;

