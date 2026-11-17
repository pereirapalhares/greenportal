USE `gmodelo_dbp`;
DROP PROCEDURE IF EXISTS sp_ia_potencial_marcar_erro_sem_alerta;
DELIMITER $$
CREATE PROCEDURE sp_ia_potencial_marcar_erro_sem_alerta(IN p_codsolicitacao BIGINT, IN p_erro TEXT)
SQL SECURITY DEFINER
BEGIN
    UPDATE ml_potenciais_solicitacoes
       SET status='ERRO', concluida_em=NOW(), erro=NULLIF(p_erro,'')
     WHERE codsolicitacao=p_codsolicitacao;
    SELECT codsolicitacao,status,concluida_em,erro
      FROM ml_potenciais_solicitacoes
     WHERE codsolicitacao=p_codsolicitacao;
END$$
DELIMITER ;
