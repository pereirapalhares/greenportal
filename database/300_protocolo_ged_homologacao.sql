-- Migração 300 — Homologação de Fornecedores: regra do protocolo (como no legado) e anexos (GED) das abas do fornecedor.
-- Aplicado por scripts/aplicar-migracao-300-protocolo-ged-homologacao.php em cada banco de cliente. Comandos separados por "-- @@".
-- 1. vw_homologacao_fornecedor_auditoria_grid: + NECESSITA_AUDITORIA (do tipo).
-- 2. vw_homologacao_fornecedor_documentos: cor de "Aguardando vencimento do protocolo" (a view do painel, 298, herda a cor).
-- 3. sp_fo_fornecedor_documento_salvar: regra do protocolo.
-- 4. sp_fo_protocolo_vencidos_processar: volta para "Pendente com o fornecedor" na data de cobrança (serviço diário).
-- Os anexos das abas (certificados, FDS, equipamentos, plano de ação, evidência da auditoria) não precisam de banco: ficam no
-- GED do legado, E:\projetoGreen\GED\green_<empresa>\<pasta>\<código> (classes/GedStorageService.php).

-- @@
-- Auditoria de homologação do fornecedor (aba Auditoria): migração 300 acrescenta "Necessita auditoria?" do cadastro do tipo.
CREATE OR REPLACE VIEW `vw_homologacao_fornecedor_auditoria_grid` AS
SELECT x.codfornecedorxtipofornecedores AS CODFORNECEDORXTIPOFORNECEDORES, x.codfornecedor AS CODFORNECEDOR,
       x.codtipofornecedor AS CODTIPOFORNECEDOR, t.DSCTIPO AS DSCTIPO,
       CASE WHEN LOWER(TRIM(COALESCE(t.necessita_auditoria, ''))) IN ('sim', 's') THEN 'Sim'
            WHEN TRIM(COALESCE(t.necessita_auditoria, '')) = '' THEN NULL
            ELSE 'Não' END AS NECESSITA_AUDITORIA,
       -- Migração 318: data zerada do legado (0000-00-00) aparece em branco, como no legado.
       CASE WHEN x.datavalidade > '1900-01-01' THEN x.datavalidade END AS DATAVALIDADE, x.datareacao AS DATAREACAO, x.fornecedoraprovadoauditoria AS FORNECEDORAPROVADOAUDITORIA, x.nota AS NOTA
  FROM fo_fornecedoresxtipofornecedores x
  LEFT JOIN fo_tipofornecedores t ON t.codtipofornecedor = x.codtipofornecedor

