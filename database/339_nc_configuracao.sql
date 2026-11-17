-- Migração 339 — Não Conformidades › Administração › Configuração do módulo (fase 4, 23/10/2026; decisões do usuário: campos e
-- obrigatórios por tipo, parâmetros da empresa e lembretes automáticos; campos de cada tipo continuam vindo do legado, só consulta).
--  - Parâmetros da empresa (nc_config_parametros): regras que eram fixas no código ou só da Crown (data e responsável na eficácia,
--    listas de fornecedor separadas, IA na análise da causa e na eficácia, alcance dos lembretes).
--  - Obrigatórios por tipo (nc_config_obrigatorios): campos que o greenportal passa a exigir no envio da ocorrência, além das regras
--    fixas (as do legado continuam). Os campos que cada tipo mostra vêm de nc_tipo_de_naoconformidadexcampos (legado, só consulta).
--  - Lembretes (nc_lembrete_regras / nc_lembrete_envios): regras por situação (gestor parado, ação vencendo/vencida, eficácia parada...),
--    com dias, repetição, aviso ao superior (usuarios.codusuario_superior) e e-mail; processadas por sp_nc_lembretes_processar no
--    serviço "nc" do Agendador (scripts/nc-lembretes-worker.php). Por padrão só NCs do greenportal (o legado tem a própria política).
-- Tabelas novas só do greenportal (não existem no legado). Blocos separados por "-- @@" (scripts/aplicar-migracao-339-nc-configuracao.php).

