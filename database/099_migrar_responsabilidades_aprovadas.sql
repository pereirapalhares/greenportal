-- Migra apenas os vínculos legados aprovados e sem ambiguidade.
-- Usuários/grupos inexistentes e o plano de ação ficam fora desta carga.
-- Usuários inativos são preservados com ATIVO = 0 para manter o histórico.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

SET @data_legado = '1900-01-01';

INSERT INTO `responsabilidade_atribuicoes`
    (`CODMODULO`, `ORIGEM`, `CODTIPORESPONSABILIDADE`, `ENTIDADE`, `REGISTRO_ID`, `TIPO_DESTINO`, `CODUSUARIO`, `VALIDO_DE`, `ATIVO`, `OBSERVACAO`)
SELECT m.`CODMODULO`, 'DIRETA', t.`CODTIPORESPONSABILIDADE`, 'legislacaoxsituacao', CAST(s.`CODSITUACAO` AS CHAR), 'USUARIO', s.`CODUSUARIO`, @data_legado, IF(u.`ativo` = 1, 1, 0), 'Migrado de legislacaoxsituacao.CODUSUARIO'
FROM `legislacaoxsituacao` s
INNER JOIN `usuarios` u ON u.`codusuario` = s.`CODUSUARIO`
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = 'REQUISITOS_LEGAIS'
INNER JOIN `responsabilidade_tipos` t ON t.`CODMODULO` = m.`CODMODULO` AND t.`CODIGO` = 'REQUISITO_LEGAL'
WHERE s.`CODUSUARIO` IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `responsabilidade_atribuicoes` a WHERE a.`CODMODULO` = m.`CODMODULO` AND a.`CODTIPORESPONSABILIDADE` = t.`CODTIPORESPONSABILIDADE` AND a.`ENTIDADE` = 'legislacaoxsituacao' AND a.`REGISTRO_ID` = CAST(s.`CODSITUACAO` AS CHAR) AND a.`TIPO_DESTINO` = 'USUARIO' AND a.`CODUSUARIO` = s.`CODUSUARIO`);

INSERT INTO `responsabilidade_atribuicoes`
    (`CODMODULO`, `ORIGEM`, `CODTIPORESPONSABILIDADE`, `ENTIDADE`, `REGISTRO_ID`, `TIPO_DESTINO`, `CODUSUARIO`, `VALIDO_DE`, `ATIVO`, `OBSERVACAO`)
SELECT m.`CODMODULO`, 'DIRETA', t.`CODTIPORESPONSABILIDADE`, 'li_licencas', CAST(l.`CODLICENCA` AS CHAR), 'USUARIO', l.`codusuario`, @data_legado, IF(u.`ativo` = 1, 1, 0), 'Migrado de li_licencas.codusuario'
FROM `li_licencas` l
INNER JOIN `usuarios` u ON u.`codusuario` = l.`codusuario`
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = 'LICENCAS'
INNER JOIN `responsabilidade_tipos` t ON t.`CODMODULO` = m.`CODMODULO` AND t.`CODIGO` = 'LICENCA_RESPONSAVEL'
WHERE l.`codusuario` IS NOT NULL AND l.`codusuario` <> 0
  AND NOT EXISTS (SELECT 1 FROM `responsabilidade_atribuicoes` a WHERE a.`CODMODULO` = m.`CODMODULO` AND a.`CODTIPORESPONSABILIDADE` = t.`CODTIPORESPONSABILIDADE` AND a.`ENTIDADE` = 'li_licencas' AND a.`REGISTRO_ID` = CAST(l.`CODLICENCA` AS CHAR) AND a.`TIPO_DESTINO` = 'USUARIO' AND a.`CODUSUARIO` = l.`codusuario`);

INSERT INTO `responsabilidade_atribuicoes`
    (`CODMODULO`, `ORIGEM`, `CODTIPORESPONSABILIDADE`, `ENTIDADE`, `REGISTRO_ID`, `TIPO_DESTINO`, `CODGRUPO`, `VALIDO_DE`, `ATIVO`, `OBSERVACAO`)
