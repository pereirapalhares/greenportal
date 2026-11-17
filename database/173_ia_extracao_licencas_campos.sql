-- Campos operacionais preenchidos pela extração assistida de documentos.
-- A confirmação do usuário continua obrigatória antes da gravação.
USE `gmodelo_dbp`;
SET NAMES utf8mb4;

ALTER TABLE `li_licencas`
  ADD COLUMN `ORGAO_EMISSOR` VARCHAR(255) NULL,
  ADD COLUMN `NUMERO_LICENCA` VARCHAR(120) NULL;

CREATE OR REPLACE VIEW `vw_licencas_grid` AS
SELECT l.CODLICENCA, l.codunidade AS CODUNIDADE, u.DSCUNIDADE, l.DSCLICENCA,
       NULLIF(l.DATA_VALIDADE,'0000-00-00') AS DATA_VALIDADE,
       NULLIF(l.DATA_REACAO,'0000-00-00') AS DATA_REACAO,
       NULLIF(l.data_protocolo,'0000-00-00 00:00:00') AS DATA_PROTOCOLO,
       l.codusuario AS CODUSUARIO, COALESCE(us.nome,'') AS RESPONSAVEL,
       CONCAT_WS(';','licencas',l.CODLICENCA) AS ARQUIVOS, l.codlista AS CODLISTA,
       l.emprotocolo AS EMPROTOCOLO, NULLIF(l.protocolado_em,'0000-00-00') AS PROTOCOLADO_EM,
       l.protocolado_no_prazo AS PROTOCOLADO_NO_PRAZO,
       (TO_DAYS(NOW()) - TO_DAYS(l.protocolado_em)) AS QTD_DIAS_EM_PROTOCOLO,
       l.CODSTATUS, l.observacao AS OBSERVACAO, l.CODCLASSIFICACAO, l.CODFREQUENCIA,
       (TO_DAYS(l.DATA_VALIDADE) - TO_DAYS(NOW())) AS QTDDIAS,
       (TO_DAYS(l.DATA_REACAO) - TO_DAYS(NOW())) AS QTDDIAS2,
       l.atividade AS ATIVIDADE, l.ORGAO_EMISSOR, l.NUMERO_LICENCA
FROM li_licencas l
LEFT JOIN unidades u ON u.CODUNIDADE = l.codunidade
LEFT JOIN usuarios us ON us.codusuario = l.codusuario;

INSERT INTO `registros_migracao`
 (`codigo_migracao`,`origem`,`destino`,`tipo`,`versao`,`status`,`quantidade_registros`,`iniciada_em`,`concluida_em`,`detalhes`)
VALUES ('IA_EXTRACAO_LICENCAS_CAMPOS_173','gmodelo_dbp.li_licencas','gmodelo_dbp.li_licencas.ORGAO_EMISSOR,NUMERO_LICENCA','ESTRUTURA','1.0.0','CONCLUIDA',0,NOW(),NOW(),JSON_OBJECT('confirmacao_usuario',true,'json_bruto','DADOS_DOCUMENTO_IA'))
ON DUPLICATE KEY UPDATE status=VALUES(status), concluida_em=VALUES(concluida_em), detalhes=VALUES(detalhes);
