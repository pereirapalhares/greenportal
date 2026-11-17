-- Correcao dos relacionamentos do modulo legal conforme comparacao com o legado.
-- Execucao idempotente: os dados removidos ficam preservados nos backups abaixo.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

USE `gmodelo_dbp`;

CREATE TABLE IF NOT EXISTS `zz_backup_153_legislacaoxatividade` LIKE `legislacaoxatividade`;
INSERT INTO `zz_backup_153_legislacaoxatividade`
SELECT DISTINCT `CODLEGISLACAO`,`CODATIVIDADE`,`codunidade`
  FROM `legislacaoxatividade`
 WHERE NOT EXISTS (
   SELECT 1 FROM `zz_backup_153_legislacaoxatividade` b
    WHERE b.`CODLEGISLACAO`=`legislacaoxatividade`.`CODLEGISLACAO`
      AND b.`CODATIVIDADE`=`legislacaoxatividade`.`CODATIVIDADE`
      AND b.`codunidade`=`legislacaoxatividade`.`codunidade`
 );

CREATE TABLE IF NOT EXISTS `zz_backup_153_legislacaoxrisco` LIKE `legislacaoxrisco`;
INSERT INTO `zz_backup_153_legislacaoxrisco`
SELECT DISTINCT `CODLEGISLACAO`,`CODRISCO`,`codunidade`
  FROM `legislacaoxrisco`
 WHERE NOT EXISTS (
   SELECT 1 FROM `zz_backup_153_legislacaoxrisco` b
    WHERE b.`CODLEGISLACAO`=`legislacaoxrisco`.`CODLEGISLACAO`
      AND b.`CODRISCO`=`legislacaoxrisco`.`CODRISCO`
      AND b.`codunidade`=`legislacaoxrisco`.`codunidade`
 );

CREATE TABLE IF NOT EXISTS `zz_backup_153_legislacaoxempresa_sem_unidade` LIKE `legislacaoxempresa`;
INSERT INTO `zz_backup_153_legislacaoxempresa_sem_unidade`
SELECT x.*
  FROM `legislacaoxempresa` x
 WHERE (x.`codunidade` IS NULL OR x.`codlegislacaocodunidade` IS NULL)
   AND NOT EXISTS (
       SELECT 1 FROM `zz_backup_153_legislacaoxempresa_sem_unidade` b
        WHERE b.`CODLEGISLACAXEMPRESA`=x.`CODLEGISLACAXEMPRESA`
   );

-- Consolida duplicacoes exatas sem perder os dados unicos.
DELETE FROM `legislacaoxatividade`;
INSERT INTO `legislacaoxatividade` (`CODLEGISLACAO`,`CODATIVIDADE`,`codunidade`)
SELECT DISTINCT `CODLEGISLACAO`,`CODATIVIDADE`,`codunidade`
  FROM `zz_backup_153_legislacaoxatividade`;

DELETE FROM `legislacaoxrisco`;
INSERT INTO `legislacaoxrisco` (`CODLEGISLACAO`,`CODRISCO`,`codunidade`)
SELECT DISTINCT `CODLEGISLACAO`,`CODRISCO`,`codunidade`
  FROM `zz_backup_153_legislacaoxrisco`;

-- Relacoes sem unidade nao podem participar dos grids contextuais.
DELETE FROM `legislacaoxempresa`
 WHERE `codunidade` IS NULL OR `codlegislacaocodunidade` IS NULL;

-- Registro existente no legado e ausente no banco novo.
INSERT INTO `legislacaoxempresa`
 (`CODLEGISLACAO`,`CODEMPRESA`,`codunidade`,`atendido`,`tipo_de_lei`,`codlegislacaocodunidade`)
SELECT 1,4,1,NULL,'MATRIZ','11'
 WHERE NOT EXISTS (
   SELECT 1 FROM `legislacaoxempresa`
    WHERE `CODLEGISLACAO`=1 AND `CODEMPRESA`=4 AND `codunidade`=1
      AND `codlegislacaocodunidade`='11'
 );

ALTER TABLE `legislacaoxatividade`
  MODIFY `codunidade` INT NOT NULL;
ALTER TABLE `legislacaoxrisco`
  MODIFY `codunidade` INT NOT NULL;

SET @exists_unique_atividade := (
  SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema='gmodelo_dbp' AND table_name='legislacaoxatividade'
     AND index_name='uq_lxat_legislacao_atividade_unidade'
);
SET @sql_unique_atividade := IF(@exists_unique_atividade=0,
  'ALTER TABLE `legislacaoxatividade` ADD UNIQUE KEY `uq_lxat_legislacao_atividade_unidade` (`CODLEGISLACAO`,`CODATIVIDADE`,`codunidade`)',
  'SELECT 1');
PREPARE stmt_unique_atividade FROM @sql_unique_atividade;
EXECUTE stmt_unique_atividade;
DEALLOCATE PREPARE stmt_unique_atividade;

