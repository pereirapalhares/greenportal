-- Green Portal - CRUD universal e views do tenant Modelo.
-- Executar depois de 003_adequar_chaves_ao_legado.sql.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

DROP PROCEDURE IF EXISTS `sp_crud_insert`;
DROP PROCEDURE IF EXISTS `sp_crud_update`;
DROP PROCEDURE IF EXISTS `sp_crud_delete`;
DROP PROCEDURE IF EXISTS `sp_crud_select`;

DELIMITER $$

CREATE PROCEDURE `sp_crud_insert`(
    IN p_tabela VARCHAR(128),
    IN p_dados JSON
)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_cols LONGTEXT;
    DECLARE v_vals LONGTEXT;
    DECLARE v_sql LONGTEXT;
    DECLARE v_count INT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = p_tabela
           AND TABLE_TYPE = 'BASE TABLE'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tabela nao autorizada ou inexistente';
    END IF;

    DROP TEMPORARY TABLE IF EXISTS `tmp_crud_campos`;
    CREATE TEMPORARY TABLE `tmp_crud_campos` (
        `campo` VARCHAR(128) NOT NULL,
        `valor` JSON NULL,
        PRIMARY KEY (`campo`)
    );

    INSERT INTO `tmp_crud_campos` (`campo`, `valor`)
    SELECT k.`campo`, JSON_EXTRACT(p_dados, CONCAT('$."', REPLACE(k.`campo`, '"', '\\"'), '"'))
      FROM JSON_TABLE(JSON_KEYS(p_dados), '$[*]' COLUMNS (`campo` VARCHAR(128) PATH '$')) k;

    SELECT COUNT(*) INTO v_count
      FROM `tmp_crud_campos` c
     WHERE NOT EXISTS (
           SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS x
            WHERE x.TABLE_SCHEMA = DATABASE()
              AND x.TABLE_NAME = p_tabela
              AND x.COLUMN_NAME = c.`campo`
       );
    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Campo nao autorizado na operacao de inclusao';
    END IF;

    SELECT GROUP_CONCAT(CONCAT('`', REPLACE(`campo`, '`', '``'), '`') ORDER BY `campo` SEPARATOR ', '),
           GROUP_CONCAT(
               CASE WHEN `valor` IS NULL OR JSON_TYPE(`valor`) = 'NULL'
                    THEN 'NULL'
                    ELSE QUOTE(JSON_UNQUOTE(`valor`)) END
               ORDER BY `campo` SEPARATOR ', '
           )
      INTO v_cols, v_vals
      FROM `tmp_crud_campos`;

    IF v_cols IS NULL OR v_vals IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nenhum campo informado para inclusao';
    END IF;

    SET v_sql = CONCAT('INSERT INTO `', REPLACE(DATABASE(), '`', '``'), '`.`',
                       REPLACE(p_tabela, '`', '``'), '` (', v_cols, ') VALUES (', v_vals, ')');
    SET @crud_sql = v_sql;
    PREPARE stmt FROM @crud_sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;

    SELECT ROW_COUNT() AS registros_afetados, LAST_INSERT_ID() AS chave_gerada;
    DROP TEMPORARY TABLE IF EXISTS `tmp_crud_campos`;
END$$

