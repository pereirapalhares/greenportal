-- Migração 348 — Integração de funcionários: questionário, avaliação e liberação (Onda 2 da apresentação Atlas Schindler 2026:
-- "Integração de funcionários: avaliação e liberação — 1. Cadastro, 2. Questionário, 3. Avaliação, 4. Decisão").
--
-- Decisões do usuário (24/10/2026):
--   * quem responde é o próprio funcionário, por um link só dele (sem senha), válido por alguns dias e para uma resposta;
--   * o questionário é o do tipo de funcionário; sem um próprio, vale o padrão da empresa (configuração com tipo vazio);
--   * a nota mínima sugere Aprovado / Reprovado e uma pessoa confirma;
--   * aprovado: grava a DATA DE REALIZAÇÃO da integração no funcionário (fo_funcionarios.DATA_VALIDADE, "Data de Realização da
--     Integração" no legado — decisão do usuário de 24/10/2026, migração 350); a validade = realização + meses da configuração
--     (vw_fo_integracao_validade) libera na portaria; gera o certificado em PDF nos arquivos do funcionário.
-- O questionário em si (blocos, perguntas, pesos, pontuação) é o motor universal de questionários (contexto EMPLOYEE_INTEGRATION);
-- a resposta fica em amb_questionario_execucoes / _respostas / _resultados da empresa.
-- Como o legado prevalece até a virada, o sincronismo pode trazer de volta uma data mais antiga: o serviço de documentos da
-- homologação reaplica a validade da integração aprovada (sp_fo_integracao_reaplicar).
-- {{UNIVERSAL}}: banco universal (.env GUNIVERSAL_DB_DATABASE). Comandos separados por "-- @@".

CREATE TABLE IF NOT EXISTS `fo_integracao_config` (
    `CODCONFIG` INT NOT NULL AUTO_INCREMENT,
    `CODTIPOFUNCIONARIO` INT NULL,
    `CHAVE_TIPO` INT AS (COALESCE(`CODTIPOFUNCIONARIO`, 0)) STORED,
    `CODIGO_QUESTIONARIO` VARCHAR(100) NOT NULL,
    `NOTA_MINIMA` DECIMAL(6,2) NULL,
    `VALIDADE_MESES` INT NOT NULL DEFAULT 12,
    `LINK_DIAS` INT NOT NULL DEFAULT 7,
    `ATIVO` TINYINT NOT NULL DEFAULT 1,
    `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `CODUSUARIO_ALTERACAO` BIGINT NULL,
    PRIMARY KEY (`CODCONFIG`),
    UNIQUE KEY `ux_fo_integracao_config_tipo` (`CHAVE_TIPO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
CREATE TABLE IF NOT EXISTS `fo_integracoes` (
    `CODINTEGRACAO` BIGINT NOT NULL AUTO_INCREMENT,
    `CODFUNCIONARIO` INT NOT NULL,
    `CODFORNECEDOR` INT NULL,
    `CODTIPOFUNCIONARIO` INT NULL,
    `CODIGO_QUESTIONARIO` VARCHAR(100) NOT NULL,
    `NOTA_MINIMA` DECIMAL(6,2) NULL,
    `VALIDADE_MESES` INT NOT NULL DEFAULT 12,
    `TOKEN_HASH` CHAR(64) NOT NULL,
    `LINK_EXPIRA_EM` DATETIME NOT NULL,
    `STATUS` VARCHAR(20) NOT NULL DEFAULT 'AGUARDANDO',
    `CODEXECUCAO` BIGINT NULL,
    `PERCENTUAL` DECIMAL(7,3) NULL,
    `SUGESTAO` VARCHAR(10) NULL,
    `RESPONDIDA_EM` DATETIME NULL,
    `DECISAO` VARCHAR(10) NULL,
    `MOTIVO` VARCHAR(1000) NULL,
    `DECIDIDA_POR` BIGINT NULL,
    `DECIDIDA_EM` DATETIME NULL,
    `VALIDADE_ATE` DATE NULL,
    `CERTIFICADO` VARCHAR(255) NULL,
    `ACESSOS` INT NOT NULL DEFAULT 0,
    `ULTIMO_ACESSO` DATETIME NULL,
    `CRIADA_POR` BIGINT NULL,
    `CRIADA_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`CODINTEGRACAO`),
    UNIQUE KEY `ux_fo_integracoes_token` (`TOKEN_HASH`),
    KEY `ix_fo_integracoes_funcionario` (`CODFUNCIONARIO`, `CODINTEGRACAO`),
    KEY `ix_fo_integracoes_status` (`STATUS`),
    CONSTRAINT `ck_fo_integracoes_status` CHECK (`STATUS` IN ('AGUARDANDO', 'RESPONDIDA', 'APROVADA', 'REPROVADA', 'CANCELADA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
-- Migração 350: validade da integração = data de realização (fo_funcionarios.DATA_VALIDADE) + meses do tipo (ou do padrão, ou 12).
-- A mesma view é criada pela 298 (Painel).
CREATE OR REPLACE VIEW `vw_fo_integracao_validade` AS
SELECT fu.`CODFUNCIONARIO`,
       IF(fu.`DATA_VALIDADE` IS NULL OR fu.`DATA_VALIDADE` < '1900-01-01', NULL, fu.`DATA_VALIDADE`) AS `DATA_REALIZACAO`,
       COALESCE(ct.`VALIDADE_MESES`, cp.`VALIDADE_MESES`, 12) AS `VALIDADE_MESES`,
       IF(fu.`DATA_VALIDADE` IS NULL OR fu.`DATA_VALIDADE` < '1900-01-01', NULL,
          DATE_ADD(fu.`DATA_VALIDADE`, INTERVAL COALESCE(ct.`VALIDADE_MESES`, cp.`VALIDADE_MESES`, 12) MONTH)) AS `DATA_VALIDADE_INTEGRACAO`
  FROM `fo_funcionarios` fu
  LEFT JOIN `fo_integracao_config` ct ON ct.`CODTIPOFUNCIONARIO` = fu.`CODTIPOFUNCIONARIO` AND ct.`ATIVO` = 1
  LEFT JOIN `fo_integracao_config` cp ON cp.`CODTIPOFUNCIONARIO` IS NULL AND cp.`ATIVO` = 1

-- @@
DROP VIEW IF EXISTS `vw_fo_integracao_tipos`

-- @@
CREATE VIEW `vw_fo_integracao_tipos` AS
SELECT `CODTIPOFUNCIONARIO`, `DSCTIPO` FROM `fo_tipofuncionarios`

-- @@
DROP VIEW IF EXISTS `vw_fo_integracao_questionarios`

-- @@
-- Questionários de integração publicados no catálogo universal (contexto EMPLOYEE_INTEGRATION)
CREATE VIEW `vw_fo_integracao_questionarios` AS
SELECT q.`codigo` AS `CODIGO_QUESTIONARIO`, MAX(q.`nome_pt`) AS `NOME`, MAX(q.`percentual_minimo`) AS `PERCENTUAL_MINIMO`
  FROM `{{UNIVERSAL}}`.`vw_amb_questionario_catalogo` q
 WHERE q.`ativo` = 1 AND q.`codpublicacao` IS NOT NULL
   AND UPPER(COALESCE(q.`modulo`, '')) IN ('EMPLOYEE_INTEGRATION', 'EMPLOYEE', 'GREEN_EMPLOYEE')
 GROUP BY q.`codigo`

-- @@
DROP VIEW IF EXISTS `vw_fo_integracao_config`

-- @@
CREATE VIEW `vw_fo_integracao_config` AS
SELECT c.`CODCONFIG`, c.`CODTIPOFUNCIONARIO`, COALESCE(t.`DSCTIPO`, '') AS `DSCTIPO`, c.`CODIGO_QUESTIONARIO`, q.`NOME` AS `QUESTIONARIO`,
       c.`NOTA_MINIMA`, q.`PERCENTUAL_MINIMO`, COALESCE(c.`NOTA_MINIMA`, q.`PERCENTUAL_MINIMO`, 70) AS `NOTA_EFETIVA`,
       c.`VALIDADE_MESES`, c.`LINK_DIAS`, c.`ATIVO`, c.`ALTERADO_EM`, IF(q.`CODIGO_QUESTIONARIO` IS NULL, 0, 1) AS `PUBLICADO`
  FROM `fo_integracao_config` c
  LEFT JOIN `fo_tipofuncionarios` t ON t.`CODTIPOFUNCIONARIO` = c.`CODTIPOFUNCIONARIO`
  LEFT JOIN `vw_fo_integracao_questionarios` q ON q.`CODIGO_QUESTIONARIO` = c.`CODIGO_QUESTIONARIO` COLLATE utf8mb4_0900_ai_ci

-- @@
DROP VIEW IF EXISTS `vw_fo_integracoes`

-- @@
CREATE VIEW `vw_fo_integracoes` AS
SELECT i.*, f.`NOME` AS `FUNCIONARIO`, f.`NRIDENTIFICACAO`, fo.`RAZAOSOCIAL`, fo.`CODUNIDADE`, u.`DSCUNIDADE`, tf.`DSCTIPO`,
       NULLIF(fo.`CODUSUARIO_RESPONSAVEL_INTEGRACAO`, 0) AS `CODUSUARIO_RESPONSAVEL`, q.`NOME` AS `QUESTIONARIO`,
       IF(i.`STATUS` = 'AGUARDANDO' AND i.`LINK_EXPIRA_EM` > NOW(), 1, 0) AS `LINK_VALIDO`
  FROM `fo_integracoes` i
  JOIN `fo_funcionarios` f ON f.`CODFUNCIONARIO` = i.`CODFUNCIONARIO`
  LEFT JOIN `fo_fornecedores` fo ON fo.`CODFORNECEDOR` = f.`CODFORNECEDOR`
  LEFT JOIN `unidades` u ON u.`CODUNIDADE` = fo.`CODUNIDADE`
  LEFT JOIN `fo_tipofuncionarios` tf ON tf.`CODTIPOFUNCIONARIO` = f.`CODTIPOFUNCIONARIO`
  LEFT JOIN `vw_fo_integracao_questionarios` q ON q.`CODIGO_QUESTIONARIO` = i.`CODIGO_QUESTIONARIO` COLLATE utf8mb4_0900_ai_ci

-- @@
-- Funcionário desligado (25/10/2026): inativo no cadastro ou com todos os critérios preenchidos como "Desligado" (é assim que o
-- legado registra o desligamento). Desligado não recebe link de integração.
CREATE OR REPLACE VIEW `vw_fo_funcionario_desligado` AS
SELECT f.`CODFUNCIONARIO`,
       IF(UPPER(TRIM(COALESCE(f.`ativo`, 'S'))) IN ('N', 'NAO', 'NÃO', '0')
          OR (EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxcriterios` x WHERE x.`codfuncionario` = f.`CODFUNCIONARIO` AND TRIM(x.`atendeaocriterio`) = 'Desligado')
              AND NOT EXISTS (SELECT 1 FROM `fo_funcionarioxtiposxcriterios` x WHERE x.`codfuncionario` = f.`CODFUNCIONARIO`
                               AND TRIM(COALESCE(x.`atendeaocriterio`, '')) NOT IN ('', 'Desligado'))), 1, 0) AS `DESLIGADO`
  FROM `fo_funcionarios` f

