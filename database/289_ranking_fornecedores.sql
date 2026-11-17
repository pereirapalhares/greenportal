-- Migração 289 — Ranking de fornecedores (Homologação; apresentação Atlas Schindler 2026, Onda 2).
-- Nota 0–100 por fornecedor ativo, faixas A/B/C/D, histórico diário e bloqueio manual com justificativa.
--   fo_ranking_config               pesos, faixas e penalidades (1 linha; tela Administração › Pesos do ranking)
--   fo_ranking_config_historico     quem mudou o quê
--   fo_ranking                      última nota calculada de cada fornecedor (gravada só pelo serviço agendado)
--   fo_ranking_historico            uma nota por fornecedor por dia (tendência)
--   fo_fornecedor_bloqueios         fornecedores bloqueados (motivo obrigatório) e o histórico de bloqueios/desbloqueios
-- O cálculo fica na view vw_fornecedor_ranking_calculo (montada pelo script, conforme as tabelas da empresa) e é gravado por
-- sp_fornecedor_ranking_atualizar, chamada SÓ pelo serviço scripts/fornecedor-ranking-worker.php no Agendador do Windows.
-- Executado por scripts/aplicar-migracao-289-ranking-fornecedores.php (blocos separados por "-- @@").

-- @@
CREATE TABLE IF NOT EXISTS `fo_ranking_config` (
    `id` TINYINT NOT NULL,
    `peso_doc_fornecedor` DECIMAL(5,2) NOT NULL DEFAULT 35,
    `peso_doc_funcionarios` DECIMAL(5,2) NOT NULL DEFAULT 25,
    `peso_nc` DECIMAL(5,2) NOT NULL DEFAULT 25,
    `peso_auditoria` DECIMAL(5,2) NOT NULL DEFAULT 15,
    `faixa_a` DECIMAL(5,2) NOT NULL DEFAULT 85,
    `faixa_b` DECIMAL(5,2) NOT NULL DEFAULT 70,
    `faixa_c` DECIMAL(5,2) NOT NULL DEFAULT 50,
    `nc_penalidade_aberta` DECIMAL(5,2) NOT NULL DEFAULT 15,
    `nc_penalidade_atrasada` DECIMAL(5,2) NOT NULL DEFAULT 10,
    `nc_penalidade_fechada` DECIMAL(5,2) NOT NULL DEFAULT 5,
    `nc_janela_meses` INT NOT NULL DEFAULT 12,
    `recalcular` TINYINT NOT NULL DEFAULT 1,
    `ultimo_calculo` DATETIME NULL,
    `alterado_por` INT NULL,
    `alterado_em` DATETIME NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB

-- @@
INSERT IGNORE INTO `fo_ranking_config` (`id`) VALUES (1)

-- @@
CREATE TABLE IF NOT EXISTS `fo_ranking_config_historico` (
    `codhistorico` INT NOT NULL AUTO_INCREMENT,
    `antes` TEXT NULL,
    `depois` TEXT NULL,
    `codusuario` INT NULL,
    `em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codhistorico`)
) ENGINE=InnoDB

-- @@
CREATE TABLE IF NOT EXISTS `fo_ranking` (
    `codfornecedor` INT NOT NULL,
    `codunidade` INT NULL,
    `nota` DECIMAL(5,1) NULL,
    `faixa` CHAR(1) NULL,
    `nota_doc_fornecedor` DECIMAL(5,1) NULL,
    `nota_doc_funcionarios` DECIMAL(5,1) NULL,
    `nota_nc` DECIMAL(5,1) NULL,
    `nota_auditoria` DECIMAL(5,1) NULL,
    `doc_ok` INT NOT NULL DEFAULT 0,
    `doc_total` INT NOT NULL DEFAULT 0,
    `func_ok` INT NOT NULL DEFAULT 0,
    `func_total` INT NOT NULL DEFAULT 0,
    `funcionarios` INT NOT NULL DEFAULT 0,
    `nc_abertas` INT NOT NULL DEFAULT 0,
    `nc_atrasadas` INT NOT NULL DEFAULT 0,
    `nc_fechadas` INT NOT NULL DEFAULT 0,
    `auditorias` INT NOT NULL DEFAULT 0,
    `calculado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codfornecedor`),
    KEY `ix_fo_ranking_faixa` (`faixa`, `nota`)
) ENGINE=InnoDB

