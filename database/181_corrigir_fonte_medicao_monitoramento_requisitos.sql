-- Corrige a fonte da aba Medição e Monitoramento dos requisitos legais.
-- Regra legada: listar os monitoramentos da unidade cujo tipo de medição
-- esteja associado à legislação em mm_medicaoxlegislacao.

USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_medicao_grid` AS
SELECT ml.`CODLEGISLACAO`,
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
  FROM `mm_medicaoxlegislacao` ml
  INNER JOIN `mm_monitoramento` m
          ON m.`codmedicao` = ml.`codmedicao`;

INSERT INTO `registros_migracao`
 (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,
  `quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
 ('GRID_MM_REQUISITOS_FONTE_MEDICAO_181',
  'gmodelo_dbp.mm_medicaoxlegislacao; gmodelo_dbp.mm_monitoramento',
  'gmodelo_dbp.vw_modulolegal_requisitos_medicao_grid; manutencaolegalrequisitoslegais_medicaomonitoramento.php',
  'CORRECAO','1.0.0','CONCLUIDA',
  (SELECT COUNT(*) FROM `vw_modulolegal_requisitos_medicao_grid`),NOW(),NOW(),
  JSON_OBJECT('fonte_associacao','mm_medicaoxlegislacao',
              'regra','mm_monitoramento.codmedicao = mm_medicaoxlegislacao.codmedicao',
              'filtro_unidade','CODUNIDADE aplicado pelo contexto da tela',
              'consulta_legada','mm_monitoramento_gdsp.php'))
ON DUPLICATE KEY UPDATE
 `status`=VALUES(`status`),
 `quantidade_registros`=VALUES(`quantidade_registros`),
 `concluida_em`=VALUES(`concluida_em`),
 `detalhes`=VALUES(`detalhes`);