SELECT m.`CODMODULO`, 'DIRETA', t.`CODTIPORESPONSABILIDADE`, 'li_licencas', CAST(l.`CODLICENCA` AS CHAR), 'GRUPO', l.`codgrupo`, @data_legado, IF(g.`ativo` = 1, 1, 0), 'Migrado de li_licencas.codgrupo'
FROM `li_licencas` l
INNER JOIN `grupos` g ON g.`codgrupo` = l.`codgrupo`
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = 'LICENCAS'
INNER JOIN `responsabilidade_tipos` t ON t.`CODMODULO` = m.`CODMODULO` AND t.`CODIGO` = 'LICENCA_RESPONSAVEL'
WHERE l.`codgrupo` IS NOT NULL AND l.`codgrupo` <> 0
  AND NOT EXISTS (SELECT 1 FROM `responsabilidade_atribuicoes` a WHERE a.`CODMODULO` = m.`CODMODULO` AND a.`CODTIPORESPONSABILIDADE` = t.`CODTIPORESPONSABILIDADE` AND a.`ENTIDADE` = 'li_licencas' AND a.`REGISTRO_ID` = CAST(l.`CODLICENCA` AS CHAR) AND a.`TIPO_DESTINO` = 'GRUPO' AND a.`CODGRUPO` = l.`codgrupo`);

INSERT INTO `responsabilidade_atribuicoes`
    (`CODMODULO`, `ORIGEM`, `CODTIPORESPONSABILIDADE`, `ENTIDADE`, `REGISTRO_ID`, `TIPO_DESTINO`, `CODGRUPO`, `VALIDO_DE`, `ATIVO`, `OBSERVACAO`)
SELECT m.`CODMODULO`, 'DIRETA', t.`CODTIPORESPONSABILIDADE`, 'li_licencas', CAST(l.`CODLICENCA` AS CHAR), 'GRUPO', l.`codgrupo_validade`, @data_legado, IF(g.`ativo` = 1, 1, 0), 'Migrado de li_licencas.codgrupo_validade'
FROM `li_licencas` l
INNER JOIN `grupos` g ON g.`codgrupo` = l.`codgrupo_validade`
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = 'LICENCAS'
INNER JOIN `responsabilidade_tipos` t ON t.`CODMODULO` = m.`CODMODULO` AND t.`CODIGO` = 'LICENCA_VALIDADE'
WHERE l.`codgrupo_validade` IS NOT NULL AND l.`codgrupo_validade` <> 0
  AND NOT EXISTS (SELECT 1 FROM `responsabilidade_atribuicoes` a WHERE a.`CODMODULO` = m.`CODMODULO` AND a.`CODTIPORESPONSABILIDADE` = t.`CODTIPORESPONSABILIDADE` AND a.`ENTIDADE` = 'li_licencas' AND a.`REGISTRO_ID` = CAST(l.`CODLICENCA` AS CHAR) AND a.`TIPO_DESTINO` = 'GRUPO' AND a.`CODGRUPO` = l.`codgrupo_validade`);

INSERT INTO `responsabilidade_atribuicoes`
    (`CODMODULO`, `ORIGEM`, `CODTIPORESPONSABILIDADE`, `ENTIDADE`, `REGISTRO_ID`, `TIPO_DESTINO`, `CODGRUPO`, `VALIDO_DE`, `ATIVO`, `OBSERVACAO`)
SELECT m.`CODMODULO`, 'DIRETA', t.`CODTIPORESPONSABILIDADE`, 'li_licencas', CAST(l.`CODLICENCA` AS CHAR), 'GRUPO', l.`codgrupo_reacao`, @data_legado, IF(g.`ativo` = 1, 1, 0), 'Migrado de li_licencas.codgrupo_reacao'
FROM `li_licencas` l
INNER JOIN `grupos` g ON g.`codgrupo` = l.`codgrupo_reacao`
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = 'LICENCAS'
INNER JOIN `responsabilidade_tipos` t ON t.`CODMODULO` = m.`CODMODULO` AND t.`CODIGO` = 'LICENCA_REACAO'
WHERE l.`codgrupo_reacao` IS NOT NULL AND l.`codgrupo_reacao` <> 0
  AND NOT EXISTS (SELECT 1 FROM `responsabilidade_atribuicoes` a WHERE a.`CODMODULO` = m.`CODMODULO` AND a.`CODTIPORESPONSABILIDADE` = t.`CODTIPORESPONSABILIDADE` AND a.`ENTIDADE` = 'li_licencas' AND a.`REGISTRO_ID` = CAST(l.`CODLICENCA` AS CHAR) AND a.`TIPO_DESTINO` = 'GRUPO' AND a.`CODGRUPO` = l.`codgrupo_reacao`);

