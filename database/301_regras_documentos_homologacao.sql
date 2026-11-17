-- Migração 301 — Homologação de Fornecedores: regras automáticas da situação dos documentos (fornecedor e funcionário),
-- adaptadas do serviço do legado E:\projetoGreen\processos\enviadoodocumento\enviadoodocumento.php (+ homologacao.php).
-- Aplicado por scripts/aplicar-migracao-301-regras-documentos-homologacao.php em cada banco de cliente. Comandos separados por "-- @@".
-- Quem executa: scripts/homologacao-documentos-worker.php (Agendador do Windows). O serviço conta os arquivos de cada documento no
-- GED (E:\projetoGreen\GED\green_<empresa>\fo_fornecedorxcriterios\<código> e \fo_funcionarioxtiposxcriterios\<código>) e chama
-- sp_fo_regras_documentos_aplicar com essas contagens. O protocolo continua com o serviço da migração 300.
--
-- Adaptações em relação ao legado:
--  * o motivo vai para o log (fo_regras_documentos_log), para o histórico do documento e para o alerta do responsável, em vez de ser
--    somado ao texto das observações; cada regra é decidida pelo estado atual (não por "o texto já está na observação");
--  * Aprovado sem validade, Ciente da regra, NA, "Não enviar ao fornecedor" e documentos em protocolo em vigor (cobrança de hoje em
--    diante, ou sem data) não mudam; protocolo com a cobrança já vencida (carga do legado, que deixava Em protocolo = Sim) segue as regras;
--  * Reprovado continua Reprovado (já é pendência do fornecedor) até o fornecedor agir;
--  * "fornecedor agiu" = alteração feita por usuário do fornecedor ou arquivo novo no GED depois da última ação da empresa;
--  * funcionário: documento vencido com arquivo passa a "Não atende" (no legado a condição aceitava qualquer data);
--  * cada regra pode ser desligada por empresa (fo_regras_documentos.ativo);
--  * migração 349: documento com arquivo de risco ALTO de adulteração sem ciência (vw_doc_adult_pendentes) vai para "Pendente com a
--    empresa" (funcionário: Atende = Não) em vez de continuar aprovado ou ser aprovado pela regra (FORN_ADULTERACAO / FUNC_ADULTERACAO).
--    Sem a 349 no banco, essas duas regras não fazem nada.
--  * migração 350: fornecedor bloqueado no ranking (fo_fornecedor_bloqueios) — as regras não aprovam documento de funcionário
--    (FUNC_ATENDE / FUNC_SEM_RESPONSAVEL não passam o "Atende" para Sim); o que já estava aprovado continua como está.

-- @@
CREATE TABLE IF NOT EXISTS `fo_regras_documentos` (
    `codregra` VARCHAR(40) NOT NULL,
    `escopo` VARCHAR(20) NOT NULL COMMENT 'FORNECEDOR ou FUNCIONARIO',
    `ordem` INT NOT NULL DEFAULT 0,
    `descricao` VARCHAR(400) NOT NULL,
    `resultado` VARCHAR(200) NOT NULL,
    `origem_legado` VARCHAR(300) NULL,
    `ativo` TINYINT NOT NULL DEFAULT 1,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`codregra`)
) COMMENT 'Regras automáticas da situação dos documentos da homologação (migração 301)'

