-- Complementa os tipos de responsabilidade das licenças.
-- Os tipos correspondem aos campos de grupo existentes em li_licencas.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO `responsabilidade_tipos`
    (`CODMODULO`, `CODIGO`, `NOME`, `DESCRICAO`, `ATIVO`, `ORDEM`)
SELECT m.`CODMODULO`, x.`CODIGO`, x.`NOME`, x.`DESCRICAO`, 1, x.`ORDEM`
FROM (
    SELECT 'LICENCA_RESPONSAVEL' AS `CODIGO`, 'Responsável pela licença' AS `NOME`, 'Grupo ou usuário responsável pela licença.' AS `DESCRICAO`, 10 AS `ORDEM`
    UNION ALL SELECT 'LICENCA_VALIDADE', 'Responsável pela validade', 'Grupo responsável pelo controle de validade da licença.', 20
    UNION ALL SELECT 'LICENCA_REACAO', 'Responsável pela reação', 'Grupo responsável pela reação ou renovação da licença.', 30
    UNION ALL SELECT 'LICENCA_PROTOCOLO', 'Responsável pelo protocolo', 'Grupo responsável pelo protocolo da licença.', 40
    UNION ALL SELECT 'LICENCA_ACESSO', 'Responsável pelo acesso', 'Grupo responsável pelo acesso ou consulta da licença.', 50
) x
INNER JOIN `responsabilidade_modulos` m ON m.`CODIGO` = 'LICENCAS'
ON DUPLICATE KEY UPDATE
    `NOME` = VALUES(`NOME`),
    `DESCRICAO` = VALUES(`DESCRICAO`),
    `ORDEM` = VALUES(`ORDEM`),
    `ATIVO` = 1;

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODELO_RESPONSABILIDADES_TIPOS_LICENCAS_096',
     'gmodelo_dbp.li_licencas campos codgrupo*',
     'gmodelo_dbp.responsabilidade_tipos',
     'ESTRUTURA', '1.0.0', 'CONCLUIDA',
     (SELECT COUNT(*) FROM `responsabilidade_tipos` WHERE `CODIGO` LIKE 'LICENCA_%'),
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
     JSON_OBJECT('campos_origem', JSON_ARRAY('codgrupo', 'codgrupo_validade', 'codgrupo_reacao', 'codgrupo_protocolo', 'codgrupo_acesso'), 'atribuicoes_criadas', 0))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`),
    `quantidade_registros` = VALUES(`quantidade_registros`),
    `concluida_em` = VALUES(`concluida_em`),
    `detalhes` = VALUES(`detalhes`);
