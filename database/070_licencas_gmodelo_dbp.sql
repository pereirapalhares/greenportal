-- Migração da origem da transação Manutenção Legal/Licenças para gmodelo_dbp.
-- A tabela li_licencas já existe em gmodelo_dbp; esta view reproduz a
-- projeção utilizada pelo CRUD, sem depender de gmodelo_db.
USE `gmodelo_dbp`;

CREATE OR REPLACE VIEW `vw_licencas_grid` AS
SELECT
    l.`CODLICENCA` AS `CODLICENCA`,
    l.`codunidade` AS `CODUNIDADE`,
    u.`DSCUNIDADE` AS `DSCUNIDADE`,
    l.`DSCLICENCA` AS `DSCLICENCA`,
    DATE_FORMAT(l.`DATA_VALIDADE`, '%d/%m/%Y') AS `DATA_VALIDADE`,
    DATE_FORMAT(l.`DATA_REACAO`, '%d/%m/%Y') AS `DATA_REACAO`,
    DATE_FORMAT(l.`data_protocolo`, '%d/%m/%Y') AS `DATA_PROTOCOLO`,
    l.`codusuario` AS `CODUSUARIO`,
    COALESCE(us.`nome`, '') AS `RESPONSAVEL`,
    CONCAT_WS(';', 'licencas', l.`CODLICENCA`) AS `ARQUIVOS`,
    l.`codlista` AS `CODLISTA`,
    l.`emprotocolo` AS `EMPROTOCOLO`,
    DATE_FORMAT(l.`protocolado_em`, '%d/%m/%Y') AS `PROTOCOLADO_EM`,
    IF((YEAR(l.`protocolado_em`) + YEAR(l.`data_protocolo`)) = 0, l.`protocolado_no_prazo`, IF(l.`protocolado_em` <= l.`data_protocolo`, 'S', 'N')) AS `PROTOCOLADO_NO_PRAZO`,
    (TO_DAYS(NOW()) - TO_DAYS(l.`protocolado_em`)) AS `QTD_DIAS_EM_PROTOCOLO`,
    l.`CODSTATUS` AS `CODSTATUS`,
    l.`observacao` AS `OBSERVACAO`,
    l.`CODCLASSIFICACAO` AS `CODCLASSIFICACAO`,
    l.`CODFREQUENCIA` AS `CODFREQUENCIA`,
    (TO_DAYS(l.`DATA_VALIDADE`) - TO_DAYS(NOW())) AS `QTDDIAS`,
    (TO_DAYS(l.`DATA_REACAO`) - TO_DAYS(NOW())) AS `QTDDIAS2`
FROM `li_licencas` l
LEFT JOIN `unidades` u ON u.`CODUNIDADE` = l.`codunidade`
LEFT JOIN `usuarios` us ON us.`codusuario` = l.`codusuario`;