-- @@
CREATE TABLE IF NOT EXISTS `fo_ranking_historico` (
    `codfornecedor` INT NOT NULL,
    `data` DATE NOT NULL,
    `nota` DECIMAL(5,1) NULL,
    `faixa` CHAR(1) NULL,
    PRIMARY KEY (`codfornecedor`, `data`)
) ENGINE=InnoDB

-- @@
CREATE TABLE IF NOT EXISTS `fo_fornecedor_bloqueios` (
    `codfornecedor` INT NOT NULL,
    `motivo` VARCHAR(2000) NOT NULL,
    `codusuario` INT NULL,
    `bloqueado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codfornecedor`)
) ENGINE=InnoDB

-- @@
CREATE TABLE IF NOT EXISTS `fo_fornecedor_bloqueios_historico` (
    `codhistorico` INT NOT NULL AUTO_INCREMENT,
    `codfornecedor` INT NOT NULL,
    `acao` VARCHAR(12) NOT NULL,
    `motivo` VARCHAR(2000) NULL,
    `codusuario` INT NULL,
    `em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codhistorico`),
    KEY `ix_fo_bloq_hist_forn` (`codfornecedor`, `em`)
) ENGINE=InnoDB

-- @@
DROP PROCEDURE IF EXISTS `sp_fornecedor_ranking_atualizar`

