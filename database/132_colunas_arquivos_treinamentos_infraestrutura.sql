USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_modulolegal_treinamentos_grid` AS
SELECT t.`codtreinamento` AS `CODTREINAMENTO`, t.`dsctreinamento` AS `DSCTREINAMENTO`, NULL AS `ARQUIVO`,
       (SELECT GROUP_CONCAT(DISTINCT p.`dscpalavrachave` ORDER BY p.`dscpalavrachave` SEPARATOR '; ')
          FROM `ml_treinamentosxpalavraschave` x JOIN `guniversal_dbp`.`palavras_chaves` p ON p.`codpalavrachave`=x.`codpalavrachave`
         WHERE x.`codtreinamento`=t.`codtreinamento`) AS `PALAVRAS_CHAVE`
FROM `ml_treinamentos` t;

CREATE OR REPLACE VIEW `vw_modulolegal_infraestrutura_grid` AS
SELECT i.`codinfraestrutura` AS `CODINFRAESTRUTURA`, i.`dscinfraestrutura` AS `DSCINFRAESTRUTURA`, NULL AS `ARQUIVO`,
       (SELECT GROUP_CONCAT(CONCAT(a.`dscatributo`, ': ', a.`valoratributo`) ORDER BY a.`dscatributo` SEPARATOR '; ') FROM `ml_infraestruturaxatributos` a WHERE a.`codinfraestrutura`=i.`codinfraestrutura`) AS `ATRIBUTOS`,
       (SELECT GROUP_CONCAT(DISTINCT p.`dscpalavrachave` ORDER BY p.`dscpalavrachave` SEPARATOR '; ') FROM `ml_infraestruturaxpalavraschave` x JOIN `guniversal_dbp`.`palavras_chaves` p ON p.`codpalavrachave`=x.`codpalavrachave` WHERE x.`codinfraestrutura`=i.`codinfraestrutura`) AS `PALAVRAS_CHAVE`
FROM `ml_infraestrutura` i;

INSERT IGNORE INTO `registros_migracao` (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('MODULOLEGAL_ARQUIVOS_132','gmodelo_dbp.vw_modulolegal_treinamentos_grid; gmodelo_dbp.vw_modulolegal_infraestrutura_grid','coluna ARQUIVO; gestao_arquivos.php','ESTRUTURA','1.0','CONCLUIDA',0,NOW(),NOW(),JSON_OBJECT('tipos',JSON_ARRAY('treinamentos','infraestrutura')));