-- @@
-- Documentos do fornecedor com descrição, nível, responsáveis, dias até o vencimento e cor.
-- Migração 300: "Aguardando vencimento do protocolo" = AMARELO até a data de cobrança (VERMELHO se a data já passou e o
-- serviço ainda não voltou o documento para "Pendente com o fornecedor").
CREATE OR REPLACE VIEW `vw_homologacao_fornecedor_documentos` AS
SELECT d.*,
       CASE
         WHEN d.SITUACAO IN ('NA', 'Não enviar ao fornecedor') THEN 'NEUTRO'
         WHEN d.SITUACAO = 'Reprovado' THEN 'VERMELHO'
         WHEN d.SITUACAO = 'Aguardando vencimento do protocolo' THEN
              IF(d.DATACOBRANCA_EMPROTOCOLO IS NOT NULL AND d.DATACOBRANCA_EMPROTOCOLO < CURDATE(), 'VERMELHO', 'AMARELO')
         WHEN d.QTDDIAS IS NOT NULL AND d.QTDDIAS < 0 THEN 'VERMELHO'
         WHEN d.SITUACAO = 'Pendente com o fornecedor' THEN 'VERMELHO'
         WHEN d.SITUACAO = 'Pendente com a empresa' THEN 'AMARELO'
         WHEN d.QTDDIAS IS NOT NULL AND d.QTDDIAS <= COALESCE(NULLIF(d.DATADEREACAO, 0), 30) THEN 'AMARELO'
         WHEN d.SITUACAO IN ('Aprovado', 'Aprovado sem validade', 'Ciente da regra') THEN 'VERDE'
         ELSE 'BRANCO'
       END AS COR
  FROM (
    SELECT x.codfornecedorxcriterios AS CODFORNECEDORXCRITERIOS, x.codfornecedor AS CODFORNECEDOR,
           x.codtipo AS CODTIPO, t.DSCTIPO AS DSCTIPO, x.codcriterio AS CODCRITERIO, c.DSCRITERIO AS DSCRITERIO,
           c.DSCRITERIO_EN AS DSCRITERIO_EN, COALESCE(tc.nivel_de_exigencia, '') AS NIVEL_DE_EXIGENCIA,
           COALESCE(x.enviadoodocumento, '') AS ENVIADOODOCUMENTO,
           x.codusuario AS CODUSUARIO, u1.nome AS RESPONSAVEL,
           x.codusuario_fornecedor AS CODUSUARIO_FORNECEDOR, u2.nome AS RESPONSAVEL_FORNECEDOR,
           IF(x.datadevalidade IS NULL OR x.datadevalidade < '1900-01-01', NULL, x.datadevalidade) AS DATADEVALIDADE,
           IF(x.datadevalidade IS NULL OR x.datadevalidade < '1900-01-01', NULL, DATEDIFF(x.datadevalidade, CURDATE())) AS QTDDIAS,
           COALESCE(x.emprotocolo, '') AS EMPROTOCOLO, x.datacobranca_emprotocolo AS DATACOBRANCA_EMPROTOCOLO,
           x.datadereacao AS DATADEREACAO, COALESCE(x.situacao, '') AS SITUACAO,
           COALESCE(x.incluidocontrato, '') AS INCLUIDOCONTRATO,
           x.observacao AS OBSERVACAO, x.observacao_do_fornecedor AS OBSERVACAO_DO_FORNECEDOR,
           f.RAZAOSOCIAL AS RAZAOSOCIAL, f.CODUNIDADE AS CODUNIDADE, f.ATIVADOFOR AS ATIVADOFOR
      FROM `fo_fornecedorxcriterios` x
      JOIN `fo_fornecedores` f ON f.codfornecedor = x.codfornecedor
      JOIN `fo_tipofornecedores` t ON t.codtipofornecedor = x.codtipo
      JOIN `fo_criteriofornecedores` c ON c.codcriteriofornecedor = x.codcriterio
      JOIN `fo_tipofornecedoresxcriteriofornecedores` tc ON tc.codtipofornecedor = x.codtipo AND tc.codcriteriofornecedor = x.codcriterio
      LEFT JOIN `usuarios` u1 ON u1.codusuario = x.codusuario
      LEFT JOIN `usuarios` u2 ON u2.codusuario = x.codusuario_fornecedor
     WHERE EXISTS (SELECT 1 FROM `fo_fornecedoresxtipofornecedores` ft WHERE ft.codfornecedor = x.codfornecedor AND ft.codtipofornecedor = x.codtipo)
  ) d

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_fornecedor_documento_salvar`

-- @@
-- Grava um documento. p_dados JSON com os campos (ausente = não altera; "" = limpa). p_escopo: EMPRESA (todos os campos)
-- ou FORNECEDOR (só validade, protocolo, dias de reação e observação do fornecedor, como no legado).
-- Migração 300 (regra do legado): marcar Em protocolo = Sim (antes não estava) ou Renovar coloca a situação em
-- "Aguardando vencimento do protocolo" com cobrança em 30 dias; tirar do protocolo com a situação aguardando volta para
-- "Pendente com o fornecedor". Na data de cobrança, sp_fo_protocolo_vencidos_processar (serviço do Agendador) faz a volta.
-- Campos: ENVIADOODOCUMENTO, CODUSUARIO, CODUSUARIO_FORNECEDOR, DATADEVALIDADE (AAAA-MM-DD), EMPROTOCOLO (S/N/R),
-- DATADEREACAO, SITUACAO, INCLUIDOCONTRATO, OBSERVACAO, OBSERVACAO_DO_FORNECEDOR.
CREATE PROCEDURE `sp_fo_fornecedor_documento_salvar`(IN p_cod BIGINT, IN p_dados JSON, IN p_usuario BIGINT, IN p_escopo VARCHAR(20))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_forn BIGINT;
    DECLARE v_env VARCHAR(3); DECLARE v_resp BIGINT; DECLARE v_respf BIGINT; DECLARE v_val DATE; DECLARE v_prot VARCHAR(1);
    DECLARE v_reac INT; DECLARE v_sit VARCHAR(100); DECLARE v_contr VARCHAR(3); DECLARE v_obs VARCHAR(500); DECLARE v_obsf VARCHAR(500);
    DECLARE v_cobr DATE; DECLARE v_txt TEXT; DECLARE v_prot0 VARCHAR(1); DECLARE v_protin VARCHAR(1) DEFAULT NULL;
    DECLARE v_emp TINYINT DEFAULT UPPER(COALESCE(p_escopo, 'EMPRESA')) <> 'FORNECEDOR';
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

    SELECT codfornecedor, enviadoodocumento, codusuario, codusuario_fornecedor,
           IF(datadevalidade < '1900-01-01', NULL, datadevalidade), emprotocolo, datadereacao, situacao, incluidocontrato, observacao,
           observacao_do_fornecedor, datacobranca_emprotocolo
      INTO v_forn, v_env, v_resp, v_respf, v_val, v_prot, v_reac, v_sit, v_contr, v_obs, v_obsf, v_cobr
      FROM `fo_fornecedorxcriterios` WHERE codfornecedorxcriterios = p_cod;
    IF v_forn IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Documento não encontrado.'; END IF;
    SET v_prot0 = UPPER(COALESCE(v_prot, ''));
    IF p_dados IS NULL OR JSON_TYPE(p_dados) <> 'OBJECT' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dados inválidos.'; END IF;

    -- Campos do fornecedor (os dois perfis)
    IF JSON_CONTAINS_PATH(p_dados, 'one', '$.DATADEVALIDADE') THEN
        SET v_txt = TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.DATADEVALIDADE')), ''));
        IF v_txt IN ('', 'null') THEN SET v_val = NULL;
        ELSEIF v_txt REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' AND STR_TO_DATE(v_txt, '%Y-%m-%d') IS NOT NULL THEN SET v_val = STR_TO_DATE(v_txt, '%Y-%m-%d');
        ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data de validade inválida.'; END IF;
    END IF;
    IF JSON_CONTAINS_PATH(p_dados, 'one', '$.DATADEREACAO') THEN
        SET v_txt = TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.DATADEREACAO')), ''));
        IF v_txt IN ('', 'null') THEN SET v_reac = NULL;
        ELSEIF v_txt REGEXP '^[0-9]{1,4}$' THEN SET v_reac = CAST(v_txt AS UNSIGNED);
        ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dias de reação inválidos (use um número de 0 a 9999).'; END IF;
    END IF;
    IF JSON_CONTAINS_PATH(p_dados, 'one', '$.EMPROTOCOLO') THEN
        SET v_txt = UPPER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.EMPROTOCOLO')), '')));
        IF v_txt NOT IN ('', 'S', 'N', 'R') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Em protocolo: use Sim, Não ou Renovar.'; END IF;
        SET v_protin = v_txt;
        IF v_txt = 'R' THEN SET v_prot = 'S', v_cobr = DATE_ADD(CURDATE(), INTERVAL 30 DAY);
        ELSEIF v_txt = 'S' THEN SET v_prot = 'S', v_cobr = COALESCE(v_cobr, DATE_ADD(CURDATE(), INTERVAL 30 DAY));
        ELSE SET v_prot = NULLIF(v_txt, ''), v_cobr = NULL; END IF;
    END IF;
    IF JSON_CONTAINS_PATH(p_dados, 'one', '$.OBSERVACAO_DO_FORNECEDOR') THEN
        SET v_obsf = NULLIF(LEFT(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.OBSERVACAO_DO_FORNECEDOR')), '')), 500), '');
    END IF;

    -- Campos da empresa
    IF v_emp THEN
        IF JSON_CONTAINS_PATH(p_dados, 'one', '$.ENVIADOODOCUMENTO') THEN
            SET v_env = NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.ENVIADOODOCUMENTO')), '')), '');
            IF v_env IS NOT NULL AND v_env NOT IN ('Sim', 'Não') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Enviado o documento: use Sim ou Não.'; END IF;
        END IF;
        IF JSON_CONTAINS_PATH(p_dados, 'one', '$.INCLUIDOCONTRATO') THEN
            SET v_contr = NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.INCLUIDOCONTRATO')), '')), '');
            IF v_contr IS NOT NULL AND v_contr NOT IN ('Sim', 'Não') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Incluído no contrato: use Sim ou Não.'; END IF;
        END IF;
        IF JSON_CONTAINS_PATH(p_dados, 'one', '$.SITUACAO') THEN
            SET v_sit = NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.SITUACAO')), '')), '');
            IF v_sit IS NOT NULL AND v_sit NOT IN ('Aprovado', 'Aprovado sem validade', 'Ciente da regra', 'Reprovado', 'Pendente com a empresa',
                'Pendente com o fornecedor', 'Não enviar ao fornecedor', 'NA', 'Aguardando vencimento do protocolo') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Situação inválida.';
            END IF;
        END IF;
        IF JSON_CONTAINS_PATH(p_dados, 'one', '$.OBSERVACAO') THEN
            SET v_obs = NULLIF(LEFT(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.OBSERVACAO')), '')), 500), '');
        END IF;
        IF JSON_CONTAINS_PATH(p_dados, 'one', '$.CODUSUARIO') THEN
            SET v_txt = TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.CODUSUARIO')), ''));
            SET v_resp = IF(v_txt REGEXP '^[0-9]+$' AND v_txt <> '0', CAST(v_txt AS UNSIGNED), NULL);
            IF v_resp IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `usuarios` WHERE codusuario = v_resp) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Responsável da empresa não encontrado.';
            END IF;
        END IF;
        IF JSON_CONTAINS_PATH(p_dados, 'one', '$.CODUSUARIO_FORNECEDOR') THEN
            SET v_txt = TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_dados, '$.CODUSUARIO_FORNECEDOR')), ''));
            SET v_respf = IF(v_txt REGEXP '^[0-9]+$' AND v_txt <> '0', CAST(v_txt AS UNSIGNED), NULL);
            IF v_respf IS NOT NULL AND v_respf <> 99999 AND NOT EXISTS (SELECT 1 FROM `fo_fornecedorxusuario` WHERE CODFORNECEDOR = v_forn AND CODUSUARIO = v_respf) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O responsável do fornecedor precisa estar ligado ao fornecedor (Fornecedor x Usuários).';
            END IF;
        END IF;
        IF v_sit = 'Reprovado' AND TRIM(COALESCE(v_obs, '')) = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe na observação da empresa o motivo da reprovação do documento.';
        END IF;
    END IF;

    -- Regra do protocolo (migração 300)
    IF v_protin = 'R' OR (v_protin = 'S' AND v_prot0 <> 'S') THEN
        SET v_sit = 'Aguardando vencimento do protocolo';
    ELSEIF COALESCE(v_prot, '') <> 'S' AND v_sit = 'Aguardando vencimento do protocolo' THEN
        SET v_sit = 'Pendente com o fornecedor';
    END IF;

    START TRANSACTION;
    UPDATE `fo_fornecedorxcriterios`
       SET enviadoodocumento = v_env, codusuario = v_resp, codusuario_fornecedor = v_respf, datadevalidade = v_val, emprotocolo = v_prot,
           datacobranca_emprotocolo = v_cobr, datadereacao = v_reac, situacao = v_sit, incluidocontrato = v_contr, observacao = v_obs,
           observacao_do_fornecedor = v_obsf
     WHERE codfornecedorxcriterios = p_cod;
    CALL sp_fo_fornecedor_documento_historico_gravar(p_cod, p_usuario);
    COMMIT;
    SELECT * FROM `vw_homologacao_fornecedor_documentos` WHERE CODFORNECEDORXCRITERIOS = p_cod;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_protocolo_vencidos_processar`

