-- CRUD de Controle de Calibração para Medição e Monitoramento.
-- A tabela e os dados já foram migrados pela MM_MODELO_054.

USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_medicao_monitoramento_controle_calibracao_grid` AS
SELECT c.`CODCONTROLE`, c.`CODEQUIPAMENTO`, e.`DSCEQUIPAMENTO`,
       c.`FAIXA_DE_INDICACAO`, c.`FAIXA_DE_USO`,
       c.`CRITERIO_DE_ACELERACAO`, c.`TOLERANCIA`, c.`OBSERVACAO`,
       c.`CODUSUARIO`, c.`DATA_CALIBRACAO`, c.`DATA_REACAO`,
       c.`VALIDADE`, c.`STATUS`
  FROM `mm_controle_calibracao` c
  LEFT JOIN `mm_equipamento` e ON e.`CODEQUIPAMENTO`=c.`CODEQUIPAMENTO`;

UPDATE `menus`
SET `url`='/principal/medicaoemonitoramento_mm_controle_calibracao.php',
    `label`=CONVERT(X'436F6E74726F6C652064652043616C69627261C3A7C3A36F' USING utf8mb4),
    `label_pt`=CONVERT(X'436F6E74726F6C652064652043616C69627261C3A7C3A36F' USING utf8mb4),
    `label_en`='Calibration Control',
    `label_es`=CONVERT(X'436F6E74726F6C2064652043616C696272616369C3B36E' USING utf8mb4),
    `nivel`=2, `ativo`=1
WHERE `codigo`='MM_CONTROLE_CALIBRACAO';

INSERT INTO `permissoes`
    (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,
     `pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`, 1, 1, 1, 1, 1, 0
FROM `perfis` p
JOIN `menus` m ON m.`codigo`='MM_CONTROLE_CALIBRACAO'
WHERE p.`codigo`='ADMINISTRADOR'
  AND NOT EXISTS (
      SELECT 1 FROM `permissoes` z
      WHERE z.`codperfil`=p.`codperfil` AND z.`codmenu`=m.`codmenu`
  );

INSERT INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,
     `quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('CRUD_MM_CONTROLE_CALIBRACAO_159',
     'green_modelo.mm_controle_calibracao; gmodelo_dbp.MM_MODELO_054',
     'gmodelo_dbp.vw_medicao_monitoramento_controle_calibracao_grid; gmodelo_dbp.menus; gmodelo_dbp.permissoes',
     'ESTRUTURA','1.0.0','CONCLUIDA',
     (SELECT COUNT(*) FROM `mm_controle_calibracao`), NOW(), NOW(),
     JSON_OBJECT(
       'tabela_destino_existente', TRUE,
       'migracao_dados_original', 'MM_MODELO_054',
       'transacao', 'medicaoemonitoramento_mm_controle_calibracao',
       'crud', TRUE,
       'permissao_inicial', 'ADMINISTRADOR'
     ))
ON DUPLICATE KEY UPDATE
    `status`=VALUES(`status`),
    `quantidade_registros`=VALUES(`quantidade_registros`),
    `concluida_em`=VALUES(`concluida_em`),
    `detalhes`=VALUES(`detalhes`);