CREATE PROCEDURE `sp_crud_update`(
    IN p_tabela VARCHAR(128),
    IN p_chave JSON,
    IN p_dados JSON
)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_set LONGTEXT;
    DECLARE v_where LONGTEXT;
    DECLARE v_sql LONGTEXT;
    DECLARE v_count INT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = p_tabela
           AND TABLE_TYPE = 'BASE TABLE'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tabela nao autorizada ou inexistente';
    END IF;

    DROP TEMPORARY TABLE IF EXISTS `tmp_crud_campos`;
    CREATE TEMPORARY TABLE `tmp_crud_campos` (
        `campo` VARCHAR(128) NOT NULL,
        `valor` JSON NULL,
        `eh_chave` TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (`campo`)
    );

    INSERT INTO `tmp_crud_campos` (`campo`, `valor`, `eh_chave`)
    SELECT k.`campo`, JSON_EXTRACT(p_dados, CONCAT('$."', REPLACE(k.`campo`, '"', '\\"'), '"')), 0
      FROM JSON_TABLE(JSON_KEYS(p_dados), '$[*]' COLUMNS (`campo` VARCHAR(128) PATH '$')) k;
    INSERT IGNORE INTO `tmp_crud_campos` (`campo`, `valor`, `eh_chave`)
    SELECT k.`campo`, JSON_EXTRACT(p_chave, CONCAT('$."', REPLACE(k.`campo`, '"', '\\"'), '"')), 1
      FROM JSON_TABLE(JSON_KEYS(p_chave), '$[*]' COLUMNS (`campo` VARCHAR(128) PATH '$')) k;

    SELECT COUNT(*) INTO v_count
      FROM `tmp_crud_campos` c
     WHERE NOT EXISTS (
           SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS x
            WHERE x.TABLE_SCHEMA = DATABASE()
              AND x.TABLE_NAME = p_tabela
              AND x.COLUMN_NAME = c.`campo`
       );
    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Campo nao autorizado na operacao de alteracao';
    END IF;

    SELECT GROUP_CONCAT(
               CONCAT('`', REPLACE(`campo`, '`', '``'), '` = ',
                      CASE WHEN `valor` IS NULL OR JSON_TYPE(`valor`) = 'NULL'
                           THEN 'NULL' ELSE QUOTE(JSON_UNQUOTE(`valor`)) END)
               ORDER BY `campo` SEPARATOR ', '
           ) INTO v_set
      FROM `tmp_crud_campos` WHERE `eh_chave` = 0;
    SELECT GROUP_CONCAT(
               CONCAT('`', REPLACE(`campo`, '`', '``'), '` = ',
                      CASE WHEN `valor` IS NULL OR JSON_TYPE(`valor`) = 'NULL'
                           THEN 'NULL' ELSE QUOTE(JSON_UNQUOTE(`valor`)) END)
               ORDER BY `campo` SEPARATOR ' AND '
           ) INTO v_where
      FROM `tmp_crud_campos` WHERE `eh_chave` = 1;

    IF v_set IS NULL OR v_where IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dados e chave sao obrigatorios para alteracao';
    END IF;

    SET v_sql = CONCAT('UPDATE `', REPLACE(DATABASE(), '`', '``'), '`.`',
                       REPLACE(p_tabela, '`', '``'), '` SET ', v_set, ' WHERE ', v_where);
    SET @crud_sql = v_sql;
    PREPARE stmt FROM @crud_sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;

    SELECT ROW_COUNT() AS registros_afetados;
    DROP TEMPORARY TABLE IF EXISTS `tmp_crud_campos`;
END$$

CREATE PROCEDURE `sp_crud_delete`(
    IN p_tabela VARCHAR(128),
    IN p_chave JSON
)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_where LONGTEXT;
    DECLARE v_sql LONGTEXT;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = p_tabela
           AND TABLE_TYPE = 'BASE TABLE'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tabela nao autorizada ou inexistente';
    END IF;

    SELECT GROUP_CONCAT(
               CONCAT('`', REPLACE(k.`campo`, '`', '``'), '` = ',
                      CASE WHEN JSON_EXTRACT(p_chave, CONCAT('$."', REPLACE(k.`campo`, '"', '\\"'), '"')) IS NULL
                           OR JSON_TYPE(JSON_EXTRACT(p_chave, CONCAT('$."', REPLACE(k.`campo`, '"', '\\"'), '"'))) = 'NULL'
                           THEN 'NULL'
                           ELSE QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_chave, CONCAT('$."', REPLACE(k.`campo`, '"', '\\"'), '"')))) END)
               ORDER BY k.`campo` SEPARATOR ' AND '
           ) INTO v_where
      FROM JSON_TABLE(JSON_KEYS(p_chave), '$[*]' COLUMNS (`campo` VARCHAR(128) PATH '$')) k;

    IF v_where IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Chave obrigatoria para exclusao';
    END IF;

    SET v_sql = CONCAT('DELETE FROM `', REPLACE(DATABASE(), '`', '``'), '`.`',
                       REPLACE(p_tabela, '`', '``'), '` WHERE ', v_where);
    SET @crud_sql = v_sql;
    PREPARE stmt FROM @crud_sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;

    SELECT ROW_COUNT() AS registros_afetados;
END$$

