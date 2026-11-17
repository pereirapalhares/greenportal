-- Substitui as colunas mensais por uma coluna virtual de acesso ao cronograma.

USE gmodelo_dbp;

CREATE OR REPLACE VIEW vw_medicao_monitoramento_monitoramento_grid AS
SELECT m.CODMONITORAMENTO, m.PARAMETROS, m.DATA_MEDICAO, m.DATA_REACAO,
       m.CODUSUARIO, m.CODEQUIPAMENTO,
       m.OBS, m.CODAREA, m.CODASPECTO, m.CODPERIGO,
       m.codunidade AS CODUNIDADE, m.codmedicao AS CODMEDICAO,
       '' AS cronograma
  FROM mm_monitoramento m;

INSERT INTO registros_migracao
  (codigo_migracao,origem,destino,tipo,versao,status,
   quantidade_registros,iniciada_em,concluida_em,detalhes)
VALUES
  ('CRONOGRAMA_GRID_MONITORAMENTO_162',
   'gmodelo_dbp.mm_monitoramento',
   'gmodelo_dbp.vw_medicao_monitoramento_monitoramento_grid e GreenPortal',
   'ESTRUTURA','1.0.0','CONCLUIDA',
   (SELECT COUNT(*) FROM mm_monitoramento),NOW(),NOW(),
   JSON_OBJECT('colunas_mensais_na_interface',FALSE,
               'coluna_virtual','cronograma',
               'pagina_reutilizavel','principal/eventos.php',
               'contexto_inicial','codmonitoramento'))
ON DUPLICATE KEY UPDATE
  status=VALUES(status), quantidade_registros=VALUES(quantidade_registros),
  concluida_em=VALUES(concluida_em), detalhes=VALUES(detalhes);
