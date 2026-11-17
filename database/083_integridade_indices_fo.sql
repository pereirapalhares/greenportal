-- Green Portal
-- Integridade e índices do módulo Homologação de Fornecedores.
-- Origem lógica: green_modelo.fo_*
-- Destino: gmodelo_dbp.fo_*
--
-- Esta migração preserva os dados e as duplicidades existentes.
-- As tabelas de relacionamento recebem identificador técnico quando não
-- existe uma combinação de negócio comprovadamente única.
--
-- Executar após backup e auditoria de duplicidades.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- Tabelas sem chave primária e sem índice.
ALTER TABLE `fo_equipamentosxpalavraschave`
    ADD COLUMN `codequipamentoxpalavrachave` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codequipamentoxpalavrachave`),
    ADD INDEX `ix_fo_equipamentosxpalavraschave_equipamento` (`codequipamento`),
    ADD INDEX `ix_fo_equipamentosxpalavraschave_palavra` (`codpalavrachave`);

ALTER TABLE `fo_fornecedorxtipoxcriterio`
    ADD COLUMN `codfornecedorxtipoxcriterio` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codfornecedorxtipoxcriterio`),
    ADD INDEX `ix_fo_fornecedorxtipoxcriterio_fornecedor` (`codfornecedor`),
    ADD INDEX `ix_fo_fornecedorxtipoxcriterio_tipo` (`DSCTIPO`),
    ADD INDEX `ix_fo_fornecedorxtipoxcriterio_criterio` (`DSCRITERIO`);

ALTER TABLE `fo_fornecedorxusuario_aux`
    ADD COLUMN `codfornecedorxusuarioaux` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codfornecedorxusuarioaux`),
    ADD INDEX `ix_fo_fornecedorxusuario_aux_fornecedor_usuario` (`CODFORNECEDOR`, `CODUSUARIO`);

ALTER TABLE `fo_funcionariosxfornecedores`
    ADD COLUMN `codfuncionarioxfornecedor` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codfuncionarioxfornecedor`),
    ADD INDEX `ix_fo_funcionariosxfornecedores_funcionario_fornecedor` (`codfuncionario`, `codfornecedor`),
    ADD INDEX `ix_fo_funcionariosxfornecedores_fornecedor` (`codfornecedor`);

ALTER TABLE `fo_funcionariosxtipofuncionarios`
    ADD COLUMN `codfuncionarioxtipofuncionario` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codfuncionarioxtipofuncionario`),
    ADD INDEX `ix_fo_funcionariosxtipofuncionarios_funcionario_tipo` (`codfuncionario`, `codtipofuncionario`),
    ADD INDEX `ix_fo_funcionariosxtipofuncionarios_tipo` (`codtipofuncionario`);

ALTER TABLE `fo_produtoquimicoxpalavraschave`
    ADD COLUMN `codprodutoquimicoxpalavrachave` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codprodutoquimicoxpalavrachave`),
    ADD INDEX `ix_fo_produtoquimicoxpalavraschave_produto` (`codproduto`),
    ADD INDEX `ix_fo_produtoquimicoxpalavraschave_palavra` (`codpalavrachave`);

ALTER TABLE `fo_tipofornecedoresxpalavraschave`
    ADD COLUMN `codtipofornecedorxpalavrachave` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codtipofornecedorxpalavrachave`),
    ADD INDEX `ix_fo_tipofornecedoresxpalavraschave_tipo` (`codtipofornecedor`),
    ADD INDEX `ix_fo_tipofornecedoresxpalavraschave_palavra` (`codpalavrachave`);

ALTER TABLE `fo_tipofuncionariosxpalavraschave`
    ADD COLUMN `codtipofuncionarioxpalavrachave` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codtipofuncionarioxpalavrachave`),
    ADD INDEX `ix_fo_tipofuncionariosxpalavraschave_tipo` (`codtipofuncionario`),
    ADD INDEX `ix_fo_tipofuncionariosxpalavraschave_palavra` (`codpalavrachave`);

ALTER TABLE `fo_tipofuncionariosxtipotreinamentos`
    ADD COLUMN `codtipofuncionarioxtipotreinamento` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codtipofuncionarioxtipotreinamento`),
    ADD INDEX `ix_fo_tipofuncionariosxtipotreinamentos_tipo_treinamento` (`codtipofuncionario`, `codtipotreinamento`),
    ADD INDEX `ix_fo_tipofuncionariosxtipotreinamentos_treinamento` (`codtipotreinamento`);

-- A tabela já possui codchave auto_increment e índice UNIQUE.
-- A chave primária torna a identidade do registro explícita sem remover dados.
ALTER TABLE `fo_tipofornecedoresxcriteriofornecedores`
    ADD PRIMARY KEY (`codchave`);

-- A combinação de negócio possui duplicidades. Mantém-se o índice existente
-- e adiciona-se uma chave técnica para preservar todas as ocorrências.
ALTER TABLE `fo_tipofuncionariosxcriteriofuncionarios`
    ADD COLUMN `codtipofuncionarioxcriteriofuncionario` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codtipofuncionarioxcriteriofuncionario`);

