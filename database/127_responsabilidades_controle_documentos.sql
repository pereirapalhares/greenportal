-- Responsabilidades diretas por documento de Controle.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_responsabilidade_controle_documentos` AS
SELECT
    a.`CODATRIBUICAO`,
    CAST(a.`REGISTRO_ID` AS UNSIGNED) AS `CODDOCUMENTO`,
    a.`TIPO_DESTINO`, a.`CODUSUARIO`, u.`userid` AS `RESPONSAVEL_USUARIO`,
    u.`nome` AS `RESPONSAVEL_NOME`, a.`CODGRUPO`, g.`nome` AS `RESPONSAVEL_GRUPO`,
    a.`PRIORIDADE`, a.`PRINCIPAL`, a.`VALIDO_DE`, a.`VALIDO_ATE`, a.`OBSERVACAO`,
    a.`ATIVO`, t.`CODIGO` AS `TIPO_CODIGO`, t.`NOME` AS `TIPO_NOME`
FROM `responsabilidade_atribuicoes` a
INNER JOIN `responsabilidade_modulos` m ON m.`CODMODULO` = a.`CODMODULO` AND m.`CODIGO` = 'CONTROLE'
LEFT JOIN `responsabilidade_tipos` t ON t.`CODTIPORESPONSABILIDADE` = a.`CODTIPORESPONSABILIDADE`
LEFT JOIN `usuarios` u ON u.`codusuario` = a.`CODUSUARIO`
LEFT JOIN `grupos` g ON g.`codgrupo` = a.`CODGRUPO`
WHERE a.`ENTIDADE` = 'documentos'
  AND a.`ATIVO` = 1
  AND a.`VALIDO_DE` <= CURRENT_DATE
  AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE` >= CURRENT_DATE);

INSERT IGNORE INTO `registros_migracao`
 (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
 ('CONTROLE_DOCUMENTOS_RESPONSABILIDADES_127',
  'gmodelo_dbp.responsabilidade_atribuicoes; responsabilidade_modulos.CONTROLE',
  'gmodelo_dbp.vw_responsabilidade_controle_documentos',
  'ESTRUTURA','1.0','CONCLUIDA',0,NOW(),NOW(),
  JSON_OBJECT('entidade','documentos','registro_id','CODDOCUMENTO','origem_atribuicao','DIRETA','exclusao','historico_ATIVO_0'));
