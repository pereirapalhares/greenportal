USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `exportacoes_grid` (
    `codexportacaogrid` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codusuario` BIGINT UNSIGNED NOT NULL,
    `transacao` VARCHAR(150) NOT NULL,
    `arquivo` VARCHAR(255) NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'SOLICITADA',
    `solicitado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `concluido_em` DATETIME NULL,
    `erro` TEXT NULL,
    PRIMARY KEY (`codexportacaogrid`),
    CONSTRAINT `fk_exportacao_grid_usuario` FOREIGN KEY (`codusuario`) REFERENCES `usuarios` (`codusuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `alertas` (
    `codalerta` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codusuario` BIGINT UNSIGNED NOT NULL,
    `titulo` VARCHAR(180) NOT NULL,
    `mensagem` TEXT NOT NULL,
    `link` VARCHAR(500) NULL,
    `lido` TINYINT(1) NOT NULL DEFAULT 0,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codalerta`),
    KEY `ix_alertas_usuario` (`codusuario`, `lido`, `criado_em`),
    CONSTRAINT `fk_alertas_usuario` FOREIGN KEY (`codusuario`) REFERENCES `usuarios` (`codusuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_alertas_grid` AS
SELECT `codalerta`, `codusuario`, `titulo`, `mensagem`, `link`, `lido`, `criado_em`
  FROM `alertas`;
