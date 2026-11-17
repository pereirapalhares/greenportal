-- Views e procedures iniciais do modelo de responsabilidades.
-- Destino atual: gmodelo_dbp, empresa Modelo.
-- Não migra vínculos legados e não cria atribuições automaticamente.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_responsabilidade_usuarios_ativos` AS
SELECT
    u.`codusuario` AS `CODUSUARIO`,
    u.`userid` AS `USERID`,
    u.`nome` AS `NOME`,
    u.`email` AS `EMAIL`,
    u.`codperfil` AS `CODPERFIL`,
    u.`codgrupo` AS `CODGRUPO`
FROM `usuarios` u
WHERE u.`ativo` = 1;

CREATE OR REPLACE VIEW `vw_responsabilidade_grupos_ativos` AS
SELECT
    g.`codgrupo` AS `CODGRUPO`,
    g.`nome` AS `NOME`,
    g.`ativo` AS `ATIVO`
FROM `grupos` g
WHERE g.`ativo` = 1;

CREATE OR REPLACE VIEW `vw_responsabilidade_grupoxusuario` AS
SELECT
    gx.`CODGRUPOXUSUARIO`,
    gx.`CODGRUPO`,
    gx.`CODUSUARIO`,
    gx.`VALIDO_DE`,
    gx.`VALIDO_ATE`,
    'NOVO' AS `ORIGEM_ASSOCIACAO`
FROM `responsabilidade_grupoxusuario` gx
INNER JOIN `grupos` g ON g.`codgrupo` = gx.`CODGRUPO` AND g.`ativo` = 1
INNER JOIN `usuarios` u ON u.`codusuario` = gx.`CODUSUARIO` AND u.`ativo` = 1
WHERE gx.`ATIVO` = 1
  AND gx.`VALIDO_DE` <= CURRENT_DATE
  AND (gx.`VALIDO_ATE` IS NULL OR gx.`VALIDO_ATE` >= CURRENT_DATE)
UNION ALL
SELECT
    NULL AS `CODGRUPOXUSUARIO`,
    u.`codgrupo` AS `CODGRUPO`,
    u.`codusuario` AS `CODUSUARIO`,
    NULL AS `VALIDO_DE`,
    NULL AS `VALIDO_ATE`,
    'LEGADO' AS `ORIGEM_ASSOCIACAO`
FROM `usuarios` u
INNER JOIN `grupos` g ON g.`codgrupo` = u.`codgrupo` AND g.`ativo` = 1
WHERE u.`ativo` = 1
  AND u.`codgrupo` IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM `responsabilidade_grupoxusuario` gx
      WHERE gx.`CODGRUPO` = u.`codgrupo`
        AND gx.`CODUSUARIO` = u.`codusuario`
  );

CREATE OR REPLACE VIEW `vw_responsabilidade_tipos` AS
SELECT
    t.`CODTIPORESPONSABILIDADE`,
    t.`CODMODULO`,
    m.`CODIGO` AS `MODULO_CODIGO`,
    m.`NOME` AS `MODULO_NOME`,
    t.`CODIGO`,
    t.`NOME`,
    t.`DESCRICAO`,
    t.`ATIVO`,
    t.`ORDEM`
FROM `responsabilidade_tipos` t
INNER JOIN `responsabilidade_modulos` m ON m.`CODMODULO` = t.`CODMODULO`
WHERE t.`ATIVO` = 1 AND m.`ATIVO` = 1;

CREATE OR REPLACE VIEW `vw_responsabilidade_categorias` AS
SELECT
    c.`CODCATEGORIARESPONSABILIDADE`,
    c.`CODCATEGORIAORIGEM`,
    c.`SCHEMAORIGEM`,
    c.`CODIGO`,
    c.`NOME`,
    c.`DESCRICAO`,
    c.`ATIVO`,
    c.`ORDEM`
FROM `responsabilidade_categorias` c
WHERE c.`ATIVO` = 1;

CREATE OR REPLACE VIEW `vw_responsabilidade_atribuicoes_atuais` AS
SELECT
    a.`CODATRIBUICAO`,
    a.`CODMODULO`,
    m.`CODIGO` AS `MODULO_CODIGO`,
    m.`NOME` AS `MODULO_NOME`,
    a.`ORIGEM`,
    a.`CODTIPORESPONSABILIDADE`,
    t.`CODIGO` AS `TIPO_CODIGO`,
    t.`NOME` AS `TIPO_NOME`,
    a.`CODCATEGORIARESPONSABILIDADE`,
    c.`CODIGO` AS `CATEGORIA_CODIGO`,
    c.`NOME` AS `CATEGORIA_NOME`,
    a.`ENTIDADE`,
    a.`REGISTRO_ID`,
    a.`TIPO_DESTINO`,
    a.`CODUSUARIO`,
    a.`CODGRUPO`,
    a.`PRIORIDADE`,
    a.`PRINCIPAL`,
    a.`VALIDO_DE`,
    a.`VALIDO_ATE`,
    a.`OBSERVACAO`
FROM `responsabilidade_atribuicoes` a
INNER JOIN `responsabilidade_modulos` m ON m.`CODMODULO` = a.`CODMODULO`
LEFT JOIN `responsabilidade_tipos` t ON t.`CODTIPORESPONSABILIDADE` = a.`CODTIPORESPONSABILIDADE`
LEFT JOIN `responsabilidade_categorias` c ON c.`CODCATEGORIARESPONSABILIDADE` = a.`CODCATEGORIARESPONSABILIDADE`
WHERE a.`ATIVO` = 1
  AND m.`ATIVO` = 1
  AND a.`VALIDO_DE` <= CURRENT_DATE
  AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE` >= CURRENT_DATE);

