-- Migração 268 — Perfil de atribuições do usuário (banco de cada empresa).
-- O usuário responde o questionário PERFIL_ATRIBUICOES (catálogo em guniversal_dbp, motor de questionários); as respostas viram
-- itens estruturados (unidade, módulo, pilar, papel, tema...) usados depois para indicar responsáveis pelas tarefas.
-- Ciclo: o usuário envia (PENDENTE_APROVACAO) → o gestor (usuarios.codusuario_superior) aprova (APROVADO, vigente por 12 meses)
-- ou devolve (DEVOLVIDO, o usuário responde de novo). Um novo envio substitui o pendente anterior (SUBSTITUIDO); o histórico fica.
-- Aplicada por scripts/aplicar-migracao-268-perfil-atribuicoes.php (blocos separados por "-- @@").

CREATE TABLE IF NOT EXISTS `perfil_atribuicoes` (
    `codperfilatrib` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codusuario` BIGINT UNSIGNED NOT NULL,
    `codgestor` BIGINT UNSIGNED NULL COMMENT 'Gestor que deve aprovar (usuarios.codusuario_superior no envio)',
    `codquestionario` BIGINT UNSIGNED NOT NULL,
    `codversao` BIGINT UNSIGNED NOT NULL,
    `versao` INT UNSIGNED NOT NULL COMMENT 'Versão publicada do questionário respondida',
    `modulos` VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Blocos respondidos (GERAL, MODULO_LEGAL, ...), separados por vírgula',
    `respostas` JSON NULL COMMENT '{"CODIGO_PERGUNTA": valor ou [valores]}',
    `status` VARCHAR(20) NOT NULL DEFAULT 'PENDENTE_APROVACAO',
    `vigente` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = perfil aprovado em uso',
    `enviado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `decidido_por` BIGINT UNSIGNED NULL,
    `decidido_em` DATETIME NULL,
    `motivo` VARCHAR(1000) NULL COMMENT 'Observação do gestor (obrigatória na devolução)',
    `valido_ate` DATE NULL COMMENT 'Revisão periódica: aprovado em + N meses',
    PRIMARY KEY (`codperfilatrib`),
    KEY `ix_perfil_atrib_usuario` (`codusuario`, `status`),
    KEY `ix_perfil_atrib_gestor` (`codgestor`, `status`),
    KEY `ix_perfil_atrib_vigente` (`codusuario`, `vigente`),
    CONSTRAINT `ck_perfil_atrib_status` CHECK (`status` IN ('PENDENTE_APROVACAO', 'APROVADO', 'DEVOLVIDO', 'SUBSTITUIDO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @@
CREATE TABLE IF NOT EXISTS `perfil_atribuicoes_itens` (
    `coditem` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codperfilatrib` BIGINT UNSIGNED NOT NULL,
    `codusuario` BIGINT UNSIGNED NOT NULL,
    `tipo` VARCHAR(20) NOT NULL COMMENT 'UNIDADE, PILAR, TEMA, CATEGORIA, RESPOSTA',
    `modulo` VARCHAR(60) NOT NULL COMMENT 'GERAL ou código do módulo (MODULO_LEGAL, HOMOLOGACAO_FORNECEDORES, NAO_CONFORMIDADES)',
    `pilar` VARCHAR(100) NULL COMMENT 'Pilar / atividade (LICENCAS, MONITORAMENTO, ...) ou código da pergunta',
    `papel` VARCHAR(20) NULL COMMENT 'EXECUTA, APROVA, ACOMPANHA',
    `valor` VARCHAR(255) NULL,
    `codunidade` BIGINT NULL,
    PRIMARY KEY (`coditem`),
    KEY `ix_perfil_atrib_item_perfil` (`codperfilatrib`),
    KEY `ix_perfil_atrib_item_busca` (`tipo`, `modulo`, `pilar`, `papel`),
    KEY `ix_perfil_atrib_item_usuario` (`codusuario`, `tipo`),
    CONSTRAINT `fk_perfil_atrib_item_perfil` FOREIGN KEY (`codperfilatrib`) REFERENCES `perfil_atribuicoes` (`codperfilatrib`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @@
-- Perfis com nomes de usuário, gestor e quem decidiu
CREATE OR REPLACE VIEW `vw_perfil_atribuicoes_grid` AS
SELECT p.`codperfilatrib`, p.`codusuario`, u.`nome` AS `usuario_nome`, u.`userid` AS `usuario_login`,
       p.`codgestor`, g.`nome` AS `gestor_nome`, p.`codquestionario`, p.`codversao`, p.`versao`, p.`modulos`,
       CAST(p.`respostas` AS CHAR) AS `respostas`, p.`status`, p.`vigente`, p.`enviado_em`, p.`decidido_por`,
       d.`nome` AS `decidido_por_nome`, p.`decidido_em`, p.`motivo`, p.`valido_ate`
  FROM `perfil_atribuicoes` p
  JOIN `usuarios` u ON u.`codusuario` = p.`codusuario`
  LEFT JOIN `usuarios` g ON g.`codusuario` = p.`codgestor`
  LEFT JOIN `usuarios` d ON d.`codusuario` = p.`decidido_por`;

-- @@
-- Situação de cada usuário ativo: último envio (não substituído), perfil vigente e se o perfil de acesso principal dele
-- aplica o questionário (perfis.aplicar_questionario — Segurança › Perfil, coluna "Aplicar questionário")
CREATE OR REPLACE VIEW `vw_perfil_atribuicoes_situacao` AS
SELECT u.`codusuario`, u.`nome` AS `usuario_nome`, u.`userid` AS `usuario_login`, u.`codusuario_superior` AS `codgestor`,
       s.`nome` AS `gestor_nome`, ul.`codperfilatrib` AS `ultimo_codperfilatrib`, ul.`status` AS `ultimo_status`,
       ul.`enviado_em` AS `ultimo_enviado_em`, ul.`versao` AS `ultimo_versao`, ul.`motivo` AS `ultimo_motivo`,
       vg.`codperfilatrib` AS `vigente_codperfilatrib`, vg.`decidido_em` AS `aprovado_em`, vg.`valido_ate`,
       vg.`versao` AS `vigente_versao`, vg.`modulos` AS `vigente_modulos`,
       pf.`codigo` AS `perfil_codigo`, COALESCE(pf.`aplicar_questionario`, 1) AS `aplicar_questionario`
  FROM `usuarios` u
  LEFT JOIN `perfis` pf ON pf.`codperfil` = u.`codperfil`
  LEFT JOIN `usuarios` s ON s.`codusuario` = u.`codusuario_superior`
  LEFT JOIN `perfil_atribuicoes` ul ON ul.`codperfilatrib` = (
        SELECT MAX(x.`codperfilatrib`) FROM `perfil_atribuicoes` x WHERE x.`codusuario` = u.`codusuario` AND x.`status` <> 'SUBSTITUIDO')
  LEFT JOIN `perfil_atribuicoes` vg ON vg.`codperfilatrib` = (
        SELECT MAX(y.`codperfilatrib`) FROM `perfil_atribuicoes` y WHERE y.`codusuario` = u.`codusuario` AND y.`vigente` = 1)
 WHERE u.`ativo` = 1;

-- @@
-- Itens estruturados do perfil vigente (base para indicar responsáveis)
CREATE OR REPLACE VIEW `vw_perfil_atribuicoes_itens_vigentes` AS
SELECT i.`coditem`, i.`codperfilatrib`, i.`codusuario`, u.`nome` AS `usuario_nome`, i.`tipo`, i.`modulo`, i.`pilar`, i.`papel`,
       i.`valor`, i.`codunidade`, p.`valido_ate`
  FROM `perfil_atribuicoes_itens` i
  JOIN `perfil_atribuicoes` p ON p.`codperfilatrib` = i.`codperfilatrib` AND p.`vigente` = 1
  JOIN `usuarios` u ON u.`codusuario` = i.`codusuario` AND u.`ativo` = 1;

-- @@
DROP PROCEDURE IF EXISTS `sp_perfil_atrib_enviar`

-- @@
-- Grava um envio do usuário (substitui o pendente anterior) e os itens estruturados.
-- p_itens: [{"tipo":"PILAR","modulo":"MODULO_LEGAL","pilar":"LICENCAS","papel":"EXECUTA","valor":null,"codunidade":null}, ...]
CREATE PROCEDURE `sp_perfil_atrib_enviar`(
    IN p_usuario BIGINT, IN p_gestor BIGINT, IN p_codquestionario BIGINT, IN p_codversao BIGINT, IN p_versao INT,
    IN p_modulos VARCHAR(500), IN p_respostas JSON, IN p_itens JSON)
BEGIN
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF p_usuario IS NULL OR p_usuario < 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Usuário inválido'; END IF;
    START TRANSACTION;
    UPDATE `perfil_atribuicoes` SET `status` = 'SUBSTITUIDO'
     WHERE `codusuario` = p_usuario AND `status` IN ('PENDENTE_APROVACAO', 'DEVOLVIDO');
    INSERT INTO `perfil_atribuicoes` (`codusuario`, `codgestor`, `codquestionario`, `codversao`, `versao`, `modulos`, `respostas`, `status`)
    VALUES (p_usuario, NULLIF(p_gestor, 0), p_codquestionario, p_codversao, p_versao, COALESCE(p_modulos, ''), p_respostas, 'PENDENTE_APROVACAO');
    SET v_id = LAST_INSERT_ID();
    IF p_itens IS NOT NULL AND JSON_LENGTH(p_itens) > 0 THEN
        INSERT INTO `perfil_atribuicoes_itens` (`codperfilatrib`, `codusuario`, `tipo`, `modulo`, `pilar`, `papel`, `valor`, `codunidade`)
        SELECT v_id, p_usuario, j.tipo, j.modulo, j.pilar, j.papel, j.valor, j.codunidade
          FROM JSON_TABLE(p_itens, '$[*]' COLUMNS (
                tipo VARCHAR(20) PATH '$.tipo', modulo VARCHAR(60) PATH '$.modulo', pilar VARCHAR(100) PATH '$.pilar',
                papel VARCHAR(20) PATH '$.papel', valor VARCHAR(255) PATH '$.valor', codunidade BIGINT PATH '$.codunidade')) j
         WHERE j.tipo IS NOT NULL AND j.modulo IS NOT NULL;
    END IF;
    COMMIT;
    SELECT v_id AS codperfilatrib;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_perfil_atrib_decidir`

-- @@
-- Aprova ou devolve um envio pendente.
-- p_gerencia_todos = 1: pode decidir o perfil de qualquer usuário (ADMINISTRADOR / ADM_DA_EMPRESA / permissão Editar da gestão);
-- caso contrário só o gestor registrado no envio. Ninguém decide o próprio perfil, exceto p_admin = 1 (ADMINISTRADOR).
-- Aprovar: perfil vigente, valido_ate = hoje + p_meses; o vigente anterior deixa de valer. Devolver: motivo obrigatório.
CREATE PROCEDURE `sp_perfil_atrib_decidir`(
    IN p_id BIGINT, IN p_decisor BIGINT, IN p_gerencia_todos TINYINT, IN p_admin TINYINT,
    IN p_acao VARCHAR(20), IN p_motivo VARCHAR(1000), IN p_meses INT)
BEGIN
    DECLARE v_usuario BIGINT UNSIGNED;
    DECLARE v_gestor BIGINT UNSIGNED;
    DECLARE v_status VARCHAR(20);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    SELECT `codusuario`, `codgestor`, `status` INTO v_usuario, v_gestor, v_status
      FROM `perfil_atribuicoes` WHERE `codperfilatrib` = p_id FOR UPDATE;
    IF v_usuario IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PERFIL_NAO_ENCONTRADO'; END IF;
    IF v_status <> 'PENDENTE_APROVACAO' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PERFIL_NAO_PENDENTE'; END IF;
    IF v_usuario = p_decisor AND COALESCE(p_admin, 0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PROPRIO_PERFIL'; END IF;
    IF COALESCE(p_gerencia_todos, 0) <> 1 AND NOT (v_gestor IS NOT NULL AND v_gestor = p_decisor) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SEM_PERMISSAO';
    END IF;
    IF UPPER(p_acao) = 'APROVAR' THEN
        UPDATE `perfil_atribuicoes` SET `vigente` = 0 WHERE `codusuario` = v_usuario AND `vigente` = 1;
        UPDATE `perfil_atribuicoes`
           SET `status` = 'APROVADO', `vigente` = 1, `decidido_por` = p_decisor, `decidido_em` = NOW(),
               `motivo` = NULLIF(TRIM(p_motivo), ''), `valido_ate` = DATE_ADD(CURDATE(), INTERVAL GREATEST(1, COALESCE(p_meses, 12)) MONTH)
         WHERE `codperfilatrib` = p_id;
    ELSEIF UPPER(p_acao) = 'DEVOLVER' THEN
        IF COALESCE(TRIM(p_motivo), '') = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MOTIVO_OBRIGATORIO'; END IF;
        UPDATE `perfil_atribuicoes`
           SET `status` = 'DEVOLVIDO', `decidido_por` = p_decisor, `decidido_em` = NOW(), `motivo` = TRIM(p_motivo)
         WHERE `codperfilatrib` = p_id;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ACAO_INVALIDA';
    END IF;
    COMMIT;
    SELECT p_id AS codperfilatrib, v_usuario AS codusuario, UPPER(p_acao) AS acao;
END