-- @@
-- INSERT IGNORE: preserva o "ativo" que a empresa já escolheu.
INSERT IGNORE INTO `fo_regras_documentos` (codregra, escopo, ordem, descricao, resultado, origem_legado) VALUES
('FORN_ADULTERACAO', 'FORNECEDOR', 15, 'Arquivo com risco alto de adulteração sem ciência (migração 349) num documento aprovado, ciente ou sem situação.', 'Pendente com a empresa', NULL),
('FUNC_ADULTERACAO', 'FUNCIONARIO', 105, 'Arquivo com risco alto de adulteração sem ciência (migração 349).', 'Atende = Não; Pendente com a empresa', NULL),
('FORN_ENVIADO', 'FORNECEDOR', 10, 'Enviado o documento acompanha o GED: com arquivo = Sim, sem arquivo = Não (menos NA).', 'Enviado o documento = Sim/Não', 'enviadoodocumento.php: setSituacao(..., "") pelo anexo'),
('FORN_VENCIDO', 'FORNECEDOR', 20, 'Validade vencida (fora de protocolo) e o documento não está com o fornecedor.', 'Pendente com o fornecedor', 'enviadoodocumento.php (Aprovado vencido) e homologacao.php (validade vencida)'),
('FORN_SEM_ARQUIVO', 'FORNECEDOR', 30, 'Sem arquivo no GED (menos Aprovado sem validade, Ciente da regra, NA, Não enviar, protocolo e Reprovado).', 'Pendente com o fornecedor', 'enviadoodocumento.php: "Não tem documento anexado"'),
('FORN_SEM_RESPONSAVEL', 'FORNECEDOR', 40, 'Sem responsável da empresa ou do fornecedor, validade futura e ainda não aprovado.', 'Pendente com a empresa', 'enviadoodocumento.php: "Esta sem usuario responsavel ou sem fornecedor responsavel"'),
('FORN_ATUALIZADO', 'FORNECEDOR', 50, 'Com arquivo, não vencido, pendente com o fornecedor / reprovado / sem situação, e o fornecedor agiu depois da última ação da empresa (alteração do usuário do fornecedor ou arquivo novo).', 'Pendente com a empresa', 'enviadoodocumento.php: "criterio atualizado pelo fornecedor" e "Data de vencimento alterada pelo fornecedor"'),
('FUNC_SEM_ARQUIVO', 'FUNCIONARIO', 110, 'Documento do funcionário sem arquivo no GED (menos NAP, Afastado, Desligado).', 'Atende = Não; Pendente com o fornecedor', 'enviadoodocumento.php: "Sem anexos, favor verificar!"'),
('FUNC_VENCIDO', 'FUNCIONARIO', 120, 'Documento do funcionário com validade vencida.', 'Atende = Não; Pendente com o fornecedor', 'enviadoodocumento.php: "Quando a data de validade estiver vencida..." (corrigido: no legado nunca era alcançada)'),
('FUNC_SEM_RESPONSAVEL', 'FUNCIONARIO', 130, 'Com arquivo, sem responsável da empresa ou do fornecedor.', 'Atende = Sim; Pendente com a empresa', 'enviadoodocumento.php: "Usuarios responsaveis não validos"'),
('FUNC_ATENDE', 'FUNCIONARIO', 140, 'Com arquivo e validade em dia (ou sem validade).', 'Atende = Sim; sem pendência', 'enviadoodocumento.php: "Tem anexo e data de validade aceita."')