-- @@
DROP VIEW IF EXISTS `vw_fo_integracao_funcionarios`

-- @@
-- Painel: cada funcionário (mesmas regras da Gestão de Funcionários), o questionário que ele responde e a última integração
CREATE VIEW `vw_fo_integracao_funcionarios` AS
SELECT g.`CODFUNCIONARIO`, g.`NOME` AS `FUNCIONARIO`, g.`NRIDENTIFICACAO`, g.`CODFORNECEDOR`, g.`RAZAOSOCIAL`, g.`CODUNIDADE`, g.`DSCUNIDADE`,
       g.`CODTIPOFUNCIONARIO`, g.`DSCTIPO`, g.`SETOR`, iv.`DATA_VALIDADE_INTEGRACAO` AS `DATA_VALIDADE`, iv.`DATA_REALIZACAO`, iv.`VALIDADE_MESES` AS `MESES_INTEGRACAO`,
       COALESCE(ct.`CODIGO_QUESTIONARIO`, cp.`CODIGO_QUESTIONARIO`) AS `CODIGO_QUESTIONARIO`,
       COALESCE(ct.`QUESTIONARIO`, cp.`QUESTIONARIO`) AS `QUESTIONARIO`,
       COALESCE(ct.`PUBLICADO`, cp.`PUBLICADO`, 0) AS `QUESTIONARIO_PUBLICADO`,
       i.`CODINTEGRACAO`, i.`STATUS`, i.`LINK_EXPIRA_EM`, IF(i.`STATUS` = 'AGUARDANDO' AND i.`LINK_EXPIRA_EM` > NOW(), 1, 0) AS `LINK_VALIDO`,
       i.`PERCENTUAL`, i.`NOTA_MINIMA`, i.`SUGESTAO`, i.`RESPONDIDA_EM`, i.`DECISAO`, i.`DECIDIDA_EM`, i.`VALIDADE_ATE`, i.`CERTIFICADO`, i.`MOTIVO`,
       COALESCE(dl.`DESLIGADO`, 0) AS `DESLIGADO`
  FROM `vw_homologacao_funcionarios_grid` g
  LEFT JOIN `vw_fo_funcionario_desligado` dl ON dl.`CODFUNCIONARIO` = g.`CODFUNCIONARIO`
  LEFT JOIN `vw_fo_integracao_validade` iv ON iv.`CODFUNCIONARIO` = g.`CODFUNCIONARIO`
  LEFT JOIN `vw_fo_integracao_config` ct ON ct.`CODTIPOFUNCIONARIO` = g.`CODTIPOFUNCIONARIO` AND ct.`ATIVO` = 1
  LEFT JOIN `vw_fo_integracao_config` cp ON cp.`CODTIPOFUNCIONARIO` IS NULL AND cp.`ATIVO` = 1
  LEFT JOIN `fo_integracoes` i ON i.`CODINTEGRACAO` = (SELECT MAX(x.`CODINTEGRACAO`) FROM `fo_integracoes` x WHERE x.`CODFUNCIONARIO` = g.`CODFUNCIONARIO` AND x.`STATUS` <> 'CANCELADA')

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_integracao_config_salvar`

-- @@
CREATE PROCEDURE `sp_fo_integracao_config_salvar`(IN p_codconfig INT, IN p_tipo INT, IN p_questionario VARCHAR(100), IN p_nota DECIMAL(6,2),
    IN p_validade INT, IN p_link_dias INT, IN p_ativo TINYINT, IN p_usuario BIGINT)
BEGIN
    IF TRIM(COALESCE(p_questionario, '')) = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QUESTIONARIO_OBRIGATORIO'; END IF;
    IF p_nota IS NOT NULL AND (p_nota < 0 OR p_nota > 100) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOTA_INVALIDA'; END IF;
    IF COALESCE(p_validade, 0) < 1 OR p_validade > 120 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'VALIDADE_INVALIDA'; END IF;
    IF COALESCE(p_link_dias, 0) < 1 OR p_link_dias > 90 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LINK_INVALIDO'; END IF;
    IF p_tipo IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `fo_tipofuncionarios` WHERE `CODTIPOFUNCIONARIO` = p_tipo) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TIPO_INEXISTENTE'; END IF;
    IF EXISTS (SELECT 1 FROM `fo_integracao_config` WHERE `CHAVE_TIPO` = COALESCE(p_tipo, 0) AND `CODCONFIG` <> COALESCE(p_codconfig, 0)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TIPO_DUPLICADO';
    END IF;
    IF COALESCE(p_codconfig, 0) = 0 THEN
        INSERT INTO `fo_integracao_config` (`CODTIPOFUNCIONARIO`, `CODIGO_QUESTIONARIO`, `NOTA_MINIMA`, `VALIDADE_MESES`, `LINK_DIAS`, `ATIVO`, `CODUSUARIO_ALTERACAO`)
        VALUES (p_tipo, UPPER(TRIM(p_questionario)), p_nota, p_validade, p_link_dias, COALESCE(p_ativo, 1), NULLIF(p_usuario, 0));
        SELECT LAST_INSERT_ID() AS CODCONFIG;
    ELSE
        UPDATE `fo_integracao_config` SET `CODTIPOFUNCIONARIO` = p_tipo, `CODIGO_QUESTIONARIO` = UPPER(TRIM(p_questionario)), `NOTA_MINIMA` = p_nota,
               `VALIDADE_MESES` = p_validade, `LINK_DIAS` = p_link_dias, `ATIVO` = COALESCE(p_ativo, 1), `CODUSUARIO_ALTERACAO` = NULLIF(p_usuario, 0)
         WHERE `CODCONFIG` = p_codconfig;
        SELECT p_codconfig AS CODCONFIG;
    END IF;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_integracao_config_excluir`