SET @exists_unique_risco := (
  SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema='gmodelo_dbp' AND table_name='legislacaoxrisco'
     AND index_name='uq_lxr_legislacao_risco_unidade'
);
SET @sql_unique_risco := IF(@exists_unique_risco=0,
  'ALTER TABLE `legislacaoxrisco` ADD UNIQUE KEY `uq_lxr_legislacao_risco_unidade` (`CODLEGISLACAO`,`CODRISCO`,`codunidade`)',
  'SELECT 1');
PREPARE stmt_unique_risco FROM @sql_unique_risco;
EXECUTE stmt_unique_risco;
DEALLOCATE PREPARE stmt_unique_risco;

CREATE OR REPLACE VIEW `vw_modulolegal_correlacionamento_ambiental_grid` AS
SELECT DISTINCT le.`CODLEGISLACAO`,le.`CODIGO`,le.`NR`,le.`ANO`,le.`ARTIGOS`,le.`MEIO_AMBIENTE`,le.`SEGURANCA`,le.`QUALIDADE`,le.`RESPOSABILIDADE`,ue.`CODUNIDADE`,u.`DSCUNIDADE`,lp.`PODER` AS `NIVEL_DE_CONTROLE`,tp.`DSCTIPO`,am.`DSCAMBITO`,GROUP_CONCAT(DISTINCT ass.`DSCASSUNTO` ORDER BY ass.`DSCASSUNTO` SEPARATOR ', ') AS `DSCASSUNTO`,
       (SELECT COUNT(DISTINCT xa.`CODASPECTO`) FROM `legislacaoxaspecto` xa WHERE xa.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND xa.`codunidade`=ue.`CODUNIDADE`) AS `QTD_ASPECTOS`,
       (SELECT COUNT(DISTINCT xv.`CODATIVIDADE`) FROM `legislacaoxatividade` xv WHERE xv.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND xv.`codunidade`=ue.`CODUNIDADE`) AS `QTD_ATIVIDADES`,
       (SELECT COUNT(DISTINCT li.`CODIMPACTO`) FROM `legislacaoximpacto` li WHERE li.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND li.`codunidade`=ue.`CODUNIDADE`) AS `QTD_IMPACTOS`,
       (SELECT COUNT(DISTINCT ll.`CODLICENCA`) FROM `legislacaoxlicencas` ll WHERE ll.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND ll.`codunidade`=ue.`CODUNIDADE`) AS `QTD_LICENCAS`,
       (SELECT COUNT(DISTINCT xd.`CODDOCUMENTO`) FROM `legislacaoxdocumentos` xd WHERE xd.`codlegislacao`=le.`CODLEGISLACAO` AND xd.`codunidade`=ue.`CODUNIDADE`) AS `QTD_DOCUMENTOS`
  FROM `glegislacao_dbp`.`legislacao` le INNER JOIN `legislacaoxempresa` ue ON ue.`CODLEGISLACAO`=le.`CODLEGISLACAO`
  LEFT JOIN `unidades` u ON u.`CODUNIDADE`=ue.`CODUNIDADE`
  LEFT JOIN `legislacaoxpoder` lp ON lp.`CODLEGISLACAO`=le.`CODLEGISLACAO` AND lp.`codunidade`=ue.`CODUNIDADE`
  LEFT JOIN `glegislacao_dbp`.`tipos` tp ON tp.`CODTIPO`=le.`CODTIPO` LEFT JOIN `glegislacao_dbp`.`ambitos` am ON am.`CODAMBITO`=le.`CODAMBITO`
  LEFT JOIN `glegislacao_dbp`.`legislacaoxassunto` lxa ON lxa.`CODLEGISLACAO`=le.`CODLEGISLACAO` LEFT JOIN `glegislacao_dbp`.`assuntos` ass ON ass.`CODASSUNTO`=lxa.`CODASSUNTO`
 WHERE LOWER(TRIM(COALESCE(le.`MEIO_AMBIENTE`,''))) IN ('sim','1','true','s')
 GROUP BY le.`CODLEGISLACAO`,le.`CODIGO`,le.`NR`,le.`ANO`,le.`ARTIGOS`,le.`MEIO_AMBIENTE`,le.`SEGURANCA`,le.`QUALIDADE`,le.`RESPOSABILIDADE`,ue.`CODUNIDADE`,u.`DSCUNIDADE`,lp.`PODER`,tp.`DSCTIPO`,am.`DSCAMBITO`
 ORDER BY le.`CODIGO`,ue.`CODUNIDADE`;

INSERT INTO `registros_migracao`
 (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('CORRECAO_CORRELACIONAMENTOS_LEGADO_153','green_modelo.legislacaoxatividade; green_modelo.legislacaoxrisco; green_modelo.legislacaoxempresa','gmodelo_dbp.legislacaoxatividade; gmodelo_dbp.legislacaoxrisco; gmodelo_dbp.legislacaoxempresa','CORRECAO','1.0.0','CONCLUIDA',(SELECT COUNT(*) FROM `vw_modulolegal_correlacionamento_ambiental_grid`),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,JSON_OBJECT('duplicacoes_consolidadas',TRUE,'registros_sem_unidade_preservados_em_backup',TRUE,'registro_legado_ausente_restaurado',TRUE,'contagens_distintas',TRUE))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
