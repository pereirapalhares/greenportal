-- Green Portal - Assuntos pertencem ao banco especializado de legislação.
-- Origem: green_legislacao.assuntos (latin1).

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

USE `glegislacao_dbp`;

CREATE TABLE IF NOT EXISTS `assuntos` (
    `CODASSUNTO` int NOT NULL,
    `DSCASSUNTO` varchar(500) DEFAULT NULL,
    PRIMARY KEY (`CODASSUNTO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELETE FROM `assuntos`;
INSERT INTO `assuntos` (`CODASSUNTO`, `DSCASSUNTO`)
SELECT `CODASSUNTO`, CONVERT(`DSCASSUNTO` USING utf8mb4)
FROM `green_legislacao`.`assuntos`;

CREATE OR REPLACE VIEW `vw_assuntos_grid` AS
SELECT `CODASSUNTO`, `DSCASSUNTO` FROM `assuntos`;

USE `gmodelo_dbp`;
DROP VIEW IF EXISTS `vw_assuntos_grid`;
DROP TABLE IF EXISTS `assuntos`;
