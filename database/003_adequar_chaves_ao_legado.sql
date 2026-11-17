-- Green Portal - adequacao das chaves ao padrao legado.
-- Executar depois de 001_criar_empresa_modelo.sql e 002_padronizar_collation_modelo.sql.
-- Nao usar id como chave: os codigos COD<ENTIDADE> sao preservados.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- Remove dependencias antes da troca das chaves e dos nomes das colunas.
ALTER TABLE `logs_auditoria` DROP FOREIGN KEY `fk_logs_usuario`;
ALTER TABLE `perfil_menus` DROP FOREIGN KEY `fk_perfil_menus_perfil`;
ALTER TABLE `perfil_menus` DROP FOREIGN KEY `fk_perfil_menus_menu`;
ALTER TABLE `usuarios` DROP FOREIGN KEY `fk_usuarios_perfil`;
ALTER TABLE `usuarios` DROP FOREIGN KEY `fk_usuarios_grupo`;
ALTER TABLE `menus` DROP FOREIGN KEY `fk_menus_pai`;

-- Os codigos de origem foram preservados na carga inicial. Eles passam a ser
-- as chaves oficiais, sem depender do id artificial criado anteriormente.
ALTER TABLE `usuarios` ADD COLUMN `codusuario_novo` BIGINT UNSIGNED NULL;
UPDATE `usuarios` SET `codusuario_novo` = `codigo_origem`;
ALTER TABLE `usuarios` DROP PRIMARY KEY, DROP COLUMN `id`;
ALTER TABLE `usuarios`
    CHANGE COLUMN `codusuario_novo` `codusuario` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    DROP COLUMN `codigo_origem`,
    CHANGE COLUMN `perfil_id` `codperfil` BIGINT UNSIGNED NULL,
    CHANGE COLUMN `grupo_id` `codgrupo` BIGINT UNSIGNED NULL,
    ADD PRIMARY KEY (`codusuario`);

ALTER TABLE `grupos` ADD COLUMN `codgrupo_novo` BIGINT UNSIGNED NULL;
UPDATE `grupos` SET `codgrupo_novo` = `codigo_origem`;
ALTER TABLE `grupos` DROP PRIMARY KEY, DROP COLUMN `id`;
ALTER TABLE `grupos`
    CHANGE COLUMN `codgrupo_novo` `codgrupo` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    DROP COLUMN `codigo_origem`,
    ADD PRIMARY KEY (`codgrupo`);

-- As demais tabelas nativas tambem adotam chaves COD<ENTIDADE>.
ALTER TABLE `empresa` CHANGE COLUMN `id` `codempresa` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `perfis` CHANGE COLUMN `id` `codperfil` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `menus`
    CHANGE COLUMN `id` `codmenu` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    CHANGE COLUMN `menu_pai_id` `codmenu_pai` BIGINT UNSIGNED NULL;
ALTER TABLE `perfil_menus`
    CHANGE COLUMN `perfil_id` `codperfil` BIGINT UNSIGNED NOT NULL,
    CHANGE COLUMN `menu_id` `codmenu` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `setup_empresa` CHANGE COLUMN `id` `codsetupempresa` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `logs_auditoria`
    CHANGE COLUMN `id` `codlogauditoria` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    CHANGE COLUMN `usuario_id` `codusuario` BIGINT UNSIGNED NULL;
ALTER TABLE `registros_migracao` CHANGE COLUMN `id` `codregistromigracao` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `bugs_testes` CHANGE COLUMN `id` `codbugteste` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `artefatos_replicacao` CHANGE COLUMN `id` `codartefatoreplicacao` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;

-- Recria as relacoes usando os codigos oficiais.
ALTER TABLE `usuarios`
    ADD CONSTRAINT `fk_usuarios_perfil` FOREIGN KEY (`codperfil`) REFERENCES `perfis` (`codperfil`),
    ADD CONSTRAINT `fk_usuarios_grupo` FOREIGN KEY (`codgrupo`) REFERENCES `grupos` (`codgrupo`);
ALTER TABLE `menus`
    ADD CONSTRAINT `fk_menus_pai` FOREIGN KEY (`codmenu_pai`) REFERENCES `menus` (`codmenu`);
ALTER TABLE `perfil_menus`
    ADD CONSTRAINT `fk_perfil_menus_perfil` FOREIGN KEY (`codperfil`) REFERENCES `perfis` (`codperfil`),
    ADD CONSTRAINT `fk_perfil_menus_menu` FOREIGN KEY (`codmenu`) REFERENCES `menus` (`codmenu`);
ALTER TABLE `logs_auditoria`
    ADD CONSTRAINT `fk_logs_usuario` FOREIGN KEY (`codusuario`) REFERENCES `usuarios` (`codusuario`);

-- Remove colunas de origem que deixaram de ser necessarias e registra a
-- adequacao para auditoria e para futuros provisionamentos.
INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODELO_CHAVES_LEGADO', 'green_modelo_seguranca.COD<ENTIDADE>', 'gmodelo_dbp.COD<ENTIDADE>', 'ESTRUTURA', '1.0.0', 'CONCLUIDA',
     (SELECT COUNT(*) FROM `usuarios`) + (SELECT COUNT(*) FROM `grupos`), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
     JSON_OBJECT('usuarios', 'CODUSUARIO', 'grupos', 'CODGRUPO', 'id_removido', TRUE));
