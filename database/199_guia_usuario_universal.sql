-- GreenSolutions 4.0.1 — Guia do usuário editável (catálogo universal).
-- Executar no banco guniversal_dbp pelo script scripts/aplicar-migracao-199-guia-usuario.php.
-- O guia é único para todas as empresas: rascunho (uma linha) + versões publicadas imutáveis.
-- Idempotente: pode ser reaplicado sem perda de conteúdo.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS guia_usuario_rascunho (
    codrascunho TINYINT UNSIGNED NOT NULL DEFAULT 1,
    conteudo JSON NOT NULL,
    revisao INT UNSIGNED NOT NULL DEFAULT 1,
    alterado_por VARCHAR(150) NULL,
    alterado_por_codusuario BIGINT UNSIGNED NULL,
    alterado_banco VARCHAR(64) NULL,
    alterado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (codrascunho),
    CONSTRAINT ck_guia_usuario_rascunho_unico CHECK (codrascunho = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS guia_usuario_versoes (
    codversao BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    numero INT UNSIGNED NOT NULL,
    conteudo JSON NOT NULL,
    hash_conteudo CHAR(64) NOT NULL,
    origem VARCHAR(20) NOT NULL DEFAULT 'PUBLICACAO',
    restaurada_de BIGINT UNSIGNED NULL,
    observacao VARCHAR(500) NULL,
    publicado_por VARCHAR(150) NULL,
    publicado_por_codusuario BIGINT UNSIGNED NULL,
    publicado_banco VARCHAR(64) NULL,
    publicado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (codversao),
    UNIQUE KEY uk_guia_usuario_versoes_numero (numero),
    KEY ix_guia_usuario_versoes_publicado_em (publicado_em),
    CONSTRAINT ck_guia_usuario_versoes_origem CHECK (origem IN ('CARGA_INICIAL','PUBLICACAO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Versão publicada vigente (a de maior número).
CREATE OR REPLACE VIEW vw_guia_usuario_publicado AS
SELECT v.codversao, v.numero, v.conteudo, v.hash_conteudo, v.observacao,
       v.publicado_por, v.publicado_em, v.origem
  FROM guia_usuario_versoes v
 WHERE v.numero = (SELECT MAX(x.numero) FROM guia_usuario_versoes x);

-- Histórico de versões, sem o conteúdo completo.
CREATE OR REPLACE VIEW vw_guia_usuario_versoes AS
SELECT v.codversao, v.numero, v.origem, v.restaurada_de, v.observacao,
       v.publicado_por, v.publicado_banco, v.publicado_em, v.hash_conteudo,
       JSON_LENGTH(v.conteudo, '$.modulos') AS qtd_modulos,
       (v.numero = (SELECT MAX(x.numero) FROM guia_usuario_versoes x)) AS vigente
  FROM guia_usuario_versoes v;

-- Rascunho com indicação de alterações ainda não publicadas.
CREATE OR REPLACE VIEW vw_guia_usuario_rascunho AS
SELECT r.codrascunho, r.conteudo, r.revisao, r.alterado_por, r.alterado_banco, r.alterado_em,
       p.numero AS numero_publicado, p.publicado_em, p.publicado_por,
       (p.hash_conteudo IS NULL OR p.hash_conteudo <> SHA2(CAST(r.conteudo AS CHAR), 256)) AS pendente
  FROM guia_usuario_rascunho r
  LEFT JOIN vw_guia_usuario_publicado p ON 1 = 1
 WHERE r.codrascunho = 1;

DROP PROCEDURE IF EXISTS sp_guia_usuario_carga_inicial;
CREATE PROCEDURE sp_guia_usuario_carga_inicial(
    IN p_conteudo JSON, IN p_usuario VARCHAR(150), IN p_codusuario BIGINT UNSIGNED, IN p_banco VARCHAR(64))
BEGIN
    DECLARE v_existe INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF p_conteudo IS NULL OR JSON_TYPE(p_conteudo) <> 'OBJECT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Conteúdo inicial do guia inválido.';
    END IF;
    START TRANSACTION;
    SELECT COUNT(*) INTO v_existe FROM guia_usuario_versoes FOR UPDATE;
    IF v_existe = 0 THEN
        INSERT INTO guia_usuario_versoes (numero, conteudo, hash_conteudo, origem, observacao, publicado_por, publicado_por_codusuario, publicado_banco)
        VALUES (1, p_conteudo, SHA2(CAST(p_conteudo AS CHAR), 256), 'CARGA_INICIAL', 'Carga inicial a partir do guia padrão.', p_usuario, p_codusuario, p_banco);
    END IF;
    INSERT IGNORE INTO guia_usuario_rascunho (codrascunho, conteudo, revisao, alterado_por, alterado_por_codusuario, alterado_banco)
    SELECT 1, v.conteudo, 1, p_usuario, p_codusuario, p_banco FROM vw_guia_usuario_publicado v;
    COMMIT;
    SELECT v_existe = 0 AS carregado;
END;

DROP PROCEDURE IF EXISTS sp_guia_usuario_salvar_rascunho;
CREATE PROCEDURE sp_guia_usuario_salvar_rascunho(
    IN p_conteudo JSON, IN p_revisao INT UNSIGNED, IN p_usuario VARCHAR(150), IN p_codusuario BIGINT UNSIGNED, IN p_banco VARCHAR(64))
BEGIN
    DECLARE v_revisao INT UNSIGNED DEFAULT NULL;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF p_conteudo IS NULL OR JSON_TYPE(p_conteudo) <> 'OBJECT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Conteúdo do guia inválido.';
    END IF;
    START TRANSACTION;
    SELECT revisao INTO v_revisao FROM guia_usuario_rascunho WHERE codrascunho = 1 FOR UPDATE;
    IF v_revisao IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rascunho do guia não inicializado.';
    END IF;
    IF v_revisao <> p_revisao THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CONFLITO_REVISAO';
    END IF;
    UPDATE guia_usuario_rascunho
       SET conteudo = p_conteudo, revisao = revisao + 1, alterado_por = p_usuario,
           alterado_por_codusuario = p_codusuario, alterado_banco = p_banco, alterado_em = CURRENT_TIMESTAMP
     WHERE codrascunho = 1;
    COMMIT;
    SELECT revisao FROM guia_usuario_rascunho WHERE codrascunho = 1;
END;

DROP PROCEDURE IF EXISTS sp_guia_usuario_publicar;
CREATE PROCEDURE sp_guia_usuario_publicar(
    IN p_revisao INT UNSIGNED, IN p_observacao VARCHAR(500), IN p_usuario VARCHAR(150), IN p_codusuario BIGINT UNSIGNED, IN p_banco VARCHAR(64))
BEGIN
    DECLARE v_revisao INT UNSIGNED DEFAULT NULL;
    DECLARE v_hash CHAR(64);
    DECLARE v_hash_publicado CHAR(64) DEFAULT NULL;
    DECLARE v_numero INT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    SELECT revisao, SHA2(CAST(conteudo AS CHAR), 256) INTO v_revisao, v_hash
      FROM guia_usuario_rascunho WHERE codrascunho = 1 FOR UPDATE;
    IF v_revisao IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rascunho do guia não inicializado.';
    END IF;
    IF v_revisao <> p_revisao THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CONFLITO_REVISAO';
    END IF;
    SELECT COALESCE(MAX(numero), 0) + 1 INTO v_numero FROM guia_usuario_versoes FOR UPDATE;
    SELECT hash_conteudo INTO v_hash_publicado FROM guia_usuario_versoes WHERE numero = v_numero - 1;
    IF v_hash_publicado IS NOT NULL AND v_hash_publicado = v_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SEM_ALTERACOES';
    END IF;
    INSERT INTO guia_usuario_versoes (numero, conteudo, hash_conteudo, origem, observacao, publicado_por, publicado_por_codusuario, publicado_banco)
    SELECT v_numero, r.conteudo, v_hash, 'PUBLICACAO', NULLIF(TRIM(p_observacao), ''), p_usuario, p_codusuario, p_banco
      FROM guia_usuario_rascunho r WHERE r.codrascunho = 1;
    COMMIT;
    SELECT codversao, numero, publicado_em FROM guia_usuario_versoes WHERE numero = v_numero;
END;

-- Copia uma versão publicada para o rascunho (usado em "Restaurar" e "Descartar rascunho").
DROP PROCEDURE IF EXISTS sp_guia_usuario_restaurar_rascunho;
CREATE PROCEDURE sp_guia_usuario_restaurar_rascunho(
    IN p_codversao BIGINT UNSIGNED, IN p_revisao INT UNSIGNED, IN p_usuario VARCHAR(150), IN p_codusuario BIGINT UNSIGNED, IN p_banco VARCHAR(64))
BEGIN
    DECLARE v_revisao INT UNSIGNED DEFAULT NULL;
    DECLARE v_existe INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    SELECT revisao INTO v_revisao FROM guia_usuario_rascunho WHERE codrascunho = 1 FOR UPDATE;
    IF v_revisao IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rascunho do guia não inicializado.';
    END IF;
    IF v_revisao <> p_revisao THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CONFLITO_REVISAO';
    END IF;
    SELECT COUNT(*) INTO v_existe FROM guia_usuario_versoes WHERE codversao = p_codversao;
    IF v_existe = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Versão do guia não encontrada.';
    END IF;
    UPDATE guia_usuario_rascunho r JOIN guia_usuario_versoes v ON v.codversao = p_codversao
       SET r.conteudo = v.conteudo, r.revisao = r.revisao + 1, r.alterado_por = p_usuario,
           r.alterado_por_codusuario = p_codusuario, r.alterado_banco = p_banco, r.alterado_em = CURRENT_TIMESTAMP
     WHERE r.codrascunho = 1;
    COMMIT;
    SELECT revisao FROM guia_usuario_rascunho WHERE codrascunho = 1;
END;

-- Menu universal: Administração › Guia do usuário.
INSERT INTO menus (codmenu, codmenu_pai, modulo, codigo, label, label_pt, label_en, label_es, url, nivel, ordem, icone, ativo)
SELECT COALESCE((SELECT MAX(m.codmenu) + 1 FROM menus m), 1),
       (SELECT p.codmenu FROM menus p WHERE p.codigo = 'ADMINISTRACAO' LIMIT 1),
       'ADMINISTRACAO', 'ADMINISTRACAO_GUIA_USUARIO', 'Guia do usuário', 'Guia do usuário', 'User guide', 'Guía del usuario',
       '/principal/administracao_guia_usuario.php', 2,
       COALESCE((SELECT MAX(o.ordem) + 1 FROM menus o WHERE o.codmenu_pai = (SELECT p2.codmenu FROM menus p2 WHERE p2.codigo = 'ADMINISTRACAO' LIMIT 1)), 99),
       'bi-book-half', 1
 WHERE NOT EXISTS (SELECT 1 FROM menus x WHERE x.codigo = 'ADMINISTRACAO_GUIA_USUARIO');

UPDATE menus child JOIN menus parent ON parent.codigo = 'ADMINISTRACAO'
   SET child.codmenu_pai = parent.codmenu, child.modulo = 'ADMINISTRACAO', child.nivel = 2,
       child.label = 'Guia do usuário', child.label_pt = 'Guia do usuário', child.label_en = 'User guide', child.label_es = 'Guía del usuario',
       child.url = '/principal/administracao_guia_usuario.php', child.icone = 'bi-book-half', child.ativo = 1
 WHERE child.codigo = 'ADMINISTRACAO_GUIA_USUARIO';

INSERT INTO green_universal_migrations (codigo_migracao, versao, status, detalhes)
VALUES ('GREEN_GUIA_USUARIO_UNIVERSAL_199', '4.0.1', 'CONCLUIDA',
        JSON_OBJECT('transacao', 'administracao_guia_usuario.php', 'api', 'api/administracao_guia_usuario.php',
                    'tabelas', JSON_ARRAY('guia_usuario_rascunho', 'guia_usuario_versoes'), 'versoes_imutaveis', TRUE))
ON DUPLICATE KEY UPDATE status = VALUES(status), detalhes = VALUES(detalhes), executado_em = CURRENT_TIMESTAMP;
