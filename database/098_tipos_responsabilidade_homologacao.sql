-- Tipos específicos para responsabilidades de Homologação.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO `responsabilidade_tipos`
    (`CODMODULO`, `CODIGO`, `NOME`, `DESCRICAO`, `ATIVO`, `ORDEM`)
SELECT m.`CODMODULO`, x.`CODIGO`, x.`NOME`, x.`DESCRICAO`, 1, x.`ORDEM`
FROM (
    SELECT 'HOMOLOGACAO_FORNECEDOR' AS `CODIGO`, 'Responsável pelo fornecedor' AS `NOME`, 'Usuário responsável pelo fornecedor na homologação.' AS `DESCRICAO`, 10 AS `ORDEM`
    UNION ALL SELECT 'HOMOLOGACAO_DOCUMENTACAO', 'Responsável pela documentação', 'Usuário responsável pela documentação do fornecedor.', 20
) x
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = 'HOMOLOGACAO'
ON DUPLICATE KEY UPDATE
    `NOME` = VALUES(`NOME`),
    `DESCRICAO` = VALUES(`DESCRICAO`),
    `ORDEM` = VALUES(`ORDEM`),
    `ATIVO` = 1;

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODELO_RESPONSABILIDADES_TIPOS_HOMOLOGACAO_098',
     'gmodelo_dbp.fo_fornecedorxusuario/fo_fornecedorxcriterios',
     'gmodelo_dbp.responsabilidade_tipos',
     'ESTRUTURA', '1.0.0', 'CONCLUIDA', 2,
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
     JSON_OBJECT('atribuicoes_criadas', 0))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`),
    `concluida_em` = VALUES(`concluida_em`),
    `detalhes` = VALUES(`detalhes`);
