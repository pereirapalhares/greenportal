-- Alinha o grid contextual de Medição e Monitoramento ao grid principal.
-- As colunas de contexto da legislação permanecem ocultas; as colunas do
-- monitoramento seguem a mesma ordem, rótulos e semântica do cadastro principal.

USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_medicao_grid` AS
SELECT r.`CODLEGISLACAO`,
       r.`codmonitoramentoxlegislacao`,
       m.`codunidade` AS `CODUNIDADE`,
       m.`CODMONITORAMENTO`,
       m.`codmedicao` AS `CODMEDICAO`,
       m.`CODEQUIPAMENTO`,
       m.`CODAREA`,
       m.`CODASPECTO`,
       m.`CODPERIGO`,
       m.`PARAMETROS`,
       m.`DATA_MEDICAO`,
       m.`DATA_REACAO`,
       m.`CODUSUARIO`,
       '' AS `cronograma`,
       m.`OBS`
  FROM `mm_monitoramentoxlegislacao` r
  INNER JOIN `mm_monitoramento` m
          ON m.`CODMONITORAMENTO` = r.`CODMONITORAMENTO`;

INSERT INTO `registros_migracao`
 (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,
  `quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
 ('GRID_MM_REQUISITOS_ALINHAMENTO_180',
  'gmodelo_dbp.vw_medicao_monitoramento_monitoramento_grid; gmodelo_dbp.mm_monitoramento',
  'gmodelo_dbp.vw_modulolegal_requisitos_medicao_grid; manutencaolegalrequisitoslegais_medicaomonitoramento.php',
  'CORRECAO','1.0.0','CONCLUIDA',
  (SELECT COUNT(*) FROM `mm_monitoramentoxlegislacao`),NOW(),NOW(),
  JSON_OBJECT('colunas',JSON_ARRAY('CODUNIDADE','CODMEDICAO','CODEQUIPAMENTO','CODAREA','CODASPECTO','CODPERIGO','PARAMETROS','DATA_MEDICAO','DATA_REACAO','CODUSUARIO','cronograma','OBS','CODMONITORAMENTO'),
              'contexto_preservado',TRUE,
              'chave_contextual','CODLEGISLACAO'))
ON DUPLICATE KEY UPDATE
 `status`=VALUES(`status`),
 `quantidade_registros`=VALUES(`quantidade_registros`),
 `concluida_em`=VALUES(`concluida_em`),
 `detalhes`=VALUES(`detalhes`);
