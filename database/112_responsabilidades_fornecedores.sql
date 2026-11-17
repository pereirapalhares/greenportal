-- Consulta de responsabilidades da Gestão de Fornecedores.
-- Vínculos legados são consultados por fo_fornecedorxusuario.
-- Novos vínculos de manutenção usam ENTIDADE = fo_fornecedores.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_responsabilidade_fornecedores` AS
SELECT
    a.`CODATRIBUICAO`, f.`CODFORNECEDOR`, f.`RAZAOSOCIAL`,
    t.`CODIGO` AS `TIPO_CODIGO`, t.`NOME` AS `TIPO_NOME`, a.`TIPO_DESTINO`,
    a.`CODUSUARIO`, u.`userid` AS `RESPONSAVEL_USUARIO`, u.`nome` AS `RESPONSAVEL_NOME`,
    a.`CODGRUPO`, g.`nome` AS `RESPONSAVEL_GRUPO`,
    CASE WHEN a.`TIPO_DESTINO` = 'USUARIO' THEN COALESCE(NULLIF(TRIM(u.`nome`), ''), u.`userid`, CONCAT('Usuário ', a.`CODUSUARIO`))
         WHEN UPPER(TRIM(COALESCE(g.`nome`, ''))) = 'TODOS' THEN 'Todos os usuários ativos'
         ELSE COALESCE(g.`nome`, CONCAT('Grupo ', a.`CODGRUPO`)) END AS `RESPONSAVEL_EXIBICAO`,
    a.`ORIGEM`, a.`ENTIDADE`, a.`REGISTRO_ID`, a.`PRIORIDADE`, a.`PRINCIPAL`, a.`VALIDO_DE`, a.`VALIDO_ATE`, a.`ATIVO`,
    CASE WHEN a.`ATIVO` = 0 THEN 'HISTORICA' WHEN a.`VALIDO_DE` > CURRENT_DATE THEN 'PROGRAMADA' WHEN a.`VALIDO_ATE` IS NOT NULL AND a.`VALIDO_ATE` < CURRENT_DATE THEN 'EXPIRADA' ELSE 'ATIVA' END AS `SITUACAO`
FROM `responsabilidade_atribuicoes` a
INNER JOIN `responsabilidade_modulos` rm ON rm.`CODMODULO` = a.`CODMODULO` AND rm.`CODIGO` = 'HOMOLOGACAO'
LEFT JOIN `responsabilidade_tipos` t ON t.`CODTIPORESPONSABILIDADE` = a.`CODTIPORESPONSABILIDADE`
LEFT JOIN `usuarios` u ON u.`codusuario` = a.`CODUSUARIO`
LEFT JOIN `grupos` g ON g.`codgrupo` = a.`CODGRUPO`
INNER JOIN `fo_fornecedorxusuario` fxu ON a.`ENTIDADE` = 'fo_fornecedorxusuario' AND CAST(a.`REGISTRO_ID` AS UNSIGNED) = fxu.`CODFORNECEDORXUSUARIO`
INNER JOIN `fo_fornecedores` f ON f.`CODFORNECEDOR` = fxu.`CODFORNECEDOR`
WHERE a.`ENTIDADE` = 'fo_fornecedorxusuario'
UNION ALL
SELECT
    a.`CODATRIBUICAO`, f.`CODFORNECEDOR`, f.`RAZAOSOCIAL`,
    t.`CODIGO`, t.`NOME`, a.`TIPO_DESTINO`, a.`CODUSUARIO`, u.`userid`, u.`nome`, a.`CODGRUPO`, g.`nome`,
    CASE WHEN a.`TIPO_DESTINO` = 'USUARIO' THEN COALESCE(NULLIF(TRIM(u.`nome`), ''), u.`userid`, CONCAT('Usuário ', a.`CODUSUARIO`))
         WHEN UPPER(TRIM(COALESCE(g.`nome`, ''))) = 'TODOS' THEN 'Todos os usuários ativos'
         ELSE COALESCE(g.`nome`, CONCAT('Grupo ', a.`CODGRUPO`)) END,
    a.`ORIGEM`, a.`ENTIDADE`, a.`REGISTRO_ID`, a.`PRIORIDADE`, a.`PRINCIPAL`, a.`VALIDO_DE`, a.`VALIDO_ATE`, a.`ATIVO`,
    CASE WHEN a.`ATIVO` = 0 THEN 'HISTORICA' WHEN a.`VALIDO_DE` > CURRENT_DATE THEN 'PROGRAMADA' WHEN a.`VALIDO_ATE` IS NOT NULL AND a.`VALIDO_ATE` < CURRENT_DATE THEN 'EXPIRADA' ELSE 'ATIVA' END
FROM `responsabilidade_atribuicoes` a
INNER JOIN `responsabilidade_modulos` rm ON rm.`CODMODULO` = a.`CODMODULO` AND rm.`CODIGO` = 'HOMOLOGACAO'
LEFT JOIN `responsabilidade_tipos` t ON t.`CODTIPORESPONSABILIDADE` = a.`CODTIPORESPONSABILIDADE`
LEFT JOIN `usuarios` u ON u.`codusuario` = a.`CODUSUARIO`
LEFT JOIN `grupos` g ON g.`codgrupo` = a.`CODGRUPO`
INNER JOIN `fo_fornecedores` f ON a.`ENTIDADE` = 'fo_fornecedores' AND CAST(a.`REGISTRO_ID` AS UNSIGNED) = f.`CODFORNECEDOR`
WHERE a.`ENTIDADE` = 'fo_fornecedores';

INSERT INTO `registros_migracao` (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('MODELO_RESPONSABILIDADES_VIEW_FORNECEDORES_112','responsabilidade_atribuicoes/fo_fornecedores','gmodelo_dbp.vw_responsabilidade_fornecedores','ESTRUTURA','1.0.0','CONCLUIDA',0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,JSON_OBJECT('legado','fo_fornecedorxusuario','novos','fo_fornecedores','orfaos','fora_do_escopo'))
ON DUPLICATE KEY UPDATE status=VALUES(status), concluida_em=VALUES(concluida_em), detalhes=VALUES(detalhes);
