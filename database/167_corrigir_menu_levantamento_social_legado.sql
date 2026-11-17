-- Corrige o Levantamento Social para refletir a hierarquia legada.

USE gmodelo_dbp;

UPDATE menus
   SET codmenu_pai=(SELECT codmenu FROM
       (SELECT codmenu FROM menus WHERE codigo='MODULO_LEGAL') x),
       modulo='MODULO_LEGAL', nivel=2, ativo=1
 WHERE codigo IN ('LAIS_REQUISITOS_LEGAIS','LAIS_CONTROLES_DOCUMENTOS',
                  'LAIS_OBJETIVOS_METAS');
UPDATE menus SET ordem=26 WHERE codigo='LAIS_REQUISITOS_LEGAIS';
UPDATE menus SET ordem=27 WHERE codigo='LAIS_CONTROLES_DOCUMENTOS';
UPDATE menus SET ordem=28 WHERE codigo='LAIS_OBJETIVOS_METAS';

INSERT INTO menus
  (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT p.codmenu,'MODULO_LEGAL','LAIS_ATIVIDADES',
       'Atividades','Atividades','Activities','Actividades',
       origem.url,3,2,1
  FROM menus p JOIN menus origem ON origem.codigo='LAIA_ATIVIDADES'
 WHERE p.codigo='LEVANTAMENTO_SOCIAL'
   AND NOT EXISTS (SELECT 1 FROM menus x WHERE x.codigo='LAIS_ATIVIDADES');

INSERT INTO menus
  (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT p.codmenu,'MODULO_LEGAL','LAIS_PARTES_INTERESSADAS',
       'Parte Interessada','Parte Interessada','Stakeholder','Parte interesada',
       '/principal/moduloleglais_partesinteressadas.php',3,3,1
  FROM menus p
 WHERE p.codigo='LEVANTAMENTO_SOCIAL'
   AND NOT EXISTS (SELECT 1 FROM menus x WHERE x.codigo='LAIS_PARTES_INTERESSADAS');

INSERT INTO menus
  (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT p.codmenu,'MODULO_LEGAL','LAIS_ASPECTOS_SOCIAIS',
       'Aspectos Sociais','Aspectos Sociais','Social Aspects','Aspectos Sociales',
       '/principal/moduloleglais_aspectossociais.php',3,4,1
  FROM menus p
 WHERE p.codigo='LEVANTAMENTO_SOCIAL'
   AND NOT EXISTS (SELECT 1 FROM menus x WHERE x.codigo='LAIS_ASPECTOS_SOCIAIS');

INSERT INTO menus
  (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT p.codmenu,'MODULO_LEGAL','LAIS_IMPACTOS_SOCIAIS',
       'Impactos Sociais','Impactos Sociais','Social Impacts','Impactos Sociales',
       '/principal/moduloleglais_impactossociais.php',3,5,1
  FROM menus p
 WHERE p.codigo='LEVANTAMENTO_SOCIAL'
   AND NOT EXISTS (SELECT 1 FROM menus x WHERE x.codigo='LAIS_IMPACTOS_SOCIAIS');

INSERT INTO menus
  (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT p.codmenu,'MODULO_LEGAL','LAIS_REVISOES',
       'Revisões LAIS','Revisões LAIS','LAIS Reviews','Revisiones LAIS',
       '/principal/moduloleglais_revisoes.php',3,6,1
  FROM menus p
 WHERE p.codigo='LEVANTAMENTO_SOCIAL'
   AND NOT EXISTS (SELECT 1 FROM menus x WHERE x.codigo='LAIS_REVISOES');

INSERT IGNORE INTO permissoes
  (codperfil,codmenu,pode_incluir,pode_visualizar,pode_visualizar_total,
   pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf)
SELECT p.codperfil,m.codmenu,p.pode_incluir,p.pode_visualizar,
       p.pode_visualizar_total,p.pode_editar,p.pode_excluir,
       p.pode_exportar_excel,p.pode_gerar_pdf
  FROM permissoes p
  JOIN menus origem ON origem.codmenu=p.codmenu
  JOIN menus m ON m.codigo IN ('LAIS_ATIVIDADES','LAIS_PARTES_INTERESSADAS',
                              'LAIS_ASPECTOS_SOCIAIS','LAIS_IMPACTOS_SOCIAIS',
                              'LAIS_REVISOES')
 WHERE origem.codigo IN ('LAIA_ATIVIDADES','LAIS')
   AND NOT EXISTS (SELECT 1 FROM permissoes x
         WHERE x.codperfil=p.codperfil AND x.codmenu=m.codmenu);

INSERT INTO registros_migracao
  (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,
   iniciada_em,concluida_em,detalhes)
VALUES
  ('MENU_LEVANTAMENTO_SOCIAL_LEGADO_167',
   'green_seguranca.menus e gmodelo_dbp.menus',
   'gmodelo_dbp.menus e gmodelo_dbp.permissoes',
   'CORRECAO','1.0.0','CONCLUIDA',
   (SELECT COUNT(*) FROM menus WHERE codigo IN
      ('LEVANTAMENTO_SOCIAL','LAIS','LAIS_ATIVIDADES',
       'LAIS_PARTES_INTERESSADAS','LAIS_ASPECTOS_SOCIAIS',
       'LAIS_IMPACTOS_SOCIAIS','LAIS_REVISOES')),
   NOW(),NOW(),
   JSON_OBJECT('pai','MODULO_LEGAL',
               'comparacao_legado','green_seguranca.menus',
               'submenus',JSON_ARRAY('LAIS','LAIS_ATIVIDADES',
                                     'LAIS_PARTES_INTERESSADAS',
                                     'LAIS_ASPECTOS_SOCIAIS',
                                     'LAIS_IMPACTOS_SOCIAIS','LAIS_REVISOES'),
               'itens_reposicionados_no_modulo_legal',
               JSON_ARRAY('LAIS_REQUISITOS_LEGAIS',
                          'LAIS_CONTROLES_DOCUMENTOS',
                          'LAIS_OBJETIVOS_METAS')))
ON DUPLICATE KEY UPDATE
  status=VALUES(status), quantidade_registros=VALUES(quantidade_registros),
  concluida_em=VALUES(concluida_em), detalhes=VALUES(detalhes);