-- @@
CREATE PROCEDURE `sp_fo_integracao_config_excluir`(IN p_codconfig INT)
BEGIN
    DELETE FROM `fo_integracao_config` WHERE `CODCONFIG` = p_codconfig;
    SELECT ROW_COUNT() AS EXCLUIDOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_integracao_criar`

-- @@
CREATE PROCEDURE `sp_fo_integracao_criar`(IN p_funcionario INT, IN p_token_hash CHAR(64), IN p_usuario BIGINT)
BEGIN
    -- Novo link de integração: o questionário do tipo do funcionário (senão o padrão); o link anterior que ainda esperava resposta é cancelado
    DECLARE v_tipo INT DEFAULT NULL;
    DECLARE v_forn INT DEFAULT NULL;
    DECLARE v_quest VARCHAR(100) DEFAULT NULL;
    DECLARE v_nota DECIMAL(6,2) DEFAULT NULL;
    DECLARE v_val INT DEFAULT 12;
    DECLARE v_dias INT DEFAULT 7;
    DECLARE v_pub TINYINT DEFAULT 0;
    DECLARE v_achou INT DEFAULT 0;
    SELECT COUNT(*), MAX(`CODTIPOFUNCIONARIO`), MAX(`CODFORNECEDOR`) INTO v_achou, v_tipo, v_forn FROM `fo_funcionarios` WHERE `CODFUNCIONARIO` = p_funcionario;
    IF v_achou = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FUNCIONARIO_INEXISTENTE'; END IF;
    IF EXISTS (SELECT 1 FROM `vw_fo_funcionario_desligado` d WHERE d.`CODFUNCIONARIO` = p_funcionario AND d.`DESLIGADO` = 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FUNCIONARIO_DESLIGADO';
    END IF;
    SELECT c.`CODIGO_QUESTIONARIO`, c.`NOTA_EFETIVA`, c.`VALIDADE_MESES`, c.`LINK_DIAS`, c.`PUBLICADO`
      INTO v_quest, v_nota, v_val, v_dias, v_pub
      FROM `vw_fo_integracao_config` c
     WHERE c.`ATIVO` = 1 AND (c.`CODTIPOFUNCIONARIO` = v_tipo OR c.`CODTIPOFUNCIONARIO` IS NULL)
     ORDER BY c.`CODTIPOFUNCIONARIO` IS NULL, c.`CODCONFIG`
     LIMIT 1;
    IF v_quest IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SEM_QUESTIONARIO'; END IF;
    IF v_pub = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QUESTIONARIO_NAO_PUBLICADO'; END IF;
    UPDATE `fo_integracoes` SET `STATUS` = 'CANCELADA' WHERE `CODFUNCIONARIO` = p_funcionario AND `STATUS` = 'AGUARDANDO';
    INSERT INTO `fo_integracoes` (`CODFUNCIONARIO`, `CODFORNECEDOR`, `CODTIPOFUNCIONARIO`, `CODIGO_QUESTIONARIO`, `NOTA_MINIMA`, `VALIDADE_MESES`, `TOKEN_HASH`, `LINK_EXPIRA_EM`, `CRIADA_POR`)
    VALUES (p_funcionario, v_forn, v_tipo, v_quest, v_nota, v_val, p_token_hash, NOW() + INTERVAL v_dias DAY, NULLIF(p_usuario, 0));
    SELECT LAST_INSERT_ID() AS CODINTEGRACAO, v_quest AS CODIGO_QUESTIONARIO, NOW() + INTERVAL v_dias DAY AS LINK_EXPIRA_EM, v_dias AS LINK_DIAS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_integracao_por_token`

