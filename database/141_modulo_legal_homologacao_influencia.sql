-- Relacionamento contextual entre requisitos legais de Influência e fornecedores.
USE `gmodelo_dbp`;

CREATE TABLE IF NOT EXISTS `legislacaoxfornecedores` (
    `CODLEGISLACAOXFORNECEDOR` INT NOT NULL AUTO_INCREMENT,
    `CODLEGISLACAO` INT NOT NULL,
    `CODUNIDADE` INT NOT NULL,
    `CODFORNECEDOR` INT NOT NULL,
    PRIMARY KEY (`CODLEGISLACAOXFORNECEDOR`),
    UNIQUE KEY `uk_leg_fornecedor_unidade` (`CODLEGISLACAO`,`CODUNIDADE`,`CODFORNECEDOR`),
    KEY `ix_leg_fornecedor_fornecedor` (`CODFORNECEDOR`),
    KEY `ix_leg_fornecedor_legislacao` (`CODLEGISLACAO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_homologacao_grid` AS
SELECT r.`CODLEGISLACAO`, r.`CODUNIDADE`, r.`CODLEGISLACAOXFORNECEDOR`,
       r.`CODFORNECEDOR`, f.`RAZAOSOCIAL`, f.`NOMEFANTASIA`, f.`ATIVADOFOR`
  FROM `legislacaoxfornecedores` r
  LEFT JOIN `fo_fornecedores` f ON f.`CODFORNECEDOR` = r.`CODFORNECEDOR`;

INSERT IGNORE INTO `registros_migracao`
 (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('MODULO_LEGAL_HOMOLOGACAO_INFLUENCIA_141',
        'requisito legal classificado como Influência; gmodelo_dbp.fo_fornecedores',
        'gmodelo_dbp.legislacaoxfornecedores; gmodelo_dbp.vw_modulolegal_requisitos_homologacao_grid',
        'ESTRUTURA','1.0.0','CONCLUIDA',0,NOW(),NOW(),
        JSON_OBJECT('chaves_contextuais',JSON_ARRAY('CODLEGISLACAO','CODUNIDADE'),
                    'regra_acesso','botão habilitado somente para PODER=Influência',
                    'crud_php','somente procedures'));
