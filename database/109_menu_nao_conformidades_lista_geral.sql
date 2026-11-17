-- Menu e transacao da lista geral de ocorrencias de nao conformidades.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

START TRANSACTION;

INSERT INTO `menus`
    (`codmenu_pai`, `modulo`, `codigo`, `label`, `label_pt`, `label_en`, `label_es`,
     `url`, `nivel`, `ordem`, `ativo`)
VALUES
    (NULL, 'NAO_CONFORMIDADES', 'NAO_CONFORMIDADES',
     CONVERT(0x4EC3A36F20436F6E666F726D696461646573 USING utf8mb4),
     CONVERT(0x4EC3A36F20436F6E666F726D696461646573 USING utf8mb4),
     'Nonconformities', 'No conformidades', NULL, 1, 80, 1)
ON DUPLICATE KEY UPDATE
    `label` = VALUES(`label`), `label_pt` = VALUES(`label_pt`),
    `label_en` = VALUES(`label_en`), `label_es` = VALUES(`label_es`),
    `nivel` = 1, `ordem` = 80, `ativo` = 1;

INSERT INTO `menus`
    (`codmenu_pai`, `modulo`, `codigo`, `label`, `label_pt`, `label_en`, `label_es`,
     `url`, `nivel`, `ordem`, `ativo`)
SELECT m.`codmenu`, 'NAO_CONFORMIDADES', 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS',
       CONVERT(0x4C6973746120676572616C206465206F636F7272C3AA6E63696173 USING utf8mb4),
       CONVERT(0x4C6973746120676572616C206465206F636F7272C3AA6E63696173 USING utf8mb4),
       'General occurrence list', 'Lista general de ocurrencias',
       '/principal/naoconformidades_listageralocorrencias.php', 2, 10, 1
  FROM `menus` m
 WHERE m.`codigo` = 'NAO_CONFORMIDADES'
ON DUPLICATE KEY UPDATE
    `codmenu_pai` = VALUES(`codmenu_pai`), `url` = VALUES(`url`),
    `label` = VALUES(`label`), `label_pt` = VALUES(`label_pt`),
    `label_en` = VALUES(`label_en`), `label_es` = VALUES(`label_es`),
    `nivel` = 2, `ordem` = 10, `ativo` = 1;

INSERT INTO `permissoes`
    (`codperfil`, `codmenu`, `pode_incluir`, `pode_visualizar`, `pode_editar`,
     `pode_excluir`, `pode_exportar_excel`, `pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`, 0, 1, 0, 0, 1, 0
  FROM `perfis` p
  CROSS JOIN `menus` m
 WHERE m.`codigo` IN ('NAO_CONFORMIDADES', 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS')
ON DUPLICATE KEY UPDATE
    `pode_incluir` = VALUES(`pode_incluir`),
    `pode_visualizar` = VALUES(`pode_visualizar`),
    `pode_editar` = VALUES(`pode_editar`),
    `pode_excluir` = VALUES(`pode_excluir`),
    `pode_exportar_excel` = VALUES(`pode_exportar_excel`),
    `pode_gerar_pdf` = VALUES(`pode_gerar_pdf`);

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`,
     `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MENU_NC_LISTA_GERAL_109',
     'nc_indicacao_ocorrencia_todas.php',
     'naoconformidades_listageralocorrencias.php',
     'ESTRUTURA', '1.0.0', 'CONCLUIDA', 3620,
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
     JSON_OBJECT('menu_pai', 'NAO_CONFORMIDADES',
                 'menu_filho', 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS',
                 'view', 'vlistadeocorrenciasnc',
                 'read_only', TRUE,
                 'legacy_columns', 31))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`),
    `quantidade_registros` = VALUES(`quantidade_registros`),
    `concluida_em` = VALUES(`concluida_em`),
    `detalhes` = VALUES(`detalhes`);

COMMIT;