-- @@
CREATE PROCEDURE `sp_fo_integracao_por_token`(IN p_token_hash CHAR(64))
BEGIN
    -- Página pública do funcionário: conta o acesso e devolve a integração (com LINK_VALIDO)
    UPDATE `fo_integracoes` SET `ACESSOS` = `ACESSOS` + 1, `ULTIMO_ACESSO` = NOW() WHERE `TOKEN_HASH` = p_token_hash;
    SELECT `CODINTEGRACAO`, `CODFUNCIONARIO`, `FUNCIONARIO`, `RAZAOSOCIAL`, `CODUNIDADE`, `DSCTIPO`, `CODIGO_QUESTIONARIO`, `QUESTIONARIO`, `STATUS`,
           `LINK_EXPIRA_EM`, `LINK_VALIDO`, `CODEXECUCAO`, `CODUSUARIO_RESPONSAVEL`, `CRIADA_POR`
      FROM `vw_fo_integracoes` WHERE `TOKEN_HASH` = p_token_hash;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_integracao_execucao`

-- @@
CREATE PROCEDURE `sp_fo_integracao_execucao`(IN p_codintegracao BIGINT, IN p_codexecucao BIGINT)
BEGIN
    UPDATE `fo_integracoes` SET `CODEXECUCAO` = p_codexecucao WHERE `CODINTEGRACAO` = p_codintegracao AND `STATUS` = 'AGUARDANDO';
    SELECT ROW_COUNT() AS ALTERADOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_integracao_respondida`