-- @@
-- Serviço do protocolo (scripts/homologacao-protocolo-worker.php, Agendador do Windows, diário): documentos em
-- "Aguardando vencimento do protocolo" cuja data de cobrança chegou voltam para "Pendente com o fornecedor"; o protocolo é
-- encerrado (Em protocolo = Não, sem data de cobrança) e o histórico guarda a alteração (p_usuario; 0 = serviço).
-- Sem data de cobrança o documento não é alterado (a migração lista esses casos). p_aplicar = 0 só lista. Retorna os documentos (com responsáveis) para o serviço avisar em Home › Alertas.
CREATE PROCEDURE `sp_fo_protocolo_vencidos_processar`(IN p_usuario BIGINT, IN p_aplicar TINYINT)
SQL SECURITY INVOKER
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; DROP TEMPORARY TABLE IF EXISTS tmp_fo_protocolo_vencidos; RESIGNAL; END;
    DROP TEMPORARY TABLE IF EXISTS tmp_fo_protocolo_vencidos;
    CREATE TEMPORARY TABLE tmp_fo_protocolo_vencidos (codigo BIGINT PRIMARY KEY, cobranca DATE NULL);
    INSERT INTO tmp_fo_protocolo_vencidos (codigo, cobranca)
    SELECT x.codfornecedorxcriterios, x.datacobranca_emprotocolo
      FROM `fo_fornecedorxcriterios` x
     WHERE x.situacao = 'Aguardando vencimento do protocolo'
       AND x.datacobranca_emprotocolo IS NOT NULL AND x.datacobranca_emprotocolo <= CURDATE();
    IF COALESCE(p_aplicar, 0) = 1 THEN
        START TRANSACTION;
        UPDATE `fo_fornecedorxcriterios` x JOIN tmp_fo_protocolo_vencidos t ON t.codigo = x.codfornecedorxcriterios
           SET x.situacao = 'Pendente com o fornecedor', x.emprotocolo = 'N', x.datacobranca_emprotocolo = NULL;
        INSERT INTO `fo_fornecedorxcriterios_historico` (CODUSUARIOALTERACAO, DATAALTERACAO, codfornecedorxcriterios, codfornecedor, codtipo, codcriterio,
            enviadoodocumento, datadevalidade, datadereacao, situacao, incluidocontrato, codusuario, codusuario_fornecedor, observacao,
            observacao_do_fornecedor, emprotocolo, datacobranca_emprotocolo)
        SELECT COALESCE(p_usuario, 0), NOW(), x.codfornecedorxcriterios, x.codfornecedor, x.codtipo, x.codcriterio, x.enviadoodocumento, x.datadevalidade,
               x.datadereacao, x.situacao, x.incluidocontrato, x.codusuario, x.codusuario_fornecedor, x.observacao, x.observacao_do_fornecedor,
               x.emprotocolo, x.datacobranca_emprotocolo
          FROM `fo_fornecedorxcriterios` x JOIN tmp_fo_protocolo_vencidos t ON t.codigo = x.codfornecedorxcriterios;
        COMMIT;
    END IF;
    SELECT t.codigo AS CODFORNECEDORXCRITERIOS, t.cobranca AS DATACOBRANCA, x.codfornecedor AS CODFORNECEDOR, f.RAZAOSOCIAL,
           TRIM(COALESCE(f.ATIVADOFOR, '')) = 'Sim' AS ATIVO, CONCAT(COALESCE(tf.DSCTIPO, ''), ' / ', COALESCE(c.DSCRITERIO, '')) AS DOCUMENTO,
           NULLIF(x.codusuario, 0) AS CODUSUARIO_EMPRESA, NULLIF(NULLIF(x.codusuario_fornecedor, 0), 99999) AS CODUSUARIO_FORNECEDOR
      FROM tmp_fo_protocolo_vencidos t
      JOIN `fo_fornecedorxcriterios` x ON x.codfornecedorxcriterios = t.codigo
      JOIN `fo_fornecedores` f ON f.codfornecedor = x.codfornecedor
      LEFT JOIN `fo_tipofornecedores` tf ON tf.codtipofornecedor = x.codtipo
      LEFT JOIN `fo_criteriofornecedores` c ON c.codcriteriofornecedor = x.codcriterio
     ORDER BY f.RAZAOSOCIAL, t.codigo;
    DROP TEMPORARY TABLE IF EXISTS tmp_fo_protocolo_vencidos;
END
