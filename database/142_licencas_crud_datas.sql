-- Retorna datas em formato ISO para edição pelo calendário do CRUD.
USE gmodelo_dbp;

CREATE OR REPLACE VIEW vw_licencas_grid AS
SELECT l.CODLICENCA, l.codunidade AS CODUNIDADE, u.DSCUNIDADE, l.DSCLICENCA,
       NULLIF(l.DATA_VALIDADE, '0000-00-00') AS DATA_VALIDADE,
       NULLIF(l.DATA_REACAO, '0000-00-00') AS DATA_REACAO,
       NULLIF(l.data_protocolo, '0000-00-00 00:00:00') AS DATA_PROTOCOLO,
       l.codusuario AS CODUSUARIO, COALESCE(us.nome, '') AS RESPONSAVEL,
       CONCAT_WS(';', 'licencas', l.CODLICENCA) AS ARQUIVOS,
       l.codlista AS CODLISTA, l.emprotocolo AS EMPROTOCOLO,
       NULLIF(l.protocolado_em, '0000-00-00') AS PROTOCOLADO_EM,
       l.protocolado_no_prazo AS PROTOCOLADO_NO_PRAZO,
       TO_DAYS(NOW()) - TO_DAYS(l.protocolado_em) AS QTD_DIAS_EM_PROTOCOLO,
       l.CODSTATUS, l.observacao AS OBSERVACAO, l.CODCLASSIFICACAO,
       l.CODFREQUENCIA, TO_DAYS(l.DATA_VALIDADE) - TO_DAYS(NOW()) AS QTDDIAS,
       TO_DAYS(l.DATA_REACAO) - TO_DAYS(NOW()) AS QTDDIAS2
  FROM li_licencas l
  LEFT JOIN unidades u ON u.CODUNIDADE = l.codunidade
  LEFT JOIN usuarios us ON us.codusuario = l.codusuario;
