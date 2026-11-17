SET NAMES utf8mb4;
INSERT INTO menus (codmenu,codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,icone,ativo)
SELECT COALESCE((SELECT MAX(codmenu)+1 FROM menus),1),
       (SELECT p.codmenu FROM menus p WHERE p.codigo='ADMINISTRACAO' LIMIT 1),
       'ADMINISTRACAO','QUESTIONARIOS','Questionários','Questionários','Questionnaires','Cuestionarios','/principal/questionarios.php',2,95,'bi-ui-checks-grid',1
WHERE NOT EXISTS (SELECT 1 FROM menus WHERE codigo='QUESTIONARIOS');
UPDATE menus child JOIN menus parent ON parent.codigo='ADMINISTRACAO'
   SET child.codmenu_pai=parent.codmenu,child.modulo='ADMINISTRACAO',child.label='Questionários',child.label_pt='Questionários',child.label_en='Questionnaires',child.label_es='Cuestionarios',child.url='/principal/questionarios.php',child.nivel=2,child.ordem=95,child.icone='bi-ui-checks-grid',child.ativo=1
 WHERE child.codigo='QUESTIONARIOS';
INSERT INTO green_universal_migrations(codigo_migracao,versao,status,detalhes)
VALUES('GREEN_QUESTIONNAIRE_ADMIN_194','4.0.1','CONCLUIDA',JSON_OBJECT('transaction','questionarios.php','api','questionarios.php','publication_immutable',TRUE))
ON DUPLICATE KEY UPDATE status=VALUES(status),detalhes=VALUES(detalhes),executado_em=CURRENT_TIMESTAMP;
