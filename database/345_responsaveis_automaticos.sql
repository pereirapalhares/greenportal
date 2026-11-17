-- Migração 345 — Gestão de tarefas, etapa 3: responsáveis automáticos pelo perfil de atribuições (migração 268).
-- Aplicada por scripts/aplicar-migracao-345-responsaveis-automaticos.php em cada banco de empresa com o perfil de atribuições.
-- Pode ser executada mais de uma vez.
--
-- Decisões do usuário (23/10/2026):
--   * vale para as tarefas dos potenciais aprovados da IA e para os itens novos dos pilares cadastrados sem responsável;
--   * mais de uma pessoa executa o pilar na unidade: vai para quem tem menos tarefas abertas (as outras acompanham);
--   * ninguém no perfil: a tarefa fica com quem pediu a análise (como antes) e o ADM DA EMPRESA recebe um alerta; o item fica
--     sem responsável e o ADM recebe o alerta;
--   * quem ACOMPANHA recebe alerta quando a tarefa é criada (ou o item é atribuído); quem APROVA, quando a tarefa é concluída.
-- A view dos itens de cada pilar (vw_resp_auto_itens) depende das colunas de cada banco e é montada pelo script.
-- @@
CREATE TABLE IF NOT EXISTS `resp_auto_controle` (
    `AREA` VARCHAR(20) NOT NULL COMMENT 'LICENCAS, MONITORAMENTO, TREINAMENTOS, INFRAESTRUTURA, CONTROLES',
    `ULTIMO_ID` BIGINT NOT NULL COMMENT 'Itens até este código já existiam na migração: não recebem responsável automático',
    `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`AREA`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS `resp_auto_registro` (
    `CODREGISTRO` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `AREA` VARCHAR(20) NOT NULL COMMENT 'Área do item (LICENCAS ...) ou TAREFA (tarefa de potencial da IA)',
    `ITEM_ID` BIGINT NOT NULL COMMENT 'Código do item ou da tarefa (green_tasks.codtarefa)',
    `ITEM` VARCHAR(300) NULL,
    `CODUNIDADE` BIGINT NULL,
    `MODULO` VARCHAR(60) NULL COMMENT 'Bloco do perfil de atribuições (MODULO_LEGAL, HOMOLOGACAO_FORNECEDORES)',
    `PILAR` VARCHAR(100) NULL,
    `SITUACAO` VARCHAR(20) NOT NULL COMMENT 'AGUARDANDO, ATRIBUIDO, SEM_CANDIDATO, JA_TINHA',
    `CODUSUARIO` BIGINT NULL COMMENT 'Executor escolhido',
    `CODATRIBUICAO` BIGINT NULL COMMENT 'responsabilidade_atribuicoes criada (itens)',
    `ACOMPANHANTES` VARCHAR(1000) NULL COMMENT 'Códigos dos usuários que acompanham, separados por vírgula',
    `APROVADORES` VARCHAR(1000) NULL COMMENT 'Códigos dos usuários que aprovam, separados por vírgula',
    `MOTIVO` VARCHAR(1000) NULL,
    `VISTO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `DECIDIDO_EM` DATETIME NULL,
    `APROVADORES_AVISADOS_EM` DATETIME NULL,
    PRIMARY KEY (`CODREGISTRO`),
    UNIQUE KEY `ux_resp_auto_item` (`AREA`, `ITEM_ID`),
    KEY `ix_resp_auto_situacao` (`SITUACAO`, `VISTO_EM`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
-- Candidatos do perfil de atribuições vigente: uma linha por usuário, módulo, pilar, papel e unidade (CODUNIDADE NULL = o
-- usuário não marcou unidades no perfil: vale para todas), com as tarefas abertas de cada um (critério de desempate)
CREATE OR REPLACE VIEW `vw_resp_auto_candidatos` AS
SELECT p.`codusuario` AS `CODUSUARIO`, p.`usuario_nome` AS `NOME`, p.`modulo` AS `MODULO`, p.`pilar` AS `PILAR`, p.`papel` AS `PAPEL`,
       un.`codunidade` AS `CODUNIDADE`,
       (SELECT COUNT(*) FROM `green_tasks` t WHERE t.`codusuario_destino` = p.`codusuario` AND t.`status` IN ('PENDENTE', 'PROCESSANDO')) AS `TAREFAS_ABERTAS`
  FROM `vw_perfil_atribuicoes_itens_vigentes` p
  LEFT JOIN `vw_perfil_atribuicoes_itens_vigentes` un ON un.`codusuario` = p.`codusuario` AND un.`tipo` = 'UNIDADE' AND un.`codunidade` IS NOT NULL
 WHERE p.`tipo` = 'PILAR' AND p.`papel` IN ('EXECUTA', 'APROVA', 'ACOMPANHA')
-- @@
-- ADM DA EMPRESA ativos (aviso de "ninguém no perfil"); sem nenhum, o ADMINISTRADOR
CREATE OR REPLACE VIEW `vw_resp_auto_adm` AS
SELECT u.`codusuario` AS `CODUSUARIO`, u.`nome` AS `NOME`, CAST(IF(UPPER(TRIM(p.`codigo`)) = 'ADMINISTRADOR', 1, 0) AS SIGNED) AS `ADMINISTRADOR`
  FROM `usuarios` u JOIN `perfis` p ON p.`codperfil` = u.`codperfil`
 WHERE COALESCE(u.`ativo`, 0) = 1
   AND (UPPER(TRIM(p.`codigo`)) = 'ADMINISTRADOR' OR UPPER(REPLACE(REPLACE(TRIM(p.`codigo`), '_', ' '), '-', ' ')) IN ('ADM DA EMPRESA', 'ADM EMPRESA'))
-- @@
CREATE OR REPLACE VIEW `vw_resp_auto_registro` AS
SELECT r.`CODREGISTRO`, r.`AREA`, r.`ITEM_ID`, r.`ITEM`, r.`CODUNIDADE`, r.`MODULO`, r.`PILAR`, r.`SITUACAO`,
       (CASE r.`SITUACAO` WHEN 'ATRIBUIDO' THEN 'Responsável indicado pelo perfil' WHEN 'SEM_CANDIDATO' THEN 'Ninguém no perfil'
            WHEN 'JA_TINHA' THEN 'Já tinha responsável' ELSE 'Aguardando' END) AS `SITUACAO_DESC`,
       r.`CODUSUARIO`, u.`nome` AS `RESPONSAVEL`, r.`CODATRIBUICAO`, r.`ACOMPANHANTES`, r.`APROVADORES`, r.`MOTIVO`,
       r.`VISTO_EM`, r.`DECIDIDO_EM`, r.`APROVADORES_AVISADOS_EM`
  FROM `resp_auto_registro` r LEFT JOIN `usuarios` u ON u.`codusuario` = r.`CODUSUARIO`
-- @@
DROP PROCEDURE IF EXISTS `sp_resp_auto_pendentes`
-- @@
CREATE PROCEDURE `sp_resp_auto_pendentes`(IN p_limite INT)
BEGIN
    -- Itens novos (código acima do registrado na migração) sem responsável, ainda não decididos. O item visto pela primeira vez
    -- fica AGUARDANDO; só recebe responsável automático depois de 1 hora sem ninguém (quem cadastrou pode indicar antes).
    INSERT IGNORE INTO `resp_auto_registro` (`AREA`, `ITEM_ID`, `ITEM`, `CODUNIDADE`, `MODULO`, `PILAR`, `SITUACAO`)
    SELECT i.`AREA`, i.`ITEM_ID`, LEFT(i.`ITEM`, 300), i.`CODUNIDADE`, i.`MODULO`, i.`PILAR`, 'AGUARDANDO'
      FROM `vw_resp_auto_itens` i
      JOIN `resp_auto_controle` c ON c.`AREA` COLLATE utf8mb4_unicode_ci = i.`AREA` COLLATE utf8mb4_unicode_ci AND i.`ITEM_ID` > c.`ULTIMO_ID`
     WHERE i.`TEM_RESPONSAVEL` = 0;
    -- Aguardando que ganhou responsável no meio do caminho: encerra sem atribuir
    UPDATE `resp_auto_registro` r
      JOIN `vw_resp_auto_itens` i ON i.`AREA` COLLATE utf8mb4_unicode_ci = r.`AREA` COLLATE utf8mb4_unicode_ci AND i.`ITEM_ID` = r.`ITEM_ID`
       SET r.`SITUACAO` = 'JA_TINHA', r.`DECIDIDO_EM` = NOW(), r.`MOTIVO` = 'O responsável foi indicado no cadastro antes da indicação automática.'
     WHERE r.`SITUACAO` = 'AGUARDANDO' AND i.`TEM_RESPONSAVEL` = 1;
    SELECT r.`AREA`, r.`ITEM_ID`, r.`ITEM`, r.`CODUNIDADE`, r.`MODULO`, r.`PILAR`, i.`ENTIDADE`, i.`MODULO_RESP`, i.`PAGINA`, i.`CHAVE`
      FROM `resp_auto_registro` r
      JOIN `vw_resp_auto_itens` i ON i.`AREA` COLLATE utf8mb4_unicode_ci = r.`AREA` COLLATE utf8mb4_unicode_ci AND i.`ITEM_ID` = r.`ITEM_ID`
     WHERE r.`SITUACAO` = 'AGUARDANDO' AND r.`VISTO_EM` <= NOW() - INTERVAL 1 HOUR AND i.`TEM_RESPONSAVEL` = 0
     ORDER BY r.`CODREGISTRO`
     LIMIT 200;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_resp_auto_atribuir`
-- @@
CREATE PROCEDURE `sp_resp_auto_atribuir`(IN p_area VARCHAR(20), IN p_item BIGINT, IN p_entidade VARCHAR(100), IN p_modulo_resp VARCHAR(80),
    IN p_usuario BIGINT, IN p_acompanhantes VARCHAR(1000), IN p_aprovadores VARCHAR(1000), IN p_motivo VARCHAR(1000))
BEGIN
    -- Item sem responsável: cria a responsabilidade direta (camada de responsabilidades, migração 093) para o executor escolhido
    -- e registra a decisão. p_usuario NULL ou 0 = ninguém no perfil (SEM_CANDIDATO, só registra).
    DECLARE v_modulo BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_atrib BIGINT DEFAULT NULL;
    IF COALESCE(p_usuario, 0) > 0 THEN
        SELECT `CODMODULO` INTO v_modulo FROM `responsabilidade_modulos` WHERE `CODIGO` COLLATE utf8mb4_unicode_ci = p_modulo_resp COLLATE utf8mb4_unicode_ci LIMIT 1;
        IF v_modulo IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Módulo de responsabilidade não encontrado'; END IF;
        INSERT INTO `responsabilidade_atribuicoes` (`CODMODULO`, `ORIGEM`, `ENTIDADE`, `REGISTRO_ID`, `TIPO_DESTINO`, `CODUSUARIO`, `PRIORIDADE`, `PRINCIPAL`, `VALIDO_DE`, `ATIVO`, `OBSERVACAO`)
        VALUES (v_modulo, 'DIRETA', p_entidade, CAST(p_item AS CHAR), 'USUARIO', p_usuario, 0, 1, CURDATE(), 1, LEFT(CONCAT('Indicado automaticamente pelo perfil de atribuições. ', COALESCE(p_motivo, '')), 1000));
        SET v_atrib = LAST_INSERT_ID();
    END IF;
    INSERT INTO `resp_auto_registro` (`AREA`, `ITEM_ID`, `SITUACAO`, `CODUSUARIO`, `CODATRIBUICAO`, `ACOMPANHANTES`, `APROVADORES`, `MOTIVO`, `DECIDIDO_EM`)
    VALUES (p_area, p_item, IF(COALESCE(p_usuario, 0) > 0, 'ATRIBUIDO', 'SEM_CANDIDATO'), NULLIF(p_usuario, 0), v_atrib, p_acompanhantes, p_aprovadores, p_motivo, NOW())
    ON DUPLICATE KEY UPDATE `SITUACAO` = VALUES(`SITUACAO`), `CODUSUARIO` = VALUES(`CODUSUARIO`), `CODATRIBUICAO` = VALUES(`CODATRIBUICAO`),
        `ACOMPANHANTES` = VALUES(`ACOMPANHANTES`), `APROVADORES` = VALUES(`APROVADORES`), `MOTIVO` = VALUES(`MOTIVO`), `DECIDIDO_EM` = NOW();
    SELECT v_atrib AS `CODATRIBUICAO`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_resp_auto_tarefa`
-- @@
CREATE PROCEDURE `sp_resp_auto_tarefa`(IN p_tarefa BIGINT, IN p_item VARCHAR(300), IN p_unidade BIGINT, IN p_modulo VARCHAR(60), IN p_pilar VARCHAR(100),
    IN p_usuario BIGINT, IN p_encontrado TINYINT, IN p_acompanhantes VARCHAR(1000), IN p_aprovadores VARCHAR(1000), IN p_motivo VARCHAR(1000))
BEGIN
    -- Tarefa de potencial da IA: registra quem foi indicado (ou que ninguém no perfil executa o pilar) e quem aprova/acompanha
    INSERT INTO `resp_auto_registro` (`AREA`, `ITEM_ID`, `ITEM`, `CODUNIDADE`, `MODULO`, `PILAR`, `SITUACAO`, `CODUSUARIO`, `ACOMPANHANTES`, `APROVADORES`, `MOTIVO`, `DECIDIDO_EM`)
    VALUES ('TAREFA', p_tarefa, LEFT(p_item, 300), NULLIF(p_unidade, 0), p_modulo, p_pilar, IF(COALESCE(p_encontrado, 0) = 1, 'ATRIBUIDO', 'SEM_CANDIDATO'),
            NULLIF(p_usuario, 0), p_acompanhantes, p_aprovadores, p_motivo, NOW())
    ON DUPLICATE KEY UPDATE `CODREGISTRO` = `CODREGISTRO`;
    SELECT ROW_COUNT() AS `REGISTRADAS`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_resp_auto_alerta`
-- @@
CREATE PROCEDURE `sp_resp_auto_alerta`(IN p_usuario BIGINT, IN p_titulo VARCHAR(180), IN p_mensagem TEXT, IN p_link VARCHAR(500))
BEGIN
    -- Alerta único por pessoa e link (o link leva a chave do aviso)
    IF COALESCE(p_usuario, 0) > 0 AND NOT EXISTS (SELECT 1 FROM `alertas` WHERE `codusuario` = p_usuario AND `link` COLLATE utf8mb4_unicode_ci = p_link COLLATE utf8mb4_unicode_ci) THEN
        INSERT INTO `alertas` (`codusuario`, `titulo`, `mensagem`, `link`, `lido`) VALUES (p_usuario, LEFT(p_titulo, 180), p_mensagem, LEFT(p_link, 500), 0);
        SELECT LAST_INSERT_ID() AS `CODALERTA`;
    ELSE
        SELECT 0 AS `CODALERTA`;
    END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_tarefa_concluir`
-- @@
CREATE PROCEDURE `sp_tarefa_concluir`(IN p_tarefa BIGINT, IN p_usuario BIGINT, IN p_observacao VARCHAR(2000))
BEGIN
    -- Conclusão da tarefa pelo responsável (Home › Agenda). Só o destinatário conclui, e só tarefa pendente.
    -- Devolve a tarefa e quem aprova (registro da indicação automática), para o aviso de conclusão.
    DECLARE v_destino BIGINT DEFAULT NULL;
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    SELECT `codusuario_destino`, `status` INTO v_destino, v_status FROM `green_tasks` WHERE `codtarefa` = p_tarefa;
    IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tarefa não encontrada.'; END IF;
    IF COALESCE(v_destino, 0) <> COALESCE(p_usuario, -1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Só o responsável pela tarefa pode concluí-la.'; END IF;
    IF v_status NOT IN ('PENDENTE', 'PROCESSANDO') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A tarefa já foi concluída ou cancelada.'; END IF;
    UPDATE `green_tasks` SET `status` = 'CONCLUIDA', `bloqueado_ate` = NULL, `processado_em` = NOW(), `erro` = NULL WHERE `codtarefa` = p_tarefa;
    INSERT INTO `green_task_history` (`codtarefa`, `status`, `detalhe`)
    VALUES (p_tarefa, 'CONCLUIDA', LEFT(CONCAT('Concluída pelo responsável', IF(COALESCE(TRIM(p_observacao), '') = '', '.', CONCAT(': ', TRIM(p_observacao)))), 2000));
    UPDATE `resp_auto_registro` SET `APROVADORES_AVISADOS_EM` = NOW() WHERE `AREA` = 'TAREFA' AND `ITEM_ID` = p_tarefa AND `APROVADORES_AVISADOS_EM` IS NULL;
    SELECT t.`codtarefa` AS `CODTAREFA`, t.`titulo` AS `TITULO`, t.`codunidade` AS `CODUNIDADE`, r.`APROVADORES`, r.`MODULO`, r.`PILAR`
      FROM `green_tasks` t LEFT JOIN `resp_auto_registro` r ON r.`AREA` = 'TAREFA' AND r.`ITEM_ID` = t.`codtarefa`
     WHERE t.`codtarefa` = p_tarefa;
END
