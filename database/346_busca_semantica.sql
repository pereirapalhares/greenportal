-- Migração 346 — Busca semântica (pesquisa pelo sentido) e reavaliação quando uma lei muda (Onda 2 da apresentação Atlas;
-- LEG-006 "reanálise quando a legislação mudar e busca semântica").
-- Aplicada por scripts/aplicar-migracao-346-busca-semantica.php em cada empresa com a Pesquisa de documentos (migração 264).
-- Pode ser executada mais de uma vez.
--
-- Decisões do usuário (23/10/2026):
--   * entram os cadastros e o texto já lido dos arquivos (OCR); arquivo sem texto lido fica só na busca por palavra;
--   * na mesma Pesquisa de documentos: depois dos resultados por palavra, os parecidos pelo sentido (marcados "por sentido"),
--     com uma chave para ligar/desligar;
--   * reavaliação agora: alteração ou revogação de uma lei → os requisitos e itens de gestão parecidos da empresa e uma tarefa
--     "revisar" para os responsáveis.
-- Vetores: embeddings da OpenAI (modelo e dimensões no .env: GPT_AI_EMBEDDING_MODEL, padrão text-embedding-3-small, 256),
-- gravados em 8 bits (VARBINARY) com a norma; a semelhança (cosseno) é calculada no PHP (classes/BuscaSemantica.php).
-- As views que dependem do banco (texto dos itens com o OCR, alterações da base de legislação, responsáveis dos requisitos)
-- são montadas pelo script.
-- @@
CREATE TABLE IF NOT EXISTS `busca_embedding` (
    `codbusca` BIGINT UNSIGNED NOT NULL COMMENT 'Item da Pesquisa de documentos (busca_indice)',
    `hash` CHAR(40) NOT NULL COMMENT 'SHA1 do texto vetorizado: mudou o texto, vetoriza de novo',
    `modelo` VARCHAR(60) NOT NULL,
    `dimensoes` SMALLINT NOT NULL,
    `vetor` VARBINARY(2048) NOT NULL COMMENT 'Componentes em 8 bits com sinal (-127..127)',
    `norma` FLOAT NOT NULL COMMENT 'Norma do vetor em 8 bits (para o cosseno)',
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`codbusca`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS `lei_reav_controle` (
    `CHAVE` VARCHAR(40) NOT NULL,
    `VALOR` VARCHAR(100) NOT NULL,
    `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`CHAVE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS `lei_reavaliacao` (
    `CODREAV` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `CODALTERACAO` BIGINT NOT NULL COMMENT 'Alteração na base de legislação (legislacaoxalteracao)',
    `CODLEGISLACAO` BIGINT NOT NULL,
    `LEI` VARCHAR(500) NULL,
    `TIPO` VARCHAR(200) NULL,
    `DESCRICAO` TEXT NULL,
    `DATA_ALTERACAO` DATE NULL,
    `EH_REQUISITO` TINYINT NOT NULL DEFAULT 0 COMMENT '1 = a lei está nos requisitos legais da empresa',
    `ITENS` INT NOT NULL DEFAULT 0,
    `CODTAREFA` BIGINT NULL COMMENT 'Tarefa "revisar" (green_tasks)',
    `CODUSUARIO` BIGINT NULL COMMENT 'Responsável pela tarefa',
    `SITUACAO` VARCHAR(20) NOT NULL DEFAULT 'ABERTA' COMMENT 'ABERTA, SEM_IMPACTO (nada parecido e não é requisito)',
    `MOTIVO` VARCHAR(1000) NULL,
    `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`CODREAV`),
    UNIQUE KEY `ux_lei_reav_alteracao` (`CODALTERACAO`),
    KEY `ix_lei_reav_lei` (`CODLEGISLACAO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS `lei_reavaliacao_itens` (
    `CODREAV` BIGINT UNSIGNED NOT NULL,
    `codbusca` BIGINT UNSIGNED NOT NULL,
    `TIPO` VARCHAR(40) NOT NULL,
    `NOME` VARCHAR(500) NULL,
    `UNIDADE` VARCHAR(500) NULL,
    `PAGINA` VARCHAR(200) NULL,
    `FILTROS` JSON NULL,
    `SEMELHANCA` DECIMAL(5,3) NULL COMMENT '0..1 (cosseno); NULL = a própria lei (requisito)',
    `MOTIVO` VARCHAR(20) NOT NULL COMMENT 'REQUISITO (a lei alterada) ou SENTIDO (parecido)',
    PRIMARY KEY (`CODREAV`, `codbusca`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
DROP PROCEDURE IF EXISTS `sp_busca_semantica_pendentes`
-- @@
CREATE PROCEDURE `sp_busca_semantica_pendentes`(IN p_limite INT)
BEGIN
    DECLARE v_lim INT DEFAULT LEAST(GREATEST(COALESCE(p_limite, 200), 1), 2000);
    -- Itens do índice sem vetor ou com o texto alterado (o hash não bate). Registros sempre; arquivos só com texto lido (OCR).
    SELECT t.`codbusca`, t.`texto`, t.`hash`
      FROM `vw_busca_semantica_textos` t
      LEFT JOIN `busca_embedding` e ON e.`codbusca` = t.`codbusca`
     WHERE (e.`codbusca` IS NULL OR e.`hash` COLLATE utf8mb4_unicode_ci <> t.`hash` COLLATE utf8mb4_unicode_ci)
     ORDER BY (e.`codbusca` IS NULL) DESC, t.`codbusca`
     LIMIT v_lim;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_busca_semantica_gravar`
-- @@
CREATE PROCEDURE `sp_busca_semantica_gravar`(IN p_codbusca BIGINT, IN p_hash CHAR(40), IN p_modelo VARCHAR(60), IN p_dimensoes SMALLINT, IN p_vetor_hex VARCHAR(4096), IN p_norma FLOAT)
BEGIN
    -- p_vetor_hex: o vetor em 8 bits em hexadecimal (o texto binário não passa pela conexão utf8mb4)
    INSERT INTO `busca_embedding` (`codbusca`, `hash`, `modelo`, `dimensoes`, `vetor`, `norma`)
    VALUES (p_codbusca, p_hash, p_modelo, p_dimensoes, UNHEX(p_vetor_hex), p_norma)
    ON DUPLICATE KEY UPDATE `hash` = VALUES(`hash`), `modelo` = VALUES(`modelo`), `dimensoes` = VALUES(`dimensoes`), `vetor` = VALUES(`vetor`), `norma` = VALUES(`norma`);
    SELECT ROW_COUNT() AS `GRAVADOS`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_busca_semantica_limpar`
-- @@
CREATE PROCEDURE `sp_busca_semantica_limpar`()
BEGIN
    -- Vetores de itens que saíram do índice ou deixaram de ter texto (arquivo sem OCR)
    DELETE e FROM `busca_embedding` e LEFT JOIN `vw_busca_semantica_textos` t ON t.`codbusca` = e.`codbusca` WHERE t.`codbusca` IS NULL;
    SELECT ROW_COUNT() AS `REMOVIDOS`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_busca_semantica_vetores`
-- @@
CREATE PROCEDURE `sp_busca_semantica_vetores`(IN p_modelo VARCHAR(60), IN p_dimensoes SMALLINT, IN p_tipos JSON, IN p_apos BIGINT, IN p_limite INT)
BEGIN
    DECLARE v_lim INT DEFAULT LEAST(GREATEST(COALESCE(p_limite, 5000), 1), 20000);
    -- Vetores do modelo atual, em páginas por codbusca (p_apos = último codbusca da página anterior); p_tipos restringe os tipos
    SELECT e.`codbusca`, e.`vetor`, e.`norma`
      FROM `busca_embedding` e JOIN `busca_indice` i ON i.`codbusca` = e.`codbusca`
     WHERE e.`modelo` COLLATE utf8mb4_unicode_ci = p_modelo COLLATE utf8mb4_unicode_ci AND e.`dimensoes` = p_dimensoes AND e.`codbusca` > COALESCE(p_apos, 0)
       AND (p_tipos IS NULL OR JSON_LENGTH(p_tipos) = 0 OR JSON_CONTAINS(p_tipos, JSON_QUOTE(CONVERT(i.`tipo` USING utf8mb4))))
     ORDER BY e.`codbusca`
     LIMIT v_lim;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_busca_semantica_resumo`
-- @@
CREATE PROCEDURE `sp_busca_semantica_resumo`()
BEGIN
    SELECT (SELECT COUNT(*) FROM `vw_busca_semantica_textos`) AS `ITENS_COM_TEXTO`, (SELECT COUNT(*) FROM `busca_embedding`) AS `VETORES`,
           (SELECT MAX(`atualizado_em`) FROM `busca_embedding`) AS `ATUALIZADO_EM`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_lei_reav_itens_busca`
-- @@
CREATE PROCEDURE `sp_lei_reav_itens_busca`(IN p_ids JSON)
BEGIN
    -- Dados dos itens do índice (para a reavaliação: requisitos e itens parecidos)
    SELECT i.`codbusca`, i.`tipo`, i.`item`, i.`chave`, i.`nome`, i.`titulo`, i.`unidade`, i.`codunidade`, i.`codusuario`, i.`pagina`, CAST(i.`filtros` AS CHAR) AS `filtros`,
           i.`ref_legislacao`, i.`ref_unidade`
      FROM `busca_indice` i JOIN JSON_TABLE(p_ids, '$[*]' COLUMNS (id BIGINT PATH '$')) j ON j.id = i.`codbusca`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_lei_reav_requisitos`
-- @@
CREATE PROCEDURE `sp_lei_reav_requisitos`(IN p_codlegislacao BIGINT)
BEGIN
    -- Requisitos da empresa (uma linha por unidade) da lei alterada
    SELECT i.`codbusca` FROM `busca_indice` i WHERE i.`tipo` = 'requisitos' AND i.`item` = 'REGISTRO' AND i.`ref_legislacao` = p_codlegislacao;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_lei_reav_registrar`
-- @@
CREATE PROCEDURE `sp_lei_reav_registrar`(IN p_codalteracao BIGINT, IN p_codlegislacao BIGINT, IN p_lei VARCHAR(500), IN p_tipo VARCHAR(200), IN p_descricao TEXT,
    IN p_data DATE, IN p_eh_requisito TINYINT, IN p_itens JSON, IN p_codtarefa BIGINT, IN p_codusuario BIGINT, IN p_situacao VARCHAR(20), IN p_motivo VARCHAR(1000))
BEGIN
    -- Grava a reavaliação de uma alteração e os itens (p_itens: [{"id","tipo","nome","unidade","pagina","filtros","s","m"}])
    DECLARE v_id BIGINT UNSIGNED;
    INSERT INTO `lei_reavaliacao` (`CODALTERACAO`, `CODLEGISLACAO`, `LEI`, `TIPO`, `DESCRICAO`, `DATA_ALTERACAO`, `EH_REQUISITO`, `ITENS`, `CODTAREFA`, `CODUSUARIO`, `SITUACAO`, `MOTIVO`)
    VALUES (p_codalteracao, p_codlegislacao, LEFT(p_lei, 500), LEFT(p_tipo, 200), p_descricao, p_data, COALESCE(p_eh_requisito, 0), COALESCE(JSON_LENGTH(p_itens), 0),
            NULLIF(p_codtarefa, 0), NULLIF(p_codusuario, 0), COALESCE(p_situacao, 'ABERTA'), LEFT(p_motivo, 1000))
    ON DUPLICATE KEY UPDATE `CODREAV` = LAST_INSERT_ID(`CODREAV`);
    SET v_id = LAST_INSERT_ID();
    IF p_itens IS NOT NULL AND JSON_LENGTH(p_itens) > 0 THEN
        INSERT IGNORE INTO `lei_reavaliacao_itens` (`CODREAV`, `codbusca`, `TIPO`, `NOME`, `UNIDADE`, `PAGINA`, `FILTROS`, `SEMELHANCA`, `MOTIVO`)
        SELECT v_id, j.id, j.tipo, LEFT(j.nome, 500), LEFT(j.unidade, 500), LEFT(j.pagina, 200), IF(j.filtros IS NULL OR j.filtros = '', NULL, CAST(j.filtros AS JSON)), j.s, j.m
          FROM JSON_TABLE(p_itens, '$[*]' COLUMNS (id BIGINT PATH '$.id', tipo VARCHAR(40) PATH '$.tipo', nome VARCHAR(500) PATH '$.nome', unidade VARCHAR(500) PATH '$.unidade',
                pagina VARCHAR(200) PATH '$.pagina', filtros VARCHAR(2000) PATH '$.filtros', s DECIMAL(5,3) PATH '$.s', m VARCHAR(20) PATH '$.m')) j;
    END IF;
    SELECT v_id AS `CODREAV`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_lei_reav_marca`
-- @@
CREATE PROCEDURE `sp_lei_reav_marca`(IN p_valor VARCHAR(100))
BEGIN
    INSERT INTO `lei_reav_controle` (`CHAVE`, `VALOR`) VALUES ('ULTIMA_ALTERACAO', p_valor) ON DUPLICATE KEY UPDATE `VALOR` = VALUES(`VALOR`);
    SELECT p_valor AS `VALOR`;
END
-- @@
CREATE OR REPLACE VIEW `vw_lei_reav_controle` AS SELECT `CHAVE`, `VALOR`, `ALTERADO_EM` FROM `lei_reav_controle`
-- @@
CREATE OR REPLACE VIEW `vw_lei_reavaliacao` AS
SELECT r.`CODREAV`, r.`CODALTERACAO`, r.`CODLEGISLACAO`, r.`LEI`, r.`TIPO`, r.`DESCRICAO`, r.`DATA_ALTERACAO`, r.`EH_REQUISITO`,
       IF(r.`EH_REQUISITO` = 1, 'Sim', 'Não') AS `EH_REQUISITO_DESC`, r.`ITENS`, r.`CODTAREFA`, r.`CODUSUARIO`, u.`nome` AS `RESPONSAVEL`,
       t.`status` AS `STATUS_TAREFA`, r.`SITUACAO`, r.`MOTIVO`, r.`CRIADO_EM`
  FROM `lei_reavaliacao` r
  LEFT JOIN `usuarios` u ON u.`codusuario` = r.`CODUSUARIO`
  LEFT JOIN `green_tasks` t ON t.`codtarefa` = r.`CODTAREFA`
-- @@
CREATE OR REPLACE VIEW `vw_lei_reavaliacao_itens` AS
SELECT x.`CODREAV`, x.`codbusca`, x.`TIPO`, x.`NOME`, x.`UNIDADE`, x.`PAGINA`, CAST(x.`FILTROS` AS CHAR) AS `FILTROS`, x.`SEMELHANCA`, x.`MOTIVO` FROM `lei_reavaliacao_itens` x
-- @@
DROP PROCEDURE IF EXISTS `sp_lei_reav_novas`
-- @@
CREATE PROCEDURE `sp_lei_reav_novas`(IN p_apos BIGINT, IN p_limite INT)
BEGIN
    DECLARE v_lim INT DEFAULT LEAST(GREATEST(COALESCE(p_limite, 50), 1), 500);
    -- Alterações da base de legislação depois da marca (vw_lei_alteracoes, montada pela migração com a base configurada)
    SELECT a.* FROM `vw_lei_alteracoes` a
     WHERE a.`CODALTERACAO` > COALESCE(p_apos, 0)
       AND NOT EXISTS (SELECT 1 FROM `lei_reavaliacao` r WHERE r.`CODALTERACAO` = a.`CODALTERACAO`)
     ORDER BY a.`CODALTERACAO`
     LIMIT v_lim;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_lei_reav_tarefa`
-- @@
CREATE PROCEDURE `sp_lei_reav_tarefa`(IN p_codreav BIGINT, IN p_tarefa BIGINT, IN p_usuario BIGINT)
BEGIN
    UPDATE `lei_reavaliacao` SET `CODTAREFA` = NULLIF(p_tarefa, 0), `CODUSUARIO` = NULLIF(p_usuario, 0) WHERE `CODREAV` = p_codreav;
    SELECT ROW_COUNT() AS `ALTERADAS`;
END
