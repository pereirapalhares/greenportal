-- Migração 246 — Assistente de IA e Solicitações de melhoria: acesso ao banco somente por views e procedures.
-- Substitui o SQL escrito nas classes AssistenteAplicacaoService e SolicitacaoMelhoriaService e na API
-- principal/api/assistente_ia.php (migrações 225, 230 e 236). Mesmas tabelas e o mesmo comportamento.
-- Criado em cada banco de cliente que tem as tabelas do assistente (ia_assistente_*) e das solicitações.
-- Executado por scripts/aplicar-migracao-246-assistente-procedures.php (comandos separados por "-- @@").
-- Pode ser executado mais de uma vez.

-- @@
-- ================================================================== views
CREATE OR REPLACE VIEW `vw_ia_assistente_mensagens` AS
SELECT m.codmensagem, m.codconversa, m.codusuario, m.papel, m.texto, m.transacao, m.categoria, m.confianca, m.acoes,
       m.funcionalidade_inexistente, m.necessidade, m.avaliacao, m.erro, m.criado_em,
       (SELECT s.codsolicitacao FROM solicitacoes_melhoria s WHERE s.codmensagem = m.codmensagem ORDER BY s.codsolicitacao LIMIT 1) AS codsolicitacao
  FROM ia_assistente_mensagens m

-- @@
CREATE OR REPLACE VIEW `vw_solicitacoes_melhoria` AS
SELECT s.*, u.nome AS usuario_nome
  FROM solicitacoes_melhoria s
  LEFT JOIN usuarios u ON u.codusuario = s.codusuario

-- @@
CREATE OR REPLACE VIEW `vw_solicitacoes_melhoria_historico` AS
SELECT h.codhistorico, h.codsolicitacao, h.status_anterior, h.status_novo, h.escopo, h.resposta, h.codusuario, h.criado_em, u.nome AS usuario_nome
  FROM solicitacoes_melhoria_historico h
  LEFT JOIN usuarios u ON u.codusuario = h.codusuario

-- @@
-- ================================================================== auditoria
DROP PROCEDURE IF EXISTS `sp_auditoria_registrar`

-- @@
CREATE PROCEDURE `sp_auditoria_registrar`(IN p_codusuario BIGINT, IN p_transacao VARCHAR(150), IN p_acao VARCHAR(30), IN p_entidade VARCHAR(100),
    IN p_registro VARCHAR(100), IN p_resultado VARCHAR(20), IN p_detalhes JSON)
SQL SECURITY INVOKER
BEGIN
    INSERT INTO logs_auditoria (codusuario, transacao, acao, entidade, registro_id, resultado, detalhes)
    VALUES (NULLIF(p_codusuario, 0), LEFT(p_transacao, 150), LEFT(p_acao, 30), LEFT(p_entidade, 100), LEFT(p_registro, 100), LEFT(p_resultado, 20), p_detalhes);
    SELECT LAST_INSERT_ID() AS codauditoria;
END

-- @@
-- ================================================================== assistente: configuração e uso
DROP PROCEDURE IF EXISTS `sp_ia_assistente_config_obter`

-- @@
CREATE PROCEDURE `sp_ia_assistente_config_obter`()
SQL SECURITY INVOKER
BEGIN
    SELECT chave, valor FROM ia_assistente_config;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_ia_assistente_recursos`

-- @@
-- Recursos instalados nesta empresa (a etapa 2, solicitações de melhoria, é opcional).
CREATE PROCEDURE `sp_ia_assistente_recursos`()
SQL SECURITY INVOKER
BEGIN
    SELECT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitacoes_melhoria') AS solicitacoes;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_ia_assistente_usados_hoje`

-- @@
CREATE PROCEDURE `sp_ia_assistente_usados_hoje`(IN p_codusuario BIGINT)
SQL SECURITY INVOKER
BEGIN
    SELECT COUNT(*) AS usados FROM ia_assistente_mensagens
     WHERE codusuario = p_codusuario AND papel = 'ASSISTENTE' AND erro IS NULL AND criado_em >= CURDATE();
