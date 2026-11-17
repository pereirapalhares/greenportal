-- Migração 225 — Assistente da aplicação ("Como posso ajudar?"), etapa 1.
-- Conversas e mensagens do assistente de IA do portal, com a classificação de cada pergunta
-- (como fazer, erro, dificuldade, funcionalidade inexistente...) para alimentar as próximas etapas
-- (solicitações de melhoria e inteligência de produto), e a configuração de quem pode usar.
-- Aplicada em todos os bancos de clientes. Pode ser executada mais de uma vez.

CREATE TABLE IF NOT EXISTS `ia_assistente_config` (
    `chave` VARCHAR(60) NOT NULL,
    `valor` VARCHAR(500) NOT NULL DEFAULT '',
    `descricao` VARCHAR(255) NULL,
    `alterado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO `ia_assistente_config` (`chave`, `valor`, `descricao`) VALUES
('ativo', '1', '1 = assistente ligado; 0 = desligado para todos'),
('perfis_liberados', 'ADMINISTRADOR,ADM DA EMPRESA,ADM EMPRESA', 'Códigos de perfil que veem o assistente, separados por vírgula. Use * para liberar a todos os perfis.'),
('limite_diario', '40', 'Perguntas por usuário por dia'),
('historico_mensagens', '6', 'Mensagens anteriores da conversa enviadas à IA como contexto');

CREATE TABLE IF NOT EXISTS `ia_assistente_conversas` (
    `codconversa` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codusuario` BIGINT NOT NULL,
    `idioma` CHAR(2) NOT NULL DEFAULT 'pt',
    `transacao_inicial` VARCHAR(100) NULL COMMENT 'Tela aberta quando a conversa começou',
    `iniciada_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizada_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `encerrada` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`codconversa`),
    KEY `idx_ia_assistente_conversas_usuario` (`codusuario`, `encerrada`, `atualizada_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ia_assistente_mensagens` (
    `codmensagem` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codconversa` BIGINT UNSIGNED NOT NULL,
    `codusuario` BIGINT NOT NULL,
    `papel` VARCHAR(12) NOT NULL COMMENT 'USUARIO ou ASSISTENTE',
    `texto` TEXT NOT NULL COMMENT 'Pergunta já sem CPF, CNPJ, e-mail e telefone',
    `transacao` VARCHAR(100) NULL COMMENT 'Tela aberta no momento da pergunta (código do menu)',
    `categoria` VARCHAR(30) NULL COMMENT 'PERGUNTA, COMO_FAZER, ERRO, DIFICULDADE_USO, FUNCIONALIDADE_INEXISTENTE, MELHORIA, INTEGRACAO, RELATORIO, IA, OUTRO',
    `confianca` DECIMAL(5,2) NULL,
    `acoes` JSON NULL COMMENT 'Telas sugeridas para abrir',
    `funcionalidade_inexistente` TINYINT(1) NOT NULL DEFAULT 0,
    `necessidade` VARCHAR(500) NULL COMMENT 'Resumo da necessidade quando a funcionalidade não existe',
    `modelo` VARCHAR(60) NULL,
    `tokens_entrada` INT NULL,
    `tokens_saida` INT NULL,
    `duracao_ms` INT NULL,
    `avaliacao` TINYINT NULL COMMENT '1 = útil, -1 = não ajudou',
    `erro` VARCHAR(500) NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codmensagem`),
    KEY `idx_ia_assistente_mensagens_conversa` (`codconversa`, `codmensagem`),
    KEY `idx_ia_assistente_mensagens_usuario` (`codusuario`, `papel`, `criado_em`),
    KEY `idx_ia_assistente_mensagens_categoria` (`categoria`, `criado_em`),
    CONSTRAINT `fk_ia_assistente_mensagens_conversa` FOREIGN KEY (`codconversa`) REFERENCES `ia_assistente_conversas` (`codconversa`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
