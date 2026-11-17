-- Migração 360 — Configuração do servidor de e-mail (SMTP) pela tela (banco universal; uma linha para todas as empresas).
-- Gravação só pela tela Administração › Configuração de e-mail (ADMINISTRADOR), via sp_crud_*. A senha fica cifrada.
-- @@
CREATE TABLE IF NOT EXISTS `smtp_configuracao` (
  `codconfiguracao` INT NOT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 0,
  `host` VARCHAR(255) NOT NULL DEFAULT '',
  `porta` INT NOT NULL DEFAULT 587,
  `seguranca` VARCHAR(10) NOT NULL DEFAULT 'tls',
  `usuario` VARCHAR(255) NOT NULL DEFAULT '',
  `senha_cifrada` TEXT NULL,
  `remetente` VARCHAR(255) NOT NULL DEFAULT '',
  `remetente_nome` VARCHAR(150) NOT NULL DEFAULT 'GreenSolutions',
  `timeout` INT NOT NULL DEFAULT 30,
  `verificar_certificado` TINYINT(1) NOT NULL DEFAULT 1,
  `ehlo` VARCHAR(255) NOT NULL DEFAULT '',
  `url_portal` VARCHAR(255) NOT NULL DEFAULT '',
  `ultimo_teste_em` DATETIME NULL,
  `ultimo_teste_resultado` VARCHAR(1000) NULL,
  `alterado_em` DATETIME NULL,
  `alterado_por` VARCHAR(200) NULL,
  `ligado_desligado_em` DATETIME NULL,
  `ligado_desligado_por` VARCHAR(200) NULL,
  PRIMARY KEY (`codconfiguracao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
