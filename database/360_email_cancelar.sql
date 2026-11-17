-- Migração 360 — em cada empresa com a fila de e-mails: cancela os pendentes quando o envio está desligado na tela.
-- @@
DROP PROCEDURE IF EXISTS `sp_email_cancelar`
-- @@
CREATE PROCEDURE `sp_email_cancelar`(IN p_motivo VARCHAR(1000))
BEGIN
  UPDATE email_fila SET status = 'CANCELADA', ultimo_erro = LEFT(COALESCE(p_motivo, ''), 1000), token = NULL, proxima_tentativa_em = NULL
   WHERE status = 'PENDENTE';
  SELECT ROW_COUNT() AS CANCELADOS;
END
