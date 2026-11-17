-- Índices compostos para as consultas recorrentes da fila de IA.
USE `gmodelo_dbp`;

ALTER TABLE `ml_potenciais_solicitacoes`
  ADD INDEX `ix_ml_potenciais_solicitacoes_fila` (`status`,`solicitada_em`,`codsolicitacao`),
  ADD INDEX `ix_ml_potenciais_solicitacoes_processando` (`status`,`iniciada_em`);

INSERT INTO `registros_migracao`
  (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
  ('INDICES_FILA_IA_PERFORMANCE_172',
   'gmodelo_dbp.ml_potenciais_solicitacoes',
   'gmodelo_dbp.ml_potenciais_solicitacoes',
   'PERFORMANCE','1.0.0','CONCLUIDA',
   (SELECT COUNT(*) FROM `ml_potenciais_solicitacoes`),NOW(),NOW(),
   JSON_OBJECT('indices',JSON_ARRAY('status,solicitada_em,codsolicitacao','status,iniciada_em'),
               'objetivo','acelerar recuperação e seleção da fila de IA'))
ON DUPLICATE KEY UPDATE
  `status`=VALUES(`status`),
  `quantidade_registros`=VALUES(`quantidade_registros`),
  `concluida_em`=VALUES(`concluida_em`),
  `detalhes`=VALUES(`detalhes`);
