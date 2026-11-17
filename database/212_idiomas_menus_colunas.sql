-- GreenSolutions 4.0.1 — Textos e idiomas: menus do portal e cadastro automático das colunas dos grids.
-- Executar no guniversal_dbp pelo script scripts/aplicar-migracao-212-idiomas-menus-colunas.php (depois da 211).
--
-- Menus: grupo MENUS, uma chave por menu (menu.<CODIGO>). A tabela menus continua sendo lida pelo portal;
--   ao Publicar, os nomes alterados são copiados para menus.label_pt / label_en / label_es.
--   sp_idioma_sincronizar_menus traz menus novos e nomes alterados por outras migrações
--   (só quando aquele texto não tem alteração pendente no rascunho).
-- Colunas: sp_idioma_registrar_lote cadastra, uma única vez, os rótulos de coluna que ainda não existem
--   (chamado pelo grid quando um ADMINISTRADOR abre a transação). Nunca sobrescreve textos existentes.
-- Idempotente.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

ALTER TABLE idioma_textos DROP CHECK ck_idioma_textos_tipo;
ALTER TABLE idioma_textos ADD CONSTRAINT ck_idioma_textos_tipo CHECK (tipo IN ('TITULO','COLUNA','BOTAO','DICA','MENSAGEM','ROTULO','MENU'));

INSERT INTO idioma_grupos (codgrupo, nome_pt, nome_en, nome_es, transacao, ordem, ativo)
VALUES ('MENUS', 'Menus do portal', 'Portal menus', 'Menús del portal', NULL, 20, 1)
ON DUPLICATE KEY UPDATE nome_pt = VALUES(nome_pt), nome_en = VALUES(nome_en), nome_es = VALUES(nome_es), ordem = VALUES(ordem), ativo = 1;

