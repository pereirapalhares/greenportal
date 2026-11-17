-- P1 Green Calendar: agenda universal, recorrências e ocorrências materializadas.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS `agenda_eventos` (
 `codeventoagenda` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `chave_idempotencia` VARCHAR(191) NOT NULL,
 `codusuario_criador` BIGINT UNSIGNED NULL, `codusuario_responsavel` BIGINT UNSIGNED NULL,
 `titulo` VARCHAR(180) NOT NULL, `descricao` TEXT NULL, `localizacao` VARCHAR(255) NULL,
 `inicio` DATETIME NOT NULL, `fim` DATETIME NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'ATIVO',
 `origem_transacao` VARCHAR(150) NULL, `origem_entidade` VARCHAR(100) NULL, `origem_id` VARCHAR(100) NULL,
 `codrecorrencia` BIGINT UNSIGNED NULL, `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (`codeventoagenda`), UNIQUE KEY `UK_AGENDA_EVENTO_IDEMP` (`chave_idempotencia`),
 KEY `IX_AGENDA_EVENTO_USUARIO` (`codusuario_responsavel`,`inicio`,`status`), KEY `IX_AGENDA_EVENTO_PERIODO` (`inicio`,`fim`,`status`),
 CONSTRAINT `CK_AGENDA_EVENTO_STATUS` CHECK (`status` IN ('ATIVO','CONCLUIDO','CANCELADO')),
 CONSTRAINT `FK_AGENDA_EVENTO_CRIADOR` FOREIGN KEY (`codusuario_criador`) REFERENCES `usuarios` (`codusuario`),
 CONSTRAINT `FK_AGENDA_EVENTO_RESPONSAVEL` FOREIGN KEY (`codusuario_responsavel`) REFERENCES `usuarios` (`codusuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS `agenda_recorrencias` (
 `codrecorrencia` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `chave_idempotencia` VARCHAR(191) NOT NULL,
 `codusuario_criador` BIGINT UNSIGNED NULL, `codusuario_responsavel` BIGINT UNSIGNED NULL,
 `titulo` VARCHAR(180) NOT NULL, `descricao` TEXT NULL, `localizacao` VARCHAR(255) NULL,
 `frequencia` VARCHAR(10) NOT NULL, `intervalo` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
 `proxima_execucao` DATETIME NOT NULL, `ultima_execucao` DATETIME NULL, `termina_em` DATETIME NULL,
 `ativo` TINYINT(1) NOT NULL DEFAULT 1, `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (`codrecorrencia`), UNIQUE KEY `UK_AGENDA_REC_IDEMP` (`chave_idempotencia`), KEY `IX_AGENDA_REC_FILA` (`ativo`,`proxima_execucao`),
 CONSTRAINT `CK_AGENDA_REC_FREQ` CHECK (`frequencia` IN ('DIARIA','SEMANAL','MENSAL')),
 CONSTRAINT `FK_AGENDA_REC_CRIADOR` FOREIGN KEY (`codusuario_criador`) REFERENCES `usuarios` (`codusuario`),
 CONSTRAINT `FK_AGENDA_REC_RESPONSAVEL` FOREIGN KEY (`codusuario_responsavel`) REFERENCES `usuarios` (`codusuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS `agenda_execucoes` (
 `codagendaexecucao` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `codrecorrencia` BIGINT UNSIGNED NOT NULL,
 `referencia` DATETIME NOT NULL, `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`codagendaexecucao`), UNIQUE KEY `UK_AGENDA_EXECUCAO` (`codrecorrencia`,`referencia`),
 CONSTRAINT `FK_AGENDA_EXEC_REC` FOREIGN KEY (`codrecorrencia`) REFERENCES `agenda_recorrencias` (`codrecorrencia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
INSERT INTO `registros_migracao` (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('GREEN_AGENDA_186','AgendaService','agenda_eventos/agenda_recorrencias/agenda_execucoes','ESTRUTURA','4.0.1','CONCLUIDA',0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,JSON_OBJECT('worker','agenda_worker.php','frequencias',JSON_ARRAY('DIARIA','SEMANAL','MENSAL'),'idempotente',TRUE))
ON DUPLICATE KEY UPDATE `status`=VALUES(`status`),`concluida_em`=VALUES(`concluida_em`),`detalhes`=VALUES(`detalhes`);