CREATE OR REPLACE VIEW `vw_responsabilidade_efetiva` AS
SELECT
    a.`CODATRIBUICAO` AS `CODORIGEM`,
    'ATRIBUICAO' AS `TIPO_ORIGEM`,
    a.`CODMODULO`,
    a.`MODULO_CODIGO`,
    a.`MODULO_NOME`,
    a.`ORIGEM`,
    a.`ENTIDADE`,
    a.`REGISTRO_ID`,
    a.`CODUSUARIO` AS `CODUSUARIO_DESTINO`,
    u.`USERID`,
    u.`NOME` AS `NOME_RESPONSAVEL`,
    a.`CODGRUPO` AS `CODGRUPO_ORIGEM`,
    a.`PRIORIDADE`,
    a.`PRINCIPAL`,
    a.`VALIDO_DE`,
    a.`VALIDO_ATE`
FROM `vw_responsabilidade_atribuicoes_atuais` a
INNER JOIN `usuarios` u ON u.`codusuario` = a.`CODUSUARIO` AND u.`ativo` = 1
WHERE a.`TIPO_DESTINO` = 'USUARIO'
UNION ALL
SELECT
    a.`CODATRIBUICAO` AS `CODORIGEM`,
    'ATRIBUICAO_GRUPO' AS `TIPO_ORIGEM`,
    a.`CODMODULO`,
    a.`MODULO_CODIGO`,
    a.`MODULO_NOME`,
    a.`ORIGEM`,
    a.`ENTIDADE`,
    a.`REGISTRO_ID`,
    gx.`CODUSUARIO` AS `CODUSUARIO_DESTINO`,
    u.`userid` AS `USERID`,
    u.`nome` AS `NOME_RESPONSAVEL`,
    a.`CODGRUPO` AS `CODGRUPO_ORIGEM`,
    a.`PRIORIDADE`,
    a.`PRINCIPAL`,
    a.`VALIDO_DE`,
    a.`VALIDO_ATE`
FROM `vw_responsabilidade_atribuicoes_atuais` a
INNER JOIN `vw_responsabilidade_grupoxusuario` gx ON gx.`CODGRUPO` = a.`CODGRUPO`
INNER JOIN `usuarios` u ON u.`codusuario` = gx.`CODUSUARIO` AND u.`ativo` = 1
WHERE a.`TIPO_DESTINO` = 'GRUPO'
UNION ALL
SELECT
    d.`CODDELEGACAO` AS `CODORIGEM`,
    'DELEGACAO' AS `TIPO_ORIGEM`,
    d.`CODMODULO`,
    m.`CODIGO` AS `MODULO_CODIGO`,
    m.`NOME` AS `MODULO_NOME`,
    'DELEGACAO' AS `ORIGEM`,
    d.`ENTIDADE`,
    d.`REGISTRO_ID`,
    d.`CODUSUARIO` AS `CODUSUARIO_DESTINO`,
    u.`userid` AS `USERID`,
    u.`nome` AS `NOME_RESPONSAVEL`,
    d.`CODGRUPO` AS `CODGRUPO_ORIGEM`,
    0 AS `PRIORIDADE`,
    1 AS `PRINCIPAL`,
    d.`VALIDO_DE`,
    d.`VALIDO_ATE`
FROM `responsabilidade_delegacoes` d
INNER JOIN `responsabilidade_modulos` m ON m.`CODMODULO` = d.`CODMODULO` AND m.`ATIVO` = 1
INNER JOIN `usuarios` u ON u.`codusuario` = d.`CODUSUARIO` AND u.`ativo` = 1
WHERE d.`TIPO_DESTINO` = 'USUARIO'
  AND d.`STATUS` = 'ATIVA'
  AND d.`VALIDO_DE` <= CURRENT_DATE
  AND (d.`VALIDO_ATE` IS NULL OR d.`VALIDO_ATE` >= CURRENT_DATE)
