-- Green Portal
-- Normalização das tabelas migradas dos módulos Homologação e ESG.
-- Destino: gmodelo_dbp
--
-- Todas as tabelas físicas fo_* e esg_* são convertidas para:
--   ENGINE = InnoDB
--   CHARACTER SET = utf8mb4
--   COLLATE = utf8mb4_0900_ai_ci
--
-- Executar somente após backup e validação dos textos.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

DROP PROCEDURE IF EXISTS `sp_normalizar_fo_esg_innodb_utf8mb4`;

DELIMITER $$

CREATE PROCEDURE `sp_normalizar_fo_esg_innodb_utf8mb4`()
BEGIN
    DECLARE v_fim TINYINT DEFAULT 0;
    DECLARE v_tabela VARCHAR(128);
    DECLARE v_sql LONGTEXT;

    DECLARE c_tabelas CURSOR FOR
        SELECT TABLE_NAME
          FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = 'gmodelo_dbp'
           AND TABLE_TYPE = 'BASE TABLE'
           AND (
                TABLE_NAME LIKE 'fo\\_%' ESCAPE '\\'
                OR TABLE_NAME LIKE 'esg\\_%' ESCAPE '\\'
           )
         ORDER BY TABLE_NAME;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim = 1;

    OPEN c_tabelas;

    ciclo: LOOP
        FETCH c_tabelas INTO v_tabela;
        IF v_fim = 1 THEN
            LEAVE ciclo;
        END IF;

        SET v_sql = CONCAT(
            'ALTER TABLE `gmodelo_dbp`.`',
            REPLACE(v_tabela, '`', '``'),
            '` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci'
        );
        SET @sql_normalizacao = v_sql;
        PREPARE stmt_normalizacao FROM @sql_normalizacao;
        EXECUTE stmt_normalizacao;
        DEALLOCATE PREPARE stmt_normalizacao;
    END LOOP;

    CLOSE c_tabelas;
END$$

DELIMITER ;

CALL `gmodelo_dbp`.`sp_normalizar_fo_esg_innodb_utf8mb4`();
DROP PROCEDURE `gmodelo_dbp`.`sp_normalizar_fo_esg_innodb_utf8mb4`;

