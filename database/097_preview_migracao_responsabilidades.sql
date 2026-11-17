-- Preview somente de leitura dos vínculos legados.
-- Não insere dados em responsabilidade_atribuicoes.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_responsabilidade_migracao_preview` AS
SELECT
    'legislacaoxsituacao' AS `ORIGEM_TABELA`,
    'CODUSUARIO' AS `ORIGEM_CAMPO`,
    CAST(s.`CODSITUACAO` AS CHAR) AS `ORIGEM_ID`,
    'REQUISITOS_LEGAIS' AS `MODULO_CODIGO`,
    'REQUISITO_LEGAL' AS `TIPO_CODIGO`,
    'USUARIO' AS `TIPO_DESTINO`,
    s.`CODUSUARIO` AS `CODUSUARIO`,
    NULL AS `CODGRUPO`,
    CASE WHEN u.`codusuario` IS NULL THEN 'USUARIO_INEXISTENTE' WHEN u.`ativo` = 0 THEN 'USUARIO_INATIVO_HISTORICO' ELSE 'CANDIDATO_ATIVO' END AS `SITUACAO_PREVIEW`
FROM `legislacaoxsituacao` s
LEFT JOIN `usuarios` u ON u.`codusuario` = s.`CODUSUARIO`
WHERE s.`CODUSUARIO` IS NOT NULL
UNION ALL
SELECT
    'planodeacao', 'codusuario', CAST(p.`codplanoacao` AS CHAR),
    'A_CONFIRMAR', 'REQUISITO_LEGAL', 'USUARIO', p.`codusuario`, NULL,
    CASE WHEN u.`codusuario` IS NULL THEN 'USUARIO_INEXISTENTE' WHEN u.`ativo` = 0 THEN 'USUARIO_INATIVO_HISTORICO' ELSE 'MODULO_A_CONFIRMAR' END
FROM `planodeacao` p
LEFT JOIN `usuarios` u ON u.`codusuario` = p.`codusuario`
WHERE p.`codusuario` IS NOT NULL AND p.`codusuario` <> 0
UNION ALL
SELECT
    'li_licencas', 'codusuario', CAST(l.`CODLICENCA` AS CHAR),
    'LICENCAS', 'LICENCA_RESPONSAVEL', 'USUARIO', l.`codusuario`, NULL,
    CASE WHEN u.`codusuario` IS NULL THEN 'USUARIO_INEXISTENTE' WHEN u.`ativo` = 0 THEN 'USUARIO_INATIVO_HISTORICO' ELSE 'CANDIDATO_ATIVO' END
FROM `li_licencas` l
LEFT JOIN `usuarios` u ON u.`codusuario` = l.`codusuario`
WHERE l.`codusuario` IS NOT NULL AND l.`codusuario` <> 0
UNION ALL
SELECT
    'li_licencas', 'codgrupo', CAST(l.`CODLICENCA` AS CHAR),
    'LICENCAS', 'LICENCA_RESPONSAVEL', 'GRUPO', NULL, l.`codgrupo`,
    CASE WHEN g.`codgrupo` IS NULL THEN 'GRUPO_INEXISTENTE' WHEN g.`ativo` = 0 THEN 'GRUPO_INATIVO_HISTORICO' ELSE 'CANDIDATO_ATIVO' END
FROM `li_licencas` l
LEFT JOIN `grupos` g ON g.`codgrupo` = l.`codgrupo`
WHERE l.`codgrupo` IS NOT NULL AND l.`codgrupo` <> 0
UNION ALL
SELECT
    'li_licencas', 'codgrupo_validade', CAST(l.`CODLICENCA` AS CHAR),
    'LICENCAS', 'LICENCA_VALIDADE', 'GRUPO', NULL, l.`codgrupo_validade`,
    CASE WHEN g.`codgrupo` IS NULL THEN 'GRUPO_INEXISTENTE' WHEN g.`ativo` = 0 THEN 'GRUPO_INATIVO_HISTORICO' ELSE 'CANDIDATO_ATIVO' END
FROM `li_licencas` l
LEFT JOIN `grupos` g ON g.`codgrupo` = l.`codgrupo_validade`
WHERE l.`codgrupo_validade` IS NOT NULL AND l.`codgrupo_validade` <> 0
UNION ALL
SELECT
    'li_licencas', 'codgrupo_reacao', CAST(l.`CODLICENCA` AS CHAR),
    'LICENCAS', 'LICENCA_REACAO', 'GRUPO', NULL, l.`codgrupo_reacao`,
    CASE WHEN g.`codgrupo` IS NULL THEN 'GRUPO_INEXISTENTE' WHEN g.`ativo` = 0 THEN 'GRUPO_INATIVO_HISTORICO' ELSE 'CANDIDATO_ATIVO' END
FROM `li_licencas` l
LEFT JOIN `grupos` g ON g.`codgrupo` = l.`codgrupo_reacao`
WHERE l.`codgrupo_reacao` IS NOT NULL AND l.`codgrupo_reacao` <> 0
UNION ALL
SELECT
    'li_licencas', 'codgrupo_protocolo', CAST(l.`CODLICENCA` AS CHAR),
    'LICENCAS', 'LICENCA_PROTOCOLO', 'GRUPO', NULL, l.`codgrupo_protocolo`,
    CASE WHEN g.`codgrupo` IS NULL THEN 'GRUPO_INEXISTENTE' WHEN g.`ativo` = 0 THEN 'GRUPO_INATIVO_HISTORICO' ELSE 'CANDIDATO_ATIVO' END
FROM `li_licencas` l
LEFT JOIN `grupos` g ON g.`codgrupo` = l.`codgrupo_protocolo`
WHERE l.`codgrupo_protocolo` IS NOT NULL AND l.`codgrupo_protocolo` <> 0
UNION ALL
SELECT
    'li_licencas', 'codgrupo_acesso', CAST(l.`CODLICENCA` AS CHAR),
    'LICENCAS', 'LICENCA_ACESSO', 'GRUPO', NULL, l.`codgrupo_acesso`,
    CASE WHEN g.`codgrupo` IS NULL THEN 'GRUPO_INEXISTENTE' WHEN g.`ativo` = 0 THEN 'GRUPO_INATIVO_HISTORICO' ELSE 'CANDIDATO_ATIVO' END
FROM `li_licencas` l
LEFT JOIN `grupos` g ON g.`codgrupo` = l.`codgrupo_acesso`
WHERE l.`codgrupo_acesso` IS NOT NULL AND l.`codgrupo_acesso` <> 0
UNION ALL
SELECT
    'mm_monitoramento', 'codusuario', CAST(m.`CODMONITORAMENTO` AS CHAR),
    'MEDICAO_MONITORAMENTO', 'MONITORAMENTO', 'USUARIO', m.`codusuario`, NULL,
    CASE WHEN u.`codusuario` IS NULL THEN 'USUARIO_INEXISTENTE' WHEN u.`ativo` = 0 THEN 'USUARIO_INATIVO_HISTORICO' ELSE 'CANDIDATO_ATIVO' END
FROM `mm_monitoramento` m
LEFT JOIN `usuarios` u ON u.`codusuario` = m.`codusuario`
WHERE m.`codusuario` IS NOT NULL AND m.`codusuario` <> 0
UNION ALL
SELECT
    'fo_funcionarioxtiposxtreinamentos', 'codusuario', CAST(f.`codigo` AS CHAR),
    'TREINAMENTO', 'TREINAMENTO', 'USUARIO', f.`codusuario`, NULL,
    CASE WHEN u.`codusuario` IS NULL THEN 'USUARIO_INEXISTENTE' WHEN u.`ativo` = 0 THEN 'USUARIO_INATIVO_HISTORICO' ELSE 'CANDIDATO_ATIVO' END
FROM `fo_funcionarioxtiposxtreinamentos` f
LEFT JOIN `usuarios` u ON u.`codusuario` = f.`codusuario`
WHERE f.`codusuario` IS NOT NULL AND f.`codusuario` <> 0
UNION ALL
SELECT
    'fo_fornecedorxusuario', 'codusuario', CAST(f.`CODFORNECEDORXUSUARIO` AS CHAR),
    'HOMOLOGACAO', 'HOMOLOGACAO_FORNECEDOR', 'USUARIO', f.`codusuario`, NULL,
    CASE WHEN u.`codusuario` IS NULL THEN 'USUARIO_INEXISTENTE' WHEN u.`ativo` = 0 THEN 'USUARIO_INATIVO_HISTORICO' ELSE 'CANDIDATO_ATIVO' END
FROM `fo_fornecedorxusuario` f
LEFT JOIN `usuarios` u ON u.`codusuario` = f.`codusuario`
WHERE f.`codusuario` IS NOT NULL AND f.`codusuario` <> 0
UNION ALL
SELECT
    'fo_fornecedorxcriterios', 'codusuario', CAST(f.`codfornecedorxcriterios` AS CHAR),
    'HOMOLOGACAO', 'HOMOLOGACAO_DOCUMENTACAO', 'USUARIO', f.`codusuario`, NULL,
    CASE WHEN u.`codusuario` IS NULL THEN 'USUARIO_INEXISTENTE' WHEN u.`ativo` = 0 THEN 'USUARIO_INATIVO_HISTORICO' ELSE 'CANDIDATO_ATIVO' END
FROM `fo_fornecedorxcriterios` f
LEFT JOIN `usuarios` u ON u.`codusuario` = f.`codusuario`
WHERE f.`codusuario` IS NOT NULL AND f.`codusuario` <> 0
UNION ALL
SELECT
    'fo_fornecedorxcriterios', 'codusuario_fornecedor', CAST(f.`codfornecedorxcriterios` AS CHAR),
    'HOMOLOGACAO', 'HOMOLOGACAO_FORNECEDOR', 'USUARIO', f.`codusuario_fornecedor`, NULL,
    CASE WHEN u.`codusuario` IS NULL THEN 'USUARIO_INEXISTENTE' WHEN u.`ativo` = 0 THEN 'USUARIO_INATIVO_HISTORICO' ELSE 'CANDIDATO_ATIVO' END
FROM `fo_fornecedorxcriterios` f
LEFT JOIN `usuarios` u ON u.`codusuario` = f.`codusuario_fornecedor`
WHERE f.`codusuario_fornecedor` IS NOT NULL AND f.`codusuario_fornecedor` <> 0;

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODELO_RESPONSABILIDADES_PREVIEW_097',
     'gmodelo_dbp vínculos legados',
     'gmodelo_dbp.vw_responsabilidade_migracao_preview',
     'ESTRUTURA', '1.0.0', 'CONCLUIDA', 0,
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
     JSON_OBJECT('somente_leitura', TRUE, 'atribuicoes_criadas', 0))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`),
    `concluida_em` = VALUES(`concluida_em`),
    `detalhes` = VALUES(`detalhes`);