UNION ALL
SELECT
    d.`CODDELEGACAO` AS `CODORIGEM`,
    'DELEGACAO_GRUPO' AS `TIPO_ORIGEM`,
    d.`CODMODULO`,
    m.`CODIGO` AS `MODULO_CODIGO`,
    m.`NOME` AS `MODULO_NOME`,
    'DELEGACAO' AS `ORIGEM`,
    d.`ENTIDADE`,
    d.`REGISTRO_ID`,
    gx.`CODUSUARIO` AS `CODUSUARIO_DESTINO`,
    u.`userid` AS `USERID`,
    u.`nome` AS `NOME_RESPONSAVEL`,
    d.`CODGRUPO` AS `CODGRUPO_ORIGEM`,
    0 AS `PRIORIDADE`,
    1 AS `PRINCIPAL`,
    d.`VALIDO_DE`,
    d.`VALIDO_ATE`
FROM `responsabilidade_delegacoes` d
INNER JOIN `responsabilidade_modulos` m ON m.`CODMODULO` = d.`CODMODULO` AND m.`ATIVO` = 1
INNER JOIN `vw_responsabilidade_grupoxusuario` gx ON gx.`CODGRUPO` = d.`CODGRUPO`
INNER JOIN `usuarios` u ON u.`codusuario` = gx.`CODUSUARIO` AND u.`ativo` = 1
WHERE d.`TIPO_DESTINO` = 'GRUPO'
  AND d.`STATUS` = 'ATIVA'
  AND d.`VALIDO_DE` <= CURRENT_DATE
  AND (d.`VALIDO_ATE` IS NULL OR d.`VALIDO_ATE` >= CURRENT_DATE);

CREATE OR REPLACE VIEW `vw_responsabilidade_orfas` AS
SELECT
    a.`CODATRIBUICAO`,
    a.`CODMODULO`,
    a.`ORIGEM`,
    a.`TIPO_DESTINO`,
    a.`CODUSUARIO`,
    a.`CODGRUPO`,
    a.`ENTIDADE`,
    a.`REGISTRO_ID`,
    CASE
        WHEN a.`TIPO_DESTINO` = 'USUARIO' AND (u.`codusuario` IS NULL OR u.`ativo` = 0) THEN 'USUARIO_INATIVO_OU_INEXISTENTE'
        WHEN a.`TIPO_DESTINO` = 'GRUPO' AND (g.`codgrupo` IS NULL OR g.`ativo` = 0) THEN 'GRUPO_INATIVO_OU_INEXISTENTE'
        WHEN a.`TIPO_DESTINO` = 'GRUPO' AND gm.`CODGRUPO` IS NULL THEN 'GRUPO_SEM_MEMBRO_ATIVO'
        WHEN a.`VALIDO_ATE` IS NOT NULL AND a.`VALIDO_ATE` < CURRENT_DATE THEN 'VINCULO_VENCIDO'
        ELSE 'REVISAR'
    END AS `TIPO_PENDENCIA`
FROM `responsabilidade_atribuicoes` a
LEFT JOIN `usuarios` u ON u.`codusuario` = a.`CODUSUARIO`
LEFT JOIN `grupos` g ON g.`codgrupo` = a.`CODGRUPO`
LEFT JOIN (
    SELECT DISTINCT `CODGRUPO`
    FROM `vw_responsabilidade_grupoxusuario`
) gm ON gm.`CODGRUPO` = a.`CODGRUPO`
WHERE (a.`TIPO_DESTINO` = 'USUARIO' AND (u.`codusuario` IS NULL OR u.`ativo` = 0))
   OR (a.`TIPO_DESTINO` = 'GRUPO' AND (g.`codgrupo` IS NULL OR g.`ativo` = 0 OR gm.`CODGRUPO` IS NULL))
   OR (a.`VALIDO_ATE` IS NOT NULL AND a.`VALIDO_ATE` < CURRENT_DATE);

