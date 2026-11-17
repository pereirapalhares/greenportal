/* Green Questionnaire — workflows dos contextos futuros. */
SET NAMES utf8mb4;

INSERT INTO workflow_definicoes(codigo,nome,versao,publicado,ativo)
VALUES
 ('ESG_QUESTIONNAIRE','Questionário ESG - revisão',1,1,1),
 ('PROJECTS_QUESTIONNAIRE','Questionário de Projetos - revisão',1,1,1),
 ('EMPLOYEE_INTEGRATION_QUESTIONNAIRE','Questionário de Integração de Funcionários - revisão',1,1,1)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),publicado=1,ativo=1;

INSERT INTO workflow_etapas(codworkflow,codigo,nome,ordem,prazo_dias,ativo)
SELECT codworkflow,'INICIO','Em preenchimento',1,7,1
  FROM workflow_definicoes
 WHERE codigo IN ('ESG_QUESTIONNAIRE','PROJECTS_QUESTIONNAIRE','EMPLOYEE_INTEGRATION_QUESTIONNAIRE') AND versao=1
ON DUPLICATE KEY UPDATE nome=VALUES(nome),prazo_dias=VALUES(prazo_dias),ativo=1;

INSERT INTO workflow_etapas(codworkflow,codigo,nome,ordem,prazo_dias,ativo)
SELECT codworkflow,'REVISAO','Em revisão',2,7,1
  FROM workflow_definicoes
 WHERE codigo IN ('ESG_QUESTIONNAIRE','PROJECTS_QUESTIONNAIRE','EMPLOYEE_INTEGRATION_QUESTIONNAIRE') AND versao=1
ON DUPLICATE KEY UPDATE nome=VALUES(nome),prazo_dias=VALUES(prazo_dias),ativo=1;

INSERT INTO workflow_etapas(codworkflow,codigo,nome,ordem,ativo)
SELECT codworkflow,'FINALIZADO','Aprovado',3,1
  FROM workflow_definicoes
 WHERE codigo IN ('ESG_QUESTIONNAIRE','PROJECTS_QUESTIONNAIRE','EMPLOYEE_INTEGRATION_QUESTIONNAIRE') AND versao=1
ON DUPLICATE KEY UPDATE nome=VALUES(nome),ativo=1;

INSERT INTO workflow_etapas(codworkflow,codigo,nome,ordem,ativo)
SELECT codworkflow,'CANCELADO','Rejeitado',4,1
  FROM workflow_definicoes
 WHERE codigo IN ('ESG_QUESTIONNAIRE','PROJECTS_QUESTIONNAIRE','EMPLOYEE_INTEGRATION_QUESTIONNAIRE') AND versao=1
ON DUPLICATE KEY UPDATE nome=VALUES(nome),ativo=1;

INSERT INTO workflow_transicoes(codworkflow,etapa_origem,acao,etapa_destino,requer_motivo,ativo)
SELECT codworkflow,'INICIO','ENVIAR','REVISAO',0,1
  FROM workflow_definicoes
 WHERE codigo IN ('ESG_QUESTIONNAIRE','PROJECTS_QUESTIONNAIRE','EMPLOYEE_INTEGRATION_QUESTIONNAIRE') AND versao=1
ON DUPLICATE KEY UPDATE etapa_destino=VALUES(etapa_destino),requer_motivo=0,ativo=1;

INSERT INTO workflow_transicoes(codworkflow,etapa_origem,acao,etapa_destino,requer_motivo,ativo)
SELECT codworkflow,'REVISAO','APROVAR','FINALIZADO',1,1
  FROM workflow_definicoes
 WHERE codigo IN ('ESG_QUESTIONNAIRE','PROJECTS_QUESTIONNAIRE','EMPLOYEE_INTEGRATION_QUESTIONNAIRE') AND versao=1
ON DUPLICATE KEY UPDATE etapa_destino=VALUES(etapa_destino),requer_motivo=1,ativo=1;

INSERT INTO workflow_transicoes(codworkflow,etapa_origem,acao,etapa_destino,requer_motivo,ativo)
SELECT codworkflow,'REVISAO','REJEITAR','CANCELADO',1,1
  FROM workflow_definicoes
 WHERE codigo IN ('ESG_QUESTIONNAIRE','PROJECTS_QUESTIONNAIRE','EMPLOYEE_INTEGRATION_QUESTIONNAIRE') AND versao=1
ON DUPLICATE KEY UPDATE etapa_destino=VALUES(etapa_destino),requer_motivo=1,ativo=1;
