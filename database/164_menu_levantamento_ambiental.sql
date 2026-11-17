-- Organiza Levantamento Ambiental abaixo de Módulo Legal.

USE gmodelo_dbp;

INSERT INTO menus
  (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT p.codmenu,'MODULO_LEGAL','LEVANTAMENTO_AMBIENTAL',
       'Levantamento Ambiental','Levantamento Ambiental',
       'Environmental Survey','Levantamiento Ambiental',
       NULL,2,20,1
  FROM menus p
 WHERE p.codigo='MODULO_LEGAL'
   AND NOT EXISTS
       (SELECT 1 FROM menus x WHERE x.codigo='LEVANTAMENTO_AMBIENTAL');

UPDATE menus
   SET codmenu_pai=(SELECT codmenu FROM
       (SELECT codmenu FROM menus WHERE codigo='MODULO_LEGAL') x),
       modulo='MODULO_LEGAL', nivel=2, ordem=20, ativo=1,
       label='Levantamento Ambiental',
       label_pt='Levantamento Ambiental',
       label_en='Environmental Survey',
       label_es='Levantamiento Ambiental',
       url=NULL
 WHERE codigo='LEVANTAMENTO_AMBIENTAL';

INSERT INTO permissoes
  (codperfil,codmenu,pode_incluir,pode_visualizar,pode_visualizar_total,
   pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf)
SELECT p.codperfil,m.codmenu,p.pode_incluir,p.pode_visualizar,
       p.pode_visualizar_total,p.pode_editar,p.pode_excluir,
       p.pode_exportar_excel,p.pode_gerar_pdf
  FROM permissoes p
  JOIN menus origem ON origem.codmenu=p.codmenu
  JOIN menus m ON m.codigo='LEVANTAMENTO_AMBIENTAL'
 WHERE origem.codigo='MODULO_LEGAL'
   AND NOT EXISTS
       (SELECT 1 FROM permissoes x
         WHERE x.codperfil=p.codperfil AND x.codmenu=m.codmenu);

UPDATE menus
   SET codmenu_pai=(SELECT codmenu FROM
       (SELECT codmenu FROM menus WHERE codigo='LEVANTAMENTO_AMBIENTAL') x),
       modulo='MODULO_LEGAL', nivel=3, ativo=1
 WHERE codigo IN
       ('LAIA','LAIA_ATIVIDADES','ASPECTOS','LAIA_DESTINOS','IMPACTOS','LAIA_REVISAO');

UPDATE menus SET ordem=1 WHERE codigo='LAIA';
UPDATE menus SET ordem=2 WHERE codigo='LAIA_ATIVIDADES';
UPDATE menus SET ordem=3 WHERE codigo='ASPECTOS';
UPDATE menus SET ordem=4 WHERE codigo='LAIA_DESTINOS';
UPDATE menus SET ordem=5 WHERE codigo='IMPACTOS';
UPDATE menus SET ordem=6 WHERE codigo='LAIA_REVISAO';

INSERT INTO registros_migracao
  (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,
   iniciada_em,concluida_em,detalhes)
VALUES
  ('MENU_LEVANTAMENTO_AMBIENTAL_164',
   'gmodelo_dbp.menus',
   'gmodelo_dbp.menus e gmodelo_dbp.permissoes',
   'ESTRUTURA','1.0.0','CONCLUIDA',
   (SELECT COUNT(*) FROM menus WHERE codigo IN
      ('LEVANTAMENTO_AMBIENTAL','LAIA','LAIA_ATIVIDADES','ASPECTOS',
       'LAIA_DESTINOS','IMPACTOS','LAIA_REVISAO')),
   NOW(),NOW(),
   JSON_OBJECT('pai','MODULO_LEGAL',
               'submenus',JSON_ARRAY('LAIA','LAIA_ATIVIDADES','ASPECTOS',
                                     'LAIA_DESTINOS','IMPACTOS','LAIA_REVISAO'),
               'transacoes_reutilizadas',TRUE))
ON DUPLICATE KEY UPDATE
  status=VALUES(status), quantidade_registros=VALUES(quantidade_registros),
  concluida_em=VALUES(concluida_em), detalhes=VALUES(detalhes);
