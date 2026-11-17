-- Green Portal - Fase 3 do modulo Gestao de Nao Conformidades.
-- Indices, foreign keys comprovadamente validas e descarte da copia redundante
-- nc_tipo_de_reclamacao no destino. A origem green_modelo permanece intacta.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
DELIMITER $$

DROP PROCEDURE IF EXISTS `__green_nc_add_index`$$
CREATE PROCEDURE `__green_nc_add_index`(IN p_table VARCHAR(128), IN p_index VARCHAR(128), IN p_columns VARCHAR(500))
MODIFIES SQL DATA
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = 'gmodelo_dbp' AND TABLE_NAME = p_table AND INDEX_NAME = p_index
    ) THEN
        SET @green_nc_index_sql = CONCAT('ALTER TABLE `gmodelo_dbp`.`', p_table, '` ADD INDEX `', p_index, '` (', p_columns, ')');
        PREPARE green_nc_index_statement FROM @green_nc_index_sql;
        EXECUTE green_nc_index_statement;
        DEALLOCATE PREPARE green_nc_index_statement;
    END IF;
END$$

CALL `__green_nc_add_index`('nc_listadistribuicaoxcategoria','ix_nc_lxc_lista','`CODLISTA`')$$
CALL `__green_nc_add_index`('nc_listadistribuicaoxcategoria','ix_nc_lxc_categoria','`CODCATEGORIA`')$$
CALL `__green_nc_add_index`('nc_listadistribuicaoxusuarios','ix_nc_lxu_lista','`CODLISTA`')$$
CALL `__green_nc_add_index`('nc_listadistribuicaoxusuarios','ix_nc_lxu_usuario','`CODUSUARIO`')$$
CALL `__green_nc_add_index`('nc_planodeacao_historico','ix_nc_historico_plano','`CODPLANOACAO`')$$
CALL `__green_nc_add_index`('nc_tipo_de_naoconformidadexcampos','ix_nc_tipo_campos_tipo','`CODTIPONAOCONFORMIDADE`')$$
CALL `__green_nc_add_index`('nc_indicacao_ocorrencia','ix_nc_ocorrencia_tipo_nao','`CODTIPONAOCONFORMIDADE`')$$

DROP PROCEDURE IF EXISTS `__green_nc_add_fk`$$
CREATE PROCEDURE `__green_nc_add_fk`(
    IN p_table VARCHAR(128), IN p_constraint VARCHAR(128), IN p_column VARCHAR(128),
    IN p_ref_table VARCHAR(128), IN p_ref_column VARCHAR(128)
)
MODIFIES SQL DATA
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = 'gmodelo_dbp' AND TABLE_NAME = p_table
           AND CONSTRAINT_NAME = p_constraint AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ) THEN
        SET @green_nc_fk_sql = CONCAT(
            'ALTER TABLE `gmodelo_dbp`.`', p_table, '` ADD CONSTRAINT `', p_constraint,
            '` FOREIGN KEY (`', p_column, '`) REFERENCES `gmodelo_dbp`.`', p_ref_table,
            '` (`', p_ref_column, '`) ON UPDATE RESTRICT ON DELETE RESTRICT'
        );
        PREPARE green_nc_fk_statement FROM @green_nc_fk_sql;
        EXECUTE green_nc_fk_statement;
        DEALLOCATE PREPARE green_nc_fk_statement;
    END IF;
END$$

