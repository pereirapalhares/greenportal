-- Migração 365 — Gestão de pendências (GER-18.1, 18.2 e 18.3 do 01_Mudancas_Gerais; decisões do usuário de 16/11/2026).
-- As pendências do portal são calculadas na hora (classes/MinhasPendencias.php e as views da barra lateral); "deletar" vira
-- OCULTAR (some até a situação mudar: o prazo da pendência mudar), "não gerar" vira uma chave por REGISTRO (o registro não
-- gera pendência para ninguém) ou por USUÁRIO (nada gera pendência para ele, até uma data opcional) e "limpar" é ocultar
-- em lote. Tudo com motivo obrigatório, log e aviso ao responsável (classes/PendenciasGestao.php).
-- Blocos separados por "-- @@"; aplicados por scripts/aplicar-migracao-365-gestao-pendencias.php.

-- @@
-- Regras em vigor (ATIVO = 1) e as já desfeitas (ATIVO = 0, com quem/quando reativou).
CREATE TABLE IF NOT EXISTS `pendencias_gestao` (
  `CODGESTAO` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `TIPO` VARCHAR(20) NOT NULL,                 -- OCULTAR | NAO_GERAR_REGISTRO | NAO_GERAR_USUARIO
  `CHAVE` VARCHAR(40) NULL,                    -- OCULTAR: identificação da pendência (tela + registro + título)
  `REG_TIPO` VARCHAR(80) NULL,                 -- tela do registro (ex.: manutencaolegal_licencas)
  `REG_CODIGO` VARCHAR(64) NULL,               -- código do registro na tela
  `CODUSUARIO_DONO` BIGINT NULL,               -- de quem é a pendência (OCULTAR) / usuário desligado (NAO_GERAR_USUARIO)
  `ASSINATURA` VARCHAR(20) NULL,               -- OCULTAR: prazo da pendência quando foi ocultada (AAAA-MM-DD ou vazio)
  `ATE` DATE NULL,                             -- NAO_GERAR_USUARIO: volta a gerar depois desta data (vazio = até reativar)
  `PILAR` VARCHAR(80) NULL,
  `TITULO` VARCHAR(300) NULL,
  `MOTIVO` VARCHAR(1000) NOT NULL,
  `LOTE` VARCHAR(32) NULL,                     -- limpeza em lote (GER-18.3)
  `ATIVO` TINYINT(1) NOT NULL DEFAULT 1,
  `CODUSUARIO_AUTOR` BIGINT NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `CODUSUARIO_REATIVOU` BIGINT NULL,
  `REATIVADO_EM` DATETIME NULL,
  PRIMARY KEY (`CODGESTAO`),
  KEY `ix_pendencias_gestao_dono` (`ATIVO`, `CODUSUARIO_DONO`),
  KEY `ix_pendencias_gestao_reg` (`ATIVO`, `REG_TIPO`, `REG_CODIGO`),
  CONSTRAINT `ck_pendencias_gestao_tipo` CHECK (`TIPO` IN ('OCULTAR', 'NAO_GERAR_REGISTRO', 'NAO_GERAR_USUARIO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

-- @@
-- Log de tudo o que foi feito (GER-18: "deve gerar um log correspondente").
CREATE TABLE IF NOT EXISTS `pendencias_gestao_log` (
  `CODLOG` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ACAO` VARCHAR(20) NOT NULL,                 -- OCULTAR | LIMPAR | NAO_GERAR_REGISTRO | NAO_GERAR_USUARIO | REATIVAR
  `CODGESTAO` BIGINT NULL,
  `LOTE` VARCHAR(32) NULL,
  `QTD` INT NOT NULL DEFAULT 1,
  `CODUSUARIO_DONO` BIGINT NULL,
  `CODUSUARIO_AUTOR` BIGINT NULL,
  `MOTIVO` VARCHAR(1000) NULL,
  `DETALHE` VARCHAR(4000) NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODLOG`),
  KEY `ix_pendencias_gestao_log` (`CRIADO_EM`),
  KEY `ix_pendencias_gestao_log_dono` (`CODUSUARIO_DONO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

-- @@
-- Regras em vigor (a chave de usuário vencida não vale mais).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `vw_pendencias_gestao_ativas` AS
SELECT g.`CODGESTAO`, g.`TIPO`, g.`CHAVE`, g.`REG_TIPO`, g.`REG_CODIGO`, g.`CODUSUARIO_DONO`, g.`ASSINATURA`, g.`ATE`,
       g.`PILAR`, g.`TITULO`, g.`MOTIVO`, g.`LOTE`, g.`CODUSUARIO_AUTOR`, g.`CRIADO_EM`
  FROM `pendencias_gestao` g
 WHERE g.`ATIVO` = 1 AND (g.`ATE` IS NULL OR g.`ATE` >= CURDATE())

-- @@
-- Grade da tela (regras em vigor e desfeitas, com os nomes).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `vw_pendencias_gestao_grid` AS
SELECT g.`CODGESTAO`, g.`TIPO`, g.`CHAVE`, g.`REG_TIPO`, g.`REG_CODIGO`, g.`CODUSUARIO_DONO`,
       CONVERT(COALESCE(d.`nome`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `DONO`,
       g.`ASSINATURA`, g.`ATE`, g.`PILAR`, g.`TITULO`, g.`MOTIVO`, g.`LOTE`, g.`ATIVO`,
       (g.`ATIVO` = 1 AND (g.`ATE` IS NULL OR g.`ATE` >= CURDATE())) AS `EM_VIGOR`,
       g.`CODUSUARIO_AUTOR`, CONVERT(COALESCE(a.`nome`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `AUTOR`, g.`CRIADO_EM`,
       g.`CODUSUARIO_REATIVOU`, CONVERT(COALESCE(r.`nome`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `REATIVOU`, g.`REATIVADO_EM`
  FROM `pendencias_gestao` g
  LEFT JOIN `usuarios` d ON d.`codusuario` = g.`CODUSUARIO_DONO`
  LEFT JOIN `usuarios` a ON a.`codusuario` = g.`CODUSUARIO_AUTOR`
  LEFT JOIN `usuarios` r ON r.`codusuario` = g.`CODUSUARIO_REATIVOU`

-- @@
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `vw_pendencias_gestao_log` AS
SELECT l.`CODLOG`, l.`ACAO`, l.`CODGESTAO`, l.`LOTE`, l.`QTD`, l.`CODUSUARIO_DONO`,
       CONVERT(COALESCE(d.`nome`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `DONO`,
       l.`CODUSUARIO_AUTOR`, CONVERT(COALESCE(a.`nome`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `AUTOR`,
       l.`MOTIVO`, l.`DETALHE`, l.`CRIADO_EM`
  FROM `pendencias_gestao_log` l
  LEFT JOIN `usuarios` d ON d.`codusuario` = l.`CODUSUARIO_DONO`
  LEFT JOIN `usuarios` a ON a.`codusuario` = l.`CODUSUARIO_AUTOR`
