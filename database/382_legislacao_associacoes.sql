-- Migração 382 — Legislação: URL de pesquisa e Legislações filhas (banco compartilhado glegislacao_dbp).
-- Blocos separados por "-- @@" (o script scripts/aplicar-migracao-382-legislacao-associacoes.php executa um a um).
-- Pode ser executada mais de uma vez.
-- @@
CREATE TABLE IF NOT EXISTS `legislacaoxurl` (
  `CODLEGISLACAOURL` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `CODLEGISLACAO` INT NOT NULL,
  `URL` VARCHAR(1000) NOT NULL,
  `DESCRICAO` VARCHAR(300) NULL,
  `ORIGEM` VARCHAR(10) NOT NULL DEFAULT 'USUARIO' COMMENT 'USUARIO ou IA',
  `SITUACAO` VARCHAR(12) NOT NULL DEFAULT 'CONFIRMADA' COMMENT 'SUGERIDA (IA, falta confirmar) ou CONFIRMADA',
  `JUSTIFICATIVA_IA` VARCHAR(1000) NULL,
  `USUARIO_INCLUSAO` VARCHAR(150) NULL,
  `EMPRESA_INCLUSAO` VARCHAR(80) NULL,
  `DATA_INCLUSAO` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `USUARIO_CONFIRMACAO` VARCHAR(150) NULL,
  `DATA_CONFIRMACAO` DATETIME NULL,
  `ULTIMA_VERIFICACAO` DATETIME NULL,
  `ULTIMO_STATUS_HTTP` INT NULL COMMENT '0 = não respondeu',
  PRIMARY KEY (`CODLEGISLACAOURL`),
  KEY `ix_lurl_legislacao` (`CODLEGISLACAO`, `SITUACAO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Migração 382: onde pesquisar alterações da lei'
-- @@
CREATE OR REPLACE VIEW `vw_legislacao_filhas` AS
SELECT r.`codlegislacaopai_filha` AS `CODLEGISLACAOPAI_FILHA`, r.`CODLEGISLACAO_PAI`, r.`CODLEGISLACAO_FILHA`,
       p.`CODIGO` AS `PAI_CODIGO`, tp.`DSCTIPO` AS `PAI_TIPO`, p.`NR` AS `PAI_NR`, p.`ANO` AS `PAI_ANO`, p.`situacao_da_lei` AS `PAI_SITUACAO`,
       f.`CODIGO` AS `FILHA_CODIGO`, tf.`DSCTIPO` AS `FILHA_TIPO`, f.`NR` AS `FILHA_NR`, f.`ANO` AS `FILHA_ANO`, f.`situacao_da_lei` AS `FILHA_SITUACAO`,
       LEFT(f.`PADRAO`, 300) AS `FILHA_RESUMO`, LEFT(p.`PADRAO`, 300) AS `PAI_RESUMO`
FROM `legislacaopaixlegislacaofilha` r
LEFT JOIN `legislacao` p ON p.`CODLEGISLACAO` = r.`CODLEGISLACAO_PAI`
LEFT JOIN `tipos` tp ON tp.`CODTIPO` = p.`CODTIPO`
LEFT JOIN `legislacao` f ON f.`CODLEGISLACAO` = r.`CODLEGISLACAO_FILHA`
LEFT JOIN `tipos` tf ON tf.`CODTIPO` = f.`CODTIPO`
-- @@
CREATE OR REPLACE VIEW `vw_legislacao_busca_simples` AS
SELECT l.`CODLEGISLACAO`, l.`CODIGO`, l.`CODTIPO`, t.`DSCTIPO`, l.`NR`, l.`ANO`, l.`situacao_da_lei`, LEFT(l.`PADRAO`, 300) AS `RESUMO`
FROM `legislacao` l LEFT JOIN `tipos` t ON t.`CODTIPO` = l.`CODTIPO`
-- @@
CREATE OR REPLACE VIEW `vw_legislacao_assoc_contagem` AS
SELECT l.`CODLEGISLACAO`,
       (SELECT COUNT(*) FROM `legislacaoxurl` u WHERE u.`CODLEGISLACAO` = l.`CODLEGISLACAO` AND u.`SITUACAO` = 'CONFIRMADA') AS `QTD_URLS`,
       (SELECT COUNT(*) FROM `legislacaoxurl` u WHERE u.`CODLEGISLACAO` = l.`CODLEGISLACAO` AND u.`SITUACAO` = 'SUGERIDA') AS `QTD_URLS_SUGERIDAS`,
       (SELECT COUNT(DISTINCT r.`CODLEGISLACAO_FILHA`) FROM `legislacaopaixlegislacaofilha` r WHERE r.`CODLEGISLACAO_PAI` = l.`CODLEGISLACAO`) AS `QTD_FILHAS`,
       (SELECT COUNT(DISTINCT r.`CODLEGISLACAO_PAI`) FROM `legislacaopaixlegislacaofilha` r WHERE r.`CODLEGISLACAO_FILHA` = l.`CODLEGISLACAO`) AS `QTD_PAIS`
FROM `legislacao` l
