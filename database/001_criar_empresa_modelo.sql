-- Green Portal - empresa Modelo
-- Criacao inicial do tenant gmodelo_dbp.
-- Este script nao remove objetos existentes e pode ser executado novamente.

-- Bancos legados do projeto utilizam latin1. A sessao de destino deve receber
-- os dados em utf8mb4, com a collation oficial do projeto.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE DATABASE IF NOT EXISTS `gmodelo_dbp`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;

USE `gmodelo_dbp`;

ALTER DATABASE `gmodelo_dbp`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `empresa` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo` VARCHAR(50) NOT NULL,
    `nome` VARCHAR(150) NOT NULL,
    `banco_dados` VARCHAR(128) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'ATIVA',
    `criada_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_empresa_codigo` (`codigo`),
    UNIQUE KEY `uk_empresa_banco` (`banco_dados`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `grupos` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo_origem` BIGINT NULL,
    `nome` VARCHAR(150) NOT NULL,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_grupos_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `perfis` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo` VARCHAR(50) NOT NULL,
    `nome` VARCHAR(100) NOT NULL,
    `acesso_total` TINYINT(1) NOT NULL DEFAULT 0,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_perfis_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `menus` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `menu_pai_id` BIGINT UNSIGNED NULL,
    `modulo` VARCHAR(100) NOT NULL,
    `codigo` VARCHAR(100) NOT NULL,
    `label` VARCHAR(150) NOT NULL,
    `url` VARCHAR(255) NULL,
    `nivel` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `ordem` INT NOT NULL DEFAULT 0,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_menus_codigo` (`codigo`),
    KEY `ix_menus_pai` (`menu_pai_id`),
    CONSTRAINT `fk_menus_pai` FOREIGN KEY (`menu_pai_id`) REFERENCES `menus` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usuarios` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo_origem` BIGINT NULL,
    `userid` VARCHAR(100) NOT NULL,
    `nome` VARCHAR(150) NOT NULL,
    `email` VARCHAR(255) NULL,
    `senha` VARCHAR(255) NOT NULL,
    `senha_usa_hash` TINYINT(1) NOT NULL DEFAULT 0,
    `perfil_id` BIGINT UNSIGNED NULL,
    `grupo_id` BIGINT UNSIGNED NULL,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `trocar_senha` TINYINT(1) NOT NULL DEFAULT 0,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ultimo_acesso` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_usuarios_userid` (`userid`),
    KEY `ix_usuarios_perfil` (`perfil_id`),
    KEY `ix_usuarios_grupo` (`grupo_id`),
    CONSTRAINT `fk_usuarios_perfil` FOREIGN KEY (`perfil_id`) REFERENCES `perfis` (`id`),
    CONSTRAINT `fk_usuarios_grupo` FOREIGN KEY (`grupo_id`) REFERENCES `grupos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `perfil_menus` (
    `perfil_id` BIGINT UNSIGNED NOT NULL,
    `menu_id` BIGINT UNSIGNED NOT NULL,
    `pode_incluir` TINYINT(1) NOT NULL DEFAULT 0,
    `pode_visualizar` TINYINT(1) NOT NULL DEFAULT 0,
    `pode_editar` TINYINT(1) NOT NULL DEFAULT 0,
    `pode_excluir` TINYINT(1) NOT NULL DEFAULT 0,
    `pode_exportar_excel` TINYINT(1) NOT NULL DEFAULT 0,
    `pode_gerar_pdf` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`perfil_id`, `menu_id`),
    CONSTRAINT `fk_perfil_menus_perfil` FOREIGN KEY (`perfil_id`) REFERENCES `perfis` (`id`),
    CONSTRAINT `fk_perfil_menus_menu` FOREIGN KEY (`menu_id`) REFERENCES `menus` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `setup_empresa` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `chave` VARCHAR(100) NOT NULL,
    `valor` TEXT NULL,
    `idioma` CHAR(2) NOT NULL DEFAULT 'pt',
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_setup_chave_idioma` (`chave`, `idioma`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `logs_auditoria` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `usuario_id` BIGINT UNSIGNED NULL,
    `transacao` VARCHAR(150) NOT NULL,
    `acao` VARCHAR(30) NOT NULL,
    `entidade` VARCHAR(100) NULL,
    `registro_id` VARCHAR(100) NULL,
    `resultado` VARCHAR(20) NOT NULL,
    `detalhes` JSON NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_logs_usuario` (`usuario_id`),
    KEY `ix_logs_data` (`criado_em`),
    CONSTRAINT `fk_logs_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `registros_migracao` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo_migracao` VARCHAR(100) NOT NULL,
    `origem` VARCHAR(255) NOT NULL,
    `destino` VARCHAR(255) NOT NULL,
    `tipo` VARCHAR(50) NOT NULL DEFAULT 'DADOS',
    `versao` VARCHAR(30) NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
    `quantidade_registros` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `iniciada_em` DATETIME NULL,
    `concluida_em` DATETIME NULL,
    `detalhes` JSON NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_registros_migracao_codigo` (`codigo_migracao`),
    KEY `ix_registros_migracao_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bugs_testes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo` VARCHAR(50) NOT NULL,
    `titulo` VARCHAR(200) NOT NULL,
    `descricao` TEXT NOT NULL,
    `modulo` VARCHAR(100) NULL,
    `transacao` VARCHAR(150) NULL,
    `severidade` VARCHAR(20) NOT NULL DEFAULT 'MEDIA',
    `status` VARCHAR(30) NOT NULL DEFAULT 'ABERTO',
    `passos_reproducao` TEXT NULL,
    `resultado_esperado` TEXT NULL,
    `resultado_obtido` TEXT NULL,
    `solucao_aplicada` TEXT NULL,
    `responsavel` VARCHAR(150) NULL,
    `encontrado_em` DATETIME NULL,
    `resolvido_em` DATETIME NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_bugs_testes_codigo` (`codigo`),
    KEY `ix_bugs_testes_status` (`status`),
    KEY `ix_bugs_testes_modulo` (`modulo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `artefatos_replicacao` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `modulo` VARCHAR(100) NOT NULL,
    `tipo_artefato` ENUM('TABELA', 'PROCEDURE', 'VIEW') NOT NULL,
    `nome_artefato` VARCHAR(200) NOT NULL,
    `schema_origem` VARCHAR(128) NOT NULL DEFAULT 'gmodelo_dbp',
    `versao` VARCHAR(30) NOT NULL DEFAULT '1.0.0',
    `ordem_replicacao` INT NOT NULL DEFAULT 0,
    `replicar` TINYINT(1) NOT NULL DEFAULT 1,
    `obrigatorio` TINYINT(1) NOT NULL DEFAULT 0,
    `dependencias` JSON NULL,
    `observacao` VARCHAR(500) NULL,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_artefatos_replicacao` (`tipo_artefato`, `nome_artefato`),
    KEY `ix_artefatos_replicacao_ordem` (`ordem_replicacao`),
    KEY `ix_artefatos_replicacao_modulo` (`modulo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `empresa` (`codigo`, `nome`, `banco_dados`)
VALUES ('MODELO', 'Modelo', 'gmodelo_dbp')
ON DUPLICATE KEY UPDATE `nome` = VALUES(`nome`), `banco_dados` = VALUES(`banco_dados`);

INSERT INTO `perfis` (`codigo`, `nome`, `acesso_total`)
VALUES
    ('ADMINISTRADOR', 'Administrador', 1),
    ('ADM_DA_EMPRESA', 'Administrador da Empresa', 0),
    ('GESTOR', 'Gestor', 0),
    ('EMITENTE', 'Emitente', 0),
    ('REVISOR', 'Revisor', 0),
    ('APROVADOR', 'Aprovador', 0),
    ('FORNECEDOR', 'Fornecedor', 0)
ON DUPLICATE KEY UPDATE `nome` = VALUES(`nome`), `acesso_total` = VALUES(`acesso_total`);

-- Migracao dos grupos existentes do banco de seguranca do Modelo.
INSERT INTO `grupos` (`codigo_origem`, `nome`)
SELECT `CODGRUPO`, `DSCGRUPO`
  FROM `green_modelo_seguranca`.`grupos` origem
 WHERE NOT EXISTS (
       SELECT 1 FROM `grupos` destino WHERE destino.`nome` = origem.`DSCGRUPO`
   );

-- Migracao dos usuarios existentes, preservando a senha conforme a politica
-- legada. O usuario admin e normalizado abaixo.
INSERT INTO `usuarios`
    (`codigo_origem`, `userid`, `nome`, `email`, `senha`, `senha_usa_hash`, `ativo`, `criado_em`)
SELECT origem.`CODUSUARIO`,
       CONVERT(origem.`USERID` USING utf8mb4),
       CONVERT(origem.`NOME` USING utf8mb4),
       CONVERT(origem.`EMAILS` USING utf8mb4),
       CONVERT(origem.`SENHA` USING utf8mb4), 0,
       CASE WHEN origem.`ativado` = 'S' THEN 1 ELSE 0 END,
       COALESCE(CAST(origem.`DTINCLUSAO` AS DATETIME), CURRENT_TIMESTAMP)
  FROM `green_modelo_seguranca`.`usuarios` origem
  JOIN (
        SELECT `USERID`, MIN(`CODUSUARIO`) AS `CODUSUARIO`
          FROM `green_modelo_seguranca`.`usuarios`
         WHERE `USERID` IS NOT NULL AND `USERID` <> ''
         GROUP BY `USERID`
       ) primeiro ON primeiro.`CODUSUARIO` = origem.`CODUSUARIO`
 WHERE NOT EXISTS (
       SELECT 1 FROM `usuarios` destino WHERE destino.`userid` = origem.`USERID`
   );

-- Menu inicial do modulo de Seguranca. Novos itens poderao ser cadastrados
-- pela transacao de menus da empresa Modelo.
INSERT INTO `menus` (`modulo`, `codigo`, `label`, `url`, `nivel`, `ordem`)
VALUES ('SEGURANCA', 'SEGURANCA', 'Segurança', NULL, 1, 10)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `ativo` = 1;

-- O administrador da Modelo possui acesso total, inclusive aos menus futuros
-- por meio do campo perfis.acesso_total.
UPDATE `usuarios` u
JOIN `perfis` p ON p.`codigo` = 'ADMINISTRADOR'
   SET u.`nome` = 'Administrador Modelo',
       u.`email` = COALESCE(NULLIF(u.`email`, ''), 'admin@modelo.local'),
       u.`senha` = 'admin1234',
       u.`senha_usa_hash` = 0,
       u.`perfil_id` = p.`id`,
       u.`ativo` = 1,
       u.`trocar_senha` = 0
 WHERE u.`userid` = 'admin';

INSERT INTO `usuarios`
    (`userid`, `nome`, `email`, `senha`, `senha_usa_hash`, `perfil_id`, `ativo`, `trocar_senha`)
SELECT 'admin', 'Administrador Modelo', 'admin@modelo.local', 'admin1234', 0, p.`id`, 1, 0
  FROM `perfis` p
 WHERE p.`codigo` = 'ADMINISTRADOR'
   AND NOT EXISTS (SELECT 1 FROM `usuarios` WHERE `userid` = 'admin');

INSERT INTO `setup_empresa` (`chave`, `valor`, `idioma`)
VALUES
    ('login_titulo', 'Acesse seu portal', 'pt'),
    ('login_subtitulo', 'Entre com seus dados para continuar.', 'pt'),
    ('login_marketing', 'Sistemas de Gestão em conformidade com as normas ISO 14001, OHSAS 18001 e ISO 16001.', 'pt'),
    ('politica_senha_usa_hash', '0', 'pt'),
    ('idioma_padrao', 'pt', 'pt')
ON DUPLICATE KEY UPDATE `valor` = VALUES(`valor`);

-- Catalogo inicial de artefatos que podem ser replicados para novos tenants.
-- A tabela empresa permanece exclusiva do tenant Modelo.
INSERT INTO `artefatos_replicacao`
    (`modulo`, `tipo_artefato`, `nome_artefato`, `ordem_replicacao`, `replicar`, `obrigatorio`, `observacao`)
VALUES
    ('SEGURANCA', 'TABELA', 'usuarios', 100, 1, 1, 'Usuarios do tenant'),
    ('SEGURANCA', 'TABELA', 'grupos', 110, 1, 1, 'Grupos do tenant'),
    ('SEGURANCA', 'TABELA', 'perfis', 120, 1, 1, 'Perfis do tenant'),
    ('SEGURANCA', 'TABELA', 'menus', 130, 1, 1, 'Menus e itens de menu'),
    ('SEGURANCA', 'TABELA', 'perfil_menus', 140, 1, 1, 'Permissoes por menu'),
    ('SEGURANCA', 'TABELA', 'setup_empresa', 150, 1, 1, 'Configuracao inicial'),
    ('SEGURANCA', 'TABELA', 'logs_auditoria', 160, 1, 1, 'Auditoria do tenant'),
    ('SEGURANCA', 'TABELA', 'registros_migracao', 170, 1, 0, 'Historico de migracoes'),
    ('SEGURANCA', 'TABELA', 'bugs_testes', 180, 1, 0, 'Registro de testes e bugs'),
    ('SEGURANCA', 'TABELA', 'artefatos_replicacao', 190, 1, 0, 'Catalogo local do template'),
    ('SEGURANCA', 'TABELA', 'empresa', 900, 0, 0, 'Cadastro do tenant Modelo, nao replicar')
ON DUPLICATE KEY UPDATE
    `modulo` = VALUES(`modulo`),
    `ordem_replicacao` = VALUES(`ordem_replicacao`),
    `replicar` = VALUES(`replicar`),
    `obrigatorio` = VALUES(`obrigatorio`),
    `observacao` = VALUES(`observacao`),
    `ativo` = 1;

INSERT INTO `registros_migracao`
    (`codigo_migracao`, `origem`, `destino`, `tipo`, `versao`, `status`, `quantidade_registros`, `iniciada_em`, `concluida_em`, `detalhes`)
VALUES
    ('MODELO_SEGURANCA_INICIAL', 'green_modelo_seguranca.usuarios/grupos', 'gmodelo_dbp.usuarios/grupos', 'DADOS', '1.0.0', 'CONCLUIDA', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, JSON_OBJECT('script', 'database/001_criar_empresa_modelo.sql'))
ON DUPLICATE KEY UPDATE `status` = 'CONCLUIDA', `concluida_em` = CURRENT_TIMESTAMP;

UPDATE `registros_migracao`
   SET `quantidade_registros` = (SELECT COUNT(*) FROM `usuarios`) + (SELECT COUNT(*) FROM `grupos`)
 WHERE `codigo_migracao` = 'MODELO_SEGURANCA_INICIAL';

INSERT INTO `logs_auditoria` (`usuario_id`, `transacao`, `acao`, `entidade`, `registro_id`, `resultado`, `detalhes`)
SELECT u.`id`, 'database/001_criar_empresa_modelo.sql', 'CRIACAO', 'empresa', CAST(e.`id` AS CHAR), 'SUCESSO',
       JSON_OBJECT('empresa', 'Modelo', 'banco', 'gmodelo_dbp', 'usuario_admin', 'admin')
  FROM `usuarios` u
  JOIN `empresa` e ON e.`codigo` = _utf8mb4'MODELO' COLLATE utf8mb4_unicode_ci
 WHERE u.`userid` = _utf8mb4'admin' COLLATE utf8mb4_unicode_ci
   AND NOT EXISTS (
       SELECT 1 FROM `logs_auditoria` l
        WHERE l.`transacao` = _utf8mb4'database/001_criar_empresa_modelo.sql' COLLATE utf8mb4_unicode_ci
          AND l.`entidade` = _utf8mb4'empresa' COLLATE utf8mb4_unicode_ci
          AND l.`registro_id` = (CAST(e.`id` AS CHAR) COLLATE utf8mb4_unicode_ci)
   );
