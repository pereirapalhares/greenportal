-- CRUD do Plano de Medição e Monitoramento.
-- A tabela e os dados já foram migrados pela MM_MODELO_054.

USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_medicao_monitoramento_monitoramento_grid` AS
SELECT m.`CODMONITORAMENTO`, m.`PARAMETROS`, m.`DATA_MEDICAO`, m.`DATA_REACAO`,
       m.`CODUSUARIO`, m.`CODEQUIPAMENTO`,
       m.`cronograma_janeiro`, m.`cronograma_fevereiro`, m.`cronograma_marco`,
       m.`cronograma_abril`, m.`cronograma_maio`, m.`cronograma_junho`,
       m.`cronograma_julho`, m.`cronograma_agosto`, m.`cronograma_setembro`,
       m.`cronograma_outubro`, m.`cronograma_novembro`, m.`cronograma_dezembro`,
       m.`OBS`, m.`CODAREA`, m.`CODASPECTO`, m.`CODPERIGO`, m.`codunidade` AS `CODUNIDADE`,
       m.`codmedicao` AS `CODMEDICAO`,
       '' AS `cronograma`
  FROM `mm_monitoramento` m;

UPDATE `menus`
SET `url`='/principal/medicaoemonitoramento_mm_monitoramento.php',
    `label`=CONVERT(X'506C616E6F206465204D656469C3A7C3A36F2065204D6F6E69746F72616D656E746F' USING utf8mb4),
    `label_pt`=CONVERT(X'506C616E6F206465204D656469C3A7C3A36F2065204D6F6E69746F72616D656E746F' USING utf8mb4),
    `label_en`='Measurement and Monitoring Plan',
    `label_es`=CONVERT(X'506C616E206465204D656469C3B36E2079204D6F6E69746F72656F' USING utf8mb4),
    `nivel`=2, `ativo`=1
WHERE `codigo`='MM_PLANO_MEDICAO_MONITORAMENTO';

INSERT INTO `permissoes`
    (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,
     `pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`, m.`codmenu`, 1, 1, 1, 1, 1, 0
FROM `perfis` p
JOIN `menus` m ON m.`codigo`='MM_PLANO_MEDICAO_MONITORAMENTO'
WHERE p.`codigo`='ADMINISTRADOR'
  AND NOT EXISTS (
      SELECT 1 FROM `permissoes` z
      WHERE z.`codperfil`=p.`codperfil` AND z.`codmenu`=m.`codmenu`
  );

INSERT INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,
     `quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('CRUD_MM_MONITORAMENTO_160',
     'green_modelo.mm_monitoramento; gmodelo_dbp.MM_MODELO_054',
     'gmodelo_dbp.vw_medicao_monitoramento_monitoramento_grid; gmodelo_dbp.menus; gmodelo_dbp.permissoes',
     'ESTRUTURA','1.0.0','CONCLUIDA',
     (SELECT COUNT(*) FROM `mm_monitoramento`), NOW(), NOW(),
     JSON_OBJECT(
       'tabela_destino_existente', TRUE,
       'migracao_dados_original', 'MM_MODELO_054',
       'transacao', 'medicaoemonitoramento_mm_monitoramento',
       'origem_legada_analisada', 'E:/wamp/www/configuracoes/mm_monitoramento.php',
       'crud', TRUE,
       'permissao_inicial', 'ADMINISTRADOR'
     ))
ON DUPLICATE KEY UPDATE
    `status`=VALUES(`status`),
    `quantidade_registros`=VALUES(`quantidade_registros`),
    `concluida_em`=VALUES(`concluida_em`),
    `detalhes`=VALUES(`detalhes`);
