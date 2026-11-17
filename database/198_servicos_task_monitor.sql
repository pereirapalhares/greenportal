USE `guniversal_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `servicos_task_status` (
    `codservico` BIGINT UNSIGNED NOT NULL,
    `codigo` VARCHAR(80) NOT NULL,
    `task_name` VARCHAR(200) NOT NULL,
    `task_path` VARCHAR(255) NOT NULL,
    `task_state` VARCHAR(40) NOT NULL DEFAULT 'NAO_INSTALADO',
    `last_task_result` BIGINT NULL,
    `last_run_time` DATETIME NULL,
    `next_run_time` DATETIME NULL,
    `mensagem` VARCHAR(1000) NULL,
    `coletado_em` DATETIME NOT NULL,
    PRIMARY KEY (`codservico`),
    UNIQUE KEY `uk_servicos_task_status_codigo` (`codigo`),
    KEY `ix_servicos_task_status_coleta` (`coletado_em`),
    CONSTRAINT `fk_servicos_task_status_servico` FOREIGN KEY (`codservico`) REFERENCES `servicos` (`codservico`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `servicos_fila_status` (
    `codigo_servico` VARCHAR(80) NOT NULL,
    `banco_dados` VARCHAR(128) NOT NULL,
    `tipo` VARCHAR(80) NOT NULL,
    `pendentes` INT NOT NULL DEFAULT 0,
    `processando` INT NOT NULL DEFAULT 0,
    `concluidas` INT NOT NULL DEFAULT 0,
    `erros` INT NOT NULL DEFAULT 0,
    `coletado_em` DATETIME NOT NULL,
    PRIMARY KEY (`codigo_servico`,`banco_dados`),
    KEY `ix_servicos_fila_status_coleta` (`coletado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `servicos_exportacoes_cache` (
    `banco_dados` VARCHAR(128) NOT NULL,
    `codexportacaogrid` BIGINT UNSIGNED NOT NULL,
    `empresa_nome` VARCHAR(150) NULL,
    `solicitado_em` DATETIME NULL,
    `concluido_em` DATETIME NULL,
    `usuario_nome` VARCHAR(150) NULL,
    `nome_planilha` VARCHAR(255) NULL,
    `status` VARCHAR(30) NULL,
    `coletado_em` DATETIME NOT NULL,
    PRIMARY KEY (`banco_dados`,`codexportacaogrid`),
    KEY `ix_servicos_exportacoes_cache_data` (`solicitado_em`),
    KEY `ix_servicos_exportacoes_cache_coleta` (`coletado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `servicos_monitoramento_execucoes` (
    `codmonitoramento` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `inicio_em` DATETIME NOT NULL,
    `fim_em` DATETIME NULL,
    `status` VARCHAR(20) NOT NULL,
    `tarefas` INT NOT NULL DEFAULT 0,
    `filas` INT NOT NULL DEFAULT 0,
    `mensagem` VARCHAR(2000) NULL,
    PRIMARY KEY (`codmonitoramento`),
    KEY `ix_servicos_monitoramento_execucoes_inicio` (`inicio_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `servicos` (`codigo`,`nome_pt`,`nome_en`,`nome_es`,`descricao_pt`,`descricao_en`,`descricao_es`,`host`,`porta`,`script`,`ordem`,`ativo`)
VALUES ('SERVICE_MONITOR','Monitor de serviços','Service monitor','Monitor de servicios','Atualiza o snapshot das tarefas do Windows.','Updates the Windows task snapshot.','Actualiza el snapshot de tareas de Windows.','TASK_SCHEDULER',0,'scripts/service-monitor.php',11,1)
ON DUPLICATE KEY UPDATE `nome_pt`=VALUES(`nome_pt`),`nome_en`=VALUES(`nome_en`),`nome_es`=VALUES(`nome_es`),`descricao_pt`=VALUES(`descricao_pt`),`descricao_en`=VALUES(`descricao_en`),`descricao_es`=VALUES(`descricao_es`),`host`='TASK_SCHEDULER',`porta`=0,`script`=VALUES(`script`),`ordem`=11,`ativo`=1;
INSERT IGNORE INTO `servicos_status` (`codservico`,`status`) SELECT `codservico`,'DESCONHECIDO' FROM `servicos` WHERE `codigo`='SERVICE_MONITOR';