END

-- @@
-- ================================================================== assistente: conversa
DROP PROCEDURE IF EXISTS `sp_ia_assistente_conversa_atual`

-- @@
-- Última conversa aberta do usuário nas últimas 24 horas (0 quando não há).
CREATE PROCEDURE `sp_ia_assistente_conversa_atual`(IN p_codusuario BIGINT)
SQL SECURITY INVOKER
BEGIN
    SELECT COALESCE((SELECT codconversa FROM ia_assistente_conversas
                      WHERE codusuario = p_codusuario AND encerrada = 0 AND atualizada_em >= NOW() - INTERVAL 1 DAY
                      ORDER BY atualizada_em DESC LIMIT 1), 0) AS codconversa;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_ia_assistente_conversa_garantir`

-- @@
-- Continua a conversa informada (se for do usuário e estiver aberta) ou abre uma nova. Devolve o código.
CREATE PROCEDURE `sp_ia_assistente_conversa_garantir`(IN p_codconversa BIGINT, IN p_codusuario BIGINT, IN p_idioma CHAR(2), IN p_transacao VARCHAR(100))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_cod BIGINT DEFAULT 0;
    IF COALESCE(p_codconversa, 0) > 0 THEN
        SELECT COALESCE(MAX(codconversa), 0) INTO v_cod FROM ia_assistente_conversas
         WHERE codconversa = p_codconversa AND codusuario = p_codusuario AND encerrada = 0;
    END IF;
    IF v_cod > 0 THEN
        UPDATE ia_assistente_conversas SET atualizada_em = NOW() WHERE codconversa = v_cod;
    ELSE
        INSERT INTO ia_assistente_conversas (codusuario, idioma, transacao_inicial) VALUES (p_codusuario, COALESCE(NULLIF(p_idioma, ''), 'pt'), NULLIF(p_transacao, ''));
        SET v_cod = LAST_INSERT_ID();
    END IF;
    SELECT v_cod AS codconversa;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_ia_assistente_conversa_encerrar`

-- @@
CREATE PROCEDURE `sp_ia_assistente_conversa_encerrar`(IN p_codconversa BIGINT, IN p_codusuario BIGINT)
SQL SECURITY INVOKER
BEGIN
    UPDATE ia_assistente_conversas SET encerrada = 1 WHERE codconversa = p_codconversa AND codusuario = p_codusuario;
    SELECT ROW_COUNT() AS afetados;
END

-- @@
-- ================================================================== assistente: mensagens
DROP PROCEDURE IF EXISTS `sp_ia_assistente_mensagens`

