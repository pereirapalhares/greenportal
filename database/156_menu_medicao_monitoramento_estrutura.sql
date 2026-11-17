-- Estrutura inicial do menu independente de Medição e Monitoramento.
-- As transações filhas ainda não possuem URL: o menu exibirá "Em construção".

USE `gmodelo_dbp`;

INSERT INTO `menus`
    (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT NULL, 'MEDICAO_MONITORAMENTO', 'MEDICAO_MONITORAMENTO',
       CONVERT(X'4D656469C3A7C3A36F2065204D6F6E69746F72616D656E746F' USING utf8mb4),
       CONVERT(X'4D656469C3A7C3A36F2065204D6F6E69746F72616D656E746F' USING utf8mb4),
       'Measurement and Monitoring',
       CONVERT(X'4D6564696369C3B36E2079204D6F6E69746F72656F' USING utf8mb4),
       NULL, 1, 45, 1
WHERE NOT EXISTS (SELECT 1 FROM `menus` WHERE `codigo`='MEDICAO_MONITORAMENTO');

UPDATE `menus`
SET `codmenu_pai`=NULL, `modulo`='MEDICAO_MONITORAMENTO',
    `label`=CONVERT(X'4D656469C3A7C3A36F2065204D6F6E69746F72616D656E746F' USING utf8mb4),
    `label_pt`=CONVERT(X'4D656469C3A7C3A36F2065204D6F6E69746F72616D656E746F' USING utf8mb4),
    `label_en`='Measurement and Monitoring',
    `label_es`=CONVERT(X'4D6564696369C3B36E2079204D6F6E69746F72656F' USING utf8mb4),
    `url`=NULL, `nivel`=1, `ordem`=45, `ativo`=1
WHERE `codigo`='MEDICAO_MONITORAMENTO';

INSERT INTO `menus`
    (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT p.`codmenu`, 'MEDICAO_MONITORAMENTO', x.`codigo`, x.`label_pt`,
       x.`label_pt`, x.`label_en`, x.`label_es`, NULL, 2, x.`ordem`, 1
FROM `menus` p
CROSS JOIN (
    SELECT 'MM_EQUIPAMENTOS' AS `codigo`, 'Equipamentos' AS `label_pt`, 'Equipment' AS `label_en`, 'Equipos' AS `label_es`, 1 AS `ordem`
    UNION ALL SELECT 'MM_MEDICOES', CONVERT(X'4D656469C3A7C3A36F' USING utf8mb4), 'Measurement', CONVERT(X'4D6564696369C3B36E' USING utf8mb4), 2
    UNION ALL SELECT 'MM_CONTROLE_CALIBRACAO', CONVERT(X'436F6E74726F6C652064652043616C69627261C3A7C3A36F' USING utf8mb4), 'Calibration Control', CONVERT(X'436F6E74726F6C2064652043616C696272616369C3B36E' USING utf8mb4), 3
    UNION ALL SELECT 'MM_PLANO_MEDICAO_MONITORAMENTO', CONVERT(X'506C616E6F206465204D656469C3A7C3A36F2065204D6F6E69746F72616D656E746F' USING utf8mb4), 'Measurement and Monitoring Plan', CONVERT(X'506C616E206465204D656469C3B36E2079204D6F6E69746F72656F' USING utf8mb4), 4
) x
WHERE p.`codigo`='MEDICAO_MONITORAMENTO'
  AND NOT EXISTS (SELECT 1 FROM `menus` m WHERE m.`codigo`=x.`codigo`);

UPDATE `menus` m
JOIN `menus` p ON p.`codigo`='MEDICAO_MONITORAMENTO'
JOIN (
    SELECT 'MM_EQUIPAMENTOS' AS `codigo`, 'Equipamentos' AS `label_pt`, 'Equipment' AS `label_en`, 'Equipos' AS `label_es`, 1 AS `ordem`
    UNION ALL SELECT 'MM_MEDICOES', CONVERT(X'4D656469C3A7C3A36F' USING utf8mb4), 'Measurement', CONVERT(X'4D6564696369C3B36E' USING utf8mb4), 2
    UNION ALL SELECT 'MM_CONTROLE_CALIBRACAO', CONVERT(X'436F6E74726F6C652064652043616C69627261C3A7C3A36F' USING utf8mb4), 'Calibration Control', CONVERT(X'436F6E74726F6C2064652043616C696272616369C3B36E' USING utf8mb4), 3
    UNION ALL SELECT 'MM_PLANO_MEDICAO_MONITORAMENTO', CONVERT(X'506C616E6F206465204D656469C3A7C3A36F2065204D6F6E69746F72616D656E746F' USING utf8mb4), 'Measurement and Monitoring Plan', CONVERT(X'506C616E206465204D656469C3B36E2079204D6F6E69746F72656F' USING utf8mb4), 4
) x ON x.`codigo`=m.`codigo`
SET m.`codmenu_pai`=p.`codmenu`, m.`modulo`='MEDICAO_MONITORAMENTO',
    m.`label`=x.`label_pt`, m.`label_pt`=x.`label_pt`, m.`label_en`=x.`label_en`,
    m.`label_es`=x.`label_es`, m.`url`=NULL, m.`nivel`=2, m.`ordem`=x.`ordem`, m.`ativo`=1;

INSERT INTO `permissoes`
    (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`, 1, 1, 1, 1, 1, 0
FROM `perfis` p
CROSS JOIN `menus` m
WHERE p.`codigo`='ADMINISTRADOR'
  AND m.`codigo` IN ('MEDICAO_MONITORAMENTO','MM_EQUIPAMENTOS','MM_MEDICOES','MM_CONTROLE_CALIBRACAO','MM_PLANO_MEDICAO_MONITORAMENTO')
  AND NOT EXISTS (
      SELECT 1 FROM `permissoes` z
      WHERE z.`codperfil`=p.`codperfil` AND z.`codmenu`=m.`codmenu`
  );

INSERT IGNORE INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('MENU_MEDICAO_MONITORAMENTO_156',
     'green_modelo.mm_equipamento; green_modelo.mm_medicao; green_modelo.mm_controle_calibracao; green_modelo.mm_planodeacao; green_modelo.mm_monitoramento',
     'gmodelo_dbp.menus; gmodelo_dbp.permissoes',
     'ESTRUTURA','1.0.0','CONCLUIDA',5,NOW(),NOW(),
     JSON_OBJECT('urls_filhas', 'NULL', 'permissao_inicial', 'ADMINISTRADOR', 'crud_independente', FALSE));