INSERT INTO `responsabilidade_atribuicoes`
    (`CODMODULO`, `ORIGEM`, `CODTIPORESPONSABILIDADE`, `ENTIDADE`, `REGISTRO_ID`, `TIPO_DESTINO`, `CODGRUPO`, `VALIDO_DE`, `ATIVO`, `OBSERVACAO`)
SELECT m.`CODMODULO`, 'DIRETA', t.`CODTIPORESPONSABILIDADE`, 'li_licencas', CAST(l.`CODLICENCA` AS CHAR), 'GRUPO', l.`codgrupo_protocolo`, @data_legado, IF(g.`ativo` = 1, 1, 0), 'Migrado de li_licencas.codgrupo_protocolo'
FROM `li_licencas` l
INNER JOIN `grupos` g ON g.`codgrupo` = l.`codgrupo_protocolo`
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = 'LICENCAS'
INNER JOIN `responsabilidade_tipos` t ON t.`CODMODULO` = m.`CODMODULO` AND t.`CODIGO` = 'LICENCA_PROTOCOLO'
WHERE l.`codgrupo_protocolo` IS NOT NULL AND l.`codgrupo_protocolo` <> 0
  AND NOT EXISTS (SELECT 1 FROM `responsabilidade_atribuicoes` a WHERE a.`CODMODULO` = m.`CODMODULO` AND a.`CODTIPORESPONSABILIDADE` = t.`CODTIPORESPONSABILIDADE` AND a.`ENTIDADE` = 'li_licencas' AND a.`REGISTRO_ID` = CAST(l.`CODLICENCA` AS CHAR) AND a.`TIPO_DESTINO` = 'GRUPO' AND a.`CODGRUPO` = l.`codgrupo_protocolo`);

INSERT INTO `responsabilidade_atribuicoes`
    (`CODMODULO`, `ORIGEM`, `CODTIPORESPONSABILIDADE`, `ENTIDADE`, `REGISTRO_ID`, `TIPO_DESTINO`, `CODGRUPO`, `VALIDO_DE`, `ATIVO`, `OBSERVACAO`)
SELECT m.`CODMODULO`, 'DIRETA', t.`CODTIPORESPONSABILIDADE`, 'li_licencas', CAST(l.`CODLICENCA` AS CHAR), 'GRUPO', l.`codgrupo_acesso`, @data_legado, IF(g.`ativo` = 1, 1, 0), 'Migrado de li_licencas.codgrupo_acesso'
FROM `li_licencas` l
INNER JOIN `grupos` g ON g.`codgrupo` = l.`codgrupo_acesso`
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = 'LICENCAS'
INNER JOIN `responsabilidade_tipos` t ON t.`CODTIPORESPONSABILIDADE` = (SELECT t2.`CODTIPORESPONSABILIDADE` FROM `responsabilidade_tipos` t2 INNER JOIN `responsabilidade_modulos` m2 ON m2.`CODMODULO` = t2.`CODMODULO` WHERE m2.`CODIGO` = 'LICENCAS' AND t2.`CODIGO` = 'LICENCA_ACESSO')
WHERE l.`codgrupo_acesso` IS NOT NULL AND l.`codgrupo_acesso` <> 0
  AND NOT EXISTS (SELECT 1 FROM `responsabilidade_atribuicoes` a WHERE a.`CODMODULO` = m.`CODMODULO` AND a.`CODTIPORESPONSABILIDADE` = t.`CODTIPORESPONSABILIDADE` AND a.`ENTIDADE` = 'li_licencas' AND a.`REGISTRO_ID` = CAST(l.`CODLICENCA` AS CHAR) AND a.`TIPO_DESTINO` = 'GRUPO' AND a.`CODGRUPO` = l.`codgrupo_acesso`);

INSERT INTO `responsabilidade_atribuicoes`
    (`CODMODULO`, `ORIGEM`, `CODTIPORESPONSABILIDADE`, `ENTIDADE`, `REGISTRO_ID`, `TIPO_DESTINO`, `CODUSUARIO`, `VALIDO_DE`, `ATIVO`, `OBSERVACAO`)
