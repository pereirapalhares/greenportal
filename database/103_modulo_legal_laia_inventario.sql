-- Green Portal - restauração do Inventário do LAIA.
-- A origem legada é green_modelo.laia_inventario (transação 182:
-- mlLaiaIventario). O tenant modelo usa gmodelo_dbp.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`laia_inventario`
  LIKE `green_modelo`.`laia_inventario`;
ALTER TABLE `gmodelo_dbp`.`laia_inventario`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`laia_inventario`
  SELECT * FROM `green_modelo`.`laia_inventario`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_laia_inventario_grid` AS
SELECT i.`CODINVENTARIO`, i.`CODLAIA`, i.`CODUNIDADE`,
       u.`DSCUNIDADE`, i.`QTD`, i.`DSCPROCEDIMENTO`, i.`ORIGEM`,
       i.`entrada_material`
  FROM `gmodelo_dbp`.`laia_inventario` i
  LEFT JOIN `gmodelo_dbp`.`unidades` u
    ON u.`CODUNIDADE` = i.`CODUNIDADE`;
