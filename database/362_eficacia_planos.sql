-- Migração 362 — Avaliação da eficácia nos planos de ação (GER-5 do 01_Mudancas_Gerais; decisões do usuário de 16/11/2026:
-- marcação em cada ação, pendência com alerta ao chegar a 100%, avaliador escolhido na ação).
-- Parte fixa: histórico das avaliações, avisos já dados e a procedure dos alertas. Colunas, gatilhos e views dependem das
-- tabelas de cada banco e são montados por classes/EficaciaPlanos.php. Blocos separados por "-- @@";
-- aplicados por scripts/aplicar-migracao-362-eficacia-planos.php.

-- @@
CREATE TABLE IF NOT EXISTS `plano_eficacia_historico` (
  `CODHISTORICO` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `MODULO` VARCHAR(20) NOT NULL,
  `CODPLANOACAO` BIGINT NOT NULL,
  `RESULTADO` VARCHAR(12) NOT NULL,
  `DATA_AVALIACAO` DATE NULL,
  `OBSERVACAO` VARCHAR(1000) NULL,
  `CODUSUARIO` BIGINT NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODHISTORICO`),
  KEY `ix_plano_eficacia_historico` (`MODULO`, `CODPLANOACAO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

-- @@
-- Alertas já dados: avaliador × ação × ciclo (CICLO = avaliações já feitas; reaberta e concluída de novo = novo aviso).
CREATE TABLE IF NOT EXISTS `plano_eficacia_avisos` (
  `CODAVISO` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `MODULO` VARCHAR(20) NOT NULL,
  `CODPLANOACAO` BIGINT NOT NULL,
  `CODUSUARIO` BIGINT NOT NULL,
  `CICLO` INT NOT NULL DEFAULT 0,
  `AVISADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODAVISO`),
  UNIQUE KEY `uk_plano_eficacia_avisos` (`MODULO`, `CODPLANOACAO`, `CODUSUARIO`, `CICLO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

-- @@
DROP PROCEDURE IF EXISTS `sp_eficacia_avisar`

-- @@
CREATE PROCEDURE `sp_eficacia_avisar`(IN p_simular TINYINT)
BEGIN
    -- Um alerta por avaliador e módulo com as ações a avaliar que ainda não foram avisadas (vw_eficacia_pendentes).
    DECLARE v_alertas INT DEFAULT 0;
    DECLARE v_itens INT DEFAULT 0;
    SET SESSION group_concat_max_len = 65535;
    DROP TEMPORARY TABLE IF EXISTS `tmp_eficacia_avisos`;
    CREATE TEMPORARY TABLE `tmp_eficacia_avisos` (
        `MODULO` VARCHAR(20) NOT NULL, `CODPLANOACAO` BIGINT NOT NULL, `CODUSUARIO` BIGINT NOT NULL, `CICLO` INT NOT NULL,
        `CODORIGEM` BIGINT NULL, `ORIGEM` VARCHAR(400) NULL, `ACAO` VARCHAR(1000) NULL,
        KEY `ix_tmp` (`CODUSUARIO`, `MODULO`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    INSERT INTO `tmp_eficacia_avisos` (`MODULO`, `CODPLANOACAO`, `CODUSUARIO`, `CICLO`, `CODORIGEM`, `ORIGEM`, `ACAO`)
    SELECT p.`MODULO`, p.`CODPLANOACAO`, p.`CODUSUARIO`, p.`AVALIACOES`, p.`CODORIGEM`, LEFT(p.`ORIGEM`, 400), LEFT(p.`ACAO`, 1000)
      FROM `vw_eficacia_pendentes` p
     WHERE NOT EXISTS (SELECT 1 FROM `plano_eficacia_avisos` a
                        WHERE a.`MODULO` = p.`MODULO` AND a.`CODPLANOACAO` = p.`CODPLANOACAO` AND a.`CODUSUARIO` = p.`CODUSUARIO` AND a.`CICLO` = p.`AVALIACOES`);
    SELECT COUNT(*) INTO v_itens FROM `tmp_eficacia_avisos`;
    IF COALESCE(p_simular, 0) = 1 THEN
        SELECT COUNT(*) INTO v_alertas FROM (SELECT DISTINCT `CODUSUARIO`, `MODULO` FROM `tmp_eficacia_avisos`) x;
    ELSE
        INSERT INTO `alertas` (`codusuario`, `titulo`, `mensagem`, `link`, `lido`)
        SELECT t.`CODUSUARIO`,
               LEFT(CONCAT(CASE t.`MODULO` WHEN 'CONDICIONANTE' THEN 'Condicionantes' ELSE 'Fornecedores' END,
                           ': avaliar a eficácia de ', COUNT(*), ' ação(ões) concluída(s)'), 180),
               LEFT(CONCAT(GROUP_CONCAT(CONCAT('• ', COALESCE(NULLIF(TRIM(t.`ACAO`), ''), CONCAT('Ação ', t.`CODPLANOACAO`)), ' (', COALESCE(t.`ORIGEM`, ''), ')')
                                        ORDER BY t.`ORIGEM`, t.`CODPLANOACAO` SEPARATOR '\n'),
                           '\n\nVocê é o avaliador destas ações. Abra o plano de ação e registre Eficaz ou Não eficaz (Não eficaz reabre a ação).'), 60000),
               '/principal/minhas_acoes.php',
               0
          FROM `tmp_eficacia_avisos` t
         GROUP BY t.`CODUSUARIO`, t.`MODULO`;
        SET v_alertas = ROW_COUNT();
        INSERT IGNORE INTO `plano_eficacia_avisos` (`MODULO`, `CODPLANOACAO`, `CODUSUARIO`, `CICLO`)
        SELECT `MODULO`, `CODPLANOACAO`, `CODUSUARIO`, `CICLO` FROM `tmp_eficacia_avisos`;
    END IF;
    DROP TEMPORARY TABLE IF EXISTS `tmp_eficacia_avisos`;
    SELECT v_alertas AS `ALERTAS`, v_itens AS `ITENS`;
END
