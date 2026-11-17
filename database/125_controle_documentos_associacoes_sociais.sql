-- Validação e habilitação dos relacionamentos sociais do Controle.
-- As tabelas sociais já estavam presentes e equivalentes no gmodelo_dbp.
USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_controle_documentos_rel_aspectos_sociais` AS
SELECT a.`CODASPECTOSOCIAL` AS `RELATION_ID`, a.`DSCASPECTOSOCIAL` AS `RELATION_LABEL`
FROM `lais_aspectossociais` a;

CREATE OR REPLACE VIEW `vw_controle_documentos_rel_impactos_sociais` AS
SELECT i.`CODIMPACTOSOCIAL` AS `RELATION_ID`, i.`DSCIMPACTOSOCIAL` AS `RELATION_LABEL`
FROM `lais_impactossociais` i;

INSERT IGNORE INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('CONTROLE_DOCUMENTOS_ASSOCIACOES_SOCIAIS_125',
     'green_modelo.lais_aspectossociais; green_modelo.lais_impactossociais; green_modelo.lais_documentosxaspectos; green_modelo.lais_documentosximpactos',
     'gmodelo_dbp (tabelas já existentes); views sociais do Controle',
     'VALIDACAO','1.0','CONCLUIDA',9,NOW(),NOW(),
     JSON_OBJECT('tabelas_necessarias', JSON_ARRAY('lais_aspectossociais','lais_impactossociais','lais_documentosxaspectos','lais_documentosximpactos'), 'migracao_dados', 'dispensada: estrutura e dados equivalentes', 'orfanatos', 0));
