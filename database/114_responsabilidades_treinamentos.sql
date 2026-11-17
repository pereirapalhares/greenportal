-- Responsabilidades por registro de treinamento.
-- Somente vínculos com usuário existente no tenant são carregados.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO `responsabilidade_atribuicoes`
    (`CODMODULO`,`ORIGEM`,`CODTIPORESPONSABILIDADE`,`ENTIDADE`,`REGISTRO_ID`,
     `TIPO_DESTINO`,`CODUSUARIO`,`VALIDO_DE`,`ATIVO`,`OBSERVACAO`)
SELECT m.`CODMODULO`, 'DIRETA', t.`CODTIPORESPONSABILIDADE`,
       'fo_funcionarioxtiposxtreinamentos', CAST(x.`codigo` AS CHAR),
       'USUARIO', x.`codusuario`, '1900-01-01', IF(u.`ativo` = 1, 1, 0),
       'Migrado de fo_funcionarioxtiposxtreinamentos.codusuario'
FROM `fo_funcionarioxtiposxtreinamentos` x
INNER JOIN `usuarios` u ON u.`codusuario` = x.`codusuario`
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = 'TREINAMENTO'
INNER JOIN `responsabilidade_tipos` t
    ON t.`CODMODULO` = m.`CODMODULO` AND t.`CODIGO` = 'TREINAMENTO'
WHERE x.`codusuario` IS NOT NULL AND x.`codusuario` <> 0
  AND NOT EXISTS (
      SELECT 1 FROM `responsabilidade_atribuicoes` a
      WHERE a.`CODMODULO` = m.`CODMODULO`
        AND a.`CODTIPORESPONSABILIDADE` = t.`CODTIPORESPONSABILIDADE`
        AND a.`ENTIDADE` = 'fo_funcionarioxtiposxtreinamentos'
        AND a.`REGISTRO_ID` = CAST(x.`codigo` AS CHAR)
        AND a.`TIPO_DESTINO` = 'USUARIO'
        AND a.`CODUSUARIO` = x.`codusuario`
  );

CREATE OR REPLACE VIEW `vw_responsabilidade_treinamentos` AS
SELECT a.`CODATRIBUICAO`, x.`codigo` AS `CODIGO`, x.`codfuncionario` AS `CODFUNCIONARIO`,
       x.`codtipofuncionario` AS `CODTIPOFUNCIONARIO`, x.`codtipotreinamento` AS `CODTIPOTREINAMENTO`,
       t.`CODIGO` AS `TIPO_CODIGO`, t.`NOME` AS `TIPO_NOME`, a.`TIPO_DESTINO`,
       a.`CODUSUARIO`, u.`userid` AS `RESPONSAVEL_USUARIO`, u.`nome` AS `RESPONSAVEL_NOME`,
       a.`CODGRUPO`, g.`nome` AS `RESPONSAVEL_GRUPO`,
       CASE WHEN a.`TIPO_DESTINO` = 'USUARIO'
            THEN COALESCE(NULLIF(TRIM(u.`nome`), ''), u.`userid`, CONCAT('Usuário ', a.`CODUSUARIO`))
            WHEN UPPER(TRIM(COALESCE(g.`nome`, ''))) = 'TODOS' THEN 'Todos os usuários ativos'
            ELSE COALESCE(g.`nome`, CONCAT('Grupo ', a.`CODGRUPO`)) END AS `RESPONSAVEL_EXIBICAO`,
       a.`ORIGEM`, a.`ENTIDADE`, a.`REGISTRO_ID`, a.`PRIORIDADE`, a.`PRINCIPAL`,
       a.`VALIDO_DE`, a.`VALIDO_ATE`, a.`ATIVO`,
       CASE WHEN a.`ATIVO` = 0 THEN 'HISTORICA'
            WHEN a.`VALIDO_DE` > CURRENT_DATE THEN 'PROGRAMADA'
            WHEN a.`VALIDO_ATE` IS NOT NULL AND a.`VALIDO_ATE` < CURRENT_DATE THEN 'EXPIRADA'
            ELSE 'ATIVA' END AS `SITUACAO`
FROM `responsabilidade_atribuicoes` a
INNER JOIN `responsabilidade_modulos` rm
    ON rm.`CODMODULO` = a.`CODMODULO` AND rm.`CODIGO` = 'TREINAMENTO'
INNER JOIN `fo_funcionarioxtiposxtreinamentos` x
    ON a.`ENTIDADE` = 'fo_funcionarioxtiposxtreinamentos'
   AND CAST(a.`REGISTRO_ID` AS UNSIGNED) = x.`codigo`
LEFT JOIN `responsabilidade_tipos` t ON t.`CODTIPORESPONSABILIDADE` = a.`CODTIPORESPONSABILIDADE`
LEFT JOIN `usuarios` u ON u.`codusuario` = a.`CODUSUARIO`
LEFT JOIN `grupos` g ON g.`codgrupo` = a.`CODGRUPO`
WHERE a.`ENTIDADE` = 'fo_funcionarioxtiposxtreinamentos';

INSERT INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,
     `iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('MODELO_RESPONSABILIDADES_TREINAMENTOS_114',
        'fo_funcionarioxtiposxtreinamentos.codusuario',
        'gmodelo_dbp.responsabilidade_atribuicoes/vw_responsabilidade_treinamentos',
        'DADOS','1.0.0','CONCLUIDA',
        (SELECT COUNT(*) FROM `responsabilidade_atribuicoes`
         WHERE `ENTIDADE` = 'fo_funcionarioxtiposxtreinamentos'),
        CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
        JSON_OBJECT('registros_legados',955,'registros_validos_carregados',2,
                    'orfaos_fora_escopo',953,'idempotente',TRUE))
ON DUPLICATE KEY UPDATE `status`=VALUES(`status`),`quantidade_registros`=VALUES(`quantidade_registros`),
`concluida_em`=VALUES(`concluida_em`),`detalhes`=VALUES(`detalhes`);
