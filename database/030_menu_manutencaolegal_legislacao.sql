-- Green Portal - cadastro da transacao Manutencao Legal / Legislacao.
-- O menu e mantido no banco do tenant Modelo; os dados ficam em
-- glegislacao_dbp.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO `menus`
    (`codmenu_pai`, `modulo`, `codigo`, `label`, `label_pt`, `label_en`, `label_es`, `url`, `nivel`, `ordem`, `ativo`)
SELECT `codmenu`, 'MANUTENCAO_LEGAL', 'LEGISLACAO', CONVERT(0x4C656769736C61C3A7C3A36F USING utf8mb4), CONVERT(0x4C656769736C61C3A7C3A36F USING utf8mb4), 'Legislation', CONVERT(0x4C656769736C616369C3B3 USING utf8mb4), '/principal/manutencaolegal_legislacao.php', 2, 10, 1
  FROM `menus`
 WHERE `codigo` = 'MANUTENCAO_LEGAL'
ON DUPLICATE KEY UPDATE
    `codmenu_pai` = VALUES(`codmenu_pai`), `modulo` = VALUES(`modulo`),
    `label` = VALUES(`label`), `label_pt` = VALUES(`label_pt`),
    `label_en` = VALUES(`label_en`), `label_es` = VALUES(`label_es`),
    `url` = VALUES(`url`), `nivel` = VALUES(`nivel`), `ordem` = VALUES(`ordem`), `ativo` = VALUES(`ativo`);

INSERT INTO `perfil_menus`
    (`codperfil`, `codmenu`, `pode_incluir`, `pode_visualizar`, `pode_editar`,
     `pode_excluir`, `pode_exportar_excel`, `pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`,
       IF(p.`codigo` = 'ADMINISTRADOR', 1, 0), 1,
       IF(p.`codigo` = 'ADMINISTRADOR', 1, 0),
       IF(p.`codigo` = 'ADMINISTRADOR', 1, 0),
       IF(p.`codigo` = 'ADMINISTRADOR', 1, 0), 0
  FROM `perfis` p
  CROSS JOIN `menus` m
 WHERE m.`codigo` = 'LEGISLACAO'
ON DUPLICATE KEY UPDATE
    `pode_incluir` = VALUES(`pode_incluir`), `pode_visualizar` = VALUES(`pode_visualizar`),
    `pode_editar` = VALUES(`pode_editar`), `pode_excluir` = VALUES(`pode_excluir`),
    `pode_exportar_excel` = VALUES(`pode_exportar_excel`), `pode_gerar_pdf` = VALUES(`pode_gerar_pdf`);

-- A aplicacao utiliza a matriz oficial permissoes para carregar o perfil na
-- sessao. O registro legado perfil_menus e mantido por compatibilidade.
INSERT INTO `permissoes`
    (`codperfil`, `codmenu`, `pode_incluir`, `pode_visualizar`, `pode_editar`,
     `pode_excluir`, `pode_exportar_excel`, `pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`,
       IF(p.`codigo` = 'ADMINISTRADOR', 1, 0), 1,
       IF(p.`codigo` = 'ADMINISTRADOR', 1, 0),
       IF(p.`codigo` = 'ADMINISTRADOR', 1, 0),
       IF(p.`codigo` = 'ADMINISTRADOR', 1, 0), 0
  FROM `perfis` p
  CROSS JOIN `menus` m
 WHERE m.`codigo` = 'LEGISLACAO'
ON DUPLICATE KEY UPDATE
    `pode_incluir` = VALUES(`pode_incluir`), `pode_visualizar` = VALUES(`pode_visualizar`),
    `pode_editar` = VALUES(`pode_editar`), `pode_excluir` = VALUES(`pode_excluir`),
    `pode_exportar_excel` = VALUES(`pode_exportar_excel`), `pode_gerar_pdf` = VALUES(`pode_gerar_pdf`);

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`,
     `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
SELECT 'MENU_MANUTENCAO_LEGAL_LEGISLACAO', 'configuracao Green Portal',
       'gmodelo_dbp.menus/perfil_menus', 'ESTRUTURA', '1.0.0', 'CONCLUIDA',
       1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
       JSON_OBJECT('codigo_menu', 'LEGISLACAO', 'url', '/principal/manutencaolegal_legislacao.php', 'banco_dados', 'glegislacao_dbp')
ON DUPLICATE KEY UPDATE concluida_em = VALUES(concluida_em), status = VALUES(status), detalhes = VALUES(detalhes);