-- @@
-- Mensagens visíveis de uma conversa do usuário (sem as que falharam), com a solicitação gerada, se houver.
CREATE PROCEDURE `sp_ia_assistente_mensagens`(IN p_codconversa BIGINT, IN p_codusuario BIGINT)
SQL SECURITY INVOKER
BEGIN
    SELECT codmensagem, papel, texto, acoes, avaliacao, categoria, funcionalidade_inexistente, necessidade, criado_em, codsolicitacao
      FROM vw_ia_assistente_mensagens
     WHERE codconversa = p_codconversa AND codusuario = p_codusuario AND erro IS NULL AND texto <> ''
     ORDER BY codmensagem;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_ia_assistente_mensagem_gravar`

-- @@
-- Grava uma mensagem (USUARIO ou ASSISTENTE). p_dados (JSON, opcional): categoria, confianca, acoes (lista de códigos),
-- funcionalidade_inexistente, necessidade, modelo, tokens_entrada, tokens_saida, duracao_ms, erro.
CREATE PROCEDURE `sp_ia_assistente_mensagem_gravar`(IN p_codconversa BIGINT, IN p_codusuario BIGINT, IN p_papel VARCHAR(12),
    IN p_texto TEXT, IN p_transacao VARCHAR(100), IN p_dados JSON)
SQL SECURITY INVOKER
BEGIN
    IF p_papel NOT IN ('USUARIO', 'ASSISTENTE') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Papel da mensagem inválido';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM ia_assistente_conversas WHERE codconversa = p_codconversa AND codusuario = p_codusuario) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Conversa não encontrada';
    END IF;
    INSERT INTO ia_assistente_mensagens (codconversa, codusuario, papel, texto, transacao, categoria, confianca, acoes, funcionalidade_inexistente,
                                         necessidade, modelo, tokens_entrada, tokens_saida, duracao_ms, erro)
    VALUES (p_codconversa, p_codusuario, p_papel, COALESCE(p_texto, ''), NULLIF(p_transacao, ''),
            JSON_VALUE(p_dados, '$.categoria' RETURNING CHAR(30)),
            JSON_VALUE(p_dados, '$.confianca' RETURNING DECIMAL(5,2)),
            IF(JSON_TYPE(JSON_EXTRACT(p_dados, '$.acoes')) = 'ARRAY', JSON_EXTRACT(p_dados, '$.acoes'), NULL),
            COALESCE(JSON_VALUE(p_dados, '$.funcionalidade_inexistente' RETURNING SIGNED), 0),
            JSON_VALUE(p_dados, '$.necessidade' RETURNING CHAR(500)),
            JSON_VALUE(p_dados, '$.modelo' RETURNING CHAR(60)),
            JSON_VALUE(p_dados, '$.tokens_entrada' RETURNING SIGNED),
            JSON_VALUE(p_dados, '$.tokens_saida' RETURNING SIGNED),
            JSON_VALUE(p_dados, '$.duracao_ms' RETURNING SIGNED),
            JSON_VALUE(p_dados, '$.erro' RETURNING CHAR(500)));
    SELECT LAST_INSERT_ID() AS codmensagem;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_ia_assistente_mensagem_sem_resposta`

-- @@
-- A pergunta que ficou sem resposta (falha da IA) não volta na conversa nem vai como histórico.
CREATE PROCEDURE `sp_ia_assistente_mensagem_sem_resposta`(IN p_codmensagem BIGINT, IN p_codusuario BIGINT)
SQL SECURITY INVOKER
BEGIN
    UPDATE ia_assistente_mensagens SET erro = 'SEM_RESPOSTA' WHERE codmensagem = p_codmensagem AND codusuario = p_codusuario;
    SELECT ROW_COUNT() AS afetados;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_ia_assistente_mensagem_classificar`

-- @@
-- A classificação da resposta vale também para a pergunta (é ela que as próximas etapas agrupam).
CREATE PROCEDURE `sp_ia_assistente_mensagem_classificar`(IN p_codmensagem BIGINT, IN p_codusuario BIGINT, IN p_categoria VARCHAR(30),
    IN p_inexistente TINYINT, IN p_necessidade VARCHAR(500))
SQL SECURITY INVOKER
BEGIN
    UPDATE ia_assistente_mensagens SET categoria = p_categoria, funcionalidade_inexistente = COALESCE(p_inexistente, 0), necessidade = p_necessidade
     WHERE codmensagem = p_codmensagem AND codusuario = p_codusuario;
    SELECT ROW_COUNT() AS afetados;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_ia_assistente_avaliar`

