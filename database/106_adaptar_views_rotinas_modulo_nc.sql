-- Green Portal - Fase 2 do modulo Gestao de Nao Conformidades.
-- Adapta as rotinas e views legadas para gmodelo_dbp.
-- Foreign keys e telas permanecem pendentes.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
DELIMITER $$

DROP FUNCTION IF EXISTS `getQtdDias_nc_planodeacao`$$
CREATE FUNCTION `getQtdDias_nc_planodeacao`(in_codocorrencia INT, in_codusuario INT)
RETURNS INT
READS SQL DATA
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE pqtddias INT DEFAULT 0;
    DECLARE pstatus INT DEFAULT 0;
    DECLARE cur_qtddias CURSOR FOR
        SELECT DISTINCT IF(YEAR(datafinal) = 0, -100, DATEDIFF(datafinal, NOW())) AS qtddias, status
          FROM nc_planodeacao
         WHERE codcausa IN (SELECT codcausa FROM nc_causas WHERE codocorrencia = in_codocorrencia)
           AND status < 100 AND codusuario = in_codusuario
        UNION ALL
        SELECT DISTINCT IF(YEAR(datafinal) = 0, -100, DATEDIFF(datafinal, NOW())) AS qtddias, status
          FROM nc_planodeacao
         WHERE codocorrencia = in_codocorrencia AND status < 100 AND codusuario = in_codusuario
         ORDER BY 1 ASC;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    OPEN cur_qtddias;
    read_loop: LOOP
        FETCH cur_qtddias INTO pqtddias, pstatus;
        IF done THEN LEAVE read_loop; END IF;
    END LOOP;
    CLOSE cur_qtddias;
    RETURN pqtddias;
END$$

DROP FUNCTION IF EXISTS `getStatusPlanoAcaoNC`$$
CREATE FUNCTION `getStatusPlanoAcaoNC`(in_codocorrencia INT)
RETURNS VARCHAR(3)
READS SQL DATA
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE ret_status VARCHAR(3) DEFAULT 'Sim';
    DECLARE cur_status INT;
    DECLARE total INT;
    DECLARE plano_count INT;
    DECLARE ocorrencias CURSOR FOR
        SELECT status FROM nc_planodeacao
         WHERE codcausa IN (SELECT codcausa FROM nc_causas WHERE codocorrencia = in_codocorrencia)
         ORDER BY status DESC;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    SELECT COUNT(status) INTO plano_count
      FROM nc_planodeacao
     WHERE codcausa IN (SELECT codcausa FROM nc_causas WHERE codocorrencia = in_codocorrencia);
    SELECT COUNT(codcausa) INTO total
      FROM nc_causas
     WHERE codocorrencia = in_codocorrencia
       AND codcausa NOT IN (SELECT codcausa FROM nc_planodeacao);
    IF plano_count = 0 THEN RETURN 'Não'; END IF;
    IF total = 0 THEN
        OPEN ocorrencias;
        read_loop: LOOP
            FETCH ocorrencias INTO cur_status;
            IF done THEN LEAVE read_loop; END IF;
            IF cur_status < 100 THEN SET ret_status = 'Não'; END IF;
        END LOOP;
        CLOSE ocorrencias;
        RETURN ret_status;
    END IF;
    RETURN 'Não';
END$$

DROP PROCEDURE IF EXISTS `getNCPendentes`$$
CREATE PROCEDURE `getNCPendentes`(IN in_codusurio INT)
READS SQL DATA
BEGIN
    SELECT DISTINCT codocorrencia, nrano, dscunidade, identificacao, etapa,
           DATE_FORMAT(data_de_conclusao_prevista, '%d/%m/%Y') AS data_de_conclusao_prevista,
           DATE_FORMAT(prazo_analize, '%d/%m/%Y') AS prazo_analize,
           dsctiponaoconformidade, codusuario_gestor, codusuario_prazo_analise,
           codusuario_responsavel, codusuario_emissor, recusa_do_gestor,
           DATE_FORMAT(data_de_envia_analise_eficacia, '%d/%m/%Y') AS data_de_envia_analise_eficacia,
           IF(getQtdDias_nc_planodeacao(codocorrencia, in_codusurio) = 0, 999,
              getQtdDias_nc_planodeacao(codocorrencia, in_codusurio)) AS qtdlinhas
      FROM nc_indicacao_ocorrencia, nc_tipo_de_naoconformidade, unidades
     WHERE ((CODUSUARIO_EMISSOR = in_codusurio AND etapa = 'Recusado pelo Gestor')
         OR (codusuario_responsavel = in_codusurio AND etapa IN ('Em Analise da Causa Raiz', 'Recusado pela Avaliacao da Eficacia'))
         OR (codusuario_responsavel = in_codusurio AND etapa = 'Plano de Ação'
             AND codocorrencia IN (SELECT codocorrencia FROM nc_causas
                                    WHERE codcausa NOT IN (SELECT codcausa FROM nc_planodeacao WHERE codcausa IS NOT NULL)))
         OR (codusuario_gestor = in_codusurio AND etapa IN ('Avaliação da Eficacia', 'Com o Gestor'))
         OR (etapa = 'Plano de Ação' AND codocorrencia IN
             (SELECT codocorrencia FROM nc_causas WHERE codcausa IN
             (SELECT codcausa FROM nc_planodeacao WHERE CODUSUARIO = in_codusurio AND status < 100)))
         OR (etapa = 'Plano de Ação' AND codocorrencia IN
             (SELECT codocorrencia FROM nc_planodeacao WHERE CODUSUARIO = in_codusurio AND status < 100))
         OR (etapa = 'Plano de Ação' AND codocorrencia IN
             (SELECT codocorrencia FROM nc_causas WHERE codcausa NOT IN
             (SELECT codcausa FROM nc_planodeacao WHERE codcausa IS NOT NULL))))
       AND etapa <> 'Finalizado'
       AND unidades.codunidade = nc_indicacao_ocorrencia.codunidade
       AND nc_tipo_de_naoconformidade.CODTIPONAOCONFORMIDADE = nc_indicacao_ocorrencia.CODTIPONAOCONFORMIDADE;
