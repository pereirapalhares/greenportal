USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW `vw_licencas_condicionantes_grid` AS
SELECT c.`CODCONDICIONANTE`, c.`CODLICENCA`, c.`DSCCONDICIONANTE`,
       c.`FREQUENCIA`, c.`ATENDIDO`, c.`CODUSUARIO`,
       COALESCE(u.`nome`, '') AS `RESPONSAVEL`, c.`OBS`
  FROM `li_condicionantes` c
  LEFT JOIN `usuarios` u ON u.`codusuario` = c.`CODUSUARIO`;

CREATE OR REPLACE VIEW `vw_licencas_palavras_chaves_grid` AS
SELECT x.`codlicencaxpalavrachave`, x.`codlicenca`, x.`codpalavrachave`,
       COALESCE(p.`dscpalavrachave`, '') AS `DSCPALAVRACHAVE`
  FROM `li_licencasxpalavraschave` x
  LEFT JOIN `green_universal`.`palavras_chaves` p
    ON p.`codpalavrachave` = x.`codpalavrachave`;

INSERT INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,
     `quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('LI_VIEWS_CONTEXTUAIS_119','gmodelo_dbp.li_condicionantes; gmodelo_dbp.li_licencasxpalavraschave',
     'gmodelo_dbp.vw_licencas_condicionantes_grid; gmodelo_dbp.vw_licencas_palavras_chaves_grid',
     'ESTRUTURA','1.0.0','CONCLUIDA',
     (SELECT COUNT(*) FROM `li_condicionantes`) + (SELECT COUNT(*) FROM `li_licencasxpalavraschave`),
     CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
     JSON_OBJECT('contexto_obrigatorio','CODLICENCA','somente_leitura',TRUE))
ON DUPLICATE KEY UPDATE `status`=VALUES(`status`),`quantidade_registros`=VALUES(`quantidade_registros`),`concluida_em`=VALUES(`concluida_em`),`detalhes`=VALUES(`detalhes`);
