-- Os processos de exportação e IA não são serviços HTTP residentes.
-- A execução oficial ocorre pelas tarefas do Agendador do Windows.
USE `guniversal_dbp`;
UPDATE `servicos`
   SET `host`='TASK_SCHEDULER', `porta`=0, `script`='scripts/export-worker.php', `ativo`=1
 WHERE `codigo`='EXPORTACAO_GRID';
UPDATE `servicos`
   SET `host`='TASK_SCHEDULER', `porta`=0, `script`='scripts/ia-worker.php', `ativo`=1
 WHERE `codigo`='INTELIGENCIA_ARTIFICIAL';