SELECT m.`CODMODULO`, 'DIRETA', t.`CODTIPORESPONSABILIDADE`, 'mm_monitoramento', CAST(x.`CODMONITORAMENTO` AS CHAR), 'USUARIO', x.`codusuario`, @data_legado, IF(u.`ativo` = 1, 1, 0), 'Migrado de mm_monitoramento.codusuario'
FROM `mm_monitoramento` x
INNER JOIN `usuarios` u ON u.`codusuario` = x.`codusuario`
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = 'MEDICAO_MONITORAMENTO'
INNER JOIN `responsabilidade_tipos` t ON t.`CODMODULO` = m.`CODMODULO` AND t.`CODIGO` = 'MONITORAMENTO'
WHERE x.`codusuario` IS NOT NULL AND x.`codusuario` <> 0
  AND NOT EXISTS (SELECT 1 FROM `responsabilidade_atribuicoes` a WHERE a.`CODMODULO` = m.`CODMODULO` AND a.`CODTIPORESPONSABILIDADE` = t.`CODTIPORESPONSABILIDADE` AND a.`ENTIDADE` = 'mm_monitoramento' AND a.`REGISTRO_ID` = CAST(x.`CODMONITORAMENTO` AS CHAR) AND a.`TIPO_DESTINO` = 'USUARIO' AND a.`CODUSUARIO` = x.`codusuario`);

INSERT INTO `responsabilidade_atribuicoes`
    (`CODMODULO`, `ORIGEM`, `CODTIPORESPONSABILIDADE`, `ENTIDADE`, `REGISTRO_ID`, `TIPO_DESTINO`, `CODUSUARIO`, `VALIDO_DE`, `ATIVO`, `OBSERVACAO`)
SELECT m.`CODMODULO`, 'DIRETA', t.`CODTIPORESPONSABILIDADE`, 'fo_fornecedorxusuario', CAST(x.`CODFORNECEDORXUSUARIO` AS CHAR), 'USUARIO', x.`codusuario`, @data_legado, IF(u.`ativo` = 1, 1, 0), 'Migrado de fo_fornecedorxusuario.codusuario'
FROM `fo_fornecedorxusuario` x
INNER JOIN `usuarios` u ON u.`codusuario` = x.`codusuario`
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = 'HOMOLOGACAO'
INNER JOIN `responsabilidade_tipos` t ON t.`CODMODULO` = m.`CODMODULO` AND t.`CODIGO` = 'HOMOLOGACAO_FORNECEDOR'
WHERE x.`codusuario` IS NOT NULL AND x.`codusuario` <> 0
  AND NOT EXISTS (SELECT 1 FROM `responsabilidade_atribuicoes` a WHERE a.`CODMODULO` = m.`CODMODULO` AND a.`CODTIPORESPONSABILIDADE` = t.`CODTIPORESPONSABILIDADE` AND a.`ENTIDADE` = 'fo_fornecedorxusuario' AND a.`REGISTRO_ID` = CAST(x.`CODFORNECEDORXUSUARIO` AS CHAR) AND a.`TIPO_DESTINO` = 'USUARIO' AND a.`CODUSUARIO` = x.`codusuario`);

INSERT INTO `responsabilidade_atribuicoes`
    (`CODMODULO`, `ORIGEM`, `CODTIPORESPONSABILIDADE`, `ENTIDADE`, `REGISTRO_ID`, `TIPO_DESTINO`, `CODUSUARIO`, `VALIDO_DE`, `ATIVO`, `OBSERVACAO`)
SELECT m.`CODMODULO`, 'DIRETA', t.`CODTIPORESPONSABILIDADE`, 'fo_fornecedorxcriterios', CAST(x.`codfornecedorxcriterios` AS CHAR), 'USUARIO', x.`codusuario`, @data_legado, IF(u.`ativo` = 1, 1, 0), 'Migrado de fo_fornecedorxcriterios.codusuario'
FROM `fo_fornecedorxcriterios` x
INNER JOIN `usuarios` u ON u.`codusuario` = x.`codusuario`
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = 'HOMOLOGACAO'
INNER JOIN `responsabilidade_tipos` t ON t.`CODMODULO` = m.`CODMODULO` AND t.`CODIGO` = 'HOMOLOGACAO_DOCUMENTACAO'
WHERE x.`codusuario` IS NOT NULL AND x.`codusuario` <> 0
  AND NOT EXISTS (SELECT 1 FROM `responsabilidade_atribuicoes` a WHERE a.`CODMODULO` = m.`CODMODULO` AND a.`CODTIPORESPONSABILIDADE` = t.`CODTIPORESPONSABILIDADE` AND a.`ENTIDADE` = 'fo_fornecedorxcriterios' AND a.`REGISTRO_ID` = CAST(x.`codfornecedorxcriterios` AS CHAR) AND a.`TIPO_DESTINO` = 'USUARIO' AND a.`CODUSUARIO` = x.`codusuario`);

