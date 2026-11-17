-- Migração 344 — Gestão de taxas das licenças (Onda 1 da apresentação Atlas Schindler 2026).
-- Aplicada por scripts/aplicar-migracao-344-licencas-taxas.php em cada banco de empresa com licenças. Pode ser executada mais de uma vez.
--
-- Decisões do usuário (23/10/2026):
--   * várias taxas por licença: tipo, descrição, número da guia, valor, vencimento, frequência, pagamento (data e valor) e comprovante;
--   * taxa recorrente: ao registrar o pagamento, o sistema cria a próxima (vencimento + frequência, mesmo valor a confirmar);
--   * aviso: agenda e pendências do responsável da licença, alerta 30 dias antes e no dia do vencimento;
--   * taxa vencida e não paga deixa a licença em Atenção na situação legal calculada (migração 284).
-- A taxa fica na licença original (CODLICENCA_BASE): vale para todas as versões, como os arquivos e o risco do item.
-- As views que dependem das colunas de cada banco (grid, responsáveis, pendências, avisos, dashboard) são montadas pelo script.
-- @@
CREATE TABLE IF NOT EXISTS `li_taxas` (
    `CODTAXA` INT NOT NULL AUTO_INCREMENT,
    `CODLICENCA` INT NOT NULL COMMENT 'Licença original (CODLICENCA_BASE): vale para todas as versões',
    `TIPO` VARCHAR(20) NOT NULL DEFAULT 'OUTRA' COMMENT 'EMISSAO, RENOVACAO, ANALISE, FISCALIZACAO, PUBLICACAO, OUTRA',
    `DESCRICAO` VARCHAR(500) NULL,
    `NUMERO_GUIA` VARCHAR(100) NULL COMMENT 'Número da guia, boleto ou DAE',
    `VALOR` DECIMAL(14,2) NULL,
    `VENCIMENTO` DATE NULL,
    `FREQUENCIA` VARCHAR(20) NOT NULL DEFAULT 'UNICA' COMMENT 'UNICA, MENSAL, TRIMESTRAL, SEMESTRAL, ANUAL, BIENAL, TRIENAL, QUADRIENAL, QUINQUENAL',
    `PAGO_EM` DATE NULL,
    `VALOR_PAGO` DECIMAL(14,2) NULL,
    `ORIGEM` VARCHAR(12) NOT NULL DEFAULT 'MANUAL' COMMENT 'MANUAL ou AUTOMATICA (próxima de uma recorrente)',
    `CODTAXA_ANTERIOR` INT NULL COMMENT 'Taxa paga que gerou esta (recorrência)',
    `PROXIMA_GERADA` TINYINT NOT NULL DEFAULT 0 COMMENT '1 = a próxima já foi criada (se for excluída, não volta)',
    `OBSERVACAO` TEXT NULL,
    `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`CODTAXA`),
    KEY `ix_li_taxas_licenca` (`CODLICENCA`),
    KEY `ix_li_taxas_vencimento` (`PAGO_EM`, `VENCIMENTO`),
    UNIQUE KEY `ux_li_taxas_anterior` (`CODTAXA_ANTERIOR`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
DROP TRIGGER IF EXISTS `trg_li_taxas_bi`
-- @@
CREATE TRIGGER `trg_li_taxas_bi` BEFORE INSERT ON `li_taxas` FOR EACH ROW
BEGIN
    -- Tipo e frequência fora da lista viram OUTRA / UNICA; valor pago sem valor informado assume o valor da taxa
    IF NEW.`TIPO` IS NULL OR NEW.`TIPO` NOT IN ('EMISSAO', 'RENOVACAO', 'ANALISE', 'FISCALIZACAO', 'PUBLICACAO', 'OUTRA') THEN SET NEW.`TIPO` = 'OUTRA'; END IF;
    IF NEW.`FREQUENCIA` IS NULL OR NEW.`FREQUENCIA` NOT IN ('UNICA', 'MENSAL', 'TRIMESTRAL', 'SEMESTRAL', 'ANUAL', 'BIENAL', 'TRIENAL', 'QUADRIENAL', 'QUINQUENAL') THEN SET NEW.`FREQUENCIA` = 'UNICA'; END IF;
    IF NEW.`PAGO_EM` IS NOT NULL AND NEW.`VALOR_PAGO` IS NULL THEN SET NEW.`VALOR_PAGO` = NEW.`VALOR`; END IF;
    IF NEW.`ORIGEM` IS NULL OR NEW.`ORIGEM` NOT IN ('MANUAL', 'AUTOMATICA') THEN SET NEW.`ORIGEM` = 'MANUAL'; END IF;
END
-- @@
DROP TRIGGER IF EXISTS `trg_li_taxas_bu`
-- @@
CREATE TRIGGER `trg_li_taxas_bu` BEFORE UPDATE ON `li_taxas` FOR EACH ROW
BEGIN
    IF NEW.`TIPO` IS NULL OR NEW.`TIPO` NOT IN ('EMISSAO', 'RENOVACAO', 'ANALISE', 'FISCALIZACAO', 'PUBLICACAO', 'OUTRA') THEN SET NEW.`TIPO` = 'OUTRA'; END IF;
    IF NEW.`FREQUENCIA` IS NULL OR NEW.`FREQUENCIA` NOT IN ('UNICA', 'MENSAL', 'TRIMESTRAL', 'SEMESTRAL', 'ANUAL', 'BIENAL', 'TRIENAL', 'QUADRIENAL', 'QUINQUENAL') THEN SET NEW.`FREQUENCIA` = 'UNICA'; END IF;
    IF NEW.`PAGO_EM` IS NOT NULL AND NEW.`VALOR_PAGO` IS NULL THEN SET NEW.`VALOR_PAGO` = NEW.`VALOR`; END IF;
    -- Pagamento desfeito: o valor pago sai junto
    IF NEW.`PAGO_EM` IS NULL AND OLD.`PAGO_EM` IS NOT NULL THEN SET NEW.`VALOR_PAGO` = NULL; END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_li_taxas_gerar_proximas`
-- @@
CREATE PROCEDURE `sp_li_taxas_gerar_proximas`()
BEGIN
    -- Taxas recorrentes pagas: cria a próxima (vencimento + frequência; sem vencimento, a partir do pagamento), com o mesmo tipo,
    -- descrição e valor (a confirmar). Cada taxa gera uma só vez: se a próxima for excluída, não volta.
    DECLARE v_geradas INT DEFAULT 0;
    INSERT INTO `li_taxas` (`CODLICENCA`, `TIPO`, `DESCRICAO`, `VALOR`, `VENCIMENTO`, `FREQUENCIA`, `ORIGEM`, `CODTAXA_ANTERIOR`, `OBSERVACAO`)
    SELECT t.`CODLICENCA`, t.`TIPO`, t.`DESCRICAO`, t.`VALOR`,
           CASE t.`FREQUENCIA`
               WHEN 'MENSAL' THEN DATE_ADD(COALESCE(t.`VENCIMENTO`, t.`PAGO_EM`), INTERVAL 1 MONTH)
               WHEN 'TRIMESTRAL' THEN DATE_ADD(COALESCE(t.`VENCIMENTO`, t.`PAGO_EM`), INTERVAL 3 MONTH)
               WHEN 'SEMESTRAL' THEN DATE_ADD(COALESCE(t.`VENCIMENTO`, t.`PAGO_EM`), INTERVAL 6 MONTH)
               WHEN 'ANUAL' THEN DATE_ADD(COALESCE(t.`VENCIMENTO`, t.`PAGO_EM`), INTERVAL 1 YEAR)
               WHEN 'BIENAL' THEN DATE_ADD(COALESCE(t.`VENCIMENTO`, t.`PAGO_EM`), INTERVAL 2 YEAR)
               WHEN 'TRIENAL' THEN DATE_ADD(COALESCE(t.`VENCIMENTO`, t.`PAGO_EM`), INTERVAL 3 YEAR)
               WHEN 'QUADRIENAL' THEN DATE_ADD(COALESCE(t.`VENCIMENTO`, t.`PAGO_EM`), INTERVAL 4 YEAR)
               ELSE DATE_ADD(COALESCE(t.`VENCIMENTO`, t.`PAGO_EM`), INTERVAL 5 YEAR) END,
           t.`FREQUENCIA`, 'AUTOMATICA', t.`CODTAXA`,
           CONCAT('Criada automaticamente ao registrar o pagamento da taxa nº ', t.`CODTAXA`, ': confira o valor e o vencimento.')
      FROM `li_taxas` t
      LEFT JOIN `li_taxas` n ON n.`CODTAXA_ANTERIOR` = t.`CODTAXA`
     WHERE t.`PAGO_EM` IS NOT NULL AND t.`FREQUENCIA` <> 'UNICA' AND t.`PROXIMA_GERADA` = 0 AND n.`CODTAXA` IS NULL;
    SET v_geradas = ROW_COUNT();
    UPDATE `li_taxas` t JOIN `li_taxas` n ON n.`CODTAXA_ANTERIOR` = t.`CODTAXA` SET t.`PROXIMA_GERADA` = 1 WHERE t.`PROXIMA_GERADA` = 0;
    SELECT v_geradas AS `GERADAS`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_li_taxas_avisar`
-- @@
CREATE PROCEDURE `sp_li_taxas_avisar`(IN p_simular TINYINT)
BEGIN
    -- Alertas do vencimento das taxas não pagas aos responsáveis da licença (vw_li_taxas_avisos): 30 dias antes e no dia
    -- (até 3 dias depois, se o serviço estava parado). Um alerta por pessoa, taxa, aviso e vencimento (o link é a chave).
    -- Os alertas viram e-mail pelo serviço de e-mail (migração 343), conforme a escolha de cada usuário.
    IF COALESCE(p_simular, 0) = 1 THEN
        SELECT COUNT(*) AS `ALERTAS` FROM `vw_li_taxas_avisos` a
         WHERE NOT EXISTS (SELECT 1 FROM `alertas` x WHERE x.`codusuario` = a.`CODUSUARIO` AND x.`link` COLLATE utf8mb4_unicode_ci = a.`LINK` COLLATE utf8mb4_unicode_ci);
    ELSE
        INSERT INTO `alertas` (`codusuario`, `titulo`, `mensagem`, `link`, `lido`)
        SELECT a.`CODUSUARIO`, LEFT(a.`TITULO`, 180), a.`MENSAGEM`, LEFT(a.`LINK`, 500), 0
          FROM `vw_li_taxas_avisos` a
         WHERE NOT EXISTS (SELECT 1 FROM `alertas` x WHERE x.`codusuario` = a.`CODUSUARIO` AND x.`link` COLLATE utf8mb4_unicode_ci = a.`LINK` COLLATE utf8mb4_unicode_ci);
        SELECT ROW_COUNT() AS `ALERTAS`;
    END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_li_taxa_limpar`
-- @@
CREATE PROCEDURE `sp_li_taxa_limpar`(IN p_codtaxa INT, IN p_campo VARCHAR(30))
BEGIN
    -- Campo apagado na tela (o CRUD padrão gravaria '' em data e valor): só os campos opcionais da taxa
    CASE UPPER(p_campo)
        WHEN 'PAGO_EM' THEN UPDATE `li_taxas` SET `PAGO_EM` = NULL WHERE `CODTAXA` = p_codtaxa;
        WHEN 'VALOR' THEN UPDATE `li_taxas` SET `VALOR` = NULL WHERE `CODTAXA` = p_codtaxa;
        WHEN 'VALOR_PAGO' THEN UPDATE `li_taxas` SET `VALOR_PAGO` = NULL WHERE `CODTAXA` = p_codtaxa;
        WHEN 'NUMERO_GUIA' THEN UPDATE `li_taxas` SET `NUMERO_GUIA` = NULL WHERE `CODTAXA` = p_codtaxa;
        WHEN 'DESCRICAO' THEN UPDATE `li_taxas` SET `DESCRICAO` = NULL WHERE `CODTAXA` = p_codtaxa;
        WHEN 'OBSERVACAO' THEN UPDATE `li_taxas` SET `OBSERVACAO` = NULL WHERE `CODTAXA` = p_codtaxa;
        ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Campo da taxa não pode ser limpo';
    END CASE;
    SELECT ROW_COUNT() AS `ALTERADAS`;
END
