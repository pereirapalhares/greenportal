-- Migração 340 — Taxímetro de IA (23/10/2026). Banco universal (guniversal_dbp): valem para todas as empresas.
--   ia_precos: preço da OpenAI por modelo (US$ por 1 milhão de tokens), com a data a partir da qual vale; só o ADMINISTRADOR altera.
--   ia_cambio: cotação do dólar por dia (PTAX venda do Banco Central, gravada pelo serviço "iaconsumo"; ou digitada à mão).
-- Blocos separados por "-- @@". Pode ser executada mais de uma vez.
-- @@
CREATE TABLE IF NOT EXISTS `ia_precos` (
  `CODPRECO` INT NOT NULL AUTO_INCREMENT,
  `MODELO` VARCHAR(100) NOT NULL COMMENT 'Nome do modelo da OpenAI; vale também para as versões datadas (gpt-4o-mini-2024-07-18). * = preço padrão',
  `DESCRICAO` VARCHAR(200) NULL,
  `USD_ENTRADA` DECIMAL(12,6) NOT NULL COMMENT 'US$ por 1 milhão de tokens de entrada',
  `USD_ENTRADA_CACHE` DECIMAL(12,6) NOT NULL COMMENT 'US$ por 1 milhão de tokens de entrada em cache',
  `USD_SAIDA` DECIMAL(12,6) NOT NULL COMMENT 'US$ por 1 milhão de tokens de saída',
  `VIGENTE_DESDE` DATE NOT NULL,
  `ATIVO` TINYINT(1) NOT NULL DEFAULT 1,
  `FONTE` VARCHAR(200) NULL,
  `ALTERADO_POR` VARCHAR(150) NULL,
  `ALTERADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODPRECO`),
  UNIQUE KEY `ux_ia_precos_modelo_vigencia` (`MODELO`, `VIGENTE_DESDE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
CREATE TABLE IF NOT EXISTS `ia_cambio` (
  `DATA` DATE NOT NULL,
  `COTACAO_VENDA` DECIMAL(10,4) NOT NULL,
  `COTACAO_COMPRA` DECIMAL(10,4) NULL,
  `FONTE` VARCHAR(10) NOT NULL DEFAULT 'PTAX' COMMENT 'PTAX ou MANUAL',
  `ALTERADO_POR` VARCHAR(150) NULL,
  `GRAVADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`DATA`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
-- @@
CREATE OR REPLACE VIEW `vw_ia_precos` AS
SELECT CODPRECO, MODELO, DESCRICAO, USD_ENTRADA, USD_ENTRADA_CACHE, USD_SAIDA, VIGENTE_DESDE, ATIVO, FONTE, ALTERADO_POR, ALTERADO_EM FROM ia_precos
-- @@
CREATE OR REPLACE VIEW `vw_ia_cambio` AS
SELECT DATA, COTACAO_VENDA, COTACAO_COMPRA, FONTE, ALTERADO_POR, GRAVADO_EM FROM ia_cambio
-- @@
DROP PROCEDURE IF EXISTS `sp_ia_preco_salvar`
-- @@
CREATE PROCEDURE `sp_ia_preco_salvar`(IN p_codigo INT, IN p_modelo VARCHAR(100), IN p_descricao VARCHAR(200), IN p_entrada DECIMAL(12,6), IN p_cache DECIMAL(12,6),
  IN p_saida DECIMAL(12,6), IN p_vigente DATE, IN p_ativo TINYINT, IN p_fonte VARCHAR(200), IN p_usuario VARCHAR(150))
BEGIN
  DECLARE v_modelo VARCHAR(100) DEFAULT LOWER(TRIM(COALESCE(p_modelo, '')));
  IF v_modelo = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o modelo (ex.: gpt-4o-mini; * = preço padrão).'; END IF;
  IF p_entrada IS NULL OR p_saida IS NULL OR p_entrada < 0 OR p_saida < 0 OR COALESCE(p_cache, 0) < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe os preços de entrada e de saída (US$ por 1 milhão de tokens).'; END IF;
  IF p_vigente IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe a data a partir da qual o preço vale.'; END IF;
  IF EXISTS (SELECT 1 FROM ia_precos WHERE MODELO = v_modelo COLLATE utf8mb4_0900_ai_ci AND VIGENTE_DESDE = p_vigente AND CODPRECO <> COALESCE(p_codigo, 0)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Já existe um preço deste modelo com esta data: altere aquele.';
  END IF;
  IF COALESCE(p_codigo, 0) > 0 THEN
    UPDATE ia_precos SET MODELO = v_modelo, DESCRICAO = NULLIF(TRIM(p_descricao), ''), USD_ENTRADA = p_entrada, USD_ENTRADA_CACHE = COALESCE(p_cache, p_entrada),
           USD_SAIDA = p_saida, VIGENTE_DESDE = p_vigente, ATIVO = IF(p_ativo = 0, 0, 1), FONTE = NULLIF(TRIM(p_fonte), ''), ALTERADO_POR = p_usuario, ALTERADO_EM = NOW()
     WHERE CODPRECO = p_codigo;
    SELECT p_codigo AS CODPRECO;
  ELSE
    INSERT INTO ia_precos (MODELO, DESCRICAO, USD_ENTRADA, USD_ENTRADA_CACHE, USD_SAIDA, VIGENTE_DESDE, ATIVO, FONTE, ALTERADO_POR)
    VALUES (v_modelo, NULLIF(TRIM(p_descricao), ''), p_entrada, COALESCE(p_cache, p_entrada), p_saida, p_vigente, IF(p_ativo = 0, 0, 1), NULLIF(TRIM(p_fonte), ''), p_usuario);
    SELECT LAST_INSERT_ID() AS CODPRECO;
  END IF;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ia_cambio_gravar`
-- @@
CREATE PROCEDURE `sp_ia_cambio_gravar`(IN p_data DATE, IN p_venda DECIMAL(10,4), IN p_compra DECIMAL(10,4), IN p_fonte VARCHAR(10), IN p_usuario VARCHAR(150))
BEGIN
  IF p_data IS NULL OR p_venda IS NULL OR p_venda <= 0 OR p_venda > 100 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe a data e a cotação do dólar (R$ por US$).'; END IF;
  IF p_data > CURDATE() THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A data da cotação não pode ser futura.'; END IF;
  INSERT INTO ia_cambio (DATA, COTACAO_VENDA, COTACAO_COMPRA, FONTE, ALTERADO_POR, GRAVADO_EM)
  VALUES (p_data, p_venda, p_compra, IF(p_fonte = 'MANUAL', 'MANUAL', 'PTAX'), p_usuario, NOW())
  ON DUPLICATE KEY UPDATE
    COTACAO_VENDA = IF(ia_cambio.FONTE = 'MANUAL' AND VALUES(FONTE) = 'PTAX', ia_cambio.COTACAO_VENDA, VALUES(COTACAO_VENDA)),
    COTACAO_COMPRA = IF(ia_cambio.FONTE = 'MANUAL' AND VALUES(FONTE) = 'PTAX', ia_cambio.COTACAO_COMPRA, VALUES(COTACAO_COMPRA)),
    ALTERADO_POR = IF(ia_cambio.FONTE = 'MANUAL' AND VALUES(FONTE) = 'PTAX', ia_cambio.ALTERADO_POR, VALUES(ALTERADO_POR)),
    GRAVADO_EM = IF(ia_cambio.FONTE = 'MANUAL' AND VALUES(FONTE) = 'PTAX', ia_cambio.GRAVADO_EM, NOW()),
    FONTE = IF(ia_cambio.FONTE = 'MANUAL' AND VALUES(FONTE) = 'PTAX', ia_cambio.FONTE, VALUES(FONTE));
  SELECT ROW_COUNT() AS GRAVADOS;
END
