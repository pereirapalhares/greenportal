USE `gmodelo_dbp`;
DROP PROCEDURE IF EXISTS sp_ia_prompt_atualizar;
DELIMITER $$
CREATE PROCEDURE sp_ia_prompt_atualizar(IN p_codprompt BIGINT, IN p_prompt_pt LONGTEXT, IN p_prompt_en LONGTEXT, IN p_prompt_es LONGTEXT, IN p_usuario BIGINT)
SQL SECURITY DEFINER
BEGIN
    DECLARE v_versao INT; DECLARE v_pt LONGTEXT; DECLARE v_en LONGTEXT; DECLARE v_es LONGTEXT;
    IF COALESCE(TRIM(p_prompt_pt),'')='' OR COALESCE(TRIM(p_prompt_en),'')='' OR COALESCE(TRIM(p_prompt_es),'')='' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Os prompts PT, EN e ES sao obrigatorios.'; END IF;
    IF CHAR_LENGTH(p_prompt_pt)<80 OR CHAR_LENGTH(p_prompt_en)<80 OR CHAR_LENGTH(p_prompt_es)<80 OR LOWER(p_prompt_pt) NOT LIKE '%potenciais%' OR LOWER(p_prompt_en) NOT LIKE '%potenciais%' OR LOWER(p_prompt_es) NOT LIKE '%potenciais%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Os prompts devem manter o contrato de resposta com potenciais.'; END IF;
    SELECT versao,prompt_pt,prompt_en,prompt_es INTO v_versao,v_pt,v_en,v_es FROM ia_prompts WHERE codprompt=p_codprompt FOR UPDATE;
    IF v_versao IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Prompt nao encontrado.'; END IF;
    IF v_pt=p_prompt_pt AND v_en=p_prompt_en AND v_es=p_prompt_es THEN SELECT p_codprompt AS codprompt,v_versao AS versao; ELSE
        INSERT INTO ia_prompts_historico(codprompt,versao_anterior,versao_nova,prompt_pt_anterior,prompt_en_anterior,prompt_es_anterior,prompt_pt_novo,prompt_en_novo,prompt_es_novo,alterado_por) VALUES(p_codprompt,v_versao,v_versao+1,v_pt,v_en,v_es,p_prompt_pt,p_prompt_en,p_prompt_es,p_usuario);
        UPDATE ia_prompts SET prompt_pt=p_prompt_pt,prompt_en=p_prompt_en,prompt_es=p_prompt_es,versao=v_versao+1,alterado_por=p_usuario,alterado_em=NOW() WHERE codprompt=p_codprompt;
        SELECT p_codprompt AS codprompt,v_versao+1 AS versao;
    END IF;
END$$
DELIMITER ;