-- @@
-- Grava a nota de todos os fornecedores (vw_fornecedor_ranking_calculo → fo_ranking) e a nota do dia no histórico.
-- Só calcula quando pedido (recalcular = 1, ex.: pesos alterados), uma vez por dia, ou com p_forcar = 1.
CREATE PROCEDURE `sp_fornecedor_ranking_atualizar`(IN p_forcar TINYINT)
BEGIN
    DECLARE v_flag TINYINT DEFAULT 0;
    DECLARE v_ultimo DATETIME DEFAULT NULL;
    DECLARE v_qtd INT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_flag = 1;
    SELECT `recalcular`, `ultimo_calculo` INTO v_flag, v_ultimo FROM `fo_ranking_config` WHERE `id` = 1;
    IF p_forcar = 1 OR v_flag = 1 OR v_ultimo IS NULL OR DATE(v_ultimo) < CURDATE() THEN
        START TRANSACTION;
        DELETE FROM `fo_ranking`;
        INSERT INTO `fo_ranking` (`codfornecedor`, `codunidade`, `nota`, `faixa`, `nota_doc_fornecedor`, `nota_doc_funcionarios`, `nota_nc`, `nota_auditoria`,
                                  `doc_ok`, `doc_total`, `func_ok`, `func_total`, `funcionarios`, `nc_abertas`, `nc_atrasadas`, `nc_fechadas`, `auditorias`, `calculado_em`)
        SELECT `CODFORNECEDOR`, `CODUNIDADE`, `NOTA`, `FAIXA`, `NOTA_DOC_FORNECEDOR`, `NOTA_DOC_FUNCIONARIOS`, `NOTA_NC`, `NOTA_AUDITORIA`,
               `DOC_OK`, `DOC_TOTAL`, `FUNC_OK`, `FUNC_TOTAL`, `FUNCIONARIOS`, `NC_ABERTAS`, `NC_ATRASADAS`, `NC_FECHADAS`, `AUDITORIAS`, NOW()
          FROM `vw_fornecedor_ranking_calculo`;
        SET v_qtd = ROW_COUNT();
        INSERT INTO `fo_ranking_historico` (`codfornecedor`, `data`, `nota`, `faixa`)
        SELECT `codfornecedor`, CURDATE(), `nota`, `faixa` FROM `fo_ranking`
        ON DUPLICATE KEY UPDATE `nota` = VALUES(`nota`), `faixa` = VALUES(`faixa`);
        UPDATE `fo_ranking_config` SET `recalcular` = 0, `ultimo_calculo` = NOW() WHERE `id` = 1;
        COMMIT;
        SELECT 'CALCULADO' AS `status`, v_qtd AS `fornecedores`;
    ELSE
        SELECT 'EM_DIA' AS `status`, 0 AS `fornecedores`;
    END IF;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fornecedor_ranking_config_salvar`

-- @@
-- Pesos (soma 100), faixas (A > B > C > 0) e penalidades das NCs. Guarda o antes/depois e pede um novo cálculo ao serviço.
CREATE PROCEDURE `sp_fornecedor_ranking_config_salvar`(
    IN p_peso_doc DECIMAL(5,2), IN p_peso_func DECIMAL(5,2), IN p_peso_nc DECIMAL(5,2), IN p_peso_aud DECIMAL(5,2),
    IN p_faixa_a DECIMAL(5,2), IN p_faixa_b DECIMAL(5,2), IN p_faixa_c DECIMAL(5,2),
    IN p_pen_aberta DECIMAL(5,2), IN p_pen_atrasada DECIMAL(5,2), IN p_pen_fechada DECIMAL(5,2), IN p_janela INT,
    IN p_usuario INT, OUT p_resultado VARCHAR(20))
proc: BEGIN
    DECLARE v_antes TEXT;
    IF p_peso_doc < 0 OR p_peso_func < 0 OR p_peso_nc < 0 OR p_peso_aud < 0 THEN SET p_resultado = 'VALORES'; LEAVE proc; END IF;
    IF ABS(p_peso_doc + p_peso_func + p_peso_nc + p_peso_aud - 100) > 0.01 THEN SET p_resultado = 'PESOS_SOMA'; LEAVE proc; END IF;
    IF NOT (p_faixa_a <= 100 AND p_faixa_a > p_faixa_b AND p_faixa_b > p_faixa_c AND p_faixa_c > 0) THEN SET p_resultado = 'FAIXAS'; LEAVE proc; END IF;
    IF p_pen_aberta < 0 OR p_pen_aberta > 100 OR p_pen_atrasada < 0 OR p_pen_atrasada > 100 OR p_pen_fechada < 0 OR p_pen_fechada > 100
       OR p_janela < 1 OR p_janela > 60 THEN SET p_resultado = 'VALORES'; LEAVE proc; END IF;
    SELECT CONCAT('pesos ', `peso_doc_fornecedor`, '/', `peso_doc_funcionarios`, '/', `peso_nc`, '/', `peso_auditoria`,
                  '; faixas ', `faixa_a`, '/', `faixa_b`, '/', `faixa_c`,
                  '; NC ', `nc_penalidade_aberta`, '/', `nc_penalidade_atrasada`, '/', `nc_penalidade_fechada`, ' em ', `nc_janela_meses`, ' meses')
      INTO v_antes FROM `fo_ranking_config` WHERE `id` = 1;
    UPDATE `fo_ranking_config`
       SET `peso_doc_fornecedor` = p_peso_doc, `peso_doc_funcionarios` = p_peso_func, `peso_nc` = p_peso_nc, `peso_auditoria` = p_peso_aud,
           `faixa_a` = p_faixa_a, `faixa_b` = p_faixa_b, `faixa_c` = p_faixa_c,
           `nc_penalidade_aberta` = p_pen_aberta, `nc_penalidade_atrasada` = p_pen_atrasada, `nc_penalidade_fechada` = p_pen_fechada,
           `nc_janela_meses` = p_janela, `recalcular` = 1, `alterado_por` = p_usuario, `alterado_em` = NOW()
     WHERE `id` = 1;
    INSERT INTO `fo_ranking_config_historico` (`antes`, `depois`, `codusuario`)
    VALUES (v_antes, CONCAT('pesos ', p_peso_doc, '/', p_peso_func, '/', p_peso_nc, '/', p_peso_aud, '; faixas ', p_faixa_a, '/', p_faixa_b, '/', p_faixa_c,
                            '; NC ', p_pen_aberta, '/', p_pen_atrasada, '/', p_pen_fechada, ' em ', p_janela, ' meses'), p_usuario);
    SET p_resultado = 'OK';
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fornecedor_ranking_solicitar`

