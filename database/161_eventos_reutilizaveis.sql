-- Agenda reutilizável do GreenPortal.
-- Origem: green_modelo.ev_eventos (legado); destino: gmodelo_dbp.ev_eventos.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`ev_eventos`
  LIKE `green_modelo`.`ev_eventos`;
ALTER TABLE `gmodelo_dbp`.`ev_eventos`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  ENGINE = InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`ev_eventos`
  SELECT * FROM `green_modelo`.`ev_eventos`;

INSERT INTO `gmodelo_dbp`.`registros_migracao`
  (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,
   `quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
  ('EVENTOS_REUTILIZAVEIS_161','green_modelo.ev_eventos','gmodelo_dbp.ev_eventos',
   'DADOS','1.0.0','CONCLUIDA',
   (SELECT COUNT(*) FROM `gmodelo_dbp`.`ev_eventos`),NOW(),NOW(),
   JSON_OBJECT('uso','agenda reutilizável por transação',
               'transacao_inicial','medicaoemonitoramento_mm_monitoramento',
               'registros_legados_preservados',TRUE))
ON DUPLICATE KEY UPDATE
  `status`=VALUES(`status`), `quantidade_registros`=VALUES(`quantidade_registros`),
  `concluida_em`=VALUES(`concluida_em`), `detalhes`=VALUES(`detalhes`);

SET FOREIGN_KEY_CHECKS = 1;
