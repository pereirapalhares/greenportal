/* GreenSolutions 4.0.1 - questionario inicial de exemplo do SGA. */
SET NAMES utf8mb4;

INSERT INTO amb_questionario_pergunta
    (codversao,codigo,texto_pt,tipo,obrigatoria,ordem,peso,ativo)
SELECT v.codversao,'SGA_ASPECTOS_IDENTIFICADOS',
       'Os aspectos e impactos ambientais da unidade estão identificados e atualizados?',
       'SIM_NAO',1,10,1,1
  FROM amb_questionario q
  JOIN amb_questionario_versao v ON v.codquestionario=q.codquestionario
 WHERE q.codigo='SGA_COMPLIANCE' AND v.versao=1 AND v.status='RASCUNHO'
   AND NOT EXISTS (SELECT 1 FROM amb_questionario_pergunta p WHERE p.codversao=v.codversao AND p.codigo='SGA_ASPECTOS_IDENTIFICADOS');

INSERT INTO amb_questionario_pergunta
    (codversao,codigo,texto_pt,tipo,obrigatoria,ordem,peso,ativo)
SELECT v.codversao,'SGA_CONTROLES_AMBIENTAIS',
       'Os controles ambientais definidos para a unidade estão implantados e funcionando?',
       'SIM_NAO',1,20,1,1
  FROM amb_questionario q
  JOIN amb_questionario_versao v ON v.codquestionario=q.codquestionario
 WHERE q.codigo='SGA_COMPLIANCE' AND v.versao=1 AND v.status='RASCUNHO'
   AND NOT EXISTS (SELECT 1 FROM amb_questionario_pergunta p WHERE p.codversao=v.codversao AND p.codigo='SGA_CONTROLES_AMBIENTAIS');

INSERT INTO amb_questionario_pergunta
    (codversao,codigo,texto_pt,tipo,obrigatoria,ordem,peso,ativo)
SELECT v.codversao,'SGA_LICENCAS_VALIDAS',
       'As licenças ambientais aplicáveis à unidade estão válidas e disponíveis?',
       'SIM_NAO',1,30,1,1
  FROM amb_questionario q
  JOIN amb_questionario_versao v ON v.codquestionario=q.codquestionario
 WHERE q.codigo='SGA_COMPLIANCE' AND v.versao=1 AND v.status='RASCUNHO'
   AND NOT EXISTS (SELECT 1 FROM amb_questionario_pergunta p WHERE p.codversao=v.codversao AND p.codigo='SGA_LICENCAS_VALIDAS');

INSERT INTO amb_questionario_pergunta
    (codversao,codigo,texto_pt,tipo,obrigatoria,ordem,peso,ativo)
SELECT v.codversao,'SGA_CONDICIONANTES_ACOMPANHADAS',
       'As condicionantes das licenças estão acompanhadas e dentro dos prazos?',
       'SIM_NAO',1,40,1,1
  FROM amb_questionario q
  JOIN amb_questionario_versao v ON v.codquestionario=q.codquestionario
 WHERE q.codigo='SGA_COMPLIANCE' AND v.versao=1 AND v.status='RASCUNHO'
   AND NOT EXISTS (SELECT 1 FROM amb_questionario_pergunta p WHERE p.codversao=v.codversao AND p.codigo='SGA_CONDICIONANTES_ACOMPANHADAS');

INSERT INTO amb_questionario_pergunta
    (codversao,codigo,texto_pt,tipo,obrigatoria,ordem,peso,ativo)
SELECT v.codversao,'SGA_MONITORAMENTOS_REALIZADOS',
       'As medições e os monitoramentos ambientais previstos foram realizados no período?',
       'SIM_NAO',1,50,1,1
  FROM amb_questionario q
  JOIN amb_questionario_versao v ON v.codquestionario=q.codquestionario
 WHERE q.codigo='SGA_COMPLIANCE' AND v.versao=1 AND v.status='RASCUNHO'
   AND NOT EXISTS (SELECT 1 FROM amb_questionario_pergunta p WHERE p.codversao=v.codversao AND p.codigo='SGA_MONITORAMENTOS_REALIZADOS');

INSERT INTO amb_questionario_pergunta
    (codversao,codigo,texto_pt,tipo,obrigatoria,ordem,peso,ativo)
