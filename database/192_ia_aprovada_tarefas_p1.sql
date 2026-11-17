/* P1: resultados aprovados da IA tornam-se tarefas operacionais idempotentes. */
SET NAMES utf8mb4;

SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='green_tasks' AND COLUMN_NAME='codunidade')=0,
    'ALTER TABLE green_tasks ADD COLUMN codunidade BIGINT UNSIGNED NULL AFTER origem_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='green_tasks' AND INDEX_NAME='ix_green_tasks_unidade')=0,
    'CREATE INDEX ix_green_tasks_unidade ON green_tasks (codunidade,status)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO registros_migracao
    (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros,
     iniciada_em, concluida_em, detalhes)
VALUES
    ('GREEN_AI_APPROVED_TASKS_192', 'ml_potenciais aprovados', 'green_tasks, alertas, logs_auditoria',
     'ESTRUTURA', '4.0.1', 'CONCLUIDA', 0, NOW(), NOW(),
     JSON_OBJECT('worker', 'GreenPortal-IaWorker',
                 'idempotencia', 'IA-POTENCIAL:<codpotencial>',
                 'responsavel_inicial', 'usuário solicitante',
                 'unidade', 'contexto_json.CODUNIDADE'))
ON DUPLICATE KEY UPDATE
    status=VALUES(status), concluida_em=VALUES(concluida_em), detalhes=VALUES(detalhes);
