-- Migração 341 — Requisitos legais: nível de atendimento (%) e risco (23/10/2026; docs/Detalhamento Cálculo do Nível do
-- Atendimento do Requisito Legal.doc). Banco de cada empresa com a situação legal calculada (migração 284).
--   * Risco de cada item de gestão (licença, medição e monitoramento, treinamento, infraestrutura, controle e fornecedor):
--     Severidade (1 a 4, critério de cada pilar) × Probabilidade (1 a 4, critério comum) = pontuação;
--     1–3 Baixo, 4–6 Moderado, 8–9 Alto, 12–16 ou Severidade 4 = Crítico. Fica em ml_risco_itens (tabela só do greenportal:
--     o sincronismo do sistema anterior não a altera). Licenças: o risco vale para todas as versões da licença.
--   * "Não se aplica" por vínculo requisito × unidade × item (ml_requisito_item_na), com justificativa: o item sai do % e do risco.
--   * Cálculo (sp_situacao_legal_calcular_alvo, substitui a versão da 284): % de atendimento = itens aplicáveis atendidos (Ok ou
--     Atenção) ÷ itens aplicáveis; risco do requisito = pior risco entre os itens aplicáveis; pendência crítica = item aplicável
--     NÃO atendido (Falha ou Vencido) com risco Crítico; itens aplicáveis sem severidade/probabilidade = "risco não avaliado".
--     A situação S / PA / N / AI continua com as mesmas regras, agora sem os itens "não se aplica". O status de item CRITICO
--     (licença vencida sem protocolo) passa a ser mostrado como "Vencido", para não confundir com o risco Crítico.
-- Blocos separados por "-- @@" (scripts/aplicar-migracao-341-requisitos-risco.php); "-- requer: tabela" pula o bloco se faltar.
-- Pode ser executada mais de uma vez. Textos comparados com COLLATE explícito (as tabelas da 284 são utf8mb4_unicode_ci).
-- @@
CREATE TABLE IF NOT EXISTS `ml_risco_criterios` (
  `AREA` VARCHAR(20) NOT NULL COMMENT 'LICENCAS, MEDICAO, TREINAMENTOS, INFRAESTRUTURA, CONTROLES, HOMOLOGACAO (severidade) ou PROBABILIDADE',
  `NIVEL` TINYINT NOT NULL,
  `ROTULO` VARCHAR(40) NOT NULL,
  `CRITERIO` VARCHAR(600) NOT NULL,
  PRIMARY KEY (`AREA`, `NIVEL`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS `ml_risco_itens` (
  `AREA` VARCHAR(20) NOT NULL,
  `ITEM_ID` BIGINT NOT NULL COMMENT 'Código do item (licença: código da licença original, vale para todas as versões)',
  `SEVERIDADE` TINYINT NOT NULL,
  `PROBABILIDADE` TINYINT NOT NULL,
  `PONTOS` TINYINT NOT NULL,
  `CLASSE` VARCHAR(10) NOT NULL COMMENT 'BAIXO, MODERADO, ALTO, CRITICO',
  `JUSTIFICATIVA` VARCHAR(1000) NULL,
  `CODUSUARIO` BIGINT NULL,
  `AVALIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`AREA`, `ITEM_ID`),
  KEY `ix_ml_risco_itens_classe` (`CLASSE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS `ml_risco_itens_historico` (
  `CODHISTORICO` BIGINT NOT NULL AUTO_INCREMENT,
  `AREA` VARCHAR(20) NOT NULL,
  `ITEM_ID` BIGINT NOT NULL,
  `SEVERIDADE_ANTES` TINYINT NULL, `PROBABILIDADE_ANTES` TINYINT NULL, `CLASSE_ANTES` VARCHAR(10) NULL,
  `SEVERIDADE` TINYINT NOT NULL, `PROBABILIDADE` TINYINT NOT NULL, `CLASSE` VARCHAR(10) NOT NULL,
  `JUSTIFICATIVA` VARCHAR(1000) NULL,
  `CODUSUARIO` BIGINT NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODHISTORICO`),
  KEY `ix_ml_risco_hist_item` (`AREA`, `ITEM_ID`, `CRIADO_EM`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS `ml_requisito_item_na` (
  `CODLEGISLACAO` INT NOT NULL,
  `CODUNIDADE` INT NOT NULL,
  `AREA` VARCHAR(20) NOT NULL,
  `ITEM_ID` BIGINT NOT NULL COMMENT 'Mesmo código de ml_risco_itens (licença: a original)',
  `JUSTIFICATIVA` VARCHAR(1000) NOT NULL,
  `CODUSUARIO` BIGINT NULL,
  `MARCADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODLEGISLACAO`, `CODUNIDADE`, `AREA`, `ITEM_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS `ml_requisito_item_na_historico` (
  `CODHISTORICO` BIGINT NOT NULL AUTO_INCREMENT,
  `CODLEGISLACAO` INT NOT NULL, `CODUNIDADE` INT NOT NULL, `AREA` VARCHAR(20) NOT NULL, `ITEM_ID` BIGINT NOT NULL,
  `ACAO` VARCHAR(12) NOT NULL COMMENT 'MARCADO ou DESMARCADO',
  `JUSTIFICATIVA` VARCHAR(1000) NULL,
  `CODUSUARIO` BIGINT NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODHISTORICO`),
  KEY `ix_ml_na_hist` (`CODLEGISLACAO`, `CODUNIDADE`, `CRIADO_EM`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE OR REPLACE VIEW `vw_ml_risco_criterios` AS
SELECT `AREA`, `NIVEL`, `ROTULO`, `CRITERIO` FROM `ml_risco_criterios`
-- @@
CREATE OR REPLACE VIEW `vw_ml_risco_itens` AS
SELECT r.`AREA`, r.`ITEM_ID`, r.`SEVERIDADE`, r.`PROBABILIDADE`, r.`PONTOS`, r.`CLASSE`,
       CONVERT(CASE r.`CLASSE` WHEN 'CRITICO' THEN 'Crítico' WHEN 'ALTO' THEN 'Alto' WHEN 'MODERADO' THEN 'Moderado' ELSE 'Baixo' END USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `CLASSE_TEXTO`,
       r.`JUSTIFICATIVA`, r.`CODUSUARIO`, r.`AVALIADO_EM`,
       CONVERT(COALESCE(NULLIF(TRIM(u.`nome`), ''), CONCAT('Usuário ', r.`CODUSUARIO`)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `NOME_USUARIO`
  FROM `ml_risco_itens` r LEFT JOIN `usuarios` u ON u.`codusuario` = r.`CODUSUARIO`
-- @@
CREATE OR REPLACE VIEW `vw_ml_requisito_item_na` AS
SELECT n.`CODLEGISLACAO`, n.`CODUNIDADE`, n.`AREA`, n.`ITEM_ID`, n.`JUSTIFICATIVA`, n.`CODUSUARIO`, n.`MARCADO_EM`,
       CONVERT(COALESCE(NULLIF(TRIM(u.`nome`), ''), CONCAT('Usuário ', n.`CODUSUARIO`)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `NOME_USUARIO`
  FROM `ml_requisito_item_na` n LEFT JOIN `usuarios` u ON u.`codusuario` = n.`CODUSUARIO`
-- @@
DROP PROCEDURE IF EXISTS `sp_ml_risco_salvar`
-- @@
CREATE PROCEDURE `sp_ml_risco_salvar`(IN p_area VARCHAR(20), IN p_item BIGINT, IN p_severidade TINYINT, IN p_probabilidade TINYINT,
  IN p_justificativa VARCHAR(1000), IN p_usuario BIGINT)
BEGIN
  -- Grava (ou altera) o risco do item e põe na fila de recálculo os requisitos ligados a ele. Licença: grava na licença original.
  DECLARE v_area VARCHAR(20) DEFAULT UPPER(TRIM(COALESCE(p_area, '')));
  DECLARE v_chave BIGINT DEFAULT p_item;
  DECLARE v_pontos TINYINT;
  DECLARE v_classe VARCHAR(10);
  DECLARE v_sev_a TINYINT DEFAULT NULL; DECLARE v_prob_a TINYINT DEFAULT NULL; DECLARE v_classe_a VARCHAR(10) DEFAULT NULL;
  IF v_area NOT IN ('LICENCAS', 'MEDICAO', 'TREINAMENTOS', 'INFRAESTRUTURA', 'CONTROLES', 'HOMOLOGACAO') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Área do item inválida.';
  END IF;
  IF p_item IS NULL OR p_item <= 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item não informado.'; END IF;
  IF p_severidade IS NULL OR p_severidade NOT BETWEEN 1 AND 4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe a severidade (1 a 4).'; END IF;
  IF p_probabilidade IS NULL OR p_probabilidade NOT BETWEEN 1 AND 4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe a probabilidade (1 a 4).'; END IF;
  IF v_area = 'LICENCAS' THEN SELECT COALESCE(MAX(`CHAVE`), p_item) INTO v_chave FROM `vw_ml_licenca_chave` WHERE `CODLICENCA` = p_item; END IF;
  SET v_pontos = p_severidade * p_probabilidade;
  SET v_classe = CASE WHEN p_severidade = 4 OR v_pontos >= 12 THEN 'CRITICO' WHEN v_pontos >= 8 THEN 'ALTO' WHEN v_pontos >= 4 THEN 'MODERADO' ELSE 'BAIXO' END;
  SELECT `SEVERIDADE`, `PROBABILIDADE`, `CLASSE` INTO v_sev_a, v_prob_a, v_classe_a FROM `ml_risco_itens`
   WHERE `AREA` = v_area COLLATE utf8mb4_unicode_ci AND `ITEM_ID` = v_chave;
  IF v_sev_a IS NULL OR v_sev_a <> p_severidade OR v_prob_a <> p_probabilidade THEN
    INSERT INTO `ml_risco_itens` (`AREA`, `ITEM_ID`, `SEVERIDADE`, `PROBABILIDADE`, `PONTOS`, `CLASSE`, `JUSTIFICATIVA`, `CODUSUARIO`, `AVALIADO_EM`)
    VALUES (v_area, v_chave, p_severidade, p_probabilidade, v_pontos, v_classe, NULLIF(TRIM(COALESCE(p_justificativa, '')), ''), NULLIF(p_usuario, 0), NOW())
    ON DUPLICATE KEY UPDATE `SEVERIDADE` = VALUES(`SEVERIDADE`), `PROBABILIDADE` = VALUES(`PROBABILIDADE`), `PONTOS` = VALUES(`PONTOS`), `CLASSE` = VALUES(`CLASSE`),
      `JUSTIFICATIVA` = VALUES(`JUSTIFICATIVA`), `CODUSUARIO` = VALUES(`CODUSUARIO`), `AVALIADO_EM` = NOW();
    INSERT INTO `ml_risco_itens_historico` (`AREA`, `ITEM_ID`, `SEVERIDADE_ANTES`, `PROBABILIDADE_ANTES`, `CLASSE_ANTES`, `SEVERIDADE`, `PROBABILIDADE`, `CLASSE`, `JUSTIFICATIVA`, `CODUSUARIO`)
    VALUES (v_area, v_chave, v_sev_a, v_prob_a, v_classe_a, p_severidade, p_probabilidade, v_classe, NULLIF(TRIM(COALESCE(p_justificativa, '')), ''), NULLIF(p_usuario, 0));
    -- Requisitos ligados ao item: fila de recálculo (serviço de responsabilidades, a cada 5 minutos; a tela de situação recalcula na hora)
    SET @ml_risco_chave = v_chave;
    SET @ml_risco_sql = CONCAT('INSERT INTO `situacao_legal_fila` (`CODLEGISLACAO`, `CODUNIDADE`, `enfileirado_em`) SELECT DISTINCT e.`CODLEGISLACAO`, e.`CODUNIDADE`, NOW(6) FROM `vw_sl_ev_',
      LOWER(v_area), '` e', IF(v_area = 'LICENCAS', ' JOIN `vw_ml_licenca_chave` k ON k.`CODLICENCA` = e.`ITEM_ID` WHERE k.`CHAVE` = ?', ' WHERE e.`ITEM_ID` = ?'),
      ' AND e.`CODLEGISLACAO` IS NOT NULL ON DUPLICATE KEY UPDATE `enfileirado_em` = NOW(6)');
    PREPARE ml_risco_fila FROM @ml_risco_sql;
    EXECUTE ml_risco_fila USING @ml_risco_chave;
    DEALLOCATE PREPARE ml_risco_fila;
  END IF;
  SELECT v_area AS `AREA`, v_chave AS `ITEM_ID`, p_severidade AS `SEVERIDADE`, p_probabilidade AS `PROBABILIDADE`, v_pontos AS `PONTOS`, v_classe AS `CLASSE`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ml_requisito_na`
-- @@
CREATE PROCEDURE `sp_ml_requisito_na`(IN p_codlegislacao INT, IN p_codunidade INT, IN p_area VARCHAR(20), IN p_item BIGINT,
  IN p_na TINYINT, IN p_justificativa VARCHAR(1000), IN p_usuario BIGINT)
BEGIN
  -- Marca (p_na = 1, justificativa de pelo menos 10 caracteres) ou desmarca (p_na = 0) "Não se aplica" para o item neste requisito e
  -- unidade, e recalcula o requisito na hora.
  DECLARE v_area VARCHAR(20) DEFAULT UPPER(TRIM(COALESCE(p_area, '')));
  DECLARE v_chave BIGINT DEFAULT p_item;
  IF v_area NOT IN ('LICENCAS', 'MEDICAO', 'TREINAMENTOS', 'INFRAESTRUTURA', 'CONTROLES', 'HOMOLOGACAO') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Área do item inválida.';
  END IF;
  IF p_codlegislacao IS NULL OR p_codlegislacao <= 0 OR p_item IS NULL OR p_item <= 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Requisito ou item não informado.'; END IF;
  IF v_area = 'LICENCAS' THEN SELECT COALESCE(MAX(`CHAVE`), p_item) INTO v_chave FROM `vw_ml_licenca_chave` WHERE `CODLICENCA` = p_item; END IF;
  IF p_na = 1 THEN
    IF CHAR_LENGTH(TRIM(COALESCE(p_justificativa, ''))) < 10 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe por que o item não se aplica a este requisito (pelo menos 10 caracteres).';
    END IF;
    INSERT INTO `ml_requisito_item_na` (`CODLEGISLACAO`, `CODUNIDADE`, `AREA`, `ITEM_ID`, `JUSTIFICATIVA`, `CODUSUARIO`, `MARCADO_EM`)
    VALUES (p_codlegislacao, COALESCE(p_codunidade, 0), v_area, v_chave, TRIM(p_justificativa), NULLIF(p_usuario, 0), NOW())
    ON DUPLICATE KEY UPDATE `JUSTIFICATIVA` = VALUES(`JUSTIFICATIVA`), `CODUSUARIO` = VALUES(`CODUSUARIO`), `MARCADO_EM` = NOW();
    INSERT INTO `ml_requisito_item_na_historico` (`CODLEGISLACAO`, `CODUNIDADE`, `AREA`, `ITEM_ID`, `ACAO`, `JUSTIFICATIVA`, `CODUSUARIO`)
    VALUES (p_codlegislacao, COALESCE(p_codunidade, 0), v_area, v_chave, 'MARCADO', TRIM(p_justificativa), NULLIF(p_usuario, 0));
  ELSE
    DELETE FROM `ml_requisito_item_na`
     WHERE `CODLEGISLACAO` = p_codlegislacao AND `CODUNIDADE` = COALESCE(p_codunidade, 0) AND `AREA` = v_area COLLATE utf8mb4_unicode_ci AND `ITEM_ID` = v_chave;
    IF ROW_COUNT() > 0 THEN
      INSERT INTO `ml_requisito_item_na_historico` (`CODLEGISLACAO`, `CODUNIDADE`, `AREA`, `ITEM_ID`, `ACAO`, `JUSTIFICATIVA`, `CODUSUARIO`)
      VALUES (p_codlegislacao, COALESCE(p_codunidade, 0), v_area, v_chave, 'DESMARCADO', NULLIF(TRIM(COALESCE(p_justificativa, '')), ''), NULLIF(p_usuario, 0));
    END IF;
  END IF;
  CALL `sp_situacao_legal_recalcular`(p_codlegislacao, p_codunidade);
END
-- @@
DROP PROCEDURE IF EXISTS `sp_situacao_legal_calcular_alvo`
-- @@
CREATE PROCEDURE `sp_situacao_legal_calcular_alvo`(IN p_tipo VARCHAR(10), OUT p_requisitos INT, OUT p_alterados INT)
BEGIN
    -- Uso interno (migração 341, substitui a versão da 284): calcula os pares (requisito, unidade) de tmp_sl_alvo — situação,
    -- % de atendimento, risco, pendências críticas e itens sem risco —, grava o histórico das mudanças de situação e o resultado.
    -- p_tipo = COMPLETO também remove as situações de requisitos que deixaram de ser aplicáveis (legislacaoxempresa).
    DECLARE v_inicio DATETIME DEFAULT NOW();
    SET SESSION group_concat_max_len = 1048576;

    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_evb`;
    CREATE TEMPORARY TABLE `tmp_sl_evb` ENGINE=InnoDB AS
    SELECT e.`CODLEGISLACAO`, COALESCE(e.`CODUNIDADE`, 0) AS `CODUNIDADE`, e.`AREA`, e.`ITEM_ID`, e.`ITEM`, e.`STATUS`, e.`MOTIVO`
      FROM `vw_sl_evidencias` e
     WHERE e.`CODLEGISLACAO` IN (SELECT `CODLEGISLACAO` FROM `tmp_sl_alvo`);
    ALTER TABLE `tmp_sl_evb` ADD INDEX `ix_tmp_sl_evb` (`CODLEGISLACAO`, `CODUNIDADE`);

    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_ev`;
    CREATE TEMPORARY TABLE `tmp_sl_ev` (
        `CODLEGISLACAO` INT NOT NULL, `CODUNIDADE` INT NOT NULL, `AREA` VARCHAR(30) NOT NULL, `ITEM_ID` BIGINT NOT NULL,
        `ITEM` VARCHAR(300) NULL, `STATUS` VARCHAR(10) NOT NULL, `MOTIVO` VARCHAR(500) NULL,
        `CHAVE` BIGINT NOT NULL DEFAULT 0, `NA` TINYINT NOT NULL DEFAULT 0, `NA_JUSTIFICATIVA` VARCHAR(1000) NULL,
        `SEVERIDADE` TINYINT NULL, `PROBABILIDADE` TINYINT NULL, `PONTOS` TINYINT NULL, `CLASSE` VARCHAR(10) NULL,
        PRIMARY KEY (`CODLEGISLACAO`, `CODUNIDADE`, `AREA`, `ITEM_ID`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    INSERT IGNORE INTO `tmp_sl_ev` (`CODLEGISLACAO`, `CODUNIDADE`, `AREA`, `ITEM_ID`, `ITEM`, `STATUS`, `MOTIVO`, `CHAVE`)
    SELECT a.`CODLEGISLACAO`, a.`CODUNIDADE`, e.`AREA`, COALESCE(e.`ITEM_ID`, 0), e.`ITEM`, e.`STATUS`, e.`MOTIVO`, COALESCE(e.`ITEM_ID`, 0)
      FROM `tmp_sl_alvo` a JOIN `tmp_sl_evb` e ON e.`CODLEGISLACAO` = a.`CODLEGISLACAO` AND e.`CODUNIDADE` = a.`CODUNIDADE`;
    INSERT IGNORE INTO `tmp_sl_ev` (`CODLEGISLACAO`, `CODUNIDADE`, `AREA`, `ITEM_ID`, `ITEM`, `STATUS`, `MOTIVO`, `CHAVE`)
    SELECT a.`CODLEGISLACAO`, a.`CODUNIDADE`, e.`AREA`, COALESCE(e.`ITEM_ID`, 0), e.`ITEM`, e.`STATUS`, e.`MOTIVO`, COALESCE(e.`ITEM_ID`, 0)
      FROM `tmp_sl_alvo` a JOIN `tmp_sl_evb` e ON e.`CODLEGISLACAO` = a.`CODLEGISLACAO` AND e.`CODUNIDADE` = 0
     WHERE a.`CODUNIDADE` <> 0;
    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_evb`;

    -- Licença: o risco e o "não se aplica" ficam na licença original (valem para todas as versões)
    UPDATE `tmp_sl_ev` t JOIN `vw_ml_licenca_chave` k ON k.`CODLICENCA` = t.`ITEM_ID`
       SET t.`CHAVE` = k.`CHAVE` WHERE t.`AREA` = 'LICENCAS';
    UPDATE `tmp_sl_ev` t JOIN `ml_requisito_item_na` n
        ON n.`CODLEGISLACAO` = t.`CODLEGISLACAO` AND n.`CODUNIDADE` = t.`CODUNIDADE` AND n.`AREA` = t.`AREA` COLLATE utf8mb4_unicode_ci AND n.`ITEM_ID` = t.`CHAVE`
       SET t.`NA` = 1, t.`NA_JUSTIFICATIVA` = n.`JUSTIFICATIVA`;
    UPDATE `tmp_sl_ev` t JOIN `ml_risco_itens` r ON r.`AREA` = t.`AREA` COLLATE utf8mb4_unicode_ci AND r.`ITEM_ID` = t.`CHAVE`
       SET t.`SEVERIDADE` = r.`SEVERIDADE`, t.`PROBABILIDADE` = r.`PROBABILIDADE`, t.`PONTOS` = r.`PONTOS`, t.`CLASSE` = r.`CLASSE`;

    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_agg`;
    CREATE TEMPORARY TABLE `tmp_sl_agg` ENGINE=InnoDB AS
    SELECT `CODLEGISLACAO`, `CODUNIDADE`, COUNT(*) AS `total`, SUM(`NA`) AS `na`,
           SUM(`NA` = 0 AND `STATUS` = 'OK') AS `ok`, SUM(`NA` = 0 AND `STATUS` = 'ATENCAO') AS `atencao`,
           SUM(`NA` = 0 AND `STATUS` = 'FALHA') AS `falha`, SUM(`NA` = 0 AND `STATUS` = 'CRITICO') AS `critico`,
           MAX(IF(`NA` = 0, FIELD(`CLASSE`, 'BAIXO', 'MODERADO', 'ALTO', 'CRITICO'), 0)) AS `nivel_risco`,
           MAX(IF(`NA` = 0 AND `CLASSE` IS NOT NULL, FIELD(`CLASSE`, 'BAIXO', 'MODERADO', 'ALTO', 'CRITICO') * 100 + `PONTOS`, NULL)) MOD 100 AS `pontos`,
           SUM(`NA` = 0 AND `CLASSE` IS NULL) AS `sem_risco`,
           SUM(`NA` = 0 AND `STATUS` IN ('FALHA', 'CRITICO') AND `CLASSE` = 'CRITICO') AS `pend_criticas`,
           JSON_ARRAYAGG(JSON_OBJECT('area', `AREA`, 'item_id', `ITEM_ID`, 'chave', `CHAVE`, 'item', `ITEM`, 'status', `STATUS`, 'motivo', `MOTIVO`,
               'na', `NA`, 'na_justificativa', `NA_JUSTIFICATIVA`, 'severidade', `SEVERIDADE`, 'probabilidade', `PROBABILIDADE`, 'pontos', `PONTOS`, 'risco', `CLASSE`)) AS `detalhes`,
           GROUP_CONCAT(CASE WHEN `NA` = 0 AND `STATUS` <> 'OK' THEN CONCAT(
                   CASE `AREA` WHEN 'LICENCAS' THEN 'Licença' WHEN 'MEDICAO' THEN 'Medição' WHEN 'TREINAMENTOS' THEN 'Treinamento'
                        WHEN 'INFRAESTRUTURA' THEN 'Infraestrutura' WHEN 'CONTROLES' THEN 'Controle' WHEN 'HOMOLOGACAO' THEN 'Fornecedor'
                        ELSE `AREA` END, ' ', `ITEM`, ': ', `MOTIVO`,
                   IF(`CLASSE` = 'CRITICO' AND `STATUS` IN ('FALHA', 'CRITICO'), ' [pendência crítica]', '')) END
               ORDER BY FIELD(`STATUS`, 'CRITICO', 'FALHA', 'ATENCAO'), `AREA`, `ITEM` SEPARATOR '; ') AS `problemas`
      FROM `tmp_sl_ev`
     GROUP BY `CODLEGISLACAO`, `CODUNIDADE`;
    ALTER TABLE `tmp_sl_agg` ADD PRIMARY KEY (`CODLEGISLACAO`, `CODUNIDADE`);

    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_res`;
    CREATE TEMPORARY TABLE `tmp_sl_res` ENGINE=InnoDB AS
    SELECT a.`CODLEGISLACAO`, a.`CODUNIDADE`,
           CAST(CASE WHEN g.`total` IS NULL THEN 'AI'
                     WHEN g.`total` - g.`na` = 0 THEN 'AI'
                     WHEN g.`critico` > 0 THEN 'N'
                     WHEN g.`falha` = 0 THEN 'S'
                     WHEN g.`ok` + g.`atencao` = 0 THEN 'N'
                     ELSE 'PA' END AS CHAR(2) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS `situacao`,
           COALESCE(g.`total`, 0) AS `itens_total`, COALESCE(g.`ok`, 0) AS `itens_ok`, COALESCE(g.`atencao`, 0) AS `itens_atencao`,
           COALESCE(g.`falha`, 0) AS `itens_falha`, COALESCE(g.`critico`, 0) AS `itens_criticos`,
           COALESCE(g.`na`, 0) AS `itens_na`, COALESCE(g.`total` - g.`na`, 0) AS `itens_aplicaveis`, COALESCE(g.`ok` + g.`atencao`, 0) AS `itens_atendidos`,
           CASE WHEN COALESCE(g.`total` - g.`na`, 0) > 0 THEN ROUND((g.`ok` + g.`atencao`) * 100 / (g.`total` - g.`na`), 1) END AS `pct_atendimento`,
           CAST(CASE WHEN COALESCE(g.`total` - g.`na`, 0) = 0 THEN NULL WHEN g.`nivel_risco` = 0 THEN 'NAO_AVALIADO'
                     ELSE ELT(g.`nivel_risco`, 'BAIXO', 'MODERADO', 'ALTO', 'CRITICO') END AS CHAR(12) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS `risco`,
           g.`pontos` AS `risco_pontos`, COALESCE(g.`pend_criticas`, 0) AS `pendencias_criticas`, COALESCE(g.`sem_risco`, 0) AS `itens_sem_risco`,
           COALESCE(g.`detalhes`, JSON_ARRAY()) AS `detalhes`,
           LEFT(CASE WHEN g.`total` IS NULL
                     THEN 'Nenhum item de gestão vinculado ao requisito (licenças, medições, treinamentos, infraestrutura, controles ou fornecedores).'
                     WHEN g.`total` - g.`na` = 0
                     THEN CONCAT(g.`total`, IF(g.`total` = 1, ' item vinculado, marcado', ' itens vinculados, todos marcados'), ' como "não se aplica".')
                     ELSE CONCAT('Atendimento ', REPLACE(TRIM(TRAILING '.0' FROM CAST(ROUND((g.`ok` + g.`atencao`) * 100 / (g.`total` - g.`na`), 1) AS CHAR)), '.', ','), '% (',
                                 g.`ok` + g.`atencao`, ' de ', g.`total` - g.`na`, IF(g.`total` - g.`na` = 1, ' item aplicável', ' itens aplicáveis'),
                                 IF(g.`na` > 0, CONCAT('; ', g.`na`, ' não se aplica'), ''), '). ',
                                 'Risco: ', CASE WHEN g.`nivel_risco` = 0 THEN 'não avaliado' ELSE ELT(g.`nivel_risco`, 'Baixo', 'Moderado', 'Alto', 'Crítico') END,
                                 IF(g.`sem_risco` > 0 AND g.`nivel_risco` > 0, CONCAT(' (', g.`sem_risco`, ' sem risco avaliado)'), ''),
                                 IF(g.`pend_criticas` > 0, CONCAT('; ', g.`pend_criticas`, IF(g.`pend_criticas` = 1, ' pendência crítica', ' pendências críticas')), ''), '. ',
                                 CONCAT_WS(', ', IF(g.`ok` > 0, CONCAT(g.`ok`, ' ok'), NULL),
                                                 IF(g.`atencao` > 0, CONCAT(g.`atencao`, ' com atenção'), NULL),
                                                 IF(g.`falha` > 0, CONCAT(g.`falha`, ' com falha'), NULL),
                                                 IF(g.`critico` > 0, CONCAT(g.`critico`, IF(g.`critico` = 1, ' vencido', ' vencidos')), NULL)),
                                 IF(g.`problemas` IS NULL, '.', CONCAT('. ', g.`problemas`))) END, 1000) AS `resumo`
      FROM `tmp_sl_alvo` a
      LEFT JOIN `tmp_sl_agg` g ON g.`CODLEGISLACAO` = a.`CODLEGISLACAO` AND g.`CODUNIDADE` = a.`CODUNIDADE`;

    INSERT INTO `situacao_legal_historico` (`CODLEGISLACAO`, `CODUNIDADE`, `situacao_anterior`, `situacao_nova`, `resumo`, `registrado_em`)
    SELECT r.`CODLEGISLACAO`, r.`CODUNIDADE`, c.`situacao`, r.`situacao`, r.`resumo`, v_inicio
      FROM `tmp_sl_res` r
      LEFT JOIN `situacao_legal_calculada` c ON c.`CODLEGISLACAO` = r.`CODLEGISLACAO` AND c.`CODUNIDADE` = r.`CODUNIDADE`
     WHERE c.`situacao` IS NULL OR c.`situacao` COLLATE utf8mb4_unicode_ci <> r.`situacao` COLLATE utf8mb4_unicode_ci;
    SET p_alterados = ROW_COUNT();

    INSERT INTO `situacao_legal_calculada`
        (`CODLEGISLACAO`, `CODUNIDADE`, `situacao`, `itens_total`, `itens_ok`, `itens_atencao`, `itens_falha`, `itens_criticos`,
         `itens_na`, `itens_aplicaveis`, `itens_atendidos`, `pct_atendimento`, `risco`, `risco_pontos`, `pendencias_criticas`, `itens_sem_risco`,
         `resumo`, `detalhes`, `calculado_em`, `alterado_em`)
    SELECT n.`CODLEGISLACAO`, n.`CODUNIDADE`, n.`situacao`, n.`itens_total`, n.`itens_ok`, n.`itens_atencao`, n.`itens_falha`, n.`itens_criticos`,
           n.`itens_na`, n.`itens_aplicaveis`, n.`itens_atendidos`, n.`pct_atendimento`, n.`risco`, n.`risco_pontos`, n.`pendencias_criticas`, n.`itens_sem_risco`,
           n.`resumo`, n.`detalhes`, v_inicio, v_inicio
      FROM `tmp_sl_res` n
    ON DUPLICATE KEY UPDATE
        `alterado_em` = IF(`situacao_legal_calculada`.`situacao` COLLATE utf8mb4_unicode_ci = n.`situacao` COLLATE utf8mb4_unicode_ci,
                           `situacao_legal_calculada`.`alterado_em`, v_inicio),
        `situacao` = n.`situacao`, `itens_total` = n.`itens_total`, `itens_ok` = n.`itens_ok`, `itens_atencao` = n.`itens_atencao`,
        `itens_falha` = n.`itens_falha`, `itens_criticos` = n.`itens_criticos`, `itens_na` = n.`itens_na`, `itens_aplicaveis` = n.`itens_aplicaveis`,
        `itens_atendidos` = n.`itens_atendidos`, `pct_atendimento` = n.`pct_atendimento`, `risco` = n.`risco`, `risco_pontos` = n.`risco_pontos`,
        `pendencias_criticas` = n.`pendencias_criticas`, `itens_sem_risco` = n.`itens_sem_risco`, `resumo` = n.`resumo`, `detalhes` = n.`detalhes`,
        `calculado_em` = v_inicio;

    IF p_tipo = 'COMPLETO' THEN
        DELETE c FROM `situacao_legal_calculada` c
          LEFT JOIN `tmp_sl_alvo` a ON a.`CODLEGISLACAO` = c.`CODLEGISLACAO` AND a.`CODUNIDADE` = c.`CODUNIDADE`
         WHERE a.`CODLEGISLACAO` IS NULL;
    END IF;

    SELECT COUNT(*) INTO p_requisitos FROM `tmp_sl_res`;
    IF p_tipo IN ('COMPLETO', 'FILA') THEN
        INSERT INTO `situacao_legal_execucoes` (`tipo`, `requisitos`, `alterados`, `iniciado_em`, `concluido_em`)
        VALUES (p_tipo, p_requisitos, p_alterados, v_inicio, NOW());
    END IF;
    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_ev`;
    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_agg`;
    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_res`;
END
-- @@
CREATE OR REPLACE VIEW `vw_situacao_legal_itens` AS
SELECT c.`CODLEGISLACAO`, c.`CODUNIDADE`, j.`ORDEM`, j.`AREA`, j.`ITEM_ID`, j.`CHAVE`, j.`ITEM`, j.`STATUS`, j.`MOTIVO`,
       COALESCE(j.`NA`, 0) AS `NA`, NULLIF(j.`NA_JUSTIFICATIVA`, 'null') AS `NA_JUSTIFICATIVA`, j.`SEVERIDADE`, j.`PROBABILIDADE`, j.`PONTOS`,
       NULLIF(j.`RISCO`, 'null') AS `RISCO`
  FROM `situacao_legal_calculada` c,
       JSON_TABLE(c.`detalhes`, '$[*]' COLUMNS (
           `ORDEM` FOR ORDINALITY,
           `AREA` VARCHAR(30) PATH '$.area',
           `ITEM_ID` BIGINT PATH '$.item_id',
           `CHAVE` BIGINT PATH '$.chave',
           `ITEM` VARCHAR(300) PATH '$.item',
           `STATUS` VARCHAR(10) PATH '$.status',
           `MOTIVO` VARCHAR(500) PATH '$.motivo',
           `NA` TINYINT PATH '$.na',
           `NA_JUSTIFICATIVA` VARCHAR(1000) PATH '$.na_justificativa',
           `SEVERIDADE` TINYINT PATH '$.severidade',
           `PROBABILIDADE` TINYINT PATH '$.probabilidade',
           `PONTOS` TINYINT PATH '$.pontos',
           `RISCO` VARCHAR(10) PATH '$.risco')) j
-- @@
-- requer: legislacaoxsituacao
CREATE OR REPLACE VIEW `vw_situacao_legal_grid` AS
SELECT x.`CODLEGISLACAO`, x.`CODUNIDADE`, x.`SITUACAO_CALCULADA`, x.`SITUACAO_CALCULADA_TEXTO`, x.`MOTIVO`,
       x.`ITENS_TOTAL`, x.`ITENS_OK`, x.`ITENS_ATENCAO`, x.`ITENS_FALHA`, x.`ITENS_CRITICOS`, x.`CALCULADO_EM`, x.`ALTERADO_EM`,
       x.`ITENS_NA`, x.`ITENS_APLICAVEIS`, x.`ITENS_ATENDIDOS`, x.`PCT_ATENDIMENTO`, x.`RISCO`, x.`RISCO_TEXTO`, x.`RISCO_PONTOS`,
       x.`PENDENCIAS_CRITICAS`, x.`ITENS_SEM_RISCO`,
       x.`SITUACAO_MANUAL`, x.`ULTIMA_AVALIACAO`, x.`DECISAO`, x.`DECISAO_JUSTIFICATIVA`, x.`DECISAO_EM`, x.`DECISAO_USUARIO`,
       x.`DECISAO_VALIDA`,
       CASE WHEN x.`SITUACAO_CALCULADA` = 'AI' OR x.`SITUACAO_MANUAL` = 'AI' OR x.`SITUACAO_MANUAL` = x.`SITUACAO_CALCULADA`
                 OR x.`DECISAO_VALIDA` = 1 THEN 0 ELSE 1 END AS `DIVERGENTE`,
       CASE WHEN x.`SITUACAO_CALCULADA` = 'AI' AND x.`SITUACAO_MANUAL` <> 'AI' THEN x.`SITUACAO_MANUAL`
            WHEN x.`DECISAO_VALIDA` = 1 AND x.`DECISAO` = 'MANTIDA' THEN x.`SITUACAO_MANUAL`
            ELSE x.`SITUACAO_CALCULADA` END AS `SITUACAO_FINAL`,
       CASE WHEN x.`SITUACAO_CALCULADA` = 'AI' AND x.`SITUACAO_MANUAL` <> 'AI' THEN 'AVALIACAO'
            WHEN x.`DECISAO_VALIDA` = 1 AND x.`DECISAO` = 'MANTIDA' THEN 'AVALIACAO'
            ELSE 'CALCULADA' END AS `ORIGEM_FINAL`
  FROM (SELECT c.`CODLEGISLACAO`, c.`CODUNIDADE`,
               c.`situacao` COLLATE utf8mb4_unicode_ci AS `SITUACAO_CALCULADA`,
               CASE c.`situacao` WHEN 'S' THEN 'Atendido' WHEN 'PA' THEN 'Parcialmente atendido' WHEN 'N' THEN 'Não atendido'
                    ELSE 'Sem evidências vinculadas' END AS `SITUACAO_CALCULADA_TEXTO`,
               c.`resumo` AS `MOTIVO`, c.`itens_total` AS `ITENS_TOTAL`, c.`itens_ok` AS `ITENS_OK`, c.`itens_atencao` AS `ITENS_ATENCAO`,
               c.`itens_falha` AS `ITENS_FALHA`, c.`itens_criticos` AS `ITENS_CRITICOS`, c.`calculado_em` AS `CALCULADO_EM`,
               c.`alterado_em` AS `ALTERADO_EM`,
               c.`itens_na` AS `ITENS_NA`, c.`itens_aplicaveis` AS `ITENS_APLICAVEIS`, c.`itens_atendidos` AS `ITENS_ATENDIDOS`,
               c.`pct_atendimento` AS `PCT_ATENDIMENTO`, c.`risco` AS `RISCO`,
               CASE c.`risco` WHEN 'CRITICO' THEN 'Crítico' WHEN 'ALTO' THEN 'Alto' WHEN 'MODERADO' THEN 'Moderado' WHEN 'BAIXO' THEN 'Baixo'
                    WHEN 'NAO_AVALIADO' THEN 'Não avaliado' ELSE '' END AS `RISCO_TEXTO`,
               c.`risco_pontos` AS `RISCO_PONTOS`, c.`pendencias_criticas` AS `PENDENCIAS_CRITICAS`, c.`itens_sem_risco` AS `ITENS_SEM_RISCO`,
               COALESCE(m.`SITUACAO_MANUAL`, 'AI') COLLATE utf8mb4_unicode_ci AS `SITUACAO_MANUAL`,
               m.`ULTIMA_AVALIACAO`, c.`decisao` AS `DECISAO`, c.`decisao_justificativa` AS `DECISAO_JUSTIFICATIVA`,
               c.`decisao_em` AS `DECISAO_EM`, COALESCE(NULLIF(TRIM(u.`nome`), ''), u.`userid`) AS `DECISAO_USUARIO`,
               CASE WHEN c.`decisao` IS NOT NULL
                         AND c.`decisao_situacao` = c.`situacao`
                         AND COALESCE(c.`decisao_manual`, 'AI') COLLATE utf8mb4_unicode_ci = COALESCE(m.`SITUACAO_MANUAL`, 'AI') COLLATE utf8mb4_unicode_ci
                    THEN 1 ELSE 0 END AS `DECISAO_VALIDA`
          FROM `situacao_legal_calculada` c
          LEFT JOIN `vw_situacao_legal_manual` m ON m.`CODLEGISLACAO` = c.`CODLEGISLACAO` AND m.`CODUNIDADE` = c.`CODUNIDADE`
          LEFT JOIN `usuarios` u ON u.`codusuario` = c.`decisao_codusuario`) x
-- @@
-- requer: vw_modulolegal_requisitos_filtro_grid
-- Grid de requisitos legais: a 284 mais % de atendimento, risco, pendências críticas e itens sem risco avaliado.
CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_situacao_grid` AS
SELECT d.*,
       CASE c.`situacao` WHEN 'S' THEN 'Atendido' WHEN 'PA' THEN 'Parcialmente atendido' WHEN 'N' THEN 'Não atendido'
            WHEN 'AI' THEN 'Sem evidências vinculadas' ELSE '' END AS `SITUACAO_CALCULADA`,
       CASE WHEN c.`situacao` IS NULL OR c.`situacao` = 'AI' OR d.`SL_MANUAL` = 'AI' OR d.`SL_MANUAL` = c.`situacao`
                 OR (c.`decisao` IS NOT NULL AND c.`decisao_situacao` = c.`situacao` AND COALESCE(c.`decisao_manual`, 'AI') = d.`SL_MANUAL`)
            THEN 'Não' ELSE 'Sim' END AS `DIVERGENCIA`,
       CASE CASE WHEN c.`situacao` IS NULL THEN d.`SL_MANUAL`
                 WHEN c.`situacao` = 'AI' AND d.`SL_MANUAL` <> 'AI' THEN d.`SL_MANUAL`
                 WHEN c.`decisao` = 'MANTIDA' AND c.`decisao_situacao` = c.`situacao` AND COALESCE(c.`decisao_manual`, 'AI') = d.`SL_MANUAL` THEN d.`SL_MANUAL`
                 ELSE c.`situacao` END
            WHEN 'S' THEN 'Atendido' WHEN 'PA' THEN 'Parcialmente atendido' WHEN 'N' THEN 'Não atendido' ELSE 'Indefinido' END AS `SITUACAO_CONSOLIDADA`,
       COALESCE(c.`resumo`, '') AS `MOTIVO_CALCULO`,
       CAST(ROUND(c.`pct_atendimento`, 0) AS SIGNED) AS `PCT_ATENDIMENTO`,
       CASE c.`risco` WHEN 'CRITICO' THEN 'Crítico' WHEN 'ALTO' THEN 'Alto' WHEN 'MODERADO' THEN 'Moderado' WHEN 'BAIXO' THEN 'Baixo'
            WHEN 'NAO_AVALIADO' THEN 'Não avaliado' ELSE '' END AS `RISCO_REQUISITO`,
       CAST(COALESCE(c.`pendencias_criticas`, 0) AS SIGNED) AS `PENDENCIAS_CRITICAS`,
       CAST(COALESCE(c.`itens_sem_risco`, 0) AS SIGNED) AS `ITENS_SEM_RISCO`
  FROM (SELECT f.*, CAST(CASE WHEN CONVERT(f.`ATENDIDO` USING utf8mb4) COLLATE utf8mb4_unicode_ci = 'Sim' THEN 'S' WHEN CONVERT(f.`ATENDIDO` USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE 'Parcial%' THEN 'PA' WHEN CONVERT(f.`ATENDIDO` USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE 'N%' THEN 'N'
                         ELSE 'AI' END AS CHAR(2) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS `SL_MANUAL`
          FROM `vw_modulolegal_requisitos_filtro_grid` f) d
  LEFT JOIN `situacao_legal_calculada` c ON c.`CODLEGISLACAO` = d.`CODLEGISLACAO` AND c.`CODUNIDADE` = d.`CODUNIDADE`
-- @@
-- requer: vw_modulolegal_requisitos_licenca_grid, vw_modulolegal_requisitos_situacao_grid, legislacaoxlicencas
-- Requisitos de uma licença (migração 306): recriada para trazer as colunas novas do grid (g.* é fixado na criação da view).
CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_licenca_grid` AS
 SELECT g.*, r.`CODLICENCA` AS `CODLICENCA`
   FROM `vw_modulolegal_requisitos_situacao_grid` g
   JOIN `legislacaoxlicencas` r ON r.`CODLEGISLACAO` = g.`CODLEGISLACAO` AND r.`codunidade` = g.`CODUNIDADE`
-- @@
-- Painel (dashboard e relatório): totais por unidade das duas dimensões. % = itens atendidos ÷ itens aplicáveis da unidade.
CREATE OR REPLACE VIEW `vw_situacao_legal_painel_risco` AS
SELECT c.`CODUNIDADE`, COUNT(*) AS `REQUISITOS`,
       CAST(SUM(c.`itens_aplicaveis` > 0) AS SIGNED) AS `REQUISITOS_COM_ITENS`,
       CAST(SUM(c.`itens_aplicaveis`) AS SIGNED) AS `ITENS_APLICAVEIS`, CAST(SUM(c.`itens_atendidos`) AS SIGNED) AS `ITENS_ATENDIDOS`,
       CAST(SUM(c.`itens_na`) AS SIGNED) AS `ITENS_NA`,
       CASE WHEN SUM(c.`itens_aplicaveis`) > 0 THEN ROUND(SUM(c.`itens_atendidos`) * 100 / SUM(c.`itens_aplicaveis`), 1) END AS `PCT_ATENDIMENTO`,
       CAST(SUM(c.`risco` = 'CRITICO') AS SIGNED) AS `RISCO_CRITICO`, CAST(SUM(c.`risco` = 'ALTO') AS SIGNED) AS `RISCO_ALTO`,
       CAST(SUM(c.`risco` = 'MODERADO') AS SIGNED) AS `RISCO_MODERADO`, CAST(SUM(c.`risco` = 'BAIXO') AS SIGNED) AS `RISCO_BAIXO`,
       CAST(SUM(c.`risco` = 'NAO_AVALIADO') AS SIGNED) AS `RISCO_NAO_AVALIADO`,
       CAST(SUM(c.`pendencias_criticas`) AS SIGNED) AS `PENDENCIAS_CRITICAS`, CAST(SUM(c.`pendencias_criticas` > 0) AS SIGNED) AS `REQUISITOS_PENDENCIA_CRITICA`,
       CAST(SUM(c.`itens_sem_risco`) AS SIGNED) AS `ITENS_SEM_RISCO`
  FROM `situacao_legal_calculada` c
 GROUP BY c.`CODUNIDADE`
-- @@
-- Itens aplicáveis ainda sem severidade/probabilidade (cada item uma vez, com quantos requisitos usam): pendência do dashboard.
CREATE OR REPLACE VIEW `vw_ml_risco_nao_avaliados` AS
SELECT i.`AREA`, i.`CHAVE` AS `ITEM_ID`, MAX(i.`ITEM`) AS `ITEM`, COUNT(DISTINCT i.`CODLEGISLACAO`) AS `REQUISITOS`,
       COUNT(DISTINCT i.`CODUNIDADE`) AS `UNIDADES`, MIN(i.`CODUNIDADE`) AS `CODUNIDADE`
  FROM `vw_situacao_legal_itens` i
 WHERE i.`NA` = 0 AND i.`RISCO` IS NULL
 GROUP BY i.`AREA`, i.`CHAVE`
-- @@
-- Pendências críticas: item aplicável não atendido (Falha ou Vencido) com risco Crítico, por requisito e unidade, uma linha por
-- responsável do item (responsabilidades por registro ou por todos os registros da tabela; sem responsável = uma linha com nome nulo).
CREATE OR REPLACE VIEW `vw_ml_pendencias_criticas` AS
SELECT i.`CODLEGISLACAO`, i.`CODUNIDADE`, i.`AREA`, i.`ITEM_ID`, i.`CHAVE`, i.`ITEM`, i.`STATUS`, i.`MOTIVO`, i.`SEVERIDADE`, i.`PROBABILIDADE`, i.`PONTOS`,
       r.`CODUSUARIO` AS `CODUSUARIO_ITEM`, r.`NOME` AS `RESPONSAVEL_ITEM`
  FROM `vw_situacao_legal_itens` i
  LEFT JOIN `vw_ml_risco_responsaveis` r ON r.`AREA` = i.`AREA` COLLATE utf8mb4_unicode_ci AND r.`ITEM_ID` IN (0, i.`ITEM_ID`, i.`CHAVE`)
 WHERE i.`NA` = 0 AND i.`STATUS` IN ('FALHA', 'CRITICO') AND i.`RISCO` = 'CRITICO'
