/* Filas persistentes consumidas pelos workers PHP do Agendador do Windows. */
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS green_ocr_jobs (
    codjob BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    chave_idempotencia VARCHAR(191) NOT NULL,
    tipo VARCHAR(50) NOT NULL,
    entidade VARCHAR(100) NOT NULL,
    registro_id VARCHAR(100) NOT NULL,
    arquivo VARCHAR(255) NOT NULL,
    caminho_arquivo VARCHAR(1000) NOT NULL,
    tamanho_bytes BIGINT UNSIGNED NULL,
    codusuario BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
    tentativas INT UNSIGNED NOT NULL DEFAULT 0,
    disponivel_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    iniciada_em DATETIME NULL,
    concluida_em DATETIME NULL,
    processador VARCHAR(150) NULL,
    texto LONGTEXT NULL,
    paginas INT UNSIGNED NULL,
    confianca DECIMAL(6,3) NULL,
    erro TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (codjob),
    UNIQUE KEY ux_green_ocr_jobs_idempotencia (chave_idempotencia),
    KEY ix_green_ocr_jobs_fila (status, disponivel_em, codjob),
    KEY ix_green_ocr_jobs_usuario (codusuario, criado_em),
    CONSTRAINT ck_green_ocr_jobs_status CHECK (status IN ('PENDENTE','PROCESSANDO','CONCLUIDA','ERRO','CANCELADA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO registros_migracao
    (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros,
     iniciada_em, concluida_em, detalhes)
VALUES
    ('GREEN_ASYNC_AI_OCR_191', 'GreenPortal IA/OCR', 'green_ocr_jobs', 'ESTRUTURA', '4.0.1',
     'CONCLUIDA', 0, NOW(), NOW(),
     JSON_OBJECT('ia_legal', 'ml_potenciais_solicitacoes',
                 'ocr', 'green_ocr_jobs',
                 'execucao', 'PHP CLI pelo Agendador do Windows',
                 'idempotencia', true))
ON DUPLICATE KEY UPDATE
    status = VALUES(status), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes);
