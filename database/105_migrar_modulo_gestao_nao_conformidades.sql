-- Green Portal - Fase 1 do modulo Gestao de Nao Conformidades.
-- Migra as 25 tabelas nc_ de green_modelo para gmodelo_dbp.
-- Nesta fase nao sao criadas foreign keys e nao sao removidos dados orfaos.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_categorias` LIKE `green_modelo`.`nc_categorias`;
ALTER TABLE `gmodelo_dbp`.`nc_categorias` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_categorias` SELECT * FROM `green_modelo`.`nc_categorias`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_causas` LIKE `green_modelo`.`nc_causas`;
ALTER TABLE `gmodelo_dbp`.`nc_causas` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_causas` SELECT * FROM `green_modelo`.`nc_causas`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_classificacao_fornecedor` LIKE `green_modelo`.`nc_classificacao_fornecedor`;
ALTER TABLE `gmodelo_dbp`.`nc_classificacao_fornecedor` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_classificacao_fornecedor` SELECT * FROM `green_modelo`.`nc_classificacao_fornecedor`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_clientes` LIKE `green_modelo`.`nc_clientes`;
ALTER TABLE `gmodelo_dbp`.`nc_clientes` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_clientes` SELECT * FROM `green_modelo`.`nc_clientes`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_indicacao_ocorrencia` LIKE `green_modelo`.`nc_indicacao_ocorrencia`;
ALTER TABLE `gmodelo_dbp`.`nc_indicacao_ocorrencia`
  MODIFY `DESCRICAO` TEXT NULL,
  MODIFY `PROVIDENCIA` TEXT NULL,
  MODIFY `ANALISE_DA_PROVIDENCIA` TEXT NULL,
  MODIFY `ANALISE_DA_CAUSA` TEXT NULL,
  MODIFY `VERIFICACAO_DA_EFICACIA` TEXT NULL,
  MODIFY `ANALISE_ABRANGENCIA` TEXT NULL;
ALTER TABLE `gmodelo_dbp`.`nc_indicacao_ocorrencia` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_indicacao_ocorrencia` SELECT * FROM `green_modelo`.`nc_indicacao_ocorrencia`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_listadistribuicao` LIKE `green_modelo`.`nc_listadistribuicao`;
ALTER TABLE `gmodelo_dbp`.`nc_listadistribuicao` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_listadistribuicao` SELECT * FROM `green_modelo`.`nc_listadistribuicao`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_listadistribuicaoxcategoria` LIKE `green_modelo`.`nc_listadistribuicaoxcategoria`;
ALTER TABLE `gmodelo_dbp`.`nc_listadistribuicaoxcategoria` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_listadistribuicaoxcategoria` SELECT * FROM `green_modelo`.`nc_listadistribuicaoxcategoria`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_listadistribuicaoxusuarios` LIKE `green_modelo`.`nc_listadistribuicaoxusuarios`;
ALTER TABLE `gmodelo_dbp`.`nc_listadistribuicaoxusuarios` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_listadistribuicaoxusuarios` SELECT * FROM `green_modelo`.`nc_listadistribuicaoxusuarios`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_origemacao` LIKE `green_modelo`.`nc_origemacao`;
ALTER TABLE `gmodelo_dbp`.`nc_origemacao` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_origemacao` SELECT * FROM `green_modelo`.`nc_origemacao`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_planodeacao` LIKE `green_modelo`.`nc_planodeacao`;
ALTER TABLE `gmodelo_dbp`.`nc_planodeacao`
  MODIFY `ACAO` TEXT NULL,
  MODIFY `RECURSOS` TEXT NULL,
  MODIFY `obs` TEXT NULL,
  MODIFY `MOTIVO_REPROGRAMACAO` TEXT NULL;
ALTER TABLE `gmodelo_dbp`.`nc_planodeacao` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_planodeacao` SELECT * FROM `green_modelo`.`nc_planodeacao`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_planodeacao_historico` LIKE `green_modelo`.`nc_planodeacao_historico`;
ALTER TABLE `gmodelo_dbp`.`nc_planodeacao_historico`
  MODIFY `ACAO` TEXT NULL,
  MODIFY `RECURSOS` TEXT NULL,
  MODIFY `obs` TEXT NULL;
ALTER TABLE `gmodelo_dbp`.`nc_planodeacao_historico` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_planodeacao_historico` SELECT * FROM `green_modelo`.`nc_planodeacao_historico`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_produtos` LIKE `green_modelo`.`nc_produtos`;
ALTER TABLE `gmodelo_dbp`.`nc_produtos` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_produtos` SELECT * FROM `green_modelo`.`nc_produtos`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_setores` LIKE `green_modelo`.`nc_setores`;
ALTER TABLE `gmodelo_dbp`.`nc_setores` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_setores` SELECT * FROM `green_modelo`.`nc_setores`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_siglagestao` LIKE `green_modelo`.`nc_siglagestao`;
ALTER TABLE `gmodelo_dbp`.`nc_siglagestao` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_siglagestao` SELECT * FROM `green_modelo`.`nc_siglagestao`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_tamanho_da_lata` LIKE `green_modelo`.`nc_tamanho_da_lata`;
ALTER TABLE `gmodelo_dbp`.`nc_tamanho_da_lata` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_tamanho_da_lata` SELECT * FROM `green_modelo`.`nc_tamanho_da_lata`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_tipo_de_naoconformidade` LIKE `green_modelo`.`nc_tipo_de_naoconformidade`;
ALTER TABLE `gmodelo_dbp`.`nc_tipo_de_naoconformidade` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_tipo_de_naoconformidade` SELECT * FROM `green_modelo`.`nc_tipo_de_naoconformidade`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_tipo_de_naoconformidadexcampos` LIKE `green_modelo`.`nc_tipo_de_naoconformidadexcampos`;
ALTER TABLE `gmodelo_dbp`.`nc_tipo_de_naoconformidadexcampos` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_tipo_de_naoconformidadexcampos` SELECT * FROM `green_modelo`.`nc_tipo_de_naoconformidadexcampos`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_tipo_de_reclamacao` LIKE `green_modelo`.`nc_tipo_de_reclamacao`;
ALTER TABLE `gmodelo_dbp`.`nc_tipo_de_reclamacao` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_tipo_de_reclamacao` SELECT * FROM `green_modelo`.`nc_tipo_de_reclamacao`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_tipo_de_tampa` LIKE `green_modelo`.`nc_tipo_de_tampa`;
ALTER TABLE `gmodelo_dbp`.`nc_tipo_de_tampa` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_tipo_de_tampa` SELECT * FROM `green_modelo`.`nc_tipo_de_tampa`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_tipoacao` LIKE `green_modelo`.`nc_tipoacao`;
ALTER TABLE `gmodelo_dbp`.`nc_tipoacao` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_tipoacao` SELECT * FROM `green_modelo`.`nc_tipoacao`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_tipodedocumento` LIKE `green_modelo`.`nc_tipodedocumento`;
ALTER TABLE `gmodelo_dbp`.`nc_tipodedocumento` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_tipodedocumento` SELECT * FROM `green_modelo`.`nc_tipodedocumento`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_tipoocorrencia` LIKE `green_modelo`.`nc_tipoocorrencia`;
ALTER TABLE `gmodelo_dbp`.`nc_tipoocorrencia` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_tipoocorrencia` SELECT * FROM `green_modelo`.`nc_tipoocorrencia`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_tiporeclamacao` LIKE `green_modelo`.`nc_tiporeclamacao`;
ALTER TABLE `gmodelo_dbp`.`nc_tiporeclamacao` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_tiporeclamacao` SELECT * FROM `green_modelo`.`nc_tiporeclamacao`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_tipos_causas` LIKE `green_modelo`.`nc_tipos_causas`;
ALTER TABLE `gmodelo_dbp`.`nc_tipos_causas` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_tipos_causas` SELECT * FROM `green_modelo`.`nc_tipos_causas`;

