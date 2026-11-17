USE `gmodelo_dbp`;
SET NAMES utf8mb4;

CREATE OR REPLACE VIEW `vw_responsabilidade_ml_infraestrutura` AS
SELECT a.`CODATRIBUICAO`, i.`CODINFRAESTRUTURA`, i.`DSCINFRAESTRUTURA`,
       rt.`CODIGO` AS `TIPO_CODIGO`, rt.`NOME` AS `TIPO_NOME`, a.`TIPO_DESTINO`,
       a.`CODUSUARIO`, u.`userid` AS `RESPONSAVEL_USUARIO`, u.`nome` AS `RESPONSAVEL_NOME`,
       a.`CODGRUPO`, g.`nome` AS `RESPONSAVEL_GRUPO`,
       CASE WHEN a.`TIPO_DESTINO`='USUARIO' THEN COALESCE(NULLIF(TRIM(u.`nome`),''),u.`userid`,CONCAT('Usuario ',a.`CODUSUARIO`))
            WHEN UPPER(TRIM(COALESCE(g.`nome`,'')))='TODOS' THEN 'Todos os usuarios ativos'
            ELSE COALESCE(g.`nome`,CONCAT('Grupo ',a.`CODGRUPO`)) END AS `RESPONSAVEL_EXIBICAO`,
       a.`ORIGEM`,a.`ENTIDADE`,a.`REGISTRO_ID`,a.`PRIORIDADE`,a.`PRINCIPAL`,a.`VALIDO_DE`,a.`VALIDO_ATE`,a.`ATIVO`,
       CASE WHEN a.`ATIVO`=0 THEN 'HISTORICA' WHEN a.`VALIDO_DE`>CURRENT_DATE THEN 'PROGRAMADA'
            WHEN a.`VALIDO_ATE` IS NOT NULL AND a.`VALIDO_ATE`<CURRENT_DATE THEN 'EXPIRADA' ELSE 'ATIVA' END AS `SITUACAO`
FROM `responsabilidade_atribuicoes` a
INNER JOIN `ml_infraestrutura` i ON a.`ENTIDADE`='ml_infraestrutura' AND CAST(a.`REGISTRO_ID` AS UNSIGNED)=i.`CODINFRAESTRUTURA`
LEFT JOIN `responsabilidade_tipos` rt ON rt.`CODTIPORESPONSABILIDADE`=a.`CODTIPORESPONSABILIDADE`
LEFT JOIN `usuarios` u ON u.`codusuario`=a.`CODUSUARIO`
LEFT JOIN `grupos` g ON g.`codgrupo`=a.`CODGRUPO`
WHERE a.`ENTIDADE`='ml_infraestrutura';