END$$

DROP PROCEDURE IF EXISTS `__green_migrate_nc_views`$$
CREATE PROCEDURE `__green_migrate_nc_views`()
MODIFIES SQL DATA
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE view_name VARCHAR(128);
    DECLARE view_sql LONGTEXT;
    DECLARE views_cursor CURSOR FOR
        SELECT TABLE_NAME, VIEW_DEFINITION
          FROM information_schema.VIEWS
         WHERE TABLE_SCHEMA = 'green_modelo'
           AND (TABLE_NAME LIKE 'view_nc_%' OR TABLE_NAME LIKE 'vw_nc_%' OR TABLE_NAME = 'vlistadeocorrenciasnc');
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    OPEN views_cursor;
    read_loop: LOOP
        FETCH views_cursor INTO view_name, view_sql;
        IF done THEN LEAVE read_loop; END IF;
        SET view_sql = REPLACE(view_sql, '`green_modelo`.', '`gmodelo_dbp`.');
        SET view_sql = REPLACE(view_sql, '`green_modelo_seguranca`.`usuarios_gestor`.', '`usuarios_gestor`.');
        SET view_sql = REPLACE(view_sql, '`green_modelo_seguranca`.`usuarios_responsavel`.', '`usuarios_responsavel`.');
        SET view_sql = REPLACE(view_sql, '`green_modelo_seguranca`.`usuarios_emissor`.', '`usuarios_emissor`.');
        SET view_sql = REPLACE(view_sql, '`green_modelo_seguranca`.`usuarios_gestor`', '`gmodelo_dbp`.`usuarios` AS `usuarios_gestor`');
        SET view_sql = REPLACE(view_sql, '`green_modelo_seguranca`.`usuarios_responsavel`', '`gmodelo_dbp`.`usuarios` AS `usuarios_responsavel`');
        SET view_sql = REPLACE(view_sql, '`green_modelo_seguranca`.`usuarios_emissor`', '`gmodelo_dbp`.`usuarios` AS `usuarios_emissor`');
        SET @green_nc_view_sql = CONCAT('CREATE OR REPLACE VIEW `gmodelo_dbp`.`', view_name, '` AS ', view_sql);
        PREPARE green_nc_view_statement FROM @green_nc_view_sql;
        EXECUTE green_nc_view_statement;
        DEALLOCATE PREPARE green_nc_view_statement;
    END LOOP;
    CLOSE views_cursor;
END$$

CALL `__green_migrate_nc_views`()$$
DROP PROCEDURE IF EXISTS `__green_migrate_nc_views`$$

DELIMITER ;

INSERT INTO `gmodelo_dbp`.`registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`,
     `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODULO_NC_106',
     'green_modelo.view_nc_*; green_modelo.vw_nc_*; green_modelo.vlistadeocorrenciasnc; rotinas NC',
     'gmodelo_dbp.view_nc_*; gmodelo_dbp.vw_nc_*; gmodelo_dbp.vlistadeocorrenciasnc; rotinas NC',
     'ESTRUTURA', '1.0.0', 'CONCLUIDA', 0,
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
     JSON_OBJECT('views', 11, 'funcoes', 2, 'procedures', 1,
                 'schema_origem_substituido', TRUE,
                 'usuarios_mapeados_para', 'gmodelo_dbp.usuarios',
                 'foreign_keys', FALSE))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`),
    `concluida_em` = VALUES(`concluida_em`),
    `detalhes` = VALUES(`detalhes`);