SELECT v.codversao,'SGA_TREINAMENTOS_ATUALIZADOS',
       'Os treinamentos ambientais necessários foram realizados e estão registrados?',
       'SIM_NAO',1,60,1,1
  FROM amb_questionario q
  JOIN amb_questionario_versao v ON v.codquestionario=q.codquestionario
 WHERE q.codigo='SGA_COMPLIANCE' AND v.versao=1 AND v.status='RASCUNHO'
   AND NOT EXISTS (SELECT 1 FROM amb_questionario_pergunta p WHERE p.codversao=v.codversao AND p.codigo='SGA_TREINAMENTOS_ATUALIZADOS');

INSERT INTO amb_questionario_pergunta
    (codversao,codigo,texto_pt,tipo,obrigatoria,ordem,peso,ativo)
SELECT v.codversao,'SGA_RESIDUOS_CONTROLADOS',
       'Os resíduos gerados estão segregados, armazenados e destinados conforme o procedimento definido?',
       'SIM_NAO',1,70,1,1
  FROM amb_questionario q
  JOIN amb_questionario_versao v ON v.codquestionario=q.codquestionario
 WHERE q.codigo='SGA_COMPLIANCE' AND v.versao=1 AND v.status='RASCUNHO'
   AND NOT EXISTS (SELECT 1 FROM amb_questionario_pergunta p WHERE p.codversao=v.codversao AND p.codigo='SGA_RESIDUOS_CONTROLADOS');

INSERT INTO amb_questionario_pergunta
    (codversao,codigo,texto_pt,tipo,obrigatoria,ordem,peso,ativo)
SELECT v.codversao,'SGA_EMERGENCIA_AMBIENTAL',
       'Os recursos e procedimentos para resposta a emergências ambientais estão disponíveis e testados?',
       'SIM_NAO',1,80,1,1
  FROM amb_questionario q
  JOIN amb_questionario_versao v ON v.codquestionario=q.codquestionario
 WHERE q.codigo='SGA_COMPLIANCE' AND v.versao=1 AND v.status='RASCUNHO'
   AND NOT EXISTS (SELECT 1 FROM amb_questionario_pergunta p WHERE p.codversao=v.codversao AND p.codigo='SGA_EMERGENCIA_AMBIENTAL');

INSERT INTO amb_questionario_pergunta
    (codversao,codigo,texto_pt,tipo,obrigatoria,ordem,peso,ativo)
SELECT v.codversao,'SGA_INFRAESTRUTURA_AMBIENTAL',
       'A infraestrutura ambiental da unidade está adequada, conservada e identificada?',
       'SIM_NAO',1,90,1,1
  FROM amb_questionario q
  JOIN amb_questionario_versao v ON v.codquestionario=q.codquestionario
 WHERE q.codigo='SGA_COMPLIANCE' AND v.versao=1 AND v.status='RASCUNHO'
   AND NOT EXISTS (SELECT 1 FROM amb_questionario_pergunta p WHERE p.codversao=v.codversao AND p.codigo='SGA_INFRAESTRUTURA_AMBIENTAL');

INSERT INTO amb_questionario_pergunta
    (codversao,codigo,texto_pt,tipo,obrigatoria,ordem,peso,ativo)
SELECT v.codversao,'SGA_INDICADORES_ACOMPANHADOS',
       'Os indicadores ambientais da unidade são acompanhados e possuem registros atualizados?',
       'SIM_NAO',1,100,1,1
  FROM amb_questionario q
  JOIN amb_questionario_versao v ON v.codquestionario=q.codquestionario
 WHERE q.codigo='SGA_COMPLIANCE' AND v.versao=1 AND v.status='RASCUNHO'
   AND NOT EXISTS (SELECT 1 FROM amb_questionario_pergunta p WHERE p.codversao=v.codversao AND p.codigo='SGA_INDICADORES_ACOMPANHADOS');

INSERT INTO green_universal_migrations(codigo_migracao,versao,status,detalhes)
VALUES('GREEN_QUESTIONNAIRE_SGA_EXAMPLE_205','4.0.1','CONCLUIDA',JSON_OBJECT('questionario','SGA_COMPLIANCE','versao',1,'perguntas_exemplo',10,'status','RASCUNHO','idempotente',TRUE))
ON DUPLICATE KEY UPDATE status=VALUES(status),detalhes=VALUES(detalhes),executado_em=CURRENT_TIMESTAMP;
