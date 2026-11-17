-- GreenSolutions 4.0.1 — Textos e idiomas da interface (catálogo universal).
-- Executar no banco guniversal_dbp pelo script scripts/aplicar-migracao-211-idiomas.php.
--
-- Os rótulos, dicas e mensagens das telas ficam aqui, em PT/EN/ES, e valem para todas as empresas.
--   idioma_textos ............. uma linha por texto (chave única, grupo, tipo, onde aparece)
--   idioma_traducoes_rascunho . o que o ADMINISTRADOR está editando (não aparece para os usuários)
--   idioma_traducoes .......... o texto publicado, lido pelas telas
--   idioma_publicacoes(+itens)  histórico de publicações, com o texto anterior e o novo
-- Leitura somente pelas views vw_idioma_*; gravação somente pelas procedures sp_idioma_*.
-- Idempotente: pode ser reaplicado sem perder textos já editados.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS idiomas (
    codidioma CHAR(2) NOT NULL,
    nome VARCHAR(40) NOT NULL,
    ordem TINYINT UNSIGNED NOT NULL DEFAULT 1,
    padrao TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (codidioma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO idiomas (codidioma, nome, ordem, padrao, ativo) VALUES
    ('pt', 'Português', 1, 1, 1), ('en', 'English', 2, 0, 1), ('es', 'Español', 3, 0, 1)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), ordem = VALUES(ordem);

-- Grupos: COMUM (vale para todas as telas) e um grupo por transação.
CREATE TABLE IF NOT EXISTS idioma_grupos (
    codgrupo VARCHAR(60) NOT NULL,
    nome_pt VARCHAR(120) NOT NULL,
    nome_en VARCHAR(120) NULL,
    nome_es VARCHAR(120) NULL,
    transacao VARCHAR(100) NULL,
    ordem INT NOT NULL DEFAULT 100,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (codgrupo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS idioma_textos (
    codtexto BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    chave VARCHAR(150) NOT NULL,
    codgrupo VARCHAR(60) NOT NULL,
    tipo VARCHAR(20) NOT NULL DEFAULT 'ROTULO',
    descricao VARCHAR(300) NULL,
    ordem INT NOT NULL DEFAULT 100,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (codtexto),
    UNIQUE KEY uk_idioma_textos_chave (chave),
    KEY ix_idioma_textos_grupo (codgrupo, ordem),
    CONSTRAINT fk_idioma_textos_grupo FOREIGN KEY (codgrupo) REFERENCES idioma_grupos (codgrupo),
    CONSTRAINT ck_idioma_textos_tipo CHECK (tipo IN ('TITULO','COLUNA','BOTAO','DICA','MENSAGEM','ROTULO','MENU'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Os textos usam collation binária: "Proxima" e "Próxima" são textos diferentes.
CREATE TABLE IF NOT EXISTS idioma_traducoes (
    codtexto BIGINT UNSIGNED NOT NULL,
    codidioma CHAR(2) NOT NULL,
    texto VARCHAR(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
    codpublicacao BIGINT UNSIGNED NULL,
    publicado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (codtexto, codidioma),
    CONSTRAINT fk_idioma_traducoes_texto FOREIGN KEY (codtexto) REFERENCES idioma_textos (codtexto),
    CONSTRAINT fk_idioma_traducoes_idioma FOREIGN KEY (codidioma) REFERENCES idiomas (codidioma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS idioma_traducoes_rascunho (
    codtexto BIGINT UNSIGNED NOT NULL,
    codidioma CHAR(2) NOT NULL,
    texto VARCHAR(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
    revisao INT UNSIGNED NOT NULL DEFAULT 1,
    alterado_por VARCHAR(150) NULL,
    alterado_por_codusuario BIGINT UNSIGNED NULL,
    alterado_banco VARCHAR(64) NULL,
    alterado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (codtexto, codidioma),
    CONSTRAINT fk_idioma_rascunho_texto FOREIGN KEY (codtexto) REFERENCES idioma_textos (codtexto),
    CONSTRAINT fk_idioma_rascunho_idioma FOREIGN KEY (codidioma) REFERENCES idiomas (codidioma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS idioma_publicacoes (
    codpublicacao BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    numero INT UNSIGNED NOT NULL,
    origem VARCHAR(20) NOT NULL DEFAULT 'PUBLICACAO',
    observacao VARCHAR(500) NULL,
    qtd_alteracoes INT UNSIGNED NOT NULL DEFAULT 0,
    publicado_por VARCHAR(150) NULL,
    publicado_por_codusuario BIGINT UNSIGNED NULL,
    publicado_banco VARCHAR(64) NULL,
    publicado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (codpublicacao),
    UNIQUE KEY uk_idioma_publicacoes_numero (numero),
    CONSTRAINT ck_idioma_publicacoes_origem CHECK (origem IN ('CARGA_INICIAL','PUBLICACAO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS idioma_publicacoes_itens (
    codpublicacao BIGINT UNSIGNED NOT NULL,
    codtexto BIGINT UNSIGNED NOT NULL,
    codidioma CHAR(2) NOT NULL,
    texto_anterior VARCHAR(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    texto_novo VARCHAR(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    PRIMARY KEY (codpublicacao, codtexto, codidioma),
    KEY ix_idioma_publicacoes_itens_texto (codtexto),
    CONSTRAINT fk_idioma_pub_itens_pub FOREIGN KEY (codpublicacao) REFERENCES idioma_publicacoes (codpublicacao),
    CONSTRAINT fk_idioma_pub_itens_texto FOREIGN KEY (codtexto) REFERENCES idioma_textos (codtexto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Textos publicados (lidos pelas telas).
CREATE OR REPLACE VIEW vw_idioma_textos_publicados AS
SELECT t.chave, t.codgrupo, tr.codidioma, tr.texto
  FROM idioma_textos t
  JOIN idioma_traducoes tr ON tr.codtexto = t.codtexto
  JOIN idiomas i ON i.codidioma = tr.codidioma AND i.ativo = 1
 WHERE t.ativo = 1;

-- Textos do rascunho (pré-visualização do ADMINISTRADOR).
CREATE OR REPLACE VIEW vw_idioma_textos_rascunho AS
SELECT t.chave, t.codgrupo, r.codidioma, r.texto
  FROM idioma_textos t
  JOIN idioma_traducoes_rascunho r ON r.codtexto = t.codtexto
  JOIN idiomas i ON i.codidioma = r.codidioma AND i.ativo = 1
 WHERE t.ativo = 1;

-- Uma linha por texto, com rascunho e publicado lado a lado (tela de administração).
CREATE OR REPLACE VIEW vw_idioma_textos_admin AS
SELECT t.codtexto, t.chave, t.codgrupo, g.nome_pt AS grupo_pt, g.nome_en AS grupo_en, g.nome_es AS grupo_es,
       g.ordem AS grupo_ordem, t.tipo, t.descricao, t.ordem,
       MAX(CASE WHEN r.codidioma = 'pt' THEN r.texto END) AS pt,
       MAX(CASE WHEN r.codidioma = 'en' THEN r.texto END) AS en,
       MAX(CASE WHEN r.codidioma = 'es' THEN r.texto END) AS es,
       MAX(CASE WHEN r.codidioma = 'pt' THEN r.revisao END) AS pt_revisao,
       MAX(CASE WHEN r.codidioma = 'en' THEN r.revisao END) AS en_revisao,
       MAX(CASE WHEN r.codidioma = 'es' THEN r.revisao END) AS es_revisao,
       MAX(CASE WHEN p.codidioma = 'pt' THEN p.texto END) AS pt_publicado,
       MAX(CASE WHEN p.codidioma = 'en' THEN p.texto END) AS en_publicado,
       MAX(CASE WHEN p.codidioma = 'es' THEN p.texto END) AS es_publicado,
       MAX(r.alterado_em) AS alterado_em,
       SUBSTRING_INDEX(GROUP_CONCAT(r.alterado_por ORDER BY r.alterado_em DESC SEPARATOR '\n'), '\n', 1) AS alterado_por,
       SUM(CASE WHEN p.codtexto IS NULL OR NOT (r.texto <=> p.texto) THEN 1 ELSE 0 END) > 0 AS pendente,
       SUM(CASE WHEN TRIM(r.texto) = '' THEN 1 ELSE 0 END) > 0 AS faltando
  FROM idioma_textos t
  JOIN idioma_grupos g ON g.codgrupo = t.codgrupo
  LEFT JOIN idioma_traducoes_rascunho r ON r.codtexto = t.codtexto
  LEFT JOIN idioma_traducoes p ON p.codtexto = r.codtexto AND p.codidioma = r.codidioma
 WHERE t.ativo = 1
 GROUP BY t.codtexto, t.chave, t.codgrupo, g.nome_pt, g.nome_en, g.nome_es, g.ordem, t.tipo, t.descricao, t.ordem;

CREATE OR REPLACE VIEW vw_idioma_grupos AS
SELECT g.codgrupo, g.nome_pt, g.nome_en, g.nome_es, g.transacao, g.ordem,
       (SELECT COUNT(*) FROM idioma_textos t WHERE t.codgrupo = g.codgrupo AND t.ativo = 1) AS qtd_textos
  FROM idioma_grupos g
 WHERE g.ativo = 1;

CREATE OR REPLACE VIEW vw_idioma_publicacoes AS
SELECT p.codpublicacao, p.numero, p.origem, p.observacao, p.qtd_alteracoes,
       p.publicado_por, p.publicado_banco, p.publicado_em,
       (p.numero = (SELECT MAX(x.numero) FROM idioma_publicacoes x)) AS vigente
  FROM idioma_publicacoes p;

CREATE OR REPLACE VIEW vw_idioma_publicacoes_itens AS
SELECT i.codpublicacao, i.codtexto, t.chave, t.codgrupo, t.descricao, i.codidioma, i.texto_anterior, i.texto_novo
  FROM idioma_publicacoes_itens i
  JOIN idioma_textos t ON t.codtexto = i.codtexto;

-- Situação geral: última publicação e quantidade de traduções pendentes.
CREATE OR REPLACE VIEW vw_idioma_situacao AS
SELECT (SELECT MAX(numero) FROM idioma_publicacoes) AS numero_publicado,
       (SELECT publicado_em FROM idioma_publicacoes ORDER BY numero DESC LIMIT 1) AS publicado_em,
       (SELECT publicado_por FROM idioma_publicacoes ORDER BY numero DESC LIMIT 1) AS publicado_por,
       (SELECT COUNT(*) FROM idioma_traducoes_rascunho r
          JOIN idioma_textos t ON t.codtexto = r.codtexto AND t.ativo = 1
          LEFT JOIN idioma_traducoes p ON p.codtexto = r.codtexto AND p.codidioma = r.codidioma
         WHERE p.codtexto IS NULL OR NOT (r.texto <=> p.texto)) AS pendentes;

-- Grupo de textos (usado pela carga inicial).
DROP PROCEDURE IF EXISTS sp_idioma_registrar_grupo;
CREATE PROCEDURE sp_idioma_registrar_grupo(
    IN p_codgrupo VARCHAR(60), IN p_nome_pt VARCHAR(120), IN p_nome_en VARCHAR(120), IN p_nome_es VARCHAR(120),
    IN p_transacao VARCHAR(100), IN p_ordem INT)
BEGIN
    INSERT INTO idioma_grupos (codgrupo, nome_pt, nome_en, nome_es, transacao, ordem, ativo)
    VALUES (p_codgrupo, p_nome_pt, p_nome_en, p_nome_es, NULLIF(p_transacao, ''), p_ordem, 1)
    ON DUPLICATE KEY UPDATE nome_pt = VALUES(nome_pt), nome_en = VALUES(nome_en), nome_es = VALUES(nome_es),
        transacao = VALUES(transacao), ordem = VALUES(ordem), ativo = 1;
END;

-- Registra um texto com os valores padrão. Não sobrescreve traduções existentes:
-- só preenche o que ainda não existe (rascunho e publicado).
DROP PROCEDURE IF EXISTS sp_idioma_registrar_texto;
CREATE PROCEDURE sp_idioma_registrar_texto(
    IN p_chave VARCHAR(150), IN p_codgrupo VARCHAR(60), IN p_tipo VARCHAR(20), IN p_descricao VARCHAR(300), IN p_ordem INT,
    IN p_pt VARCHAR(1000), IN p_en VARCHAR(1000), IN p_es VARCHAR(1000), IN p_codpublicacao BIGINT UNSIGNED, IN p_usuario VARCHAR(150))
BEGIN
    DECLARE v_codtexto BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF p_chave IS NULL OR p_chave NOT REGEXP '^[a-z0-9_]+(\\.[A-Za-z0-9_]+)+$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Chave de texto inválida.';
    END IF;
    START TRANSACTION;
    INSERT INTO idioma_textos (chave, codgrupo, tipo, descricao, ordem, ativo)
    VALUES (p_chave, p_codgrupo, p_tipo, p_descricao, p_ordem, 1)
    ON DUPLICATE KEY UPDATE codgrupo = VALUES(codgrupo), tipo = VALUES(tipo), descricao = VALUES(descricao), ordem = VALUES(ordem), ativo = 1;
    SELECT codtexto INTO v_codtexto FROM idioma_textos WHERE chave = p_chave;
    INSERT IGNORE INTO idioma_traducoes_rascunho (codtexto, codidioma, texto, revisao, alterado_por)
    VALUES (v_codtexto, 'pt', COALESCE(p_pt, ''), 1, p_usuario), (v_codtexto, 'en', COALESCE(p_en, ''), 1, p_usuario), (v_codtexto, 'es', COALESCE(p_es, ''), 1, p_usuario);
    INSERT IGNORE INTO idioma_traducoes (codtexto, codidioma, texto, codpublicacao)
    VALUES (v_codtexto, 'pt', COALESCE(p_pt, ''), p_codpublicacao), (v_codtexto, 'en', COALESCE(p_en, ''), p_codpublicacao), (v_codtexto, 'es', COALESCE(p_es, ''), p_codpublicacao);
    COMMIT;
END;

-- Abre (uma única vez) a publicação nº 1 que marca a carga inicial.
DROP PROCEDURE IF EXISTS sp_idioma_carga_inicial;
CREATE PROCEDURE sp_idioma_carga_inicial(IN p_usuario VARCHAR(150), IN p_banco VARCHAR(64))
BEGIN
    INSERT INTO idioma_publicacoes (numero, origem, observacao, qtd_alteracoes, publicado_por, publicado_banco)
    SELECT 1, 'CARGA_INICIAL', 'Carga inicial com os textos que estavam no código.', 0, p_usuario, p_banco
     WHERE NOT EXISTS (SELECT 1 FROM idioma_publicacoes);
    SELECT codpublicacao FROM idioma_publicacoes WHERE numero = 1;
END;

-- Grava alterações no rascunho. p_itens: [{"codtexto":1,"idioma":"en","texto":"...","revisao":3}, ...]
-- Se alguém gravou o mesmo texto antes (revisão diferente), nada é gravado: CONFLITO_REVISAO.
DROP PROCEDURE IF EXISTS sp_idioma_salvar_rascunho;
CREATE PROCEDURE sp_idioma_salvar_rascunho(
    IN p_itens JSON, IN p_usuario VARCHAR(150), IN p_codusuario BIGINT UNSIGNED, IN p_banco VARCHAR(64))
BEGIN
    DECLARE v_total INT DEFAULT 0;
    DECLARE v_validos INT DEFAULT 0;
    DECLARE v_conflitos INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF p_itens IS NULL OR JSON_TYPE(p_itens) <> 'ARRAY' OR JSON_LENGTH(p_itens) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nenhuma alteração recebida.';
    END IF;
    START TRANSACTION;
    DROP TEMPORARY TABLE IF EXISTS tmp_idioma_itens;
    CREATE TEMPORARY TABLE tmp_idioma_itens (
        codtexto BIGINT UNSIGNED NOT NULL, codidioma CHAR(2) NOT NULL,
        texto VARCHAR(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, revisao INT UNSIGNED NOT NULL,
        PRIMARY KEY (codtexto, codidioma)) ENGINE=InnoDB;
    INSERT INTO tmp_idioma_itens (codtexto, codidioma, texto, revisao)
    SELECT j.codtexto, j.idioma, j.texto, j.revisao
      FROM JSON_TABLE(p_itens, '$[*]' COLUMNS (
            codtexto BIGINT UNSIGNED PATH '$.codtexto',
            idioma CHAR(2) PATH '$.idioma',
            texto VARCHAR(1000) PATH '$.texto',
            revisao INT UNSIGNED PATH '$.revisao')) j;
    SELECT COUNT(*) INTO v_total FROM tmp_idioma_itens;
    SELECT COUNT(*) INTO v_validos FROM tmp_idioma_itens i
      JOIN idioma_traducoes_rascunho r ON r.codtexto = i.codtexto AND r.codidioma = i.codidioma;
    IF v_validos <> v_total THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Texto ou idioma não encontrado.';
    END IF;
    IF EXISTS (SELECT 1 FROM tmp_idioma_itens WHERE codidioma = 'pt' AND TRIM(texto) = '') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O texto em português é obrigatório.';
    END IF;
    IF EXISTS (SELECT 1 FROM tmp_idioma_itens i JOIN idioma_textos t ON t.codtexto = i.codtexto WHERE t.tipo = 'MENU' AND CHAR_LENGTH(i.texto) > 150) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nomes de menu podem ter no máximo 150 caracteres.';
    END IF;
    SELECT COUNT(*) INTO v_conflitos FROM tmp_idioma_itens i
      JOIN idioma_traducoes_rascunho r ON r.codtexto = i.codtexto AND r.codidioma = i.codidioma
     WHERE r.revisao <> i.revisao;
    IF v_conflitos > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CONFLITO_REVISAO';
    END IF;
    UPDATE idioma_traducoes_rascunho r
      JOIN tmp_idioma_itens i ON i.codtexto = r.codtexto AND i.codidioma = r.codidioma
       SET r.texto = i.texto, r.revisao = r.revisao + 1, r.alterado_por = p_usuario,
           r.alterado_por_codusuario = p_codusuario, r.alterado_banco = p_banco, r.alterado_em = CURRENT_TIMESTAMP
     WHERE NOT (r.texto <=> i.texto);
    COMMIT;
    SELECT r.codtexto, r.codidioma, r.revisao FROM idioma_traducoes_rascunho r
      JOIN tmp_idioma_itens i ON i.codtexto = r.codtexto AND i.codidioma = r.codidioma;
    DROP TEMPORARY TABLE IF EXISTS tmp_idioma_itens;
END;

-- Publica tudo o que está diferente entre o rascunho e o publicado.
DROP PROCEDURE IF EXISTS sp_idioma_publicar;
CREATE PROCEDURE sp_idioma_publicar(
    IN p_observacao VARCHAR(500), IN p_usuario VARCHAR(150), IN p_codusuario BIGINT UNSIGNED, IN p_banco VARCHAR(64))
BEGIN
    DECLARE v_qtd INT DEFAULT 0;
    DECLARE v_numero INT UNSIGNED;
    DECLARE v_codpublicacao BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    SELECT COUNT(*) INTO v_qtd FROM idioma_traducoes_rascunho r
      JOIN idioma_textos t ON t.codtexto = r.codtexto AND t.ativo = 1
      LEFT JOIN idioma_traducoes p ON p.codtexto = r.codtexto AND p.codidioma = r.codidioma
     WHERE p.codtexto IS NULL OR NOT (r.texto <=> p.texto)
       FOR UPDATE;
    IF v_qtd = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SEM_ALTERACOES';
    END IF;
    IF EXISTS (SELECT 1 FROM idioma_traducoes_rascunho r JOIN idioma_textos t ON t.codtexto = r.codtexto AND t.ativo = 1
                WHERE r.codidioma = 'pt' AND TRIM(r.texto) = '') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Há textos sem a versão em português.';
    END IF;
    SELECT COALESCE(MAX(numero), 0) + 1 INTO v_numero FROM idioma_publicacoes FOR UPDATE;
    INSERT INTO idioma_publicacoes (numero, origem, observacao, qtd_alteracoes, publicado_por, publicado_por_codusuario, publicado_banco)
    VALUES (v_numero, 'PUBLICACAO', NULLIF(TRIM(p_observacao), ''), v_qtd, p_usuario, p_codusuario, p_banco);
    SET v_codpublicacao = LAST_INSERT_ID();
    INSERT INTO idioma_publicacoes_itens (codpublicacao, codtexto, codidioma, texto_anterior, texto_novo)
    SELECT v_codpublicacao, r.codtexto, r.codidioma, p.texto, r.texto
      FROM idioma_traducoes_rascunho r
      JOIN idioma_textos t ON t.codtexto = r.codtexto AND t.ativo = 1
      LEFT JOIN idioma_traducoes p ON p.codtexto = r.codtexto AND p.codidioma = r.codidioma
     WHERE p.codtexto IS NULL OR NOT (r.texto <=> p.texto);
    INSERT INTO idioma_traducoes (codtexto, codidioma, texto, codpublicacao, publicado_em)
    SELECT i.codtexto, i.codidioma, i.texto_novo, v_codpublicacao, CURRENT_TIMESTAMP
      FROM idioma_publicacoes_itens i WHERE i.codpublicacao = v_codpublicacao
    ON DUPLICATE KEY UPDATE texto = VALUES(texto), codpublicacao = VALUES(codpublicacao), publicado_em = VALUES(publicado_em);
    -- Menus: só os nomes publicados agora. EN/ES vazios ficam nulos (o portal mostra o português).
    UPDATE menus m
      JOIN idioma_textos t ON t.codgrupo = 'MENUS' AND t.chave = CONCAT('menu.', m.codigo)
      JOIN idioma_publicacoes_itens i ON i.codtexto = t.codtexto AND i.codpublicacao = v_codpublicacao AND i.codidioma = 'pt'
       SET m.label_pt = LEFT(i.texto_novo, 150), m.label = LEFT(i.texto_novo, 150);
    UPDATE menus m
      JOIN idioma_textos t ON t.codgrupo = 'MENUS' AND t.chave = CONCAT('menu.', m.codigo)
      JOIN idioma_publicacoes_itens i ON i.codtexto = t.codtexto AND i.codpublicacao = v_codpublicacao AND i.codidioma = 'en'
       SET m.label_en = LEFT(NULLIF(i.texto_novo, ''), 150);
    UPDATE menus m
      JOIN idioma_textos t ON t.codgrupo = 'MENUS' AND t.chave = CONCAT('menu.', m.codigo)
      JOIN idioma_publicacoes_itens i ON i.codtexto = t.codtexto AND i.codpublicacao = v_codpublicacao AND i.codidioma = 'es'
       SET m.label_es = LEFT(NULLIF(i.texto_novo, ''), 150);
    COMMIT;
    SELECT codpublicacao, numero, qtd_alteracoes, publicado_em FROM idioma_publicacoes WHERE codpublicacao = v_codpublicacao;
END;

-- Descarta o rascunho (volta ao texto publicado). p_codtextos: [1,2,3] ou NULL para todos.
DROP PROCEDURE IF EXISTS sp_idioma_descartar_rascunho;
CREATE PROCEDURE sp_idioma_descartar_rascunho(
    IN p_codtextos JSON, IN p_usuario VARCHAR(150), IN p_codusuario BIGINT UNSIGNED, IN p_banco VARCHAR(64))
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF p_codtextos IS NOT NULL AND JSON_TYPE(p_codtextos) <> 'ARRAY' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lista de textos inválida.';
    END IF;
    START TRANSACTION;
    UPDATE idioma_traducoes_rascunho r
      JOIN idioma_traducoes p ON p.codtexto = r.codtexto AND p.codidioma = r.codidioma
       SET r.texto = p.texto, r.revisao = r.revisao + 1, r.alterado_por = p_usuario,
           r.alterado_por_codusuario = p_codusuario, r.alterado_banco = p_banco, r.alterado_em = CURRENT_TIMESTAMP
     WHERE NOT (r.texto <=> p.texto)
       AND (p_codtextos IS NULL OR r.codtexto IN (SELECT j.codtexto FROM JSON_TABLE(p_codtextos, '$[*]' COLUMNS (codtexto BIGINT UNSIGNED PATH '$')) j));
    SELECT ROW_COUNT() AS descartados;
    COMMIT;
END;

-- Menu universal: Administração › Textos e idiomas.
INSERT INTO menus (codmenu, codmenu_pai, modulo, codigo, label, label_pt, label_en, label_es, url, nivel, ordem, icone, ativo)
SELECT COALESCE((SELECT MAX(m.codmenu) + 1 FROM menus m), 1),
       (SELECT p.codmenu FROM menus p WHERE p.codigo = 'ADMINISTRACAO' LIMIT 1),
       'ADMINISTRACAO', 'ADMINISTRACAO_IDIOMAS', 'Textos e idiomas', 'Textos e idiomas', 'Texts and languages', 'Textos e idiomas',
       '/principal/administracao_idiomas.php', 2,
       COALESCE((SELECT MAX(o.ordem) + 1 FROM menus o WHERE o.codmenu_pai = (SELECT p2.codmenu FROM menus p2 WHERE p2.codigo = 'ADMINISTRACAO' LIMIT 1)), 99),
       'bi-translate', 1
 WHERE NOT EXISTS (SELECT 1 FROM menus x WHERE x.codigo = 'ADMINISTRACAO_IDIOMAS');

UPDATE menus child JOIN menus parent ON parent.codigo = 'ADMINISTRACAO'
   SET child.codmenu_pai = parent.codmenu, child.modulo = 'ADMINISTRACAO', child.nivel = 2,
       child.label = 'Textos e idiomas', child.label_pt = 'Textos e idiomas', child.label_en = 'Texts and languages', child.label_es = 'Textos e idiomas',
       child.url = '/principal/administracao_idiomas.php', child.icone = 'bi-translate', child.ativo = 1
 WHERE child.codigo = 'ADMINISTRACAO_IDIOMAS';

INSERT INTO green_universal_migrations (codigo_migracao, versao, status, detalhes)
VALUES ('GREEN_IDIOMAS_UNIVERSAL_211', '4.0.1', 'CONCLUIDA',
        JSON_OBJECT('transacao', 'administracao_idiomas.php', 'api', 'api/administracao_idiomas.php',
                    'tabelas', JSON_ARRAY('idiomas', 'idioma_grupos', 'idioma_textos', 'idioma_traducoes', 'idioma_traducoes_rascunho', 'idioma_publicacoes', 'idioma_publicacoes_itens'),
                    'piloto', 'modulolegal_controle'))
ON DUPLICATE KEY UPDATE status = VALUES(status), detalhes = VALUES(detalhes), executado_em = CURRENT_TIMESTAMP;