-- Sincroniza o grupo MENUS com a tabela menus.
DROP PROCEDURE IF EXISTS sp_idioma_sincronizar_menus;
CREATE PROCEDURE sp_idioma_sincronizar_menus(IN p_usuario VARCHAR(150))
BEGIN
    DECLARE v_codpublicacao BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_novos INT DEFAULT 0;
    DECLARE v_atualizados INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    SELECT codpublicacao INTO v_codpublicacao FROM idioma_publicacoes WHERE numero = 1;
    START TRANSACTION;
    SELECT COUNT(*) INTO v_novos FROM menus m
     WHERE m.codigo REGEXP '^[A-Za-z0-9_]+$'
       AND NOT EXISTS (SELECT 1 FROM idioma_textos t WHERE t.chave = CONCAT('menu.', m.codigo));
    INSERT INTO idioma_textos (chave, codgrupo, tipo, descricao, ordem, ativo)
    SELECT CONCAT('menu.', m.codigo), 'MENUS', 'MENU',
           LEFT(CONCAT(IF(p.codmenu IS NULL, 'Menu principal', CONCAT('Menu ', COALESCE(NULLIF(p.label_pt, ''), p.label, p.codigo))),
                       IF(m.url IS NULL OR m.url = '', '', CONCAT(' — ', m.url))), 300),
           COALESCE(p.ordem, m.ordem, 0) * 1000 + IF(p.codmenu IS NULL, 0, COALESCE(m.ordem, 0)),
           m.ativo
      FROM menus m
      LEFT JOIN menus p ON p.codmenu = m.codmenu_pai
     WHERE m.codigo REGEXP '^[A-Za-z0-9_]+$'
    ON DUPLICATE KEY UPDATE descricao = VALUES(descricao), ordem = VALUES(ordem), ativo = VALUES(ativo);
    -- Textos novos: rascunho e publicado com os nomes atuais do menu.
    INSERT IGNORE INTO idioma_traducoes_rascunho (codtexto, codidioma, texto, revisao, alterado_por)
    SELECT t.codtexto, i.codidioma,
           CASE i.codidioma WHEN 'pt' THEN COALESCE(NULLIF(m.label_pt, ''), m.label, m.codigo) WHEN 'en' THEN COALESCE(m.label_en, '') ELSE COALESCE(m.label_es, '') END,
           1, p_usuario
      FROM menus m JOIN idioma_textos t ON t.chave = CONCAT('menu.', m.codigo) JOIN idiomas i ON i.codidioma IN ('pt','en','es');
    INSERT IGNORE INTO idioma_traducoes (codtexto, codidioma, texto, codpublicacao)
    SELECT t.codtexto, i.codidioma,
           CASE i.codidioma WHEN 'pt' THEN COALESCE(NULLIF(m.label_pt, ''), m.label, m.codigo) WHEN 'en' THEN COALESCE(m.label_en, '') ELSE COALESCE(m.label_es, '') END,
           v_codpublicacao
      FROM menus m JOIN idioma_textos t ON t.chave = CONCAT('menu.', m.codigo) JOIN idiomas i ON i.codidioma IN ('pt','en','es');
    -- Nome alterado direto na tabela menus (por outra migração): acompanha, se não houver edição pendente.
    UPDATE idioma_traducoes p
      JOIN idioma_traducoes_rascunho r ON r.codtexto = p.codtexto AND r.codidioma = p.codidioma
      JOIN idioma_textos t ON t.codtexto = p.codtexto AND t.codgrupo = 'MENUS'
      JOIN menus m ON CONCAT('menu.', m.codigo) = t.chave
       SET p.texto = CASE p.codidioma WHEN 'pt' THEN COALESCE(NULLIF(m.label_pt, ''), m.label, m.codigo) WHEN 'en' THEN COALESCE(m.label_en, '') ELSE COALESCE(m.label_es, '') END,
           r.texto = CASE p.codidioma WHEN 'pt' THEN COALESCE(NULLIF(m.label_pt, ''), m.label, m.codigo) WHEN 'en' THEN COALESCE(m.label_en, '') ELSE COALESCE(m.label_es, '') END,
           r.revisao = r.revisao + 1, p.publicado_em = CURRENT_TIMESTAMP
     WHERE r.texto <=> p.texto
       AND NOT (CAST(p.texto AS BINARY) <=> CAST(CASE p.codidioma WHEN 'pt' THEN COALESCE(NULLIF(m.label_pt, ''), m.label, m.codigo) WHEN 'en' THEN COALESCE(m.label_en, '') ELSE COALESCE(m.label_es, '') END AS BINARY));
    SET v_atualizados = ROW_COUNT();
    COMMIT;
    SELECT v_novos AS novos, v_atualizados AS atualizados;
END;

-- Cadastra, uma única vez, textos que ainda não existem (ex.: rótulos de coluna de um grid).
-- p_itens: [{"chave":"x.coluna.CAMPO","tipo":"COLUNA","descricao":"...","ordem":10,"pt":"...","en":"...","es":"..."}]
-- O nome do grupo vem do menu da transação (Pai › Filho) quando existe; senão, do nome recebido.
DROP PROCEDURE IF EXISTS sp_idioma_registrar_lote;
CREATE PROCEDURE sp_idioma_registrar_lote(
    IN p_codgrupo VARCHAR(60), IN p_nome_pt VARCHAR(120), IN p_nome_en VARCHAR(120), IN p_nome_es VARCHAR(120),
    IN p_transacao VARCHAR(100), IN p_itens JSON, IN p_usuario VARCHAR(150))
