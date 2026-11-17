/* P2 Green Questionnaire - integrações Legal, SGA, Qualidade e Workflow. */
SET NAMES utf8mb4;

INSERT INTO workflow_definicoes(codigo,nome,versao,publicado,ativo)
VALUES
 ('LEGAL_QUESTIONNAIRE','Questionário Legal - revisão',1,1,1),
 ('SGA_QUESTIONNAIRE','Questionário SGA - revisão',1,1,1),
 ('QUALITY_QUESTIONNAIRE','Questionário Qualidade - revisão',1,1,1),
 ('WORKFLOW_QUESTIONNAIRE','Questionário Workflow - revisão',1,1,1)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),publicado=1,ativo=1;

INSERT INTO workflow_etapas(codworkflow,codigo,nome,ordem,prazo_dias,ativo)
SELECT codworkflow,'INICIO','Em preenchimento',1,7,1 FROM workflow_definicoes WHERE codigo IN ('LEGAL_QUESTIONNAIRE','SGA_QUESTIONNAIRE','QUALITY_QUESTIONNAIRE','WORKFLOW_QUESTIONNAIRE') AND versao=1
ON DUPLICATE KEY UPDATE nome=VALUES(nome),prazo_dias=VALUES(prazo_dias),ativo=1;
INSERT INTO workflow_etapas(codworkflow,codigo,nome,ordem,prazo_dias,ativo)
SELECT codworkflow,'REVISAO','Em revisão',2,7,1 FROM workflow_definicoes WHERE codigo IN ('LEGAL_QUESTIONNAIRE','SGA_QUESTIONNAIRE','QUALITY_QUESTIONNAIRE','WORKFLOW_QUESTIONNAIRE') AND versao=1
ON DUPLICATE KEY UPDATE nome=VALUES(nome),prazo_dias=VALUES(prazo_dias),ativo=1;
INSERT INTO workflow_etapas(codworkflow,codigo,nome,ordem,ativo)
SELECT codworkflow,'FINALIZADO','Aprovado',3,1 FROM workflow_definicoes WHERE codigo IN ('LEGAL_QUESTIONNAIRE','SGA_QUESTIONNAIRE','QUALITY_QUESTIONNAIRE','WORKFLOW_QUESTIONNAIRE') AND versao=1
ON DUPLICATE KEY UPDATE nome=VALUES(nome),ativo=1;
INSERT INTO workflow_etapas(codworkflow,codigo,nome,ordem,ativo)
SELECT codworkflow,'CANCELADO','Rejeitado',4,1 FROM workflow_definicoes WHERE codigo IN ('LEGAL_QUESTIONNAIRE','SGA_QUESTIONNAIRE','QUALITY_QUESTIONNAIRE','WORKFLOW_QUESTIONNAIRE') AND versao=1
ON DUPLICATE KEY UPDATE nome=VALUES(nome),ativo=1;

INSERT INTO workflow_transicoes(codworkflow,etapa_origem,acao,etapa_destino,requer_motivo,ativo)
SELECT codworkflow,'INICIO','ENVIAR','REVISAO',0,1 FROM workflow_definicoes WHERE codigo IN ('LEGAL_QUESTIONNAIRE','SGA_QUESTIONNAIRE','QUALITY_QUESTIONNAIRE','WORKFLOW_QUESTIONNAIRE') AND versao=1
ON DUPLICATE KEY UPDATE etapa_destino=VALUES(etapa_destino),requer_motivo=0,ativo=1;
INSERT INTO workflow_transicoes(codworkflow,etapa_origem,acao,etapa_destino,requer_motivo,ativo)
SELECT codworkflow,'REVISAO','APROVAR','FINALIZADO',1,1 FROM workflow_definicoes WHERE codigo IN ('LEGAL_QUESTIONNAIRE','SGA_QUESTIONNAIRE','QUALITY_QUESTIONNAIRE','WORKFLOW_QUESTIONNAIRE') AND versao=1
ON DUPLICATE KEY UPDATE etapa_destino=VALUES(etapa_destino),requer_motivo=1,ativo=1;
INSERT INTO workflow_transicoes(codworkflow,etapa_origem,acao,etapa_destino,requer_motivo,ativo)
SELECT codworkflow,'REVISAO','REJEITAR','CANCELADO',1,1 FROM workflow_definicoes WHERE codigo IN ('LEGAL_QUESTIONNAIRE','SGA_QUESTIONNAIRE','QUALITY_QUESTIONNAIRE','WORKFLOW_QUESTIONNAIRE') AND versao=1
ON DUPLICATE KEY UPDATE etapa_destino=VALUES(etapa_destino),requer_motivo=1,ativo=1;

INSERT INTO registros_migracao(codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes)
VALUES('GREEN_QUESTIONNAIRE_CONTEXT_WORKFLOWS_196','QuestionnaireService','Legal/SGA/Qualidade/Workflow','CONFIGURACAO','4.0.1','CONCLUIDA',4,NOW(),NOW(),JSON_OBJECT('contextos',JSON_ARRAY('LEGAL','SGA','QUALITY','WORKFLOW'),'publicacao_imutavel',TRUE,'evidencias_idempotentes',TRUE))
ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