INSERT INTO `responsabilidade_atribuicoes`
    (`CODMODULO`, `ORIGEM`, `CODTIPORESPONSABILIDADE`, `ENTIDADE`, `REGISTRO_ID`, `TIPO_DESTINO`, `CODUSUARIO`, `VALIDO_DE`, `ATIVO`, `OBSERVACAO`)
SELECT m.`CODMODULO`, 'DIRETA', t.`CODTIPORESPONSABILIDADE`, 'fo_fornecedorxcriterios', CAST(x.`codfornecedorxcriterios` AS CHAR), 'USUARIO', x.`codusuario_fornecedor`, @data_legado, IF(u.`ativo` = 1, 1, 0), 'Migrado de fo_fornecedorxcriterios.codusuario_fornecedor'
FROM `fo_fornecedorxcriterios` x
INNER JOIN `usuarios` u ON u.`codusuario` = x.`codusuario_fornecedor`
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = 'HOMOLOGACAO'
INNER JOIN `responsabilidade_tipos` t ON t.`CODMODULO` = m.`CODMODULO` AND t.`CODIGO` = 'HOMOLOGACAO_FORNECEDOR'
WHERE x.`codusuario_fornecedor` IS NOT NULL AND x.`codusuario_fornecedor` <> 0
  AND NOT EXISTS (SELECT 1 FROM `responsabilidade_atribuicoes` a WHERE a.`CODMODULO` = m.`CODMODULO` AND a.`CODTIPORESPONSABILIDADE` = t.`CODTIPORESPONSABILIDADE` AND a.`ENTIDADE` = 'fo_fornecedorxcriterios' AND a.`REGISTRO_ID` = CAST(x.`codfornecedorxcriterios` AS CHAR) AND a.`TIPO_DESTINO` = 'USUARIO' AND a.`CODUSUARIO` = x.`codusuario_fornecedor`);

INSERT INTO `responsabilidade_atribuicoes`
    (`CODMODULO`, `ORIGEM`, `CODTIPORESPONSABILIDADE`, `ENTIDADE`, `REGISTRO_ID`, `TIPO_DESTINO`, `CODUSUARIO`, `VALIDO_DE`, `ATIVO`, `OBSERVACAO`)
SELECT m.`CODMODULO`, 'DIRETA', t.`CODTIPORESPONSABILIDADE`, 'fo_funcionarioxtiposxtreinamentos', CAST(x.`codigo` AS CHAR), 'USUARIO', x.`codusuario`, @data_legado, IF(u.`ativo` = 1, 1, 0), 'Migrado de fo_funcionarioxtiposxtreinamentos.codusuario'
FROM `fo_funcionarioxtiposxtreinamentos` x
INNER JOIN `usuarios` u ON u.`codusuario` = x.`codusuario`
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = 'TREINAMENTO'
INNER JOIN `responsabilidade_tipos` t ON t.`CODMODULO` = m.`CODMODULO` AND t.`CODIGO` = 'TREINAMENTO'
WHERE x.`codusuario` IS NOT NULL AND x.`codusuario` <> 0
  AND NOT EXISTS (SELECT 1 FROM `responsabilidade_atribuicoes` a WHERE a.`CODMODULO` = m.`CODMODULO` AND a.`CODTIPORESPONSABILIDADE` = t.`CODTIPORESPONSABILIDADE` AND a.`ENTIDADE` = 'fo_funcionarioxtiposxtreinamentos' AND a.`REGISTRO_ID` = CAST(x.`codigo` AS CHAR) AND a.`TIPO_DESTINO` = 'USUARIO' AND a.`CODUSUARIO` = x.`codusuario`);

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODELO_RESPONSABILIDADES_VINCULOS_099',
     'vw_responsabilidade_migracao_preview e tabelas legadas aprovadas',
     'gmodelo_dbp.responsabilidade_atribuicoes',
     'DADOS', '1.0.0', 'CONCLUIDA',
     (SELECT COUNT(*) FROM `responsabilidade_atribuicoes`),
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
     JSON_OBJECT('plano_de_acao_migrado', FALSE, 'infraestrutura_migrado', FALSE, 'controle_migrado', FALSE, 'usuarios_inativos_preservados', TRUE, 'data_padrao_legado', '1900-01-01'))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`),
    `quantidade_registros` = VALUES(`quantidade_registros`),
    `concluida_em` = VALUES(`concluida_em`),
    `detalhes` = VALUES(`detalhes`);
