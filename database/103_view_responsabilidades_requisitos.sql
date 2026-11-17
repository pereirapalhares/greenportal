-- Consulta das responsabilidades por situação de requisito legal.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_responsabilidade_requisitos` AS
SELECT
    a.`CODATRIBUICAO`,
    CAST(a.`REGISTRO_ID` AS UNSIGNED) AS `CODSITUACAO`,
    s.`CODLEGISLACAO`,
    s.`codunidade` AS `CODUNIDADE`,
    s.`dscsituacao` AS `DESCRICAO_SITUACAO`,
    t.`CODIGO` AS `TIPO_CODIGO`,
    t.`NOME` AS `TIPO_NOME`,
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
    a.`ORIGEM`, a.`PRIORIDADE`, a.`PRINCIPAL`, a.`VALIDO_DE`, a.`VALIDO_ATE`, a.`ATIVO`,
    CASE WHEN a.`ATIVO` = 0 THEN 'HISTORICA' WHEN a.`VALIDO_DE` > CURRENT_DATE THEN 'PROGRAMADA' WHEN a.`VALIDO_ATE` IS NOT NULL AND a.`VALIDO_ATE` < CURRENT_DATE THEN 'EXPIRADA' ELSE 'ATIVA' END AS `SITUACAO`
FROM `responsabilidade_atribuicoes` a
INNER JOIN `responsabilidade_modulos` m ON m.`CODMODULO` = a.`CODMODULO`
LEFT JOIN `responsabilidade_tipos` t ON t.`CODTIPORESPONSABILIDADE` = a.`CODTIPORESPONSABILIDADE`
LEFT JOIN `usuarios` u ON u.`codusuario` = a.`CODUSUARIO`
LEFT JOIN `grupos` g ON g.`codgrupo` = a.`CODGRUPO`
LEFT JOIN `legislacaoxsituacao` s ON s.`CODSITUACAO` = CAST(a.`REGISTRO_ID` AS UNSIGNED)
WHERE m.`CODIGO` = 'REQUISITOS_LEGAIS'
  AND a.`ENTIDADE` = 'legislacaoxsituacao';

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODELO_RESPONSABILIDADES_VIEW_REQUISITOS_103', 'gmodelo_dbp.responsabilidade_atribuicoes', 'gmodelo_dbp.vw_responsabilidade_requisitos', 'ESTRUTURA', '1.0.0', 'CONCLUIDA', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, JSON_OBJECT('consulta', 'por CODSITUACAO', 'historico', TRUE))
ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `concluida_em` = VALUES(`concluida_em`), `detalhes` = VALUES(`detalhes`);
