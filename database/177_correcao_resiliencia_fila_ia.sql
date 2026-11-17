/* Correção idempotente da fila de IA: usuário obrigatório, alerta garantido e erro sem efeito cascata. */

/* Aplicar em cada banco tenant: gmodelo_dbp, gmodelo2_dbp e gatlas_dbp. */

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

DROP PROCEDURE IF EXISTS sp_ia_potencial_solicitar_com_alerta;
DELIMITER $$
CREATE PROCEDURE sp_ia_potencial_solicitar_com_alerta(IN p_codusuario BIGINT, IN p_idioma CHAR(2), IN p_contexto_json JSON, IN p_link_status VARCHAR(500))
SQL SECURITY DEFINER
BEGIN
    DECLARE v_id BIGINT DEFAULT NULL;
    DECLARE v_link VARCHAR(500);
    IF p_codusuario IS NULL OR p_codusuario <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Solicitação de IA sem usuário autenticado.';
    END IF;
    SELECT codsolicitacao INTO v_id
      FROM ml_potenciais_solicitacoes
     WHERE codusuario=p_codusuario
       AND status IN ('PENDENTE','PROCESSANDO')
       AND JSON_UNQUOTE(JSON_EXTRACT(contexto_json,'$.CODLEGISLACAO'))=JSON_UNQUOTE(JSON_EXTRACT(p_contexto_json,'$.CODLEGISLACAO'))
     ORDER BY codsolicitacao DESC LIMIT 1;
    IF v_id IS NULL THEN
        INSERT INTO ml_potenciais_solicitacoes(codusuario,idioma,contexto_json)
        VALUES(p_codusuario,IF(p_idioma IN ('pt','en','es'),p_idioma,'pt'),p_contexto_json);
        SET v_id=LAST_INSERT_ID();
    END IF;
    SET v_link=REPLACE(p_link_status,'{CODSOLICITACAO}',v_id);
    INSERT INTO alertas(codusuario,titulo,mensagem,link_status,lido)
    SELECT p_codusuario,
           CASE p_idioma WHEN 'en' THEN 'AI analysis requested' WHEN 'es' THEN 'Analisis de IA solicitada' ELSE 'Analise de IA solicitada' END,
           CASE p_idioma WHEN 'en' THEN 'The analysis was queued and will be processed in the background.' WHEN 'es' THEN 'El analisis fue puesto en cola y sera procesado en segundo plano.' ELSE 'A analise foi colocada na fila e sera processada em segundo plano.' END,
           v_link,0
     WHERE NOT EXISTS (SELECT 1 FROM alertas WHERE codusuario=p_codusuario AND link_status=v_link);
    SELECT v_id AS codsolicitacao;
END$$
DELIMITER ;
