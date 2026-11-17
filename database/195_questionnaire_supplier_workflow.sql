SET NAMES utf8mb4;
INSERT INTO workflow_definicoes(codigo,nome,versao,publicado,ativo)
VALUES('SUPPLIER_QUESTIONNAIRE','Avaliação de fornecedor por questionário',1,1,1)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),publicado=1,ativo=1;
INSERT INTO workflow_etapas(codworkflow,codigo,nome,ordem,prazo_dias,ativo)
SELECT codworkflow,'INICIO','Em preenchimento',1,10,1 FROM workflow_definicoes WHERE codigo='SUPPLIER_QUESTIONNAIRE' AND versao=1
ON DUPLICATE KEY UPDATE nome=VALUES(nome),prazo_dias=VALUES(prazo_dias),ativo=1;
INSERT INTO workflow_etapas(codworkflow,codigo,nome,ordem,ativo)
SELECT codworkflow,'FINALIZADO','Aprovado',2,1 FROM workflow_definicoes WHERE codigo='SUPPLIER_QUESTIONNAIRE' AND versao=1
ON DUPLICATE KEY UPDATE nome=VALUES(nome),ativo=1;
INSERT INTO workflow_etapas(codworkflow,codigo,nome,ordem,ativo)
SELECT codworkflow,'CANCELADO','Rejeitado',3,1 FROM workflow_definicoes WHERE codigo='SUPPLIER_QUESTIONNAIRE' AND versao=1
ON DUPLICATE KEY UPDATE nome=VALUES(nome),ativo=1;
INSERT INTO workflow_transicoes(codworkflow,etapa_origem,acao,etapa_destino,requer_motivo,ativo)
SELECT codworkflow,'INICIO','APROVAR','FINALIZADO',1,1 FROM workflow_definicoes WHERE codigo='SUPPLIER_QUESTIONNAIRE' AND versao=1
ON DUPLICATE KEY UPDATE etapa_destino=VALUES(etapa_destino),requer_motivo=1,ativo=1;
INSERT INTO workflow_transicoes(codworkflow,etapa_origem,acao,etapa_destino,requer_motivo,ativo)
SELECT codworkflow,'INICIO','REJEITAR','CANCELADO',1,1 FROM workflow_definicoes WHERE codigo='SUPPLIER_QUESTIONNAIRE' AND versao=1
ON DUPLICATE KEY UPDATE etapa_destino=VALUES(etapa_destino),requer_motivo=1,ativo=1;
INSERT INTO registros_migracao(codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes)
VALUES('GREEN_QUESTIONNAIRE_SUPPLIER_WORKFLOW_195','QuestionnaireService','workflow_definicoes/etapas/transicoes','CONFIGURACAO','4.0.1','CONCLUIDA',1,NOW(),NOW(),JSON_OBJECT('codigo_workflow','SUPPLIER_QUESTIONNAIRE','aprovacao_humana',TRUE))
ON DUPLICATE KEY UPDATE status=VALUES(status),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
