-- Ajustes de desempenho para relacionamentos consultados pelos grids.
-- Seguro para reexecução: cada índice é criado somente se ainda não existir.

USE gmodelo_dbp;

DELIMITER $$
DROP PROCEDURE IF EXISTS sp_migration_146_tuning_indices $$
CREATE PROCEDURE sp_migration_146_tuning_indices()
BEGIN
    DECLARE v_exists INT DEFAULT 0;

    SET @schema_name = 'gmodelo_dbp';

    SET v_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='documentosxpalavraschave' AND index_name='ix_dxp_documento_palavra');
    IF v_exists = 0 THEN ALTER TABLE gmodelo_dbp.documentosxpalavraschave ADD INDEX ix_dxp_documento_palavra (coddocumento, codpalavrachave); END IF;
    SET v_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='documentosxpalavraschave' AND index_name='ix_dxp_palavra_documento');
    IF v_exists = 0 THEN ALTER TABLE gmodelo_dbp.documentosxpalavraschave ADD INDEX ix_dxp_palavra_documento (codpalavrachave, coddocumento); END IF;
    SET v_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='documentosxunidades' AND index_name='ix_dxu_unidade_documento');
    IF v_exists = 0 THEN ALTER TABLE gmodelo_dbp.documentosxunidades ADD INDEX ix_dxu_unidade_documento (CODUNIDADE, CODDOCUMENTO); END IF;
    SET v_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='documentosxatividades' AND index_name='ix_dxa_atividade_documento');
    IF v_exists = 0 THEN ALTER TABLE gmodelo_dbp.documentosxatividades ADD INDEX ix_dxa_atividade_documento (CODATIVIDADE, CODDOCUMENTO, codunidade); END IF;
    SET v_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='documentosxperigos' AND index_name='ix_dxp_perigo_documento');
    IF v_exists = 0 THEN ALTER TABLE gmodelo_dbp.documentosxperigos ADD INDEX ix_dxp_perigo_documento (CODPERIGO, CODDOCUMENTO, codunidade); END IF;
    SET v_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='documentosxriscos' AND index_name='ix_dxr_risco_documento');
    IF v_exists = 0 THEN ALTER TABLE gmodelo_dbp.documentosxriscos ADD INDEX ix_dxr_risco_documento (CODRISCO, CODDOCUMENTO, codunidade); END IF;

    SET v_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='legislacaoxdocumentos' AND index_name='ix_lxd_legislacao_unidade');
    IF v_exists = 0 THEN ALTER TABLE gmodelo_dbp.legislacaoxdocumentos ADD INDEX ix_lxd_legislacao_unidade (codlegislacao, codunidade); END IF;
    SET v_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='legislacaoxaspecto' AND index_name='ix_lxa_legislacao_unidade');
    IF v_exists = 0 THEN ALTER TABLE gmodelo_dbp.legislacaoxaspecto ADD INDEX ix_lxa_legislacao_unidade (CODLEGISLACAO, codunidade); END IF;
    SET v_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='legislacaoxatividade' AND index_name='ix_lxat_legislacao_unidade');
    IF v_exists = 0 THEN ALTER TABLE gmodelo_dbp.legislacaoxatividade ADD INDEX ix_lxat_legislacao_unidade (CODLEGISLACAO, codunidade); END IF;
    SET v_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='legislacaoximpacto' AND index_name='ix_lxi_legislacao_unidade');
    IF v_exists = 0 THEN ALTER TABLE gmodelo_dbp.legislacaoximpacto ADD INDEX ix_lxi_legislacao_unidade (CODLEGISLACAO, codunidade); END IF;
    SET v_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='legislacaoxperigo' AND index_name='ix_lxp_legislacao_unidade');
    IF v_exists = 0 THEN ALTER TABLE gmodelo_dbp.legislacaoxperigo ADD INDEX ix_lxp_legislacao_unidade (CODLEGISLACAO, codunidade); END IF;
    SET v_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='legislacaoxrisco' AND index_name='ix_lxr_legislacao_unidade');
    IF v_exists = 0 THEN ALTER TABLE gmodelo_dbp.legislacaoxrisco ADD INDEX ix_lxr_legislacao_unidade (CODLEGISLACAO, codunidade); END IF;
    SET v_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='legislacaoxlicencas' AND index_name='ix_lxl_legislacao_unidade');
    IF v_exists = 0 THEN ALTER TABLE gmodelo_dbp.legislacaoxlicencas ADD INDEX ix_lxl_legislacao_unidade (CODLEGISLACAO, codunidade); END IF;

    INSERT INTO gmodelo_dbp.registros_migracao
        (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
    VALUES
        ('146_tuning_indices_dbp', 'greenportal', 'gmodelo_dbp/glegislacao_dbp', 'ESTRUTURA', '1', 'APLICADO', 0, NOW(), NOW(), JSON_OBJECT('descricao', 'Índices de desempenho para relacionamentos dos grids'));
END $$
DELIMITER ;

CALL sp_migration_146_tuning_indices();
DROP PROCEDURE IF EXISTS sp_migration_146_tuning_indices;

ANALYZE TABLE
    gmodelo_dbp.documentos,
    gmodelo_dbp.documentosxpalavraschave,
    gmodelo_dbp.documentosxunidades,
    gmodelo_dbp.documentosxatividades,
    gmodelo_dbp.documentosxperigos,
    gmodelo_dbp.documentosxriscos,
    gmodelo_dbp.legislacaoxempresa,
    gmodelo_dbp.legislacaoxpoder,
    gmodelo_dbp.legislacaoxsituacao,
    glegislacao_dbp.legislacao,
    glegislacao_dbp.legislacaoxassunto,
    glegislacao_dbp.legislacaoxpergunta;
