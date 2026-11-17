-- Auditoria automática de todas as mudanças de etapa das NCs da Empresa.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

DROP TRIGGER IF EXISTS `tr_nc_indicacao_ocorrencia_workflow_audit`;
DELIMITER $$
CREATE TRIGGER `tr_nc_indicacao_ocorrencia_workflow_audit`
AFTER UPDATE ON `nc_indicacao_ocorrencia`
FOR EACH ROW
BEGIN
  IF NOT (OLD.`ETAPA` <=> NEW.`ETAPA`) AND NEW.`CODTIPONAOCONFORMIDADE` = 3
     AND COALESCE(@nc_workflow_user, 0) > 0 THEN
    INSERT INTO `nc_workflow_historico`
      (`CODOCORRENCIA`,`ACAO`,`ETAPA_ANTERIOR`,`ETAPA_NOVA`,`CODUSUARIO`,`MOTIVO`)
    VALUES
      (NEW.`CODOCORRENCIA`,
       CASE NEW.`ETAPA`
         WHEN 'Com o Gestor' THEN 'ENVIO_GESTOR'
         WHEN 'Em Analise da Causa Raiz' THEN 'ENVIO_ANALISE_CAUSA'
         WHEN 'Avaliação da Eficacia' THEN 'ENVIO_AVALIACAO_EFICACIA'
         WHEN 'Finalizado' THEN 'FINALIZACAO'
         WHEN 'Em criação' THEN 'DEVOLUCAO_EMITENTE'
         ELSE 'MUDANCA_ETAPA'
       END,
       OLD.`ETAPA`, NEW.`ETAPA`, @nc_workflow_user,
       CASE WHEN NEW.`ETAPA` IN ('Em criação','Recusado pelo Gestor','Em Analise da Causa Raiz')
            THEN COALESCE(NULLIF(NEW.`RECUSA_DO_GESTOR`, ''), NULL) ELSE NULL END);
  END IF;
END$$
DELIMITER ;
