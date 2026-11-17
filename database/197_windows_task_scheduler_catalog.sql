-- Catálogo central dos executores PHP no Agendador do Windows.
USE `guniversal_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

INSERT INTO `servicos` (`codigo`,`nome_pt`,`nome_en`,`nome_es`,`descricao_pt`,`descricao_en`,`descricao_es`,`host`,`porta`,`script`,`ordem`,`ativo`)
VALUES
 ('TASK_WORKER','Tarefas e notificações','Tasks and notifications','Tareas y notificaciones','Processa tarefas, notificações e pendências.','Processes tasks, notifications and pending items.','Procesa tareas, notificaciones y pendientes.','TASK_SCHEDULER',0,'scripts/task-worker.php',1,1),
 ('AGENDA_WORKER','Agenda','Agenda','Agenda','Materializa ocorrências recorrentes da agenda.','Materializes recurring agenda occurrences.','Materializa ocurrencias recurrentes de agenda.','TASK_SCHEDULER',0,'scripts/agenda-worker.php',2,1),
 ('RESPONSIBILITY_WORKER','Responsabilidades','Responsibilities','Responsabilidades','Gera tarefas para responsabilidades próximas do vencimento.','Generates tasks for responsibilities nearing due date.','Genera tareas para responsabilidades próximas al vencimiento.','TASK_SCHEDULER',0,'scripts/responsibility-worker.php',3,1),
 ('WORKFLOW_WORKER','Workflow','Workflow','Workflow','Monitora instâncias de workflow ativas.','Monitors active workflow instances.','Monitorea instancias de workflow activas.','TASK_SCHEDULER',0,'scripts/workflow-worker.php',4,1),
 ('CONTENT_WORKER','Conteúdo e GED','Content and GED','Contenido y GED','Monitora conteúdos em revisão.','Monitors content under review.','Monitorea contenidos en revisión.','TASK_SCHEDULER',0,'scripts/content-worker.php',5,1),
 ('GED_WORKER','GED','GED','GED','Verifica o armazenamento físico do GED.','Checks physical GED storage.','Verifica el almacenamiento físico del GED.','TASK_SCHEDULER',0,'scripts/ged-worker.php',6,1),
 ('INTELIGENCIA_ARTIFICIAL','Inteligência Artificial','Artificial Intelligence','Inteligencia Artificial','Processa análises e potenciais de IA por tenant.','Processes AI analyses and potentials per tenant.','Procesa análisis y potenciales de IA por tenant.','TASK_SCHEDULER',0,'scripts/ia-worker.php',7,1),
 ('OCR_WORKER','OCR','OCR','OCR','Processa a fila de reconhecimento óptico.','Processes the optical recognition queue.','Procesa la cola de reconocimiento óptico.','TASK_SCHEDULER',0,'scripts/ocr-worker.php',8,1),
 ('NOTIFICATION_WORKER','Notificações','Notifications','Notificaciones','Processa notificações pendentes.','Processes pending notifications.','Procesa notificaciones pendientes.','TASK_SCHEDULER',0,'scripts/notification-worker.php',9,1),
 ('EXPORTACAO_GRID','Exportação Excel','Excel Export','Exportación Excel','Gera planilhas a partir das filas dos grids.','Generates spreadsheets from grid queues.','Genera hojas de cálculo a partir de las colas de grids.','TASK_SCHEDULER',0,'scripts/export-worker.php',10,1)
ON DUPLICATE KEY UPDATE
 `nome_pt`=VALUES(`nome_pt`),`nome_en`=VALUES(`nome_en`),`nome_es`=VALUES(`nome_es`),
 `descricao_pt`=VALUES(`descricao_pt`),`descricao_en`=VALUES(`descricao_en`),`descricao_es`=VALUES(`descricao_es`),
 `host`='TASK_SCHEDULER',`porta`=0,`script`=VALUES(`script`),`ordem`=VALUES(`ordem`),`ativo`=1;

INSERT IGNORE INTO `servicos_status` (`codservico`,`status`)
SELECT `codservico`,'DESCONHECIDO' FROM `servicos` WHERE `ativo`=1;
