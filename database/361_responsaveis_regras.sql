-- Migração 361 — Responsáveis por regra e avisos por módulo (item 4 do 01_Mudancas_Gerais, GER-3; LIC-50/51, MM-72/78,
-- CON-91/94, TRE-106/109, INF-121/124). Decisões do usuário (12/11/2026):
--   * a regra é DINÂMICA: vale na hora para registros novos e antigos; nada é copiado para o registro;
--   * os responsáveis da regra SOMAM com o Responsável do registro e com as atribuições;
--   * Licenças: Tipo documental + unidade; Medição: Tipo de medição + unidade; Controles, Treinamentos e Infraestrutura: unidade;
--   * os avisos (prazos, atribuição, alteração) são escolhidos em cada regra.
-- Parte fixa (tabelas e a procedure dos avisos). As views dependem das colunas de cada banco e são montadas por
-- classes/ResponsaveisRegras.php. Blocos separados por "-- @@"; aplicados por scripts/aplicar-migracao-361-responsaveis-regras.php.

-- @@
CREATE TABLE IF NOT EXISTS `resp_regras` (
  `CODREGRA` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `AREA` VARCHAR(20) NOT NULL,
  `REGISTRO_ID` BIGINT NULL,
  `CODTIPO` BIGINT NULL,
  `CODUNIDADE` BIGINT NULL,
  `TIPO_DESTINO` VARCHAR(10) NOT NULL,
  `CODUSUARIO` BIGINT NULL,
  `CODGRUPO` BIGINT NULL,
  `AVISO_PRAZO` TINYINT(1) NOT NULL DEFAULT 1,
  `AVISO_ATRIBUICAO` TINYINT(1) NOT NULL DEFAULT 1,
  `AVISO_ALTERACAO` TINYINT(1) NOT NULL DEFAULT 0,
  `ATIVO` TINYINT(1) NOT NULL DEFAULT 1,
  `OBSERVACAO` VARCHAR(500) NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `CODUSUARIO_CRIACAO` BIGINT NULL,
  `ATUALIZADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `CODUSUARIOALTERACAO` BIGINT NULL,
  `CHAVE_UNICA` VARCHAR(160) GENERATED ALWAYS AS (CONCAT_WS('|', `AREA`, COALESCE(`REGISTRO_ID`, 0), COALESCE(`CODTIPO`, 0),
      COALESCE(`CODUNIDADE`, 0), `TIPO_DESTINO`, COALESCE(`CODUSUARIO`, 0), COALESCE(`CODGRUPO`, 0))) STORED,
  PRIMARY KEY (`CODREGRA`),
  UNIQUE KEY `uk_resp_regras` (`CHAVE_UNICA`),
  KEY `ix_resp_regras_area` (`AREA`, `ATIVO`, `REGISTRO_ID`),
  KEY `ix_resp_regras_usuario` (`CODUSUARIO`),
  KEY `ix_resp_regras_grupo` (`CODGRUPO`),
  CONSTRAINT `ck_resp_regras_area` CHECK (`AREA` IN ('LICENCAS', 'MEDICAO', 'CONTROLES', 'TREINAMENTOS', 'INFRAESTRUTURA', 'HOMOLOGACAO', 'LEGAL')),
  CONSTRAINT `ck_resp_regras_destino` CHECK ((`TIPO_DESTINO` = 'USUARIO' AND `CODUSUARIO` IS NOT NULL AND `CODGRUPO` IS NULL)
      OR (`TIPO_DESTINO` = 'GRUPO' AND `CODGRUPO` IS NOT NULL AND `CODUSUARIO` IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

-- @@
-- Migração 406 (24/11/2026, onda 2 da Revisão G): áreas HOMOLOGACAO (fornecedores por tipo) e LEGAL (requisitos por categoria)
-- nas regras. Refaz a restrição nas tabelas criadas antes (a 361 pode ser reaplicada: DROP e ADD da mesma restrição).
ALTER TABLE `resp_regras` DROP CHECK `ck_resp_regras_area`

-- @@
ALTER TABLE `resp_regras` ADD CONSTRAINT `ck_resp_regras_area` CHECK (`AREA` IN ('LICENCAS', 'MEDICAO', 'CONTROLES', 'TREINAMENTOS', 'INFRAESTRUTURA', 'HOMOLOGACAO', 'LEGAL'))

-- @@
-- Avisos já dados (o mesmo aviso não se repete): pessoa × registro × tipo × chave (data do prazo ou último histórico).
CREATE TABLE IF NOT EXISTS `resp_regras_avisos` (
  `CODAVISO` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `CODUSUARIO` BIGINT NOT NULL,
  `AREA` VARCHAR(20) NOT NULL,
  `REGISTRO_ID` BIGINT NOT NULL,
  `TIPO` VARCHAR(20) NOT NULL,
  `CHAVE` VARCHAR(40) NOT NULL DEFAULT '',
  `AVISADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODAVISO`),
  UNIQUE KEY `uk_resp_regras_avisos` (`CODUSUARIO`, `AREA`, `REGISTRO_ID`, `TIPO`, `CHAVE`),
  KEY `ix_resp_regras_avisos_data` (`AVISADO_EM`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

-- @@
-- Marcas do serviço (HIST = último historico_alteracoes já avisado; HIST_ATE = limite da execução em andamento).
CREATE TABLE IF NOT EXISTS `resp_regras_controle` (
  `CHAVE` VARCHAR(40) NOT NULL,
  `VALOR` VARCHAR(100) NULL,
  `ATUALIZADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`CHAVE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

-- @@
DROP PROCEDURE IF EXISTS `sp_resp_regras_avisar`

-- @@
CREATE PROCEDURE `sp_resp_regras_avisar`(IN p_simular TINYINT)
BEGIN
    -- Avisos das regras (vw_resp_regras_avisos_pendentes): um alerta por pessoa, módulo e tipo de aviso, com a lista dos registros.
    -- Viram e-mail pelo serviço de e-mail (migração 343), conforme Minha conta › Alertas por e-mail.
    DECLARE v_alertas INT DEFAULT 0;
    DECLARE v_itens INT DEFAULT 0;
    DECLARE v_hist INT DEFAULT 0;
    SET SESSION group_concat_max_len = 65535;
    SELECT COUNT(*) INTO v_hist FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'historico_alteracoes';
    IF v_hist > 0 AND COALESCE(p_simular, 0) = 0 THEN
        INSERT INTO `resp_regras_controle` (`CHAVE`, `VALOR`)
        SELECT 'HIST_ATE', CAST(COALESCE(MAX(`codhistorico`), 0) AS CHAR) FROM `historico_alteracoes`
        ON DUPLICATE KEY UPDATE `VALOR` = VALUES(`VALOR`);
    END IF;
    DROP TEMPORARY TABLE IF EXISTS `tmp_resp_regras_avisos`;
    CREATE TEMPORARY TABLE `tmp_resp_regras_avisos` (
        `CODUSUARIO` BIGINT NOT NULL, `AREA` VARCHAR(20) NOT NULL, `REGISTRO_ID` BIGINT NOT NULL, `TIPO` VARCHAR(20) NOT NULL,
        `CHAVE` VARCHAR(40) NOT NULL, `DESCRICAO` VARCHAR(300) NULL, `DETALHE` VARCHAR(500) NULL,
        KEY `ix_tmp` (`CODUSUARIO`, `AREA`, `TIPO`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    INSERT INTO `tmp_resp_regras_avisos` (`CODUSUARIO`, `AREA`, `REGISTRO_ID`, `TIPO`, `CHAVE`, `DESCRICAO`, `DETALHE`)
    SELECT DISTINCT p.`CODUSUARIO`, p.`AREA`, p.`REGISTRO_ID`, p.`TIPO`, p.`CHAVE`, LEFT(p.`DESCRICAO`, 300), LEFT(p.`DETALHE`, 500)
      FROM `vw_resp_regras_avisos_pendentes` p
     WHERE NOT EXISTS (SELECT 1 FROM `resp_regras_avisos` a
                        WHERE a.`CODUSUARIO` = p.`CODUSUARIO` AND a.`AREA` = p.`AREA` AND a.`REGISTRO_ID` = p.`REGISTRO_ID`
                          AND a.`TIPO` = p.`TIPO` AND a.`CHAVE` = p.`CHAVE`);
    SELECT COUNT(*) INTO v_itens FROM `tmp_resp_regras_avisos`;
    IF COALESCE(p_simular, 0) = 1 THEN
        SELECT COUNT(*) INTO v_alertas FROM (SELECT DISTINCT `CODUSUARIO`, `AREA`, `TIPO` FROM `tmp_resp_regras_avisos`) x;
    ELSE
        INSERT INTO `alertas` (`codusuario`, `titulo`, `mensagem`, `link`, `lido`)
        SELECT t.`CODUSUARIO`,
               LEFT(CONCAT(
                   CASE t.`AREA` WHEN 'LICENCAS' THEN 'Licenças' WHEN 'MEDICAO' THEN 'Medição e monitoramento' WHEN 'CONTROLES' THEN 'Controles'
                        WHEN 'TREINAMENTOS' THEN 'Treinamentos' WHEN 'HOMOLOGACAO' THEN 'Homologação de fornecedores'
                        WHEN 'LEGAL' THEN 'Requisitos legais' ELSE 'Infraestrutura' END, ': ',
                   CASE t.`TIPO`
                        WHEN 'ATRIBUICAO' THEN CONCAT('você passou a responder por ', COUNT(*), ' registro(s)')
                        WHEN 'VENCIMENTO' THEN CONCAT(COUNT(*), ' vence(m) em até 30 dias')
                        WHEN 'REACAO' THEN CONCAT(COUNT(*), ' com a data de reação atingida')
                        WHEN 'MEDICAO' THEN CONCAT(COUNT(*), ' medição(ões) prevista(s) em até 7 dias')
                        WHEN 'AVALIACAO' THEN CONCAT(COUNT(*), ' com a próxima avaliação em até 30 dias')
                        WHEN 'DOCUMENTOS' THEN CONCAT(COUNT(*), ' documento(s) vence(m) em até 30 dias')
                        ELSE CONCAT(COUNT(*), ' registro(s) alterado(s) por outra pessoa') END), 180),
               LEFT(CONCAT(GROUP_CONCAT(CONCAT('• ', COALESCE(NULLIF(TRIM(t.`DESCRICAO`), ''), CONCAT('#', t.`REGISTRO_ID`)),
                                               IF(COALESCE(t.`DETALHE`, '') = '', '', CONCAT(' — ', t.`DETALHE`)))
                                        ORDER BY t.`DESCRICAO`, t.`REGISTRO_ID` SEPARATOR '\n'),
                           '\n\nVocê recebe este aviso porque responde por estes registros (Responsável do registro, atribuição ou regra de Responsáveis e avisos do módulo).'), 60000),
               CASE t.`AREA` WHEN 'LICENCAS' THEN '/principal/minhas_licencas.php' WHEN 'MEDICAO' THEN '/principal/minhas_medicoes.php'
                    WHEN 'CONTROLES' THEN '/principal/meus_controles.php' WHEN 'TREINAMENTOS' THEN '/principal/meus_treinamentos.php'
                    WHEN 'HOMOLOGACAO' THEN '/principal/meus_fornecedores.php' WHEN 'LEGAL' THEN '/principal/meus_requisitos.php'
                    ELSE '/principal/minhas_infraestruturas.php' END,
               0
          FROM `tmp_resp_regras_avisos` t
         GROUP BY t.`CODUSUARIO`, t.`AREA`, t.`TIPO`;
        SET v_alertas = ROW_COUNT();
        INSERT IGNORE INTO `resp_regras_avisos` (`CODUSUARIO`, `AREA`, `REGISTRO_ID`, `TIPO`, `CHAVE`)
        SELECT `CODUSUARIO`, `AREA`, `REGISTRO_ID`, `TIPO`, `CHAVE` FROM `tmp_resp_regras_avisos`;
        IF v_hist > 0 THEN
            -- alterações até HIST_ATE já avisadas (na 1ª execução, só marca: alterações antigas não viram aviso)
            INSERT INTO `resp_regras_controle` (`CHAVE`, `VALOR`)
            SELECT 'HIST', c.`VALOR` FROM `resp_regras_controle` c WHERE c.`CHAVE` = 'HIST_ATE'
            ON DUPLICATE KEY UPDATE `VALOR` = VALUES(`VALOR`);
        END IF;
    END IF;
    DROP TEMPORARY TABLE IF EXISTS `tmp_resp_regras_avisos`;
    SELECT v_alertas AS `ALERTAS`, v_itens AS `ITENS`;
END