-- @@
CREATE TABLE IF NOT EXISTS `fo_regras_documentos_log` (
    `codlog` BIGINT NOT NULL AUTO_INCREMENT,
    `executado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `escopo` VARCHAR(20) NOT NULL,
    `codigo` BIGINT NOT NULL COMMENT 'fo_fornecedorxcriterios.codfornecedorxcriterios ou fo_funcionarioxtiposxcriterios.codigo',
    `codfornecedor` BIGINT NULL,
    `codregra` VARCHAR(40) NOT NULL,
    `situacao_antes` VARCHAR(100) NULL,
    `situacao_depois` VARCHAR(100) NULL,
    `campo` VARCHAR(40) NULL COMMENT 'enviadoodocumento (fornecedor) ou atendeaocriterio (funcionário)',
    `campo_antes` VARCHAR(30) NULL,
    `campo_depois` VARCHAR(30) NULL,
    `arquivos` INT NULL,
    `codusuario` BIGINT NULL COMMENT '0 = serviço',
    PRIMARY KEY (`codlog`),
    KEY `ix_fo_regras_log_doc` (`escopo`, `codigo`, `executado_em`)
) COMMENT 'O que as regras automáticas mudaram em cada documento (migração 301)'

-- @@
CREATE OR REPLACE VIEW `vw_fo_regras_documentos` AS
SELECT codregra AS CODREGRA, escopo AS ESCOPO, ordem AS ORDEM, descricao AS DESCRICAO, resultado AS RESULTADO, origem_legado AS ORIGEM_LEGADO,
       ativo AS ATIVO, atualizado_em AS ATUALIZADO_EM
  FROM `fo_regras_documentos`

-- @@
CREATE OR REPLACE VIEW `vw_fo_regras_documentos_log` AS
SELECT l.codlog AS CODLOG, l.executado_em AS EXECUTADO_EM, l.escopo AS ESCOPO, l.codigo AS CODIGO, l.codfornecedor AS CODFORNECEDOR,
       f.RAZAOSOCIAL AS RAZAOSOCIAL, l.codregra AS CODREGRA, r.descricao AS REGRA, l.situacao_antes AS SITUACAO_ANTES,
       l.situacao_depois AS SITUACAO_DEPOIS, l.campo AS CAMPO, l.campo_antes AS CAMPO_ANTES, l.campo_depois AS CAMPO_DEPOIS,
       l.arquivos AS ARQUIVOS, l.codusuario AS CODUSUARIO
  FROM `fo_regras_documentos_log` l
  LEFT JOIN `fo_regras_documentos` r ON r.codregra = l.codregra
  LEFT JOIN `fo_fornecedores` f ON f.codfornecedor = l.codfornecedor

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_regras_documentos_listar`

-- @@
-- Códigos que o serviço deve examinar (ele conta os arquivos de cada um no GED). FORNECEDOR: documentos visíveis (tipo ligado ao
-- fornecedor e critério ligado ao tipo), menos NA. FUNCIONARIO: critérios de funcionários ativos, menos NAP/Afastado/Desligado.
CREATE PROCEDURE `sp_fo_regras_documentos_listar`(IN p_escopo VARCHAR(20))
SQL SECURITY INVOKER
BEGIN
    IF UPPER(COALESCE(p_escopo, '')) = 'FORNECEDOR' THEN
        SELECT d.CODFORNECEDORXCRITERIOS AS codigo FROM `vw_homologacao_fornecedor_documentos` d
         WHERE COALESCE(d.SITUACAO, '') <> 'NA' ORDER BY d.CODFORNECEDORXCRITERIOS;
    ELSEIF UPPER(COALESCE(p_escopo, '')) = 'FUNCIONARIO' THEN
        SELECT x.codigo AS codigo FROM `fo_funcionarioxtiposxcriterios` x
          JOIN `fo_funcionarios` fu ON fu.CODFUNCIONARIO = x.codfuncionario
         WHERE UPPER(TRIM(COALESCE(fu.ativo, 'S'))) NOT IN ('N', 'NAO', 'NÃO', '0')
           AND UPPER(TRIM(COALESCE(x.atendeaocriterio, ''))) NOT IN ('NAP', 'AFASTADO', 'DESLIGADO')
         ORDER BY x.codigo;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escopo inválido (use FORNECEDOR ou FUNCIONARIO).';
    END IF;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_regras_documentos_aplicar`

-- @@
-- Aplica as regras. p_arquivos: JSON [{"c": código, "n": quantidade de arquivos no GED, "m": "AAAA-MM-DD HH:MM:SS" do arquivo mais novo}].
-- p_aplicar = 0 só calcula (simulação). p_usuario: quem aparece no histórico (0 = serviço).
-- Retorna os documentos que mudam (ou mudariam), com a regra, a situação antes/depois e os responsáveis (para os alertas).
CREATE PROCEDURE `sp_fo_regras_documentos_aplicar`(IN p_escopo VARCHAR(20), IN p_arquivos JSON, IN p_aplicar TINYINT, IN p_usuario BIGINT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_esc VARCHAR(20) DEFAULT UPPER(COALESCE(p_escopo, ''));
    DECLARE v_env, v_venc, v_sarq, v_sresp, v_atu, v_f1, v_f2, v_f3, v_f4, v_adf, v_adu, v_adt, v_blq TINYINT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        DROP TEMPORARY TABLE IF EXISTS tmp_fo_rd;
        DROP TEMPORARY TABLE IF EXISTS tmp_fo_rd_hist;
        RESIGNAL;
    END;
    IF v_esc NOT IN ('FORNECEDOR', 'FUNCIONARIO') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escopo inválido (use FORNECEDOR ou FUNCIONARIO).'; END IF;
    IF p_arquivos IS NULL OR JSON_TYPE(p_arquivos) <> 'ARRAY' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lista de arquivos inválida.'; END IF;

    SELECT COALESCE(MAX(codregra = 'FORN_ENVIADO' AND ativo = 1), 0), COALESCE(MAX(codregra = 'FORN_VENCIDO' AND ativo = 1), 0),
           COALESCE(MAX(codregra = 'FORN_SEM_ARQUIVO' AND ativo = 1), 0), COALESCE(MAX(codregra = 'FORN_SEM_RESPONSAVEL' AND ativo = 1), 0),
           COALESCE(MAX(codregra = 'FORN_ATUALIZADO' AND ativo = 1), 0), COALESCE(MAX(codregra = 'FUNC_SEM_ARQUIVO' AND ativo = 1), 0),
           COALESCE(MAX(codregra = 'FUNC_VENCIDO' AND ativo = 1), 0), COALESCE(MAX(codregra = 'FUNC_SEM_RESPONSAVEL' AND ativo = 1), 0),
           COALESCE(MAX(codregra = 'FUNC_ATENDE' AND ativo = 1), 0),
           COALESCE(MAX(codregra = 'FORN_ADULTERACAO' AND ativo = 1), 0), COALESCE(MAX(codregra = 'FUNC_ADULTERACAO' AND ativo = 1), 0)
      INTO v_env, v_venc, v_sarq, v_sresp, v_atu, v_f1, v_f2, v_f3, v_f4, v_adf, v_adu
      FROM `fo_regras_documentos`;
    -- Migração 349 (análise de adulteração) aplicada neste banco?
    SELECT COUNT(*) > 0 INTO v_adt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vw_doc_adult_pendentes';
    -- Migração 350: bloqueio do ranking (289) instalado neste banco?
    SELECT COUNT(*) > 0 INTO v_blq FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fo_fornecedor_bloqueios';

    DROP TEMPORARY TABLE IF EXISTS tmp_fo_rd;
    CREATE TEMPORARY TABLE tmp_fo_rd (
        codigo BIGINT PRIMARY KEY, arquivos INT NOT NULL DEFAULT 0, ultimo DATETIME NULL, codfornecedor BIGINT NULL,
        sit_ant VARCHAR(100) NULL, aux_ant VARCHAR(30) NULL, sit_nova VARCHAR(100) NULL, aux_novo VARCHAR(30) NULL,
        regra VARCHAR(40) NULL, regra_aux VARCHAR(40) NULL, resp_empresa BIGINT NULL, resp_fornecedor BIGINT NULL
    );
    INSERT IGNORE INTO tmp_fo_rd (codigo, arquivos, ultimo)
    SELECT j.c, GREATEST(COALESCE(j.n, 0), 0), IF(COALESCE(j.m, '') REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$', STR_TO_DATE(j.m, '%Y-%m-%d %H:%i:%s'), NULL)
      FROM JSON_TABLE(p_arquivos, '$[*]' COLUMNS (c BIGINT PATH '$.c', n INT PATH '$.n', m VARCHAR(19) PATH '$.m')) j
     WHERE j.c > 0;

    IF v_esc = 'FORNECEDOR' THEN
        -- Última ação da empresa (inclui o serviço) e do fornecedor (usuário ligado ao fornecedor em Fornecedor x Usuários), pelo histórico
        DROP TEMPORARY TABLE IF EXISTS tmp_fo_rd_hist;
        CREATE TEMPORARY TABLE tmp_fo_rd_hist (codigo BIGINT PRIMARY KEY, ult_emp DATETIME NULL, ult_forn DATETIME NULL);
        INSERT INTO tmp_fo_rd_hist (codigo, ult_emp, ult_forn)
        SELECT h.codfornecedorxcriterios, MAX(IF(fu.CODUSUARIO IS NULL, h.DATAALTERACAO, NULL)), MAX(IF(fu.CODUSUARIO IS NULL, NULL, h.DATAALTERACAO))
          FROM `fo_fornecedorxcriterios_historico` h
          JOIN tmp_fo_rd t ON t.codigo = h.codfornecedorxcriterios
          LEFT JOIN (SELECT DISTINCT CODFORNECEDOR, CODUSUARIO FROM `fo_fornecedorxusuario`) fu
                 ON fu.CODFORNECEDOR = h.codfornecedor AND fu.CODUSUARIO = h.CODUSUARIOALTERACAO
         GROUP BY h.codfornecedorxcriterios;

        UPDATE tmp_fo_rd t
          JOIN `fo_fornecedorxcriterios` x ON x.codfornecedorxcriterios = t.codigo
          LEFT JOIN tmp_fo_rd_hist hh ON hh.codigo = t.codigo
           SET t.codfornecedor = x.codfornecedor,
               t.sit_ant = COALESCE(TRIM(x.situacao), ''),
               t.aux_ant = COALESCE(TRIM(x.enviadoodocumento), ''),
               t.aux_novo = IF(t.arquivos > 0, 'Sim', 'Não'),
               t.resp_empresa = NULLIF(x.codusuario, 0),
               t.resp_fornecedor = NULLIF(NULLIF(x.codusuario_fornecedor, 0), 99999),
               t.regra = CASE
                   WHEN COALESCE(TRIM(x.situacao), '') IN ('NA', 'Não enviar ao fornecedor', 'Aguardando vencimento do protocolo')
                        OR (UPPER(COALESCE(x.emprotocolo, '')) = 'S' AND COALESCE(x.datacobranca_emprotocolo >= CURDATE(), 1) = 1) THEN NULL
                   WHEN v_venc = 1 AND x.datadevalidade > '1900-01-01' AND x.datadevalidade < CURDATE()
                        AND COALESCE(TRIM(x.situacao), '') NOT IN ('Pendente com o fornecedor', 'Reprovado') THEN 'FORN_VENCIDO'
                   WHEN v_sarq = 1 AND t.arquivos = 0
                        AND COALESCE(TRIM(x.situacao), '') NOT IN ('Pendente com o fornecedor', 'Reprovado', 'Aprovado sem validade', 'Ciente da regra')
                        AND NOT ((COALESCE(x.codusuario, 0) = 0 OR COALESCE(x.codusuario_fornecedor, 0) = 0) AND COALESCE(x.datadevalidade >= CURDATE(), 0) = 1)
                        THEN 'FORN_SEM_ARQUIVO'
                   WHEN v_sresp = 1 AND (COALESCE(x.codusuario, 0) = 0 OR COALESCE(x.codusuario_fornecedor, 0) = 0)
                        AND COALESCE(x.datadevalidade >= CURDATE(), 0) = 1
                        AND COALESCE(TRIM(x.situacao), '') NOT IN ('Aprovado', 'Aprovado sem validade', 'Ciente da regra', 'Pendente com a empresa')
                        THEN 'FORN_SEM_RESPONSAVEL'
                   WHEN v_atu = 1 AND t.arquivos > 0
                        AND NOT COALESCE(x.datadevalidade > '1900-01-01' AND x.datadevalidade < CURDATE(), 0)
                        AND COALESCE(TRIM(x.situacao), '') IN ('Pendente com o fornecedor', 'Reprovado', '')
                        AND ((hh.ult_forn IS NOT NULL AND (hh.ult_emp IS NULL OR hh.ult_forn > hh.ult_emp))
                             OR (t.ultimo IS NOT NULL AND (hh.ult_emp IS NULL OR t.ultimo > hh.ult_emp)))
                        THEN 'FORN_ATUALIZADO'
                   ELSE NULL
               END;
        DELETE FROM tmp_fo_rd WHERE sit_ant IS NULL; -- código que não existe mais
        UPDATE tmp_fo_rd
           SET sit_nova = CASE regra WHEN 'FORN_VENCIDO' THEN 'Pendente com o fornecedor' WHEN 'FORN_SEM_ARQUIVO' THEN 'Pendente com o fornecedor'
                                     WHEN 'FORN_SEM_RESPONSAVEL' THEN 'Pendente com a empresa' WHEN 'FORN_ATUALIZADO' THEN 'Pendente com a empresa' ELSE NULL END,
               regra_aux = IF(v_env = 1 AND sit_ant <> 'NA' AND aux_ant <> aux_novo, 'FORN_ENVIADO', NULL);
        UPDATE tmp_fo_rd SET regra = NULL, sit_nova = NULL WHERE sit_nova IS NOT NULL AND sit_nova = sit_ant;
        -- Migração 349: arquivo de risco alto sem ciência tira o documento de aprovado (vencido e sem arquivo continuam com o fornecedor)
        IF v_adt = 1 AND v_adf = 1 THEN
            UPDATE tmp_fo_rd t JOIN `vw_doc_adult_pendentes` p ON p.ESCOPO = 'FORNECEDOR' AND p.CODIGO = t.codigo
               SET t.regra = 'FORN_ADULTERACAO', t.sit_nova = 'Pendente com a empresa'
             WHERE t.sit_ant IN ('Aprovado', 'Aprovado sem validade', 'Ciente da regra', '')
               AND COALESCE(t.sit_nova, '') <> 'Pendente com o fornecedor';
        END IF;

        IF COALESCE(p_aplicar, 0) = 1 THEN
            START TRANSACTION;
            UPDATE `fo_fornecedorxcriterios` x JOIN tmp_fo_rd t ON t.codigo = x.codfornecedorxcriterios
               SET x.situacao = COALESCE(t.sit_nova, x.situacao),
                   x.enviadoodocumento = IF(t.regra_aux IS NULL, x.enviadoodocumento, t.aux_novo)
             WHERE t.regra IS NOT NULL OR t.regra_aux IS NOT NULL;
            -- Histórico só quando a situação muda (o "Enviado" sozinho fica no log)
            INSERT INTO `fo_fornecedorxcriterios_historico` (CODUSUARIOALTERACAO, DATAALTERACAO, codfornecedorxcriterios, codfornecedor, codtipo, codcriterio,
                enviadoodocumento, datadevalidade, datadereacao, situacao, incluidocontrato, codusuario, codusuario_fornecedor, observacao,
                observacao_do_fornecedor, emprotocolo, datacobranca_emprotocolo)
            SELECT COALESCE(p_usuario, 0), NOW(), x.codfornecedorxcriterios, x.codfornecedor, x.codtipo, x.codcriterio, x.enviadoodocumento, x.datadevalidade,
                   x.datadereacao, x.situacao, x.incluidocontrato, x.codusuario, x.codusuario_fornecedor, x.observacao, x.observacao_do_fornecedor,
                   x.emprotocolo, x.datacobranca_emprotocolo
              FROM `fo_fornecedorxcriterios` x JOIN tmp_fo_rd t ON t.codigo = x.codfornecedorxcriterios
             WHERE t.regra IS NOT NULL;
            INSERT INTO `fo_regras_documentos_log` (escopo, codigo, codfornecedor, codregra, situacao_antes, situacao_depois, campo, campo_antes, campo_depois, arquivos, codusuario)
            SELECT 'FORNECEDOR', codigo, codfornecedor, regra, sit_ant, sit_nova, NULL, NULL, NULL, arquivos, COALESCE(p_usuario, 0) FROM tmp_fo_rd WHERE regra IS NOT NULL;
            INSERT INTO `fo_regras_documentos_log` (escopo, codigo, codfornecedor, codregra, situacao_antes, situacao_depois, campo, campo_antes, campo_depois, arquivos, codusuario)
            SELECT 'FORNECEDOR', codigo, codfornecedor, regra_aux, NULL, NULL, 'enviadoodocumento', aux_ant, aux_novo, arquivos, COALESCE(p_usuario, 0) FROM tmp_fo_rd WHERE regra_aux IS NOT NULL;
            COMMIT;
        END IF;

        SELECT t.codigo AS CODIGO, t.codfornecedor AS CODFORNECEDOR, f.RAZAOSOCIAL, TRIM(COALESCE(f.ATIVADOFOR, '')) = 'Sim' AS ATIVO,
               CONCAT(COALESCE(tf.DSCTIPO, ''), ' / ', COALESCE(c.DSCRITERIO, '')) AS DOCUMENTO,
               t.regra AS REGRA, t.sit_ant AS SITUACAO_ANTES, t.sit_nova AS SITUACAO_DEPOIS,
               t.regra_aux AS REGRA_CAMPO, t.aux_ant AS CAMPO_ANTES, IF(t.regra_aux IS NULL, NULL, t.aux_novo) AS CAMPO_DEPOIS,
               t.arquivos AS ARQUIVOS, t.resp_empresa AS CODUSUARIO_EMPRESA, t.resp_fornecedor AS CODUSUARIO_FORNECEDOR
          FROM tmp_fo_rd t
          JOIN `fo_fornecedorxcriterios` x ON x.codfornecedorxcriterios = t.codigo
          JOIN `fo_fornecedores` f ON f.codfornecedor = t.codfornecedor
          LEFT JOIN `fo_tipofornecedores` tf ON tf.codtipofornecedor = x.codtipo
          LEFT JOIN `fo_criteriofornecedores` c ON c.codcriteriofornecedor = x.codcriterio
         WHERE t.regra IS NOT NULL OR t.regra_aux IS NOT NULL
         ORDER BY f.RAZAOSOCIAL, t.codigo;
        DROP TEMPORARY TABLE IF EXISTS tmp_fo_rd_hist;
    ELSE
        UPDATE tmp_fo_rd t
          JOIN `fo_funcionarioxtiposxcriterios` x ON x.codigo = t.codigo
          JOIN `fo_funcionarios` fu ON fu.CODFUNCIONARIO = x.codfuncionario
           SET t.codfornecedor = fu.CODFORNECEDOR,
               t.sit_ant = COALESCE(TRIM(x.situacao), ''),
               t.aux_ant = COALESCE(TRIM(x.atendeaocriterio), ''),
               t.resp_empresa = NULLIF(x.codusuario, 0),
               t.resp_fornecedor = NULLIF(x.codusuario_responsavel_fornecedor, 0),
               t.regra = CASE
                   WHEN UPPER(TRIM(COALESCE(x.atendeaocriterio, ''))) IN ('NAP', 'AFASTADO', 'DESLIGADO') THEN NULL
                   WHEN v_f1 = 1 AND t.arquivos = 0 THEN 'FUNC_SEM_ARQUIVO'
                   WHEN v_f2 = 1 AND x.datadevalidade > '1900-01-01' AND x.datadevalidade < CURDATE() THEN 'FUNC_VENCIDO'
                   WHEN v_f3 = 1 AND (COALESCE(x.codusuario, 0) = 0 OR COALESCE(x.codusuario_responsavel_fornecedor, 0) = 0) THEN 'FUNC_SEM_RESPONSAVEL'
                   WHEN v_f4 = 1 THEN 'FUNC_ATENDE'
                   ELSE NULL
               END;
        DELETE FROM tmp_fo_rd WHERE sit_ant IS NULL;
        UPDATE tmp_fo_rd
           SET aux_novo = CASE regra WHEN 'FUNC_SEM_ARQUIVO' THEN 'Não' WHEN 'FUNC_VENCIDO' THEN 'Não' WHEN 'FUNC_SEM_RESPONSAVEL' THEN 'Sim' WHEN 'FUNC_ATENDE' THEN 'Sim' ELSE NULL END,
               sit_nova = CASE regra WHEN 'FUNC_SEM_ARQUIVO' THEN 'Pendente com o fornecedor' WHEN 'FUNC_VENCIDO' THEN 'Pendente com o fornecedor'
                                     WHEN 'FUNC_SEM_RESPONSAVEL' THEN 'Pendente com a empresa' WHEN 'FUNC_ATENDE' THEN '' ELSE NULL END;
        UPDATE tmp_fo_rd SET regra = NULL WHERE regra IS NOT NULL AND UPPER(aux_novo) = UPPER(aux_ant) AND sit_nova = sit_ant;
        -- Migração 349: arquivo de risco alto sem ciência não é aprovado pela regra (e sai de "Atende = Sim")
        IF v_adt = 1 AND v_adu = 1 THEN
            UPDATE tmp_fo_rd t JOIN `vw_doc_adult_pendentes` p ON p.ESCOPO = 'FUNCIONARIO' AND p.CODIGO = t.codigo
               SET t.regra = 'FUNC_ADULTERACAO', t.aux_novo = 'Não', t.sit_nova = 'Pendente com a empresa'
             WHERE UPPER(t.aux_ant) NOT IN ('NAP', 'AFASTADO', 'DESLIGADO')
               AND COALESCE(t.regra, '') NOT IN ('FUNC_SEM_ARQUIVO', 'FUNC_VENCIDO');
            -- Já está assim: nada muda (e a regra que aprovaria fica de fora)
            UPDATE tmp_fo_rd SET regra = NULL WHERE regra = 'FUNC_ADULTERACAO' AND aux_ant = 'Não' AND sit_ant = 'Pendente com a empresa';
        END IF;
        -- Migração 350: fornecedor bloqueado no ranking — a regra não aprova (o documento fica como está)
        IF v_blq = 1 THEN
            UPDATE tmp_fo_rd t JOIN `fo_fornecedor_bloqueios` b ON b.codfornecedor = t.codfornecedor
               SET t.regra = NULL
             WHERE t.regra IN ('FUNC_ATENDE', 'FUNC_SEM_RESPONSAVEL') AND UPPER(COALESCE(t.aux_ant, '')) <> 'SIM';
        END IF;

        IF COALESCE(p_aplicar, 0) = 1 THEN
            START TRANSACTION;
            UPDATE `fo_funcionarioxtiposxcriterios` x JOIN tmp_fo_rd t ON t.codigo = x.codigo
               SET x.atendeaocriterio = t.aux_novo, x.situacao = t.sit_nova
             WHERE t.regra IS NOT NULL;
            INSERT INTO `fo_regras_documentos_log` (escopo, codigo, codfornecedor, codregra, situacao_antes, situacao_depois, campo, campo_antes, campo_depois, arquivos, codusuario)
            SELECT 'FUNCIONARIO', codigo, codfornecedor, regra, sit_ant, sit_nova, 'atendeaocriterio', aux_ant, aux_novo, arquivos, COALESCE(p_usuario, 0)
              FROM tmp_fo_rd WHERE regra IS NOT NULL;
            COMMIT;
        END IF;

        SELECT t.codigo AS CODIGO, t.codfornecedor AS CODFORNECEDOR, f.RAZAOSOCIAL, TRIM(COALESCE(f.ATIVADOFOR, '')) = 'Sim' AS ATIVO,
               CONCAT(COALESCE(fu.NOME, ''), ' / ', COALESCE(c.DSCRITERIO, '')) AS DOCUMENTO,
               t.regra AS REGRA, t.sit_ant AS SITUACAO_ANTES, t.sit_nova AS SITUACAO_DEPOIS,
               t.regra AS REGRA_CAMPO, t.aux_ant AS CAMPO_ANTES, t.aux_novo AS CAMPO_DEPOIS,
               t.arquivos AS ARQUIVOS, t.resp_empresa AS CODUSUARIO_EMPRESA, t.resp_fornecedor AS CODUSUARIO_FORNECEDOR
          FROM tmp_fo_rd t
          JOIN `fo_funcionarioxtiposxcriterios` x ON x.codigo = t.codigo
          JOIN `fo_funcionarios` fu ON fu.CODFUNCIONARIO = x.codfuncionario
          LEFT JOIN `fo_fornecedores` f ON f.codfornecedor = t.codfornecedor
          LEFT JOIN `fo_criteriofuncionarios` c ON c.codcriteriofuncionario = x.codcriteriofuncionario
         WHERE t.regra IS NOT NULL
         ORDER BY f.RAZAOSOCIAL, fu.NOME, t.codigo;
    END IF;
    DROP TEMPORARY TABLE IF EXISTS tmp_fo_rd;
END
