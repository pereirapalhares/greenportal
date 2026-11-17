USE `gmodelo_dbp`;
DROP PROCEDURE IF EXISTS sp_ia_potencial_recuperar_expiradas;
DELIMITER $$
CREATE PROCEDURE sp_ia_potencial_recuperar_expiradas(IN p_minutos INT)
SQL SECURITY DEFINER
BEGIN
    DECLARE v_minutos INT DEFAULT 30;
    SET v_minutos=IF(p_minutos<5,5,p_minutos);
    UPDATE ml_potenciais_solicitacoes SET status='PENDENTE', iniciada_em=NULL, processador=NULL, erro='Processamento recuperado automaticamente apos interrupcao do servico'
    WHERE status='PROCESSANDO' AND iniciada_em IS NOT NULL AND iniciada_em < DATE_SUB(NOW(), INTERVAL v_minutos MINUTE);
    SELECT ROW_COUNT() AS recuperadas;
END$$
DELIMITER ;