DROP PROCEDURE IF EXISTS `sp_responsabilidade_validar_destino`;
DELIMITER $$
CREATE PROCEDURE `sp_responsabilidade_validar_destino`(
    IN p_tipo_destino VARCHAR(10),
    IN p_codusuario BIGINT,
    IN p_codgrupo BIGINT
)
BEGIN
    IF p_tipo_destino NOT IN ('USUARIO', 'GRUPO') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tipo de destino invalido.';
    END IF;

    IF p_tipo_destino = 'USUARIO' AND (p_codusuario IS NULL OR p_codgrupo IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe somente um usuario ativo.';
    END IF;

    IF p_tipo_destino = 'GRUPO' AND (p_codgrupo IS NULL OR p_codusuario IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe somente um grupo ativo.';
    END IF;

    IF p_tipo_destino = 'USUARIO' AND NOT EXISTS (
        SELECT 1 FROM `usuarios` WHERE `codusuario` = p_codusuario AND `ativo` = 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Usuario inexistente ou inativo.';
    END IF;

    IF p_tipo_destino = 'GRUPO' AND NOT EXISTS (
        SELECT 1 FROM `grupos` WHERE `codgrupo` = p_codgrupo AND `ativo` = 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Grupo inexistente ou inativo.';
    END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS `sp_responsabilidade_validar_vigencia`;
DELIMITER $$
CREATE PROCEDURE `sp_responsabilidade_validar_vigencia`(
    IN p_valido_de DATE,
    IN p_valido_ate DATE
)
BEGIN
    IF p_valido_de IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A data inicial da vigencia e obrigatoria.';
    END IF;

    IF p_valido_ate IS NOT NULL AND p_valido_ate < p_valido_de THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A data final nao pode ser anterior a data inicial.';
    END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS `sp_responsabilidade_validar_duplicidade`;
DELIMITER $$
CREATE PROCEDURE `sp_responsabilidade_validar_duplicidade`(
    IN p_codatribuicao BIGINT,
    IN p_codmodulo BIGINT,
    IN p_origem VARCHAR(20),
    IN p_codtipo BIGINT,
    IN p_codcategoria BIGINT,
    IN p_entidade VARCHAR(100),
    IN p_registro_id VARCHAR(100),
    IN p_tipo_destino VARCHAR(10),
    IN p_codusuario BIGINT,
    IN p_codgrupo BIGINT,
    IN p_valido_de DATE,
    IN p_valido_ate DATE
)
BEGIN
    CALL `sp_responsabilidade_validar_vigencia`(p_valido_de, p_valido_ate);
    CALL `sp_responsabilidade_validar_destino`(p_tipo_destino, p_codusuario, p_codgrupo);

    IF EXISTS (
        SELECT 1
        FROM `responsabilidade_atribuicoes` a
        WHERE (p_codatribuicao IS NULL OR a.`CODATRIBUICAO` <> p_codatribuicao)
          AND a.`ATIVO` = 1
          AND a.`CODMODULO` = p_codmodulo
          AND a.`ORIGEM` = p_origem
          AND (a.`CODTIPORESPONSABILIDADE` <=> p_codtipo)
          AND (a.`CODCATEGORIARESPONSABILIDADE` <=> p_codcategoria)
          AND (a.`ENTIDADE` <=> p_entidade)
          AND (a.`REGISTRO_ID` <=> p_registro_id)
          AND a.`TIPO_DESTINO` = p_tipo_destino
          AND (a.`CODUSUARIO` <=> p_codusuario)
          AND (a.`CODGRUPO` <=> p_codgrupo)
          AND a.`VALIDO_DE` <= COALESCE(p_valido_ate, '9999-12-31')
          AND COALESCE(a.`VALIDO_ATE`, '9999-12-31') >= p_valido_de
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ja existe vinculo ativo com vigencia sobreposta.';
    END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS `sp_responsabilidade_resolver`;
DELIMITER $$
CREATE PROCEDURE `sp_responsabilidade_resolver`(
    IN p_codmodulo BIGINT,
    IN p_entidade VARCHAR(100),
    IN p_registro_id VARCHAR(100)
)
BEGIN
    SELECT *
    FROM `vw_responsabilidade_efetiva`
    WHERE `CODMODULO` = p_codmodulo
      AND (`ENTIDADE` <=> p_entidade)
      AND (`REGISTRO_ID` <=> p_registro_id)
    ORDER BY `PRIORIDADE`, `PRINCIPAL` DESC, `NOME_RESPONSAVEL`;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS `sp_responsabilidade_identificar_orfas`;
DELIMITER $$
CREATE PROCEDURE `sp_responsabilidade_identificar_orfas`()
BEGIN
    SELECT *
    FROM `vw_responsabilidade_orfas`
    ORDER BY `CODMODULO`, `TIPO_PENDENCIA`, `CODATRIBUICAO`;
END$$
DELIMITER ;

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODELO_RESPONSABILIDADES_VIEWS_094', 'modelo_logico_responsabilidades_20260819.md',
     'gmodelo_dbp.views e procedures responsabilidade_*', 'ESTRUTURA', '1.0.0', 'CONCLUIDA', 13,
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
     JSON_OBJECT('atribuicoes_migradas', 0, 'delegacoes_migradas', 0, 'tenant_referencia', 'gmodelo_dbp'))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`),
    `concluida_em` = VALUES(`concluida_em`),
    `detalhes` = VALUES(`detalhes`);