-- @@
CREATE PROCEDURE `sp_ia_assistente_avaliar`(IN p_codmensagem BIGINT, IN p_codusuario BIGINT, IN p_nota TINYINT)
SQL SECURITY INVOKER
BEGIN
    UPDATE ia_assistente_mensagens SET avaliacao = IF(p_nota IN (1, -1), p_nota, NULL)
     WHERE codmensagem = p_codmensagem AND codusuario = p_codusuario AND papel = 'ASSISTENTE';
    SELECT ROW_COUNT() AS afetados;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_ia_assistente_mensagem_origem`

-- @@
-- Resposta do assistente do próprio usuário, com a pergunta que a originou (para registrar a solicitação).
CREATE PROCEDURE `sp_ia_assistente_mensagem_origem`(IN p_codmensagem BIGINT, IN p_codusuario BIGINT)
SQL SECURITY INVOKER
BEGIN
    SELECT a.codconversa, a.categoria, a.necessidade, a.transacao, a.confianca,
           (SELECT p.texto FROM ia_assistente_mensagens p WHERE p.codconversa = a.codconversa AND p.papel = 'USUARIO' AND p.codmensagem < a.codmensagem
             ORDER BY p.codmensagem DESC LIMIT 1) AS pergunta
      FROM ia_assistente_mensagens a
     WHERE a.codmensagem = p_codmensagem AND a.codusuario = p_codusuario AND a.papel = 'ASSISTENTE' AND a.erro IS NULL;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_ia_assistente_sinais`

