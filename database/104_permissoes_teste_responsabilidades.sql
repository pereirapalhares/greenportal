-- Habilita o perfil GESTOR para a repetição dos testes de permissões.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

UPDATE `permissoes` p
INNER JOIN `menus` m ON m.`codmenu` = p.`codmenu`
SET p.`pode_incluir` = 1,
    p.`pode_visualizar` = 1,
    p.`pode_editar` = 1,
    p.`pode_excluir` = 1,
    p.`pode_exportar_excel` = 1,
    p.`pode_gerar_pdf` = 1
WHERE p.`codperfil` = 3
  AND m.`codigo` IN ('LICENCAS', 'REQUISITOS_LEGAIS');

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODELO_PERMISSOES_TESTE_RESPONSABILIDADES_104', 'gmodelo_dbp.permissoes/perfil GESTOR', 'gmodelo_dbp.permissoes', 'CONFIGURACAO', '1.0.0', 'CONCLUIDA', 2, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, JSON_OBJECT('transacoes', JSON_ARRAY('LICENCAS', 'REQUISITOS_LEGAIS'), 'objetivo', 'homologacao visual'))
ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `concluida_em` = VALUES(`concluida_em`), `detalhes` = VALUES(`detalhes`);
