USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `preferencias_usuario` (
    `codpreferenciausuario` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codusuario` BIGINT UNSIGNED NOT NULL,
    `chave` VARCHAR(150) NOT NULL,
    `valor` TEXT NULL,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`codpreferenciausuario`),
    UNIQUE KEY `uk_preferencia_usuario` (`codusuario`, `chave`),
    CONSTRAINT `fk_preferencia_usuario_usuario` FOREIGN KEY (`codusuario`) REFERENCES `usuarios` (`codusuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `artefatos_replicacao` (`modulo`,`tipo_artefato`,`nome_artefato`,`ordem_replicacao`,`replicar`,`obrigatorio`,`observacao`)
SELECT 'SEGURANCA','TABELA','preferencias_usuario',195,1,1,'Preferencias individuais de layout e apresentacao'
WHERE NOT EXISTS (SELECT 1 FROM `artefatos_replicacao` WHERE `tipo_artefato`='TABELA' AND `nome_artefato`='preferencias_usuario');
