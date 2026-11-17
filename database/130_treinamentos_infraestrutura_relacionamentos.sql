-- Views de catálogo e apoio aos grids de relacionamentos.

USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_modulolegal_rel_palavras_chaves` AS
SELECT `codpalavrachave` AS `RELATION_ID`, `dscpalavrachave` AS `RELATION_LABEL`
FROM `guniversal_dbp`.`palavras_chaves`;

CREATE OR REPLACE VIEW `vw_modulolegal_infraestrutura_atributos` AS
SELECT `codatributo` AS `RELATION_ID`, `dscatributo` AS `RELATION_LABEL`
FROM `ml_infraestruturaxatributos`;

INSERT IGNORE INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('TREINAMENTOS_INFRAESTRUTURA_RELACIONAMENTOS_130',
     'guniversal_dbp.palavras_chaves; gmodelo_dbp.ml_treinamenosxpalavraschave; gmodelo_dbp.ml_infraestruturaxpalavraschave; gmodelo_dbp.ml_infraestruturaxatributos',
     'vw_modulolegal_rel_palavras_chaves; vw_modulolegal_infraestrutura_atributos',
     'ESTRUTURA','1.0','CONCLUIDA',0,NOW(),NOW(),
     JSON_OBJECT('relacionamentos',JSON_ARRAY('treinamentos_palavras_chaves','infraestrutura_palavras_chaves','infraestrutura_atributos')));
