-- Organiza Levantamento Ocupacional abaixo de Módulo Legal.

USE gmodelo_dbp;

INSERT INTO menus
  (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT p.codmenu,'MODULO_LEGAL','LEVANTAMENTO_OCUPACIONAL',
       'Levantamento Ocupacional','Levantamento Ocupacional',
       'Occupational Survey','Levantamiento Ocupacional',
       NULL,2,21,1
  FROM menus p
 WHERE p.codigo='MODULO_LEGAL'
   AND NOT EXISTS
       (SELECT 1 FROM menus x WHERE x.codigo='LEVANTAMENTO_OCUPACIONAL');

UPDATE menus
   SET codmenu_pai=(SELECT codmenu FROM
       (SELECT codmenu FROM menus WHERE codigo='MODULO_LEGAL') x),
       modulo='MODULO_LEGAL', nivel=2, ordem=21, ativo=1,
       label='Levantamento Ocupacional',
       label_pt='Levantamento Ocupacional',
       label_en='Occupational Survey',
       label_es='Levantamiento Ocupacional',
       url=NULL
 WHERE codigo='LEVANTAMENTO_OCUPACIONAL';

UPDATE menus
   SET codmenu_pai=(SELECT codmenu FROM
       (SELECT codmenu FROM menus WHERE codigo='LEVANTAMENTO_OCUPACIONAL') x),
       modulo='MODULO_LEGAL', nivel=3, ordem=1, ativo=1
 WHERE codigo='LPRO';

UPDATE menus
   SET codmenu_pai=(SELECT codmenu FROM
       (SELECT codmenu FROM menus WHERE codigo='LEVANTAMENTO_OCUPACIONAL') x),
       modulo='MODULO_LEGAL', nivel=3, ordem=5, ativo=1
 WHERE codigo='LPRO_REVISAO';

INSERT INTO menus
  (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT p.codmenu,'MODULO_LEGAL','LPRO_ATIVIDADES',
       'Atividades','Atividades','Activities','Actividades',
       origem.url,3,2,1
  FROM menus p
  JOIN menus origem ON origem.codigo='LAIA_ATIVIDADES'
 WHERE p.codigo='LEVANTAMENTO_OCUPACIONAL'
   AND NOT EXISTS (SELECT 1 FROM menus x WHERE x.codigo='LPRO_ATIVIDADES');

INSERT INTO menus
  (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT p.codmenu,'MODULO_LEGAL','LPRO_PERIGOS',
       'Perigos','Perigos','Hazards','Peligros',
       origem.url,3,3,1
  FROM menus p
  JOIN menus origem ON origem.codigo='PERIGOS'
 WHERE p.codigo='LEVANTAMENTO_OCUPACIONAL'
   AND NOT EXISTS (SELECT 1 FROM menus x WHERE x.codigo='LPRO_PERIGOS');

INSERT INTO menus
  (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT p.codmenu,'MODULO_LEGAL','LPRO_RISCOS',
       'Riscos','Riscos','Risks','Riesgos',
       origem.url,3,4,1
  FROM menus p
  JOIN menus origem ON origem.codigo='RISCOS'
 WHERE p.codigo='LEVANTAMENTO_OCUPACIONAL'
   AND NOT EXISTS (SELECT 1 FROM menus x WHERE x.codigo='LPRO_RISCOS');

INSERT IGNORE INTO permissoes
  (codperfil,codmenu,pode_incluir,pode_visualizar,pode_visualizar_total,
   pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf)
SELECT p.codperfil,m.codmenu,p.pode_incluir,p.pode_visualizar,
       p.pode_visualizar_total,p.pode_editar,p.pode_excluir,
       p.pode_exportar_excel,p.pode_gerar_pdf
  FROM permissoes p
  JOIN menus origem ON origem.codmenu=p.codmenu
  JOIN menus m ON m.codigo IN ('LEVANTAMENTO_OCUPACIONAL','LPRO_ATIVIDADES',
                              'LPRO_PERIGOS','LPRO_RISCOS')
 WHERE origem.codigo IN ('MODULO_LEGAL','LAIA_ATIVIDADES','PERIGOS','RISCOS')
   AND NOT EXISTS
       (SELECT 1 FROM permissoes x
         WHERE x.codperfil=p.codperfil AND x.codmenu=m.codmenu);

INSERT INTO registros_migracao
  (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,
   iniciada_em,concluida_em,detalhes)
VALUES
  ('MENU_LEVANTAMENTO_OCUPACIONAL_165',
   'gmodelo_dbp.menus',
   'gmodelo_dbp.menus e gmodelo_dbp.permissoes',
   'ESTRUTURA','1.0.0','CONCLUIDA',
   (SELECT COUNT(*) FROM menus WHERE codigo IN
      ('LEVANTAMENTO_OCUPACIONAL','LPRO','LPRO_ATIVIDADES',
       'LPRO_PERIGOS','LPRO_RISCOS','LPRO_REVISAO')),
   NOW(),NOW(),
   JSON_OBJECT('pai','MODULO_LEGAL',
               'submenus',JSON_ARRAY('LPRO','LPRO_ATIVIDADES','LPRO_PERIGOS',
                                     'LPRO_RISCOS','LPRO_REVISAO'),
               'transacoes_reutilizadas',TRUE))
ON DUPLICATE KEY UPDATE
  status=VALUES(status), quantidade_registros=VALUES(quantidade_registros),
  concluida_em=VALUES(concluida_em), detalhes=VALUES(detalhes);
