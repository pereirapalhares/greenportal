-- Migração 340 — Taxímetro de IA (23/10/2026). Banco de cada empresa.
-- Cada chamada à OpenAI (fila de IA, assistente, solicitações de melhoria, licenças) grava uma linha em ia_consumo com os tokens
-- devolvidos pela OpenAI, o preço do modelo e a cotação PTAX do dia (tabelas do universal, migração 340 universal) e o custo em R$.
-- O limite mensal (só o perfil ADMINISTRADOR altera, pela tela Administração › Consumo de IA) bloqueia novos pedidos de IA ao passar
-- do limite + folga; o serviço "iaconsumo" do Agendador avisa os ADM DA EMPRESA (e o ADMINISTRADOR) por alerta e e-mail.
-- Blocos separados por "-- @@" (scripts/aplicar-migracao-340-ia-consumo.php). Pode ser executada mais de uma vez.
-- @@
CREATE TABLE IF NOT EXISTS `ia_consumo` (
  `CODCONSUMO` BIGINT NOT NULL AUTO_INCREMENT,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `CODEMPRESA` INT NULL,
  `CODUSUARIO` BIGINT NULL COMMENT 'Quem pediu (NULL = serviço)',
  `MODULO` VARCHAR(60) NOT NULL COMMENT 'Módulo do sistema (IaTaximetro::MODULOS)',
  `TIPO` VARCHAR(50) NOT NULL COMMENT 'Tipo do pedido (tipo do job da fila, ASSISTENTE, SOLICITACAO_MELHORIA...)',
  `ENTIDADE` VARCHAR(100) NULL,
  `REGISTRO` VARCHAR(100) NULL COMMENT 'Registro da transação (ex.: código da NC)',
  `CODJOB` BIGINT NULL COMMENT 'green_ia_document_jobs.codjob',
  `ORIGEM` VARCHAR(20) NOT NULL DEFAULT 'TELA' COMMENT 'FILA, TELA ou HISTORICO (estimativa dos pedidos anteriores ao taxímetro)',
  `MODELO` VARCHAR(100) NOT NULL DEFAULT '',
  `PRECO_MODELO` VARCHAR(100) NULL COMMENT 'Linha da tabela de preços usada (* = preço padrão)',
  `TOKENS_ENTRADA` INT NOT NULL DEFAULT 0 COMMENT 'Tokens de entrada (inclui os em cache)',
  `TOKENS_CACHE` INT NOT NULL DEFAULT 0,
  `TOKENS_SAIDA` INT NOT NULL DEFAULT 0,
  `CUSTO_USD` DECIMAL(14,6) NOT NULL DEFAULT 0,
  `COTACAO` DECIMAL(10,4) NOT NULL DEFAULT 0 COMMENT 'R$ por US$ (PTAX venda)',
  `COTACAO_DATA` DATE NULL,
  `ACRESCIMO_PCT` DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT 'IOF/spread da empresa no dia',
  `CUSTO_BRL` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `ESTIMADO` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = tokens estimados pelo tamanho do texto',
  `SUCESSO` TINYINT(1) NOT NULL DEFAULT 1,
  `DURACAO_MS` INT NULL,
  `ERRO` VARCHAR(500) NULL,
  `REFERENCIA` VARCHAR(60) NULL COMMENT 'Chave da estimativa (JOB:n, MSG:n), evita repetir',
  PRIMARY KEY (`CODCONSUMO`),
  UNIQUE KEY `ux_ia_consumo_referencia` (`REFERENCIA`),
  KEY `ix_ia_consumo_data` (`CRIADO_EM`),
  KEY `ix_ia_consumo_usuario` (`CODUSUARIO`, `CRIADO_EM`),
  KEY `ix_ia_consumo_modulo` (`MODULO`, `CRIADO_EM`),
  KEY `ix_ia_consumo_job` (`CODJOB`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
CREATE TABLE IF NOT EXISTS `ia_limite_config` (
  `CHAVE` VARCHAR(40) NOT NULL COMMENT 'LIMITE_MENSAL_BRL, FOLGA_PCT, AVISO_PCT, ACRESCIMO_PCT, ESTIMATIVA_ATE_JOB, ESTIMATIVA_ATE_MSG',
  `VALOR` VARCHAR(40) NOT NULL DEFAULT '',
  `ALTERADO_POR` BIGINT NULL,
  `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CHAVE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
CREATE TABLE IF NOT EXISTS `ia_limite_extras` (
  `CODEXTRA` BIGINT NOT NULL AUTO_INCREMENT,
  `MES` DATE NOT NULL COMMENT 'Primeiro dia do mês em que o crédito vale',
  `VALOR_BRL` DECIMAL(14,2) NOT NULL,
  `MOTIVO` VARCHAR(500) NOT NULL,
  `CODUSUARIO` BIGINT NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODEXTRA`),
  KEY `ix_ia_limite_extras_mes` (`MES`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
CREATE TABLE IF NOT EXISTS `ia_limite_historico` (
  `CODHISTORICO` BIGINT NOT NULL AUTO_INCREMENT,
  `ACAO` VARCHAR(30) NOT NULL COMMENT 'LIMITE, FOLGA, AVISO, ACRESCIMO, EXTRA',
  `ANTES` VARCHAR(100) NULL,
  `DEPOIS` VARCHAR(100) NULL,
  `MOTIVO` VARCHAR(500) NULL,
  `CODUSUARIO` BIGINT NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODHISTORICO`),
  KEY `ix_ia_limite_historico_data` (`CRIADO_EM`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
CREATE TABLE IF NOT EXISTS `ia_limite_avisos` (
  `MES` DATE NOT NULL,
  `FAIXA` VARCHAR(10) NOT NULL COMMENT 'AVISO, LIMITE ou BLOQUEIO',
  `LIMITE_BRL` DECIMAL(14,2) NOT NULL COMMENT 'Limite do mês quando o aviso saiu (aumentar o limite libera novos avisos)',
  `CONSUMO_BRL` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `DESTINATARIOS` INT NOT NULL DEFAULT 0,
  `ENVIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`MES`, `FAIXA`, `LIMITE_BRL`)
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
CREATE OR REPLACE VIEW `vw_ia_consumo` AS
SELECT c.CODCONSUMO, c.CRIADO_EM, CAST(DATE_FORMAT(c.CRIADO_EM, '%Y-%m-01') AS DATE) AS MES, c.CODEMPRESA, c.CODUSUARIO,
       CONVERT(CASE WHEN c.CODUSUARIO IS NULL OR c.CODUSUARIO = 0 THEN 'Serviço (Agendador)'
                    ELSE COALESCE(NULLIF(TRIM(u.nome), ''), u.userid, CONCAT('Usuário ', c.CODUSUARIO)) END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NOME_USUARIO,
       c.MODULO, c.TIPO, c.ENTIDADE, c.REGISTRO, c.CODJOB, c.ORIGEM, c.MODELO, c.PRECO_MODELO,
       c.TOKENS_ENTRADA, c.TOKENS_CACHE, c.TOKENS_SAIDA, c.TOKENS_ENTRADA + c.TOKENS_SAIDA AS TOKENS_TOTAL,
       c.CUSTO_USD, c.COTACAO, c.COTACAO_DATA, c.ACRESCIMO_PCT, c.CUSTO_BRL, c.ESTIMADO, c.SUCESSO, c.DURACAO_MS, c.ERRO, c.REFERENCIA
  FROM ia_consumo c
  LEFT JOIN usuarios u ON u.codusuario = c.CODUSUARIO
-- @@
CREATE OR REPLACE VIEW `vw_ia_consumo_mensal` AS
SELECT CAST(DATE_FORMAT(CRIADO_EM, '%Y-%m-01') AS DATE) AS MES, COUNT(*) AS CHAMADAS, CAST(SUM(ESTIMADO) AS SIGNED) AS ESTIMADAS,
       CAST(SUM(SUCESSO = 0) AS SIGNED) AS ERROS, CAST(SUM(TOKENS_ENTRADA) AS SIGNED) AS TOKENS_ENTRADA, CAST(SUM(TOKENS_SAIDA) AS SIGNED) AS TOKENS_SAIDA,
       SUM(CUSTO_USD) AS CUSTO_USD, SUM(CUSTO_BRL) AS CUSTO_BRL, COUNT(DISTINCT CODUSUARIO) AS USUARIOS
  FROM ia_consumo
 GROUP BY CAST(DATE_FORMAT(CRIADO_EM, '%Y-%m-01') AS DATE)
-- @@
CREATE OR REPLACE VIEW `vw_ia_limite_config` AS
SELECT CAST(NULLIF((SELECT VALOR FROM ia_limite_config WHERE CHAVE = 'LIMITE_MENSAL_BRL'), '') AS DECIMAL(14,2)) AS LIMITE_MENSAL_BRL,
       CAST(COALESCE(NULLIF((SELECT VALOR FROM ia_limite_config WHERE CHAVE = 'FOLGA_PCT'), ''), '10') AS DECIMAL(6,2)) AS FOLGA_PCT,
       CAST(COALESCE(NULLIF((SELECT VALOR FROM ia_limite_config WHERE CHAVE = 'AVISO_PCT'), ''), '80') AS DECIMAL(6,2)) AS AVISO_PCT,
       CAST(COALESCE(NULLIF((SELECT VALOR FROM ia_limite_config WHERE CHAVE = 'ACRESCIMO_PCT'), ''), '0') AS DECIMAL(6,2)) AS ACRESCIMO_PCT,
       (SELECT MAX(ALTERADO_EM) FROM ia_limite_config WHERE CHAVE IN ('LIMITE_MENSAL_BRL', 'FOLGA_PCT', 'AVISO_PCT', 'ACRESCIMO_PCT')) AS ALTERADO_EM
-- @@
CREATE OR REPLACE VIEW `vw_ia_limite_situacao` AS
SELECT b.MES, b.LIMITE_BASE_BRL, b.EXTRA_BRL,
       IF(b.LIMITE_BASE_BRL IS NULL, NULL, b.LIMITE_BASE_BRL + b.EXTRA_BRL) AS LIMITE_BRL,
       IF(b.LIMITE_BASE_BRL IS NULL, NULL, ROUND((b.LIMITE_BASE_BRL + b.EXTRA_BRL) * (1 + b.FOLGA_PCT / 100), 4)) AS BLOQUEIO_BRL,
       b.FOLGA_PCT, b.AVISO_PCT, b.ACRESCIMO_PCT, b.CONSUMO_BRL, b.CHAMADAS,
       IF(b.LIMITE_BASE_BRL IS NULL OR b.LIMITE_BASE_BRL + b.EXTRA_BRL <= 0, NULL, ROUND(b.CONSUMO_BRL * 100 / (b.LIMITE_BASE_BRL + b.EXTRA_BRL), 1)) AS PCT,
       CONVERT(CASE WHEN b.LIMITE_BASE_BRL IS NULL THEN 'SEM_LIMITE'
            WHEN b.CONSUMO_BRL >= ROUND((b.LIMITE_BASE_BRL + b.EXTRA_BRL) * (1 + b.FOLGA_PCT / 100), 4) THEN 'BLOQUEADO'
            WHEN b.CONSUMO_BRL >= b.LIMITE_BASE_BRL + b.EXTRA_BRL THEN 'EXCEDIDO'
            WHEN b.CONSUMO_BRL >= (b.LIMITE_BASE_BRL + b.EXTRA_BRL) * b.AVISO_PCT / 100 THEN 'AVISO'
            ELSE 'NORMAL' END USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS SITUACAO
  FROM (SELECT CAST(DATE_FORMAT(CURDATE(), '%Y-%m-01') AS DATE) AS MES, cfg.LIMITE_MENSAL_BRL AS LIMITE_BASE_BRL,
               COALESCE((SELECT SUM(e.VALOR_BRL) FROM ia_limite_extras e WHERE e.MES = CAST(DATE_FORMAT(CURDATE(), '%Y-%m-01') AS DATE)), 0) AS EXTRA_BRL,
               cfg.FOLGA_PCT, cfg.AVISO_PCT, cfg.ACRESCIMO_PCT,
               COALESCE((SELECT SUM(c.CUSTO_BRL) FROM ia_consumo c WHERE c.CRIADO_EM >= CAST(DATE_FORMAT(CURDATE(), '%Y-%m-01') AS DATE)), 0) AS CONSUMO_BRL,
               (SELECT COUNT(*) FROM ia_consumo c WHERE c.CRIADO_EM >= CAST(DATE_FORMAT(CURDATE(), '%Y-%m-01') AS DATE)) AS CHAMADAS
          FROM vw_ia_limite_config cfg) b
-- @@
CREATE OR REPLACE VIEW `vw_ia_limite_extras` AS
SELECT e.CODEXTRA, e.MES, e.VALOR_BRL, e.MOTIVO, e.CODUSUARIO, e.CRIADO_EM,
       CONVERT(COALESCE(NULLIF(TRIM(u.nome), ''), u.userid, CONCAT('Usuário ', e.CODUSUARIO)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NOME_USUARIO
  FROM ia_limite_extras e LEFT JOIN usuarios u ON u.codusuario = e.CODUSUARIO
-- @@
CREATE OR REPLACE VIEW `vw_ia_limite_historico` AS
SELECT h.CODHISTORICO, h.ACAO, h.ANTES, h.DEPOIS, h.MOTIVO, h.CODUSUARIO, h.CRIADO_EM,
       CONVERT(COALESCE(NULLIF(TRIM(u.nome), ''), u.userid, CONCAT('Usuário ', h.CODUSUARIO)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NOME_USUARIO
  FROM ia_limite_historico h LEFT JOIN usuarios u ON u.codusuario = h.CODUSUARIO
-- @@
CREATE OR REPLACE VIEW `vw_ia_limite_avisos` AS
SELECT MES, FAIXA, LIMITE_BRL, CONSUMO_BRL, DESTINATARIOS, ENVIADO_EM FROM ia_limite_avisos
-- @@
CREATE OR REPLACE VIEW `vw_ia_limite_destinatarios` AS
SELECT u.codusuario AS CODUSUARIO,
       CONVERT(COALESCE(NULLIF(TRIM(u.nome), ''), u.userid, CONCAT('Usuário ', u.codusuario)) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS NOME,
       CONVERT(COALESCE(NULLIF(TRIM(u.email), ''), NULLIF(TRIM(u.emails), ''), '') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS EMAIL,
       CAST(IF(UPPER(TRIM(p.codigo)) = 'ADMINISTRADOR', 1, 0) AS SIGNED) AS ADMINISTRADOR
  FROM usuarios u JOIN perfis p ON p.codperfil = u.codperfil
 WHERE COALESCE(u.ativo, 0) = 1
   AND (UPPER(TRIM(p.codigo)) = 'ADMINISTRADOR' OR UPPER(REPLACE(REPLACE(TRIM(p.codigo), '_', ' '), '-', ' ')) IN ('ADM DA EMPRESA', 'ADM EMPRESA'))
-- @@
DROP PROCEDURE IF EXISTS `sp_ia_consumo_registrar`
-- @@
CREATE PROCEDURE `sp_ia_consumo_registrar`(
  IN p_criado DATETIME, IN p_empresa INT, IN p_usuario BIGINT, IN p_modulo VARCHAR(60), IN p_tipo VARCHAR(50), IN p_entidade VARCHAR(100),
  IN p_registro VARCHAR(100), IN p_codjob BIGINT, IN p_origem VARCHAR(20), IN p_modelo VARCHAR(100), IN p_preco_modelo VARCHAR(100),
  IN p_entrada INT, IN p_cache INT, IN p_saida INT, IN p_usd DECIMAL(14,6), IN p_cotacao DECIMAL(10,4), IN p_cotacao_data DATE,
  IN p_estimado TINYINT, IN p_sucesso TINYINT, IN p_ms INT, IN p_erro VARCHAR(500), IN p_referencia VARCHAR(60))
BEGIN
  DECLARE v_acr DECIMAL(6,2) DEFAULT 0;
  SELECT ACRESCIMO_PCT INTO v_acr FROM vw_ia_limite_config;
  INSERT IGNORE INTO ia_consumo (CRIADO_EM, CODEMPRESA, CODUSUARIO, MODULO, TIPO, ENTIDADE, REGISTRO, CODJOB, ORIGEM, MODELO, PRECO_MODELO,
         TOKENS_ENTRADA, TOKENS_CACHE, TOKENS_SAIDA, CUSTO_USD, COTACAO, COTACAO_DATA, ACRESCIMO_PCT, CUSTO_BRL, ESTIMADO, SUCESSO, DURACAO_MS, ERRO, REFERENCIA)
  VALUES (COALESCE(p_criado, NOW()), NULLIF(p_empresa, 0), NULLIF(p_usuario, 0), LEFT(COALESCE(NULLIF(p_modulo, ''), 'Outros'), 60), LEFT(COALESCE(p_tipo, ''), 50),
          LEFT(p_entidade, 100), LEFT(p_registro, 100), NULLIF(p_codjob, 0), LEFT(COALESCE(NULLIF(p_origem, ''), 'TELA'), 20), LEFT(COALESCE(p_modelo, ''), 100), LEFT(p_preco_modelo, 100),
          GREATEST(COALESCE(p_entrada, 0), 0), GREATEST(COALESCE(p_cache, 0), 0), GREATEST(COALESCE(p_saida, 0), 0), COALESCE(p_usd, 0), COALESCE(p_cotacao, 0), p_cotacao_data,
          COALESCE(v_acr, 0), ROUND(COALESCE(p_usd, 0) * COALESCE(p_cotacao, 0) * (1 + COALESCE(v_acr, 0) / 100), 4),
          IF(p_estimado = 1, 1, 0), IF(p_sucesso = 0, 0, 1), p_ms, LEFT(p_erro, 500), NULLIF(p_referencia, ''));
  SELECT ROW_COUNT() AS GRAVADOS, LAST_INSERT_ID() AS CODCONSUMO;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ia_limite_salvar`
-- @@
CREATE PROCEDURE `sp_ia_limite_salvar`(IN p_chave VARCHAR(40), IN p_valor VARCHAR(40), IN p_motivo VARCHAR(500), IN p_usuario BIGINT)
BEGIN
  DECLARE v_antes VARCHAR(40) DEFAULT NULL;
  DECLARE v_valor VARCHAR(40) DEFAULT TRIM(COALESCE(p_valor, ''));
  DECLARE v_num DECIMAL(14,2) DEFAULT NULL;
  IF p_chave NOT IN ('LIMITE_MENSAL_BRL', 'FOLGA_PCT', 'AVISO_PCT', 'ACRESCIMO_PCT') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Parâmetro do limite inválido.';
  END IF;
  SET v_valor = REPLACE(v_valor, ',', '.');
  IF v_valor <> '' THEN
    IF v_valor NOT REGEXP '^[0-9]+(\\.[0-9]{1,2})?$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe um número (até 2 casas decimais).'; END IF;
    SET v_num = CAST(v_valor AS DECIMAL(14,2));
  ELSEIF p_chave <> 'LIMITE_MENSAL_BRL' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o percentual.';
  END IF;
  IF p_chave = 'FOLGA_PCT' AND v_num > 100 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A folga vai de 0 a 100%.'; END IF;
  IF p_chave = 'AVISO_PCT' AND (v_num < 1 OR v_num > 100) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O aviso vai de 1 a 100% do limite.'; END IF;
  IF p_chave = 'ACRESCIMO_PCT' AND v_num > 50 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O acréscimo (IOF/spread) vai de 0 a 50%.'; END IF;
  IF p_chave = 'LIMITE_MENSAL_BRL' AND v_num IS NOT NULL AND v_num <= 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O limite deve ser maior que zero (vazio = sem limite).'; END IF;
  IF p_chave = 'LIMITE_MENSAL_BRL' AND TRIM(COALESCE(p_motivo, '')) = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o motivo da alteração do limite (ex.: negociação com a empresa).'; END IF;
  SELECT VALOR INTO v_antes FROM ia_limite_config WHERE CHAVE = p_chave COLLATE utf8mb4_0900_ai_ci;
  IF v_num IS NOT NULL THEN SET v_valor = CAST(v_num AS CHAR); END IF;
  IF COALESCE(v_antes, '') <> v_valor THEN
    INSERT INTO ia_limite_config (CHAVE, VALOR, ALTERADO_POR, ALTERADO_EM) VALUES (p_chave, v_valor, p_usuario, NOW())
      ON DUPLICATE KEY UPDATE VALOR = VALUES(VALOR), ALTERADO_POR = VALUES(ALTERADO_POR), ALTERADO_EM = NOW();
    INSERT INTO ia_limite_historico (ACAO, ANTES, DEPOIS, MOTIVO, CODUSUARIO)
    VALUES (CASE p_chave WHEN 'LIMITE_MENSAL_BRL' THEN 'LIMITE' WHEN 'FOLGA_PCT' THEN 'FOLGA' WHEN 'AVISO_PCT' THEN 'AVISO' ELSE 'ACRESCIMO' END,
            NULLIF(COALESCE(v_antes, ''), ''), NULLIF(v_valor, ''), NULLIF(TRIM(COALESCE(p_motivo, '')), ''), p_usuario);
  END IF;
  SELECT p_chave AS CHAVE, v_valor AS VALOR, IF(COALESCE(v_antes, '') <> v_valor, 1, 0) AS ALTERADO;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ia_limite_extra_incluir`
-- @@
CREATE PROCEDURE `sp_ia_limite_extra_incluir`(IN p_valor DECIMAL(14,2), IN p_motivo VARCHAR(500), IN p_usuario BIGINT)
BEGIN
  DECLARE v_mes DATE DEFAULT CAST(DATE_FORMAT(CURDATE(), '%Y-%m-01') AS DATE);
  IF p_valor IS NULL OR p_valor <= 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o valor do crédito extra (maior que zero).'; END IF;
  IF TRIM(COALESCE(p_motivo, '')) = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o motivo do crédito extra (ex.: negociação com a empresa).'; END IF;
  IF (SELECT LIMITE_MENSAL_BRL FROM vw_ia_limite_config) IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A empresa está sem limite: defina o limite mensal antes de dar crédito extra.'; END IF;
  INSERT INTO ia_limite_extras (MES, VALOR_BRL, MOTIVO, CODUSUARIO) VALUES (v_mes, p_valor, TRIM(p_motivo), p_usuario);
  INSERT INTO ia_limite_historico (ACAO, ANTES, DEPOIS, MOTIVO, CODUSUARIO) VALUES ('EXTRA', NULL, CONCAT(CAST(p_valor AS CHAR), ' em ', DATE_FORMAT(v_mes, '%m/%Y')), TRIM(p_motivo), p_usuario);
  SELECT LAST_INSERT_ID() AS CODEXTRA;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ia_limite_avisar`
-- @@
CREATE PROCEDURE `sp_ia_limite_avisar`(IN p_simular TINYINT)
BEGIN
  DECLARE v_mes DATE; DECLARE v_limite DECIMAL(14,2); DECLARE v_bloq DECIMAL(14,2); DECLARE v_consumo DECIMAL(14,4);
  DECLARE v_aviso DECIMAL(6,2); DECLARE v_folga DECIMAL(6,2); DECLARE v_pct DECIMAL(10,1); DECLARE v_situacao VARCHAR(20);
  DECLARE v_faixa VARCHAR(10) DEFAULT NULL; DECLARE v_n INT DEFAULT 0; DECLARE v_titulo VARCHAR(180); DECLARE v_msg VARCHAR(1000);
  SELECT MES, LIMITE_BRL, BLOQUEIO_BRL, CONSUMO_BRL, AVISO_PCT, FOLGA_PCT, PCT, SITUACAO
    INTO v_mes, v_limite, v_bloq, v_consumo, v_aviso, v_folga, v_pct, v_situacao FROM vw_ia_limite_situacao;
  SET v_faixa = CASE v_situacao WHEN 'BLOQUEADO' THEN 'BLOQUEIO' WHEN 'EXCEDIDO' THEN 'LIMITE' WHEN 'AVISO' THEN 'AVISO' ELSE NULL END;
  IF v_faixa IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ia_limite_avisos a WHERE a.MES = v_mes AND a.FAIXA = v_faixa COLLATE utf8mb4_0900_ai_ci AND a.LIMITE_BRL = v_limite) THEN
    SET v_titulo = CASE v_faixa
      WHEN 'AVISO' THEN CONCAT('IA: ', FORMAT(v_pct, 0, 'pt_BR'), '% do limite mensal já usado')
      WHEN 'LIMITE' THEN 'IA: limite mensal da empresa atingido'
      ELSE 'IA bloqueada: limite mensal e folga esgotados' END;
    SET v_msg = CONCAT('A empresa usou R$ ', FORMAT(v_consumo, 2, 'pt_BR'), ' (', FORMAT(v_pct, 0, 'pt_BR'), '%) do limite mensal de IA de R$ ', FORMAT(v_limite, 2, 'pt_BR'),
      ' em ', DATE_FORMAT(v_mes, '%m/%Y'), '. ',
      CASE v_faixa
        WHEN 'AVISO' THEN CONCAT('Ao passar de R$ ', FORMAT(v_bloq, 2, 'pt_BR'), ' (limite + folga de ', FORMAT(v_folga, 0, 'pt_BR'), '%), os novos pedidos de IA ficam bloqueados até o limite ser aumentado.')
        WHEN 'LIMITE' THEN CONCAT('Há uma folga de ', FORMAT(v_folga, 0, 'pt_BR'), '% (até R$ ', FORMAT(v_bloq, 2, 'pt_BR'), ') para terminar os trabalhos em andamento; depois os novos pedidos de IA serão bloqueados. Fale com o administrador para negociar o aumento do limite.')
        ELSE 'Os novos pedidos de IA estão bloqueados até o administrador aumentar o limite; as análises já pedidas continuam. Fale com o administrador para negociar o aumento.' END);
    SELECT COUNT(*) INTO v_n FROM vw_ia_limite_destinatarios d WHERE d.ADMINISTRADOR = 0 OR v_faixa <> 'AVISO';
    IF p_simular = 0 THEN
      INSERT INTO alertas (codusuario, titulo, mensagem, link, lido)
      SELECT d.CODUSUARIO, v_titulo, v_msg, '/principal/administracao_consumo_ia.php', 0
        FROM vw_ia_limite_destinatarios d WHERE d.ADMINISTRADOR = 0 OR v_faixa <> 'AVISO';
      INSERT INTO email_fila (destinatario, nome_destinatario, assunto, corpo, origem, referencia)
      SELECT d.EMAIL, d.NOME, LEFT(v_titulo, 255),
             CONCAT_WS(CHAR(10), v_msg, '', 'Acompanhe o consumo em Administração › Consumo de IA no greenportal.'), 'IA_LIMITE', CONCAT('IA_LIMITE:', DATE_FORMAT(v_mes, '%Y-%m'), ':', v_faixa)
        FROM vw_ia_limite_destinatarios d WHERE (d.ADMINISTRADOR = 0 OR v_faixa <> 'AVISO') AND TRIM(d.EMAIL) <> '';
      INSERT IGNORE INTO ia_limite_avisos (MES, FAIXA, LIMITE_BRL, CONSUMO_BRL, DESTINATARIOS) VALUES (v_mes, v_faixa, v_limite, v_consumo, v_n);
      IF v_faixa IN ('LIMITE', 'BLOQUEIO') THEN INSERT IGNORE INTO ia_limite_avisos (MES, FAIXA, LIMITE_BRL, CONSUMO_BRL, DESTINATARIOS) VALUES (v_mes, 'AVISO', v_limite, v_consumo, 0); END IF;
      IF v_faixa = 'BLOQUEIO' THEN INSERT IGNORE INTO ia_limite_avisos (MES, FAIXA, LIMITE_BRL, CONSUMO_BRL, DESTINATARIOS) VALUES (v_mes, 'LIMITE', v_limite, v_consumo, 0); END IF;
    END IF;
  ELSE
    SET v_faixa = NULL;
  END IF;
  SELECT v_situacao AS SITUACAO, v_consumo AS CONSUMO_BRL, v_limite AS LIMITE_BRL, v_bloq AS BLOQUEIO_BRL, v_pct AS PCT, v_faixa AS AVISO_ENVIADO, IF(v_faixa IS NULL, 0, v_n) AS DESTINATARIOS;
END
