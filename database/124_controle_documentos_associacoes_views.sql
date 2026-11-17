-- Views de leitura das associações do Controle.
-- O PHP deve acessar estas views e as procedures CRUD universais; não deve
-- executar SELECT/INSERT/UPDATE/DELETE diretamente.
USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_controle_documentos_rel_unidades` AS
SELECT u.`CODUNIDADE` AS `RELATION_ID`, u.`DSCUNIDADE` AS `RELATION_LABEL` FROM `unidades` u;

CREATE OR REPLACE VIEW `vw_controle_documentos_rel_atividades` AS
SELECT a.`CODATIVIDADE` AS `RELATION_ID`, a.`DSCATIVIDADE` AS `RELATION_LABEL` FROM `atividades` a;

CREATE OR REPLACE VIEW `vw_controle_documentos_rel_aspectos` AS
SELECT a.`CODASPECTO` AS `RELATION_ID`, a.`DSCASPECTO` AS `RELATION_LABEL` FROM `aspectos` a;

CREATE OR REPLACE VIEW `vw_controle_documentos_rel_impactos` AS
SELECT i.`CODIMPACTO` AS `RELATION_ID`, i.`DSCIMPACTO` AS `RELATION_LABEL` FROM `impactos` i;

CREATE OR REPLACE VIEW `vw_controle_documentos_rel_perigos` AS
SELECT p.`CODPERIGO` AS `RELATION_ID`, p.`DSCPERIGO` AS `RELATION_LABEL` FROM `perigos` p;

CREATE OR REPLACE VIEW `vw_controle_documentos_rel_riscos` AS
SELECT r.`CODRISCO` AS `RELATION_ID`, r.`DSCRISCO` AS `RELATION_LABEL` FROM `riscos` r;

CREATE OR REPLACE VIEW `vw_controle_documentos_rel_palavras_chaves` AS
SELECT pk.`codpalavrachave` AS `RELATION_ID`, pk.`dscpalavrachave` AS `RELATION_LABEL` FROM `guniversal_dbp`.`palavras_chaves` pk;

INSERT IGNORE INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('CONTROLE_DOCUMENTOS_ASSOCIACOES_VIEWS_124', 'API de associações do Controle', 'gmodelo_dbp.vw_controle_documentos_rel_*', 'ESTRUTURA', '1.0', 'CONCLUIDA', 7, NOW(), NOW(), JSON_OBJECT('crud_php', 'somente procedures', 'leitura', 'sp_crud_select', 'inclusao', 'sp_crud_insert', 'exclusao', 'sp_crud_delete'));
