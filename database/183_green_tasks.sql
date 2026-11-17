-- Green Tasks v1: fila idempotente por tenant e histórico de ciclo de vida.
-- Aplique em cada banco operacional: gatlas_dbp, gmodelo_dbp e gmodelo2_dbp.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `green_tasks` (
    `codtarefa` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `chave_idempotencia` VARCHAR(191) NOT NULL,
    `tipo` VARCHAR(50) NOT NULL,
    `origem_transacao` VARCHAR(150) NULL,
    `origem_entidade` VARCHAR(100) NULL,
    `origem_id` VARCHAR(100) NULL,
    `titulo` VARCHAR(180) NOT NULL,
    `descricao` TEXT NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
    `prioridade` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `codusuario_destino` BIGINT UNSIGNED NULL,
    `codgrupo_destino` BIGINT UNSIGNED NULL,
    `data_limite` DATETIME NULL,
    `tentativas` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `disponivel_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `bloqueado_ate` DATETIME NULL,
    `processado_em` DATETIME NULL,
    `erro` VARCHAR(2000) NULL,
    `criado_por` BIGINT UNSIGNED NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`codtarefa`),
    UNIQUE KEY `uk_green_tasks_idempotencia` (`chave_idempotencia`),
    KEY `ix_green_tasks_fila` (`status`,`disponivel_em`,`bloqueado_ate`,`prioridade`),
    KEY `ix_green_tasks_destino` (`codusuario_destino`,`status`),
    KEY `ix_green_tasks_origem` (`origem_entidade`,`origem_id`,`tipo`),
    CONSTRAINT `ck_green_tasks_status` CHECK (`status` IN ('PENDENTE','PROCESSANDO','CONCLUIDA','ERRO','CANCELADA')),
    CONSTRAINT `ck_green_tasks_destino` CHECK (`codusuario_destino` IS NOT NULL OR `codgrupo_destino` IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `green_task_history` (
    `codhistoricotarefa` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codtarefa` BIGINT UNSIGNED NOT NULL,
    `status` VARCHAR(20) NOT NULL,
    `detalhe` VARCHAR(2000) NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codhistoricotarefa`),
    KEY `ix_green_task_history_task` (`codtarefa`,`criado_em`),
    CONSTRAINT `fk_green_task_history_task` FOREIGN KEY (`codtarefa`) REFERENCES `green_tasks` (`codtarefa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `registros_migracao`
    (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES
    ('GREEN_TASKS_183','Green Platform P1','green_tasks/green_task_history','ESTRUTURA','4.0.1','CONCLUIDA',0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
     JSON_OBJECT('worker','scripts/task-worker.php','tipos_inicialmente_suportados',JSON_ARRAY('NOTIFICACAO'),'idempotente',TRUE))
ON DUPLICATE KEY UPDATE `status`=VALUES(`status`),`versao`=VALUES(`versao`),`concluida_em`=VALUES(`concluida_em`),`detalhes`=VALUES(`detalhes`);
