-- Migração 286 — Requisitos inativados (apresentação Atlas Schindler 2026, Módulo Legal: "o usuário pode retirar requisitos da
-- base ativa, mantendo-os em uma tabela de leis inativadas").
-- Por unidade e com efeito imediato. Inativar move a linha de legislacaoxempresa para legislacaoxempresa_inativos (mesma
-- estrutura + quem/quando/por quê); avaliações, evidências, responsáveis e vínculos ficam onde estão (histórico preservado).
-- Reativar devolve a linha original. Tudo registrado em requisitos_inativacao_historico.
-- Executado por scripts/aplicar-migracao-286-requisitos-inativados.php (blocos separados por "-- @@").

-- @@
CREATE TABLE IF NOT EXISTS `legislacaoxempresa_inativos` LIKE `legislacaoxempresa`

-- @@
CREATE TABLE IF NOT EXISTS `requisitos_inativacao_historico` (
    `codhistorico` INT NOT NULL AUTO_INCREMENT,
    `CODLEGISLACAO` INT NOT NULL,
    `CODUNIDADE` INT NOT NULL,
    `acao` VARCHAR(10) NOT NULL,
    `motivo` VARCHAR(2000) NULL,
    `codusuario` INT NULL,
    `em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codhistorico`),
    KEY `ix_req_inat_hist_req` (`CODLEGISLACAO`, `CODUNIDADE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

-- @@
DROP PROCEDURE IF EXISTS `sp_requisito_inativar`

-- @@
-- Inativa o requisito (lei + unidade). p_motivo obrigatório (10+ caracteres). p_resultado: OK, NAO_ENCONTRADO ou MOTIVO.
CREATE PROCEDURE `sp_requisito_inativar`(IN p_codlegislacao INT, IN p_codunidade INT, IN p_motivo VARCHAR(2000), IN p_codusuario INT, OUT p_resultado VARCHAR(20))
proc: BEGIN
    DECLARE v_cols TEXT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF CHAR_LENGTH(TRIM(COALESCE(p_motivo, ''))) < 10 THEN SET p_resultado = 'MOTIVO'; LEAVE proc; END IF;
    IF NOT EXISTS (SELECT 1 FROM `legislacaoxempresa` WHERE `CODLEGISLACAO` = p_codlegislacao AND `codunidade` = p_codunidade) THEN
        SET p_resultado = 'NAO_ENCONTRADO'; LEAVE proc;
    END IF;
    -- colunas originais (as de controle da inativação ficam fora)
    SELECT GROUP_CONCAT(CONCAT('`', COLUMN_NAME, '`') ORDER BY ORDINAL_POSITION SEPARATOR ', ') INTO v_cols
      FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legislacaoxempresa';
    START TRANSACTION;
    -- uma inativação anterior da mesma lei/unidade é substituída pela atual
    DELETE FROM `legislacaoxempresa_inativos` WHERE `CODLEGISLACAO` = p_codlegislacao AND `codunidade` = p_codunidade;
    SET @sql_inat = CONCAT('INSERT INTO `legislacaoxempresa_inativos` (', v_cols, ', `inativado_em`, `inativado_por`, `inativado_motivo`) SELECT ', v_cols,
        ', NOW(), ', COALESCE(p_codusuario, 'NULL'), ', ', QUOTE(TRIM(p_motivo)), ' FROM `legislacaoxempresa` WHERE `CODLEGISLACAO` = ', p_codlegislacao, ' AND `codunidade` = ', p_codunidade);
    PREPARE st FROM @sql_inat; EXECUTE st; DEALLOCATE PREPARE st;
    DELETE FROM `legislacaoxempresa` WHERE `CODLEGISLACAO` = p_codlegislacao AND `codunidade` = p_codunidade;
    -- situação calculada é derivada: sai junto (volta no recálculo ao reativar)
    IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'situacao_legal_calculada') THEN
        DELETE FROM `situacao_legal_calculada` WHERE `CODLEGISLACAO` = p_codlegislacao AND `CODUNIDADE` = p_codunidade;
    END IF;
    INSERT INTO `requisitos_inativacao_historico` (`CODLEGISLACAO`, `CODUNIDADE`, `acao`, `motivo`, `codusuario`) VALUES (p_codlegislacao, p_codunidade, 'INATIVAR', TRIM(p_motivo), p_codusuario);
    COMMIT;
    SET p_resultado = 'OK';
END

-- @@
DROP PROCEDURE IF EXISTS `sp_requisito_reativar`

-- @@
-- Reativa: devolve a linha original para legislacaoxempresa. Se a lei já voltou para a unidade por outro caminho (matriz de
-- aplicabilidade), só encerra a inativação. p_resultado: OK, JA_ATIVO ou NAO_ENCONTRADO.
CREATE PROCEDURE `sp_requisito_reativar`(IN p_codlegislacao INT, IN p_codunidade INT, IN p_motivo VARCHAR(2000), IN p_codusuario INT, OUT p_resultado VARCHAR(20))
proc: BEGIN
    DECLARE v_cols TEXT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF NOT EXISTS (SELECT 1 FROM `legislacaoxempresa_inativos` WHERE `CODLEGISLACAO` = p_codlegislacao AND `codunidade` = p_codunidade) THEN
        SET p_resultado = 'NAO_ENCONTRADO'; LEAVE proc;
    END IF;
    SELECT GROUP_CONCAT(CONCAT('`', COLUMN_NAME, '`') ORDER BY ORDINAL_POSITION SEPARATOR ', ') INTO v_cols
      FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legislacaoxempresa';
    START TRANSACTION;
    IF EXISTS (SELECT 1 FROM `legislacaoxempresa` WHERE `CODLEGISLACAO` = p_codlegislacao AND `codunidade` = p_codunidade) THEN
        SET p_resultado = 'JA_ATIVO';
    ELSE
        SET @sql_reat = CONCAT('INSERT INTO `legislacaoxempresa` (', v_cols, ') SELECT ', v_cols, ' FROM `legislacaoxempresa_inativos` WHERE `CODLEGISLACAO` = ', p_codlegislacao, ' AND `codunidade` = ', p_codunidade);
        PREPARE st FROM @sql_reat; EXECUTE st; DEALLOCATE PREPARE st;
        SET p_resultado = 'OK';
    END IF;
    DELETE FROM `legislacaoxempresa_inativos` WHERE `CODLEGISLACAO` = p_codlegislacao AND `codunidade` = p_codunidade;
    INSERT INTO `requisitos_inativacao_historico` (`CODLEGISLACAO`, `CODUNIDADE`, `acao`, `motivo`, `codusuario`) VALUES (p_codlegislacao, p_codunidade, 'REATIVAR', NULLIF(TRIM(COALESCE(p_motivo, '')), ''), p_codusuario);
    COMMIT;
    IF EXISTS (SELECT 1 FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_situacao_legal_recalcular') THEN
        CALL sp_situacao_legal_recalcular(p_codlegislacao, p_codunidade);
    END IF;
END
