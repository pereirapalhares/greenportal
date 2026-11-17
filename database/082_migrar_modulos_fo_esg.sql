-- Green Portal
-- Migração dos módulos legados:
--   fo_  = Homologação de Fornecedores
--   esg_ = ESG
--
-- Origem: green_modelo
-- Destino: gmodelo_dbp
--
-- IMPORTANTE:
-- 1. Executar primeiro em homologação.
-- 2. Fazer backup completo de green_modelo e gmodelo_dbp.
-- 3. Este script migra somente tabelas físicas (BASE TABLE), não views,
--    procedures, triggers ou configurações de telas.
-- 4. A estrutura é copiada com CREATE TABLE LIKE e os dados são carregados
--    com INSERT IGNORE, preservando as chaves existentes.
-- 5. Conversão de engine, charset e collations deve ser feita depois da
--    validação estrutural e textual, em migração própria.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE DATABASE IF NOT EXISTS `gmodelo_dbp`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

DROP PROCEDURE IF EXISTS `gmodelo_dbp`.`sp_migrar_modulos_fo_esg`;

DELIMITER $$

CREATE PROCEDURE `gmodelo_dbp`.`sp_migrar_modulos_fo_esg`()
BEGIN
    DECLARE v_fim TINYINT DEFAULT 0;
    DECLARE v_tabela VARCHAR(128);
    DECLARE v_sql LONGTEXT;

    DECLARE c_tabelas CURSOR FOR
        SELECT TABLE_NAME
          FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = 'green_modelo'
           AND TABLE_TYPE = 'BASE TABLE'
           AND (
                TABLE_NAME LIKE 'fo\\_%' ESCAPE '\\'
                OR TABLE_NAME LIKE 'esg\\_%' ESCAPE '\\'
           )
         ORDER BY TABLE_NAME;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim = 1;

    DROP TEMPORARY TABLE IF EXISTS `tmp_migracao_fo_esg`;
    CREATE TEMPORARY TABLE `tmp_migracao_fo_esg` (
        `tabela` VARCHAR(128) NOT NULL,
        `linhas_origem` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `linhas_destino_antes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `linhas_destino_depois` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `status` VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
        `mensagem` TEXT NULL,
        PRIMARY KEY (`tabela`)
    ) ENGINE=InnoDB;

    OPEN c_tabelas;

    ciclo: LOOP
        FETCH c_tabelas INTO v_tabela;
        IF v_fim = 1 THEN
            LEAVE ciclo;
        END IF;

        SET v_sql = CONCAT(
            'INSERT INTO `tmp_migracao_fo_esg` (`tabela`, `linhas_origem`) ',
            'SELECT ', QUOTE(v_tabela), ', COUNT(*) FROM `green_modelo`.`',
            REPLACE(v_tabela, '`', '``'), '`'
        );
        SET @sql_migracao = v_sql;
        PREPARE stmt_migracao FROM @sql_migracao;
        EXECUTE stmt_migracao;
        DEALLOCATE PREPARE stmt_migracao;

        SET v_sql = CONCAT(
            'CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`',
            REPLACE(v_tabela, '`', '``'), '` LIKE `green_modelo`.`',
            REPLACE(v_tabela, '`', '``'), '`'
        );
        SET @sql_migracao = v_sql;
        PREPARE stmt_migracao FROM @sql_migracao;
        EXECUTE stmt_migracao;
        DEALLOCATE PREPARE stmt_migracao;

        SET v_sql = CONCAT(
            'SELECT COUNT(*) INTO @linhas_destino_antes FROM `gmodelo_dbp`.`',
            REPLACE(v_tabela, '`', '``'), '`'
        );
        SET @linhas_destino_antes = 0;
        SET @sql_migracao = v_sql;
        PREPARE stmt_migracao FROM @sql_migracao;
        EXECUTE stmt_migracao;
        DEALLOCATE PREPARE stmt_migracao;

        UPDATE `tmp_migracao_fo_esg`
           SET `linhas_destino_antes` = COALESCE(@linhas_destino_antes, 0)
         WHERE `tabela` = v_tabela;

        SET v_sql = CONCAT(
            'INSERT IGNORE INTO `gmodelo_dbp`.`',
            REPLACE(v_tabela, '`', '``'), '` SELECT * FROM `green_modelo`.`',
            REPLACE(v_tabela, '`', '``'), '`'
        );
        SET @sql_migracao = v_sql;
        PREPARE stmt_migracao FROM @sql_migracao;
        EXECUTE stmt_migracao;
        DEALLOCATE PREPARE stmt_migracao;

        SET v_sql = CONCAT(
            'SELECT COUNT(*) INTO @linhas_destino_depois FROM `gmodelo_dbp`.`',
            REPLACE(v_tabela, '`', '``'), '`'
        );
        SET @linhas_destino_depois = 0;
        SET @sql_migracao = v_sql;
        PREPARE stmt_migracao FROM @sql_migracao;
        EXECUTE stmt_migracao;
        DEALLOCATE PREPARE stmt_migracao;

        UPDATE `tmp_migracao_fo_esg`
           SET `linhas_destino_depois` = COALESCE(@linhas_destino_depois, 0),
               `status` = CASE
                   WHEN COALESCE(@linhas_destino_depois, 0) >= `linhas_origem` THEN 'OK'
                   ELSE 'VALIDAR'
               END
         WHERE `tabela` = v_tabela;
    END LOOP;

    CLOSE c_tabelas;

    SELECT `tabela`, `linhas_origem`, `linhas_destino_antes`,
           `linhas_destino_depois`, `status`, `mensagem`
      FROM `tmp_migracao_fo_esg`
     ORDER BY `tabela`;

    DROP TEMPORARY TABLE IF EXISTS `tmp_migracao_fo_esg`;
END$$

DELIMITER ;

-- Execução controlada:
-- CALL `gmodelo_dbp`.`sp_migrar_modulos_fo_esg`();
-- DROP PROCEDURE `gmodelo_dbp`.`sp_migrar_modulos_fo_esg`;
