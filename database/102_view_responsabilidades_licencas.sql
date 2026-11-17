-- Consulta consolidada das responsabilidades vinculadas a cada licença.
-- Mantém uma linha por atribuição (usuário ou grupo), incluindo histórico.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_responsabilidade_licencas` AS
SELECT
    a.`CODATRIBUICAO`,
    CAST(a.`REGISTRO_ID` AS UNSIGNED) AS `CODLICENCA`,
    l.`DSCLICENCA`,
    t.`CODIGO` AS `TIPO_CODIGO`,
    t.`NOME` AS `TIPO_NOME`,
    COALESCE(c.`CODIGO`, '') AS `CATEGORIA_CODIGO`,
    COALESCE(c.`NOME`, '') AS `CATEGORIA_NOME`,
    a.`TIPO_DESTINO`,
    a.`CODUSUARIO`,
    u.`userid` AS `RESPONSAVEL_USUARIO`,
    u.`nome` AS `RESPONSAVEL_NOME`,
    a.`CODGRUPO`,
    g.`nome` AS `RESPONSAVEL_GRUPO`,
    CASE
        WHEN a.`TIPO_DESTINO` = 'USUARIO' THEN COALESCE(NULLIF(TRIM(u.`nome`), ''), u.`userid`, CONCAT('Usuário ', a.`CODUSUARIO`))
        WHEN UPPER(TRIM(COALESCE(g.`nome`, ''))) = 'TODOS' THEN 'Todos os usuários ativos'
        ELSE COALESCE(g.`nome`, CONCAT('Grupo ', a.`CODGRUPO`))
    END AS `RESPONSAVEL_EXIBICAO`,
    CASE
        WHEN a.`TIPO_DESTINO` = 'GRUPO' AND a.`CODGRUPO` = 99999 THEN (SELECT COUNT(*) FROM `usuarios` ux WHERE ux.`ativo` = 1)
        WHEN a.`TIPO_DESTINO` = 'GRUPO' THEN (SELECT COUNT(*) FROM `usuarios` ux WHERE ux.`ativo` = 1 AND ux.`codgrupo` = a.`CODGRUPO`)
        ELSE NULL
    END AS `USUARIOS_ATIVOS_GRUPO`,
    a.`ORIGEM`,
    a.`PRIORIDADE`,
    a.`PRINCIPAL`,
    a.`VALIDO_DE`,
    a.`VALIDO_ATE`,
    a.`ATIVO`,
    CASE
        WHEN a.`ATIVO` = 0 THEN 'HISTORICA'
        WHEN a.`VALIDO_DE` > CURRENT_DATE THEN 'PROGRAMADA'
        WHEN a.`VALIDO_ATE` IS NOT NULL AND a.`VALIDO_ATE` < CURRENT_DATE THEN 'EXPIRADA'
        ELSE 'ATIVA'
    END AS `SITUACAO`
FROM `responsabilidade_atribuicoes` a
INNER JOIN `responsabilidade_modulos` m ON m.`CODMODULO` = a.`CODMODULO`
LEFT JOIN `responsabilidade_tipos` t ON t.`CODTIPORESPONSABILIDADE` = a.`CODTIPORESPONSABILIDADE`
LEFT JOIN `responsabilidade_categorias` c ON c.`CODCATEGORIARESPONSABILIDADE` = a.`CODCATEGORIARESPONSABILIDADE`
LEFT JOIN `usuarios` u ON u.`codusuario` = a.`CODUSUARIO`
LEFT JOIN `grupos` g ON g.`codgrupo` = a.`CODGRUPO`
LEFT JOIN `li_licencas` l ON l.`CODLICENCA` = CAST(a.`REGISTRO_ID` AS UNSIGNED)
WHERE m.`CODIGO` = 'LICENCAS'
  AND a.`ENTIDADE` = 'li_licencas';

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODELO_RESPONSABILIDADES_VIEW_LICENCAS_102', 'gmodelo_dbp.responsabilidade_atribuicoes', 'gmodelo_dbp.vw_responsabilidade_licencas', 'ESTRUTURA', '1.0.0', 'CONCLUIDA', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, JSON_OBJECT('consulta', 'uma linha por atribuicao', 'historico', TRUE))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`),
    `concluida_em` = VALUES(`concluida_em`),
    `detalhes` = VALUES(`detalhes`);