CREATE TABLE IF NOT EXISTS `gmodelo_dbp`.`nc_unidadesdocliente` LIKE `green_modelo`.`nc_unidadesdocliente`;
ALTER TABLE `gmodelo_dbp`.`nc_unidadesdocliente` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE=InnoDB;
INSERT IGNORE INTO `gmodelo_dbp`.`nc_unidadesdocliente` SELECT * FROM `green_modelo`.`nc_unidadesdocliente`;

INSERT INTO `gmodelo_dbp`.`registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`,
     `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODULO_NC_105',
     'green_modelo.nc_*',
     'gmodelo_dbp.nc_*',
     'DADOS', '1.0.0', 'CONCLUIDA',
     (SELECT COUNT(*) FROM `gmodelo_dbp`.`nc_indicacao_ocorrencia`) +
     (SELECT COUNT(*) FROM `gmodelo_dbp`.`nc_causas`) +
     (SELECT COUNT(*) FROM `gmodelo_dbp`.`nc_planodeacao`),
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
     JSON_OBJECT('tabelas', 25, 'engine_destino', 'InnoDB',
                 'collation_destino', 'utf8mb4_0900_ai_ci',
                 'foreign_keys', FALSE, 'orfaos_preservados', TRUE,
                 'views_rotinas', 'pendentes'))
ON DUPLICATE KEY UPDATE
    `status` = VALUES(`status`),
    `quantidade_registros` = VALUES(`quantidade_registros`),
    `concluida_em` = VALUES(`concluida_em`),
    `detalhes` = VALUES(`detalhes`);

SET FOREIGN_KEY_CHECKS = 1;
