-- Migração 407 — Onda 2 da Revisão G, Legal (24/11/2026): LEG-26 (categoria), LEG-27 (Responsável × Categoria), LEG-28 (atribuição
-- automática pela categoria). Decisões do usuário (24/11/2026):
--   * categorias = catálogo central glegislacao_dbp.legislacao_categoria (só o ADMINISTRADOR mantém);
--   * o usuário do cliente só vincula a lei a categorias; o vínculo vale SÓ para a empresa dele; uma lei pode ter várias categorias;
--   * a lei sem vínculo próprio usa o vínculo central (glegislacao_dbp.legislacaoxcategoria, que vem do legado).
-- Aplicada em cada empresa por scripts/aplicar-migracao-361-responsaveis-regras.php (junto das views das regras). Blocos "-- @@".

-- @@
-- Vínculo lei × categoria da empresa. CODCATEGORIALEGISLACAO = 0: "sem categoria" escolhido pela empresa (não usa o central).
CREATE TABLE IF NOT EXISTS `legislacaoxcategoria_empresa` (
  `CODLEGCATEMPRESA` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `CODLEGISLACAO` INT NOT NULL,
  `CODCATEGORIALEGISLACAO` INT NOT NULL,
  `CODUSUARIO` BIGINT NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODLEGCATEMPRESA`),
  UNIQUE KEY `uk_legcat_empresa` (`CODLEGISLACAO`, `CODCATEGORIALEGISLACAO`),
  KEY `ix_legcat_empresa_cat` (`CODCATEGORIALEGISLACAO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

-- @@
-- Catálogo de categorias (central), para as telas da empresa.
CREATE OR REPLACE VIEW `vw_legislacao_categorias` AS
SELECT c.`CODCATEGORIALEGISLACAO` AS `CODCATEGORIALEGISLACAO`, CONVERT(c.`NOME` USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `NOME`
  FROM `glegislacao_dbp`.`legislacao_categoria` c

-- @@
-- Categorias que valem para cada lei nesta empresa: as da empresa; se a empresa não definiu nenhuma, as centrais.
CREATE OR REPLACE VIEW `vw_legislacao_categorias_empresa` AS
SELECT e.`CODLEGISLACAO` AS `CODLEGISLACAO`, e.`CODCATEGORIALEGISLACAO` AS `CODCATEGORIALEGISLACAO`,
       CONVERT('EMPRESA' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `ORIGEM`
  FROM `legislacaoxcategoria_empresa` e
 WHERE e.`CODCATEGORIALEGISLACAO` > 0
UNION ALL
SELECT c.`CODLEGISLACAO`, c.`CODCATEGORIALEGISLACAO`, CONVERT('CENTRAL' USING utf8mb4) COLLATE utf8mb4_unicode_ci
  FROM `glegislacao_dbp`.`legislacaoxcategoria` c
 WHERE NOT EXISTS (SELECT 1 FROM `legislacaoxcategoria_empresa` e WHERE e.`CODLEGISLACAO` = c.`CODLEGISLACAO`)
