USE `gmodelo_dbp`;
SET NAMES utf8mb4;

/* Corrige registros gravados com perda de acentuacao na primeira versao. */
UPDATE alertas
   SET titulo = CONVERT(0x416EC3A16C69736520646520494120736F6C69636974616461 USING utf8mb4),
       mensagem = CONVERT(0x4120616EC3A16C69736520666F6920636F6C6F63616461206E612066696C61206520736572C3A12070726F6365737361646120656D20736567756E646F20706C616E6F2E USING utf8mb4)
 WHERE titulo LIKE 'An%lise de IA solicitada';

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
          CASE p_idioma WHEN 'en' THEN 'AI analysis requested' WHEN 'es' THEN CONVERT(0x416EC3A16C6C6973697320646520494120736F6C69636974616461 USING utf8mb4) ELSE CONVERT(0x416EC3A16C69736520646520494120736F6C69636974616461 USING utf8mb4) END,
          CASE p_idioma WHEN 'en' THEN 'The analysis was queued and will be processed in the background.' WHEN 'es' THEN CONVERT(0x456C20616EC3A16C69736973206675652070756573746F20656E20636F6C61207920736572C3A12070726F63657361646F20656E20736567756E646F20706C616E6F2E USING utf8mb4) ELSE CONVERT(0x4120616EC3A16C69736520666F6920636F6C6F63616461206E612066696C61206520736572C3A12070726F6365737361646120656D20736567756E646F20706C616E6F2E USING utf8mb4) END,
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
        SELECT p_codsolicitacao,x.codrequisitolegal,x.tipo_potencial,x.titulo,x.descricao,x.justificativa_ia,x.confianca_ia,IF(x.idioma_conteudo IN ('pt','en','es'),x.idioma_conteudo,'pt') FROM JSON_TABLE(p_potenciais_json,'$.potenciais[*]' COLUMNS(codrequisitolegal BIGINT PATH '$.codrequisitolegal' NULL ON ERROR,tipo_potencial VARCHAR(30) PATH '$.tipo_potencial',titulo TEXT PATH '$.titulo',descricao TEXT PATH '$.descricao' NULL ON ERROR,justificativa_ia TEXT PATH '$.justificativa_ia' NULL ON ERROR,confianca_ia DECIMAL(5,2) PATH '$.confianca_ia' NULL ON ERROR,idioma_conteudo CHAR(2) PATH '$.idioma_conteudo' NULL ON ERROR)) AS x;
    END IF;
    UPDATE ml_potenciais_solicitacoes SET status=p_status,concluida_em=NOW(),erro=NULLIF(p_erro,'') WHERE codsolicitacao=p_codsolicitacao;
    SELECT codusuario,idioma,concluida_em INTO v_usuario,v_idioma,v_fim FROM ml_potenciais_solicitacoes WHERE codsolicitacao=p_codsolicitacao;
    INSERT INTO alertas(codusuario,titulo,mensagem,link,link_status,lido) VALUES(v_usuario,
      CASE v_idioma WHEN 'en' THEN IF(p_status='CONCLUIDA','AI analysis completed','AI analysis failed') WHEN 'es' THEN IF(p_status='CONCLUIDA',CONVERT(0x416EC3A16C6973697320646520494120636F6E636C7569646F USING utf8mb4),CONVERT(0x416EC3A16C697369732064652049412066616C6C69646F USING utf8mb4)) ELSE IF(p_status='CONCLUIDA',CONVERT(0x416EC3A16C69736520646520494120636F6E636C75C3AD6461 USING utf8mb4),CONVERT(0x46616C6861206E6120616EC3A16C697365206465204941 USING utf8mb4)) END,
      CASE v_idioma WHEN 'en' THEN IF(p_status='CONCLUIDA',CONCAT('The analysis was completed at ',DATE_FORMAT(v_fim,'%d/%m/%Y %H:%i'),'.'),CONCAT('The analysis could not be completed: ',COALESCE(p_erro,''))) WHEN 'es' THEN IF(p_status='CONCLUIDA',CONCAT(CONVERT(0x456C20616EC3A16C697369732066696E616C697AC3B320 USING utf8mb4),DATE_FORMAT(v_fim,'%d/%m/%Y %H:%i'),'.'),CONCAT(CONVERT(0x456C20616EC3A16C69736973206E6F207075646F20636F6D706C6574617273653A20 USING utf8mb4),COALESCE(p_erro,''))) ELSE IF(p_status='CONCLUIDA',CONCAT(CONVERT(0x4120616EC3A16C69736520666F6920636F6E636C75C3AD6461 USING utf8mb4),' em ',DATE_FORMAT(v_fim,'%d/%m/%Y %H:%i'),'.'),CONCAT(CONVERT(0x4120616EC3A16C697365206EC3A36F20707564652073657220636F6E636C75C3AD64613A20 USING utf8mb4),COALESCE(p_erro,''))) END,
      IF(p_status='CONCLUIDA',CONCAT('/principal/ia_potenciais_resultado.php?codsolicitacao=',p_codsolicitacao),NULL),CONCAT('/principal/ia_potenciais_resultado.php?codsolicitacao=',p_codsolicitacao),0);
    SELECT codsolicitacao,status,concluida_em,erro FROM ml_potenciais_solicitacoes WHERE codsolicitacao=p_codsolicitacao;
END$$
DELIMITER ;
