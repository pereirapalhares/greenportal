-- Migração do histórico de alterações da situação dos requisitos legais.
-- Origem: green_modelo.situacaoxhistorico
-- Destino: gmodelo_dbp.situacaoxhistorico
-- A tabela registra a situação, data, descrição e usuário responsável.

USE `gmodelo_dbp`;

CREATE TABLE IF NOT EXISTS `situacaoxhistorico` (
    `CODHISTORICO` int NOT NULL AUTO_INCREMENT,
    `CODSITUACAO` int DEFAULT NULL,
    `DATAHISTORICO` date DEFAULT NULL,
    `DESCRICAO` varchar(500) DEFAULT NULL,
    `codusuario` int DEFAULT NULL,
    PRIMARY KEY (`CODHISTORICO`),
    KEY `ix_situacaoxhistorico_situacao_data` (`CODSITUACAO`, `DATAHISTORICO`),
    KEY `ix_situacaoxhistorico_usuario` (`codusuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO `situacaoxhistorico`
    (`CODHISTORICO`, `CODSITUACAO`, `DATAHISTORICO`, `DESCRICAO`, `codusuario`)
SELECT `CODHISTORICO`, `CODSITUACAO`, `DATAHISTORICO`, CONVERT(`DESCRICAO` USING utf8mb4), `codusuario`
  FROM `green_modelo`.`situacaoxhistorico`;

CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_situacaohistorico_grid` AS
SELECT h.`CODHISTORICO`, h.`CODSITUACAO`, s.`CODLEGISLACAO`, l.`CODIGO`,
       s.`codunidade` AS `CODUNIDADE`, COALESCE(u.`DSCUNIDADE`, '') AS `UNIDADE`,
       h.`DATAHISTORICO`, h.`DESCRICAO`, h.`codusuario` AS `CODUSUARIO`,
       COALESCE(usr.`nome`, usr.`userid`, '') AS `USUARIO`
  FROM `situacaoxhistorico` h
  INNER JOIN `legislacaoxsituacao` s ON s.`CODSITUACAO` = h.`CODSITUACAO`
  LEFT JOIN `glegislacao_dbp`.`legislacao` l ON l.`CODLEGISLACAO` = s.`CODLEGISLACAO`
  LEFT JOIN `unidades` u ON u.`CODUNIDADE` = s.`codunidade`
  LEFT JOIN `usuarios` usr ON usr.`codusuario` = h.`codusuario`;

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES ('REQUISITOS_SITUACAO_HISTORICO_136', 'green_modelo.situacaoxhistorico', 'gmodelo_dbp.situacaoxhistorico', 'DADOS', '1.0.0', 'CONCLUIDA',
        (SELECT COUNT(*) FROM `situacaoxhistorico`), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
        JSON_OBJECT('chave', 'CODHISTORICO', 'relacionamento', 'CODSITUACAO', 'origem_php', 'configuracoes/situacaoxhistorico.php'))
ON DUPLICATE KEY UPDATE status=VALUES(status), quantidade_registros=VALUES(quantidade_registros), concluida_em=VALUES(concluida_em), detalhes=VALUES(detalhes);