-- @@
-- "Recalcular agora": só marca o pedido; quem calcula é o serviço agendado (em até 15 minutos).
CREATE PROCEDURE `sp_fornecedor_ranking_solicitar`()
BEGIN
    UPDATE `fo_ranking_config` SET `recalcular` = 1 WHERE `id` = 1;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fornecedor_bloquear`

-- @@
-- Bloqueia (ou atualiza o motivo do bloqueio). Motivo obrigatório (mínimo 10 caracteres).
CREATE PROCEDURE `sp_fornecedor_bloquear`(IN p_codfornecedor INT, IN p_motivo VARCHAR(2000), IN p_usuario INT, OUT p_resultado VARCHAR(20))
proc: BEGIN
    DECLARE v_existe INT DEFAULT 0;
    DECLARE v_bloqueado INT DEFAULT 0;
    IF CHAR_LENGTH(TRIM(COALESCE(p_motivo, ''))) < 10 THEN SET p_resultado = 'MOTIVO'; LEAVE proc; END IF;
    SELECT COUNT(*) INTO v_existe FROM `fo_fornecedores` WHERE `codfornecedor` = p_codfornecedor;
    IF v_existe = 0 THEN SET p_resultado = 'NAO_ENCONTRADO'; LEAVE proc; END IF;
    SELECT COUNT(*) INTO v_bloqueado FROM `fo_fornecedor_bloqueios` WHERE `codfornecedor` = p_codfornecedor;
    INSERT INTO `fo_fornecedor_bloqueios` (`codfornecedor`, `motivo`, `codusuario`, `bloqueado_em`)
    VALUES (p_codfornecedor, TRIM(p_motivo), p_usuario, NOW())
    ON DUPLICATE KEY UPDATE `motivo` = VALUES(`motivo`), `codusuario` = VALUES(`codusuario`), `bloqueado_em` = VALUES(`bloqueado_em`);
    INSERT INTO `fo_fornecedor_bloqueios_historico` (`codfornecedor`, `acao`, `motivo`, `codusuario`)
    VALUES (p_codfornecedor, IF(v_bloqueado > 0, 'ALTERADO', 'BLOQUEADO'), TRIM(p_motivo), p_usuario);
    SET p_resultado = IF(v_bloqueado > 0, 'ATUALIZADO', 'OK');
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fornecedor_desbloquear`

-- @@
CREATE PROCEDURE `sp_fornecedor_desbloquear`(IN p_codfornecedor INT, IN p_motivo VARCHAR(2000), IN p_usuario INT, OUT p_resultado VARCHAR(20))
proc: BEGIN
    DECLARE v_bloqueado INT DEFAULT 0;
    IF CHAR_LENGTH(TRIM(COALESCE(p_motivo, ''))) < 10 THEN SET p_resultado = 'MOTIVO'; LEAVE proc; END IF;
    SELECT COUNT(*) INTO v_bloqueado FROM `fo_fornecedor_bloqueios` WHERE `codfornecedor` = p_codfornecedor;
    IF v_bloqueado = 0 THEN SET p_resultado = 'NAO_BLOQUEADO'; LEAVE proc; END IF;
    DELETE FROM `fo_fornecedor_bloqueios` WHERE `codfornecedor` = p_codfornecedor;
    INSERT INTO `fo_fornecedor_bloqueios_historico` (`codfornecedor`, `acao`, `motivo`, `codusuario`)
    VALUES (p_codfornecedor, 'DESBLOQUEADO', TRIM(p_motivo), p_usuario);
    SET p_resultado = 'OK';
END