-- @@
CREATE PROCEDURE `sp_fo_integracao_respondida`(IN p_codintegracao BIGINT, IN p_codexecucao BIGINT, IN p_percentual DECIMAL(7,3))
BEGIN
    -- Resposta do funcionário: a nota mínima sugere Aprovado / Reprovado; a decisão é de uma pessoa (sp_fo_integracao_decidir)
    UPDATE `fo_integracoes`
       SET `STATUS` = 'RESPONDIDA', `CODEXECUCAO` = p_codexecucao, `PERCENTUAL` = p_percentual, `RESPONDIDA_EM` = NOW(),
           `SUGESTAO` = IF(p_percentual >= COALESCE(`NOTA_MINIMA`, 70), 'APROVADO', 'REPROVADO')
     WHERE `CODINTEGRACAO` = p_codintegracao AND `STATUS` = 'AGUARDANDO' AND `LINK_EXPIRA_EM` > NOW();
    SELECT ROW_COUNT() AS ALTERADOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_integracao_decidir`

-- @@
CREATE PROCEDURE `sp_fo_integracao_decidir`(IN p_codintegracao BIGINT, IN p_decisao VARCHAR(10), IN p_motivo VARCHAR(1000), IN p_usuario BIGINT)
BEGIN
    -- Decisão da pessoa. Aprovada: a realização (hoje) vai para o funcionário (DATA_VALIDADE = "Data de Realização da Integração");
    -- a validade (hoje + meses) fica na integração e é a que aparece no certificado (migração 350)
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_sug VARCHAR(10) DEFAULT NULL;
    DECLARE v_func INT DEFAULT NULL;
    DECLARE v_meses INT DEFAULT 12;
    DECLARE v_validade DATE DEFAULT NULL;
    IF p_decisao NOT IN ('APROVADA', 'REPROVADA') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DECISAO_INVALIDA'; END IF;
    SELECT `STATUS`, `SUGESTAO`, `CODFUNCIONARIO`, `VALIDADE_MESES` INTO v_status, v_sug, v_func, v_meses FROM `fo_integracoes` WHERE `CODINTEGRACAO` = p_codintegracao FOR UPDATE;
    IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRACAO_INEXISTENTE'; END IF;
    IF v_status <> 'RESPONDIDA' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRACAO_NAO_RESPONDIDA'; END IF;
    IF (p_decisao = 'REPROVADA' OR (p_decisao = 'APROVADA' AND v_sug = 'REPROVADO')) AND CHAR_LENGTH(TRIM(COALESCE(p_motivo, ''))) < 5 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MOTIVO_OBRIGATORIO';
    END IF;
    SET v_validade = IF(p_decisao = 'APROVADA', CURDATE() + INTERVAL v_meses MONTH, NULL);
    UPDATE `fo_integracoes` SET `STATUS` = p_decisao, `DECISAO` = p_decisao, `MOTIVO` = NULLIF(TRIM(p_motivo), ''), `DECIDIDA_POR` = NULLIF(p_usuario, 0),
           `DECIDIDA_EM` = NOW(), `VALIDADE_ATE` = v_validade
     WHERE `CODINTEGRACAO` = p_codintegracao;
    IF p_decisao = 'APROVADA' THEN
        UPDATE `fo_funcionarios` SET `DATA_VALIDADE` = CURDATE() WHERE `CODFUNCIONARIO` = v_func;
    END IF;
    SELECT p_decisao AS DECISAO, v_validade AS VALIDADE_ATE, v_func AS CODFUNCIONARIO;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_integracao_certificado`

