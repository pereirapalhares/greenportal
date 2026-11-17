-- Migração 264 — Dicionário da pesquisa: termos do negócio (siglas, significados, sinônimos) usados pela Pesquisa de documentos.
-- Aplicado por scripts/aplicar-migracao-264-busca-documentos.php em guniversal_dbp (base comum de todas as empresas) e em cada
-- banco de empresa (termos da empresa). A pesquisa usa os dois (vw_busca_dicionario), só os termos APROVADOS. status: SUGESTAO (a revisar), APROVADO, REJEITADO. origem: SETOR (carga inicial), DADOS
-- (extraído dos cadastros pelo serviço), MANUAL. contexto: tipos da pesquisa onde o termo é mais relevante (separados por vírgula).
-- Comandos separados por "-- @@".

-- @@
CREATE TABLE IF NOT EXISTS `busca_dicionario` (
    `coddicionario` INT NOT NULL AUTO_INCREMENT,
    `termo` VARCHAR(100) NOT NULL,
    `significado` VARCHAR(300) NOT NULL,
    `sinonimos` VARCHAR(1000) NULL COMMENT 'separados por ponto e vírgula',
    `sigla` TINYINT NOT NULL DEFAULT 0 COMMENT '1 = vale só como palavra inteira (ASO não acha "caso")',
    `contexto` VARCHAR(300) NULL COMMENT 'tipos da pesquisa: licencas,controles,requisitos,treinamentos,infraestrutura,fornecedorcriterios,funcionariocriterios,funcionariotreinamentos',
    `status` VARCHAR(12) NOT NULL DEFAULT 'SUGESTAO',
    `origem` VARCHAR(12) NOT NULL DEFAULT 'MANUAL',
    `ocorrencias` INT NOT NULL DEFAULT 0,
    `exemplo` VARCHAR(500) NULL COMMENT 'texto de onde a sugestão foi extraída',
    `atualizado_por` INT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`coddicionario`),
    UNIQUE KEY `uk_busca_dicionario` (`termo`, `significado`),
    KEY `idx_busca_dicionario_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
DROP PROCEDURE IF EXISTS `sp_busca_dicionario_gravar`

-- @@
-- Inclui (p_id = 0) ou altera um termo. Devolve coddicionario.
CREATE PROCEDURE `sp_busca_dicionario_gravar`(IN p_id INT, IN p_termo VARCHAR(100) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
    IN p_significado VARCHAR(300) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci, IN p_sinonimos VARCHAR(1000) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
    IN p_sigla TINYINT, IN p_contexto VARCHAR(300) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci, IN p_status VARCHAR(12) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
    IN p_usuario INT)
BEGIN
    IF COALESCE(p_id, 0) > 0 THEN
        UPDATE `busca_dicionario` SET termo = TRIM(p_termo), significado = TRIM(p_significado), sinonimos = NULLIF(TRIM(p_sinonimos), ''),
               sigla = COALESCE(p_sigla, 0), contexto = NULLIF(TRIM(p_contexto), ''), status = COALESCE(NULLIF(p_status, ''), status), atualizado_por = p_usuario
         WHERE coddicionario = p_id;
        SELECT p_id AS coddicionario;
    ELSE
        INSERT INTO `busca_dicionario` (termo, significado, sinonimos, sigla, contexto, status, origem, atualizado_por)
        VALUES (TRIM(p_termo), TRIM(p_significado), NULLIF(TRIM(p_sinonimos), ''), COALESCE(p_sigla, 0), NULLIF(TRIM(p_contexto), ''),
                COALESCE(NULLIF(p_status, ''), 'APROVADO'), 'MANUAL', p_usuario);
        SELECT LAST_INSERT_ID() AS coddicionario;
    END IF;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_busca_dicionario_status`

-- @@
-- Aprova / rejeita / volta para sugestão. p_ids = [1, 2, ...]
CREATE PROCEDURE `sp_busca_dicionario_status`(IN p_ids JSON, IN p_status VARCHAR(12) CHARSET utf8mb4 COLLATE utf8mb4_0900_ai_ci, IN p_usuario INT)
BEGIN
    UPDATE `busca_dicionario` d JOIN JSON_TABLE(COALESCE(p_ids, JSON_ARRAY()), '$[*]' COLUMNS (id INT PATH '$')) j ON j.id = d.coddicionario
       SET d.status = p_status, d.atualizado_por = p_usuario
     WHERE p_status IN ('SUGESTAO', 'APROVADO', 'REJEITADO');
    SELECT ROW_COUNT() AS alterados;
END

-- @@
DROP PROCEDURE IF EXISTS `sp_busca_dicionario_excluir`

-- @@
CREATE PROCEDURE `sp_busca_dicionario_excluir`(IN p_ids JSON)
BEGIN
    DELETE d FROM `busca_dicionario` d JOIN JSON_TABLE(COALESCE(p_ids, JSON_ARRAY()), '$[*]' COLUMNS (id INT PATH '$')) j ON j.id = d.coddicionario;
    SELECT ROW_COUNT() AS excluidos;
END