-- @@
CREATE TABLE IF NOT EXISTS `nc_config_parametros` (
  `CHAVE` VARCHAR(60) NOT NULL,
  `VALOR` VARCHAR(500) NULL,
  `ALTERADO_POR` INT NULL,
  `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`CHAVE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
CREATE TABLE IF NOT EXISTS `nc_config_obrigatorios` (
  `CODTIPONAOCONFORMIDADE` INT NOT NULL,
  `CAMPO` VARCHAR(64) NOT NULL,
  `ROTULO` VARCHAR(200) NOT NULL,
  `ALTERADO_POR` INT NULL,
  `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODTIPONAOCONFORMIDADE`, `CAMPO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
CREATE TABLE IF NOT EXISTS `nc_lembrete_regras` (
  `CODREGRA` INT NOT NULL AUTO_INCREMENT,
  `EVENTO` VARCHAR(30) NOT NULL,
  `NOME` VARCHAR(200) NOT NULL,
  `ATIVO` TINYINT NOT NULL DEFAULT 0,
  `DIAS` INT NOT NULL DEFAULT 1,
  `REPETIR_DIAS` INT NOT NULL DEFAULT 0,
  `ESCALAR_APOS` INT NOT NULL DEFAULT 0,
  `ENVIAR_EMAIL` TINYINT NOT NULL DEFAULT 1,
  `TIPOS` VARCHAR(100) NULL,
  `ULTIMA_EXECUCAO` DATETIME NULL,
  `QTD_ENVIADOS` INT NOT NULL DEFAULT 0,
  `ALTERADO_POR` INT NULL,
  `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODREGRA`),
  KEY `ix_nc_lembrete_regras_evento` (`EVENTO`, `ATIVO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
CREATE TABLE IF NOT EXISTS `nc_lembrete_envios` (
  `CODREGRA` INT NOT NULL,
  `CODOCORRENCIA` INT NOT NULL,
  `CODPLANOACAO` INT NOT NULL DEFAULT 0,
  `CODUSUARIO` INT NOT NULL,
  `QTD` INT NOT NULL DEFAULT 1,
  `PRIMEIRO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ULTIMO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODREGRA`, `CODOCORRENCIA`, `CODPLANOACAO`, `CODUSUARIO`),
  KEY `ix_nc_lembrete_envios_data` (`ULTIMO_EM`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
CREATE OR REPLACE VIEW `vw_nc_config_parametros` AS
SELECT p.CHAVE, p.VALOR, p.ALTERADO_POR, CONVERT(COALESCE(NULLIF(TRIM(u.nome), ''), u.userid, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS ALTERADO_POR_NOME, p.ALTERADO_EM
  FROM nc_config_parametros p LEFT JOIN usuarios u ON u.codusuario = p.ALTERADO_POR
-- @@
CREATE OR REPLACE VIEW `vw_nc_config_obrigatorios` AS
SELECT c.CODTIPONAOCONFORMIDADE, c.CAMPO, c.ROTULO, c.ALTERADO_POR, c.ALTERADO_EM FROM nc_config_obrigatorios c
-- @@
-- Colunas de nc_indicacao_ocorrencia (para validar os campos que a configuração pode exigir)
CREATE OR REPLACE VIEW `vw_nc_config_colunas` AS
SELECT UPPER(COLUMN_NAME) AS CAMPO FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nc_indicacao_ocorrencia'
-- @@
CREATE OR REPLACE VIEW `vw_nc_lembrete_regras` AS
SELECT r.CODREGRA, r.EVENTO, r.NOME, r.ATIVO, r.DIAS, r.REPETIR_DIAS, r.ESCALAR_APOS, r.ENVIAR_EMAIL, r.TIPOS, r.ULTIMA_EXECUCAO, r.QTD_ENVIADOS,
       r.ALTERADO_POR, CONVERT(COALESCE(NULLIF(TRIM(u.nome), ''), u.userid, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS ALTERADO_POR_NOME, r.ALTERADO_EM
  FROM nc_lembrete_regras r LEFT JOIN usuarios u ON u.codusuario = r.ALTERADO_POR
-- @@
CREATE OR REPLACE VIEW `vw_nc_lembrete_envios` AS
SELECT e.CODREGRA, r.NOME AS REGRA, e.CODOCORRENCIA, CONVERT(COALESCE(NULLIF(o.NRANO, ''), o.CODOCORRENCIA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NUMERO,
       e.CODPLANOACAO, e.CODUSUARIO, CONVERT(COALESCE(NULLIF(TRIM(u.nome), ''), u.userid, CONCAT('Usuário ', e.CODUSUARIO)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS USUARIO,
       e.QTD, e.PRIMEIRO_EM, e.ULTIMO_EM
  FROM nc_lembrete_envios e
  JOIN nc_lembrete_regras r ON r.CODREGRA = e.CODREGRA
  LEFT JOIN nc_indicacao_ocorrencia o ON o.CODOCORRENCIA = e.CODOCORRENCIA
  LEFT JOIN usuarios u ON u.codusuario = e.CODUSUARIO
-- @@
-- NCs em aberto com a etapa normalizada e os dias sem movimento (último registro do histórico ou criação)
CREATE OR REPLACE VIEW `vw_nc_lembrete_base` AS
SELECT o.CODOCORRENCIA, o.CODTIPONAOCONFORMIDADE,
       CONVERT(COALESCE(NULLIF(o.NRANO, ''), o.CODOCORRENCIA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NUMERO,
       CONVERT(LEFT(COALESCE(o.IDENTIFICACAO, ''), 300) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS SUMARIO,
       CONVERT(fn_nc_etapa(o.ETAPA) USING utf8mb4) COLLATE utf8mb4_bin AS ETAPA,
       o.CODUSUARIO_EMISSOR, o.CODUSUARIO_GESTOR, o.CODUSUARIO_RESPONSAVEL, o.DATA_DE_CONCLUSAO_PREVISTA,
       COALESCE(o.data_de_criacao, o.DATA_OCORRENCIA) AS CRIADA_EM,
       DATEDIFF(CURDATE(), GREATEST(COALESCE(h.ULTIMA, '1900-01-01'), COALESCE(o.data_de_criacao, o.DATA_OCORRENCIA, '1900-01-01'))) AS DIAS_PARADA,
       IF(o.CODOCORRENCIA >= fn_nc_faixa('ocorrencia'), 'GREENPORTAL', 'LEGADO') AS ORIGEM
  FROM nc_indicacao_ocorrencia o
  LEFT JOIN (SELECT CODOCORRENCIA, MAX(CRIADO_EM) AS ULTIMA FROM nc_workflow_historico GROUP BY CODOCORRENCIA) h ON h.CODOCORRENCIA = o.CODOCORRENCIA
 WHERE COALESCE(o.ETAPA, '') <> 'Finalizado'
-- @@
-- Situações que podem gerar lembrete (EVENTO), com o destinatário e a medida em dias (parada, atraso ou dias até vencer)
CREATE OR REPLACE VIEW `vw_nc_lembrete_candidatos` AS
SELECT CONVERT('GESTOR_PENDENTE' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS EVENTO, b.CODOCORRENCIA, 0 AS CODPLANOACAO, b.CODUSUARIO_GESTOR AS CODUSUARIO, b.CODTIPONAOCONFORMIDADE, b.ORIGEM, b.CRIADA_EM, b.NUMERO, b.SUMARIO,
       CONVERT('aguarda a sua avaliação como gestor' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS TEXTO, CONVERT('gestor' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS ABA, b.DIAS_PARADA AS DIAS
  FROM vw_nc_lembrete_base b WHERE b.ETAPA = 'Com o Gestor' AND b.CODUSUARIO_GESTOR > 0
UNION ALL
SELECT CONVERT('EMITENTE_RECUSADA' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, b.CODOCORRENCIA, 0, b.CODUSUARIO_EMISSOR, b.CODTIPONAOCONFORMIDADE, b.ORIGEM, b.CRIADA_EM, b.NUMERO, b.SUMARIO,
       CONVERT('foi devolvida pelo gestor e aguarda a sua correção' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT('ocorrencia' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, b.DIAS_PARADA
  FROM vw_nc_lembrete_base b WHERE b.ETAPA = 'Recusado pelo Gestor' AND b.CODUSUARIO_EMISSOR > 0
UNION ALL
SELECT CONVERT('ANALISE_PARADA' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, b.CODOCORRENCIA, 0, b.CODUSUARIO_RESPONSAVEL, b.CODTIPONAOCONFORMIDADE, b.ORIGEM, b.CRIADA_EM, b.NUMERO, b.SUMARIO,
       CONVERT('aguarda a análise da causa e o plano de ação' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT('analise' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, b.DIAS_PARADA
  FROM vw_nc_lembrete_base b
 WHERE (b.ETAPA IN ('Em Analise da Causa Raiz', 'Recusado pela Avaliacao da Eficacia') OR b.ETAPA LIKE 'Plano de A%') AND b.CODTIPONAOCONFORMIDADE NOT IN (5, 6) AND b.CODUSUARIO_RESPONSAVEL > 0
UNION ALL
SELECT CONVERT('CONCLUSAO_VENCIDA' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, b.CODOCORRENCIA, 0, b.CODUSUARIO_RESPONSAVEL, b.CODTIPONAOCONFORMIDADE, b.ORIGEM, b.CRIADA_EM, b.NUMERO, b.SUMARIO,
       CONVERT(CONCAT('passou da data prevista de conclusão (', DATE_FORMAT(b.DATA_DE_CONCLUSAO_PREVISTA, '%d/%m/%Y'), ')') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
       CONVERT('analise' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, DATEDIFF(CURDATE(), b.DATA_DE_CONCLUSAO_PREVISTA)
  FROM vw_nc_lembrete_base b
 WHERE (b.ETAPA IN ('Em Analise da Causa Raiz', 'Recusado pela Avaliacao da Eficacia') OR b.ETAPA LIKE 'Plano de A%') AND b.CODUSUARIO_RESPONSAVEL > 0
   AND b.DATA_DE_CONCLUSAO_PREVISTA > '1900-01-01' AND b.DATA_DE_CONCLUSAO_PREVISTA < CURDATE()
UNION ALL
SELECT CONVERT('ACAO_VENCENDO' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, b.CODOCORRENCIA, p.CODPLANOACAO, p.CODUSUARIO, b.CODTIPONAOCONFORMIDADE, b.ORIGEM, b.CRIADA_EM, b.NUMERO, b.SUMARIO,
       CONVERT(CONCAT('a ação "', LEFT(COALESCE(p.ACAO, ''), 80), '" vence em ', DATE_FORMAT(p.DATAFINAL, '%d/%m/%Y')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
       CONVERT('analise' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, DATEDIFF(p.DATAFINAL, CURDATE())
  FROM nc_planodeacao p LEFT JOIN nc_causas c ON c.CODCAUSA = p.CODCAUSA
  JOIN vw_nc_lembrete_base b ON b.CODOCORRENCIA = COALESCE(p.CODOCORRENCIA, c.CODOCORRENCIA)
 WHERE COALESCE(p.STATUS, 0) < 100 AND p.CODUSUARIO > 0 AND p.DATAFINAL IS NOT NULL AND p.DATAFINAL >= CURDATE()
UNION ALL
SELECT CONVERT('ACAO_VENCIDA' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, b.CODOCORRENCIA, p.CODPLANOACAO, p.CODUSUARIO, b.CODTIPONAOCONFORMIDADE, b.ORIGEM, b.CRIADA_EM, b.NUMERO, b.SUMARIO,
       CONVERT(CONCAT('a ação "', LEFT(COALESCE(p.ACAO, ''), 80), '" venceu em ', DATE_FORMAT(p.DATAFINAL, '%d/%m/%Y')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
       CONVERT('analise' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, DATEDIFF(CURDATE(), p.DATAFINAL)
  FROM nc_planodeacao p LEFT JOIN nc_causas c ON c.CODCAUSA = p.CODCAUSA
  JOIN vw_nc_lembrete_base b ON b.CODOCORRENCIA = COALESCE(p.CODOCORRENCIA, c.CODOCORRENCIA)
 WHERE COALESCE(p.STATUS, 0) < 100 AND p.CODUSUARIO > 0 AND p.DATAFINAL IS NOT NULL AND p.DATAFINAL > '1900-01-01' AND p.DATAFINAL < CURDATE()
UNION ALL
SELECT CONVERT('EFICACIA_PENDENTE' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, b.CODOCORRENCIA, 0, b.CODUSUARIO_GESTOR, b.CODTIPONAOCONFORMIDADE, b.ORIGEM, b.CRIADA_EM, b.NUMERO, b.SUMARIO,
       CONVERT('aguarda a sua avaliação da eficácia' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT('eficacia' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, b.DIAS_PARADA
  FROM vw_nc_lembrete_base b WHERE b.ETAPA LIKE 'Avalia%Eficacia' AND b.CODUSUARIO_GESTOR > 0
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_config_parametro_salvar`
-- @@
-- Parâmetro da empresa (só as chaves conhecidas, valores validados); registra antes → depois no histórico da administração
CREATE PROCEDURE `sp_nc_config_parametro_salvar`(IN p_chave VARCHAR(60), IN p_valor VARCHAR(500), IN p_usuario INT)
BEGIN
  DECLARE v_antes VARCHAR(500); DECLARE v_valor VARCHAR(500) DEFAULT TRIM(COALESCE(p_valor, ''));
  IF p_chave NOT IN ('EFICACIA_EXIGE_PRAZO', 'LISTAS_FORNECEDOR_SEPARADAS', 'IA_ANALISE_CAUSA', 'IA_EFICACIA', 'LEMBRETES_INCLUIR_LEGADO', 'LEMBRETES_DESDE') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Parâmetro desconhecido.';
  END IF;
  IF p_chave = 'LEMBRETES_DESDE' THEN
    IF v_valor <> '' AND (v_valor NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' OR STR_TO_DATE(v_valor, '%Y-%m-%d') IS NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data inválida (use AAAA-MM-DD ou deixe vazio).'; END IF;
  ELSEIF v_valor NOT IN ('S', 'N') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Use S (sim) ou N (não).';
  END IF;
  SELECT VALOR INTO v_antes FROM nc_config_parametros WHERE CHAVE = p_chave;
  INSERT INTO nc_config_parametros (CHAVE, VALOR, ALTERADO_POR) VALUES (p_chave, v_valor, p_usuario)
    ON DUPLICATE KEY UPDATE VALOR = v_valor, ALTERADO_POR = p_usuario, ALTERADO_EM = NOW();
  IF NOT (v_antes <=> v_valor) THEN CALL sp_nc_admin_historico('PARAMETRO', 'CONFIGURACAO', p_chave, JSON_OBJECT('valor', v_antes), JSON_OBJECT('valor', v_valor), NULL, p_usuario); END IF;
  SELECT p_chave AS CHAVE, v_valor AS VALOR;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_config_obrigatorios_salvar`
-- @@
-- Campos que o tipo passa a exigir no envio (substitui a lista do tipo): p_campos = JSON [{"campo": "CODSETOR", "rotulo": "Setor"}, ...]
CREATE PROCEDURE `sp_nc_config_obrigatorios_salvar`(IN p_tipo INT, IN p_campos JSON, IN p_usuario INT)
BEGIN
  DECLARE v_j INT DEFAULT 0; DECLARE v_n INT DEFAULT 0; DECLARE v_campo VARCHAR(64); DECLARE v_rotulo VARCHAR(200); DECLARE v_antes JSON;
  IF NOT EXISTS (SELECT 1 FROM nc_tipo_de_naoconformidade WHERE CODTIPONAOCONFORMIDADE = p_tipo) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipo de não conformidade inválido.'; END IF;
  IF p_campos IS NULL OR JSON_TYPE(p_campos) <> 'ARRAY' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lista de campos inválida.'; END IF;
  SELECT COALESCE(JSON_ARRAYAGG(CAMPO), JSON_ARRAY()) INTO v_antes FROM nc_config_obrigatorios WHERE CODTIPONAOCONFORMIDADE = p_tipo;
  SET v_n = JSON_LENGTH(p_campos);
  -- confere tudo antes de alterar (campo inválido não apaga a lista atual)
  WHILE v_j < v_n DO
    SET v_campo = UPPER(JSON_UNQUOTE(JSON_EXTRACT(p_campos, CONCAT('$[', v_j, '].campo'))));
    IF v_campo IS NULL OR v_campo NOT REGEXP '^[A-Z0-9_]+$' OR NOT EXISTS (SELECT 1 FROM vw_nc_config_colunas WHERE CAMPO = v_campo) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Campo inválido na lista de obrigatórios.';
    END IF;
    SET v_j = v_j + 1;
  END WHILE;
  DELETE FROM nc_config_obrigatorios WHERE CODTIPONAOCONFORMIDADE = p_tipo;
  SET v_j = 0;
  WHILE v_j < v_n DO
    SET v_campo = UPPER(JSON_UNQUOTE(JSON_EXTRACT(p_campos, CONCAT('$[', v_j, '].campo'))));
    SET v_rotulo = LEFT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_campos, CONCAT('$[', v_j, '].rotulo'))), v_campo), 200);
    INSERT IGNORE INTO nc_config_obrigatorios (CODTIPONAOCONFORMIDADE, CAMPO, ROTULO, ALTERADO_POR) VALUES (p_tipo, v_campo, v_rotulo, p_usuario);
    SET v_j = v_j + 1;
  END WHILE;
  CALL sp_nc_admin_historico('OBRIGATORIOS', 'CONFIGURACAO', CONCAT('TIPO ', p_tipo), JSON_OBJECT('campos', v_antes),
    JSON_OBJECT('campos', (SELECT COALESCE(JSON_ARRAYAGG(CAMPO), JSON_ARRAY()) FROM nc_config_obrigatorios WHERE CODTIPONAOCONFORMIDADE = p_tipo)), NULL, p_usuario);
  SELECT p_tipo AS CODTIPONAOCONFORMIDADE, (SELECT COUNT(*) FROM nc_config_obrigatorios WHERE CODTIPONAOCONFORMIDADE = p_tipo) AS QTD;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_config_validar_envio`
-- @@
-- Envio da ocorrência (a tela chama depois de sp_nc_ocorrencia_salvar, na mesma transação): campos obrigatórios configurados para o tipo
CREATE PROCEDURE `sp_nc_config_validar_envio`(IN p_codigo INT)
BEGIN
  DECLARE v_fim INT DEFAULT 0; DECLARE v_campo VARCHAR(64); DECLARE v_rotulo VARCHAR(200); DECLARE v_faltam VARCHAR(2000) DEFAULT ''; DECLARE v_tipo INT;
  DECLARE c CURSOR FOR SELECT o.CAMPO, o.ROTULO FROM nc_config_obrigatorios o JOIN vw_nc_config_colunas k ON k.CAMPO = o.CAMPO WHERE o.CODTIPONAOCONFORMIDADE = v_tipo ORDER BY o.ROTULO;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim = 1;
  SELECT CODTIPONAOCONFORMIDADE INTO v_tipo FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = p_codigo;
  SET v_fim = 0;
  OPEN c;
  laco: LOOP
    FETCH c INTO v_campo, v_rotulo;
    IF v_fim = 1 THEN LEAVE laco; END IF;
    SET @nc_cfg_vazio = 0;
    SET @nc_cfg_sql = CONCAT('SELECT COUNT(*) INTO @nc_cfg_vazio FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA = ', p_codigo,
      ' AND TRIM(COALESCE(CAST(`', v_campo, '` AS CHAR), '''')) IN ('''', ''0'', ''0000-00-00 00:00:00'', ''0000-00-00'')');
    PREPARE nc_cfg_st FROM @nc_cfg_sql; EXECUTE nc_cfg_st; DEALLOCATE PREPARE nc_cfg_st;
    IF @nc_cfg_vazio > 0 THEN SET v_faltam = CONCAT(v_faltam, IF(v_faltam = '', '', ', '), v_rotulo); END IF;
  END LOOP;
  CLOSE c;
  IF v_faltam <> '' THEN
    SET v_faltam = LEFT(CONCAT('Preencha antes de enviar: ', v_faltam, '.'), 128);
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_faltam;
  END IF;
  SELECT p_codigo AS CODOCORRENCIA;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_lembrete_regra_salvar`
-- @@
-- Regra de lembrete (nova: p_codigo 0). Dias ≥ 0; repetição 0 = uma vez; aviso ao superior depois de N lembretes (0 = nunca).
CREATE PROCEDURE `sp_nc_lembrete_regra_salvar`(IN p_codigo INT, IN p_evento VARCHAR(30), IN p_nome VARCHAR(200), IN p_ativo TINYINT, IN p_dias INT,
  IN p_repetir INT, IN p_escalar INT, IN p_email TINYINT, IN p_tipos VARCHAR(100), IN p_usuario INT)
BEGIN
  DECLARE v_cod INT; DECLARE v_antes JSON;
  IF p_evento NOT IN ('GESTOR_PENDENTE', 'EMITENTE_RECUSADA', 'ANALISE_PARADA', 'CONCLUSAO_VENCIDA', 'ACAO_VENCENDO', 'ACAO_VENCIDA', 'EFICACIA_PENDENTE') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Situação do lembrete inválida.';
  END IF;
  IF TRIM(COALESCE(p_nome, '')) = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dê um nome à regra.'; END IF;
  IF COALESCE(p_dias, -1) < 0 OR p_dias > 3650 OR COALESCE(p_repetir, -1) < 0 OR p_repetir > 365 OR COALESCE(p_escalar, -1) < 0 OR p_escalar > 20 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dias, repetição ou aviso ao superior fora do limite.';
  END IF;
  IF COALESCE(p_tipos, '') <> '' AND p_tipos NOT REGEXP '^[0-9]+(,[0-9]+)*$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipos de NC inválidos.'; END IF;
  IF COALESCE(p_codigo, 0) = 0 THEN
    INSERT INTO nc_lembrete_regras (EVENTO, NOME, ATIVO, DIAS, REPETIR_DIAS, ESCALAR_APOS, ENVIAR_EMAIL, TIPOS, ALTERADO_POR)
    VALUES (p_evento, TRIM(p_nome), IF(p_ativo = 1, 1, 0), p_dias, p_repetir, p_escalar, IF(p_email = 1, 1, 0), NULLIF(p_tipos, ''), p_usuario);
    SET v_cod = LAST_INSERT_ID();
  ELSE
    SELECT JSON_OBJECT('evento', EVENTO, 'nome', NOME, 'ativo', ATIVO, 'dias', DIAS, 'repetir', REPETIR_DIAS, 'escalar', ESCALAR_APOS, 'email', ENVIAR_EMAIL, 'tipos', TIPOS) INTO v_antes
      FROM nc_lembrete_regras WHERE CODREGRA = p_codigo;
    IF v_antes IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Regra não encontrada.'; END IF;
    UPDATE nc_lembrete_regras SET EVENTO = p_evento, NOME = TRIM(p_nome), ATIVO = IF(p_ativo = 1, 1, 0), DIAS = p_dias, REPETIR_DIAS = p_repetir, ESCALAR_APOS = p_escalar,
           ENVIAR_EMAIL = IF(p_email = 1, 1, 0), TIPOS = NULLIF(p_tipos, ''), ALTERADO_POR = p_usuario WHERE CODREGRA = p_codigo;
    SET v_cod = p_codigo;
  END IF;
  CALL sp_nc_admin_historico(IF(COALESCE(p_codigo, 0) = 0, 'LEMBRETE_INCLUIDO', 'LEMBRETE_ALTERADO'), 'CONFIGURACAO', CONCAT('REGRA ', v_cod), v_antes,
    JSON_OBJECT('evento', p_evento, 'nome', TRIM(p_nome), 'ativo', IF(p_ativo = 1, 1, 0), 'dias', p_dias, 'repetir', p_repetir, 'escalar', p_escalar, 'email', IF(p_email = 1, 1, 0), 'tipos', NULLIF(p_tipos, '')), NULL, p_usuario);
  SELECT v_cod AS CODREGRA;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_lembretes_processar`
-- @@
-- Serviço "nc" do Agendador (e "Simular" na tela, p_simular = 1: só conta). Para cada regra ativa: situações da regra, só destinatário
-- ativo, até 500 por regra e execução; não repete antes de REPETIR_DIAS (0 = uma vez). Grava alerta (Home › Alertas) e, com
-- ENVIAR_EMAIL, e-mail na fila (serviço "email"); a partir do lembrete ESCALAR_APOS + 1, avisa também o superior do destinatário.
CREATE PROCEDURE `sp_nc_lembretes_processar`(IN p_simular TINYINT)
BEGIN
  DECLARE v_fim INT DEFAULT 0; DECLARE v_regra INT; DECLARE v_evento VARCHAR(30); DECLARE v_nome VARCHAR(200); DECLARE v_dias INT; DECLARE v_rep INT;
  DECLARE v_esc INT; DECLARE v_email TINYINT; DECLARE v_tipos VARCHAR(100); DECLARE v_legado VARCHAR(5); DECLARE v_desde DATE; DECLARE v_n INT; DECLARE v_sup INT;
  DECLARE c CURSOR FOR SELECT CODREGRA, EVENTO, NOME, DIAS, REPETIR_DIAS, ESCALAR_APOS, ENVIAR_EMAIL, TIPOS FROM nc_lembrete_regras WHERE ATIVO = 1 ORDER BY CODREGRA;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim = 1;
  SELECT COALESCE(MAX(IF(CHAVE = 'LEMBRETES_INCLUIR_LEGADO', VALOR, NULL)), 'N'), STR_TO_DATE(NULLIF(MAX(IF(CHAVE = 'LEMBRETES_DESDE', VALOR, NULL)), ''), '%Y-%m-%d')
    INTO v_legado, v_desde FROM nc_config_parametros;
  DROP TEMPORARY TABLE IF EXISTS tmp_nc_lemb_res;
  CREATE TEMPORARY TABLE tmp_nc_lemb_res (CODREGRA INT, NOME VARCHAR(200), LEMBRETES INT, SUPERIORES INT);
  SET v_fim = 0;
  OPEN c;
  laco: LOOP
    FETCH c INTO v_regra, v_evento, v_nome, v_dias, v_rep, v_esc, v_email, v_tipos;
    IF v_fim = 1 THEN LEAVE laco; END IF;
    DROP TEMPORARY TABLE IF EXISTS tmp_nc_lemb;
    CREATE TEMPORARY TABLE tmp_nc_lemb AS
      SELECT x.CODOCORRENCIA, x.CODPLANOACAO, x.CODUSUARIO, x.NUMERO, x.SUMARIO, x.TEXTO, x.ABA, x.DIAS, COALESCE(e.QTD, 0) + 1 AS VEZ,
             COALESCE(u.codusuario_superior, 0) AS SUPERIOR, CONVERT(COALESCE(NULLIF(TRIM(u.nome), ''), u.userid, '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NOME_DESTINO
        FROM vw_nc_lembrete_candidatos x
        JOIN usuarios u ON u.codusuario = x.CODUSUARIO AND u.ativo = 1
        LEFT JOIN nc_lembrete_envios e ON e.CODREGRA = v_regra AND e.CODOCORRENCIA = x.CODOCORRENCIA AND e.CODPLANOACAO = x.CODPLANOACAO AND e.CODUSUARIO = x.CODUSUARIO
       WHERE x.EVENTO = v_evento
         AND IF(v_evento = 'ACAO_VENCENDO', x.DIAS <= v_dias, x.DIAS >= v_dias)
         AND (COALESCE(v_tipos, '') = '' OR FIND_IN_SET(x.CODTIPONAOCONFORMIDADE, v_tipos) > 0)
         AND (v_legado = 'S' OR x.ORIGEM = 'GREENPORTAL')
         AND (v_desde IS NULL OR x.CRIADA_EM >= v_desde)
         AND (e.CODREGRA IS NULL OR (v_rep > 0 AND e.ULTIMO_EM <= NOW() - INTERVAL v_rep DAY))
       ORDER BY x.DIAS DESC
       LIMIT 500;
    SELECT COUNT(*), COALESCE(SUM(v_esc > 0 AND VEZ > v_esc AND SUPERIOR > 0 AND SUPERIOR <> CODUSUARIO), 0) INTO v_n, v_sup FROM tmp_nc_lemb;
    IF p_simular = 0 AND v_n > 0 THEN
      INSERT INTO alertas (codusuario, titulo, mensagem, link, lido)
      SELECT t.CODUSUARIO, LEFT(CONCAT('NC ', t.NUMERO, ': ', t.TEXTO), 180),
             LEFT(CONCAT('Lembrete automático (', v_nome, ', ', t.VEZ, 'º aviso). Não conformidade ', t.NUMERO, IF(t.SUMARIO = '', '', CONCAT(' — ', LEFT(t.SUMARIO, 200))), '.'), 1000),
             CONCAT('/principal/naoconformidades_ocorrencia.php?codigo=', t.CODOCORRENCIA, '&aba=', t.ABA), 0
        FROM tmp_nc_lemb t;
      IF v_sup > 0 THEN
        INSERT INTO alertas (codusuario, titulo, mensagem, link, lido)
        SELECT t.SUPERIOR, LEFT(CONCAT('NC ', t.NUMERO, ': pendência de ', t.NOME_DESTINO, ' sem solução'), 180),
               LEFT(CONCAT('Você recebe este aviso como superior de ', t.NOME_DESTINO, ': a não conformidade ', t.NUMERO, ': ', t.TEXTO, ' (', t.VEZ, 'º lembrete de "', v_nome, '").'), 1000),
               CONCAT('/principal/naoconformidades_ocorrencia.php?codigo=', t.CODOCORRENCIA, '&aba=', t.ABA), 0
          FROM tmp_nc_lemb t JOIN usuarios s ON s.codusuario = t.SUPERIOR AND s.ativo = 1
         WHERE v_esc > 0 AND t.VEZ > v_esc AND t.SUPERIOR <> t.CODUSUARIO;
      END IF;
      IF v_email = 1 THEN
        INSERT INTO email_fila (destinatario, nome_destinatario, assunto, corpo, origem, referencia)
        SELECT p.EMAIL, p.NOME, LEFT(CONCAT('NC ', t.NUMERO, ': ', t.TEXTO), 255),
               CONCAT_WS(CHAR(10), CONCAT('Lembrete: a não conformidade ', t.NUMERO, ': ', t.TEXTO, '.'), IF(t.SUMARIO = '', NULL, CONCAT('Sumário: ', t.SUMARIO)), '',
                 CONCAT('Este é o ', t.VEZ, 'º aviso da regra "', v_nome, '". Acesse o greenportal para tratar a pendência.')),
               'NC_LEMBRETE', CONCAT('NC:', t.CODOCORRENCIA)
          FROM tmp_nc_lemb t JOIN vw_nc_pessoas p ON p.CODUSUARIO = t.CODUSUARIO AND p.TIPO = 'USUARIO' WHERE TRIM(p.EMAIL) <> '';
        IF v_sup > 0 THEN
          INSERT INTO email_fila (destinatario, nome_destinatario, assunto, corpo, origem, referencia)
          SELECT p.EMAIL, p.NOME, LEFT(CONCAT('NC ', t.NUMERO, ': pendência de ', t.NOME_DESTINO, ' sem solução'), 255),
                 CONCAT('Você recebe este aviso como superior de ', t.NOME_DESTINO, ': a não conformidade ', t.NUMERO, ': ', t.TEXTO, ' (', t.VEZ, 'º lembrete de "', v_nome, '").'),
                 'NC_LEMBRETE', CONCAT('NC:', t.CODOCORRENCIA)
            FROM tmp_nc_lemb t JOIN usuarios s ON s.codusuario = t.SUPERIOR AND s.ativo = 1 JOIN vw_nc_pessoas p ON p.CODUSUARIO = t.SUPERIOR AND p.TIPO = 'USUARIO'
           WHERE v_esc > 0 AND t.VEZ > v_esc AND t.SUPERIOR <> t.CODUSUARIO AND TRIM(p.EMAIL) <> '';
        END IF;
      END IF;
      INSERT INTO nc_lembrete_envios (CODREGRA, CODOCORRENCIA, CODPLANOACAO, CODUSUARIO, QTD, PRIMEIRO_EM, ULTIMO_EM)
      SELECT v_regra, t.CODOCORRENCIA, t.CODPLANOACAO, t.CODUSUARIO, 1, NOW(), NOW() FROM tmp_nc_lemb t
      ON DUPLICATE KEY UPDATE QTD = nc_lembrete_envios.QTD + 1, ULTIMO_EM = NOW();
      UPDATE nc_lembrete_regras SET ULTIMA_EXECUCAO = NOW(), QTD_ENVIADOS = QTD_ENVIADOS + v_n WHERE CODREGRA = v_regra;
    END IF;
    INSERT INTO tmp_nc_lemb_res VALUES (v_regra, v_nome, v_n, v_sup);
  END LOOP;
  CLOSE c;
  DROP TEMPORARY TABLE IF EXISTS tmp_nc_lemb;
  SELECT CODREGRA, NOME, LEMBRETES, SUPERIORES FROM tmp_nc_lemb_res ORDER BY CODREGRA;
  DROP TEMPORARY TABLE IF EXISTS tmp_nc_lemb_res;
END
