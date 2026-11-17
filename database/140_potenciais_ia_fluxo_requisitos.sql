USE `gmodelo_dbp`;
SET NAMES utf8mb4;

SET @add_link_status = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='alertas' AND COLUMN_NAME='link_status')=0,
    'ALTER TABLE alertas ADD COLUMN link_status VARCHAR(500) NULL AFTER link', 'SELECT 1');
PREPARE stmt FROM @add_link_status; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP PROCEDURE IF EXISTS sp_ia_potencial_solicitar_com_alerta;
DELIMITER $$
CREATE PROCEDURE sp_ia_potencial_solicitar_com_alerta(IN p_codusuario BIGINT, IN p_idioma CHAR(2), IN p_contexto_json JSON, IN p_link_status VARCHAR(500))
SQL SECURITY DEFINER
BEGIN
    DECLARE v_id BIGINT DEFAULT NULL;
    SELECT codsolicitacao INTO v_id FROM ml_potenciais_solicitacoes WHERE codusuario=p_codusuario AND status IN ('PENDENTE','PROCESSANDO') AND JSON_UNQUOTE(JSON_EXTRACT(contexto_json,'$.CODLEGISLACAO'))=JSON_UNQUOTE(JSON_EXTRACT(p_contexto_json,'$.CODLEGISLACAO')) ORDER BY codsolicitacao DESC LIMIT 1;
    IF v_id IS NULL THEN
        INSERT INTO ml_potenciais_solicitacoes(codusuario,idioma,contexto_json) VALUES(p_codusuario,IF(p_idioma IN ('pt','en','es'),p_idioma,'pt'),p_contexto_json);
        SET v_id=LAST_INSERT_ID();
        INSERT INTO alertas(codusuario,titulo,mensagem,link_status,lido) VALUES(p_codusuario,
          CASE p_idioma WHEN 'en' THEN 'AI analysis requested' WHEN 'es' THEN 'Análisis de IA solicitada' ELSE 'Análise de IA solicitada' END,
          CASE p_idioma WHEN 'en' THEN 'The analysis was queued and will be processed in the background.' WHEN 'es' THEN 'El análisis fue puesto en cola y será procesado en segundo plano.' ELSE 'A análise foi colocada na fila e será processada em segundo plano.' END,
          REPLACE(p_link_status,'{CODSOLICITACAO}',v_id),0);
    END IF;
    SELECT v_id AS codsolicitacao;
END$$

DROP PROCEDURE IF EXISTS sp_ia_potencial_concluir;
CREATE PROCEDURE sp_ia_potencial_concluir(IN p_codsolicitacao BIGINT, IN p_status VARCHAR(20), IN p_potenciais_json JSON, IN p_erro TEXT)
SQL SECURITY DEFINER
BEGIN
    DECLARE v_usuario BIGINT; DECLARE v_idioma CHAR(2); DECLARE v_fim DATETIME;
    IF p_status='CONCLUIDA' THEN
        INSERT INTO ml_potenciais(codsolicitacao,codrequisitolegal,tipo_potencial,titulo,descricao,justificativa_ia,confianca_ia,idioma_conteudo)
        SELECT p_codsolicitacao,x.codrequisitolegal,x.tipo_potencial,x.titulo,x.descricao,x.justificativa_ia,x.confianca_ia,IF(x.idioma_conteudo IN ('pt','en','es'),x.idioma_conteudo,'pt')
        FROM JSON_TABLE(p_potenciais_json,'$.potenciais[*]' COLUMNS(codrequisitolegal BIGINT PATH '$.codrequisitolegal' NULL ON ERROR,tipo_potencial VARCHAR(30) PATH '$.tipo_potencial',titulo TEXT PATH '$.titulo',descricao TEXT PATH '$.descricao' NULL ON ERROR,justificativa_ia TEXT PATH '$.justificativa_ia' NULL ON ERROR,confianca_ia DECIMAL(5,2) PATH '$.confianca_ia' NULL ON ERROR,idioma_conteudo CHAR(2) PATH '$.idioma_conteudo' NULL ON ERROR)) AS x;
    END IF;
    UPDATE ml_potenciais_solicitacoes SET status=p_status,concluida_em=NOW(),erro=NULLIF(p_erro,'') WHERE codsolicitacao=p_codsolicitacao;
    SELECT codusuario,idioma,concluida_em INTO v_usuario,v_idioma,v_fim FROM ml_potenciais_solicitacoes WHERE codsolicitacao=p_codsolicitacao;
    INSERT INTO alertas(codusuario,titulo,mensagem,link,link_status,lido) VALUES(v_usuario,
      CASE v_idioma WHEN 'en' THEN IF(p_status='CONCLUIDA','AI analysis completed','AI analysis failed') WHEN 'es' THEN IF(p_status='CONCLUIDA','Análisis de IA concluido','Análisis de IA fallido') ELSE IF(p_status='CONCLUIDA','Análise de IA concluída','Falha na análise de IA') END,
      CASE v_idioma WHEN 'en' THEN IF(p_status='CONCLUIDA',CONCAT('The analysis was completed at ',DATE_FORMAT(v_fim,'%d/%m/%Y %H:%i'),'.'),CONCAT('The analysis could not be completed: ',COALESCE(p_erro,''))) WHEN 'es' THEN IF(p_status='CONCLUIDA',CONCAT('El análisis finalizó a las ',DATE_FORMAT(v_fim,'%d/%m/%Y %H:%i'),'.'),CONCAT('El análisis no pudo completarse: ',COALESCE(p_erro,''))) ELSE IF(p_status='CONCLUIDA',CONCAT('A análise foi concluída em ',DATE_FORMAT(v_fim,'%d/%m/%Y %H:%i'),'.'),CONCAT('A análise não pôde ser concluída: ',COALESCE(p_erro,''))) END,
      IF(p_status='CONCLUIDA',CONCAT('/principal/ia_potenciais_resultado.php?codsolicitacao=',p_codsolicitacao),NULL),CONCAT('/principal/ia_potenciais_resultado.php?codsolicitacao=',p_codsolicitacao),0);
    SELECT codsolicitacao,status,concluida_em,erro FROM ml_potenciais_solicitacoes WHERE codsolicitacao=p_codsolicitacao;
END$$
DELIMITER ;

UPDATE menus SET ativo=0 WHERE codigo='MODULOLEGAL_POTENCIAIS';
