-- Responsabilidades por registro da tabela infraestrutura.
-- Não migra responsáveis legados: o legado não possui CODUSUARIO/CODGRUPO.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_responsabilidade_infraestruturas` AS
SELECT
    a.`CODATRIBUICAO`,
    CAST(a.`REGISTRO_ID` AS UNSIGNED) AS `CODINFRAESTRUTURA`,
    i.`DSCINFRAESTRUTURA`,
    a.`CODUSUARIO`,
    u.`userid` AS `RESPONSAVEL_USUARIO`,
    u.`nome` AS `RESPONSAVEL_NOME`,
    COALESCE(NULLIF(TRIM(u.`nome`), ''), u.`userid`, CONCAT('Usuário ', a.`CODUSUARIO`)) AS `RESPONSAVEL_EXIBICAO`,
    t.`CODIGO` AS `TIPO_CODIGO`,
    t.`NOME` AS `TIPO_NOME`,
    a.`TIPO_DESTINO`, a.`ORIGEM`, a.`PRIORIDADE`, a.`PRINCIPAL`,
    a.`VALIDO_DE`, a.`VALIDO_ATE`, a.`ATIVO`,
    CASE
        WHEN a.`ATIVO` = 0 THEN 'HISTORICA'
        WHEN a.`VALIDO_DE` > CURRENT_DATE THEN 'PROGRAMADA'
        WHEN a.`VALIDO_ATE` IS NOT NULL AND a.`VALIDO_ATE` < CURRENT_DATE THEN 'EXPIRADA'
        ELSE 'ATIVA'
    END AS `SITUACAO`
FROM `responsabilidade_atribuicoes` a
INNER JOIN `responsabilidade_modulos` rm ON rm.`CODMODULO` = a.`CODMODULO` AND rm.`CODIGO` = 'INFRAESTRUTURA'
LEFT JOIN `responsabilidade_tipos` t ON t.`CODTIPORESPONSABILIDADE` = a.`CODTIPORESPONSABILIDADE`
LEFT JOIN `usuarios` u ON u.`codusuario` = a.`CODUSUARIO`
LEFT JOIN `infraestrutura` i ON i.`CODINFRAESTRUTURA` = CAST(a.`REGISTRO_ID` AS UNSIGNED)
WHERE a.`ENTIDADE` = 'infraestrutura' AND a.`TIPO_DESTINO` = 'USUARIO';

INSERT INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('MODELO_RESPONSABILIDADES_VIEW_INFRAESTRUTURA_117', 'gmodelo_dbp.responsabilidade_atribuicoes/infraestrutura', 'gmodelo_dbp.vw_responsabilidade_infraestruturas', 'ESTRUTURA', '1.0.0', 'CONCLUIDA', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, JSON_OBJECT('consulta', 'por CODINFRAESTRUTURA', 'legado_responsavel', FALSE, 'destino', 'USUARIO'))
ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `concluida_em` = VALUES(`concluida_em`), `detalhes` = VALUES(`detalhes`);