-- @@
CREATE PROCEDURE `sp_fo_integracao_certificado`(IN p_codintegracao BIGINT, IN p_arquivo VARCHAR(255))
BEGIN
    UPDATE `fo_integracoes` SET `CERTIFICADO` = p_arquivo WHERE `CODINTEGRACAO` = p_codintegracao AND `STATUS` = 'APROVADA';
    SELECT ROW_COUNT() AS ALTERADOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_integracao_cancelar`

-- @@
CREATE PROCEDURE `sp_fo_integracao_cancelar`(IN p_codintegracao BIGINT, IN p_usuario BIGINT)
BEGIN
    UPDATE `fo_integracoes` SET `STATUS` = 'CANCELADA', `DECIDIDA_POR` = NULLIF(p_usuario, 0), `DECIDIDA_EM` = NOW()
     WHERE `CODINTEGRACAO` = p_codintegracao AND `STATUS` IN ('AGUARDANDO', 'RESPONDIDA');
    SELECT ROW_COUNT() AS ALTERADOS;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_fo_integracao_reaplicar`

-- @@
CREATE PROCEDURE `sp_fo_integracao_reaplicar`()
BEGIN
    -- O sincronismo legado -> novo pode trazer de volta uma data de integração mais antiga: a realização da última integração
    -- aprovada prevalece (migração 350: DATA_VALIDADE é a data de realização)
    UPDATE `fo_funcionarios` f
      JOIN (SELECT `CODFUNCIONARIO`, MAX(DATE(`DECIDIDA_EM`)) AS V FROM `fo_integracoes` WHERE `STATUS` = 'APROVADA' GROUP BY `CODFUNCIONARIO`) a
        ON a.`CODFUNCIONARIO` = f.`CODFUNCIONARIO`
       SET f.`DATA_VALIDADE` = a.V
     WHERE f.`DATA_VALIDADE` IS NULL OR f.`DATA_VALIDADE` < a.V;
    SELECT ROW_COUNT() AS REAPLICADAS;
END
