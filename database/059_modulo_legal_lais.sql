-- Green Portal - Modulo Legal / LAIS

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`lais`
  LIKE `green_modelo`.`lais`;
ALTER TABLE `gmodelo_dbp`.`lais` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`lais` SELECT * FROM `green_modelo`.`lais`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`lais_partes_interessadas` LIKE `green_modelo`.`lais_partes_interessadas`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`lais_aspectossociais` LIKE `green_modelo`.`lais_aspectossociais`;
CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`lais_impactossociais` LIKE `green_modelo`.`lais_impactossociais`;
ALTER TABLE `gmodelo_dbp`.`lais_partes_interessadas` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
ALTER TABLE `gmodelo_dbp`.`lais_aspectossociais` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
ALTER TABLE `gmodelo_dbp`.`lais_impactossociais` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`lais_partes_interessadas` SELECT * FROM `green_modelo`.`lais_partes_interessadas`;
INSERT IGNORE INTO `gmodelo_dbp`.`lais_aspectossociais` SELECT * FROM `green_modelo`.`lais_aspectossociais`;
INSERT IGNORE INTO `gmodelo_dbp`.`lais_impactossociais` SELECT * FROM `green_modelo`.`lais_impactossociais`;

CREATE OR REPLACE VIEW `gmodelo_dbp`.`vw_lais_grid` AS
SELECT l.`CODLAIS`, l.`CODUNIDADE`, u.`DSCUNIDADE`, l.`CODPARTE`, p.`DSCPARTE`,
       l.`MODO_DA_OCORRENCIA`, l.`CODASPECTOSOCIAL`, a.`DSCASPECTOSOCIAL`,
       l.`SITUACAO`, CASE l.`SITUACAO` WHEN 'N' THEN 'Normal' WHEN 'AC' THEN 'Acidental' WHEN 'A' THEN 'Anormal' ELSE l.`SITUACAO` END AS `SITUACAO_DESCRICAO`,
       l.`TEMPO`, CASE l.`TEMPO` WHEN 'A' THEN 'Atual' WHEN 'P' THEN 'Passado' WHEN 'F' THEN 'Futuro' ELSE l.`TEMPO` END AS `TEMPO_DESCRICAO`,
       l.`INFLUENCIA`, CASE l.`INFLUENCIA` WHEN 'D' THEN 'Direto' WHEN 'I' THEN 'Indireto' ELSE l.`INFLUENCIA` END AS `INFLUENCIA_DESCRICAO`,
       l.`CLASSIFICACAO`, CASE l.`CLASSIFICACAO` WHEN 'A' THEN 'Adverso' WHEN 'Anormal' THEN 'Adverso' WHEN 'B' THEN 'Benéfico' ELSE l.`CLASSIFICACAO` END AS `CLASSIFICACAO_DESCRICAO`,
       l.`CODIMPACTOSOCIAL`, i.`DSCIMPACTOSOCIAL`, l.`ESCALA`, l.`SEVERIDADE`, l.`DURACAO`,
       (COALESCE(l.`ESCALA`,0)+COALESCE(l.`SEVERIDADE`,0)+COALESCE(l.`DURACAO`,0)) AS `RESULTADO`,
       CASE WHEN (COALESCE(l.`ESCALA`,0)+COALESCE(l.`SEVERIDADE`,0)+COALESCE(l.`DURACAO`,0)) IN (8,9) OR l.`SEVERIDADE`=3 THEN 'X' ELSE '' END AS `FILTROB`,
       l.`DISPOSICAOATENUANTE`, l.`FILTROC`, l.`FILTROD`, l.`data_trilha`, l.`userid_trilha`
  FROM `gmodelo_dbp`.`lais` l
  LEFT JOIN `gmodelo_dbp`.`unidades` u ON u.`CODUNIDADE`=l.`CODUNIDADE`
  LEFT JOIN `gmodelo_dbp`.`lais_partes_interessadas` p ON p.`CODPARTE`=l.`CODPARTE`
  LEFT JOIN `gmodelo_dbp`.`lais_aspectossociais` a ON a.`CODASPECTOSOCIAL`=l.`CODASPECTOSOCIAL`
  LEFT JOIN `gmodelo_dbp`.`lais_impactossociais` i ON i.`CODIMPACTOSOCIAL`=l.`CODIMPACTOSOCIAL`;

INSERT INTO `gmodelo_dbp`.`menus`
    (`codmenu_pai`,`modulo`,`codigo`,`label`,`label_pt`,`label_en`,`label_es`,`url`,`nivel`,`ordem`,`ativo`)
SELECT p.`codmenu`,'MODULO_LEGAL','LAIS','LAIS','LAIS','LAIS','LAIS','/principal/modulolegal_lais.php',2,25,1
  FROM `gmodelo_dbp`.`menus` p
 WHERE p.`codigo`='MODULO_LEGAL'
   AND NOT EXISTS (SELECT 1 FROM `gmodelo_dbp`.`menus` e WHERE e.`codigo`='LAIS');

INSERT INTO `gmodelo_dbp`.`permissoes`
    (`codperfil`,`codmenu`,`pode_incluir`,`pode_visualizar`,`pode_editar`,`pode_excluir`,`pode_exportar_excel`,`pode_gerar_pdf`)
SELECT p.`codperfil`,m.`codmenu`,IF(p.`codigo`='ADMINISTRADOR',1,0),IF(p.`codigo`='ADMINISTRADOR',1,0),IF(p.`codigo`='ADMINISTRADOR',1,0),IF(p.`codigo`='ADMINISTRADOR',1,0),IF(p.`codigo`='ADMINISTRADOR',1,0),0
  FROM `gmodelo_dbp`.`perfis` p CROSS JOIN `gmodelo_dbp`.`menus` m
 WHERE p.`ativo`=1 AND m.`codigo`='LAIS'
   AND NOT EXISTS (SELECT 1 FROM `gmodelo_dbp`.`permissoes` e WHERE e.`codperfil`=p.`codperfil` AND e.`codmenu`=m.`codmenu`);

INSERT INTO `gmodelo_dbp`.`registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('MODULO_LEGAL_LAIS_059','green_modelo.lais; green_modelo.lais_*','gmodelo_dbp.lais; gmodelo_dbp.lais_*','DADOS','1.0.0','CONCLUIDA',
        (SELECT COUNT(*) FROM `gmodelo_dbp`.`lais`),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
        JSON_OBJECT('fonte_legado','E:/wamp/www/configuracoes/vlais.php','resultado_formula','ESCALA + SEVERIDADE + DURACAO','filtro_significativo','RESULTADO IN (8,9) OR SEVERIDADE=3','transacao','/principal/modulolegal_lais.php'))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);

SET FOREIGN_KEY_CHECKS = 1;
