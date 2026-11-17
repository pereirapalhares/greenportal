-- Migração 392 — Legislação › CheckList: "Copiar o checklist de outra lei" (17/11/2026). Banco: glegislacao_dbp.
-- Resumo das leis que têm checklist (148 hoje), para escolher a lei de origem. Blocos separados por "-- @@".
-- @@
CREATE OR REPLACE VIEW `vw_legislacao_checklist_resumo` AS
SELECT x.`CODLEGISLACAO`, l.`CODIGO`, t.`DSCTIPO`, l.`NR`, l.`ANO`, l.`situacao_da_lei`, COUNT(*) AS `QTD_PERGUNTAS`
  FROM `legislacaoxpergunta` x
  JOIN `legislacao` l ON l.`CODLEGISLACAO` = x.`CODLEGISLACAO`
  LEFT JOIN `tipos` t ON t.`CODTIPO` = l.`CODTIPO`
 GROUP BY x.`CODLEGISLACAO`, l.`CODIGO`, t.`DSCTIPO`, l.`NR`, l.`ANO`, l.`situacao_da_lei`
