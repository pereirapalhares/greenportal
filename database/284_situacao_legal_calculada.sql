-- Migração 284 — Situação legal consolidada automaticamente (slide 26 da apresentação Atlas Schindler 2026).
-- Aplicada por scripts/aplicar-migracao-284-situacao-legal.php, em cada banco de empresa. Pode ser executada mais de uma vez.
--
-- Este arquivo tem a parte fixa: tabelas, views de leitura das telas e procedures de cálculo e decisão.
-- As views de evidência por área (vw_sl_ev_*), a união vw_sl_evidencias, a view de responsáveis vw_sl_responsaveis e os
-- gatilhos que enfileiram o recálculo são montados pelo script, conforme as tabelas e colunas que existem em cada banco.
--
-- Situação calculada por requisito (CODLEGISLACAO) e unidade (CODUNIDADE), a partir dos itens de gestão vinculados:
--   S  = todos os itens ok (ou só com atenção)      PA = parte dos itens com falha
--   N  = nenhum item ok, ou algum item crítico (ex.: licença vencida sem protocolo)
--   AI = nenhum item de gestão vinculado ao requisito
-- A avaliação manual (legislacaoxsituacao) continua existindo; vw_situacao_legal_grid mostra as duas, a divergência e a
-- decisão do responsável (aceitar a calculada ou manter a avaliação com justificativa).
-- Textos comparados com COLLATE explícito nos dois lados: as tabelas não têm a mesma collation em todos os bancos.
-- @@
CREATE TABLE IF NOT EXISTS `situacao_legal_calculada` (
    `CODLEGISLACAO` INT NOT NULL,
    `CODUNIDADE` INT NOT NULL,
    `situacao` VARCHAR(2) NOT NULL,
    `itens_total` INT NOT NULL DEFAULT 0,
    `itens_ok` INT NOT NULL DEFAULT 0,
    `itens_atencao` INT NOT NULL DEFAULT 0,
    `itens_falha` INT NOT NULL DEFAULT 0,
    `itens_criticos` INT NOT NULL DEFAULT 0,
    `resumo` VARCHAR(1000) NULL,
    `detalhes` JSON NULL,
    `calculado_em` DATETIME NOT NULL,
    `alterado_em` DATETIME NOT NULL,
    `decisao` VARCHAR(10) NULL,
    `decisao_situacao` VARCHAR(2) NULL,
    `decisao_manual` VARCHAR(2) NULL,
    `decisao_justificativa` VARCHAR(2000) NULL,
    `decisao_codusuario` INT NULL,
    `decisao_em` DATETIME NULL,
    PRIMARY KEY (`CODLEGISLACAO`, `CODUNIDADE`),
    KEY `ix_slc_unidade_situacao` (`CODUNIDADE`, `situacao`),
    KEY `ix_slc_situacao` (`situacao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS `situacao_legal_historico` (
    `codhistorico` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `CODLEGISLACAO` INT NOT NULL,
    `CODUNIDADE` INT NOT NULL,
    `situacao_anterior` VARCHAR(2) NULL,
    `situacao_nova` VARCHAR(2) NOT NULL,
    `resumo` VARCHAR(1000) NULL,
    `registrado_em` DATETIME NOT NULL,
    PRIMARY KEY (`codhistorico`),
    KEY `ix_slh_requisito` (`CODLEGISLACAO`, `CODUNIDADE`, `registrado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS `situacao_legal_fila` (
    `CODLEGISLACAO` INT NOT NULL,
    `CODUNIDADE` INT NOT NULL DEFAULT 0,
    `enfileirado_em` DATETIME(6) NOT NULL,
    PRIMARY KEY (`CODLEGISLACAO`, `CODUNIDADE`),
    KEY `ix_slf_enfileirado` (`enfileirado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS `situacao_legal_execucoes` (
    `codexecucao` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tipo` VARCHAR(10) NOT NULL,
    `requisitos` INT NOT NULL DEFAULT 0,
    `alterados` INT NOT NULL DEFAULT 0,
    `iniciado_em` DATETIME NOT NULL,
    `concluido_em` DATETIME NOT NULL,
    PRIMARY KEY (`codexecucao`),
    KEY `ix_sle_tipo_inicio` (`tipo`, `iniciado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
-- requer: legislacaoxsituacao
CREATE OR REPLACE VIEW `vw_situacao_legal_manual` AS
SELECT s.`CODLEGISLACAO`, s.`codunidade` AS `CODUNIDADE`,
       CAST(CASE
           WHEN s.`q_pa` > 0 THEN 'PA'
           WHEN s.`q_n` > 0 AND s.`q_s` > 0 THEN 'PA'
           WHEN s.`q_s` > 0 AND s.`q_i` > 0 THEN 'AI'
           WHEN s.`q_n` > 0 AND s.`q_i` > 0 THEN 'N'
           WHEN s.`q_s` > 0 THEN 'S'
           WHEN s.`q_n` > 0 THEN 'N'
           ELSE 'AI' END AS CHAR(2) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS `SITUACAO_MANUAL`,
       s.`ultima_avaliacao` AS `ULTIMA_AVALIACAO`, s.`q_total` AS `AVALIACOES`
  FROM (SELECT `CODLEGISLACAO`, `codunidade`,
               SUM(`atendido` = 'PA') AS `q_pa`, SUM(`atendido` = 'S') AS `q_s`, SUM(`atendido` = 'N') AS `q_n`,
               SUM(`atendido` IS NULL OR `atendido` NOT IN ('S', 'N', 'PA')) AS `q_i`,
               COUNT(*) AS `q_total`, MAX(`data_de_avaliacao`) AS `ultima_avaliacao`
          FROM `legislacaoxsituacao`
         WHERE `CODLEGISLACAO` IS NOT NULL AND `codunidade` IS NOT NULL
         GROUP BY `CODLEGISLACAO`, `codunidade`) s
-- @@
-- requer: legislacaoxsituacao
CREATE OR REPLACE VIEW `vw_situacao_legal_grid` AS
SELECT x.`CODLEGISLACAO`, x.`CODUNIDADE`, x.`SITUACAO_CALCULADA`, x.`SITUACAO_CALCULADA_TEXTO`, x.`MOTIVO`,
       x.`ITENS_TOTAL`, x.`ITENS_OK`, x.`ITENS_ATENCAO`, x.`ITENS_FALHA`, x.`ITENS_CRITICOS`, x.`CALCULADO_EM`, x.`ALTERADO_EM`,
       x.`SITUACAO_MANUAL`, x.`ULTIMA_AVALIACAO`, x.`DECISAO`, x.`DECISAO_JUSTIFICATIVA`, x.`DECISAO_EM`, x.`DECISAO_USUARIO`,
       x.`DECISAO_VALIDA`,
       CASE WHEN x.`SITUACAO_CALCULADA` = 'AI' OR x.`SITUACAO_MANUAL` = 'AI' OR x.`SITUACAO_MANUAL` = x.`SITUACAO_CALCULADA`
                 OR x.`DECISAO_VALIDA` = 1 THEN 0 ELSE 1 END AS `DIVERGENTE`,
       CASE WHEN x.`SITUACAO_CALCULADA` = 'AI' AND x.`SITUACAO_MANUAL` <> 'AI' THEN x.`SITUACAO_MANUAL`
            WHEN x.`DECISAO_VALIDA` = 1 AND x.`DECISAO` = 'MANTIDA' THEN x.`SITUACAO_MANUAL`
            ELSE x.`SITUACAO_CALCULADA` END AS `SITUACAO_FINAL`,
       CASE WHEN x.`SITUACAO_CALCULADA` = 'AI' AND x.`SITUACAO_MANUAL` <> 'AI' THEN 'AVALIACAO'
            WHEN x.`DECISAO_VALIDA` = 1 AND x.`DECISAO` = 'MANTIDA' THEN 'AVALIACAO'
            ELSE 'CALCULADA' END AS `ORIGEM_FINAL`
  FROM (SELECT c.`CODLEGISLACAO`, c.`CODUNIDADE`,
               c.`situacao` COLLATE utf8mb4_unicode_ci AS `SITUACAO_CALCULADA`,
               CASE c.`situacao` WHEN 'S' THEN 'Atendido' WHEN 'PA' THEN 'Parcialmente atendido' WHEN 'N' THEN 'Não atendido'
                    ELSE 'Sem evidências vinculadas' END AS `SITUACAO_CALCULADA_TEXTO`,
               c.`resumo` AS `MOTIVO`, c.`itens_total` AS `ITENS_TOTAL`, c.`itens_ok` AS `ITENS_OK`, c.`itens_atencao` AS `ITENS_ATENCAO`,
               c.`itens_falha` AS `ITENS_FALHA`, c.`itens_criticos` AS `ITENS_CRITICOS`, c.`calculado_em` AS `CALCULADO_EM`,
               c.`alterado_em` AS `ALTERADO_EM`,
               COALESCE(m.`SITUACAO_MANUAL`, 'AI') COLLATE utf8mb4_unicode_ci AS `SITUACAO_MANUAL`,
               m.`ULTIMA_AVALIACAO`, c.`decisao` AS `DECISAO`, c.`decisao_justificativa` AS `DECISAO_JUSTIFICATIVA`,
               c.`decisao_em` AS `DECISAO_EM`, COALESCE(NULLIF(TRIM(u.`nome`), ''), u.`userid`) AS `DECISAO_USUARIO`,
               CASE WHEN c.`decisao` IS NOT NULL
                         AND c.`decisao_situacao` = c.`situacao`
                         AND COALESCE(c.`decisao_manual`, 'AI') COLLATE utf8mb4_unicode_ci = COALESCE(m.`SITUACAO_MANUAL`, 'AI') COLLATE utf8mb4_unicode_ci
                    THEN 1 ELSE 0 END AS `DECISAO_VALIDA`
          FROM `situacao_legal_calculada` c
          LEFT JOIN `vw_situacao_legal_manual` m ON m.`CODLEGISLACAO` = c.`CODLEGISLACAO` AND m.`CODUNIDADE` = c.`CODUNIDADE`
          LEFT JOIN `usuarios` u ON u.`codusuario` = c.`decisao_codusuario`) x
-- @@
CREATE OR REPLACE VIEW `vw_situacao_legal_itens` AS
SELECT c.`CODLEGISLACAO`, c.`CODUNIDADE`, j.`ORDEM`, j.`AREA`, j.`ITEM_ID`, j.`ITEM`, j.`STATUS`, j.`MOTIVO`
  FROM `situacao_legal_calculada` c,
       JSON_TABLE(c.`detalhes`, '$[*]' COLUMNS (
           `ORDEM` FOR ORDINALITY,
           `AREA` VARCHAR(30) PATH '$.area',
           `ITEM_ID` BIGINT PATH '$.item_id',
           `ITEM` VARCHAR(300) PATH '$.item',
           `STATUS` VARCHAR(10) PATH '$.status',
           `MOTIVO` VARCHAR(500) PATH '$.motivo')) j
-- @@
CREATE OR REPLACE VIEW `vw_situacao_legal_historico` AS
SELECT `codhistorico`, `CODLEGISLACAO`, `CODUNIDADE`, `situacao_anterior` AS `SITUACAO_ANTERIOR`, `situacao_nova` AS `SITUACAO_NOVA`,
       `resumo` AS `RESUMO`, `registrado_em` AS `REGISTRADO_EM`
  FROM `situacao_legal_historico`
-- @@
CREATE OR REPLACE VIEW `vw_situacao_legal_totais` AS
SELECT `situacao` AS `SITUACAO`, COUNT(*) AS `TOTAL` FROM `situacao_legal_calculada` GROUP BY `situacao`
-- @@
-- requer: vw_modulolegal_requisitos_filtro_grid
-- Grid de requisitos legais: a view atual (avaliação manual em ATENDIDO) mais a situação calculada, a divergência e a
-- situação consolidada (a que vale para relatórios). Se vw_modulolegal_requisitos_filtro_grid mudar, rodar a migração de novo.
CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_situacao_grid` AS
SELECT d.*,
       CASE c.`situacao` WHEN 'S' THEN 'Atendido' WHEN 'PA' THEN 'Parcialmente atendido' WHEN 'N' THEN 'Não atendido'
            WHEN 'AI' THEN 'Sem evidências vinculadas' ELSE '' END AS `SITUACAO_CALCULADA`,
       CASE WHEN c.`situacao` IS NULL OR c.`situacao` = 'AI' OR d.`SL_MANUAL` = 'AI' OR d.`SL_MANUAL` = c.`situacao`
                 OR (c.`decisao` IS NOT NULL AND c.`decisao_situacao` = c.`situacao` AND COALESCE(c.`decisao_manual`, 'AI') = d.`SL_MANUAL`)
            THEN 'Não' ELSE 'Sim' END AS `DIVERGENCIA`,
       CASE CASE WHEN c.`situacao` IS NULL THEN d.`SL_MANUAL`
                 WHEN c.`situacao` = 'AI' AND d.`SL_MANUAL` <> 'AI' THEN d.`SL_MANUAL`
                 WHEN c.`decisao` = 'MANTIDA' AND c.`decisao_situacao` = c.`situacao` AND COALESCE(c.`decisao_manual`, 'AI') = d.`SL_MANUAL` THEN d.`SL_MANUAL`
                 ELSE c.`situacao` END
            WHEN 'S' THEN 'Atendido' WHEN 'PA' THEN 'Parcialmente atendido' WHEN 'N' THEN 'Não atendido' ELSE 'Indefinido' END AS `SITUACAO_CONSOLIDADA`,
       COALESCE(c.`resumo`, '') AS `MOTIVO_CALCULO`
  FROM (SELECT f.*, CAST(CASE WHEN CONVERT(f.`ATENDIDO` USING utf8mb4) COLLATE utf8mb4_unicode_ci = 'Sim' THEN 'S' WHEN CONVERT(f.`ATENDIDO` USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE 'Parcial%' THEN 'PA' WHEN CONVERT(f.`ATENDIDO` USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE 'N%' THEN 'N'
                         ELSE 'AI' END AS CHAR(2) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS `SL_MANUAL`
          FROM `vw_modulolegal_requisitos_filtro_grid` f) d
  LEFT JOIN `situacao_legal_calculada` c ON c.`CODLEGISLACAO` = d.`CODLEGISLACAO` AND c.`CODUNIDADE` = d.`CODUNIDADE`
-- @@
DROP PROCEDURE IF EXISTS `sp_situacao_legal_calcular_alvo`
-- @@
CREATE PROCEDURE `sp_situacao_legal_calcular_alvo`(IN p_tipo VARCHAR(10), OUT p_requisitos INT, OUT p_alterados INT)
BEGIN
    -- Uso interno: calcula os pares (requisito, unidade) que estão em tmp_sl_alvo, grava o histórico das mudanças e a situação.
    -- p_tipo = COMPLETO também remove as situações de requisitos que deixaram de ser aplicáveis (legislacaoxempresa).
    DECLARE v_inicio DATETIME DEFAULT NOW();
    SET SESSION group_concat_max_len = 1048576;

    -- Evidências dos requisitos do alvo (a view é lida uma vez); vínculo com unidade 0 vale para todas as unidades do requisito
    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_evb`;
    CREATE TEMPORARY TABLE `tmp_sl_evb` ENGINE=InnoDB AS
    SELECT e.`CODLEGISLACAO`, COALESCE(e.`CODUNIDADE`, 0) AS `CODUNIDADE`, e.`AREA`, e.`ITEM_ID`, e.`ITEM`, e.`STATUS`, e.`MOTIVO`
      FROM `vw_sl_evidencias` e
     WHERE e.`CODLEGISLACAO` IN (SELECT `CODLEGISLACAO` FROM `tmp_sl_alvo`);
    ALTER TABLE `tmp_sl_evb` ADD INDEX `ix_tmp_sl_evb` (`CODLEGISLACAO`, `CODUNIDADE`);

    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_ev`;
    CREATE TEMPORARY TABLE `tmp_sl_ev` (
        `CODLEGISLACAO` INT NOT NULL, `CODUNIDADE` INT NOT NULL, `AREA` VARCHAR(30) NOT NULL, `ITEM_ID` BIGINT NOT NULL,
        `ITEM` VARCHAR(300) NULL, `STATUS` VARCHAR(10) NOT NULL, `MOTIVO` VARCHAR(500) NULL,
        PRIMARY KEY (`CODLEGISLACAO`, `CODUNIDADE`, `AREA`, `ITEM_ID`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    INSERT IGNORE INTO `tmp_sl_ev` (`CODLEGISLACAO`, `CODUNIDADE`, `AREA`, `ITEM_ID`, `ITEM`, `STATUS`, `MOTIVO`)
    SELECT a.`CODLEGISLACAO`, a.`CODUNIDADE`, e.`AREA`, COALESCE(e.`ITEM_ID`, 0), e.`ITEM`, e.`STATUS`, e.`MOTIVO`
      FROM `tmp_sl_alvo` a JOIN `tmp_sl_evb` e ON e.`CODLEGISLACAO` = a.`CODLEGISLACAO` AND e.`CODUNIDADE` = a.`CODUNIDADE`;
    INSERT IGNORE INTO `tmp_sl_ev` (`CODLEGISLACAO`, `CODUNIDADE`, `AREA`, `ITEM_ID`, `ITEM`, `STATUS`, `MOTIVO`)
    SELECT a.`CODLEGISLACAO`, a.`CODUNIDADE`, e.`AREA`, COALESCE(e.`ITEM_ID`, 0), e.`ITEM`, e.`STATUS`, e.`MOTIVO`
      FROM `tmp_sl_alvo` a JOIN `tmp_sl_evb` e ON e.`CODLEGISLACAO` = a.`CODLEGISLACAO` AND e.`CODUNIDADE` = 0
     WHERE a.`CODUNIDADE` <> 0;
    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_evb`;

    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_agg`;
    CREATE TEMPORARY TABLE `tmp_sl_agg` ENGINE=InnoDB AS
    SELECT `CODLEGISLACAO`, `CODUNIDADE`, COUNT(*) AS `total`,
           SUM(`STATUS` = 'OK') AS `ok`, SUM(`STATUS` = 'ATENCAO') AS `atencao`,
           SUM(`STATUS` = 'FALHA') AS `falha`, SUM(`STATUS` = 'CRITICO') AS `critico`,
           JSON_ARRAYAGG(JSON_OBJECT('area', `AREA`, 'item_id', `ITEM_ID`, 'item', `ITEM`, 'status', `STATUS`, 'motivo', `MOTIVO`)) AS `detalhes`,
           GROUP_CONCAT(CASE WHEN `STATUS` <> 'OK' THEN CONCAT(
                   CASE `AREA` WHEN 'LICENCAS' THEN 'Licença' WHEN 'MEDICAO' THEN 'Medição' WHEN 'TREINAMENTOS' THEN 'Treinamento'
                        WHEN 'INFRAESTRUTURA' THEN 'Infraestrutura' WHEN 'CONTROLES' THEN 'Controle' WHEN 'HOMOLOGACAO' THEN 'Fornecedor'
                        ELSE `AREA` END, ' ', `ITEM`, ': ', `MOTIVO`) END
               ORDER BY FIELD(`STATUS`, 'CRITICO', 'FALHA', 'ATENCAO'), `AREA`, `ITEM` SEPARATOR '; ') AS `problemas`
      FROM `tmp_sl_ev`
     GROUP BY `CODLEGISLACAO`, `CODUNIDADE`;
    ALTER TABLE `tmp_sl_agg` ADD PRIMARY KEY (`CODLEGISLACAO`, `CODUNIDADE`);

    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_res`;
    CREATE TEMPORARY TABLE `tmp_sl_res` ENGINE=InnoDB AS
    SELECT a.`CODLEGISLACAO`, a.`CODUNIDADE`,
           CAST(CASE WHEN g.`total` IS NULL THEN 'AI'
                     WHEN g.`critico` > 0 THEN 'N'
                     WHEN g.`falha` = 0 THEN 'S'
                     WHEN g.`ok` + g.`atencao` = 0 THEN 'N'
                     ELSE 'PA' END AS CHAR(2) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS `situacao`,
           COALESCE(g.`total`, 0) AS `itens_total`, COALESCE(g.`ok`, 0) AS `itens_ok`, COALESCE(g.`atencao`, 0) AS `itens_atencao`,
           COALESCE(g.`falha`, 0) AS `itens_falha`, COALESCE(g.`critico`, 0) AS `itens_criticos`,
           COALESCE(g.`detalhes`, JSON_ARRAY()) AS `detalhes`,
           LEFT(CASE WHEN g.`total` IS NULL
                     THEN 'Nenhum item de gestão vinculado ao requisito (licenças, medições, treinamentos, infraestrutura, controles ou fornecedores).'
                     ELSE CONCAT(g.`total`, IF(g.`total` = 1, ' item: ', ' itens: '),
                                 CONCAT_WS(', ', IF(g.`ok` > 0, CONCAT(g.`ok`, ' ok'), NULL),
                                                 IF(g.`atencao` > 0, CONCAT(g.`atencao`, ' com atenção'), NULL),
                                                 IF(g.`falha` > 0, CONCAT(g.`falha`, ' com falha'), NULL),
                                                 IF(g.`critico` > 0, CONCAT(g.`critico`, IF(g.`critico` = 1, ' crítico', ' críticos')), NULL)),
                                 IF(g.`problemas` IS NULL, '.', CONCAT('. ', g.`problemas`))) END, 1000) AS `resumo`
      FROM `tmp_sl_alvo` a
      LEFT JOIN `tmp_sl_agg` g ON g.`CODLEGISLACAO` = a.`CODLEGISLACAO` AND g.`CODUNIDADE` = a.`CODUNIDADE`;

    INSERT INTO `situacao_legal_historico` (`CODLEGISLACAO`, `CODUNIDADE`, `situacao_anterior`, `situacao_nova`, `resumo`, `registrado_em`)
    SELECT r.`CODLEGISLACAO`, r.`CODUNIDADE`, c.`situacao`, r.`situacao`, r.`resumo`, v_inicio
      FROM `tmp_sl_res` r
      LEFT JOIN `situacao_legal_calculada` c ON c.`CODLEGISLACAO` = r.`CODLEGISLACAO` AND c.`CODUNIDADE` = r.`CODUNIDADE`
     WHERE c.`situacao` IS NULL OR c.`situacao` COLLATE utf8mb4_unicode_ci <> r.`situacao` COLLATE utf8mb4_unicode_ci;
    SET p_alterados = ROW_COUNT();

    INSERT INTO `situacao_legal_calculada`
        (`CODLEGISLACAO`, `CODUNIDADE`, `situacao`, `itens_total`, `itens_ok`, `itens_atencao`, `itens_falha`, `itens_criticos`,
         `resumo`, `detalhes`, `calculado_em`, `alterado_em`)
    SELECT n.`CODLEGISLACAO`, n.`CODUNIDADE`, n.`situacao`, n.`itens_total`, n.`itens_ok`, n.`itens_atencao`, n.`itens_falha`,
           n.`itens_criticos`, n.`resumo`, n.`detalhes`, v_inicio, v_inicio
      FROM `tmp_sl_res` n
    ON DUPLICATE KEY UPDATE
        `alterado_em` = IF(`situacao_legal_calculada`.`situacao` COLLATE utf8mb4_unicode_ci = n.`situacao` COLLATE utf8mb4_unicode_ci,
                           `situacao_legal_calculada`.`alterado_em`, v_inicio),
        `situacao` = n.`situacao`, `itens_total` = n.`itens_total`, `itens_ok` = n.`itens_ok`, `itens_atencao` = n.`itens_atencao`,
        `itens_falha` = n.`itens_falha`, `itens_criticos` = n.`itens_criticos`, `resumo` = n.`resumo`, `detalhes` = n.`detalhes`,
        `calculado_em` = v_inicio;

    IF p_tipo = 'COMPLETO' THEN
        DELETE c FROM `situacao_legal_calculada` c
          LEFT JOIN `tmp_sl_alvo` a ON a.`CODLEGISLACAO` = c.`CODLEGISLACAO` AND a.`CODUNIDADE` = c.`CODUNIDADE`
         WHERE a.`CODLEGISLACAO` IS NULL;
    END IF;

    SELECT COUNT(*) INTO p_requisitos FROM `tmp_sl_res`;
    IF p_tipo IN ('COMPLETO', 'FILA') THEN
        INSERT INTO `situacao_legal_execucoes` (`tipo`, `requisitos`, `alterados`, `iniciado_em`, `concluido_em`)
        VALUES (p_tipo, p_requisitos, p_alterados, v_inicio, NOW());
    END IF;
    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_ev`;
    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_agg`;
    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_res`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_situacao_legal_recalcular`
-- @@
CREATE PROCEDURE `sp_situacao_legal_recalcular`(IN p_codlegislacao INT, IN p_codunidade INT)
BEGIN
    -- Recalcula na hora. Requisito e unidade NULL = todos (cálculo completo).
    DECLARE v_requisitos INT DEFAULT 0;
    DECLARE v_alterados INT DEFAULT 0;
    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_alvo`;
    CREATE TEMPORARY TABLE `tmp_sl_alvo` (`CODLEGISLACAO` INT NOT NULL, `CODUNIDADE` INT NOT NULL,
        PRIMARY KEY (`CODLEGISLACAO`, `CODUNIDADE`)) ENGINE=InnoDB;
    INSERT IGNORE INTO `tmp_sl_alvo` (`CODLEGISLACAO`, `CODUNIDADE`)
    SELECT `CODLEGISLACAO`, `codunidade` FROM `legislacaoxempresa`
     WHERE `CODLEGISLACAO` IS NOT NULL AND `codunidade` IS NOT NULL
       AND (p_codlegislacao IS NULL OR `CODLEGISLACAO` = p_codlegislacao)
       AND (p_codunidade IS NULL OR `codunidade` = p_codunidade);
    CALL `sp_situacao_legal_calcular_alvo`(IF(p_codlegislacao IS NULL AND p_codunidade IS NULL, 'COMPLETO', 'PONTUAL'), v_requisitos, v_alterados);
    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_alvo`;
    SELECT v_requisitos AS `requisitos`, v_alterados AS `alterados`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_situacao_legal_manutencao`
-- @@
CREATE PROCEDURE `sp_situacao_legal_manutencao`(IN p_limite INT)
BEGIN
    -- Chamada pelo serviço de responsabilidades (a cada 5 minutos):
    --   uma vez por dia, o cálculo completo (pega o que muda só com a data, como vencimentos), que também esvazia a fila;
    --   nas demais vezes, os requisitos enfileirados pelos gatilhos das tabelas de gestão (até p_limite por vez).
    DECLARE v_corte DATETIME(6) DEFAULT NOW(6);
    DECLARE v_requisitos INT DEFAULT 0;
    DECLARE v_alterados INT DEFAULT 0;
    DECLARE v_tipo VARCHAR(10) DEFAULT 'FILA';
    SET p_limite = LEAST(GREATEST(COALESCE(p_limite, 500), 1), 5000);
    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_alvo`;
    CREATE TEMPORARY TABLE `tmp_sl_alvo` (`CODLEGISLACAO` INT NOT NULL, `CODUNIDADE` INT NOT NULL,
        PRIMARY KEY (`CODLEGISLACAO`, `CODUNIDADE`)) ENGINE=InnoDB;

    IF NOT EXISTS (SELECT 1 FROM `situacao_legal_execucoes` WHERE `tipo` = 'COMPLETO' AND `iniciado_em` >= CURDATE()) THEN
        SET v_tipo = 'COMPLETO';
        INSERT IGNORE INTO `tmp_sl_alvo` (`CODLEGISLACAO`, `CODUNIDADE`)
        SELECT `CODLEGISLACAO`, `codunidade` FROM `legislacaoxempresa` WHERE `CODLEGISLACAO` IS NOT NULL AND `codunidade` IS NOT NULL;
        CALL `sp_situacao_legal_calcular_alvo`('COMPLETO', v_requisitos, v_alterados);
        DELETE FROM `situacao_legal_fila` WHERE `enfileirado_em` <= v_corte;
    ELSE
        DROP TEMPORARY TABLE IF EXISTS `tmp_sl_fila`;
        CREATE TEMPORARY TABLE `tmp_sl_fila` (`CODLEGISLACAO` INT NOT NULL, `CODUNIDADE` INT NOT NULL,
            PRIMARY KEY (`CODLEGISLACAO`, `CODUNIDADE`)) ENGINE=InnoDB;
        INSERT INTO `tmp_sl_fila` (`CODLEGISLACAO`, `CODUNIDADE`)
        SELECT `CODLEGISLACAO`, `CODUNIDADE` FROM `situacao_legal_fila`
         WHERE `enfileirado_em` <= v_corte ORDER BY `enfileirado_em` LIMIT p_limite;
        IF EXISTS (SELECT 1 FROM `tmp_sl_fila`) THEN
            INSERT IGNORE INTO `tmp_sl_alvo` (`CODLEGISLACAO`, `CODUNIDADE`)
            SELECT x.`CODLEGISLACAO`, x.`codunidade`
              FROM `legislacaoxempresa` x
              JOIN `tmp_sl_fila` f ON f.`CODLEGISLACAO` = x.`CODLEGISLACAO` AND (f.`CODUNIDADE` = 0 OR f.`CODUNIDADE` = x.`codunidade`)
             WHERE x.`codunidade` IS NOT NULL;
            DELETE q FROM `situacao_legal_fila` q
              JOIN `tmp_sl_fila` f ON f.`CODLEGISLACAO` = q.`CODLEGISLACAO` AND f.`CODUNIDADE` = q.`CODUNIDADE`
             WHERE q.`enfileirado_em` <= v_corte;
            CALL `sp_situacao_legal_calcular_alvo`('FILA', v_requisitos, v_alterados);
        END IF;
        DROP TEMPORARY TABLE IF EXISTS `tmp_sl_fila`;
    END IF;
    DROP TEMPORARY TABLE IF EXISTS `tmp_sl_alvo`;
    SELECT v_tipo AS `tipo`, v_requisitos AS `requisitos`, v_alterados AS `alterados`,
           (SELECT COUNT(*) FROM `situacao_legal_fila`) AS `pendentes`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_situacao_legal_decidir`
-- @@
-- requer: legislacaoxsituacao
CREATE PROCEDURE `sp_situacao_legal_decidir`(IN p_codlegislacao INT, IN p_codunidade INT, IN p_decisao VARCHAR(10),
    IN p_justificativa VARCHAR(2000), IN p_codusuario INT)
BEGIN
    -- Decisão do responsável quando a avaliação manual diverge da calculada:
    --   ACEITAR = a situação calculada passa a valer; MANTER = a avaliação manual continua valendo (justificativa obrigatória);
    --   LIMPAR = desfaz a decisão. A decisão deixa de valer sozinha se a situação calculada ou a avaliação manual mudarem.
    DECLARE v_manual VARCHAR(2) DEFAULT NULL;
    SET p_decisao = UPPER(TRIM(COALESCE(p_decisao, '')));
    IF p_decisao NOT IN ('ACEITAR', 'MANTER', 'LIMPAR') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Decisão inválida: use ACEITAR, MANTER ou LIMPAR.';
    END IF;
    IF p_decisao = 'MANTER' AND CHAR_LENGTH(TRIM(COALESCE(p_justificativa, ''))) < 10 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Para manter a avaliação, informe a justificativa (pelo menos 10 caracteres).';
    END IF;
    SELECT `SITUACAO_MANUAL` INTO v_manual FROM `vw_situacao_legal_manual`
     WHERE `CODLEGISLACAO` = p_codlegislacao AND `CODUNIDADE` = p_codunidade LIMIT 1;
    UPDATE `situacao_legal_calculada`
       SET `decisao` = CASE p_decisao WHEN 'ACEITAR' THEN 'ACEITA' WHEN 'MANTER' THEN 'MANTIDA' ELSE NULL END,
           `decisao_situacao` = IF(p_decisao = 'LIMPAR', NULL, `situacao`),
           `decisao_manual` = IF(p_decisao = 'LIMPAR', NULL, COALESCE(v_manual, 'AI')),
           `decisao_justificativa` = IF(p_decisao = 'LIMPAR', NULL, NULLIF(TRIM(COALESCE(p_justificativa, '')), '')),
           `decisao_codusuario` = IF(p_decisao = 'LIMPAR', NULL, NULLIF(p_codusuario, 0)),
           `decisao_em` = IF(p_decisao = 'LIMPAR', NULL, NOW())
     WHERE `CODLEGISLACAO` = p_codlegislacao AND `CODUNIDADE` = p_codunidade;
    SELECT ROW_COUNT() AS `afetados`;
END