-- Relacoes sem registros orfos no destino.
CALL `__green_nc_add_fk`('nc_causas','fk_nc_causas_ocorrencia','CODOCORRENCIA','nc_indicacao_ocorrencia','CODOCORRENCIA')$$
CALL `__green_nc_add_fk`('nc_indicacao_ocorrencia','fk_nc_ocorrencia_tipo_nao','CODTIPONAOCONFORMIDADE','nc_tipo_de_naoconformidade','CODTIPONAOCONFORMIDADE')$$
CALL `__green_nc_add_fk`('nc_tipo_de_naoconformidadexcampos','fk_nc_tipo_campos_tipo','CODTIPONAOCONFORMIDADE','nc_tipo_de_naoconformidade','CODTIPONAOCONFORMIDADE')$$
CALL `__green_nc_add_fk`('nc_listadistribuicaoxcategoria','fk_nc_lxc_lista','CODLISTA','nc_listadistribuicao','CODLISTA')$$
CALL `__green_nc_add_fk`('nc_listadistribuicaoxcategoria','fk_nc_lxc_categoria','CODCATEGORIA','nc_categorias','CODCATEGORIA')$$
CALL `__green_nc_add_fk`('nc_listadistribuicaoxusuarios','fk_nc_lxu_lista','CODLISTA','nc_listadistribuicao','CODLISTA')$$
CALL `__green_nc_add_fk`('nc_planodeacao_historico','fk_nc_historico_plano','CODPLANOACAO','nc_planodeacao','CODPLANOACAO')$$

DROP PROCEDURE IF EXISTS `__green_nc_replace_reclamacao_view`$$
CREATE PROCEDURE `__green_nc_replace_reclamacao_view`()
MODIFIES SQL DATA
BEGIN
    DECLARE view_sql LONGTEXT;
    SELECT VIEW_DEFINITION INTO view_sql
      FROM information_schema.VIEWS
     WHERE TABLE_SCHEMA = 'gmodelo_dbp'
       AND TABLE_NAME = 'view_nc_indicacao_ocorrencia_clientes';
    IF view_sql IS NOT NULL AND view_sql LIKE '%nc_tipo_de_reclamacao%' THEN
        SET view_sql = REPLACE(view_sql, '`gmodelo_dbp`.`nc_tipo_de_reclamacao`.`codtipodereclamacao`', '`gmodelo_dbp`.`nc_tiporeclamacao`.`codtiporeclamacao`');
        SET view_sql = REPLACE(view_sql, '`gmodelo_dbp`.`nc_tipo_de_reclamacao`.`dsctipodereclamacao`', '`gmodelo_dbp`.`nc_tiporeclamacao`.`dsctiporeclamacao`');
        SET view_sql = REPLACE(view_sql, '`gmodelo_dbp`.`nc_tipo_de_reclamacao`', '`gmodelo_dbp`.`nc_tiporeclamacao`');
        SET @green_nc_view_sql = CONCAT('CREATE OR REPLACE VIEW `gmodelo_dbp`.`view_nc_indicacao_ocorrencia_clientes` AS ', view_sql);
        PREPARE green_nc_view_statement FROM @green_nc_view_sql;
        EXECUTE green_nc_view_statement;
        DEALLOCATE PREPARE green_nc_view_statement;
    END IF;
END$$

CALL `__green_nc_replace_reclamacao_view`()$$
DROP PROCEDURE IF EXISTS `__green_nc_replace_reclamacao_view`$$

DROP TABLE IF EXISTS `gmodelo_dbp`.`nc_tipo_de_reclamacao`$$
DROP PROCEDURE IF EXISTS `__green_nc_add_index`$$
DROP PROCEDURE IF EXISTS `__green_nc_add_fk`$$

DELIMITER ;

INSERT INTO `gmodelo_dbp`.`registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`,
     `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODULO_NC_107',
     'gmodelo_dbp.nc_*',
     'gmodelo_dbp.nc_* com integridade parcial',
     'ESTRUTURA', '1.0.0', 'CONCLUIDA', 0,
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
     JSON_OBJECT('indices_adicionados', 7, 'foreign_keys_criadas', 7,
                 'foreign_keys_pendentes_orfaos', TRUE,
                 'tabela_redundante_descartada', 'nc_tipo_de_reclamacao',
                 'origem_preservada', 'green_modelo.nc_tipo_de_reclamacao'))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`),
    `concluida_em` = VALUES(`concluida_em`),
    `detalhes` = VALUES(`detalhes`);
