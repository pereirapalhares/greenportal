-- Responsabilidades diretas dos registros de ml_treinamentos.
USE `gmodelo_dbp`;
SET NAMES utf8mb4;

CREATE OR REPLACE VIEW `vw_responsabilidade_ml_treinamentos` AS
SELECT a.`CODATRIBUICAO`, t.`CODTREINAMENTO`, t.`DSCTREINAMENTO`,
       rt.`CODIGO` AS `TIPO_CODIGO`, rt.`NOME` AS `TIPO_NOME`, a.`TIPO_DESTINO`,
       a.`CODUSUARIO`, u.`userid` AS `RESPONSAVEL_USUARIO`, u.`nome` AS `RESPONSAVEL_NOME`,
       a.`CODGRUPO`, g.`nome` AS `RESPONSAVEL_GRUPO`,
       CASE WHEN a.`TIPO_DESTINO` = 'USUARIO'
            THEN COALESCE(NULLIF(TRIM(u.`nome`), ''), u.`userid`, CONCAT('Usuario ', a.`CODUSUARIO`))
            WHEN UPPER(TRIM(COALESCE(g.`nome`, ''))) = 'TODOS' THEN 'Todos os usuarios ativos'
            ELSE COALESCE(g.`nome`, CONCAT('Grupo ', a.`CODGRUPO`)) END AS `RESPONSAVEL_EXIBICAO`,
       a.`ORIGEM`, a.`ENTIDADE`, a.`REGISTRO_ID`, a.`PRIORIDADE`, a.`PRINCIPAL`,
       a.`VALIDO_DE`, a.`VALIDO_ATE`, a.`ATIVO`,
       CASE WHEN a.`ATIVO` = 0 THEN 'HISTORICA'
            WHEN a.`VALIDO_DE` > CURRENT_DATE THEN 'PROGRAMADA'
            WHEN a.`VALIDO_ATE` IS NOT NULL AND a.`VALIDO_ATE` < CURRENT_DATE THEN 'EXPIRADA'
            ELSE 'ATIVA' END AS `SITUACAO`
FROM `responsabilidade_atribuicoes` a
INNER JOIN `ml_treinamentos` t
        ON a.`ENTIDADE` = 'ml_treinamentos'
       AND CAST(a.`REGISTRO_ID` AS UNSIGNED) = t.`CODTREINAMENTO`
LEFT JOIN `responsabilidade_tipos` rt ON rt.`CODTIPORESPONSABILIDADE` = a.`CODTIPORESPONSABILIDADE`
LEFT JOIN `usuarios` u ON u.`codusuario` = a.`CODUSUARIO`
LEFT JOIN `grupos` g ON g.`codgrupo` = a.`CODGRUPO`
WHERE a.`ENTIDADE` = 'ml_treinamentos';

CREATE INDEX `ix_responsabilidade_atribuicoes_treinamento`
    ON `responsabilidade_atribuicoes` (`ENTIDADE`, `REGISTRO_ID`, `ATIVO`);

-- A view de Meus Treinamentos também considera membros de grupos atribuídos.
CREATE OR REPLACE VIEW `vw_minhas_treinamentos_grid` AS
SELECT DISTINCT t.`CODTREINAMENTO`, t.`DSCTREINAMENTO`, g.`ARQUIVO`, g.`PALAVRAS_CHAVE`, a.`CODUSUARIO`
FROM `ml_treinamentos` t
INNER JOIN `vw_modulolegal_treinamentos_grid` g ON g.`CODTREINAMENTO` = t.`CODTREINAMENTO`
INNER JOIN `responsabilidade_atribuicoes` a ON a.`ENTIDADE` = 'ml_treinamentos'
 AND a.`REGISTRO_ID` = CAST(t.`CODTREINAMENTO` AS CHAR) AND a.`TIPO_DESTINO` = 'USUARIO'
 AND a.`CODUSUARIO` IS NOT NULL AND a.`ATIVO` = 1 AND a.`VALIDO_DE` <= CURRENT_DATE
 AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE` >= CURRENT_DATE)
UNION
SELECT DISTINCT t.`CODTREINAMENTO`, t.`DSCTREINAMENTO`, g.`ARQUIVO`, g.`PALAVRAS_CHAVE`, gu.`CODUSUARIO`
FROM `ml_treinamentos` t
INNER JOIN `vw_modulolegal_treinamentos_grid` g ON g.`CODTREINAMENTO` = t.`CODTREINAMENTO`
INNER JOIN `responsabilidade_atribuicoes` a ON a.`ENTIDADE` = 'ml_treinamentos'
 AND a.`REGISTRO_ID` = CAST(t.`CODTREINAMENTO` AS CHAR) AND a.`TIPO_DESTINO` = 'GRUPO'
 AND a.`CODGRUPO` IS NOT NULL AND a.`ATIVO` = 1 AND a.`VALIDO_DE` <= CURRENT_DATE
 AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE` >= CURRENT_DATE)
INNER JOIN `responsabilidade_grupoxusuario` gu ON gu.`CODGRUPO` = a.`CODGRUPO` AND gu.`ATIVO` = 1;
