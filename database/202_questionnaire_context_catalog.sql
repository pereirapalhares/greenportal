/* Green Questionnaire — catálogo inicial dos contextos da plataforma. */
SET NAMES utf8mb4;

INSERT INTO amb_questionario
    (codigo,nome_pt,nome_en,nome_es,descricao_pt,modulo,ativo,criado_por)
VALUES
    ('LEGAL_REQUIREMENTS','Avaliação de Requisitos Legais','Legal Requirements Assessment','Evaluación de Requisitos Legales','Questionário para avaliar aplicabilidade, atendimento, evidências e pendências de requisitos legais.','LEGAL',1,NULL),
    ('SGA_COMPLIANCE','Avaliação do SGA','Environmental Management Assessment','Evaluación del SGA','Questionário para licenças, controles, treinamentos, infraestrutura e aspectos ambientais.','SGA',1,NULL),
    ('QUALITY_AUDIT','Auditoria de Qualidade','Quality Audit','Auditoría de Calidad','Questionário para auditorias, não conformidades, ações corretivas e eficácia.','QUALITY',1,NULL),
    ('WORKFLOW_CHECKLIST','Checklist de Workflow','Workflow Checklist','Lista de Verificación de Workflow','Questionário utilizado como checklist dentro de processos e aprovações.','WORKFLOW',1,NULL),
    ('SUPPLIER_HOMOLOGATION','Homologação de Fornecedor','Supplier Qualification','Homologación de Proveedor','Questionário ponderado para homologação, risco e avaliação de fornecedores.','SUPPLIER',1,NULL),
    ('ESG_ASSESSMENT','Avaliação ESG','ESG Assessment','Evaluación ESG','Questionário para indicadores ambientais, sociais e de governança.','ESG',1,NULL),
    ('PROJECT_DELIVERABLES','Checklist de Projetos','Project Deliverables Checklist','Lista de Entregables de Proyectos','Questionário para fases, entregáveis, riscos e revisões de projetos.','PROJECTS',1,NULL),
    ('EMPLOYEE_INTEGRATION','Integração de Funcionários','Employee Onboarding','Integración de Empleados','Questionário ponderado de integração, capacitação e ciência de requisitos.','EMPLOYEE_INTEGRATION',1,NULL)
ON DUPLICATE KEY UPDATE
    nome_pt=VALUES(nome_pt),nome_en=VALUES(nome_en),nome_es=VALUES(nome_es),
    descricao_pt=VALUES(descricao_pt),modulo=VALUES(modulo),ativo=VALUES(ativo);

INSERT INTO amb_questionario_versao
    (codquestionario,versao,status,instrucoes_pt,percentual_minimo,criado_por)
SELECT q.codquestionario,1,'RASCUNHO',
       'Versão inicial do catálogo. As perguntas devem ser configuradas e validadas antes da publicação.',
       CASE q.codigo
           WHEN 'LEGAL_REQUIREMENTS' THEN 100
           WHEN 'WORKFLOW_CHECKLIST' THEN 100
           ELSE 80
       END,
       NULL
  FROM amb_questionario q
 WHERE q.codigo IN ('LEGAL_REQUIREMENTS','SGA_COMPLIANCE','QUALITY_AUDIT','WORKFLOW_CHECKLIST',
                    'SUPPLIER_HOMOLOGATION','ESG_ASSESSMENT','PROJECT_DELIVERABLES','EMPLOYEE_INTEGRATION')
   AND NOT EXISTS (
       SELECT 1 FROM amb_questionario_versao v
       WHERE v.codquestionario=q.codquestionario AND v.versao=1
   );

-- Correção de hierarquia: Questionários pertence a Administração.
-- Mantém o mesmo codmenu e apenas repara instalações que receberam a versão
-- inicial sem o vínculo do menu pai.
UPDATE menus child
JOIN menus parent ON parent.codigo='ADMINISTRACAO'
   SET child.codmenu_pai=parent.codmenu,
       child.modulo='ADMINISTRACAO',
       child.nivel=2,
       child.ativo=1
 WHERE child.codigo='QUESTIONARIOS';

INSERT INTO green_universal_migrations(codigo_migracao,versao,status,detalhes)
VALUES(
    'GREEN_QUESTIONNAIRE_CONTEXT_CATALOG_202',
    '4.0.1',
    'CONCLUIDA',
    JSON_OBJECT(
        'contextos',JSON_ARRAY('LEGAL','SGA','QUALITY','WORKFLOW','SUPPLIER','ESG','PROJECTS','EMPLOYEE_INTEGRATION'),
        'questionarios_base',8,
        'status_inicial','RASCUNHO',
        'perguntas_migradas',FALSE,
        'publicacao_imutavel',TRUE
    )
)
ON DUPLICATE KEY UPDATE status=VALUES(status),detalhes=VALUES(detalhes),executado_em=CURRENT_TIMESTAMP;
