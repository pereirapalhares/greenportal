-- Migração 342 — Requisitos legais, etapa 2: a IA sugere a Severidade e a Probabilidade dos itens de gestão (23/10/2026).
-- Banco de cada empresa com a migração 341 (ml_risco_itens). Regras (decisão do usuário):
--   * a IA só sugere, com justificativa; quem grava é o responsável: aceitar, ajustar ou recusar cada sugestão;
--   * pode decidir quem pode alterar o cadastro do item (permissão de edição da transação do pilar) — conferido na tela;
--   * o lote (itens com "risco não avaliado") só roda quando alguém pede; nada roda sozinho;
--   * cada chamada passa pelo taxímetro de IA (tipo ML_RISCO) e respeita o limite mensal da empresa.
-- Pedido: ml_risco_lotes (um por pedido, de um item ou em lote) e ml_risco_sugestoes (uma por item). Os itens vão para a fila
-- de IA (green_ia_document_jobs, tipo ML_RISCO, até 8 itens por pedido); o serviço "ia" do Agendador grava o resultado nas
-- sugestões (sp_ml_risco_sugestao_resultado) e, quando o lote termina, o alerta "Sugestões de risco prontas" (sp_ml_risco_lote_verificar).
-- Aceitar/ajustar grava o risco por sp_ml_risco_salvar (migração 341) e marca a origem (IA ou IA ajustada) no item e no histórico.
-- Blocos separados por "-- @@" (scripts/aplicar-migracao-342-risco-sugestao-ia.php). Textos comparados com COLLATE explícito.
-- @@
CREATE TABLE IF NOT EXISTS `ml_risco_lotes` (
  `CODLOTE` BIGINT NOT NULL AUTO_INCREMENT,
  `CODUSUARIO` BIGINT NULL COMMENT 'Quem pediu (recebe o alerta)',
  `ORIGEM` VARCHAR(10) NOT NULL DEFAULT 'LOTE' COMMENT 'ITEM (botão no cadastro) ou LOTE (itens não avaliados)',
  `AREA` VARCHAR(20) NULL COMMENT 'Filtro do lote (vazio = todas as áreas)',
  `TOTAL` INT NOT NULL DEFAULT 0,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `CONCLUIDO_EM` DATETIME NULL,
  `ALERTADO` TINYINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`CODLOTE`),
  KEY `ix_ml_risco_lotes_usuario` (`CODUSUARIO`, `CRIADO_EM`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS `ml_risco_sugestoes` (
  `CODSUGESTAO` BIGINT NOT NULL AUTO_INCREMENT,
  `CODLOTE` BIGINT NOT NULL,
  `AREA` VARCHAR(20) NOT NULL,
  `ITEM_ID` BIGINT NOT NULL COMMENT 'Mesmo código de ml_risco_itens (licença: a original)',
  `ITEM` VARCHAR(300) NULL,
  `REF` VARCHAR(10) NULL COMMENT 'Referência do item no pedido à IA (A1, A2...)',
  `CODJOB` BIGINT NULL,
  `STATUS` VARCHAR(14) NOT NULL DEFAULT 'AGUARDANDO' COMMENT 'AGUARDANDO, PENDENTE (a decidir), ACEITA, AJUSTADA, RECUSADA, SUBSTITUIDA, ERRO, SEM_RESPOSTA',
  `SEVERIDADE` TINYINT NULL, `PROBABILIDADE` TINYINT NULL, `PONTOS` TINYINT NULL, `CLASSE` VARCHAR(10) NULL,
  `JUSTIFICATIVA` VARCHAR(2000) NULL,
  `CONFIANCA` TINYINT NULL COMMENT '0 a 100, informada pela IA',
  `ERRO` VARCHAR(500) NULL,
  `SEV_FINAL` TINYINT NULL, `PROB_FINAL` TINYINT NULL,
  `MOTIVO_DECISAO` VARCHAR(1000) NULL,
  `CODUSUARIO_DECISAO` BIGINT NULL,
  `DECIDIDO_EM` DATETIME NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `RESPONDIDO_EM` DATETIME NULL,
  PRIMARY KEY (`CODSUGESTAO`),
  KEY `ix_ml_risco_sug_item` (`AREA`, `ITEM_ID`, `STATUS`),
  KEY `ix_ml_risco_sug_job` (`CODJOB`),
  KEY `ix_ml_risco_sug_lote` (`CODLOTE`, `STATUS`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
DROP PROCEDURE IF EXISTS `sp_ml_risco_lote_criar`
-- @@
CREATE PROCEDURE `sp_ml_risco_lote_criar`(IN p_usuario BIGINT, IN p_origem VARCHAR(10), IN p_area VARCHAR(20))
BEGIN
  INSERT INTO `ml_risco_lotes` (`CODUSUARIO`, `ORIGEM`, `AREA`)
  VALUES (NULLIF(p_usuario, 0), IF(UPPER(COALESCE(p_origem, '')) = 'ITEM', 'ITEM', 'LOTE'), NULLIF(UPPER(TRIM(COALESCE(p_area, ''))), ''));
  SELECT LAST_INSERT_ID() AS `CODLOTE`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ml_risco_sugestao_criar`
-- @@
CREATE PROCEDURE `sp_ml_risco_sugestao_criar`(IN p_lote BIGINT, IN p_area VARCHAR(20), IN p_item BIGINT, IN p_nome VARCHAR(300), IN p_ref VARCHAR(10))
BEGIN
  -- Uma sugestão por item: a ainda não decidida (ou aguardando) do mesmo item fica SUBSTITUIDA. Licença: na licença original.
  DECLARE v_area VARCHAR(20) DEFAULT UPPER(TRIM(COALESCE(p_area, '')));
  DECLARE v_chave BIGINT DEFAULT p_item;
  IF v_area NOT IN ('LICENCAS', 'MEDICAO', 'TREINAMENTOS', 'INFRAESTRUTURA', 'CONTROLES', 'HOMOLOGACAO') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Área do item inválida.';
  END IF;
  IF v_area = 'LICENCAS' THEN SELECT COALESCE(MAX(`CHAVE`), p_item) INTO v_chave FROM `vw_ml_licenca_chave` WHERE `CODLICENCA` = p_item; END IF;
  UPDATE `ml_risco_sugestoes` SET `STATUS` = 'SUBSTITUIDA'
   WHERE `AREA` = v_area COLLATE utf8mb4_unicode_ci AND `ITEM_ID` = v_chave AND `STATUS` IN ('AGUARDANDO', 'PENDENTE');
  INSERT INTO `ml_risco_sugestoes` (`CODLOTE`, `AREA`, `ITEM_ID`, `ITEM`, `REF`) VALUES (p_lote, v_area, v_chave, LEFT(p_nome, 300), p_ref);
  UPDATE `ml_risco_lotes` SET `TOTAL` = `TOTAL` + 1 WHERE `CODLOTE` = p_lote;
  SELECT LAST_INSERT_ID() AS `CODSUGESTAO`, v_chave AS `ITEM_ID`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ml_risco_sugestao_job`
-- @@
CREATE PROCEDURE `sp_ml_risco_sugestao_job`(IN p_sugestao BIGINT, IN p_job BIGINT)
BEGIN
  UPDATE `ml_risco_sugestoes` SET `CODJOB` = p_job WHERE `CODSUGESTAO` = p_sugestao AND `STATUS` = 'AGUARDANDO';
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ml_risco_sugestao_resultado`
-- @@
CREATE PROCEDURE `sp_ml_risco_sugestao_resultado`(IN p_sugestao BIGINT, IN p_status VARCHAR(14), IN p_sev TINYINT, IN p_prob TINYINT,
  IN p_justificativa VARCHAR(2000), IN p_confianca TINYINT, IN p_erro VARCHAR(500))
BEGIN
  -- Resposta da IA para o item (serviço "ia"): PENDENTE com os valores (1 a 4), ou ERRO / SEM_RESPOSTA com o motivo.
  DECLARE v_status VARCHAR(14) DEFAULT UPPER(COALESCE(p_status, ''));
  DECLARE v_pontos TINYINT DEFAULT NULL;
  IF v_status = 'PENDENTE' AND (p_sev IS NULL OR p_sev NOT BETWEEN 1 AND 4 OR p_prob IS NULL OR p_prob NOT BETWEEN 1 AND 4) THEN
    SET v_status = 'SEM_RESPOSTA';
  END IF;
  IF v_status = 'PENDENTE' THEN SET v_pontos = p_sev * p_prob; END IF;
  UPDATE `ml_risco_sugestoes`
     SET `STATUS` = IF(v_status IN ('PENDENTE', 'ERRO', 'SEM_RESPOSTA'), v_status, 'ERRO'),
         `SEVERIDADE` = IF(v_status = 'PENDENTE', p_sev, NULL), `PROBABILIDADE` = IF(v_status = 'PENDENTE', p_prob, NULL), `PONTOS` = v_pontos,
         `CLASSE` = CASE WHEN v_pontos IS NULL THEN NULL WHEN p_sev = 4 OR v_pontos >= 12 THEN 'CRITICO' WHEN v_pontos >= 8 THEN 'ALTO' WHEN v_pontos >= 4 THEN 'MODERADO' ELSE 'BAIXO' END,
         `JUSTIFICATIVA` = NULLIF(TRIM(COALESCE(p_justificativa, '')), ''), `CONFIANCA` = IF(p_confianca BETWEEN 0 AND 100, p_confianca, NULL),
         `ERRO` = NULLIF(TRIM(COALESCE(p_erro, '')), ''), `RESPONDIDO_EM` = NOW()
   WHERE `CODSUGESTAO` = p_sugestao AND `STATUS` = 'AGUARDANDO';
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ml_risco_lote_verificar`
-- @@
CREATE PROCEDURE `sp_ml_risco_lote_verificar`(IN p_lote BIGINT, IN p_link VARCHAR(500))
BEGIN
  -- Lote sem itens aguardando: marca concluído e manda um alerta (uma vez) para quem pediu.
  DECLARE v_usuario BIGINT DEFAULT NULL; DECLARE v_alertado TINYINT DEFAULT 1; DECLARE v_origem VARCHAR(10) DEFAULT 'LOTE';
  DECLARE v_aguardando INT DEFAULT 0; DECLARE v_pendentes INT DEFAULT 0; DECLARE v_erros INT DEFAULT 0; DECLARE v_item VARCHAR(300) DEFAULT '';
  SELECT `CODUSUARIO`, `ALERTADO`, `ORIGEM` INTO v_usuario, v_alertado, v_origem FROM `ml_risco_lotes` WHERE `CODLOTE` = p_lote;
  SELECT SUM(`STATUS` = 'AGUARDANDO'), SUM(`STATUS` = 'PENDENTE'), SUM(`STATUS` IN ('ERRO', 'SEM_RESPOSTA')), MAX(`ITEM`)
    INTO v_aguardando, v_pendentes, v_erros, v_item FROM `ml_risco_sugestoes` WHERE `CODLOTE` = p_lote;
  IF COALESCE(v_aguardando, 0) = 0 AND v_alertado = 0 THEN
    UPDATE `ml_risco_lotes` SET `CONCLUIDO_EM` = NOW(), `ALERTADO` = 1 WHERE `CODLOTE` = p_lote;
    IF v_usuario IS NOT NULL AND EXISTS (SELECT 1 FROM `usuarios` WHERE `codusuario` = v_usuario AND `ativo` = 1) THEN
      INSERT INTO `alertas` (`codusuario`, `titulo`, `mensagem`, `link`, `lido`)
      VALUES (v_usuario,
              IF(COALESCE(v_pendentes, 0) > 0, 'Sugestões de risco da IA prontas para revisar', 'A IA não conseguiu sugerir o risco'),
              LEFT(CONCAT(IF(v_origem = 'ITEM', CONCAT('Item "', COALESCE(v_item, ''), '": '), CONCAT('Pedido ', p_lote, ': ')),
                          IF(COALESCE(v_pendentes, 0) = 0,
                             CONCAT('a IA não respondeu (', COALESCE(v_erros, 0), ' item(ns) sem sugestão). Veja o motivo na aba "Decididas e sem resposta" e peça de novo.'),
                             CONCAT(v_pendentes, IF(v_pendentes = 1, ' sugestão de Severidade × Probabilidade para revisar', ' sugestões de Severidade × Probabilidade para revisar'),
                                    IF(COALESCE(v_erros, 0) > 0, CONCAT('; ', v_erros, ' item(ns) sem resposta da IA'), ''),
                                    '. Nada foi gravado nos itens: aceite, ajuste ou recuse cada sugestão.'))), 1000),
              p_link, 0);
    END IF;
  END IF;
  SELECT COALESCE(v_aguardando, 0) AS `AGUARDANDO`, COALESCE(v_pendentes, 0) AS `PENDENTES`, COALESCE(v_erros, 0) AS `ERROS`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ml_risco_sugestao_decidir`
-- @@
CREATE PROCEDURE `sp_ml_risco_sugestao_decidir`(IN p_sugestao BIGINT, IN p_decisao VARCHAR(10), IN p_sev TINYINT, IN p_prob TINYINT,
  IN p_motivo VARCHAR(1000), IN p_usuario BIGINT)
BEGIN
  -- ACEITAR (valores da IA), AJUSTAR (valores informados, 1 a 4) ou RECUSAR. Aceitar/ajustar grava o risco do item
  -- (sp_ml_risco_salvar, com a justificativa da IA) e marca a origem no item e no histórico. A permissão é conferida na tela.
  DECLARE v_decisao VARCHAR(10) DEFAULT UPPER(TRIM(COALESCE(p_decisao, '')));
  DECLARE v_status VARCHAR(14) DEFAULT NULL; DECLARE v_area VARCHAR(20); DECLARE v_item BIGINT;
  DECLARE v_sev TINYINT; DECLARE v_prob TINYINT; DECLARE v_just VARCHAR(2000); DECLARE v_hist BIGINT DEFAULT 0;
  SELECT `STATUS`, `AREA`, `ITEM_ID`, `SEVERIDADE`, `PROBABILIDADE`, `JUSTIFICATIVA` INTO v_status, v_area, v_item, v_sev, v_prob, v_just
    FROM `ml_risco_sugestoes` WHERE `CODSUGESTAO` = p_sugestao;
  IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sugestão não encontrada.'; END IF;
  IF v_status <> 'PENDENTE' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta sugestão já foi decidida ou foi substituída por uma mais nova.'; END IF;
  IF v_decisao NOT IN ('ACEITAR', 'AJUSTAR', 'RECUSAR') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Decisão inválida.'; END IF;
  IF v_decisao = 'RECUSAR' THEN
    UPDATE `ml_risco_sugestoes` SET `STATUS` = 'RECUSADA', `MOTIVO_DECISAO` = NULLIF(TRIM(COALESCE(p_motivo, '')), ''),
           `CODUSUARIO_DECISAO` = NULLIF(p_usuario, 0), `DECIDIDO_EM` = NOW() WHERE `CODSUGESTAO` = p_sugestao;
  ELSE
    IF v_decisao = 'AJUSTAR' THEN
      IF p_sev IS NULL OR p_sev NOT BETWEEN 1 AND 4 OR p_prob IS NULL OR p_prob NOT BETWEEN 1 AND 4 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Para ajustar, informe a severidade e a probabilidade (1 a 4).';
      END IF;
      IF p_sev = v_sev AND p_prob = v_prob THEN SET v_decisao = 'ACEITAR'; ELSE SET v_sev = p_sev; SET v_prob = p_prob; END IF;
    END IF;
    SELECT COALESCE(MAX(`CODHISTORICO`), 0) INTO v_hist FROM `ml_risco_itens_historico`;
    CALL `sp_ml_risco_salvar`(v_area, v_item, v_sev, v_prob,
      LEFT(CONCAT(IF(v_decisao = 'ACEITAR', 'Sugerido pela IA', 'Sugerido pela IA e ajustado'), IF(v_just IS NULL, '', CONCAT(': ', v_just)),
                  IF(v_decisao = 'ACEITAR' OR NULLIF(TRIM(COALESCE(p_motivo, '')), '') IS NULL, '', CONCAT(' — Ajuste: ', TRIM(p_motivo)))), 1000), p_usuario);
    UPDATE `ml_risco_itens` SET `ORIGEM` = IF(v_decisao = 'ACEITAR', 'IA', 'IA_AJUSTADA'), `CODSUGESTAO` = p_sugestao
     WHERE `AREA` = v_area COLLATE utf8mb4_unicode_ci AND `ITEM_ID` = v_item;
    UPDATE `ml_risco_itens_historico` SET `ORIGEM` = IF(v_decisao = 'ACEITAR', 'IA', 'IA_AJUSTADA'), `CODSUGESTAO` = p_sugestao
     WHERE `CODHISTORICO` > v_hist AND `AREA` = v_area COLLATE utf8mb4_unicode_ci AND `ITEM_ID` = v_item;
    UPDATE `ml_risco_sugestoes` SET `STATUS` = IF(v_decisao = 'ACEITAR', 'ACEITA', 'AJUSTADA'), `SEV_FINAL` = v_sev, `PROB_FINAL` = v_prob,
           `MOTIVO_DECISAO` = NULLIF(TRIM(COALESCE(p_motivo, '')), ''), `CODUSUARIO_DECISAO` = NULLIF(p_usuario, 0), `DECIDIDO_EM` = NOW()
     WHERE `CODSUGESTAO` = p_sugestao;
  END IF;
  SELECT p_sugestao AS `CODSUGESTAO`, IF(v_decisao = 'RECUSAR', 'RECUSADA', IF(v_decisao = 'ACEITAR', 'ACEITA', 'AJUSTADA')) AS `STATUS`, v_area AS `AREA`, v_item AS `ITEM_ID`;
END
-- @@
CREATE OR REPLACE VIEW `vw_ml_risco_sugestoes` AS
SELECT s.`CODSUGESTAO`, s.`CODLOTE`, s.`AREA`, s.`ITEM_ID`, s.`ITEM`, s.`REF` AS `REF_PEDIDO`, s.`CODJOB`, s.`STATUS`,
       CONVERT(CASE s.`STATUS` WHEN 'AGUARDANDO' THEN 'Aguardando a IA' WHEN 'PENDENTE' THEN 'A revisar' WHEN 'ACEITA' THEN 'Aceita'
            WHEN 'AJUSTADA' THEN 'Ajustada' WHEN 'RECUSADA' THEN 'Recusada' WHEN 'SUBSTITUIDA' THEN 'Substituída'
            WHEN 'ERRO' THEN 'Erro da IA' WHEN 'SEM_RESPOSTA' THEN 'Sem resposta' ELSE s.`STATUS` END USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `STATUS_TEXTO`,
       s.`SEVERIDADE`, s.`PROBABILIDADE`, s.`PONTOS`, s.`CLASSE`, s.`JUSTIFICATIVA`, s.`CONFIANCA`, s.`ERRO`, s.`SEV_FINAL`, s.`PROB_FINAL`,
       s.`MOTIVO_DECISAO`, s.`CODUSUARIO_DECISAO`, s.`DECIDIDO_EM`, s.`CRIADO_EM`, s.`RESPONDIDO_EM`,
       l.`CODUSUARIO` AS `CODUSUARIO_PEDIDO`, l.`ORIGEM` AS `ORIGEM_PEDIDO`,
       CONVERT(COALESCE(NULLIF(TRIM(up.`nome`), ''), '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `NOME_PEDIDO`,
       CONVERT(COALESCE(NULLIF(TRIM(ud.`nome`), ''), '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `NOME_DECISAO`,
       r.`SEVERIDADE` AS `SEV_ATUAL`, r.`PROBABILIDADE` AS `PROB_ATUAL`, r.`CLASSE` AS `CLASSE_ATUAL`
  FROM `ml_risco_sugestoes` s
  JOIN `ml_risco_lotes` l ON l.`CODLOTE` = s.`CODLOTE`
  LEFT JOIN `usuarios` up ON up.`codusuario` = l.`CODUSUARIO`
  LEFT JOIN `usuarios` ud ON ud.`codusuario` = s.`CODUSUARIO_DECISAO`
  LEFT JOIN `ml_risco_itens` r ON r.`AREA` = s.`AREA` AND r.`ITEM_ID` = s.`ITEM_ID`
-- @@
CREATE OR REPLACE VIEW `vw_ml_risco_lotes` AS
SELECT l.`CODLOTE`, l.`CODUSUARIO`, l.`ORIGEM`, l.`AREA`, l.`TOTAL`, l.`CRIADO_EM`, l.`CONCLUIDO_EM`,
       CAST(COALESCE(SUM(s.`STATUS` = 'AGUARDANDO'), 0) AS SIGNED) AS `AGUARDANDO`, CAST(COALESCE(SUM(s.`STATUS` = 'PENDENTE'), 0) AS SIGNED) AS `PENDENTES`,
       CAST(COALESCE(SUM(s.`STATUS` IN ('ACEITA', 'AJUSTADA')), 0) AS SIGNED) AS `ACEITAS`, CAST(COALESCE(SUM(s.`STATUS` = 'RECUSADA'), 0) AS SIGNED) AS `RECUSADAS`,
       CAST(COALESCE(SUM(s.`STATUS` IN ('ERRO', 'SEM_RESPOSTA')), 0) AS SIGNED) AS `ERROS`,
       CONVERT(COALESCE(NULLIF(TRIM(u.`nome`), ''), '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `NOME_PEDIDO`
  FROM `ml_risco_lotes` l
  LEFT JOIN `ml_risco_sugestoes` s ON s.`CODLOTE` = l.`CODLOTE`
  LEFT JOIN `usuarios` u ON u.`codusuario` = l.`CODUSUARIO`
 GROUP BY l.`CODLOTE`, l.`CODUSUARIO`, l.`ORIGEM`, l.`AREA`, l.`TOTAL`, l.`CRIADO_EM`, l.`CONCLUIDO_EM`, u.`nome`
-- @@
-- Origem do risco gravado (MANUAL, IA, IA_AJUSTADA): selo "IA" nos cadastros e na Situação do requisito
CREATE OR REPLACE VIEW `vw_ml_risco_itens_origem` AS
SELECT `AREA`, `ITEM_ID`, `ORIGEM`, `CODSUGESTAO` FROM `ml_risco_itens`
-- @@
DROP TRIGGER IF EXISTS `trg_ml_risco_itens_origem`
-- @@
-- Alteração manual do risco (cadastro do item) depois de uma sugestão aceita: a origem volta a ser MANUAL
CREATE TRIGGER `trg_ml_risco_itens_origem` BEFORE UPDATE ON `ml_risco_itens` FOR EACH ROW
BEGIN
  IF (NEW.`SEVERIDADE` <> OLD.`SEVERIDADE` OR NEW.`PROBABILIDADE` <> OLD.`PROBABILIDADE`)
     AND NEW.`ORIGEM` <=> OLD.`ORIGEM` AND NEW.`CODSUGESTAO` <=> OLD.`CODSUGESTAO` THEN
    SET NEW.`ORIGEM` = 'MANUAL', NEW.`CODSUGESTAO` = NULL;
  END IF;
END