-- @@
-- Gestão de solicitações › Sinais do assistente: respostas que não ajudaram, dificuldades, erros e funcionalidades inexistentes.
CREATE PROCEDURE `sp_ia_assistente_sinais`(IN p_dias INT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_dias INT DEFAULT LEAST(GREATEST(COALESCE(p_dias, 30), 1), 365);
    SELECT a.codmensagem, a.categoria, a.avaliacao, a.funcionalidade_inexistente, a.necessidade, a.transacao, a.criado_em, u.nome AS usuario_nome,
           (SELECT p.texto FROM ia_assistente_mensagens p WHERE p.codconversa = a.codconversa AND p.papel = 'USUARIO' AND p.codmensagem < a.codmensagem
             ORDER BY p.codmensagem DESC LIMIT 1) AS pergunta,
           a.codsolicitacao
      FROM vw_ia_assistente_mensagens a
      LEFT JOIN usuarios u ON u.codusuario = a.codusuario
     WHERE a.papel = 'ASSISTENTE' AND a.erro IS NULL AND a.criado_em >= NOW() - INTERVAL v_dias DAY
       AND (a.avaliacao = -1 OR a.categoria IN ('DIFICULDADE_USO', 'ERRO') OR a.funcionalidade_inexistente = 1)
     ORDER BY a.criado_em DESC
     LIMIT 300;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_ia_assistente_sinais_resumo`

-- @@
CREATE PROCEDURE `sp_ia_assistente_sinais_resumo`(IN p_dias INT)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_dias INT DEFAULT LEAST(GREATEST(COALESCE(p_dias, 30), 1), 365);
    SELECT COUNT(*) AS total, COALESCE(SUM(avaliacao = 1), 0) AS uteis, COALESCE(SUM(avaliacao = -1), 0) AS nao_uteis, COUNT(DISTINCT codusuario) AS usuarios
      FROM ia_assistente_mensagens
     WHERE papel = 'ASSISTENTE' AND erro IS NULL AND criado_em >= NOW() - INTERVAL v_dias DAY;
END

-- @@
-- ================================================================== solicitações de melhoria
DROP PROCEDURE IF EXISTS `sp_solicitacao_melhoria_registrar`

-- @@
-- Grava a solicitação confirmada e o primeiro registro do histórico (NOVA, CLIENTE), numa transação.
CREATE PROCEDURE `sp_solicitacao_melhoria_registrar`(IN p_codusuario BIGINT, IN p_titulo VARCHAR(200), IN p_descricao TEXT, IN p_descricao_original TEXT,
    IN p_beneficio VARCHAR(500), IN p_modulo VARCHAR(150), IN p_transacao VARCHAR(100), IN p_categoria VARCHAR(30), IN p_prioridade VARCHAR(10),
    IN p_origem VARCHAR(12), IN p_codmensagem BIGINT, IN p_confianca DECIMAL(5,2), IN p_versao VARCHAR(20))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_cod BIGINT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF TRIM(COALESCE(p_titulo, '')) = '' OR TRIM(COALESCE(p_descricao, '')) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o título e a descrição da solicitação.';
    END IF;
    IF COALESCE(p_codmensagem, 0) > 0 AND EXISTS (SELECT 1 FROM solicitacoes_melhoria WHERE codmensagem = p_codmensagem) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta sugestão já foi registrada.';
    END IF;
    START TRANSACTION;
    INSERT INTO solicitacoes_melhoria (codusuario, titulo, descricao, descricao_original, beneficio, modulo, transacao, categoria, prioridade_usuario,
                                       origem, codmensagem, confianca_ia, versao_aplicacao)
    VALUES (p_codusuario, p_titulo, p_descricao, NULLIF(p_descricao_original, ''), NULLIF(p_beneficio, ''), NULLIF(p_modulo, ''), NULLIF(p_transacao, ''),
            p_categoria, p_prioridade, IF(p_origem = 'ASSISTENTE', 'ASSISTENTE', 'TELA'), NULLIF(p_codmensagem, 0), p_confianca, p_versao);
    SET v_cod = LAST_INSERT_ID();
    INSERT INTO solicitacoes_melhoria_historico (codsolicitacao, status_anterior, status_novo, escopo, codusuario)
    VALUES (v_cod, NULL, 'NOVA', 'CLIENTE', p_codusuario);
    COMMIT;
    SELECT v_cod AS codsolicitacao;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_solicitacoes_melhoria_minhas`

-- @@
CREATE PROCEDURE `sp_solicitacoes_melhoria_minhas`(IN p_codusuario BIGINT)
SQL SECURITY INVOKER
BEGIN
    SELECT * FROM vw_solicitacoes_melhoria WHERE codusuario = p_codusuario ORDER BY criado_em DESC LIMIT 200;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_solicitacoes_melhoria_listar`

-- @@
-- Gestão: p_status = '' (todas), 'ABERTAS' ou uma situação; p_categoria = '' ou uma categoria; p_busca em título,
-- descrição, módulo, nome de quem pediu ou número.
CREATE PROCEDURE `sp_solicitacoes_melhoria_listar`(IN p_status VARCHAR(20), IN p_categoria VARCHAR(30), IN p_busca VARCHAR(200))
SQL SECURITY INVOKER
BEGIN
    DECLARE v_like VARCHAR(210) DEFAULT CONCAT('%', COALESCE(p_busca, ''), '%');
    SELECT * FROM vw_solicitacoes_melhoria s
     WHERE (COALESCE(p_status, '') = ''
            OR (p_status = 'ABERTAS' AND s.status IN ('NOVA', 'EM_ANALISE', 'PLANEJADA', 'EM_DESENVOLVIMENTO'))
            OR s.status = p_status)
       AND (COALESCE(p_categoria, '') = '' OR s.categoria = p_categoria)
       AND (COALESCE(p_busca, '') = ''
            OR s.titulo LIKE v_like OR s.descricao LIKE v_like OR s.modulo LIKE v_like OR s.usuario_nome LIKE v_like
            OR (p_busca REGEXP '^[0-9]+$' AND s.codsolicitacao = CAST(p_busca AS UNSIGNED)))
     ORDER BY FIELD(s.status, 'NOVA', 'EM_ANALISE', 'PLANEJADA', 'EM_DESENVOLVIMENTO', 'ENTREGUE', 'RECUSADA', 'DUPLICADA'), s.criado_em DESC
     LIMIT 500;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_solicitacoes_melhoria_totais`

-- @@
CREATE PROCEDURE `sp_solicitacoes_melhoria_totais`()
SQL SECURITY INVOKER
BEGIN
    SELECT status, COUNT(*) AS n FROM solicitacoes_melhoria GROUP BY status;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_solicitacao_melhoria_obter`

-- @@
CREATE PROCEDURE `sp_solicitacao_melhoria_obter`(IN p_codsolicitacao BIGINT)
SQL SECURITY INVOKER
BEGIN
    SELECT * FROM vw_solicitacoes_melhoria WHERE codsolicitacao = p_codsolicitacao;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_solicitacao_melhoria_historico`

-- @@
CREATE PROCEDURE `sp_solicitacao_melhoria_historico`(IN p_codsolicitacao BIGINT)
SQL SECURITY INVOKER
BEGIN
    SELECT status_anterior, status_novo, escopo, resposta, criado_em, usuario_nome
      FROM vw_solicitacoes_melhoria_historico WHERE codsolicitacao = p_codsolicitacao ORDER BY codhistorico;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_solicitacao_melhoria_da_mensagem`

-- @@
CREATE PROCEDURE `sp_solicitacao_melhoria_da_mensagem`(IN p_codmensagem BIGINT)
SQL SECURITY INVOKER
BEGIN
    SELECT COALESCE((SELECT codsolicitacao FROM solicitacoes_melhoria WHERE codmensagem = p_codmensagem ORDER BY codsolicitacao LIMIT 1), 0) AS codsolicitacao;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_solicitacao_melhoria_atualizar`

-- @@
-- Gestão: muda situação/escopo/resposta e grava o histórico, numa transação. Valida a solicitação original quando DUPLICADA.
-- p_status_anterior protege contra duas pessoas alterando ao mesmo tempo (nada muda se a situação já for outra).
CREATE PROCEDURE `sp_solicitacao_melhoria_atualizar`(IN p_codsolicitacao BIGINT, IN p_status_anterior VARCHAR(20), IN p_status VARCHAR(20), IN p_escopo VARCHAR(10),
    IN p_original BIGINT, IN p_resposta TEXT, IN p_codusuario BIGINT)
SQL SECURITY INVOKER
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF p_status = 'DUPLICADA' AND NOT EXISTS (SELECT 1 FROM solicitacoes_melhoria WHERE codsolicitacao = p_original AND codsolicitacao <> p_codsolicitacao) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o número da solicitação que já trata deste assunto.';
    END IF;
    START TRANSACTION;
    UPDATE solicitacoes_melhoria
       SET status = p_status, escopo = IF(p_escopo = 'PRODUTO', 'PRODUTO', 'CLIENTE'), codsolicitacao_original = IF(p_status = 'DUPLICADA', p_original, NULL),
           resposta = COALESCE(NULLIF(p_resposta, ''), resposta), codusuario_atualizacao = p_codusuario
     WHERE codsolicitacao = p_codsolicitacao AND status = p_status_anterior;
    IF ROW_COUNT() = 0 AND NOT EXISTS (SELECT 1 FROM solicitacoes_melhoria WHERE codsolicitacao = p_codsolicitacao AND status = p_status) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A solicitação foi alterada por outra pessoa. Recarregue e tente novamente.';
    END IF;
    INSERT INTO solicitacoes_melhoria_historico (codsolicitacao, status_anterior, status_novo, escopo, resposta, codusuario)
    VALUES (p_codsolicitacao, p_status_anterior, p_status, IF(p_escopo = 'PRODUTO', 'PRODUTO', 'CLIENTE'), NULLIF(p_resposta, ''), p_codusuario);
    COMMIT;
    SELECT p_codsolicitacao AS codsolicitacao;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_alerta_registrar`

-- @@
-- Alerta para um usuário (sino do portal).
CREATE PROCEDURE `sp_alerta_registrar`(IN p_codusuario BIGINT, IN p_titulo VARCHAR(180), IN p_mensagem TEXT, IN p_link VARCHAR(500))
SQL SECURITY INVOKER
BEGIN
    INSERT INTO alertas (codusuario, titulo, mensagem, link, lido) VALUES (p_codusuario, LEFT(p_titulo, 180), p_mensagem, NULLIF(p_link, ''), 0);
    SELECT LAST_INSERT_ID() AS codalerta;
END
