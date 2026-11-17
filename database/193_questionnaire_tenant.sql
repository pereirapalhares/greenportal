/* P2 Green Questionnaire — execuções, respostas e resultados por tenant. */
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS amb_questionario_execucoes (
    codexecucao BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    chave_idempotencia VARCHAR(191) NOT NULL,
    codpublicacao BIGINT UNSIGNED NOT NULL,
    codquestionario BIGINT UNSIGNED NOT NULL,
    codversao BIGINT UNSIGNED NOT NULL,
    codigo_questionario VARCHAR(100) NOT NULL,
    codusuario BIGINT UNSIGNED NOT NULL,
    codunidade BIGINT UNSIGNED NULL,
    origem_entidade VARCHAR(100) NULL,
    origem_id VARCHAR(100) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ABERTA',
    iniciada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concluida_em DATETIME NULL,
    cancelada_em DATETIME NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (codexecucao),
    UNIQUE KEY ux_amb_questionario_execucao_idempotencia (chave_idempotencia),
    KEY ix_amb_questionario_execucao_usuario (codusuario,status),
    KEY ix_amb_questionario_execucao_origem (origem_entidade,origem_id),
    CONSTRAINT ck_amb_questionario_execucao_status CHECK (status IN ('ABERTA','CONCLUIDA','CANCELADA','EXPIRADA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS amb_questionario_respostas (
    codresposta BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codexecucao BIGINT UNSIGNED NOT NULL,
    codpergunta BIGINT UNSIGNED NOT NULL,
    codigo_pergunta VARCHAR(100) NOT NULL,
    valor_json JSON NULL,
    respondido_por BIGINT UNSIGNED NOT NULL,
    respondido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (codresposta),
    UNIQUE KEY ux_amb_questionario_resposta (codexecucao,codpergunta),
    CONSTRAINT fk_amb_questionario_resposta_execucao FOREIGN KEY (codexecucao) REFERENCES amb_questionario_execucoes(codexecucao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS amb_questionario_resultados (
    codresultado BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codexecucao BIGINT UNSIGNED NOT NULL,
    total_perguntas INT UNSIGNED NOT NULL DEFAULT 0,
    total_respondidas INT UNSIGNED NOT NULL DEFAULT 0,
    pontuacao DECIMAL(12,3) NOT NULL DEFAULT 0,
    percentual DECIMAL(6,3) NULL,
    aprovado TINYINT(1) NULL,
    detalhes_json JSON NULL,
    calculado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (codresultado),
    UNIQUE KEY ux_amb_questionario_resultado_execucao (codexecucao),
    CONSTRAINT fk_amb_questionario_resultado_execucao FOREIGN KEY (codexecucao) REFERENCES amb_questionario_execucoes(codexecucao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS amb_questionario_evidencias (
    codevidencia BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codexecucao BIGINT UNSIGNED NOT NULL,
    codpergunta BIGINT UNSIGNED NULL,
    entidade VARCHAR(100) NOT NULL,
    registro_id VARCHAR(100) NOT NULL,
    criado_por BIGINT UNSIGNED NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (codevidencia),
    KEY ix_amb_questionario_evidencia_execucao (codexecucao,codpergunta),
    CONSTRAINT fk_amb_questionario_evidencia_execucao FOREIGN KEY (codexecucao) REFERENCES amb_questionario_execucoes(codexecucao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW vw_amb_questionario_execucoes_grid AS
SELECT e.codexecucao,e.chave_idempotencia,e.codpublicacao,e.codquestionario,e.codversao,
       e.codigo_questionario,e.codusuario,e.codunidade,e.origem_entidade,e.origem_id,
       e.status,e.iniciada_em,e.concluida_em,r.total_perguntas,r.total_respondidas,
       r.pontuacao,r.percentual,r.aprovado
  FROM amb_questionario_execucoes e
  LEFT JOIN amb_questionario_resultados r ON r.codexecucao=e.codexecucao;

INSERT INTO registros_migracao
    (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes)
VALUES('GREEN_QUESTIONNAIRE_TENANT_193','Green Questionnaire universal','amb_questionario_execucoes/amb_questionario_respostas/amb_questionario_resultados','ESTRUTURA','4.0.1','CONCLUIDA',0,NOW(),NOW(),JSON_OBJECT('catalogo','guniversal_dbp','respostas_por_tenant',TRUE,'publicacao_imutavel',TRUE))
ON DUPLICATE KEY UPDATE status=VALUES(status),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes);
