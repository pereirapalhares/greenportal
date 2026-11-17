USE `gmodelo_dbp`;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ia_prompts (
    codprompt BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(80) NOT NULL,
    nome_pt VARCHAR(180) NOT NULL,
    nome_en VARCHAR(180) NOT NULL,
    nome_es VARCHAR(180) NOT NULL,
    prompt_pt LONGTEXT NOT NULL,
    prompt_en LONGTEXT NOT NULL,
    prompt_es LONGTEXT NOT NULL,
    versao INT NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    alterado_por BIGINT UNSIGNED NULL,
    alterado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (codprompt), UNIQUE KEY uk_ia_prompts_codigo (codigo),
    CONSTRAINT fk_ia_prompts_usuario FOREIGN KEY (alterado_por) REFERENCES usuarios(codusuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ia_prompts_historico (
    codhistorico BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codprompt BIGINT UNSIGNED NOT NULL,
    versao_anterior INT NOT NULL,
    versao_nova INT NOT NULL,
    prompt_pt_anterior LONGTEXT NOT NULL,
    prompt_en_anterior LONGTEXT NOT NULL,
    prompt_es_anterior LONGTEXT NOT NULL,
    prompt_pt_novo LONGTEXT NOT NULL,
    prompt_en_novo LONGTEXT NOT NULL,
    prompt_es_novo LONGTEXT NOT NULL,
    alterado_por BIGINT UNSIGNED NOT NULL,
    alterado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (codhistorico), KEY ix_ia_prompts_historico_prompt (codprompt, alterado_em),
    CONSTRAINT fk_ia_prompts_historico_prompt FOREIGN KEY (codprompt) REFERENCES ia_prompts(codprompt),
    CONSTRAINT fk_ia_prompts_historico_usuario FOREIGN KEY (alterado_por) REFERENCES usuarios(codusuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO ia_prompts (codigo,nome_pt,nome_en,nome_es,prompt_pt,prompt_en,prompt_es)
SELECT 'ML_REQUISITO_LEGAL','Analise de requisito legal','Legal requirement analysis','Analisis de requisito legal',
'Voce e um especialista em gestao legal, meio ambiente, saude, seguranca e qualidade. Analise o requisito legal recebido e identifique possiveis acoes de gestao. Responda exclusivamente em JSON valido, sem Markdown, no formato {"potenciais":[{"codrequisitolegal":null,"tipo_potencial":"CONTROLE|INFLUENCIA|LICENCA|MONITORAMENTO|TREINAMENTO|INFRAESTRUTURA","titulo":"Titulo objetivo","descricao":"Descricao da acao","justificativa_ia":"Justificativa baseada no requisito","confianca_ia":0,"idioma_conteudo":"pt"}]}. Nao invente codigos ou informacoes. Use lista vazia quando nao houver sugestao segura. Nao aprove nem rejeite nada.',
'You are a specialist in legal management, environment, health, safety and quality. Analyze the legal requirement and identify possible management actions. Respond exclusively with valid JSON, without Markdown, using {"potenciais":[{"codrequisitolegal":null,"tipo_potencial":"CONTROLE|INFLUENCIA|LICENCA|MONITORAMENTO|TREINAMENTO|INFRAESTRUTURA","titulo":"Objective title","descricao":"Suggested action","justificativa_ia":"Justification based on the requirement","confianca_ia":0,"idioma_conteudo":"en"}]}. Do not invent codes or information. Use an empty list when there is no safe suggestion. Do not approve or reject anything.',
'Eres especialista en gestion legal, medio ambiente, salud, seguridad y calidad. Analiza el requisito legal e identifica posibles acciones de gestion. Responde exclusivamente con JSON valido, sin Markdown, usando {"potenciais":[{"codrequisitolegal":null,"tipo_potencial":"CONTROLE|INFLUENCIA|LICENCA|MONITORAMENTO|TREINAMENTO|INFRAESTRUTURA","titulo":"Titulo objetivo","descricao":"Accion sugerida","justificativa_ia":"Justificacion basada en el requisito","confianca_ia":0,"idioma_conteudo":"es"}]}. No inventes codigos ni informacion. Usa una lista vacia cuando no exista una sugerencia segura. No apruebes ni rechaces nada.'
WHERE NOT EXISTS (SELECT 1 FROM ia_prompts WHERE codigo='ML_REQUISITO_LEGAL');

DROP VIEW IF EXISTS vw_ia_prompts_grid;
CREATE VIEW vw_ia_prompts_grid AS
SELECT codprompt,codigo,nome_pt,nome_en,nome_es,prompt_pt,prompt_en,prompt_es,versao,ativo,alterado_por,alterado_em FROM ia_prompts;
DROP VIEW IF EXISTS vw_ia_prompts_historico_grid;
CREATE VIEW vw_ia_prompts_historico_grid AS
SELECT h.codhistorico,h.codprompt,p.codigo,h.versao_anterior,h.versao_nova,h.alterado_por,h.alterado_em
FROM ia_prompts_historico h JOIN ia_prompts p ON p.codprompt=h.codprompt;

DROP PROCEDURE IF EXISTS sp_ia_prompt_obter;
DELIMITER $$
CREATE PROCEDURE sp_ia_prompt_obter(IN p_codigo VARCHAR(80), IN p_idioma CHAR(2))
SQL SECURITY DEFINER
BEGIN
    SELECT codigo,versao,IF(p_idioma='en',prompt_en,IF(p_idioma='es',prompt_es,prompt_pt)) AS prompt,IF(p_idioma IN ('pt','en','es'),p_idioma,'pt') AS idioma
    FROM ia_prompts WHERE codigo=p_codigo AND ativo=1 LIMIT 1;
END$$

DROP PROCEDURE IF EXISTS sp_ia_prompt_atualizar;
CREATE PROCEDURE sp_ia_prompt_atualizar(IN p_codprompt BIGINT, IN p_prompt_pt LONGTEXT, IN p_prompt_en LONGTEXT, IN p_prompt_es LONGTEXT, IN p_usuario BIGINT)
SQL SECURITY DEFINER
BEGIN
    DECLARE v_versao INT; DECLARE v_pt LONGTEXT; DECLARE v_en LONGTEXT; DECLARE v_es LONGTEXT;
    IF COALESCE(TRIM(p_prompt_pt),'')='' OR COALESCE(TRIM(p_prompt_en),'')='' OR COALESCE(TRIM(p_prompt_es),'')='' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Os prompts PT, EN e ES sao obrigatorios.'; END IF;
    SELECT versao,prompt_pt,prompt_en,prompt_es INTO v_versao,v_pt,v_en,v_es FROM ia_prompts WHERE codprompt=p_codprompt FOR UPDATE;
    IF v_versao IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Prompt nao encontrado.'; END IF;
    IF v_pt=p_prompt_pt AND v_en=p_prompt_en AND v_es=p_prompt_es THEN SELECT p_codprompt AS codprompt,v_versao AS versao; ELSE
        INSERT INTO ia_prompts_historico(codprompt,versao_anterior,versao_nova,prompt_pt_anterior,prompt_en_anterior,prompt_es_anterior,prompt_pt_novo,prompt_en_novo,prompt_es_novo,alterado_por)
        VALUES(p_codprompt,v_versao,v_versao+1,v_pt,v_en,v_es,p_prompt_pt,p_prompt_en,p_prompt_es,p_usuario);
        UPDATE ia_prompts SET prompt_pt=p_prompt_pt,prompt_en=p_prompt_en,prompt_es=p_prompt_es,versao=v_versao+1,alterado_por=p_usuario,alterado_em=NOW() WHERE codprompt=p_codprompt;
        SELECT p_codprompt AS codprompt,v_versao+1 AS versao;
    END IF;
END$$
DELIMITER ;

INSERT INTO menus (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT m.codmenu,'SETUP','SETUP_IA_PROMPT','Prompts de IA','Prompts de IA','AI prompts','Prompts de IA','/principal/setup_iaprompt.php',2,8,1
FROM menus m WHERE m.codigo='SETUP' AND NOT EXISTS (SELECT 1 FROM menus x WHERE x.codigo='SETUP_IA_PROMPT');
INSERT INTO permissoes(codperfil,codmenu,pode_incluir,pode_visualizar,pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf)
SELECT p.codperfil,m.codmenu,0,IF(p.codigo='ADMINISTRADOR',1,0),IF(p.codigo='ADMINISTRADOR',1,0),0,0,0
FROM perfis p CROSS JOIN menus m WHERE m.codigo='SETUP_IA_PROMPT' AND NOT EXISTS (SELECT 1 FROM permissoes x WHERE x.codperfil=p.codperfil AND x.codmenu=m.codmenu);
UPDATE permissoes x JOIN menus m ON m.codmenu=x.codmenu JOIN perfis p ON p.codperfil=x.codperfil SET x.pode_visualizar=IF(p.codigo='ADMINISTRADOR',1,0),x.pode_editar=IF(p.codigo='ADMINISTRADOR',1,0),x.pode_incluir=0,x.pode_excluir=0 WHERE m.codigo='SETUP_IA_PROMPT';
