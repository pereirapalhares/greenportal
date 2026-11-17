-- Organiza o Levantamento Social abaixo de Módulo Legal.
-- Reutiliza as transações sociais já existentes no GreenPortal.

USE gmodelo_dbp;

INSERT INTO menus
  (codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
SELECT p.codmenu,'MODULO_LEGAL','LEVANTAMENTO_SOCIAL',
       'Levantamento Social','Levantamento Social',
       'Social Survey','Levantamiento Social',
       NULL,2,22,1
  FROM menus p
 WHERE p.codigo='MODULO_LEGAL'
   AND NOT EXISTS
       (SELECT 1 FROM menus x WHERE x.codigo='LEVANTAMENTO_SOCIAL');

UPDATE menus
   SET codmenu_pai=(SELECT codmenu FROM
       (SELECT codmenu FROM menus WHERE codigo='MODULO_LEGAL') x),
       modulo='MODULO_LEGAL', nivel=2, ordem=22, ativo=1,
       label='Levantamento Social',
       label_pt='Levantamento Social',
       label_en='Social Survey',
       label_es='Levantamiento Social',
       url=NULL
 WHERE codigo='LEVANTAMENTO_SOCIAL';

UPDATE menus
   SET codmenu_pai=(SELECT codmenu FROM
       (SELECT codmenu FROM menus WHERE codigo='LEVANTAMENTO_SOCIAL') x),
       modulo='MODULO_LEGAL', nivel=3, ativo=1
 WHERE codigo IN ('LAIS','LAIS_REQUISITOS_LEGAIS',
                  'LAIS_CONTROLES_DOCUMENTOS','LAIS_OBJETIVOS_METAS');

UPDATE menus SET ordem=1 WHERE codigo='LAIS';
UPDATE menus SET ordem=2 WHERE codigo='LAIS_REQUISITOS_LEGAIS';
UPDATE menus SET ordem=3 WHERE codigo='LAIS_CONTROLES_DOCUMENTOS';
UPDATE menus SET ordem=4 WHERE codigo='LAIS_OBJETIVOS_METAS';

INSERT IGNORE INTO permissoes
  (codperfil,codmenu,pode_incluir,pode_visualizar,pode_visualizar_total,
   pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf)
SELECT p.codperfil,m.codmenu,p.pode_incluir,p.pode_visualizar,
       p.pode_visualizar_total,p.pode_editar,p.pode_excluir,
       p.pode_exportar_excel,p.pode_gerar_pdf
  FROM permissoes p
  JOIN menus origem ON origem.codmenu=p.codmenu
  JOIN menus m ON m.codigo='LEVANTAMENTO_SOCIAL'
 WHERE origem.codigo='MODULO_LEGAL'
   AND NOT EXISTS
       (SELECT 1 FROM permissoes x
         WHERE x.codperfil=p.codperfil AND x.codmenu=m.codmenu);

INSERT INTO registros_migracao
  (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,
   iniciada_em,concluida_em,detalhes)
VALUES
  ('MENU_LEVANTAMENTO_SOCIAL_166',
   'gmodelo_dbp.menus',
   'gmodelo_dbp.menus e gmodelo_dbp.permissoes',
   'ESTRUTURA','1.0.0','CONCLUIDA',
   (SELECT COUNT(*) FROM menus WHERE codigo IN
      ('LEVANTAMENTO_SOCIAL','LAIS','LAIS_REQUISITOS_LEGAIS',
       'LAIS_CONTROLES_DOCUMENTOS','LAIS_OBJETIVOS_METAS')),
   NOW(),NOW(),
   JSON_OBJECT('pai','MODULO_LEGAL',
               'submenus',JSON_ARRAY('LAIS','LAIS_REQUISITOS_LEGAIS',
                                     'LAIS_CONTROLES_DOCUMENTOS',
                                     'LAIS_OBJETIVOS_METAS'),
               'aspectos_sociais_e_impactos_sociais','tratados_na_transacao_LAIS',
               'transacoes_reutilizadas',TRUE))
ON DUPLICATE KEY UPDATE
  status=VALUES(status), quantidade_registros=VALUES(quantidade_registros),
  concluida_em=VALUES(concluida_em), detalhes=VALUES(detalhes);
