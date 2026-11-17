-- Migração 280 — procedures para as consultas que ainda eram feitas direto nas tabelas (banco de cada empresa).
-- Aplicada por scripts/aplicar-migracao-280-procedures-acesso-dados.php. Pode ser executada mais de uma vez.
-- As procedures são criadas mesmo que a tabela não exista no banco (ela é resolvida na execução: quem chama trata o erro).
-- Textos comparados com COLLATE explícito nos dois lados: as tabelas não têm a mesma collation em todos os bancos.
-- @@
DROP PROCEDURE IF EXISTS `sp_log_auditoria_registrar`
-- @@
-- (sp_log_auditoria_registrar foi substituída por sp_auditoria_registrar, migração 281; esta só remove a antiga)
-- @@
DROP PROCEDURE IF EXISTS `sp_ged_licenca_requisito_contar`
-- @@
CREATE PROCEDURE `sp_ged_licenca_requisito_contar`(IN p_codlegislacao BIGINT, IN p_codunidade BIGINT, IN p_codlicenca BIGINT, IN p_codlicenca_base BIGINT)
BEGIN
    -- Licença (ou a pasta dela) associada ao requisito legal na unidade
    SELECT COUNT(*) AS total
      FROM `legislacaoxlicencas`
     WHERE `CODLEGISLACAO` = p_codlegislacao AND `codunidade` = p_codunidade AND `CODLICENCA` IN (p_codlicenca, p_codlicenca_base);
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ged_licenca_versoes`
-- @@
CREATE PROCEDURE `sp_ged_licenca_versoes`(IN p_codlicenca_base BIGINT)
BEGIN
    -- Versões da licença (pasta = CODLICENCA_BASE), da mais antiga para a mais nova
    SELECT `CODLICENCA`, `CODLICENCA_BASE`, `CODLICENCA_ANTERIOR`, `VERSAO`, `STATUS_VERSAO`, `NOME_ARQUIVO`, `DATA_VALIDADE`,
           `STATUS_EXTRACAO`, `FONTE_EXTRACAO`, `CONFIANCA_EXTRACAO`, `DATA_UPLOAD`, `DATA_EXTRACAO`
      FROM `li_licencas`
     WHERE `CODLICENCA_BASE` = p_codlicenca_base
     ORDER BY CAST(SUBSTRING_INDEX(`VERSAO`, '.', 1) AS UNSIGNED), CAST(SUBSTRING_INDEX(`VERSAO`, '.', -1) AS UNSIGNED), `CODLICENCA`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ged_analises_ia_listar`
-- @@
CREATE PROCEDURE `sp_ged_analises_ia_listar`(IN p_tipo VARCHAR(30), IN p_codigo_registro BIGINT, IN p_arquivo VARCHAR(255))
BEGIN
    -- Histórico das análises de IA do registro (aba "Análises de IA" da Gestão de Arquivos); p_arquivo vazio = todos os arquivos
    SELECT a.`codanalise`, a.`referencia`, a.`arquivo`, a.`status`, a.`resumo`, a.`dados_ia`, a.`confianca`, a.`fonte_texto`, a.`mensagem`,
           a.`solicitado_em`, a.`concluido_em`, a.`confirmado_em`,
           COALESCE(NULLIF(u.`nome`, ''), u.`userid`) AS `usuario`, COALESCE(NULLIF(c.`nome`, ''), c.`userid`) AS `usuario_confirmacao`
      FROM `ged_analises_ia` a
      LEFT JOIN `usuarios` u ON u.`codusuario` = a.`codusuario`
      LEFT JOIN `usuarios` c ON c.`codusuario` = a.`codusuario_confirmacao`
     WHERE a.`tipo` COLLATE utf8mb4_unicode_ci = p_tipo COLLATE utf8mb4_unicode_ci
       AND a.`codigo_registro` = p_codigo_registro
       AND (COALESCE(p_arquivo, '') = '' OR a.`arquivo` COLLATE utf8mb4_unicode_ci = p_arquivo COLLATE utf8mb4_unicode_ci)
     ORDER BY a.`solicitado_em` DESC, a.`codanalise` DESC
     LIMIT 200;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_ged_analises_ia_situacao`
-- @@
CREATE PROCEDURE `sp_ged_analises_ia_situacao`(IN p_tipo VARCHAR(30), IN p_codigo_registro BIGINT)
BEGIN
    -- Situação da última análise (sem erro) de cada arquivo do registro
    SELECT a.`arquivo`, a.`status`
      FROM `ged_analises_ia` a
      JOIN (SELECT `arquivo`, MAX(`codanalise`) AS m
              FROM `ged_analises_ia`
             WHERE `tipo` COLLATE utf8mb4_unicode_ci = p_tipo COLLATE utf8mb4_unicode_ci AND `codigo_registro` = p_codigo_registro AND `status` <> 'ERRO'
             GROUP BY `arquivo`) x ON x.m = a.`codanalise`;
END
-- @@
DROP PROCEDURE IF EXISTS `sp_usuario_perfis_legado`
-- @@
CREATE PROCEDURE `sp_usuario_perfis_legado`(IN p_codusuario BIGINT, IN p_efetivo TINYINT)
BEGIN
    -- Perfis associados ao usuário migrado (efetivo = 1: perfil composto; 0: perfis de origem)
    SELECT `codperfil` FROM `atlas_usuarios_perfis_legado` WHERE `codusuario` = p_codusuario AND `efetivo` = p_efetivo ORDER BY `codperfil`;
END
