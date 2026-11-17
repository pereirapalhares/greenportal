-- CRUD de Medições para Medição e Monitoramento.
-- A tabela e os dados já foram migrados pela MM_MODELO_054.

USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_medicao_monitoramento_medicoes_grid` AS
SELECT m.`codmedicao` AS `CODMEDICAO`,
       m.`dscmedicao` AS `DSCMEDICAO`
  FROM `mm_medicao` m;

UPDATE `menus`
SET `url`='/principal/medicaoemonitoramento_mm_medicoes.php',
    `label`=CONVERT(X'4D656469C3A7C3A36F' USING utf8mb4),
    `label_pt`=CONVERT(X'4D656469C3A7C3A36F' USING utf8mb4),
    `label_en`='Measurement',
    `label_es`=CONVERT(X'4D6564696369C3B36E' USING utf8mb4),
    `nivel`=2, `ativo`=1
WHERE `codigo`='MM_MEDICOES';

INSERT INTO `permissoes`
    (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,
     `pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`, 1, 1, 1, 1, 1, 0
FROM `perfis` p
JOIN `menus` m ON m.`codigo`='MM_MEDICOES'
WHERE p.`codigo`='ADMINISTRADOR'
  AND NOT EXISTS (
      SELECT 1 FROM `permissoes` z
      WHERE z.`codperfil`=p.`codperfil` AND z.`codmenu`=m.`codmenu`
  );

INSERT INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,
     `quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('CRUD_MM_MEDICAO_158',
     'green_modelo.mm_medicao; gmodelo_dbp.MM_MODELO_054',
     'gmodelo_dbp.vw_medicao_monitoramento_medicoes_grid; gmodelo_dbp.menus; gmodelo_dbp.permissoes',
     'ESTRUTURA','1.0.0','CONCLUIDA',
     (SELECT COUNT(*) FROM `mm_medicao`), NOW(), NOW(),
     JSON_OBJECT(
       'tabela_destino_existente', TRUE,
       'migracao_dados_original', 'MM_MODELO_054',
       'transacao', 'medicaoemonitoramento_mm_medicoes',
       'crud', TRUE,
       'permissao_inicial', 'ADMINISTRADOR'
     ))
ON DUPLICATE KEY UPDATE
    `status`=VALUES(`status`),
    `quantidade_registros`=VALUES(`quantidade_registros`),
    `concluida_em`=VALUES(`concluida_em`),
    `detalhes`=VALUES(`detalhes`);
