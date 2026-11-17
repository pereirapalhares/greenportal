-- Green Portal - Índices e chaves técnicas dos relacionamentos legados.
-- Aplicar em homologação após backup dos bancos gmodelo_dbp e glegislacao_dbp.
--
-- Esta migração não remove duplicidades nem altera colunas existentes.
-- Algumas tabelas possuem dados duplicados ou colunas nulas; por isso recebem
-- uma chave técnica AUTO_INCREMENT e índices de relacionamento não únicos.
-- Foreign keys e índices UNIQUE serão tratados após auditoria de órfãos.

USE `glegislacao_dbp`;

ALTER TABLE `legislacaoxpalavraschave`
    ADD COLUMN `codlegislacaoxpalavrachave` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codlegislacaoxpalavrachave`),
    ADD INDEX `ix_lxpc_palavrachave` (`codpalavrachave`),
    ADD INDEX `ix_lxpc_legislacao` (`codlegislacao`);

ALTER TABLE `legislacaopaixlegislacaofilha`
    ADD COLUMN `codlegislacaopai_filha` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codlegislacaopai_filha`),
    ADD INDEX `ix_lplf_pai` (`CODLEGISLACAO_PAI`),
    ADD INDEX `ix_lplf_filha` (`CODLEGISLACAO_FILHA`);

ALTER TABLE `unidades`
    ADD COLUMN `codunidade_legislacao` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codunidade_legislacao`),
    ADD INDEX `ix_unidades_codigo` (`CODUNIDADE`);

USE `gmodelo_dbp`;

ALTER TABLE `documentosxaspectos`
    ADD COLUMN `coddocumentoxaspecto` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`coddocumentoxaspecto`),
    ADD INDEX `ix_dxa_documento` (`CODDOCUMENTO`),
    ADD INDEX `ix_dxa_aspecto` (`CODASPECTO`),
    ADD INDEX `ix_dxa_unidade` (`codunidade`);

ALTER TABLE `documentosximpactos`
    ADD COLUMN `coddocumentoximpacto` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`coddocumentoximpacto`),
    ADD INDEX `ix_dxi_documento` (`CODDOCUMENTO`),
    ADD INDEX `ix_dxi_impacto` (`CODIMPACTO`),
    ADD INDEX `ix_dxi_unidade` (`codunidade`);

ALTER TABLE `lais_documentosxaspectos`
    ADD COLUMN `codlaisdocumentoxaspecto` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codlaisdocumentoxaspecto`),
    ADD INDEX `ix_ldxa_documento` (`CODDOCUMENTO`),
    ADD INDEX `ix_ldxa_aspecto` (`CODASPECTOSOCIAL`),
    ADD INDEX `ix_ldxa_unidade` (`codunidade`);

ALTER TABLE `lais_documentosximpactos`
    ADD COLUMN `codlaisdocumentoximpacto` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codlaisdocumentoximpacto`),
    ADD INDEX `ix_ldxi_documento` (`CODDOCUMENTO`),
    ADD INDEX `ix_ldxi_impacto` (`CODIMPACTOSOCIAL`),
    ADD INDEX `ix_ldxi_unidade` (`codunidade`);

ALTER TABLE `li_licencasxpalavraschave`
    ADD COLUMN `codlicencaxpalavrachave` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codlicencaxpalavrachave`),
    ADD INDEX `ix_lipc_palavrachave` (`codpalavrachave`),
    ADD INDEX `ix_lipc_licenca` (`codlicenca`);

ALTER TABLE `lpro_abrangencia`
    ADD PRIMARY KEY (`ABRANGENCIA`),
    ADD INDEX `ix_lpro_abrangencia_descricao` (`DSCABRANGENCIA`);

ALTER TABLE `lpro_natureza_do_perigo`
    ADD PRIMARY KEY (`NATUREZA_DO_PERIGO`),
    ADD INDEX `ix_lpro_natureza_descricao` (`DSCNATUREZA_DO_PERIGO`);

ALTER TABLE `lpro_situacao`
    ADD PRIMARY KEY (`SITUACAO`),
    ADD INDEX `ix_lpro_situacao_descricao` (`DSCSITUACAO`);

ALTER TABLE `lpro_tipo_de_atividade`
    ADD PRIMARY KEY (`TIPO_DE_ATIVIDADE`),
    ADD INDEX `ix_lpro_atividade_descricao` (`DSCTIPO_DE_ATIVIDADE`);

ALTER TABLE `mm_medicaoxlaudo`
    ADD COLUMN `codmedicaoxlaudo` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codmedicaoxlaudo`),
    ADD INDEX `ix_mml_medicao` (`codmedicao`),
    ADD INDEX `ix_mml_laudo` (`codlaudo`);

ALTER TABLE `mm_medicaoxlegislacao`
    ADD COLUMN `codmedicaoxlegislacao` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codmedicaoxlegislacao`),
    ADD INDEX `ix_mmg_legislacao` (`codlegislacao`),
    ADD INDEX `ix_mmg_medicao` (`codmedicao`);

ALTER TABLE `mm_medicaoxpalavraschave`
    ADD COLUMN `codmedicaoxpalavrachave` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codmedicaoxpalavrachave`),
    ADD INDEX `ix_mmpp_palavrachave` (`codpalavrachave`),
    ADD INDEX `ix_mmpp_medicao` (`codmedicao`);

ALTER TABLE `mm_monitoramentoxlegislacao`
    ADD COLUMN `codmonitoramentoxlegislacao` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codmonitoramentoxlegislacao`),
    ADD INDEX `ix_mmlg_monitoramento` (`CODMONITORAMENTO`),
    ADD INDEX `ix_mmlg_legislacao` (`CODLEGISLACAO`);

ALTER TABLE `objetivosmetasxaspectos`
    ADD COLUMN `codobjetivoxaspecto` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codobjetivoxaspecto`),
    ADD INDEX `ix_oma_objetivo` (`CODOBJETIVO`),
    ADD INDEX `ix_oma_aspecto` (`CODASPECTO`);

ALTER TABLE `objetivosmetasxaspectossociais`
    ADD COLUMN `codobjetivoxaspectosocial` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codobjetivoxaspectosocial`),
    ADD INDEX `ix_omas_objetivo` (`CODOBJETIVO`),
    ADD INDEX `ix_omas_aspecto` (`CODASPECTOSOCIAL`);

ALTER TABLE `vlaiaxlegislacao`
    ADD COLUMN `codvlaiaxlegislacao` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (`codvlaiaxlegislacao`),
    ADD INDEX `ix_vlaia_legislacao` (`codlegislacao`),
    ADD INDEX `ix_vlaia_aspecto` (`CODASPECTO`),
    ADD INDEX `ix_vlaia_impacto` (`CODIMPACTO`),
    ADD INDEX `ix_vlaia_atividade` (`CODATIVIDADE`),
    ADD INDEX `ix_vlaia_unidade` (`CODUNIDADE`);

-- gmodelo_dbp.unidades já possui PRIMARY KEY (CODUNIDADE).
-- mm_medicaoxpalavrachave não existe no banco atual; não criar tabela sem
-- confirmar a necessidade funcional e a origem correta dos dados.
-- Nota: a observação acima foi superada; a tabela existente é
-- mm_medicaoxpalavraschave (plural) e recebeu a chave e os índices nesta
-- migração.
