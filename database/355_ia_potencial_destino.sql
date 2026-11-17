-- Migração 355 — Fase 4 da sugestão do Ricardo (docs/Ricardo_sugestao1.txt): aprovar o potencial da IA escolhendo o destino.
--   LICENCA : vincular o requisito a uma ou várias licenças JÁ cadastradas (ex.: várias "Licença Ambiental") ou criar uma nova,
--             já com o tipo documental.
--   CONTROLE: vincular a um documento de controle JÁ cadastrado (o mesmo procedimento pode atender vários potenciais) ou criar um novo.
-- Todo destino fica registrado em ml_potenciais_destinos (quem, quando, novo ou existente). No controle, o vínculo também vai para
-- content_document_links com origem IA_CONFIRMADA (camada de vínculos documentais, migração 224/225).
-- O runner (scripts/aplicar-migracao-355-ia-potencial-destino.php) separa os blocos por "-- @@" e troca os marcadores:
--   {TIPODOC_COL}/{TIPODOC_VAL}: coluna CODTIPODOCUMENTO de li_licencas (migração 226) — vazio se não existir;
--   blocos entre "-- CONTENT_INI" e "-- CONTENT_FIM": removidos se o banco não tem content_documents/content_document_links.
-- @@
CREATE TABLE IF NOT EXISTS ml_potenciais_destinos (
    codpotencialdestino BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codpotencial BIGINT UNSIGNED NOT NULL,
    tipo_destino VARCHAR(30) NOT NULL COMMENT 'LICENCA ou CONTROLE',
    codregistro BIGINT UNSIGNED NOT NULL COMMENT 'CODLICENCA ou CODDOCUMENTO',
    modo VARCHAR(20) NOT NULL COMMENT 'NOVO (criado na aprovação) ou EXISTENTE (já cadastrado)',
    codlegislacao BIGINT UNSIGNED NOT NULL DEFAULT 0,
    codunidade BIGINT UNSIGNED NOT NULL DEFAULT 0,
    vinculo_criado TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = o requisito já estava vinculado a esse registro',
    codusuario BIGINT UNSIGNED NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (codpotencialdestino),
    UNIQUE KEY uk_ml_potenciais_destinos (codpotencial, tipo_destino, codregistro),
    KEY ix_ml_potenciais_destinos_registro (tipo_destino, codregistro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
DROP PROCEDURE IF EXISTS sp_ia_potencial_aprovar_destino
-- @@
CREATE PROCEDURE sp_ia_potencial_aprovar_destino(
    IN p_codpotencial BIGINT,
    IN p_codusuario BIGINT,
    IN p_modo VARCHAR(20),
    IN p_ids TEXT,
    IN p_codtipodocumento BIGINT
)
SQL SECURITY DEFINER
BEGIN
    DECLARE v_tipo VARCHAR(30) DEFAULT '';
    DECLARE v_status VARCHAR(20) DEFAULT '';
    DECLARE v_codusuario_origem BIGINT DEFAULT 0;
    DECLARE v_codlegislacao BIGINT DEFAULT 0;
    DECLARE v_codunidade BIGINT DEFAULT 0;
    DECLARE v_titulo TEXT;
    DECLARE v_descricao TEXT;
    DECLARE v_justificativa TEXT;
    DECLARE v_contexto JSON;
    DECLARE v_resto TEXT DEFAULT '';
    DECLARE v_item VARCHAR(30) DEFAULT '';
    DECLARE v_id BIGINT DEFAULT 0;
    DECLARE v_primeiro BIGINT DEFAULT 0;
    DECLARE v_qtd INT DEFAULT 0;
    DECLARE v_existe INT DEFAULT 0;
    DECLARE v_novo_vinculo TINYINT DEFAULT 1;
    DECLARE v_content BIGINT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET p_modo = UPPER(TRIM(COALESCE(p_modo, '')));
    IF p_modo NOT IN ('NOVO', 'EXISTENTE') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha criar um novo registro ou vincular a um existente.';
    END IF;

    SELECT p.tipo_potencial, p.status, p.titulo, p.descricao, p.justificativa_ia, s.codusuario, s.contexto_json
      INTO v_tipo, v_status, v_titulo, v_descricao, v_justificativa, v_codusuario_origem, v_contexto
      FROM ml_potenciais p
      INNER JOIN ml_potenciais_solicitacoes s ON s.codsolicitacao = p.codsolicitacao
     WHERE p.codpotencial = p_codpotencial
     LIMIT 1;

    SET v_tipo = UPPER(COALESCE(v_tipo, ''));
    IF v_tipo NOT IN ('LICENCA', 'CONTROLE') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A escolha do destino vale para potenciais de licenca e de documento de controle.';
    END IF;
    IF COALESCE(v_status, '') <> 'SUGERIDO' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O potencial nao esta disponivel para validacao.';
    END IF;

    SET v_codlegislacao = CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_contexto, '$.CODLEGISLACAO')), '0') AS UNSIGNED);
    SET v_codunidade = CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_contexto, '$.CODUNIDADE')), '0') AS UNSIGNED);
    IF v_codlegislacao = 0 OR v_codunidade = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nao foi possivel aprovar: requisito legal ou unidade nao identificados.';
    END IF;

    START TRANSACTION;
    UPDATE ml_potenciais
       SET status = 'APROVADO', validado_em = NOW(), validado_por = p_codusuario
     WHERE codpotencial = p_codpotencial AND status = 'SUGERIDO';
    IF ROW_COUNT() = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O potencial nao esta disponivel para validacao.';
    END IF;

    IF p_modo = 'NOVO' THEN
        IF v_tipo = 'LICENCA' THEN
            INSERT INTO li_licencas (DSCLICENCA, codunidade, codusuario, observacao{TIPODOC_COL})
            VALUES (LEFT(COALESCE(v_titulo, 'Licenca aprovada pela IA'), 5000), v_codunidade, NULLIF(v_codusuario_origem, 0),
                    LEFT(CONCAT_WS('\n\n', NULLIF(v_descricao, ''), NULLIF(v_justificativa, '')), 500){TIPODOC_VAL});
            SET v_id = LAST_INSERT_ID();
            INSERT INTO legislacaoxlicencas (CODLEGISLACAO, CODLICENCA, codunidade) VALUES (v_codlegislacao, v_id, v_codunidade);
        ELSE
            INSERT INTO documentos (CODIGO, TITULO, ARQUIVO)
            VALUES (CONCAT('IA-', p_codpotencial), LEFT(COALESCE(v_titulo, 'Documento aprovado pela IA'), 500), '');
            SET v_id = LAST_INSERT_ID();
            INSERT INTO legislacaoxdocumentos (CODDOCUMENTO, CODLEGISLACAO, CODUNIDADE) VALUES (v_id, v_codlegislacao, v_codunidade);
        END IF;
        SET v_primeiro = v_id;
        SET v_qtd = 1;
        INSERT INTO ml_potenciais_destinos (codpotencial, tipo_destino, codregistro, modo, codlegislacao, codunidade, vinculo_criado, codusuario)
        VALUES (p_codpotencial, v_tipo, v_id, 'NOVO', v_codlegislacao, v_codunidade, 1, p_codusuario);
    ELSE
        -- Lista de códigos separados por vírgula (ex.: "12,15,40"); repetidos e vazios são ignorados.
        SET v_resto = CONCAT(REPLACE(REPLACE(COALESCE(p_ids, ''), ' ', ''), ';', ','), ',');
        WHILE v_resto <> '' DO
            SET v_item = SUBSTRING_INDEX(v_resto, ',', 1);
            SET v_resto = SUBSTRING(v_resto, CHAR_LENGTH(v_item) + 2);
            IF v_item REGEXP '^[0-9]+$' THEN
                SET v_id = CAST(v_item AS UNSIGNED);
                IF v_id > 0 AND NOT EXISTS (SELECT 1 FROM ml_potenciais_destinos WHERE codpotencial = p_codpotencial AND tipo_destino = v_tipo AND codregistro = v_id) THEN
                    IF v_tipo = 'LICENCA' THEN
                        SELECT COUNT(*) INTO v_existe FROM li_licencas WHERE CODLICENCA = v_id;
                        IF v_existe = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Licenca escolhida nao encontrada.'; END IF;
                        SELECT COUNT(*) INTO v_existe FROM legislacaoxlicencas WHERE CODLEGISLACAO = v_codlegislacao AND CODLICENCA = v_id AND codunidade = v_codunidade;
                        SET v_novo_vinculo = IF(v_existe = 0, 1, 0);
                        IF v_existe = 0 THEN
                            INSERT INTO legislacaoxlicencas (CODLEGISLACAO, CODLICENCA, codunidade) VALUES (v_codlegislacao, v_id, v_codunidade);
                        END IF;
                    ELSE
                        SELECT COUNT(*) INTO v_existe FROM documentos WHERE CODDOCUMENTO = v_id;
                        IF v_existe = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Documento de controle escolhido nao encontrado.'; END IF;
                        SELECT COUNT(*) INTO v_existe FROM legislacaoxdocumentos WHERE CODDOCUMENTO = v_id AND CODLEGISLACAO = v_codlegislacao AND CODUNIDADE = v_codunidade;
                        SET v_novo_vinculo = IF(v_existe = 0, 1, 0);
                        IF v_existe = 0 THEN
                            INSERT INTO legislacaoxdocumentos (CODDOCUMENTO, CODLEGISLACAO, CODUNIDADE) VALUES (v_id, v_codlegislacao, v_codunidade);
                        END IF;
                    END IF;
                    INSERT INTO ml_potenciais_destinos (codpotencial, tipo_destino, codregistro, modo, codlegislacao, codunidade, vinculo_criado, codusuario)
                    VALUES (p_codpotencial, v_tipo, v_id, 'EXISTENTE', v_codlegislacao, v_codunidade, v_novo_vinculo, p_codusuario);
                    IF v_primeiro = 0 THEN SET v_primeiro = v_id; END IF;
                    SET v_qtd = v_qtd + 1;
                END IF;
            END IF;
        END WHILE;
        IF v_qtd = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Escolha pelo menos um registro existente para vincular.';
        END IF;
    END IF;

    -- CONTENT_INI
    -- Controle: documento lógico (content_documents) e vínculo com o requisito, origem IA_CONFIRMADA (mesma chave da migração 225).
    IF v_tipo = 'CONTROLE' THEN
        INSERT INTO content_documents (chave_idempotencia, codigo, titulo, descricao, tipo_documento, codusuario_criador, codunidade, origem_entidade, origem_id, status)
        SELECT CONCAT('CONTROLE_DOCUMENTO_', d.CODDOCUMENTO), CONCAT('CONTROLE_DOCUMENTO_', d.CODDOCUMENTO),
               LEFT(COALESCE(NULLIF(d.TITULO, ''), CONCAT('Documento de controle ', d.CODDOCUMENTO)), 500), NULLIF(d.ARQUIVO, ''), 'CONTROLE',
               p_codusuario, v_codunidade, 'documentos', CAST(d.CODDOCUMENTO AS CHAR), 'RASCUNHO'
          FROM documentos d
          JOIN ml_potenciais_destinos x ON x.codpotencial = p_codpotencial AND x.tipo_destino = 'CONTROLE' AND x.codregistro = d.CODDOCUMENTO
         WHERE NOT EXISTS (SELECT 1 FROM content_documents c WHERE c.chave_idempotencia = CONCAT('CONTROLE_DOCUMENTO_', d.CODDOCUMENTO));
        INSERT INTO content_document_links (coddocumento, tipo_entidade, entidade_id, codrequisitolegal, codunidade, papel, origem_vinculo, status, codusuario, observacao)
        SELECT c.coddocumento, 'CONTROLE_REQUISITO', x.codregistro, v_codlegislacao, v_codunidade, 'EVIDENCIA', 'IA_CONFIRMADA', 'ATIVO', p_codusuario,
               CONCAT('Potencial IA ', p_codpotencial, ' aprovado (', x.modo, ')')
          FROM ml_potenciais_destinos x
          JOIN content_documents c ON c.chave_idempotencia = CONCAT('CONTROLE_DOCUMENTO_', x.codregistro)
         WHERE x.codpotencial = p_codpotencial AND x.tipo_destino = 'CONTROLE'
           AND NOT EXISTS (SELECT 1 FROM content_document_links l
                            WHERE l.coddocumento = c.coddocumento AND l.tipo_entidade = 'CONTROLE_REQUISITO' AND l.entidade_id = x.codregistro
                              AND l.codrequisitolegal = v_codlegislacao AND l.codunidade = v_codunidade AND l.papel = 'EVIDENCIA');
    END IF;
    -- CONTENT_FIM

    UPDATE ml_potenciais SET codregistro_destino = v_primeiro WHERE codpotencial = p_codpotencial;
    COMMIT;

    SELECT p.codpotencial, p.status, p.codregistro_destino, v_qtd AS qtd_destinos
      FROM ml_potenciais p WHERE p.codpotencial = p_codpotencial;
END
-- @@
CREATE OR REPLACE VIEW vw_ml_potenciais_destinos AS
SELECT x.codpotencialdestino, x.codpotencial, p.codsolicitacao, x.tipo_destino, x.codregistro, x.modo, x.codlegislacao, x.codunidade,
       x.vinculo_criado, x.codusuario, x.criado_em,
       CASE WHEN x.tipo_destino = 'LICENCA' THEN l.DSCLICENCA ELSE CONCAT_WS(' - ', NULLIF(d.CODIGO, ''), d.TITULO) END AS descricao_registro
  FROM ml_potenciais_destinos x
  JOIN ml_potenciais p ON p.codpotencial = x.codpotencial
  LEFT JOIN li_licencas l ON x.tipo_destino = 'LICENCA' AND l.CODLICENCA = x.codregistro
  LEFT JOIN documentos d ON x.tipo_destino = 'CONTROLE' AND d.CODDOCUMENTO = x.codregistro
