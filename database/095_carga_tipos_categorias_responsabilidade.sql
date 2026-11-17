-- Carga inicial do catálogo de tipos e categorias de responsabilidades.
-- Destino atual: gmodelo_dbp, empresa Modelo.
-- Não cria atribuições nem migra dados operacionais.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO `responsabilidade_tipos`
    (`CODMODULO`, `CODIGO`, `NOME`, `DESCRICAO`, `ATIVO`, `ORDEM`)
SELECT m.`CODMODULO`, x.`CODIGO`, x.`NOME`, x.`DESCRICAO`, 1, x.`ORDEM`
FROM (
    SELECT 'REQUISITOS_LEGAIS' AS `MODULO`, 'REQUISITO_LEGAL' AS `CODIGO`, 'Requisito legal' AS `NOME`, 'Responsabilidade relacionada a requisito legal.' AS `DESCRICAO`, 10 AS `ORDEM`
    UNION ALL SELECT 'LICENCAS', 'LICENCA', 'Licença', 'Responsabilidade relacionada a licença.', 10
    UNION ALL SELECT 'MEDICAO_MONITORAMENTO', 'MEDICAO', 'Medição', 'Responsabilidade relacionada a medição.', 10
    UNION ALL SELECT 'MEDICAO_MONITORAMENTO', 'MONITORAMENTO', 'Monitoramento', 'Responsabilidade relacionada a monitoramento.', 20
    UNION ALL SELECT 'TREINAMENTO', 'TREINAMENTO', 'Treinamento', 'Responsabilidade relacionada a treinamento.', 10
    UNION ALL SELECT 'INFRAESTRUTURA', 'INFRAESTRUTURA', 'Infraestrutura', 'Responsabilidade relacionada a infraestrutura.', 10
    UNION ALL SELECT 'CONTROLE', 'CONTROLE', 'Controle', 'Responsabilidade relacionada a controle.', 10
    UNION ALL SELECT 'HOMOLOGACAO', 'HOMOLOGACAO', 'Homologação', 'Responsabilidade relacionada a homologação.', 10
) x
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = x.`MODULO`
ON DUPLICATE KEY UPDATE
    `NOME` = VALUES(`NOME`),
    `DESCRICAO` = VALUES(`DESCRICAO`),
    `ORDEM` = VALUES(`ORDEM`),
    `ATIVO` = 1;

INSERT INTO `responsabilidade_categorias`
    (`CODCATEGORIAORIGEM`, `SCHEMAORIGEM`, `CODIGO`, `NOME`, `ATIVO`, `ORDEM`, `SINCRONIZADO_EM`)
SELECT
    c.`CODCATEGORIALEGISLACAO`,
    'glegislacao_dbp',
    CONCAT('LEG_', c.`CODCATEGORIALEGISLACAO`),
    c.`NOME`,
    1,
    c.`CODCATEGORIALEGISLACAO`,
    CURRENT_TIMESTAMP
FROM `glegislacao_dbp`.`legislacao_categoria` c
ON DUPLICATE KEY UPDATE
    `NOME` = VALUES(`NOME`),
    `ATIVO` = 1,
    `ORDEM` = VALUES(`ORDEM`),
    `SINCRONIZADO_EM` = CURRENT_TIMESTAMP;

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODELO_RESPONSABILIDADES_TIPOS_CATEGORIAS_095',
     'glegislacao_dbp.legislacao_categoria e catalogo inicial da Fase 1',
     'gmodelo_dbp.responsabilidade_tipos/responsabilidade_categorias',
     'DADOS', '1.0.0', 'CONCLUIDA',
     (SELECT COUNT(*) FROM `responsabilidade_tipos`) + (SELECT COUNT(*) FROM `responsabilidade_categorias`),
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
     JSON_OBJECT('tipos_base', (SELECT COUNT(*) FROM `responsabilidade_tipos`),
                 'categorias_origem', (SELECT COUNT(*) FROM `glegislacao_dbp`.`legislacao_categoria`),
                 'atribuicoes_criadas', 0))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`),
    `quantidade_registros` = VALUES(`quantidade_registros`),
    `concluida_em` = VALUES(`concluida_em`),
    `detalhes` = VALUES(`detalhes`);
