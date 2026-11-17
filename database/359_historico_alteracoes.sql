-- Migração 359 — Históricos obrigatórios (GER-16; MM-77, CON-93, TRE-108, INF-123, HOM-141).
-- Tabela única por empresa, gravada só pelos gatilhos trg_hist_* (criados por scripts/aplicar-migracao-359-historico.php).
-- Uma linha por campo alterado (ALTERAR); na inclusão e na exclusão, uma linha com todos os campos em JSON.
-- codusuario / origem vêm de @gp_usuario / @gp_origem, que o portal define em cada conexão; vazios = fora do portal.

-- @@
CREATE TABLE IF NOT EXISTS `historico_alteracoes` (
  `codhistorico` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tabela` VARCHAR(64) NOT NULL,
  `registro_id` VARCHAR(64) NOT NULL,
  `operacao` VARCHAR(10) NOT NULL,
  `campo` VARCHAR(64) NULL,
  `valor_anterior` MEDIUMTEXT NULL,
  `valor_novo` MEDIUMTEXT NULL,
  `codusuario` INT NULL,
  `origem` VARCHAR(120) NULL,
  `data_hora` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`codhistorico`),
  KEY `ix_hist_registro` (`tabela`, `registro_id`, `data_hora`),
  KEY `ix_hist_data` (`data_hora`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci

-- @@
CREATE OR REPLACE VIEW `vw_historico_alteracoes` AS
SELECT h.`codhistorico`, h.`tabela`, h.`registro_id`, h.`operacao`, h.`campo`, h.`valor_anterior`, h.`valor_novo`, h.`codusuario`,
       COALESCE(NULLIF(TRIM(u.`nome`), ''), u.`userid`) AS `usuario`, h.`origem`, h.`data_hora`
  FROM `historico_alteracoes` h
  LEFT JOIN `usuarios` u ON u.`codusuario` = h.`codusuario`