BEGIN
    DECLARE v_codpublicacao BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_pt VARCHAR(120); DECLARE v_en VARCHAR(120); DECLARE v_es VARCHAR(120);
    DECLARE v_novos INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    IF p_codgrupo IS NULL OR p_codgrupo NOT REGEXP '^[A-Z0-9_]+$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Grupo de textos inválido.';
    END IF;
    IF p_itens IS NULL OR JSON_TYPE(p_itens) <> 'ARRAY' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lista de textos inválida.';
    END IF;
    SELECT codpublicacao INTO v_codpublicacao FROM idioma_publicacoes WHERE numero = 1;
    SELECT LEFT(CONCAT(COALESCE(CONCAT(NULLIF(p.label_pt, ''), ' › '), ''), COALESCE(NULLIF(m.label_pt, ''), m.label)), 120),
           LEFT(CONCAT(COALESCE(CONCAT(NULLIF(p.label_en, ''), ' › '), ''), COALESCE(NULLIF(m.label_en, ''), m.label_pt, m.label)), 120),
           LEFT(CONCAT(COALESCE(CONCAT(NULLIF(p.label_es, ''), ' › '), ''), COALESCE(NULLIF(m.label_es, ''), m.label_pt, m.label)), 120)
      INTO v_pt, v_en, v_es
      FROM menus m LEFT JOIN menus p ON p.codmenu = m.codmenu_pai
     WHERE p_transacao <> '' AND m.url LIKE CONCAT('%/', p_transacao, '.php%')
     ORDER BY m.ativo DESC, m.codmenu LIMIT 1;
    START TRANSACTION;
    INSERT IGNORE INTO idioma_grupos (codgrupo, nome_pt, nome_en, nome_es, transacao, ordem, ativo)
    VALUES (p_codgrupo, COALESCE(NULLIF(v_pt, ''), NULLIF(p_nome_pt, ''), p_codgrupo), COALESCE(NULLIF(v_en, ''), NULLIF(p_nome_en, ''), p_nome_pt),
            COALESCE(NULLIF(v_es, ''), NULLIF(p_nome_es, ''), p_nome_pt), NULLIF(p_transacao, ''), 200, 1);
    DROP TEMPORARY TABLE IF EXISTS tmp_idioma_lote;
    CREATE TEMPORARY TABLE tmp_idioma_lote (
        chave VARCHAR(150) NOT NULL PRIMARY KEY, tipo VARCHAR(20) NOT NULL, descricao VARCHAR(300) NULL, ordem INT NOT NULL,
        pt VARCHAR(1000) NOT NULL, en VARCHAR(1000) NOT NULL, es VARCHAR(1000) NOT NULL) ENGINE=InnoDB;
    INSERT IGNORE INTO tmp_idioma_lote (chave, tipo, descricao, ordem, pt, en, es)
    SELECT j.chave, COALESCE(j.tipo, 'ROTULO'), j.descricao, COALESCE(j.ordem, 100), COALESCE(j.pt, ''), COALESCE(j.en, ''), COALESCE(j.es, '')
      FROM JSON_TABLE(p_itens, '$[*]' COLUMNS (
            chave VARCHAR(150) PATH '$.chave', tipo VARCHAR(20) PATH '$.tipo', descricao VARCHAR(300) PATH '$.descricao',
            ordem INT PATH '$.ordem', pt VARCHAR(1000) PATH '$.pt', en VARCHAR(1000) PATH '$.en', es VARCHAR(1000) PATH '$.es')) j
     WHERE j.chave REGEXP '^[a-z0-9_]+(\\.[A-Za-z0-9_]+)+$'
       AND j.chave LIKE CONCAT(LOWER(p_codgrupo), '.%')
       AND COALESCE(j.tipo, 'ROTULO') IN ('TITULO','COLUNA','BOTAO','DICA','MENSAGEM','ROTULO')
       AND TRIM(COALESCE(j.pt, '')) <> '';
    SELECT COUNT(*) INTO v_novos FROM tmp_idioma_lote l WHERE NOT EXISTS (SELECT 1 FROM idioma_textos t WHERE t.chave = l.chave);
    INSERT IGNORE INTO idioma_textos (chave, codgrupo, tipo, descricao, ordem, ativo)
    SELECT chave, p_codgrupo, tipo, descricao, ordem, 1 FROM tmp_idioma_lote;
    INSERT IGNORE INTO idioma_traducoes_rascunho (codtexto, codidioma, texto, revisao, alterado_por)
    SELECT t.codtexto, i.codidioma, CASE i.codidioma WHEN 'pt' THEN l.pt WHEN 'en' THEN l.en ELSE l.es END, 1, p_usuario
      FROM tmp_idioma_lote l JOIN idioma_textos t ON t.chave = l.chave JOIN idiomas i ON i.codidioma IN ('pt','en','es');
    INSERT IGNORE INTO idioma_traducoes (codtexto, codidioma, texto, codpublicacao)
    SELECT t.codtexto, i.codidioma, CASE i.codidioma WHEN 'pt' THEN l.pt WHEN 'en' THEN l.en ELSE l.es END, v_codpublicacao
      FROM tmp_idioma_lote l JOIN idioma_textos t ON t.chave = l.chave JOIN idiomas i ON i.codidioma IN ('pt','en','es');
    COMMIT;
    DROP TEMPORARY TABLE IF EXISTS tmp_idioma_lote;
    SELECT v_novos AS novos;
