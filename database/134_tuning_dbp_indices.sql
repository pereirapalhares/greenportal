-- Tuning de baixo risco para os bancos *_dbp.
-- Compatível com MySQL 8.4; executar uma vez por ambiente.

USE gmodelo_dbp;

-- A view de condicionantes e o popup de plano de ação filtram por estes campos.
ALTER TABLE li_condicionantes
    ADD INDEX ix_li_condicionantes_licenca (CODLICENCA);

ALTER TABLE li_planodeacao
    ADD INDEX ix_li_planodeacao_condicionante (CODCONDICIONANTE);

-- A consulta de ocorrências correlaciona os dois campos no EXISTS.
ALTER TABLE nc_planodeacao
    ADD INDEX ix_nc_planodeacao_ocorrencia_usuario (CODOCORRENCIA, CODUSUARIO);
