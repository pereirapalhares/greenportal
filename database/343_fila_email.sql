-- Migração 343 — fila de e-mails (as mesmas definições da migração 333, sem as partes de Não Conformidades) e a tabela de
-- alertas, para a empresa que ainda não as tem (ex.: empresa sem o módulo de NC). Aplicada por
-- scripts/aplicar-migracao-343-alertas-email.php só quando falta a fila. Blocos separados por "-- @@".
-- @@
CREATE TABLE IF NOT EXISTS `alertas` (
    `codalerta` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codusuario` BIGINT UNSIGNED NOT NULL,
    `titulo` VARCHAR(180) NOT NULL,
    `mensagem` TEXT NOT NULL,
    `link` VARCHAR(500) NULL,
    `lido` TINYINT(1) NOT NULL DEFAULT 0,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codalerta`),
    KEY `ix_alertas_usuario` (`codusuario`, `lido`, `criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
CREATE TABLE IF NOT EXISTS `email_fila` (
  `codemail` BIGINT NOT NULL AUTO_INCREMENT,
  `destinatario` VARCHAR(500) NOT NULL,
  `nome_destinatario` VARCHAR(200) NULL,
  `assunto` VARCHAR(255) NOT NULL,
  `corpo` MEDIUMTEXT NOT NULL,
  `origem` VARCHAR(50) NOT NULL,
  `referencia` VARCHAR(100) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
  `tentativas` INT NOT NULL DEFAULT 0,
  `ultimo_erro` VARCHAR(1000) NULL,
  `token` CHAR(36) NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processando_em` DATETIME NULL,
  `proxima_tentativa_em` DATETIME NULL,
  `enviado_em` DATETIME NULL,
  PRIMARY KEY (`codemail`),
  KEY `ix_email_fila_status` (`status`, `proxima_tentativa_em`),
  KEY `ix_email_fila_token` (`token`),
  KEY `ix_email_fila_criado` (`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
CREATE OR REPLACE VIEW `vw_email_fila` AS
SELECT codemail, destinatario, nome_destinatario, assunto, origem, referencia, status, tentativas, ultimo_erro, criado_em, proxima_tentativa_em, enviado_em
  FROM email_fila
-- @@
DROP PROCEDURE IF EXISTS `sp_email_enfileirar`
-- @@
CREATE PROCEDURE `sp_email_enfileirar`(IN p_destinatario VARCHAR(500), IN p_nome VARCHAR(200), IN p_assunto VARCHAR(255), IN p_corpo MEDIUMTEXT, IN p_origem VARCHAR(50), IN p_referencia VARCHAR(100))
BEGIN
  IF TRIM(COALESCE(p_destinatario, '')) <> '' THEN
    INSERT INTO email_fila (destinatario, nome_destinatario, assunto, corpo, origem, referencia) VALUES (TRIM(p_destinatario), p_nome, LEFT(p_assunto, 255), p_corpo, p_origem, p_referencia);
  END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_email_reivindicar`
-- @@
CREATE PROCEDURE `sp_email_reivindicar`()
BEGIN
  DECLARE v_token CHAR(36) DEFAULT UUID();
  UPDATE email_fila SET status = 'PENDENTE', token = NULL WHERE status = 'PROCESSANDO' AND processando_em < NOW() - INTERVAL 30 MINUTE;
  UPDATE email_fila SET status = 'PROCESSANDO', token = v_token, processando_em = NOW()
   WHERE status = 'PENDENTE' AND (proxima_tentativa_em IS NULL OR proxima_tentativa_em <= NOW()) ORDER BY codemail LIMIT 25;
  SELECT codemail, destinatario, nome_destinatario, assunto, corpo, origem, referencia, tentativas FROM email_fila WHERE token = v_token ORDER BY codemail;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_email_resultado`
-- @@
CREATE PROCEDURE `sp_email_resultado`(IN p_codigo BIGINT, IN p_ok TINYINT, IN p_erro VARCHAR(1000))
BEGIN
  IF p_ok = 1 THEN
    UPDATE email_fila SET status = 'CONCLUIDA', enviado_em = NOW(), token = NULL, ultimo_erro = NULL WHERE codemail = p_codigo;
  ELSE
    UPDATE email_fila SET tentativas = tentativas + 1, status = IF(tentativas >= 5, 'ERRO', 'PENDENTE'),
           proxima_tentativa_em = NOW() + INTERVAL (10 * tentativas) MINUTE, ultimo_erro = LEFT(COALESCE(p_erro, ''), 1000), token = NULL
     WHERE codemail = p_codigo;
  END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_email_recusado`
-- @@
CREATE PROCEDURE `sp_email_recusado`(IN p_codigo BIGINT, IN p_erro VARCHAR(1000))
BEGIN
  UPDATE email_fila SET tentativas = tentativas + 1, status = 'ERRO', ultimo_erro = LEFT(COALESCE(p_erro, ''), 1000), token = NULL, proxima_tentativa_em = NULL WHERE codemail = p_codigo;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_email_devolver`
-- @@
CREATE PROCEDURE `sp_email_devolver`(IN p_codigo BIGINT)
BEGIN
  UPDATE email_fila SET status = 'PENDENTE', token = NULL WHERE codemail = p_codigo AND status = 'PROCESSANDO';
END
-- @@
DROP PROCEDURE IF EXISTS `sp_email_reenviar`
-- @@
CREATE PROCEDURE `sp_email_reenviar`(IN p_codigo BIGINT)
BEGIN
  UPDATE email_fila SET status = 'PENDENTE', tentativas = 0, proxima_tentativa_em = NULL, token = NULL WHERE codemail = p_codigo AND status IN ('ERRO', 'CANCELADA');
  SELECT ROW_COUNT() AS ALTERADOS;
END