END;

-- Salvar rascunho (mesma regra da 211) + limite de 150 caracteres para nomes de menu.
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

-- Publicar (mesma regra da 211) + cópia dos nomes de menu publicados para a tabela menus.
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

-- Ajuda da tela: atualiza o parágrafo sobre o alcance (só se ainda estiver com o texto original da 211).
UPDATE ajuda_transacoes
   SET texto_pt = REPLACE(texto_pt, 'Por enquanto a transação <em>Módulo Legal › Controle</em> e os textos comuns dos grids estão nesta tela. As demais transações serão incluídas aos poucos.',
           'Grupo <strong>Menus do portal</strong>: os nomes do menu lateral e das abas. Ao publicar, o nome muda no menu de todos; cada usuário vê a mudança ao entrar de novo no portal (ou atualizar a página). Nomes de menu têm no máximo 150 caracteres.</li><li><strong>Colunas dos grids</strong>: quando um ADMINISTRADOR abre uma transação pela primeira vez, as colunas dela aparecem aqui sozinhas, num grupo com o nome da transação, com os textos atuais. Para que uma tela apareça na lista, basta abri-la uma vez com o perfil ADMINISTRADOR.'),
       texto_en = REPLACE(texto_en, 'For now, the <em>Legal Module › Control</em> transaction and the common grid texts are on this screen. Other transactions will be added gradually.',
           '<strong>Portal menus</strong> group: the names in the side menu and tabs. Once published, the name changes in everyone''s menu; each user sees it after signing in again (or reloading the page). Menu names have at most 150 characters.</li><li><strong>Grid columns</strong>: when an ADMINISTRADOR opens a transaction for the first time, its columns appear here automatically, in a group named after the transaction, with the current texts. To list a screen here, open it once with the ADMINISTRADOR profile.'),
       texto_es = REPLACE(texto_es, 'Por ahora la transacción <em>Módulo Legal › Control</em> y los textos comunes de las cuadrículas están en esta pantalla. Las demás transacciones se incluirán poco a poco.',
           'Grupo <strong>Menús del portal</strong>: los nombres del menú lateral y de las pestañas. Al publicar, el nombre cambia en el menú de todos; cada usuario lo ve al volver a entrar al portal (o al actualizar la página). Los nombres de menú tienen como máximo 150 caracteres.</li><li><strong>Columnas de las cuadrículas</strong>: cuando un ADMINISTRADOR abre una transacción por primera vez, sus columnas aparecen aquí solas, en un grupo con el nombre de la transacción, con los textos actuales. Para que una pantalla aparezca en la lista, basta abrirla una vez con el perfil ADMINISTRADOR.')
 WHERE transacao = 'administracao_idiomas';

INSERT INTO green_universal_migrations (codigo_migracao, versao, status, detalhes)
VALUES ('GREEN_IDIOMAS_MENUS_COLUNAS_212', '4.0.1', 'CONCLUIDA',
        JSON_OBJECT('grupo_menus', 'MENUS', 'procedures', JSON_ARRAY('sp_idioma_sincronizar_menus', 'sp_idioma_registrar_lote', 'sp_idioma_salvar_rascunho', 'sp_idioma_publicar')))
ON DUPLICATE KEY UPDATE status = VALUES(status), detalhes = VALUES(detalhes), executado_em = CURRENT_TIMESTAMP;
