-- Migração 230 — Solicitações de melhoria (etapa 2 do assistente da aplicação).
-- Pedidos de funcionalidade, melhoria, integração, relatório ou IA feitos pelos usuários, registrados
-- pelo painel do assistente (com confirmação) ou pela tela Administração › Minhas solicitações,
-- acompanhados pela tela Administração › Gestão de solicitações. Cada mudança de situação fica no histórico
-- e gera um alerta para quem pediu.
-- Aplicada em todos os bancos de clientes. Pode ser executada mais de uma vez.

CREATE TABLE IF NOT EXISTS `solicitacoes_melhoria` (
    `codsolicitacao` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codusuario` BIGINT NOT NULL COMMENT 'Quem pediu',
    `titulo` VARCHAR(200) NOT NULL,
    `descricao` TEXT NOT NULL COMMENT 'Descrição revisada (confirmada pelo usuário)',
    `descricao_original` TEXT NULL COMMENT 'Pergunta original feita ao assistente',
    `beneficio` VARCHAR(500) NULL COMMENT 'Para que o usuário precisa',
    `modulo` VARCHAR(150) NULL,
    `transacao` VARCHAR(100) NULL COMMENT 'Tela de origem (código do menu)',
    `categoria` VARCHAR(30) NOT NULL DEFAULT 'MELHORIA' COMMENT 'FUNCIONALIDADE_INEXISTENTE, MELHORIA, INTEGRACAO, RELATORIO, IA, DIFICULDADE_USO, OUTRO',
    `prioridade_usuario` VARCHAR(10) NOT NULL DEFAULT 'MEDIA' COMMENT 'BAIXA, MEDIA, ALTA',
    `origem` VARCHAR(12) NOT NULL DEFAULT 'TELA' COMMENT 'ASSISTENTE ou TELA',
    `codmensagem` BIGINT UNSIGNED NULL COMMENT 'Resposta do assistente que originou o pedido (ia_assistente_mensagens)',
    `confianca_ia` DECIMAL(5,2) NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'NOVA' COMMENT 'NOVA, EM_ANALISE, PLANEJADA, EM_DESENVOLVIMENTO, ENTREGUE, RECUSADA, DUPLICADA',
    `escopo` VARCHAR(10) NOT NULL DEFAULT 'CLIENTE' COMMENT 'CLIENTE (só desta empresa) ou PRODUTO (vale para todos os clientes)',
    `codsolicitacao_original` BIGINT UNSIGNED NULL COMMENT 'Quando DUPLICADA: a solicitação que já trata o assunto',
    `resposta` TEXT NULL COMMENT 'Última resposta da equipe para quem pediu',
    `versao_aplicacao` VARCHAR(20) NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `codusuario_atualizacao` BIGINT NULL,
    PRIMARY KEY (`codsolicitacao`),
    KEY `idx_solicitacoes_melhoria_usuario` (`codusuario`, `criado_em`),
    KEY `idx_solicitacoes_melhoria_status` (`status`, `criado_em`),
    KEY `idx_solicitacoes_melhoria_mensagem` (`codmensagem`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `solicitacoes_melhoria_historico` (
    `codhistorico` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codsolicitacao` BIGINT UNSIGNED NOT NULL,
    `status_anterior` VARCHAR(20) NULL,
    `status_novo` VARCHAR(20) NOT NULL,
    `escopo` VARCHAR(10) NULL,
    `resposta` TEXT NULL,
    `codusuario` BIGINT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codhistorico`),
    KEY `idx_solicitacoes_melhoria_historico` (`codsolicitacao`, `criado_em`),
    CONSTRAINT `fk_solicitacoes_melhoria_historico` FOREIGN KEY (`codsolicitacao`) REFERENCES `solicitacoes_melhoria` (`codsolicitacao`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
