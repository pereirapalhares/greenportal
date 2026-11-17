-- Migração 385 — RCL (Registro de Consulta à Legislação): views no banco glegislacao_dbp.
-- Blocos separados por "-- @@". Uma linha por lei × assunto (como o legado, que gera uma linha por assunto).
-- @@
CREATE OR REPLACE VIEW `vw_legislacao_rcl` AS
SELECT l.`CODLEGISLACAO`, l.`CODIGO`, t.`DSCTIPO`, l.`NR`, l.`ANO`, a.`DSCAMBITO`, s.`DSCASSUNTO`, l.`PADRAO`,
       l.`MEIO_AMBIENTE`, l.`SEGURANCA`, l.`QUALIDADE`, l.`RESPOSABILIDADE`,
       l.`situacao_da_lei` AS `SITUACAO`, l.`devido_da_lei` AS `DEVIDO`,
       DATE_FORMAT(l.`data_atualizacao`, '%Y-%m') AS `MES_ATUALIZACAO`
  FROM `legislacao` l
  LEFT JOIN `tipos` t ON t.`CODTIPO` = l.`CODTIPO`
  LEFT JOIN `ambitos` a ON a.`CODAMBITO` = l.`CODAMBITO`
  LEFT JOIN `legislacaoxassunto` la ON la.`CODLEGISLACAO` = l.`CODLEGISLACAO`
  LEFT JOIN `assuntos` s ON s.`CODASSUNTO` = la.`CODASSUNTO`
 WHERE l.`data_atualizacao` IS NOT NULL
-- @@
CREATE OR REPLACE VIEW `vw_legislacao_rcl_alteracoes` AS
SELECT x.`codalteracao` AS `CODALTERACAO`, x.`codlegislacao` AS `CODLEGISLACAO`, x.`tipo` AS `TIPO`, x.`descricao` AS `DESCRICAO`,
       DATE_FORMAT(x.`data_da_alteracao`, '%Y-%m') AS `MES_ALTERACAO`
  FROM `legislacaoxalteracao` x
 WHERE x.`data_da_alteracao` IS NOT NULL
