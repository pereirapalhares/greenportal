-- Posiciona Medição e Monitoramento abaixo de Módulo Legal.

USE gmodelo_dbp;

UPDATE menus
   SET codmenu_pai=(SELECT codmenu FROM (SELECT codmenu FROM menus WHERE codigo='MODULO_LEGAL') x),
       modulo='MODULO_LEGAL',
       nivel=2,
       ordem=42,
       ativo=1
 WHERE codigo='MEDICAO_MONITORAMENTO';

UPDATE menus
   SET codmenu_pai=(SELECT codmenu FROM (SELECT codmenu FROM menus WHERE codigo='MEDICAO_MONITORAMENTO') x),
       modulo='MODULO_LEGAL',
       nivel=3,
       ativo=1
 WHERE codigo IN ('MM_EQUIPAMENTOS','MM_MEDICOES','MM_CONTROLE_CALIBRACAO',
                  'MM_PLANO_MEDICAO_MONITORAMENTO');

INSERT INTO registros_migracao
  (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,
   iniciada_em,concluida_em,detalhes)
VALUES
  ('HIERARQUIA_MM_MODULO_LEGAL_163',
   'gmodelo_dbp.menus',
   'gmodelo_dbp.menus',
   'ESTRUTURA','1.0.0','CONCLUIDA',
   (SELECT COUNT(*) FROM menus WHERE codigo IN
      ('MEDICAO_MONITORAMENTO','MM_EQUIPAMENTOS','MM_MEDICOES',
       'MM_CONTROLE_CALIBRACAO','MM_PLANO_MEDICAO_MONITORAMENTO')),
   NOW(),NOW(),
   JSON_OBJECT('pai','MODULO_LEGAL',
               'filhos',JSON_ARRAY('MM_EQUIPAMENTOS','MM_MEDICOES',
                                   'MM_CONTROLE_CALIBRACAO',
                                   'MM_PLANO_MEDICAO_MONITORAMENTO')))
ON DUPLICATE KEY UPDATE
  status=VALUES(status), quantidade_registros=VALUES(quantidade_registros),
  concluida_em=VALUES(concluida_em), detalhes=VALUES(detalhes);
