SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `green_ia_document_jobs` (
  `codjob` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chave_idempotencia` VARCHAR(191) NOT NULL,
  `tipo` VARCHAR(50) NOT NULL,
  `entidade` VARCHAR(100) NOT NULL,
  `registro_id` VARCHAR(100) NOT NULL,
  `arquivo` VARCHAR(255) NOT NULL,
  `codanalise_ged` BIGINT UNSIGNED NULL,
  `codusuario` BIGINT UNSIGNED NULL,
  `idioma` VARCHAR(5) NOT NULL DEFAULT 'pt',
  `prompt` LONGTEXT NOT NULL,
  `texto` LONGTEXT NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
  `resultado_json` LONGTEXT NULL,
  `erro` VARCHAR(2000) NULL,
  `tentativas` INT UNSIGNED NOT NULL DEFAULT 0,
  `disponivel_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `iniciada_em` DATETIME NULL,
  `concluida_em` DATETIME NULL,
  `processador` VARCHAR(150) NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`codjob`),
  UNIQUE KEY `ux_green_ia_document_jobs_idempotencia` (`chave_idempotencia`),
  KEY `ix_green_ia_document_jobs_fila` (`status`,`disponivel_em`,`codjob`),
  KEY `ix_green_ia_document_jobs_usuario` (`codusuario`,`criado_em`),
  CONSTRAINT `ck_green_ia_document_jobs_status` CHECK (`status` IN ('PENDENTE','PROCESSANDO','CONCLUIDA','ERRO','CANCELADA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP VIEW IF EXISTS `vw_green_ia_document_jobs`;
CREATE VIEW `vw_green_ia_document_jobs` AS SELECT * FROM `green_ia_document_jobs`;

DROP PROCEDURE IF EXISTS `sp_ia_document_job_enfileirar`;
DELIMITER $$
CREATE PROCEDURE `sp_ia_document_job_enfileirar`(
 IN p_chave VARCHAR(191), IN p_tipo VARCHAR(50), IN p_entidade VARCHAR(100), IN p_registro VARCHAR(100),
 IN p_arquivo VARCHAR(255), IN p_codged BIGINT, IN p_usuario BIGINT, IN p_idioma VARCHAR(5),
 IN p_prompt LONGTEXT, IN p_texto LONGTEXT
)
BEGIN
 INSERT INTO green_ia_document_jobs(chave_idempotencia,tipo,entidade,registro_id,arquivo,codanalise_ged,codusuario,idioma,prompt,texto)
 VALUES(p_chave,p_tipo,p_entidade,p_registro,p_arquivo,NULLIF(p_codged,0),NULLIF(p_usuario,0),IF(p_idioma IN ('pt','en','es'),p_idioma,'pt'),p_prompt,p_texto)
 ON DUPLICATE KEY UPDATE codjob=LAST_INSERT_ID(codjob), atualizado_em=NOW();
 SELECT * FROM green_ia_document_jobs WHERE codjob=LAST_INSERT_ID();
END$$

DROP PROCEDURE IF EXISTS `sp_ia_document_job_reservar`$$
CREATE PROCEDURE `sp_ia_document_job_reservar`(IN p_processador VARCHAR(150))
BEGIN
 DECLARE v_id BIGINT DEFAULT NULL;
 START TRANSACTION;
 SELECT codjob INTO v_id FROM green_ia_document_jobs
 WHERE status='PENDENTE' AND disponivel_em<=NOW() ORDER BY codjob LIMIT 1 FOR UPDATE SKIP LOCKED;
 IF v_id IS NOT NULL THEN
   UPDATE green_ia_document_jobs SET status='PROCESSANDO',iniciada_em=NOW(),tentativas=tentativas+1,processador=p_processador WHERE codjob=v_id;
   SELECT * FROM green_ia_document_jobs WHERE codjob=v_id;
 END IF;
 COMMIT;
END$$

DROP PROCEDURE IF EXISTS `sp_ia_document_job_concluir`$$
CREATE PROCEDURE `sp_ia_document_job_concluir`(IN p_id BIGINT, IN p_resultado LONGTEXT)
BEGIN
 UPDATE green_ia_document_jobs SET status='CONCLUIDA',resultado_json=p_resultado,erro=NULL,concluida_em=NOW() WHERE codjob=p_id;
 SELECT * FROM green_ia_document_jobs WHERE codjob=p_id;
END$$

DROP PROCEDURE IF EXISTS `sp_ia_document_job_falhar`$$
CREATE PROCEDURE `sp_ia_document_job_falhar`(IN p_id BIGINT, IN p_erro VARCHAR(2000))
BEGIN
 UPDATE green_ia_document_jobs SET status='ERRO',erro=LEFT(p_erro,2000),concluida_em=NOW() WHERE codjob=p_id;
 SELECT * FROM green_ia_document_jobs WHERE codjob=p_id;
END$$
DELIMITER ;
