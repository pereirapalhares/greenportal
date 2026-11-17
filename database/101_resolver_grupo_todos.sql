-- Trata o grupo legado 99999 / TODOS como grupo especial de todos os usuários ativos.
-- Não materializa membros em responsabilidade_grupoxusuario.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

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
  AND gx.`CODGRUPO` <> 99999
  AND gx.`VALIDO_DE` <= CURRENT_DATE
  AND (gx.`VALIDO_ATE` IS NULL OR gx.`VALIDO_ATE` >= CURRENT_DATE)
UNION ALL
SELECT
    NULL AS `CODGRUPOXUSUARIO`,
    g.`codgrupo` AS `CODGRUPO`,
    u.`codusuario` AS `CODUSUARIO`,
    NULL AS `VALIDO_DE`,
    NULL AS `VALIDO_ATE`,
    'ESPECIAL_TODOS' AS `ORIGEM_ASSOCIACAO`
FROM `grupos` g
INNER JOIN `usuarios` u ON u.`ativo` = 1
WHERE g.`codgrupo` = 99999
  AND g.`ativo` = 1
  AND UPPER(TRIM(g.`nome`)) = 'TODOS'
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
  AND u.`codgrupo` <> 99999
  AND NOT EXISTS (
      SELECT 1
      FROM `responsabilidade_grupoxusuario` gx
      WHERE gx.`CODGRUPO` = u.`codgrupo`
        AND gx.`CODUSUARIO` = u.`codusuario`
  );

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODELO_RESPONSABILIDADES_GRUPO_TODOS_101', 'gmodelo_dbp.grupos 99999/TODOS', 'gmodelo_dbp.vw_responsabilidade_grupoxusuario', 'ESTRUTURA', '1.0.0', 'CONCLUIDA', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, JSON_OBJECT('regra', 'TODOS representa usuarios ativos', 'materializacao', FALSE))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`),
    `concluida_em` = VALUES(`concluida_em`),
    `detalhes` = VALUES(`detalhes`);
