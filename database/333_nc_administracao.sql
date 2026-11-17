-- Migração 333 — Não Conformidades › Administração, fase 1b (22/10/2026, decisões do usuário):
--   listas de distribuição, participantes externos (pessoas sem login que recebem os avisos por e-mail) e a fila de e-mails
--   enviada pelo serviço do Agendador do Windows (scripts/email-worker.php).
-- Regras (até a virada o legado prevalece):
--   - listas do sistema anterior (código abaixo de 50001) só consulta; listas novas do greenportal a partir de 50001
--     (SincronismoLegado::FAIXAS_NOVO: o sincronismo não apaga nem altera); "Copiar como nova lista" leva membros e categorias;
--   - participantes externos: os do legado (usuarios.ativado = 'X') vêm pelo sincronismo (nome e e-mail; no greenportal só se muda
--     Ativo); os novos, incluídos no greenportal, recebem código a partir de 90000001 (não colide com usuários);
--   - toda alteração fica em nc_admin_historico (antes → depois, quem e quando).
-- Aplicada por scripts/aplicar-migracao-333-nc-administracao.php (blocos separados por "-- @@").

CREATE TABLE IF NOT EXISTS `nc_participantes` (
  `CODUSUARIO` INT NOT NULL,
  `NOME` VARCHAR(200) NOT NULL,
  `EMAIL` VARCHAR(500) NOT NULL,
  `ATIVO` TINYINT(1) NOT NULL DEFAULT 1,
  `ORIGEM` VARCHAR(20) NOT NULL DEFAULT 'GREENPORTAL',
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `CODUSUARIO_INCLUSAO` INT NULL,
  `ALTERADO_EM` DATETIME NULL,
  PRIMARY KEY (`CODUSUARIO`),
  KEY `ix_nc_participantes_email` (`EMAIL`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
CREATE TABLE IF NOT EXISTS `nc_admin_historico` (
  `CODHISTORICO` BIGINT NOT NULL AUTO_INCREMENT,
  `ACAO` VARCHAR(50) NOT NULL,
  `OBJETO` VARCHAR(50) NOT NULL,
  `CODIGO` VARCHAR(50) NOT NULL,
  `ANTES` JSON NULL,
  `DEPOIS` JSON NULL,
  `MOTIVO` TEXT NULL,
  `CODUSUARIO` INT NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODHISTORICO`),
  KEY `ix_nc_admin_historico_objeto` (`OBJETO`, `CODIGO`),
  KEY `ix_nc_admin_historico_data` (`CRIADO_EM`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
CREATE TABLE IF NOT EXISTS `email_fila` (
  `codemail` BIGINT NOT NULL AUTO_INCREMENT,
  `destinatario` VARCHAR(500) NOT NULL,
  `nome_destinatario` VARCHAR(200) NULL,
  `assunto` VARCHAR(255) NOT NULL,
  `corpo` MEDIUMTEXT NOT NULL,
  `origem` VARCHAR(50) NOT NULL,
  `referencia` VARCHAR(100) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
  `tentativas` INT NOT NULL DEFAULT 0,
  `ultimo_erro` VARCHAR(1000) NULL,
  `token` CHAR(36) NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processando_em` DATETIME NULL,
  `proxima_tentativa_em` DATETIME NULL,
  `enviado_em` DATETIME NULL,
  PRIMARY KEY (`codemail`),
  KEY `ix_email_fila_status` (`status`, `proxima_tentativa_em`),
  KEY `ix_email_fila_token` (`token`),
  KEY `ix_email_fila_criado` (`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
-- Pessoas que podem estar numa lista: usuários do portal e participantes externos (o participante que também é usuário aparece como usuário)
CREATE OR REPLACE VIEW `vw_nc_pessoas` AS
SELECT u.codusuario AS CODUSUARIO,
       CONVERT(COALESCE(NULLIF(TRIM(u.nome), ''), u.userid, CONCAT('Usuário ', u.codusuario)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NOME,
       CONVERT(COALESCE(NULLIF(TRIM(u.email), ''), NULLIF(TRIM(u.emails), ''), '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS EMAIL,
       CONVERT('USUARIO' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS TIPO,
       CAST(COALESCE(u.ativo, 0) AS SIGNED) AS ATIVO
  FROM usuarios u
UNION ALL
SELECT p.CODUSUARIO, CONVERT(p.NOME USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CONVERT(p.EMAIL USING utf8mb4) COLLATE utf8mb4_0900_ai_ci,
       CONVERT('PARTICIPANTE' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci, CAST(p.ATIVO AS SIGNED)
  FROM nc_participantes p
 WHERE NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.codusuario = p.CODUSUARIO)
-- @@
CREATE OR REPLACE VIEW `vw_nc_lista_membros` AS
SELECT x.CODLISTAXUSUARIOS, x.CODLISTA, x.CODUSUARIO,
       COALESCE(p.NOME, CONVERT(CONCAT(x.CODUSUARIO, ' (não cadastrado)') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) AS NOME,
       COALESCE(p.EMAIL, '') AS EMAIL,
       COALESCE(p.TIPO, CONVERT('NAO_CADASTRADO' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) AS TIPO,
       CAST(COALESCE(p.ATIVO, 0) AS SIGNED) AS ATIVO
  FROM nc_listadistribuicaoxusuarios x
  LEFT JOIN vw_nc_pessoas p ON p.CODUSUARIO = x.CODUSUARIO
-- @@
CREATE OR REPLACE VIEW `vw_nc_categorias` AS
SELECT CODCATEGORIA, CONVERT(TRIM(DSCCATEGORIA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS DSCCATEGORIA FROM nc_categorias
-- @@
CREATE OR REPLACE VIEW `vw_nc_lista_categorias` AS
SELECT lc.CODLISTA, lc.CODCATEGORIA, CONVERT(TRIM(c.DSCCATEGORIA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS DSCCATEGORIA
  FROM nc_listadistribuicaoxcategoria lc JOIN nc_categorias c ON c.CODCATEGORIA = lc.CODCATEGORIA
-- @@
CREATE OR REPLACE VIEW `vw_nc_listas` AS
SELECT l.CODLISTA,
       CONVERT(TRIM(l.DSCLISTA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS DSCLISTA,
       (SELECT GROUP_CONCAT(DISTINCT c.DSCCATEGORIA ORDER BY c.DSCCATEGORIA SEPARATOR ', ') FROM vw_nc_lista_categorias c WHERE c.CODLISTA = l.CODLISTA) AS CATEGORIAS,
       CAST(COALESCE(m.QTD_MEMBROS, 0) AS SIGNED) AS QTD_MEMBROS,
       CAST(COALESCE(m.QTD_USUARIOS, 0) AS SIGNED) AS QTD_USUARIOS,
       CAST(COALESCE(m.QTD_PARTICIPANTES, 0) AS SIGNED) AS QTD_PARTICIPANTES,
       CAST(COALESCE(m.QTD_INATIVOS, 0) AS SIGNED) AS QTD_INATIVOS,
       CAST(COALESCE(n.QTD_NCS, 0) AS SIGNED) AS QTD_NCS,
       CAST(COALESCE(n.QTD_ABERTAS, 0) AS SIGNED) AS QTD_ABERTAS,
       n.ULTIMO_USO,
       CONVERT(IF(l.CODLISTA >= 50001, 'GREENPORTAL', 'LEGADO') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS ORIGEM
  FROM nc_listadistribuicao l
  LEFT JOIN (SELECT CODLISTA, COUNT(*) AS QTD_MEMBROS, SUM(TIPO = 'USUARIO') AS QTD_USUARIOS, SUM(TIPO = 'PARTICIPANTE') AS QTD_PARTICIPANTES,
                    SUM(ATIVO = 0) AS QTD_INATIVOS
               FROM vw_nc_lista_membros GROUP BY CODLISTA) m ON m.CODLISTA = l.CODLISTA
  LEFT JOIN (SELECT CAST(listadedistribuicao AS SIGNED) AS CODLISTA, COUNT(*) AS QTD_NCS, SUM(COALESCE(ETAPA, '') <> 'Finalizado') AS QTD_ABERTAS,
                    MAX(COALESCE(data_de_criacao, DATA_OCORRENCIA)) AS ULTIMO_USO
               FROM nc_indicacao_ocorrencia WHERE listadedistribuicao REGEXP '^[0-9]+$' GROUP BY CAST(listadedistribuicao AS SIGNED)) n ON n.CODLISTA = l.CODLISTA
-- @@
CREATE OR REPLACE VIEW `vw_nc_participantes` AS
SELECT p.CODUSUARIO, CONVERT(p.NOME USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NOME, CONVERT(p.EMAIL USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS EMAIL,
       CAST(p.ATIVO AS SIGNED) AS ATIVO, CONVERT(p.ORIGEM USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS ORIGEM, p.CRIADO_EM, p.ALTERADO_EM,
       (SELECT COUNT(DISTINCT x.CODLISTA) FROM nc_listadistribuicaoxusuarios x WHERE x.CODUSUARIO = p.CODUSUARIO) AS QTD_LISTAS,
       CAST(EXISTS (SELECT 1 FROM usuarios u WHERE u.codusuario = p.CODUSUARIO) AS SIGNED) AS TAMBEM_USUARIO
  FROM nc_participantes p
-- @@
CREATE OR REPLACE VIEW `vw_nc_participante_listas` AS
SELECT x.CODUSUARIO, l.CODLISTA, CONVERT(TRIM(l.DSCLISTA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS DSCLISTA
  FROM nc_listadistribuicaoxusuarios x JOIN nc_listadistribuicao l ON l.CODLISTA = x.CODLISTA
-- @@
CREATE OR REPLACE VIEW `vw_nc_admin_historico` AS
SELECT h.CODHISTORICO, h.ACAO, h.OBJETO, h.CODIGO, h.ANTES, h.DEPOIS, h.MOTIVO, h.CODUSUARIO, h.CRIADO_EM,
       CONVERT(COALESCE(NULLIF(TRIM(u.nome), ''), u.userid, CONCAT('Usuário ', h.CODUSUARIO)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NOME_USUARIO
  FROM nc_admin_historico h LEFT JOIN usuarios u ON u.codusuario = h.CODUSUARIO
-- @@
CREATE OR REPLACE VIEW `vw_email_fila` AS
SELECT codemail, destinatario, nome_destinatario, assunto, origem, referencia, status, tentativas, ultimo_erro, criado_em, proxima_tentativa_em, enviado_em
  FROM email_fila
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_admin_historico`
-- @@
CREATE PROCEDURE `sp_nc_admin_historico`(IN p_acao VARCHAR(50), IN p_objeto VARCHAR(50), IN p_codigo VARCHAR(50), IN p_antes JSON, IN p_depois JSON, IN p_motivo TEXT, IN p_usuario INT)
BEGIN
  INSERT INTO nc_admin_historico (ACAO, OBJETO, CODIGO, ANTES, DEPOIS, MOTIVO, CODUSUARIO) VALUES (p_acao, p_objeto, p_codigo, p_antes, p_depois, NULLIF(TRIM(COALESCE(p_motivo, '')), ''), p_usuario);
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_lista_validar`
-- @@
-- Lista que pode ser alterada no greenportal (criada nele) e nome livre
CREATE PROCEDURE `sp_nc_lista_validar`(IN p_codigo INT, IN p_nome VARCHAR(500), IN p_categorias JSON)
BEGIN
  IF p_codigo > 0 AND p_codigo < 50001 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lista do sistema anterior: até a virada só consulta. Use "Copiar como nova lista" para criar uma lista no greenportal.';
  END IF;
  IF p_codigo > 0 AND NOT EXISTS (SELECT 1 FROM nc_listadistribuicao WHERE CODLISTA = p_codigo) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lista não encontrada.';
  END IF;
  IF p_nome IS NOT NULL THEN
    IF TRIM(p_nome) = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o nome da lista.'; END IF;
    IF EXISTS (SELECT 1 FROM nc_listadistribuicao WHERE CODLISTA <> COALESCE(p_codigo, 0)
                AND CONVERT(TRIM(DSCLISTA) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = CONVERT(TRIM(p_nome) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Já existe uma lista com este nome.';
    END IF;
  END IF;
  IF p_categorias IS NOT NULL THEN
    IF JSON_TYPE(p_categorias) <> 'ARRAY' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Categorias inválidas.'; END IF;
    IF EXISTS (SELECT 1 FROM JSON_TABLE(p_categorias, '$[*]' COLUMNS (c INT PATH '$')) j WHERE NOT EXISTS (SELECT 1 FROM nc_categorias k WHERE k.CODCATEGORIA = j.c)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Categoria inexistente.';
    END IF;
  END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_lista_salvar`
-- @@
CREATE PROCEDURE `sp_nc_lista_salvar`(IN p_codigo INT, IN p_nome VARCHAR(500), IN p_categorias JSON, IN p_usuario INT)
BEGIN
  DECLARE v_cod INT DEFAULT COALESCE(p_codigo, 0);
  DECLARE v_nome VARCHAR(500) DEFAULT TRIM(COALESCE(p_nome, ''));
  DECLARE v_cats JSON DEFAULT COALESCE(p_categorias, JSON_ARRAY());
  DECLARE v_antes JSON DEFAULT NULL;
  DECLARE v_base INT DEFAULT 0;
  CALL sp_nc_lista_validar(v_cod, v_nome, v_cats);
  IF v_cod = 0 THEN
    SELECT GREATEST(50001, COALESCE(MAX(CODLISTA), 0) + 1) INTO v_cod FROM nc_listadistribuicao WHERE CODLISTA >= 50001;
    INSERT INTO nc_listadistribuicao (CODLISTA, DSCLISTA) VALUES (v_cod, v_nome);
  ELSE
    SELECT JSON_OBJECT('nome', DSCLISTA, 'categorias', (SELECT JSON_ARRAYAGG(CODCATEGORIA) FROM nc_listadistribuicaoxcategoria WHERE CODLISTA = v_cod))
      INTO v_antes FROM nc_listadistribuicao WHERE CODLISTA = v_cod;
    UPDATE nc_listadistribuicao SET DSCLISTA = v_nome WHERE CODLISTA = v_cod;
  END IF;
  DELETE FROM nc_listadistribuicaoxcategoria
   WHERE CODLISTA = v_cod AND CODCATEGORIA NOT IN (SELECT c FROM JSON_TABLE(v_cats, '$[*]' COLUMNS (c INT PATH '$')) j WHERE c IS NOT NULL);
  SELECT GREATEST(50000, COALESCE(MAX(CODLISTACATEGORIA), 0)) INTO v_base FROM nc_listadistribuicaoxcategoria WHERE CODLISTACATEGORIA >= 50001;
  INSERT INTO nc_listadistribuicaoxcategoria (CODLISTACATEGORIA, CODLISTA, CODCATEGORIA)
  SELECT v_base + ROW_NUMBER() OVER (ORDER BY j.c), v_cod, j.c
    FROM (SELECT DISTINCT c FROM JSON_TABLE(v_cats, '$[*]' COLUMNS (c INT PATH '$')) t WHERE c IS NOT NULL) j
   WHERE NOT EXISTS (SELECT 1 FROM nc_listadistribuicaoxcategoria x WHERE x.CODLISTA = v_cod AND x.CODCATEGORIA = j.c);
  CALL sp_nc_admin_historico(IF(v_antes IS NULL, 'LISTA_INCLUIDA', 'LISTA_ALTERADA'), 'LISTA', v_cod, v_antes,
       JSON_OBJECT('nome', v_nome, 'categorias', (SELECT JSON_ARRAYAGG(CODCATEGORIA) FROM nc_listadistribuicaoxcategoria WHERE CODLISTA = v_cod)), NULL, p_usuario);
  SELECT v_cod AS CODLISTA;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_lista_membros`
-- @@
-- Inclui e retira pessoas (usuários ativos do portal ou participantes externos ativos) de uma lista do greenportal
CREATE PROCEDURE `sp_nc_lista_membros`(IN p_codigo INT, IN p_incluir JSON, IN p_retirar JSON, IN p_usuario INT)
BEGIN
  DECLARE v_inc JSON DEFAULT COALESCE(p_incluir, JSON_ARRAY());
  DECLARE v_ret JSON DEFAULT COALESCE(p_retirar, JSON_ARRAY());
  DECLARE v_base INT DEFAULT 0;
  DECLARE v_invalido INT DEFAULT NULL;
  DECLARE v_incluidos, v_retirados INT DEFAULT 0;
  DECLARE v_msg VARCHAR(255);
  CALL sp_nc_lista_validar(COALESCE(p_codigo, 0), NULL, NULL);
  IF COALESCE(p_codigo, 0) = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Grave a lista antes de incluir pessoas.'; END IF;
  IF JSON_TYPE(v_inc) <> 'ARRAY' OR JSON_TYPE(v_ret) <> 'ARRAY' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pessoas inválidas.'; END IF;
  SELECT j.c INTO v_invalido FROM JSON_TABLE(v_inc, '$[*]' COLUMNS (c INT PATH '$')) j
   WHERE NOT EXISTS (SELECT 1 FROM vw_nc_pessoas p WHERE p.CODUSUARIO = j.c AND p.ATIVO = 1) LIMIT 1;
  IF v_invalido IS NOT NULL THEN
    SET v_msg = CONCAT('Pessoa ', v_invalido, ' não existe ou está desativada.');
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
  END IF;
  DELETE FROM nc_listadistribuicaoxusuarios
   WHERE CODLISTA = p_codigo AND CODUSUARIO IN (SELECT c FROM JSON_TABLE(v_ret, '$[*]' COLUMNS (c INT PATH '$')) j WHERE c IS NOT NULL);
  SET v_retirados = ROW_COUNT();
  SELECT GREATEST(50000, COALESCE(MAX(CODLISTAXUSUARIOS), 0)) INTO v_base FROM nc_listadistribuicaoxusuarios WHERE CODLISTAXUSUARIOS >= 50001;
  INSERT INTO nc_listadistribuicaoxusuarios (CODLISTAXUSUARIOS, CODLISTA, CODUSUARIO)
  SELECT v_base + ROW_NUMBER() OVER (ORDER BY j.c), p_codigo, j.c
    FROM (SELECT DISTINCT c FROM JSON_TABLE(v_inc, '$[*]' COLUMNS (c INT PATH '$')) t WHERE c IS NOT NULL) j
   WHERE NOT EXISTS (SELECT 1 FROM nc_listadistribuicaoxusuarios x WHERE x.CODLISTA = p_codigo AND x.CODUSUARIO = j.c);
  SET v_incluidos = ROW_COUNT();
  IF v_incluidos > 0 OR v_retirados > 0 THEN
    CALL sp_nc_admin_historico('LISTA_MEMBROS', 'LISTA', p_codigo, NULL, JSON_OBJECT('incluidos', JSON_EXTRACT(v_inc, '$'), 'retirados', JSON_EXTRACT(v_ret, '$')), NULL, p_usuario);
  END IF;
  SELECT v_incluidos AS INCLUIDOS, v_retirados AS RETIRADOS;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_lista_excluir`
-- @@
CREATE PROCEDURE `sp_nc_lista_excluir`(IN p_codigo INT, IN p_usuario INT)
BEGIN
  DECLARE v_uso INT DEFAULT 0;
  DECLARE v_antes JSON;
  DECLARE v_msg VARCHAR(255);
  CALL sp_nc_lista_validar(COALESCE(p_codigo, 0), NULL, NULL);
  SELECT COUNT(*) INTO v_uso FROM nc_indicacao_ocorrencia WHERE listadedistribuicao REGEXP '^[0-9]+$' AND CAST(listadedistribuicao AS SIGNED) = p_codigo;
  IF v_uso > 0 THEN
    SET v_msg = CONCAT('A lista está em ', v_uso, ' não conformidade(s) e não pode ser excluída.');
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
  END IF;
  SELECT JSON_OBJECT('nome', DSCLISTA,
         'categorias', (SELECT JSON_ARRAYAGG(CODCATEGORIA) FROM nc_listadistribuicaoxcategoria WHERE CODLISTA = p_codigo),
         'membros', (SELECT JSON_ARRAYAGG(CODUSUARIO) FROM nc_listadistribuicaoxusuarios WHERE CODLISTA = p_codigo))
    INTO v_antes FROM nc_listadistribuicao WHERE CODLISTA = p_codigo;
  DELETE FROM nc_listadistribuicaoxusuarios WHERE CODLISTA = p_codigo;
  DELETE FROM nc_listadistribuicaoxcategoria WHERE CODLISTA = p_codigo;
  DELETE FROM nc_listadistribuicao WHERE CODLISTA = p_codigo;
  CALL sp_nc_admin_historico('LISTA_EXCLUIDA', 'LISTA', p_codigo, v_antes, NULL, NULL, p_usuario);
  SELECT p_codigo AS CODLISTA;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_lista_copiar`
-- @@
-- Nova lista do greenportal a partir de outra (inclusive do sistema anterior), com as categorias e as pessoas cadastradas
CREATE PROCEDURE `sp_nc_lista_copiar`(IN p_origem INT, IN p_nome VARCHAR(500), IN p_usuario INT)
BEGIN
  DECLARE v_cod INT;
  DECLARE v_base INT DEFAULT 0;
  DECLARE v_nome VARCHAR(500) DEFAULT TRIM(COALESCE(p_nome, ''));
  IF NOT EXISTS (SELECT 1 FROM nc_listadistribuicao WHERE CODLISTA = p_origem) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lista de origem não encontrada.'; END IF;
  CALL sp_nc_lista_validar(0, v_nome, NULL);
  SELECT GREATEST(50001, COALESCE(MAX(CODLISTA), 0) + 1) INTO v_cod FROM nc_listadistribuicao WHERE CODLISTA >= 50001;
  INSERT INTO nc_listadistribuicao (CODLISTA, DSCLISTA) VALUES (v_cod, v_nome);
  SELECT GREATEST(50000, COALESCE(MAX(CODLISTACATEGORIA), 0)) INTO v_base FROM nc_listadistribuicaoxcategoria WHERE CODLISTACATEGORIA >= 50001;
  INSERT INTO nc_listadistribuicaoxcategoria (CODLISTACATEGORIA, CODLISTA, CODCATEGORIA)
  SELECT v_base + ROW_NUMBER() OVER (ORDER BY c.CODCATEGORIA), v_cod, c.CODCATEGORIA
    FROM (SELECT DISTINCT lc.CODCATEGORIA FROM nc_listadistribuicaoxcategoria lc JOIN nc_categorias k ON k.CODCATEGORIA = lc.CODCATEGORIA WHERE lc.CODLISTA = p_origem) c;
  SELECT GREATEST(50000, COALESCE(MAX(CODLISTAXUSUARIOS), 0)) INTO v_base FROM nc_listadistribuicaoxusuarios WHERE CODLISTAXUSUARIOS >= 50001;
  INSERT INTO nc_listadistribuicaoxusuarios (CODLISTAXUSUARIOS, CODLISTA, CODUSUARIO)
  SELECT v_base + ROW_NUMBER() OVER (ORDER BY m.CODUSUARIO), v_cod, m.CODUSUARIO
    FROM (SELECT DISTINCT x.CODUSUARIO FROM nc_listadistribuicaoxusuarios x JOIN vw_nc_pessoas p ON p.CODUSUARIO = x.CODUSUARIO WHERE x.CODLISTA = p_origem) m;
  CALL sp_nc_admin_historico('LISTA_COPIADA', 'LISTA', v_cod, NULL, JSON_OBJECT('nome', v_nome, 'copiada_de', p_origem), NULL, p_usuario);
  SELECT v_cod AS CODLISTA;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_participante_salvar`
-- @@
-- Participante externo (sem login; recebe os avisos das listas por e-mail). Os do sistema anterior: só Ativo.
CREATE PROCEDURE `sp_nc_participante_salvar`(IN p_codigo INT, IN p_nome VARCHAR(200), IN p_email VARCHAR(500), IN p_ativo TINYINT, IN p_usuario INT)
BEGIN
  DECLARE v_cod INT DEFAULT COALESCE(p_codigo, 0);
  DECLARE v_origem VARCHAR(20);
  DECLARE v_antes JSON;
  DECLARE v_nome VARCHAR(200) DEFAULT TRIM(COALESCE(p_nome, ''));
  DECLARE v_email VARCHAR(500) DEFAULT TRIM(BOTH ';' FROM TRIM(REPLACE(REPLACE(COALESCE(p_email, ''), ',', ';'), ' ', '')));
  DECLARE v_ativo TINYINT DEFAULT IF(COALESCE(p_ativo, 1) = 0, 0, 1);
  DECLARE v_aviso VARCHAR(255) DEFAULT '';
  IF v_cod > 0 THEN
    SELECT ORIGEM, JSON_OBJECT('nome', NOME, 'email', EMAIL, 'ativo', ATIVO) INTO v_origem, v_antes FROM nc_participantes WHERE CODUSUARIO = v_cod;
    IF v_origem IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Participante não encontrado.'; END IF;
  END IF;
  IF v_origem = 'LEGADO' THEN
    -- Nome e e-mail vêm do sistema anterior pelo sincronismo; aqui só Ativo
    UPDATE nc_participantes SET ATIVO = v_ativo, ALTERADO_EM = NOW() WHERE CODUSUARIO = v_cod;
    IF v_nome <> '' AND (CONVERT(v_nome USING utf8mb4) COLLATE utf8mb4_0900_ai_ci <> (SELECT CONVERT(NOME USING utf8mb4) COLLATE utf8mb4_0900_ai_ci FROM nc_participantes WHERE CODUSUARIO = v_cod)
        OR CONVERT(v_email USING utf8mb4) COLLATE utf8mb4_0900_ai_ci <> (SELECT CONVERT(EMAIL USING utf8mb4) COLLATE utf8mb4_0900_ai_ci FROM nc_participantes WHERE CODUSUARIO = v_cod)) THEN
      SET v_aviso = 'Participante do sistema anterior: nome e e-mail só podem ser alterados lá até a virada; foi gravado apenas o campo Ativo.';
    END IF;
  ELSE
    IF v_nome = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o nome do participante.'; END IF;
    IF v_email NOT REGEXP '^[^@;[:space:]]+@[^@;[:space:]]+[.][^@;[:space:]]+(;[^@;[:space:]]+@[^@;[:space:]]+[.][^@;[:space:]]+)*$' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'E-mail inválido (para mais de um, separe com ponto e vírgula).';
    END IF;
    IF EXISTS (SELECT 1 FROM nc_participantes WHERE CODUSUARIO <> v_cod AND ATIVO = 1
                AND CONVERT(LOWER(EMAIL) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = CONVERT(LOWER(v_email) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Já existe um participante ativo com este e-mail.';
    END IF;
    IF v_cod = 0 THEN
      SELECT GREATEST(90000001, COALESCE(MAX(CODUSUARIO), 0) + 1) INTO v_cod FROM nc_participantes WHERE CODUSUARIO >= 90000001;
      IF EXISTS (SELECT 1 FROM usuarios WHERE codusuario = v_cod) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Faixa de códigos de participantes ocupada por um usuário: avise o suporte.'; END IF;
      INSERT INTO nc_participantes (CODUSUARIO, NOME, EMAIL, ATIVO, ORIGEM, CODUSUARIO_INCLUSAO) VALUES (v_cod, v_nome, v_email, v_ativo, 'GREENPORTAL', p_usuario);
    ELSE
      UPDATE nc_participantes SET NOME = v_nome, EMAIL = v_email, ATIVO = v_ativo, ALTERADO_EM = NOW() WHERE CODUSUARIO = v_cod;
    END IF;
  END IF;
  CALL sp_nc_admin_historico(IF(v_antes IS NULL, 'PARTICIPANTE_INCLUIDO', 'PARTICIPANTE_ALTERADO'), 'PARTICIPANTE', v_cod, v_antes,
       (SELECT JSON_OBJECT('nome', NOME, 'email', EMAIL, 'ativo', ATIVO) FROM nc_participantes WHERE CODUSUARIO = v_cod), NULL, p_usuario);
  SELECT v_cod AS CODUSUARIO, v_aviso AS AVISO;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_email_enfileirar`
-- @@
CREATE PROCEDURE `sp_email_enfileirar`(IN p_destinatario VARCHAR(500), IN p_nome VARCHAR(200), IN p_assunto VARCHAR(255), IN p_corpo MEDIUMTEXT, IN p_origem VARCHAR(50), IN p_referencia VARCHAR(100))
BEGIN
  IF TRIM(COALESCE(p_destinatario, '')) <> '' THEN
    INSERT INTO email_fila (destinatario, nome_destinatario, assunto, corpo, origem, referencia) VALUES (TRIM(p_destinatario), p_nome, LEFT(p_assunto, 255), p_corpo, p_origem, p_referencia);
  END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_email_reivindicar`
-- @@
-- Serviço de e-mail (scripts/email-worker.php): pega até 25 e-mails pendentes (os presos em PROCESSANDO há mais de 30 minutos voltam à fila)
CREATE PROCEDURE `sp_email_reivindicar`()
BEGIN
  DECLARE v_token CHAR(36) DEFAULT UUID();
  UPDATE email_fila SET status = 'PENDENTE', token = NULL WHERE status = 'PROCESSANDO' AND processando_em < NOW() - INTERVAL 30 MINUTE;
  UPDATE email_fila SET status = 'PROCESSANDO', token = v_token, processando_em = NOW()
   WHERE status = 'PENDENTE' AND (proxima_tentativa_em IS NULL OR proxima_tentativa_em <= NOW()) ORDER BY codemail LIMIT 25;
  SELECT codemail, destinatario, nome_destinatario, assunto, corpo, origem, referencia, tentativas FROM email_fila WHERE token = v_token ORDER BY codemail;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_email_resultado`
-- @@
-- Resultado do envio: enviado, ou nova tentativa em 10/20/30/40 minutos; na 5ª falha fica em ERRO (Administração › E-mails enviados reenvia)
CREATE PROCEDURE `sp_email_resultado`(IN p_codigo BIGINT, IN p_ok TINYINT, IN p_erro VARCHAR(1000))
BEGIN
  IF p_ok = 1 THEN
    UPDATE email_fila SET status = 'CONCLUIDA', enviado_em = NOW(), token = NULL, ultimo_erro = NULL WHERE codemail = p_codigo;
  ELSE
    UPDATE email_fila SET tentativas = tentativas + 1, status = IF(tentativas >= 5, 'ERRO', 'PENDENTE'),
           proxima_tentativa_em = NOW() + INTERVAL (10 * tentativas) MINUTE, ultimo_erro = LEFT(COALESCE(p_erro, ''), 1000), token = NULL
     WHERE codemail = p_codigo;
  END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_email_recusado`
-- @@
-- Recusa definitiva (endereço inválido ou servidor respondeu 5xx): vai direto para ERRO, sem novas tentativas
CREATE PROCEDURE `sp_email_recusado`(IN p_codigo BIGINT, IN p_erro VARCHAR(1000))
BEGIN
  UPDATE email_fila SET tentativas = tentativas + 1, status = 'ERRO', ultimo_erro = LEFT(COALESCE(p_erro, ''), 1000), token = NULL, proxima_tentativa_em = NULL WHERE codemail = p_codigo;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_email_devolver`
-- @@
-- Servidor de e-mail fora do ar: devolve à fila sem contar tentativa
CREATE PROCEDURE `sp_email_devolver`(IN p_codigo BIGINT)
BEGIN
  UPDATE email_fila SET status = 'PENDENTE', token = NULL WHERE codemail = p_codigo AND status = 'PROCESSANDO';
END
-- @@
DROP PROCEDURE IF EXISTS `sp_email_reenviar`
-- @@
CREATE PROCEDURE `sp_email_reenviar`(IN p_codigo BIGINT)
BEGIN
  UPDATE email_fila SET status = 'PENDENTE', tentativas = 0, proxima_tentativa_em = NULL, token = NULL WHERE codemail = p_codigo AND status IN ('ERRO', 'CANCELADA');
  SELECT ROW_COUNT() AS ALTERADOS;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_nc_email_participantes`
-- @@
-- Chamado por sp_nc_alertar quando a NC é enviada ao gestor: os participantes externos ativos da lista de distribuição recebem
-- por e-mail o mesmo aviso que os usuários recebem em Home › Alertas ("Tomar conhecimento da criação da não conformidade").
CREATE PROCEDURE `sp_nc_email_participantes`(IN p_codigo INT, IN p_titulo VARCHAR(180))
BEGIN
  DECLARE v_lista VARCHAR(100);
  DECLARE v_numero, v_sumario, v_etapa, v_tipo, v_unidade, v_dsclista VARCHAR(500) DEFAULT '';
  DECLARE v_descricao MEDIUMTEXT;
  SELECT listadedistribuicao, CONVERT(COALESCE(NULLIF(NRANO, ''), CODOCORRENCIA) USING utf8mb4), CONVERT(COALESCE(IDENTIFICACAO, '') USING utf8mb4),
         CONVERT(fn_nc_etapa(COALESCE(ETAPA, '')) USING utf8mb4), CONVERT(COALESCE(DSCTIPONAOCONFORMIDADE, '') USING utf8mb4), CONVERT(COALESCE(DSCUNIDADE, '') USING utf8mb4),
         CONVERT(COALESCE(DSCLISTA, '') USING utf8mb4), CONVERT(COALESCE(DESCRICAO, '') USING utf8mb4)
    INTO v_lista, v_numero, v_sumario, v_etapa, v_tipo, v_unidade, v_dsclista, v_descricao
    FROM vw_nc_ocorrencia WHERE CODOCORRENCIA = p_codigo;
  IF v_lista REGEXP '^[0-9]+$' THEN
    INSERT INTO email_fila (destinatario, nome_destinatario, assunto, corpo, origem, referencia)
    SELECT p.EMAIL, p.NOME, LEFT(CONCAT('NC ', v_numero, ': ', p_titulo), 255),
           CONCAT_WS(CHAR(10), p_titulo, '',
             CONCAT('Não conformidade: ', v_numero),
             IF(v_tipo = '', NULL, CONCAT('Tipo: ', v_tipo)),
             IF(v_unidade = '', NULL, CONCAT('Unidade: ', v_unidade)),
             IF(v_sumario = '', NULL, CONCAT('Sumário: ', v_sumario)),
             IF(COALESCE(v_descricao, '') = '', NULL, CONCAT('Descrição: ', LEFT(v_descricao, 4000))),
             CONCAT('Etapa: ', v_etapa), '',
             CONCAT('Você recebe este aviso por fazer parte da lista de distribuição "', v_dsclista, '".')),
           'NAO_CONFORMIDADE', CONCAT('NC:', p_codigo)
      FROM nc_listadistribuicaoxusuarios x
      JOIN nc_participantes p ON p.CODUSUARIO = x.CODUSUARIO AND p.ATIVO = 1
     WHERE x.CODLISTA = CAST(v_lista AS SIGNED) AND TRIM(p.EMAIL) <> ''
       AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.codusuario = p.CODUSUARIO);
  END IF;
END