CREATE PROCEDURE `sp_crud_select`(
    IN p_tabela VARCHAR(128),
    IN p_filtros JSON,
    IN p_busca VARCHAR(255),
    IN p_limite INT,
    IN p_offset INT
)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_where LONGTEXT DEFAULT '1 = 1';
    DECLARE v_busca LONGTEXT;
    DECLARE v_sql LONGTEXT;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = p_tabela
           AND TABLE_TYPE IN ('BASE TABLE', 'VIEW')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tabela ou view nao autorizada ou inexistente';
    END IF;

    IF p_filtros IS NOT NULL AND JSON_LENGTH(p_filtros) > 0 THEN
        SELECT GROUP_CONCAT(
                   CONCAT('`', REPLACE(k.`campo`, '`', '``'), '` = ',
                          QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$."', REPLACE(k.`campo`, '"', '\\"'), '"')))))
                   ORDER BY k.`campo` SEPARATOR ' AND '
               ) INTO v_busca
          FROM JSON_TABLE(JSON_KEYS(p_filtros), '$[*]' COLUMNS (`campo` VARCHAR(128) PATH '$')) k
         WHERE EXISTS (
               SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS x
                WHERE x.TABLE_SCHEMA = DATABASE()
                  AND x.TABLE_NAME = p_tabela
                  AND x.COLUMN_NAME = k.`campo`
           );
        IF v_busca IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND ', v_busca); END IF;
    END IF;

    IF p_busca IS NOT NULL AND p_busca <> '' THEN
        SELECT GROUP_CONCAT(
                   CONCAT('`', REPLACE(c.`COLUMN_NAME`, '`', '``'), '` LIKE ', QUOTE(CONCAT('%', p_busca, '%')))
                   SEPARATOR ' OR '
               ) INTO v_busca
          FROM INFORMATION_SCHEMA.COLUMNS c
         WHERE c.TABLE_SCHEMA = DATABASE()
           AND c.TABLE_NAME = p_tabela
           AND c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext');
        IF v_busca IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND (', v_busca, ')'); END IF;
    END IF;

    SET v_sql = CONCAT('SELECT * FROM `', REPLACE(DATABASE(), '`', '``'), '`.`',
                       REPLACE(p_tabela, '`', '``'), '` WHERE ', v_where,
                       ' LIMIT ', LEAST(GREATEST(COALESCE(p_limite, 50), 1), 1000),
                       ' OFFSET ', GREATEST(COALESCE(p_offset, 0), 0));
    SET @crud_sql = v_sql;
    PREPARE stmt FROM @crud_sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END$$

DELIMITER ;

CREATE OR REPLACE VIEW `vw_usuarios_grid` AS
SELECT u.`codusuario`, u.`userid`, u.`nome`, u.`email`, u.`codperfil`, u.`codgrupo`, u.`ativo`,
       p.`codigo` AS `perfil_codigo`, p.`nome` AS `perfil_nome`,
       g.`nome` AS `grupo_nome`, u.`criado_em`, u.`ultimo_acesso`
  FROM `usuarios` u
  LEFT JOIN `perfis` p ON p.`codperfil` = u.`codperfil`
  LEFT JOIN `grupos` g ON g.`codgrupo` = u.`codgrupo`;

CREATE OR REPLACE VIEW `vw_grupos_grid` AS
SELECT `codgrupo`, `nome`, `ativo` FROM `grupos`;

CREATE OR REPLACE VIEW `vw_perfis_grid` AS
SELECT `codperfil`, `codigo`, `nome`, `acesso_total`, `ativo` FROM `perfis`;

CREATE OR REPLACE VIEW `vw_menus_grid` AS
SELECT m.`codmenu`, m.`codmenu_pai`, p.`label` AS `menu_pai`, m.`modulo`,
       m.`codigo`, m.`label`, m.`url`, m.`nivel`, m.`ordem`, m.`ativo`
  FROM `menus` m
  LEFT JOIN `menus` p ON p.`codmenu` = m.`codmenu_pai`;

CREATE OR REPLACE VIEW `vw_empresa_grid` AS
SELECT `codempresa`, `codigo`, `nome`, `banco_dados`, `status`, `criada_em`
  FROM `empresa`;

CREATE OR REPLACE VIEW `vw_perfil_menus_grid` AS
SELECT pm.`codperfil`, p.`codigo` AS `perfil_codigo`, pm.`codmenu`, m.`label` AS `menu_label`,
       pm.`pode_incluir`, pm.`pode_visualizar`, pm.`pode_editar`, pm.`pode_excluir`,
       pm.`pode_exportar_excel`, pm.`pode_gerar_pdf`
  FROM `perfil_menus` pm
  JOIN `perfis` p ON p.`codperfil` = pm.`codperfil`
  JOIN `menus` m ON m.`codmenu` = pm.`codmenu`;

CREATE OR REPLACE VIEW `vw_setup_empresa_grid` AS
SELECT `codsetupempresa`, `chave`, `valor`, `idioma`, `atualizado_em`
  FROM `setup_empresa`;

CREATE OR REPLACE VIEW `vw_logs_auditoria_grid` AS
SELECT l.`codlogauditoria`, l.`codusuario`, u.`userid`, l.`transacao`, l.`acao`,
       l.`entidade`, l.`registro_id`, l.`resultado`, l.`detalhes`, l.`criado_em`
  FROM `logs_auditoria` l
  LEFT JOIN `usuarios` u ON u.`codusuario` = l.`codusuario`;

CREATE OR REPLACE VIEW `vw_registros_migracao_grid` AS
SELECT `codregistromigracao`, `codigo_migracao`, `origem`, `destino`, `tipo`,
       `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`, `criado_em`
  FROM `registros_migracao`;

CREATE OR REPLACE VIEW `vw_bugs_testes_grid` AS
SELECT `codbugteste`, `codigo`, `titulo`, `descricao`, `modulo`, `transacao`,
       `severidade`, `status`, `responsavel`, `encontrado_em`, `resolvido_em`, `criado_em`, `atualizado_em`
  FROM `bugs_testes`;

CREATE OR REPLACE VIEW `vw_artefatos_replicacao_grid` AS
SELECT `codartefatoreplicacao`, `modulo`, `tipo_artefato`, `nome_artefato`,
       `schema_origem`, `versao`, `ordem_replicacao`, `replicar`, `obrigatorio`,
       `dependencias`, `observacao`, `ativo`, `criado_em`, `atualizado_em`
  FROM `artefatos_replicacao`;

INSERT INTO `artefatos_replicacao`
    (`modulo`, `tipo_artefato`, `nome_artefato`, `ordem_replicacao`, `replicar`, `obrigatorio`, `observacao`)
VALUES
    ('SEGURANCA', 'PROCEDURE', 'sp_crud_insert', 500, 1, 1, 'CRUD universal de inclusao'),
    ('SEGURANCA', 'PROCEDURE', 'sp_crud_update', 510, 1, 1, 'CRUD universal de alteracao'),
    ('SEGURANCA', 'PROCEDURE', 'sp_crud_delete', 520, 1, 1, 'CRUD universal de exclusao'),
    ('SEGURANCA', 'PROCEDURE', 'sp_crud_select', 530, 1, 1, 'Consulta universal para grids'),
    ('SEGURANCA', 'VIEW', 'vw_usuarios_grid', 600, 1, 1, 'Grid de usuarios'),
    ('SEGURANCA', 'VIEW', 'vw_grupos_grid', 610, 1, 1, 'Grid de grupos'),
    ('SEGURANCA', 'VIEW', 'vw_perfis_grid', 620, 1, 1, 'Grid de perfis'),
    ('SEGURANCA', 'VIEW', 'vw_menus_grid', 630, 1, 1, 'Grid de menus'),
    ('SEGURANCA', 'VIEW', 'vw_empresa_grid', 640, 0, 0, 'View exclusiva do Modelo'),
    ('SEGURANCA', 'VIEW', 'vw_perfil_menus_grid', 650, 1, 1, 'Grid de permissoes'),
    ('SEGURANCA', 'VIEW', 'vw_setup_empresa_grid', 660, 1, 1, 'Grid de setup'),
    ('SEGURANCA', 'VIEW', 'vw_logs_auditoria_grid', 670, 1, 1, 'Grid de auditoria'),
    ('SEGURANCA', 'VIEW', 'vw_registros_migracao_grid', 680, 1, 0, 'Grid de migracoes'),
    ('SEGURANCA', 'VIEW', 'vw_bugs_testes_grid', 690, 1, 0, 'Grid de testes e bugs'),
    ('SEGURANCA', 'VIEW', 'vw_artefatos_replicacao_grid', 700, 0, 0, 'View exclusiva do Modelo')
ON DUPLICATE KEY UPDATE
    `ordem_replicacao` = VALUES(`ordem_replicacao`),
    `replicar` = VALUES(`replicar`),
    `obrigatorio` = VALUES(`obrigatorio`),
    `observacao` = VALUES(`observacao`),
    `ativo` = 1;
