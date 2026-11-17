-- Migração 343 — Alertas do portal por e-mail, com link direto (Onda 1 da apresentação Atlas; 23/10/2026).
-- Banco de cada empresa com alertas e a fila de e-mails (migração 333: email_fila, sp_email_enfileirar).
-- Decisões do usuário: cada usuário escolhe em Minha conta › Alertas por e-mail: na hora (padrão), resumo diário às 7h ou
-- não receber; "Exportação iniciada" não vai por e-mail; o e-mail traz "Abrir no portal" (login e a tela abre como aba).
-- O serviço de e-mail do Agendador (scripts/email-worker.php, a cada 2 minutos) lê os alertas novos (depois da marca
-- ULTIMO_CODALERTA: nada do histórico é enviado), junta os de cada pessoa no mesmo ciclo num e-mail e põe na fila; os do
-- resumo ficam em alertas_email_resumo até as 7h (só os ainda não lidos saem no resumo). Sem SMTP no .env, a marca só avança.
-- Blocos separados por "-- @@" (scripts/aplicar-migracao-343-alertas-email.php). Textos comparados com COLLATE explícito.
-- @@
CREATE TABLE IF NOT EXISTS `alertas_email_pref` (
  `CODUSUARIO` BIGINT NOT NULL,
  `MODO` VARCHAR(10) NOT NULL DEFAULT 'IMEDIATO' COMMENT 'IMEDIATO, RESUMO (diário às 7h) ou NAO',
  `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODUSUARIO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS `alertas_email_controle` (
  `CHAVE` VARCHAR(40) NOT NULL,
  `VALOR` VARCHAR(100) NULL,
  `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`CHAVE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS `alertas_email_resumo` (
  `CODALERTA` BIGINT NOT NULL,
  `CODUSUARIO` BIGINT NOT NULL,
  `INCLUIDO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODALERTA`),
  KEY `ix_alertas_email_resumo_usuario` (`CODUSUARIO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE OR REPLACE VIEW `vw_alertas_email_pref` AS
SELECT u.`codusuario` AS `CODUSUARIO`, CONVERT(COALESCE(u.`nome`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `NOME`,
       CONVERT(TRIM(COALESCE(u.`email`, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `EMAIL`,
       CAST(COALESCE(p.`MODO`, 'IMEDIATO') AS CHAR(10) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS `MODO`, p.`ALTERADO_EM`
  FROM `usuarios` u LEFT JOIN `alertas_email_pref` p ON p.`CODUSUARIO` = u.`codusuario`
 WHERE u.`ativo` = 1
-- @@
DROP PROCEDURE IF EXISTS `sp_alerta_email_pref_salvar`
-- @@
CREATE PROCEDURE `sp_alerta_email_pref_salvar`(IN p_usuario BIGINT, IN p_modo VARCHAR(10))
BEGIN
  DECLARE v_modo VARCHAR(10) DEFAULT UPPER(TRIM(COALESCE(p_modo, '')));
  IF v_modo NOT IN ('IMEDIATO', 'RESUMO', 'NAO') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Opção inválida.'; END IF;
  IF COALESCE(p_usuario, 0) < 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Usuário não informado.'; END IF;
  INSERT INTO `alertas_email_pref` (`CODUSUARIO`, `MODO`) VALUES (p_usuario, v_modo) ON DUPLICATE KEY UPDATE `MODO` = VALUES(`MODO`);
  -- quem deixa de receber o resumo não recebe os itens que já estavam esperando
  IF v_modo <> 'RESUMO' THEN DELETE FROM `alertas_email_resumo` WHERE `CODUSUARIO` = p_usuario; END IF;
  SELECT p_usuario AS `CODUSUARIO`, v_modo AS `MODO`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_alertas_email_novos`
-- @@
CREATE PROCEDURE `sp_alertas_email_novos`(IN p_limite INT)
BEGIN
  -- Alertas depois da marca (o primeiro uso grava a marca no último alerta existente: o histórico não vai por e-mail),
  -- com o e-mail e a escolha da pessoa. Alerta com mais de 1 dia (serviço parado) e "Exportação iniciada" saem como IGNORAR.
  DECLARE v_marca BIGINT DEFAULT NULL;
  SELECT CAST(`VALOR` AS UNSIGNED) INTO v_marca FROM `alertas_email_controle` WHERE `CHAVE` = 'ULTIMO_CODALERTA';
  IF v_marca IS NULL THEN
    SELECT COALESCE(MAX(`codalerta`), 0) INTO v_marca FROM `alertas`;
    INSERT INTO `alertas_email_controle` (`CHAVE`, `VALOR`) VALUES ('ULTIMO_CODALERTA', v_marca) ON DUPLICATE KEY UPDATE `VALOR` = VALUES(`VALOR`);
  END IF;
  SELECT a.`codalerta` AS `CODALERTA`, a.`codusuario` AS `CODUSUARIO`, a.`titulo` AS `TITULO`, a.`mensagem` AS `MENSAGEM`, a.`link` AS `LINK`, a.`criado_em` AS `CRIADO_EM`,
         COALESCE(p.`NOME`, '') AS `NOME`, COALESCE(p.`EMAIL`, '') AS `EMAIL`, COALESCE(p.`MODO`, 'NAO') AS `MODO`,
         CASE WHEN p.`CODUSUARIO` IS NULL THEN 'IGNORAR'
              WHEN a.`criado_em` < NOW() - INTERVAL 1 DAY THEN 'IGNORAR'
              WHEN CONVERT(COALESCE(a.`titulo`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE 'Exporta__o iniciada%' THEN 'IGNORAR'
              ELSE p.`MODO` END AS `ACAO`
    FROM `alertas` a
    LEFT JOIN `vw_alertas_email_pref` p ON p.`CODUSUARIO` = a.`codusuario`
   WHERE a.`codalerta` > v_marca
   ORDER BY a.`codalerta`
   LIMIT p_limite;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_alertas_email_marca`
-- @@
CREATE PROCEDURE `sp_alertas_email_marca`(IN p_ultimo BIGINT)
BEGIN
  INSERT INTO `alertas_email_controle` (`CHAVE`, `VALOR`) VALUES ('ULTIMO_CODALERTA', p_ultimo)
  ON DUPLICATE KEY UPDATE `VALOR` = IF(CAST(`VALOR` AS UNSIGNED) < p_ultimo, VALUES(`VALOR`), `VALOR`);
END
-- @@
DROP PROCEDURE IF EXISTS `sp_alerta_email_resumo_incluir`
-- @@
CREATE PROCEDURE `sp_alerta_email_resumo_incluir`(IN p_alerta BIGINT, IN p_usuario BIGINT)
BEGIN
  INSERT IGNORE INTO `alertas_email_resumo` (`CODALERTA`, `CODUSUARIO`) VALUES (p_alerta, p_usuario);
END
-- @@
DROP PROCEDURE IF EXISTS `sp_alertas_email_resumo_pendentes`
-- @@
CREATE PROCEDURE `sp_alertas_email_resumo_pendentes`()
BEGIN
  -- Itens do resumo diário ainda não lidos, de quem continua com a opção RESUMO e tem e-mail.
  SELECT r.`CODUSUARIO`, r.`CODALERTA`, a.`titulo` AS `TITULO`, a.`mensagem` AS `MENSAGEM`, a.`link` AS `LINK`, a.`criado_em` AS `CRIADO_EM`,
         p.`NOME`, p.`EMAIL`
    FROM `alertas_email_resumo` r
    JOIN `alertas` a ON a.`codalerta` = r.`CODALERTA` AND COALESCE(a.`lido`, 0) = 0
    JOIN `vw_alertas_email_pref` p ON p.`CODUSUARIO` = r.`CODUSUARIO` AND p.`MODO` = 'RESUMO'
   ORDER BY r.`CODUSUARIO`, r.`CODALERTA`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_alertas_email_resumo_concluir`
-- @@
CREATE PROCEDURE `sp_alertas_email_resumo_concluir`(IN p_dia DATE)
BEGIN
  -- Depois de montar os resumos do dia: limpa a lista e grava o dia (um resumo por dia).
  DELETE FROM `alertas_email_resumo`;
  INSERT INTO `alertas_email_controle` (`CHAVE`, `VALOR`) VALUES ('RESUMO_DIA', DATE_FORMAT(p_dia, '%Y-%m-%d'))
  ON DUPLICATE KEY UPDATE `VALOR` = VALUES(`VALOR`);
END
-- @@
CREATE OR REPLACE VIEW `vw_alertas_email_controle` AS
SELECT `CHAVE`, `VALOR`, `ALTERADO_EM` FROM `alertas_email_controle`
